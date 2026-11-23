<?php
/**
 * H2 (1.5.2): a tracker request (cg_save_checkout_data) that arrives after
 * the order cleanup must not add the checkout back as incomplete or count
 * it again; a block-checkout draft must not run the cleanup early.
 *
 * Run on a local test site only (never production):
 *   wp eval-file tests/qa/ghost-incomplete-checkout-tests.php
 * HTTP is answered by a pre_http_request filter in this process; nothing
 * leaves the machine. Options, rows, orders and transients made here are
 * removed.
 */
if (!defined('ABSPATH')) {
    exit;
}
global $wpdb;
$GLOBALS['QA_T'] = $wpdb->prefix . 'cg_incomplete_checkouts';
$GLOBALS['QA_ORIG'] = ['cg_plugin_options' => get_option('cg_plugin_options', null), 'cg_api_cache' => get_transient('cg_api_cache')];
$GLOBALS['QA_LOG'] = [];
add_filter('pre_http_request', function ($pre, $args, $url) {
    $body = json_decode((string) ($args['body'] ?? ''), true);
    $GLOBALS['QA_LOG'][] = preg_replace('#^.*?/api/v1#', '', (string) parse_url($url, PHP_URL_PATH)) . ':' . (is_array($body) ? (string) ($body['action_type'] ?? '') : '');
    return ['headers' => new WpOrg\Requests\Utility\CaseInsensitiveDictionary([]), 'body' => '{"status":"success"}', 'response' => ['code' => 200, 'message' => ''], 'cookies' => [], 'filename' => null];
}, PHP_INT_MAX, 3);
$GLOBALS['QA_WARN'] = [];
set_error_handler(function ($no, $str, $file, $line) {
    if (strpos($file, 'checkoutguard') !== false) {
        $GLOBALS['QA_WARN'][] = "$str @ " . basename($file) . ":$line";
    }
    return false;
});
// wp-cli does not load the Store API, which registers the block checkout
// draft status; register it the same way here.
if (!array_key_exists('wc-checkout-draft', wc_get_order_statuses())) {
    register_post_status('wc-checkout-draft', ['label' => 'Draft', 'internal' => true, 'public' => false, 'exclude_from_search' => true, 'show_in_admin_all_list' => false, 'show_in_admin_status_list' => false]);
    add_filter('wc_order_statuses', function ($s) {
        $s['wc-checkout-draft'] = 'Draft';
        return $s;
    }, PHP_INT_MAX);
}
class QA_H2_Die extends Exception {}
add_filter('wp_doing_ajax', '__return_true', PHP_INT_MAX);
add_filter('wp_die_ajax_handler', function () {
    return function ($msg = '') {
        throw new QA_H2_Die(is_string($msg) ? $msg : '');
    };
}, PHP_INT_MAX);
$o = is_array($GLOBALS['QA_ORIG']['cg_plugin_options']) ? $GLOBALS['QA_ORIG']['cg_plugin_options'] : [];
$o['api_key'] = 'qa-h2-key';
update_option('cg_plugin_options', $o);
$GLOBALS['QA_ORDERS'] = [];
$GLOBALS['QA_TAG'] = 'qah2' . wp_rand(10000, 99999);
$GLOBALS['QA_MARKS'] = [];

