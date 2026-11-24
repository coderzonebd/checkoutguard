<?php
/**
 * Incomplete checkout cleanup for orders created in wp-admin
 * (manual invoice and the Recover button), plus storefront regression.
 *
 * Run on a local test site only (never production):
 *   wp eval-file tests/qa/admin-order-incomplete-cleanup-tests.php
 * It inserts and deletes its own cg_incomplete_checkouts rows (session_id
 * prefix cgmi-), creates and force-deletes its own orders, and restores
 * cg_api_cache and cg_last_known_plan. HQ calls are stubbed (no network).
 */
if (!defined('ABSPATH')) {
    exit;
}
$GLOBALS['R'] = ['pass' => 0, 'fail' => 0];
function mi_check($id, $cond, $detail = '') {
    $GLOBALS['R'][$cond ? 'pass' : 'fail']++;
    echo ($cond ? 'PASS ' : 'FAIL ') . $id . ($detail !== '' ? ' - ' . $detail : ''), "\n";
}
class CgmiDie extends Error {}

global $wpdb;
$T = $wpdb->prefix . 'cg_incomplete_checkouts';
$GLOBALS['MI_HQ'] = [];
add_filter('pre_http_request', function ($pre, $args, $url) {
    $GLOBALS['MI_HQ'][] = $url;
    return ['headers' => [], 'body' => '{}', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
}, 10, 3);
// Deleted rows give usage back through WP-Cron (cg_queue_usage_decrement).
// Run every queued decrement event first, so the HQ calls it sends are
// counted like the direct ones.
function mi_drain_queued_decrements() {
    if (!defined('CG_USAGE_DECREMENT_HOOK')) {
        return;
    }
    for ($guard = 0; $guard < 50; $guard++) {
        $events = [];
        foreach ((array) _get_cron_array() as $ts => $hooks) {
            foreach ((array) ($hooks[CG_USAGE_DECREMENT_HOOK] ?? []) as $ev) {
                $events[] = [$ts, $ev['args']];
            }
        }
        if (!$events) {
            return;
        }
        foreach ($events as $e) {
            wp_unschedule_event($e[0], CG_USAGE_DECREMENT_HOOK, $e[1]);
            do_action_ref_array(CG_USAGE_DECREMENT_HOOK, $e[1]);
        }
    }
}
function mi_decrements() {
    mi_drain_queued_decrements();
    return count(array_filter($GLOBALS['MI_HQ'], function ($u) { return strpos($u, 'decrement-usage') !== false; }));
}

$saved_cache = get_transient('cg_api_cache');
$saved_last = get_option('cg_last_known_plan', null);
set_transient('cg_api_cache', ['plan_slug' => 'pro', 'status' => 'active', 'usage' => ['checkouts' => 50]], DAY_IN_SECONDS);
update_option('cg_last_known_plan', ['plan_slug' => 'pro', 'status' => 'active', 'synced_at' => time()], false);

$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
wp_set_current_user((int) $admins[0]);

// Shop manager's own request context: a WC session and a device cookie.
if (function_exists('WC') && WC()->session === null) {
    WC()->session = new WC_Session_Handler();
    WC()->session->init();
}
$ADMIN_SID = (function_exists('WC') && WC()->session) ? (string) WC()->session->get_customer_id() : '';
$ADMIN_FP = 'cgmi-admin-fp-' . wp_generate_password(6, false);

$created_orders = [];
function mi_row($fields) {
    global $wpdb;
    $T = $wpdb->prefix . 'cg_incomplete_checkouts';
    $row = array_merge([
        'session_id' => 'cgmi-' . wp_generate_password(8, false),
        'status' => 'incomplete',
        'cart_details' => '[]',
        'created_at' => current_time('mysql'),
        'updated_at' => current_time('mysql'),
    ], $fields);
    $wpdb->insert($T, $row);
    return (int) $wpdb->insert_id;
}
function mi_get($id) {
    global $wpdb;
    $T = $wpdb->prefix . 'cg_incomplete_checkouts';
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$T} WHERE id = %d", $id));
}
function mi_clear_rows() {
    global $wpdb;
    $T = $wpdb->prefix . 'cg_incomplete_checkouts';
    $wpdb->query("DELETE FROM {$T} WHERE session_id LIKE 'cgmi-%' OR device_fingerprint LIKE 'cgmi-%' OR email LIKE '%@cgmi.test'");
    if ($GLOBALS['ADMIN_SID_G'] !== '') {
        $wpdb->query($wpdb->prepare("DELETE FROM {$T} WHERE session_id = %s", $GLOBALS['ADMIN_SID_G']));
    }
}
$GLOBALS['ADMIN_SID_G'] = $ADMIN_SID;
// Admin context = the manager's device cookie plus is_admin() (admin-ajax.php).
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
function mi_admin_context($on, $screen = null) {
    if ($on) {
        $_COOKIE['cg_device_fp'] = $GLOBALS['ADMIN_FP_G'];
    } else {
        unset($_COOKIE['cg_device_fp']);
    }
    if ($screen === null) {
        $screen = $on;
    }
    if ($screen) {
        $GLOBALS['current_screen'] = WP_Screen::get('dashboard');
    } else {
        unset($GLOBALS['current_screen']);
    }
}
$GLOBALS['ADMIN_FP_G'] = $ADMIN_FP;
$has_fp_col = (bool) $wpdb->get_var("SHOW COLUMNS FROM {$T} LIKE 'device_fingerprint'");

