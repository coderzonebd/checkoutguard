<?php
/**
 * QA tests for the duplicate order blocker (cg_check_duplicate_order_core).
 *
 * Run with: wp eval-file tests/qa/duplicate-blocker-tests.php
 * Local test site only. All outgoing HTTP and mail are blocked while the tests run.
 *
 * S1   (control): the same customer placing the same cart again is still blocked.
 * U    (no options): with no duplicate_blocker_* option saved, every switch on the
 *      settings page (rendered HTML) shows the same on/off state that checkout
 *      really uses. Runtime state is read from behaviour, not from code.
 * K    (fresh install): activation seeds the duplicate blocker options, and after
 *      activation the settings page and checkout again agree on every switch.
 * G    (message): the block message never contains an order number.
 * F4   (Store API retry): runs the real Store API checkout in-process, each attempt
 *      from a new cart session. A blocked attempt must not count as an earlier
 *      order: once the real order is outside the window, the next attempt goes
 *      through (F4a at t+80, F4b at t+100 after two blocked attempts). F4-ctl
 *      checks that attempts inside the window are still blocked.
 *      F4 runs last because it defines REST_REQUEST for the rest of the process.
 *      F4c: a blocked attempt leaves no pending order or draft; if WC made one
 *      it is cancelled or in the trash. The one live order left must be the
 *      t+0 order by id, not just one order by count.
 *      F4d: closing a blocked attempt sends no admin "Order cancelled" email;
 *      a normal cancel right after still sends one.
 *
 * Pending founder decision (skipped unless QA_DUP_PENDING=1 is set):
 * P-off  default OFF: with no options, a different customer who only shares the
 *        IP or the device is not blocked.
 * P-id   with match_ip=1 / match_device=1 saved, a different customer who only
 *        shares the IP or the device is still not blocked.
 * If the founder decides the other way, delete or flip these and re-check their
 * mutants. Do not enable them before that decision.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_filter('pre_http_request', function () {
    return new WP_Error('qa_blocked', 'HTTP blocked in QA test');
}, 1);

// wp eval-file runs this file inside a function, so shared state lives in $GLOBALS.
$GLOBALS['qa_pass'] = 0;
$GLOBALS['qa_fail'] = 0;
$GLOBALS['qa_skip'] = 0;
$GLOBALS['qa_orders'] = [];

// No mail from the test orders.
add_filter('pre_wp_mail', '__return_false');

function qa_dup_check($name, $ok, $detail = '')
{
    if ($ok) {
        $GLOBALS['qa_pass']++;
        echo "PASS {$name}\n";
    } else {
        $GLOBALS['qa_fail']++;
        echo "FAIL {$name}" . ($detail !== '' ? " :: {$detail}" : '') . "\n";
    }
}

function qa_dup_product()
{
    $p = new WC_Product_Simple();
    $p->set_name('QA dup product');
    $p->set_regular_price('500');
    $p->set_status('publish');
    $p->save();
    return $p;
}

function qa_dup_order($product, $name, $phone, $email, $ip, $device_fp)
{
    $o = wc_create_order();
    $o->set_billing_first_name($name);
    $o->set_billing_last_name('QA');
    $o->add_product($product, 1);
    $o->set_billing_phone($phone);
    $o->set_billing_email($email);
    $o->set_customer_ip_address($ip);
    if ($device_fp !== '') {
        $o->update_meta_data('_cg_device_fingerprint', $device_fp);
    }
    $o->calculate_totals();
    $o->set_status('processing');
    $o->save();
    $GLOBALS['qa_orders'][] = $o->get_id();
    return $o;
}

function qa_dup_customer($product, $name, $phone, $email, $ip, $device_fp)
{
    return [
        'name'         => $name . ' QA',
        'phone'        => $phone,
        'email'        => $email,
        'ip'           => $ip,
        'device_fp'    => $device_fp,
        'address_hash' => '',
        'products'     => [$product->get_id() => 1],
        'total_amount' => 500.0,
    ];
}

function qa_dup_set_options($dup)
{
    $o = get_option('cg_plugin_options', []);
    foreach (array_keys($o) as $k) {
        if (strpos($k, 'duplicate_blocker_') === 0) {
            unset($o[$k]);
        }
    }
    update_option('cg_plugin_options', array_merge($o, $dup));
}

function qa_dup_cleanup()
{
    foreach ($GLOBALS['qa_orders'] as $id) {
        $o = wc_get_order($id);
        if ($o) {
            $o->delete(true);
        }
    }
    $GLOBALS['qa_orders'] = [];
}

$saved_options = get_option('cg_plugin_options', []);
// K deletes cg_db_version to simulate a fresh install; put it back at the end.
$saved_db_version = get_option('cg_db_version', null);
$product = qa_dup_product();

// Test data only (made-up names, numbers and addresses).
$A = ['Alpha', '01700000101', 'qa-a@example.test', '203.0.113.10', 'qa-fp-aaaa'];
$B = ['Bravo', '01800000202', 'qa-b@example.test', '198.51.100.20', 'qa-fp-bbbb'];

$all_on = [
    'duplicate_blocker_enabled'      => 1,
    'duplicate_blocker_match_phone'  => 1,
    'duplicate_blocker_match_email'  => 1,
    'duplicate_blocker_match_ip'     => 1,
    'duplicate_blocker_match_device' => 1,
];

function qa_dup_switches()
{
    return ['enabled', 'match_phone', 'match_email', 'match_ip', 'match_device'];
}

// On/off state of each duplicate blocker checkbox as the settings page renders it.
function qa_dup_ui_state()
{
    if (!function_exists('cg_render_fraud_blocker_page')) {
        require_once CG_ADMIN_DIR . 'admin-pages.php';
    }
    $prev_user = get_current_user_id();
    $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
    wp_set_current_user($admins ? (int) $admins[0] : 1);
    ob_start();
    cg_render_fraud_blocker_page();
    $html = ob_get_clean();
    // Render as admin, then go back: F4 checks out as a guest.
    wp_set_current_user($prev_user);
    $state = [];
    if (preg_match_all('/<input\b[^>]*\bid="cg_duplicate_blocker_(\w+)"[^>]*>/', $html, $m, PREG_SET_ORDER)) {
        foreach ($m as $hit) {
            if (strpos($hit[0], 'type="checkbox"') !== false) {
                $state[$hit[1]] = (bool) preg_match('/\bchecked\b/', $hit[0]);
            }
        }
    }
    return $state;
}

// On/off state of each switch as checkout really behaves, read from behaviour:
// an earlier order by A, then one attempt that shares only one identifier.
function qa_dup_runtime_state($product, $A, $B)
{
    $tries = [
        'enabled'      => [$A[0], $A[1], $A[2], $A[3], $A[4]],
        'match_phone'  => [$B[0], $A[1], $B[2], $B[3], ''],
        'match_email'  => [$B[0], $B[1], $A[2], $B[3], ''],
        'match_ip'     => [$B[0], $B[1], $B[2], $A[3], $B[4]],
        'match_device' => [$B[0], $B[1], $B[2], $B[3], $A[4]],
    ];
    $state = [];
    foreach ($tries as $sw => $c) {
        qa_dup_order($product, $A[0], $A[1], $A[2], $A[3], $A[4]);
        $r = cg_check_duplicate_order_core(qa_dup_customer($product, $c[0], $c[1], $c[2], $c[3], $c[4]));
        $state[$sw] = is_string($r) && $r !== '';
        qa_dup_cleanup();
    }
    return $state;
}

// One guest checkout through the real Store API route, from a new cart session.
// Returns [http_status, error_code].
function qa_dup_store_checkout($pid, $name, $phone, $email)
{
    // Each real checkout is a new PHP request; reset the engine's per-request result cache.
    if (class_exists('CG_Validation_Engine') && property_exists('CG_Validation_Engine', 'evaluated_contexts')) {
        $rp = new ReflectionProperty('CG_Validation_Engine', 'evaluated_contexts');
        $rp->setAccessible(true);
        $rp->setValue(null, []);
    }
    // New browser session: no cart and no draft order carried over from the last attempt.
    if (function_exists('WC') && WC()->session) {
        add_filter('woocommerce_set_cookie_enabled', '__return_false'); // CLI: no headers to send
        WC()->session->forget_session();
        remove_filter('woocommerce_set_cookie_enabled', '__return_false');
    }
    if (function_exists('WC') && WC()->cart) {
        WC()->cart->empty_cart(true);
    }
    $r = new WP_REST_Request('POST', '/wc/store/v1/cart/add-item');
    $r->set_header('Content-Type', 'application/json');
    $r->set_body(wp_json_encode(['id' => $pid, 'quantity' => 1]));
    $res = rest_do_request($r);
    $h = $res->get_headers();
    $addr = ['first_name' => $name, 'last_name' => 'QA', 'address_1' => 'Road 1', 'city' => 'Dhaka', 'postcode' => '1200', 'country' => 'BD', 'state' => 'BD-13'];
    $c = new WP_REST_Request('POST', '/wc/store/v1/checkout');
    $c->set_header('Content-Type', 'application/json');
    $c->set_header('Cart-Token', $h['Cart-Token'] ?? '');
    $c->set_body(wp_json_encode([
        'billing_address'  => $addr + ['email' => $email, 'phone' => $phone],
        'shipping_address' => $addr,
        'payment_method'   => 'cod',
    ]));
    // rest_do_request() does not set these; a real /wc/store/v1/checkout request does.
    if (!defined('REST_REQUEST')) {
        define('REST_REQUEST', true);
    }
    if (empty($GLOBALS['wp']) || !is_object($GLOBALS['wp'])) {
        $GLOBALS['wp'] = new WP();
    }
    $prev_route = $GLOBALS['wp']->query_vars['rest_route'] ?? null;
    $GLOBALS['wp']->query_vars['rest_route'] = '/wc/store/v1/checkout';
    $res = rest_do_request($c);
    if ($prev_route === null) {
        unset($GLOBALS['wp']->query_vars['rest_route']);
    } else {
        $GLOBALS['wp']->query_vars['rest_route'] = $prev_route;
    }
    $d = $res->get_data();
    return [$res->get_status(), is_array($d) ? (string) ($d['code'] ?? '') : ''];
}

// Every order (any status) placed with this billing email.
function qa_dup_orders_by_email($email)
{
    $statuses = array_merge(array_keys(wc_get_order_statuses()), ['checkout-draft', 'wc-checkout-draft', 'trash']);
    return wc_get_orders(['limit' => -1, 'return' => 'ids', 'billing_email' => $email, 'status' => $statuses]);
}

// Orders with this billing email that are still live: not cancelled, not
// failed, not in the trash (a deleted order is gone and not counted).
function qa_dup_live_orders($email)
{
    $out = [];
    foreach (qa_dup_orders_by_email($email) as $id) {
        $o = wc_get_order($id);
        if ($o && !in_array($o->get_status(), ['cancelled', 'failed', 'trash'], true)) {
            $out[$id] = $o->get_status();
        }
    }
    return $out;
}

function qa_dup_fmt_live($live)
{
    $parts = [];
    foreach ($live as $id => $status) {
        $parts[] = $id . ':' . $status;
    }
    return count($live) . ' live (' . implode(',', $parts) . ')';
}

// The id of the only order with this billing email, or 0 if there is not
// exactly one.
function qa_dup_single_order_id($email)
{
    $ids = array_map('intval', qa_dup_orders_by_email($email));
    return count($ids) === 1 ? $ids[0] : 0;
}

// Move orders back in time without saving them through WC (a save would run the
// plugin's own order save guard and change the state under test).
function qa_dup_age_orders($ids, $secs)
{
    global $wpdb;
    $ids = array_map('intval', (array) $ids);
    if (!$ids) {
        return;
    }
    $in = implode(',', $ids);
    $wpdb->query($wpdb->prepare("UPDATE {$wpdb->posts} SET post_date = DATE_SUB(post_date, INTERVAL %d SECOND), post_date_gmt = DATE_SUB(post_date_gmt, INTERVAL %d SECOND) WHERE ID IN ($in)", $secs, $secs));
    $hpos = $wpdb->prefix . 'wc_orders';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $hpos)) === $hpos) {
        $wpdb->query($wpdb->prepare("UPDATE {$hpos} SET date_created_gmt = DATE_SUB(date_created_gmt, INTERVAL %d SECOND) WHERE id IN ($in)", $secs));
    }
    foreach ($ids as $id) {
        clean_post_cache($id);
    }
    wp_cache_flush();
}

function qa_dup_delete_orders($ids)
{
    foreach ((array) $ids as $id) {
        $o = wc_get_order($id);
        if ($o) {
            $o->delete(true);
        }
    }
}

function qa_dup_has_number($msg, $num)
{
    if (!is_string($msg)) {
        return false;
    }
    return preg_match('/#\s*\d+/', $msg) === 1 || preg_match('/(?<!\d)' . preg_quote($num, '/') . '(?!\d)/', $msg) === 1;
}

// ---- S1: control, same customer again is blocked (both configurations) ----
foreach (['no options' => [], 'all match on' => $all_on] as $label => $cfg) {
    qa_dup_set_options($cfg);
    $a = qa_dup_order($product, $A[0], $A[1], $A[2], $A[3], $A[4]);
    $r = cg_check_duplicate_order_core(qa_dup_customer($product, $A[0], $A[1], $A[2], '192.0.2.99', ''));
    qa_dup_check("S1 [{$label}] same customer, same cart again is blocked", is_string($r) && $r !== '', var_export($r, true));
    qa_dup_cleanup();
}

// ---- G: no order number in any block message ----
qa_dup_set_options($all_on);
$a = qa_dup_order($product, $A[0], $A[1], $A[2], $A[3], $A[4]);
$num = (string) $a->get_order_number();
$cases = [
    'same phone'         => qa_dup_customer($product, $A[0], $A[1], 'qa-other@example.test', '192.0.2.99', ''),
    'same email'         => qa_dup_customer($product, $A[0], '01900000303', $A[2], '192.0.2.99', ''),
    'same IP, other customer'     => qa_dup_customer($product, $B[0], $B[1], $B[2], $A[3], $B[4]),
    'same device, other customer' => qa_dup_customer($product, $B[0], $B[1], $B[2], $B[3], $A[4]),
];
foreach ($cases as $label => $cust) {
    $r = cg_check_duplicate_order_core($cust);
    qa_dup_check(
        "G [{$label}] block message has no order number",
        !qa_dup_has_number($r, $num),
        'message: ' . var_export($r, true)
    );
}
qa_dup_cleanup();

// ---- U: no options, settings page state equals runtime state ----
qa_dup_set_options([]);
$ui = qa_dup_ui_state();
$rt = qa_dup_runtime_state($product, $A, $B);
foreach (qa_dup_switches() as $sw) {
    qa_dup_check(
        "U [{$sw}] no options: settings page matches checkout",
        isset($ui[$sw]) && $ui[$sw] === $rt[$sw],
        'page ' . (isset($ui[$sw]) ? ($ui[$sw] ? 'on' : 'off') : 'missing') . ', checkout ' . ($rt[$sw] ? 'on' : 'off')
    );
}

// ---- K: fresh install, activation seeds options, page and checkout agree ----
delete_option('cg_plugin_options');
delete_option('cg_db_version');
cg_plugin_activate();
if (function_exists('cg_maybe_run_migrations')) {
    cg_maybe_run_migrations();
}
$o = get_option('cg_plugin_options', []);
$seeded = array_values(array_filter(array_keys((array) $o), function ($k) {
    return strpos($k, 'duplicate_blocker_') === 0;
}));
qa_dup_check(
    'K-seed fresh install: activation saves the duplicate blocker options',
    in_array('duplicate_blocker_enabled', $seeded, true),
    'saved keys: ' . ($seeded ? implode(',', $seeded) : '(none)') . ', cg_db_version=' . get_option('cg_db_version', '(none)')
);
$ui = qa_dup_ui_state();
$rt = qa_dup_runtime_state($product, $A, $B);
$diff = [];
foreach (qa_dup_switches() as $sw) {
    if (!isset($ui[$sw]) || $ui[$sw] !== $rt[$sw]) {
        $diff[] = $sw . ' page=' . (isset($ui[$sw]) ? ($ui[$sw] ? 'on' : 'off') : 'missing') . ' checkout=' . ($rt[$sw] ? 'on' : 'off');
    }
}
qa_dup_check('K-match fresh install: settings page matches checkout on every switch', !$diff, implode('; ', $diff));

// ---- Pending founder decision: P-off and P-id (skipped by default) ----
$run_pending = getenv('QA_DUP_PENDING') === '1';
$pending = [
    'P-off [no options]'  => [],
    'P-id [all match on]' => $all_on,
];
foreach ($pending as $label => $cfg) {
    foreach (['ip' => [$B[0], $B[1], $B[2], $A[3], $B[4]], 'device' => [$B[0], $B[1], $B[2], $B[3], $A[4]]] as $what => $c) {
        $name = "{$label} different customer sharing only the {$what} is not blocked";
        if (!$run_pending) {
            $GLOBALS['qa_skip']++;
            echo "SKIP {$name} (pending founder decision)\n";
            continue;
        }
        qa_dup_set_options($cfg);
        qa_dup_order($product, $A[0], $A[1], $A[2], $A[3], $A[4]);
        $r = cg_check_duplicate_order_core(qa_dup_customer($product, $c[0], $c[1], $c[2], $c[3], $c[4]));
        qa_dup_check($name, $r === null, var_export($r, true));
        qa_dup_cleanup();
    }
}

// F4 runs last: the Store API helper defines REST_REQUEST, which cannot be
// undefined, so no other section may run after it.
// ---- F4: blocked Store API attempts do not count as earlier orders ----
add_filter('woocommerce_store_api_disable_nonce_check', '__return_true');
qa_dup_set_options(['duplicate_blocker_window' => 1]);
$F4 = ['Delta', '01600000404', 'qa-f4@example.test'];
qa_dup_delete_orders(qa_dup_orders_by_email($F4[2]));
$pid = $product->get_id();

// F4a: t+0 order, t+40 blocked, t+80 goes through.
$r0 = qa_dup_store_checkout($pid, $F4[0], $F4[1], $F4[2]);
$id0 = qa_dup_single_order_id($F4[2]);
qa_dup_age_orders(qa_dup_orders_by_email($F4[2]), 40);
$r40 = qa_dup_store_checkout($pid, $F4[0], $F4[1], $F4[2]);
qa_dup_check('F4-ctl [a] t+40 inside the window is blocked', $r0[0] === 200 && $r40[0] === 400, 't+0 ' . implode('/', $r0) . ', t+40 ' . implode('/', $r40));
// F4c: the blocked attempt leaves no pending order or draft behind; if WC
// created one it is cancelled or in the trash. Only the t+0 order is live,
// and it is the same order: a fix that cancels the real order and keeps the
// attempt would still leave one live order.
$live = qa_dup_live_orders($F4[2]);
qa_dup_check('F4c [a] blocked t+40 attempt leaves no live order (only the t+0 order)', $r40[0] === 400 && $id0 > 0 && array_keys($live) === [$id0], 't+0 id ' . $id0 . ', ' . qa_dup_fmt_live($live));
qa_dup_age_orders(qa_dup_orders_by_email($F4[2]), 40);
$r80 = qa_dup_store_checkout($pid, $F4[0], $F4[1], $F4[2]);
qa_dup_check('F4a t+80: real order is outside the window, blocked t+40 attempt does not block', $r80[0] === 200, 't+80 ' . implode('/', $r80));
qa_dup_delete_orders(qa_dup_orders_by_email($F4[2]));

// F4b: t+0 order, t+40 and t+50 blocked, t+100 goes through.
$r0 = qa_dup_store_checkout($pid, $F4[0], $F4[1], $F4[2]);
$id0 = qa_dup_single_order_id($F4[2]);
qa_dup_age_orders(qa_dup_orders_by_email($F4[2]), 40);
$r40 = qa_dup_store_checkout($pid, $F4[0], $F4[1], $F4[2]);
qa_dup_age_orders(qa_dup_orders_by_email($F4[2]), 10);
$r50 = qa_dup_store_checkout($pid, $F4[0], $F4[1], $F4[2]);
qa_dup_check('F4-ctl [b] t+40 and t+50 inside the window are blocked', $r0[0] === 200 && $r40[0] === 400 && $r50[0] === 400, 't+0 ' . implode('/', $r0) . ', t+40 ' . implode('/', $r40) . ', t+50 ' . implode('/', $r50));
$live = qa_dup_live_orders($F4[2]);
qa_dup_check('F4c [b] two blocked attempts leave no live order (only the t+0 order)', $r40[0] === 400 && $r50[0] === 400 && $id0 > 0 && array_keys($live) === [$id0], 't+0 id ' . $id0 . ', ' . qa_dup_fmt_live($live));
qa_dup_age_orders(qa_dup_orders_by_email($F4[2]), 50);
$r100 = qa_dup_store_checkout($pid, $F4[0], $F4[1], $F4[2]);
qa_dup_check('F4b t+100: two blocked attempts do not renew the window', $r100[0] === 200, 't+100 ' . implode('/', $r100));
qa_dup_delete_orders(qa_dup_orders_by_email($F4[2]));

// F4d: closing a blocked Store API attempt (pending -> cancelled) must not send
// the admin "Order cancelled" email; a normal cancel right after still does,
// which also shows mail capture works and the mute is only for that attempt.
$GLOBALS['qa_f4d_mail'] = [];
$qa_f4d_cap = function ($r, $atts) {
    $GLOBALS['qa_f4d_mail'][] = (string) ($atts['subject'] ?? '');
    return true; // never really send
};
$qa_f4d_cancelled = function () {
    return count(preg_grep('/has been cancelled/i', $GLOBALS['qa_f4d_mail']));
};
add_filter('pre_wp_mail', $qa_f4d_cap, 10, 2);
$r0 = qa_dup_store_checkout($pid, $F4[0], $F4[1], $F4[2]);
qa_dup_age_orders(qa_dup_orders_by_email($F4[2]), 40);
$GLOBALS['qa_f4d_mail'] = [];
$r40 = qa_dup_store_checkout($pid, $F4[0], $F4[1], $F4[2]);
$m_a = $qa_f4d_cancelled();
$n_cx = count(array_filter(array_map('wc_get_order', qa_dup_orders_by_email($F4[2])), function ($o) { return $o && $o->get_status() === 'cancelled'; }));
qa_dup_check('F4d [a] blocked t+40 attempt sends no "Order cancelled" email', $r0[0] === 200 && $r40[0] === 400 && $m_a === 0, 't+40 ' . implode('/', $r40) . ", cancelled mails=$m_a, cancelled orders=$n_cx, subjects=" . wp_json_encode($GLOBALS['qa_f4d_mail']));
$o = wc_create_order();
$o->set_billing_email('qa-f4d-ctl@example.test');
$o->set_status('pending');
$o->save();
$GLOBALS['qa_f4d_mail'] = [];
$o->set_status('cancelled', 'QA F4d control');
$o->save();
$m_b = $qa_f4d_cancelled();
qa_dup_check('F4d [b] a normal pending order cancelled after it still sends 1 "Order cancelled" email', $m_b === 1, "cancelled mails=$m_b, subjects=" . wp_json_encode($GLOBALS['qa_f4d_mail']));
$o->delete(true);
remove_filter('pre_wp_mail', $qa_f4d_cap, 10);
qa_dup_delete_orders(qa_dup_orders_by_email($F4[2]));
remove_filter('woocommerce_store_api_disable_nonce_check', '__return_true');

update_option('cg_plugin_options', $saved_options);
$product->delete(true);
if ($saved_db_version === null) {
    delete_option('cg_db_version');
} else {
    update_option('cg_db_version', $saved_db_version);
}

echo 'RESULT pass=' . $GLOBALS['qa_pass'] . ' fail=' . $GLOBALS['qa_fail'] . ' skip=' . $GLOBALS['qa_skip'] . "\n";
