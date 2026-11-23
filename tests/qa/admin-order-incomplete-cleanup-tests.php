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
function mi_decrements() {
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
$cust = mi_row(['phone' => '01799000111', 'email' => 'cust1@cgmi.test', 'first_name' => 'Cust']);
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
mi_check('M1 manual invoice: incomplete row with the same phone/email is cleaned up', mi_get($cust) === null);
if ($has_fp_col) {
    mi_check('M2 manual invoice: row matching only the admin\'s device cookie is kept', mi_get($adm_fp) !== null);
}
if ($ADMIN_SID !== '') {
    mi_check('M3 manual invoice: row matching only the admin\'s WC session is kept', mi_get($adm_sid) !== null);
}
$fo = $ok ? wc_get_order($o->get_id()) : null;
mi_check('M4 manual invoice order marked _cg_incomplete_cleaned=1', $fo && $fo->get_meta('_cg_incomplete_cleaned') === '1');
mi_check('M5 one HQ decrement for the one deleted row', mi_decrements() - $hq0 === 1, 'decrements=' . (mi_decrements() - $hq0));
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
mi_check('R5 one HQ decrement for the one deleted row', mi_decrements() - $hq0 === 1, 'decrements=' . (mi_decrements() - $hq0));
remove_all_filters('wp_doing_ajax');
remove_all_filters('wp_die_ajax_handler');
mi_clear_rows();

// ---------- Storefront regression ----------
// Shopper's request: device cookie set, not an admin screen.
mi_admin_context(true, false);
// S1 checkout order: shopper's device cookie and phone still match.
$fp_row = $has_fp_col ? mi_row(['device_fingerprint' => $ADMIN_FP, 'phone' => '01799000555']) : 0;
$ph_row = mi_row(['phone' => '01799000444']);
$so = new WC_Order();
$so->set_created_via('checkout');
$so->set_billing_phone('01799000444');
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
// billing phone is set still cleans up.
mi_admin_context(false, true);
$ph_row = mi_row(['phone' => '01799000666']);
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
mi_check('S3 empty first call leaves no flag; second call with phone cleans up', $flag_after_empty === '' && mi_get($ph_row) === null, 'flag=' . var_export($flag_after_empty, true));

// S4 storefront: a recovered row of the same customer is kept.
$hist = mi_row(['phone' => '01799000666', 'status' => 'recovered', 'recovered_order_id' => 1]);
$so2 = new WC_Order();
$so2->set_created_via('checkout');
$so2->set_billing_phone('01799000666');
$so2->save();
$created_orders[] = $so2->get_id();
cg_delete_incomplete_checkout_on_order_completion($so2->get_id());
mi_check('S4 storefront order: recovered row of the same customer is kept', mi_get($hist) !== null);

// S5 order from another checkout plugin (created_via not set): the shopper's device cookie still matches.
mi_admin_context(true);
if ($has_fp_col) {
    $fp_row = mi_row(['device_fingerprint' => $ADMIN_FP]);
    $so3 = new WC_Order();
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