$product = new WC_Product_Simple();
$product->set_name('cgmi test product');
$product->set_regular_price('100');
$product->save();
$PID = $product->get_id();

mi_clear_rows();

// ---------- Manual invoice ----------
mi_admin_context(true);
$cust_old = mi_row(['phone' => '01799000111', 'first_name' => 'Cust', 'created_at' => date('Y-m-d H:i:s', current_time('timestamp') - 7200)]);
$cust = mi_row(['phone' => '01799000111', 'email' => 'cust1@cgmi.test', 'first_name' => 'Cust', 'admin_notes' => 'called once', 'created_at' => date('Y-m-d H:i:s', current_time('timestamp') - 60)]);
$cust_hold = mi_row(['email' => 'cust1@cgmi.test', 'status' => 'hold', 'created_at' => date('Y-m-d H:i:s', current_time('timestamp') - 3600)]);
$adm_fp = $has_fp_col ? mi_row(['phone' => '01799000999', 'device_fingerprint' => $ADMIN_FP, 'first_name' => 'Other']) : 0;
$adm_sid = $ADMIN_SID !== '' ? mi_row(['session_id' => $ADMIN_SID, 'phone' => '01799000888', 'first_name' => 'Other2']) : 0;
$hq0 = mi_decrements();
$o = cg_create_manual_invoice_order([
    'customer_name' => 'Cust One',
    'phone' => '01799000111',
    'email' => 'cust1@cgmi.test',
    'items' => [['name' => 'Item', 'qty' => 1, 'price' => 100]],
    'payment_method' => 'cod',
]);
$ok = !is_wp_error($o);
if ($ok) { $created_orders[] = $o->get_id(); }
mi_check('M0 manual invoice order created', $ok, $ok ? '' : $o->get_error_message());
$m1 = mi_get($cust);
mi_check('M1 manual invoice: newest matching row is kept as recovered with the order id',
    $ok && $m1 && $m1->status === 'recovered' && (int) $m1->recovered_order_id === $o->get_id(),
    $m1 ? $m1->status . '/' . $m1->recovered_order_id : 'missing');
mi_check('M1b manual invoice: recovered row keeps old notes and gets a recovery note',
    $m1 && strpos((string) $m1->admin_notes, 'called once') === 0 && strpos((string) $m1->admin_notes, '#' . ($ok ? $o->get_id() : 0)) !== false,
    $m1 ? (string) $m1->admin_notes : '');