function qa_reset() {
    set_transient('cg_api_cache', ['usage' => ['checkouts' => 10, 'contact_reveals' => 0], 'limits' => ['checkouts' => 100, 'contact_reveals' => 0]], DAY_IN_SECONDS);
    $GLOBALS['QA_LOG'] = [];
}
function qa_usage() {
    $c = get_transient('cg_api_cache');
    return (int) ($c['usage']['checkouts'] ?? -1);
}
function qa_incr_calls() {
    return count(array_filter($GLOBALS['QA_LOG'], function ($p) { return $p === '/sites/increment-usage:checkouts'; }));
}
function qa_rows($col, $val) {
    global $wpdb;
    return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$GLOBALS['QA_T']} WHERE {$col} = %s", $val));
}
function qa_remember_marks(array $vals) {
    foreach ($vals as $v) {
        $GLOBALS['QA_MARKS'][] = $v;
    }
}
// Customer: device fingerprint, phone (raw BD number), email.
function qa_cust($sfx) {
    $n = (string) wp_rand(10000000, 99999999);
    return ['fp' => $GLOBALS['QA_TAG'] . "fp$sfx", 'phone' => '017' . $n, 'email' => $GLOBALS['QA_TAG'] . "$sfx@example.test"];
}
// Each customer has its own WC session (the tracker request and the order
// request of one customer share it). wp-cli has one session for the whole
// run, so its customer id is set per customer here.
function qa_session(array $c) {
    if (function_exists('WC') && WC() && !WC()->session && function_exists('wc_load_cart')) {
        wc_load_cart();
    }
    if (function_exists('WC') && WC() && WC()->session) {
        $p = new ReflectionProperty(get_class(WC()->session), '_customer_id');
        $p->setAccessible(true);
        $p->setValue(WC()->session, 't_' . $c['fp']);
        qa_remember_marks(['t_' . $c['fp']]);
    }
}
// Tracker request as tracker.js sends it.
function qa_save(array $c, array $only = ['fp', 'phone', 'email']) {
    $_POST = [
        'action' => 'cg_save_checkout_data',
        'nonce' => wp_create_nonce('cg_save_checkout_data_nonce'),
        'billing_first_name' => 'QA',
        'billing_phone' => in_array('phone', $only, true) ? $c['phone'] : '',
        'billing_email' => in_array('email', $only, true) ? $c['email'] : '',
        'device_fingerprint' => in_array('fp', $only, true) ? $c['fp'] : '',
    ];
    $_REQUEST = $_POST;
    unset($_COOKIE['cg_device_fp']);
    qa_session($c);
    ob_start();
    try {
        cg_handle_save_checkout_data();
    } catch (QA_H2_Die $e) {
    }
    $out = (string) ob_get_clean();
    $_POST = $_REQUEST = [];
    return json_decode($out, true);
}
// Order for the customer; the cleanup runs as on woocommerce_checkout_order_processed.
function qa_order(array $c, $status = 'pending') {
    qa_session($c);
    $order = wc_create_order(['status' => $status]);
    $order->set_billing_email($c['email']);
    $order->set_billing_phone($c['phone']);
    $order->update_meta_data('_cg_device_fingerprint', $c['fp']);
    $order->set_status($status);
    $order->save();
    $GLOBALS['QA_ORDERS'][] = $order->get_id();
    // wc_create_order()/save() fire woocommerce_new_order before billing is
    // set; drop that run's guard so the cleanup below is the real one.
    $order = wc_get_order($order->get_id());
    $order->delete_meta_data('_cg_incomplete_cleaned');
    $order->save_meta_data();
    qa_remember_marks([$c['fp'], cg_normalize_phone_number($c['phone']), $c['email']]);
    return $order->get_id();
}
function qa_clear_marks() {
    foreach ($GLOBALS['QA_MARKS'] as $v) {
        delete_transient('cg_ordered_' . md5(strtolower(trim((string) $v))));
    }
}

$R = [];
function qa_check(&$R, $id, $name, $ok, $detail) {
    $R[] = [$id, $name, $ok ? 'PASS' : 'FAIL', $detail];
}