mi_check('M1c manual invoice: older incomplete and hold rows of the same customer are deleted', mi_get($cust_old) === null && mi_get($cust_hold) === null);
if ($has_fp_col) {
    mi_check('M2 manual invoice: row matching only the admin\'s device cookie is kept', mi_get($adm_fp) !== null);
}
if ($ADMIN_SID !== '') {
    mi_check('M3 manual invoice: row matching only the admin\'s WC session is kept', mi_get($adm_sid) !== null);
}
$fo = $ok ? wc_get_order($o->get_id()) : null;
mi_check('M4 manual invoice order marked _cg_incomplete_cleaned=1', $fo && $fo->get_meta('_cg_incomplete_cleaned') === '1');
mi_check('M5 one HQ decrement per matching row (recovered + deleted)', mi_decrements() - $hq0 === 3, 'decrements=' . (mi_decrements() - $hq0));
// M8 a later storefront order of the same customer keeps the recovered row.
// Drop the admin-only rows first: the storefront path would match them.
$wpdb->query($wpdb->prepare("DELETE FROM {$T} WHERE id IN (%d, %d)", $adm_fp, $adm_sid));
mi_admin_context(false, false);
$hq1 = mi_decrements();
$so8 = new WC_Order();
$so8->set_created_via('checkout');
$so8->set_billing_phone('01799000111');
$so8->save();
$created_orders[] = $so8->get_id();
cg_delete_incomplete_checkout_on_order_completion($so8->get_id());
$m8 = mi_get($cust);
mi_check('M8 later storefront order keeps the recovered row, no decrement', $m8 && $m8->status === 'recovered' && mi_decrements() === $hq1);
mi_admin_context(true);
mi_check('M7 manual invoice order does not get the admin\'s device fingerprint', $fo && $fo->get_meta('_cg_device_fingerprint') === '', $fo ? (string) $fo->get_meta('_cg_device_fingerprint') : '');
mi_clear_rows();

// M6 manual invoice with name only: nothing matches, nothing deleted, no decrement, no flag.
$other = mi_row(['phone' => '01799000222', 'email' => 'cust2@cgmi.test']);
$adm_fp = $has_fp_col ? mi_row(['device_fingerprint' => $ADMIN_FP]) : 0;
$hq0 = mi_decrements();
$o = cg_create_manual_invoice_order(['customer_name' => 'Walk In', 'items' => [['name' => 'Item', 'qty' => 1, 'price' => 10]]]);
if (!is_wp_error($o)) { $created_orders[] = $o->get_id(); }
$fo = is_wp_error($o) ? null : wc_get_order($o->get_id());
mi_check('M6 manual invoice without phone/email: no row deleted, no decrement, no cleaned flag',
    mi_get($other) !== null && (!$has_fp_col || mi_get($adm_fp) !== null) && mi_decrements() === $hq0 && $fo && $fo->get_meta('_cg_incomplete_cleaned') === '',
    'decrements=' . (mi_decrements() - $hq0));
mi_clear_rows();

// M9 the keep UPDATE to 'recovered' fails (Zahid N5): the newest row is
// not deleted (it stays incomplete), older rows are deleted, and usage is
// given back only for the deleted rows.
mi_admin_context(true);
$m9_keep = mi_row(['phone' => '01799000121', 'email' => 'cust9@cgmi.test', 'first_name' => 'Nine', 'created_at' => date('Y-m-d H:i:s', current_time('timestamp') - 60)]);
$m9_old = mi_row(['phone' => '01799000121', 'first_name' => 'Nine', 'created_at' => date('Y-m-d H:i:s', current_time('timestamp') - 7200)]);
$m9_fail = function ($q) use ($T) {
    if (stripos($q, 'UPDATE') === 0 && strpos($q, $T) !== false && strpos($q, 'recovered') !== false) {
        return 'SELECT cgmi_forced_failure FROM cgmi_no_such_table';
    }
    return $q;
};
add_filter('query', $m9_fail);
$suppress = $wpdb->suppress_errors(true);
$hq0 = mi_decrements();
$o = cg_create_manual_invoice_order([
    'customer_name' => 'Nine',
    'phone' => '01799000121',
    'email' => 'cust9@cgmi.test',
    'items' => [['name' => 'Item', 'qty' => 1, 'price' => 100]],
    'payment_method' => 'cod',
]);
$wpdb->suppress_errors($suppress);
remove_filter('query', $m9_fail);
if (!is_wp_error($o)) { $created_orders[] = $o->get_id(); }
$k = mi_get($m9_keep);
mi_check('M9 failed keep UPDATE: newest row stays incomplete, older row deleted, one decrement',
    !is_wp_error($o) && $k !== null && $k->status === 'incomplete' && mi_get($m9_old) === null && mi_decrements() - $hq0 === 1,
    ($k ? $k->status : 'missing') . ' decrements=' . (mi_decrements() - $hq0));
mi_clear_rows();

// ---------- Recover button ----------
add_filter('wp_doing_ajax', '__return_true');
add_filter('wp_die_ajax_handler', function () { return function () { throw new CgmiDie('die'); }; });
function mi_recover($entry_id) {
    $_POST['entry_id'] = (string) $entry_id;
    $_POST['nonce'] = $_REQUEST['nonce'] = wp_create_nonce('cg_recover_order_nonce');
    $_REQUEST['entry_id'] = $_POST['entry_id'];
    ob_start();
    try {
        cg_recover_order_ajax_handler();
    } catch (CgmiDie $e) {
    }
    $out = ob_get_clean();
    return json_decode($out, true);
}
$cart = wp_json_encode([['product_id' => $PID, 'quantity' => 1]]);
$entry = mi_row(['phone' => '01799000333', 'email' => 'cust3@cgmi.test', 'first_name' => 'Rec', 'cart_details' => $cart, 'country' => 'BD']);
$sib = mi_row(['phone' => '01799000333', 'first_name' => 'Rec']);
$hist = mi_row(['email' => 'cust3@cgmi.test', 'status' => 'recovered', 'recovered_order_id' => 1]);
$adm_fp = $has_fp_col ? mi_row(['phone' => '01799000777', 'device_fingerprint' => $ADMIN_FP]) : 0;
$hq0 = mi_decrements();
$res = mi_recover($entry);
$oid = (int) ($res['data']['order_id'] ?? 0);
if ($oid) { $created_orders[] = $oid; }
mi_check('R0 Recover created an order', !empty($res['success']) && $oid > 0, wp_json_encode($res['data']['message'] ?? $res));
$e = mi_get($entry);
mi_check('R1 recovered entry is kept with status recovered and the order id', $e && $e->status === 'recovered' && (int) $e->recovered_order_id === $oid, $e ? $e->status : 'missing');
mi_check('R2 other incomplete row of the same customer is cleaned up', mi_get($sib) === null);
mi_check('R3 older recovered row of the same customer is kept', mi_get($hist) !== null);
if ($has_fp_col) {
    mi_check('R4 row matching only the admin\'s device cookie is kept', mi_get($adm_fp) !== null);
}
$ro = $oid ? wc_get_order($oid) : null;
mi_check('R6 recovered order does not get the admin\'s device fingerprint', $ro && $ro->get_meta('_cg_device_fingerprint') === '');
// Rafi spec 5.1: the recovered entry is not counted as incomplete either.
mi_check('R5 two HQ decrements: the recovered entry and the one deleted row', mi_decrements() - $hq0 === 2, 'decrements=' . (mi_decrements() - $hq0));
mi_clear_rows();

// R7 the UPDATE to 'recovered' fails: the entry itself must not be
// deleted by the cleanup and no usage is given back for it.
$entry = mi_row(['phone' => '01799000334', 'email' => 'cust4@cgmi.test', 'first_name' => 'Rec', 'cart_details' => $cart, 'country' => 'BD']);
$sib = mi_row(['phone' => '01799000334', 'first_name' => 'Rec']);
$fail_update = function ($q) use ($T) {
    if (stripos($q, 'UPDATE') === 0 && strpos($q, $T) !== false && strpos($q, 'recovered') !== false) {
        return 'SELECT cgmi_forced_failure FROM cgmi_no_such_table';
    }
    return $q;
};
add_filter('query', $fail_update);
$suppress = $wpdb->suppress_errors(true);
$hq0 = mi_decrements();
$res = mi_recover($entry);
$wpdb->suppress_errors($suppress);
remove_filter('query', $fail_update);
$oid = (int) ($res['data']['order_id'] ?? 0);
if ($oid) { $created_orders[] = $oid; }
$e = mi_get($entry);
mi_check('R7 failed recovered UPDATE: entry is kept, nothing deleted, no decrement',
    $e !== null && $e->status === 'incomplete' && mi_get($sib) !== null && mi_decrements() === $hq0,
    ($e ? $e->status : 'missing') . ' decrements=' . (mi_decrements() - $hq0));