try {
    // G1: tracker request with the same fingerprint after the order.
    qa_reset();
    $c = qa_cust('g1');
    qa_save($c);
    $before = qa_rows('device_fingerprint', $c['fp']);
    $oid = qa_order($c);
    cg_delete_incomplete_checkout_on_order_completion($oid);
    $afterClean = qa_rows('device_fingerprint', $c['fp']);
    qa_reset();
    $r = qa_save($c, ['fp']);
    $n = qa_rows('device_fingerprint', $c['fp']);
    qa_check($R, 'G1', 'late save by fingerprint after order adds no row, no count', $before === 1 && $afterClean === 0 && $n === 0 && qa_usage() === 10 && qa_incr_calls() === 0, "rows before=$before afterClean=$afterClean after=$n usage=" . qa_usage() . ' incr=' . qa_incr_calls() . ' resp=' . wp_json_encode($r));

    // G2: by phone only (new fingerprint, e.g. other tab).
    qa_reset();
    $c = qa_cust('g2');
    $oid = qa_order($c);
    cg_delete_incomplete_checkout_on_order_completion($oid);
    qa_reset();
    $c2 = $c;
    $c2['fp'] = $c['fp'] . 'x';
    $r = qa_save($c2, ['fp', 'phone']);
    $n = qa_rows('phone', cg_normalize_phone_number($c['phone']));
    qa_check($R, 'G2', 'late save by phone after order adds no row, no count', $n === 0 && qa_usage() === 10 && qa_incr_calls() === 0, "rows=$n usage=" . qa_usage() . ' incr=' . qa_incr_calls());
    qa_remember_marks([$c2['fp']]);

    // G3: by email only.
    qa_reset();
    $c = qa_cust('g3');
    $oid = qa_order($c);
    cg_delete_incomplete_checkout_on_order_completion($oid);
    qa_reset();
    $c3 = $c;
    $c3['fp'] = $c['fp'] . 'y';
    $r = qa_save($c3, ['fp', 'email']);
    $n = qa_rows('email', $c['email']);
    qa_check($R, 'G3', 'late save by email after order adds no row, no count', $n === 0 && qa_usage() === 10 && qa_incr_calls() === 0, "rows=$n usage=" . qa_usage() . ' incr=' . qa_incr_calls());
    qa_remember_marks([$c3['fp']]);

    // G4: another customer is still tracked and counted.
    qa_reset();
    $d = qa_cust('g4');
    $r = qa_save($d);
    $n = qa_rows('device_fingerprint', $d['fp']);
    qa_check($R, 'G4', 'other customer: row added and counted', $n === 1 && qa_usage() === 11 && qa_incr_calls() === 1, "rows=$n usage=" . qa_usage() . ' incr=' . qa_incr_calls());

    // G5: once the mark is gone the same customer is tracked again.
    qa_reset();
    $c = qa_cust('g5');
    $oid = qa_order($c);
    cg_delete_incomplete_checkout_on_order_completion($oid);
    $marked = (bool) get_transient('cg_ordered_' . md5($c['fp']));
    // TTL check (no persistent object cache on the QA site: the timeout is an option row).
    $ttl_left = wp_using_ext_object_cache() ? CG_ORDER_PLACED_MARK_TTL : ((int) get_option('_transient_timeout_cg_ordered_' . md5($c['fp'])) - time());
    $marked = $marked && $ttl_left > CG_ORDER_PLACED_MARK_TTL - 10 && $ttl_left <= CG_ORDER_PLACED_MARK_TTL;
    qa_clear_marks();
    qa_reset();
    $r = qa_save($c);
    $n = qa_rows('device_fingerprint', $c['fp']);
    qa_check($R, 'G5', 'mark set with TTL, after it ends the customer is tracked', $marked && $n === 1 && qa_usage() === 11, "marked=" . ($marked ? 1 : 0) . " rows=$n usage=" . qa_usage());

    // G6: block checkout draft does not clean up or set the guard.
    qa_reset();
    $c = qa_cust('g6');
    qa_save($c);
    $oid = qa_order($c, 'checkout-draft');
    cg_delete_incomplete_checkout_on_order_completion($oid);
    $draftRows = qa_rows('device_fingerprint', $c['fp']);
    $guard = (string) wc_get_order($oid)->get_meta('_cg_incomplete_cleaned');
    $draftMark = (bool) get_transient('cg_ordered_' . md5($c['fp']));
    qa_check($R, 'G6', 'checkout-draft: rows kept, no guard, no mark', $draftRows === 1 && $guard === '' && !$draftMark, "rows=$draftRows guard='$guard' mark=" . ($draftMark ? 1 : 0));

    // G7: the draft becomes the order; the Store API hook cleans up.
    $order = wc_get_order($oid);
    $order->set_status('pending');
    $order->save();
    $order = wc_get_order($oid);
    $order->delete_meta_data('_cg_incomplete_cleaned');
    $order->save_meta_data();
    do_action('woocommerce_store_api_checkout_order_processed', wc_get_order($oid));
    $n = qa_rows('device_fingerprint', $c['fp']);
    qa_check($R, 'G7', 'store_api_checkout_order_processed runs the cleanup', $n === 0 && (bool) has_action('woocommerce_store_api_checkout_order_processed', 'cg_delete_incomplete_checkout_on_order_completion'), "rows=$n");

    // G8: a marked customer with a row (made by someone else's flow) is still updated.
    qa_reset();
    $c = qa_cust('g8');
    $oid = qa_order($c);
    cg_delete_incomplete_checkout_on_order_completion($oid);
    $wpdb->insert($GLOBALS['QA_T'], ['session_id' => $GLOBALS['QA_TAG'] . '-g8', 'device_fingerprint' => $c['fp'], 'status' => 'incomplete', 'first_name' => 'Old']);
    qa_reset();
    $r = qa_save($c, ['fp']);
    $fn = (string) $wpdb->get_var($wpdb->prepare("SELECT first_name FROM {$GLOBALS['QA_T']} WHERE device_fingerprint = %s", $c['fp']));
    qa_check($R, 'G8', 'existing row of a marked customer is updated, not counted', qa_rows('device_fingerprint', $c['fp']) === 1 && $fn === 'QA' && qa_incr_calls() === 0, "first_name=$fn incr=" . qa_incr_calls());

    qa_check($R, 'G9', 'no PHP warnings from the plugin', empty($GLOBALS['QA_WARN']), implode(' ; ', array_slice($GLOBALS['QA_WARN'], 0, 3)));
} catch (Throwable $e) {
    qa_check($R, 'GX', 'fatal', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $wpdb->query($wpdb->prepare("DELETE FROM {$GLOBALS['QA_T']} WHERE session_id LIKE %s OR device_fingerprint LIKE %s OR email LIKE %s", 'cg_fp_' . $GLOBALS['QA_TAG'] . '%', $GLOBALS['QA_TAG'] . '%', $GLOBALS['QA_TAG'] . '%'));
    $wpdb->query($wpdb->prepare("DELETE FROM {$GLOBALS['QA_T']} WHERE session_id LIKE %s", $GLOBALS['QA_TAG'] . '%'));
    foreach ($GLOBALS['QA_ORDERS'] as $id) {
        $o2 = wc_get_order($id);
        if ($o2) {
            $o2->delete(true);
        }
    }
    qa_clear_marks();
    if ($GLOBALS['QA_ORIG']['cg_plugin_options'] === null) {
        delete_option('cg_plugin_options');
    } else {
        update_option('cg_plugin_options', $GLOBALS['QA_ORIG']['cg_plugin_options']);
    }
    if ($GLOBALS['QA_ORIG']['cg_api_cache'] === false) {
        delete_transient('cg_api_cache');
    } else {
        set_transient('cg_api_cache', $GLOBALS['QA_ORIG']['cg_api_cache'], DAY_IN_SECONDS);
    }
}
$pass = 0;
foreach ($R as $row) {
    echo implode(' | ', $row) . "\n";
    $pass += $row[2] === 'PASS' ? 1 : 0;
}
echo 'SUMMARY ' . $pass . '/' . count($R) . " PASS\n";