mi_clear_rows();

// R8 two Recover clicks on the same entry at once: the other click marks
// the entry recovered between our SELECT and our UPDATE. Our UPDATE must
// match 0 rows (it is guarded by the status we read), so no second
// decrement for the entry and the other click's order id stays.
$entry = mi_row(['phone' => '01799000335', 'email' => 'cust6@cgmi.test', 'first_name' => 'Rec', 'cart_details' => $cart, 'country' => 'BD']);
$sib = mi_row(['phone' => '01799000335', 'first_name' => 'Rec']);
$race_done = false;
$race = function ($q) use ($T, $entry, &$race_done, $wpdb) {
    if (!$race_done && stripos($q, 'UPDATE') === 0 && strpos($q, $T) !== false && strpos($q, 'recovered') !== false) {
        $race_done = true;
        $wpdb->query($wpdb->prepare("UPDATE {$T} SET status = 'recovered', recovered_order_id = 999999 WHERE id = %d", $entry));
    }
    return $q;
};
add_filter('query', $race);
$hq0 = mi_decrements();
$res = mi_recover($entry);
remove_filter('query', $race);
$oid = (int) ($res['data']['order_id'] ?? 0);
if ($oid) { $created_orders[] = $oid; }
$e = mi_get($entry);
mi_check('R8 concurrent Recover: entry keeps the other click\'s order id, one decrement (the deleted row only)',
    $race_done && $e !== null && $e->status === 'recovered' && (int) $e->recovered_order_id === 999999 && mi_get($sib) === null && mi_decrements() - $hq0 === 1,
    ($e ? $e->status . '/' . $e->recovered_order_id : 'missing') . ' decrements=' . (mi_decrements() - $hq0));
// N11: two Recover clicks at once must not create two WooCommerce orders.
// The handler takes a per-entry lock before it reads the entry and creates
// the order; the click that does not get the lock creates nothing.
function mi_lock_name($entry_id) {
    return 'cg_recover_lock_' . (int) $entry_id;
}
function mi_lock_raw($entry_id) {
    global $wpdb;
    return $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", mi_lock_name($entry_id)));
}
function mi_set_lock($entry_id, $value) {
    global $wpdb;
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", mi_lock_name($entry_id)));
    $wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", mi_lock_name($entry_id), $value));
    wp_cache_delete(mi_lock_name($entry_id), 'options');
}
function mi_orders_for_entry($entry_id) {
    return wc_get_orders(['limit' => -1, 'return' => 'ids', 'meta_key' => '_cg_recovered_from', 'meta_value' => (string) $entry_id]);
}

// R9 another click holds the lock: no order, entry unchanged, no decrement.
$entry = mi_row(['phone' => '01799000336', 'email' => 'cust7@cgmi.test', 'first_name' => 'Rec', 'cart_details' => $cart, 'country' => 'BD']);
$r9_lock = time() . '-111111';
mi_set_lock($entry, $r9_lock);
$hq0 = mi_decrements();
$res = mi_recover($entry);
$oid = (int) ($res['data']['order_id'] ?? 0);
if ($oid) { $created_orders[] = $oid; }
$e = mi_get($entry);
mi_check('R9 lock held by another click: no order, entry still incomplete, no decrement, lock untouched',
    empty($res['success']) && $oid === 0 && count(mi_orders_for_entry($entry)) === 0 && $e && $e->status === 'incomplete' && mi_decrements() === $hq0 && mi_lock_raw($entry) === $r9_lock,
    wp_json_encode($res['data']['message'] ?? $res) . ' orders=' . count(mi_orders_for_entry($entry)));
foreach (mi_orders_for_entry($entry) as $x) { $created_orders[] = $x; }
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", mi_lock_name($entry)));
mi_clear_rows();

// R10 the other click finishes while we wait for the lock: the entry is
// read again under the lock, so it is already recovered and we stop.
$entry = mi_row(['phone' => '01799000337', 'email' => 'cust8@cgmi.test', 'first_name' => 'Rec', 'cart_details' => $cart, 'country' => 'BD']);
$r10_fired = false;
$r10 = function ($q) use ($T, $entry, &$r10_fired, $wpdb) {
    if (!$r10_fired && stripos($q, 'INSERT') === 0 && strpos($q, mi_lock_name($entry)) !== false) {
        $r10_fired = true;
        $wpdb->query($wpdb->prepare("UPDATE {$T} SET status = 'recovered', recovered_order_id = 999998 WHERE id = %d", $entry));
    }
    return $q;
};
add_filter('query', $r10);
$hq0 = mi_decrements();
$res = mi_recover($entry);
remove_filter('query', $r10);
$oid = (int) ($res['data']['order_id'] ?? 0);
if ($oid) { $created_orders[] = $oid; }
foreach (mi_orders_for_entry($entry) as $x) { if ($x !== $oid) { $created_orders[] = $x; } }
$e = mi_get($entry);
mi_check('R10 entry recovered by the other click before our lock: no second order, no decrement, lock released',
    $r10_fired && empty($res['success']) && count(mi_orders_for_entry($entry)) === 0 && $e && (int) $e->recovered_order_id === 999998 && mi_decrements() === $hq0 && mi_lock_raw($entry) === null,
    'fired=' . ($r10_fired ? 1 : 0) . ' orders=' . count(mi_orders_for_entry($entry)) . ' ' . wp_json_encode($res['data']['message'] ?? $res));
mi_clear_rows();

// R11 a stale lock (a request died) does not block Recover for ever; the
// lock is released afterwards.
$entry = mi_row(['phone' => '01799000338', 'email' => 'cust10@cgmi.test', 'first_name' => 'Rec', 'cart_details' => $cart, 'country' => 'BD']);
mi_set_lock($entry, (time() - 600) . '-222222');
$res = mi_recover($entry);
$oid = (int) ($res['data']['order_id'] ?? 0);
if ($oid) { $created_orders[] = $oid; }
$e = mi_get($entry);
mi_check('R11 stale lock: Recover creates one order, entry recovered, lock released',
    !empty($res['success']) && $oid > 0 && count(mi_orders_for_entry($entry)) === 1 && $e && $e->status === 'recovered' && mi_lock_raw($entry) === null,
    wp_json_encode($res['data']['message'] ?? $res) . ' lock=' . var_export(mi_lock_raw($entry), true));
mi_clear_rows();

// R13 a slow Recover (WC hooks, email or SMS) may hold the lock for minutes;
// a lock taken 290 seconds ago is still live and blocks a second click.
$entry = mi_row(['phone' => '01799000340', 'email' => 'cust12@cgmi.test', 'first_name' => 'Rec', 'cart_details' => $cart, 'country' => 'BD']);
$r13_lock = (time() - 290) . '-333333';
mi_set_lock($entry, $r13_lock);
$res = mi_recover($entry);
$oid = (int) ($res['data']['order_id'] ?? 0);
if ($oid) { $created_orders[] = $oid; }
foreach (mi_orders_for_entry($entry) as $x) { if ($x !== $oid) { $created_orders[] = $x; } }
$e = mi_get($entry);
mi_check('R13 lock 290s old is still live: no order, entry still incomplete, lock untouched',
    empty($res['success']) && $oid === 0 && count(mi_orders_for_entry($entry)) === 0 && $e && $e->status === 'incomplete' && mi_lock_raw($entry) === $r13_lock,
    wp_json_encode($res['data']['message'] ?? $res) . ' orders=' . count(mi_orders_for_entry($entry)));
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", mi_lock_name($entry)));
mi_clear_rows();

// R14 a lock older than the stale window (310 seconds) is taken over.
$entry = mi_row(['phone' => '01799000341', 'email' => 'cust13@cgmi.test', 'first_name' => 'Rec', 'cart_details' => $cart, 'country' => 'BD']);
mi_set_lock($entry, (time() - 310) . '-444444');
$res = mi_recover($entry);
$oid = (int) ($res['data']['order_id'] ?? 0);
if ($oid) { $created_orders[] = $oid; }
$e = mi_get($entry);
mi_check('R14 lock 310s old is stale: Recover creates one order, lock released',
    !empty($res['success']) && $oid > 0 && count(mi_orders_for_entry($entry)) === 1 && $e && $e->status === 'recovered' && mi_lock_raw($entry) === null,
    wp_json_encode($res['data']['message'] ?? $res) . ' lock=' . var_export(mi_lock_raw($entry), true));
mi_clear_rows();

// R12 Recover that fails (no cart items) releases the lock and leaves the
// entry as it was.
$entry = mi_row(['phone' => '01799000339', 'email' => 'cust11@cgmi.test', 'first_name' => 'Rec', 'cart_details' => '[]', 'country' => 'BD']);
$r12_new = function ($id) use (&$created_orders) { $created_orders[] = (int) $id; };
add_action('woocommerce_new_order', $r12_new);
$res = mi_recover($entry);
remove_action('woocommerce_new_order', $r12_new);
$oid = (int) ($res['data']['order_id'] ?? 0);
if ($oid) { $created_orders[] = $oid; }
foreach (mi_orders_for_entry($entry) as $x) { if ($x !== $oid) { $created_orders[] = $x; } }
$e = mi_get($entry);
mi_check('R12 failed Recover: lock released, entry still incomplete',
    empty($res['success']) && $e && $e->status === 'incomplete' && mi_lock_raw($entry) === null && !$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", mi_lock_name($entry))),
    wp_json_encode($res['data']['message'] ?? $res));
mi_clear_rows();
remove_all_filters('wp_doing_ajax');
remove_all_filters('wp_die_ajax_handler');
mi_clear_rows();

// ---------- Storefront regression ----------
// Shopper's request: device cookie set, not an admin screen.
mi_admin_context(true, false);
// S1 checkout order: shopper's device cookie and phone still match.
// The cookie row carries the order's email: a device fingerprint alone
// does not identify the customer (another customer's row may share it).
$fp_row = $has_fp_col ? mi_row(['device_fingerprint' => $ADMIN_FP, 'phone' => '01799000555', 'email' => 'cust20@cgmi.test']) : 0;
$ph_row = mi_row(['phone' => '01799000444']);
$so = new WC_Order();
$so->set_created_via('checkout');
$so->set_billing_phone('01799000444');
$so->set_billing_email('cust20@cgmi.test');
$so->save();
$created_orders[] = $so->get_id();
cg_delete_incomplete_checkout_on_order_completion($so->get_id());
mi_check('S1 storefront order: row with the same phone is cleaned up', mi_get($ph_row) === null);
if ($has_fp_col) {
    mi_check('S2 storefront order: row with the shopper\'s device cookie is cleaned up', mi_get($fp_row) === null);
}
mi_clear_rows();

// S3 WooCommerce "Add order" in wp-admin (created_via admin): the first call
// has no identifier and must not mark the order, so the call after the
// billing phone is set still cleans up. Like a manual invoice (Rafi spec
// 5.1) the matching row is kept as recovered and its usage is given back.
mi_admin_context(false, true);
$ph_row = mi_row(['phone' => '01799000666']);
$hq3 = mi_decrements();
$so = new WC_Order();
$so->set_created_via('admin');
$so->save();
$created_orders[] = $so->get_id();
cg_delete_incomplete_checkout_on_order_completion($so->get_id());
$so = wc_get_order($so->get_id());
$flag_after_empty = $so->get_meta('_cg_incomplete_cleaned');
$so->set_billing_phone('01799000666');
$so->save();
cg_delete_incomplete_checkout_on_order_completion($so->get_id());
mi_admin_context(true, false);
$s3 = mi_get($ph_row);
mi_check('S3 empty first call leaves no flag; second call with phone keeps the row as recovered, one decrement',
    $flag_after_empty === '' && $s3 && $s3->status === 'recovered' && (int) $s3->recovered_order_id === $so->get_id() && mi_decrements() - $hq3 === 1,
    'flag=' . var_export($flag_after_empty, true) . ' row=' . ($s3 ? $s3->status . '/' . $s3->recovered_order_id : 'missing') . ' decrements=' . (mi_decrements() - $hq3));
mi_clear_rows();

// A1 WooCommerce "Add order" with two matching rows (Rafi spec 5.1, same as
// the manual invoice): the newest is kept as recovered with a note, the
// older one is deleted, usage is given back for both; a row that matches
// only the admin's device cookie is kept.
mi_admin_context(true, true);
$a_old = mi_row(['phone' => '01799000655', 'created_at' => date('Y-m-d H:i:s', current_time('timestamp') - 7200)]);
$a_new = mi_row(['phone' => '01799000655', 'email' => 'cust5@cgmi.test', 'created_at' => date('Y-m-d H:i:s', current_time('timestamp') - 60)]);
$a_fp = $has_fp_col ? mi_row(['phone' => '01799000656', 'device_fingerprint' => $ADMIN_FP]) : 0;
$hqa = mi_decrements();
$ao = new WC_Order();
$ao->set_created_via('admin');
$ao->set_billing_phone('01799000655');
$ao->set_billing_email('cust5@cgmi.test');
$ao->save();
$created_orders[] = $ao->get_id();
cg_delete_incomplete_checkout_on_order_completion($ao->get_id());
$an = mi_get($a_new);
mi_check('A1 admin "Add order": newest row recovered with the order id and a note, older row deleted, 2 decrements',
    $an && $an->status === 'recovered' && (int) $an->recovered_order_id === $ao->get_id() && strpos((string) $an->admin_notes, '#' . $ao->get_id()) !== false
    && strpos((string) $an->admin_notes, 'WooCommerce admin order') !== false
    && mi_get($a_old) === null && mi_decrements() - $hqa === 2,
    ($an ? $an->status . '/' . $an->recovered_order_id : 'missing') . ' old=' . (mi_get($a_old) ? 'kept' : 'gone') . ' decrements=' . (mi_decrements() - $hqa));
if ($has_fp_col) {
    mi_check('A2 admin "Add order": row matching only the admin\'s device cookie is kept', mi_get($a_fp) !== null);
}
mi_admin_context(true, false);
mi_clear_rows();

// S4 storefront: a recovered row of the same customer is kept.
$hist = mi_row(['phone' => '01799000666', 'status' => 'recovered', 'recovered_order_id' => 1]);
$so2 = new WC_Order();
$so2->set_created_via('checkout');
$so2->set_billing_phone('01799000666');
$so2->save();
$created_orders[] = $so2->get_id();
cg_delete_incomplete_checkout_on_order_completion($so2->get_id());
mi_check('S4 storefront order: recovered row of the same customer is kept', mi_get($hist) !== null);

// S5 order from another checkout plugin (created_via not set): the shopper's
// row (device cookie and the order's phone) is still cleaned up.
mi_admin_context(true);
if ($has_fp_col) {
    $fp_row = mi_row(['device_fingerprint' => $ADMIN_FP, 'phone' => '01799000677']);
    $so3 = new WC_Order();
    $so3->set_billing_phone('01799000677');
    $so3->save();
    $created_orders[] = $so3->get_id();
    cg_delete_incomplete_checkout_on_order_completion($so3->get_id());
    mi_check('S5 order with empty created_via: row with the device cookie is cleaned up', mi_get($fp_row) === null);
}
mi_admin_context(false);
mi_clear_rows();

// ---------- cleanup ----------
mi_clear_rows();
foreach ($created_orders as $id) {
    $x = wc_get_order($id);
    if ($x) { $x->delete(true); }
}
wp_delete_post($PID, true);
unset($_COOKIE['cg_device_fp'], $_POST['entry_id'], $_POST['nonce'], $_REQUEST['nonce'], $_REQUEST['entry_id']);
if ($saved_cache === false) { delete_transient('cg_api_cache'); } else { set_transient('cg_api_cache', $saved_cache, DAY_IN_SECONDS); }
if ($saved_last === null) { delete_option('cg_last_known_plan'); } else { update_option('cg_last_known_plan', $saved_last, false); }
$R = $GLOBALS['R'];
echo "== {$R['pass']} pass, {$R['fail']} fail\n";
