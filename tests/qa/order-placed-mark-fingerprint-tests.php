<?php
/**
 * The order-placed mark must not skip another customer's checkout only
 * because the device fingerprint is the same (ajax-checkouts.php).
 *
 * Browsers of the same phone model and version send the same fingerprint.
 * After customer A placed an order, a new checkout of customer B on such a
 * browser (own session, phone and email) was skipped as "Order already
 * placed" for CG_ORDER_PLACED_MARK_TTL seconds: no row, not counted.
 *
 * Run on a local test site only (never production):
 *   wp eval-file tests/qa/order-placed-mark-fingerprint-tests.php
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
class QA_MK_Die extends Exception {}
add_filter('wp_doing_ajax', '__return_true', PHP_INT_MAX);
add_filter('wp_die_ajax_handler', function () {
    return function ($msg = '') {
        throw new QA_MK_Die(is_string($msg) ? $msg : '');
    };
}, PHP_INT_MAX);
$o = is_array($GLOBALS['QA_ORIG']['cg_plugin_options']) ? $GLOBALS['QA_ORIG']['cg_plugin_options'] : [];
$o['api_key'] = 'qa-mk-key';
update_option('cg_plugin_options', $o);
$GLOBALS['QA_ORDERS'] = [];
$GLOBALS['QA_TAG'] = 'qamk' . wp_rand(10000, 99999);
$GLOBALS['QA_MARKS'] = [];

function qa_reset() {
    set_transient('cg_api_cache', ['usage' => ['checkouts' => 10, 'contact_reveals' => 0], 'limits' => ['checkouts' => 100, 'contact_reveals' => 0]], DAY_IN_SECONDS);
    $GLOBALS['QA_LOG'] = [];
}
function qa_incr_calls() {
    return count(array_filter($GLOBALS['QA_LOG'], function ($p) { return $p === '/sites/increment-usage:checkouts'; }));
}
function qa_rows($col, $val) {
    global $wpdb;
    return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$GLOBALS['QA_T']} WHERE {$col} = %s", $val));
}
// Customer: own WC session, device fingerprint, phone (raw BD number), email.
function qa_cust($sfx, $fp) {
    $n = (string) wp_rand(10000000, 99999999);
    return ['sid' => 't_' . $GLOBALS['QA_TAG'] . $sfx, 'fp' => $fp, 'phone' => '017' . $n, 'email' => $GLOBALS['QA_TAG'] . "$sfx@example.test"];
}
function qa_fp($sfx) {
    return $GLOBALS['QA_TAG'] . 'fp' . $sfx;
}
// wp-cli has one WC session for the whole run: set its customer id.
function qa_session(array $c) {
    if (function_exists('WC') && WC() && !WC()->session && function_exists('wc_load_cart')) {
        wc_load_cart();
    }
    if (function_exists('WC') && WC() && WC()->session) {
        $p = new ReflectionProperty(get_class(WC()->session), '_customer_id');
        $p->setAccessible(true);
        $p->setValue(WC()->session, $c['sid']);
        $GLOBALS['QA_MARKS'][] = $c['sid'];
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
    } catch (QA_MK_Die $e) {
    }
    $out = (string) ob_get_clean();
    $_POST = $_REQUEST = [];
    return json_decode($out, true);
}
// Order for the customer; the cleanup runs as on woocommerce_checkout_order_processed.
function qa_order(array $c) {
    qa_session($c);
    $order = wc_create_order(['status' => 'pending']);
    $order->set_billing_email($c['email']);
    $order->set_billing_phone($c['phone']);
    $order->update_meta_data('_cg_device_fingerprint', $c['fp']);
    $order->save();
    $GLOBALS['QA_ORDERS'][] = $order->get_id();
    $order = wc_get_order($order->get_id());
    $order->delete_meta_data('_cg_incomplete_cleaned');
    $order->save_meta_data();
    $GLOBALS['QA_MARKS'][] = $c['fp'];
    $GLOBALS['QA_MARKS'][] = cg_normalize_phone_number($c['phone']);
    $GLOBALS['QA_MARKS'][] = $c['email'];
    cg_delete_incomplete_checkout_on_order_completion($order->get_id());
    return $order->get_id();
}
function qa_clear_marks() {
    foreach ($GLOBALS['QA_MARKS'] as $v) {
        delete_transient('cg_ordered_' . md5(strtolower(trim((string) $v))));
    }
}
function qa_skipped($r) {
    return is_array($r) && (($r['data']['skipped'] ?? '') === 'order_placed');
}
$R = [];
function qa_check(&$R, $id, $name, $ok, $detail) {
    $R[] = [$id, $name, $ok ? 'PASS' : 'FAIL', $detail];
}

try {
    // K1: A orders. B (same fingerprint, own session, phone and email)
    // starts a checkout within the mark: tracked and counted.
    $fp = qa_fp('k1');
    $a = qa_cust('k1a', $fp);
    $b = qa_cust('k1b', $fp);
    qa_order($a);
    qa_reset();
    $r = qa_save($b);
    $n = qa_rows('email', $b['email']);
    qa_check($R, 'K1', 'same fingerprint, other session/phone/email after an order: row added, 1 increment', !qa_skipped($r) && $n === 1 && qa_incr_calls() === 1, "rows=$n incr=" . qa_incr_calls() . ' resp=' . wp_json_encode($r));
    qa_clear_marks();

    // K2: the same with only the fingerprint and an email (no phone yet).
    $fp = qa_fp('k2');
    $a = qa_cust('k2a', $fp);
    $b = qa_cust('k2b', $fp);
    qa_order($a);
    qa_reset();
    $r = qa_save($b, ['fp', 'email']);
    $n = qa_rows('email', $b['email']);
    qa_check($R, 'K2', 'same fingerprint, other session, email only: row added, 1 increment', !qa_skipped($r) && $n === 1 && qa_incr_calls() === 1, "rows=$n incr=" . qa_incr_calls() . ' resp=' . wp_json_encode($r));
    qa_clear_marks();

    // K3 control: A's own late request (same session, fingerprint only) is
    // still skipped.
    $fp = qa_fp('k3');
    $a = qa_cust('k3a', $fp);
    qa_order($a);
    qa_reset();
    $r = qa_save($a, ['fp']);
    $n = qa_rows('device_fingerprint', $fp);
    qa_check($R, 'K3', 'control: same session after the order: skipped, no row, 0 increments', qa_skipped($r) && $n === 0 && qa_incr_calls() === 0, "rows=$n incr=" . qa_incr_calls() . ' resp=' . wp_json_encode($r));
    qa_clear_marks();

    // K4 control: A's late request from another tab (new session, same
    // fingerprint and phone) is still skipped.
    $fp = qa_fp('k4');
    $a = qa_cust('k4a', $fp);
    qa_order($a);
    $a2 = $a;
    $a2['sid'] .= 'x';
    qa_reset();
    $r = qa_save($a2, ['fp', 'phone']);
    $n = qa_rows('device_fingerprint', $fp);
    qa_check($R, 'K4', 'control: other session, same fingerprint + phone: skipped, 0 increments', qa_skipped($r) && $n === 0 && qa_incr_calls() === 0, "rows=$n incr=" . qa_incr_calls() . ' resp=' . wp_json_encode($r));
    qa_clear_marks();

    // K5 control: new session and other fingerprint, same email: skipped.
    $a = qa_cust('k5a', qa_fp('k5'));
    qa_order($a);
    $a2 = $a;
    $a2['sid'] .= 'x';
    $a2['fp'] = qa_fp('k5x');
    qa_reset();
    $r = qa_save($a2, ['fp', 'email']);
    $n = qa_rows('email', $a['email']);
    qa_check($R, 'K5', 'control: other session and fingerprint, same email: skipped, 0 increments', qa_skipped($r) && $n === 0 && qa_incr_calls() === 0, "rows=$n incr=" . qa_incr_calls() . ' resp=' . wp_json_encode($r));
    qa_clear_marks();

    qa_check($R, 'K9', 'no PHP warnings from the plugin', empty($GLOBALS['QA_WARN']), implode(' ; ', array_slice($GLOBALS['QA_WARN'], 0, 3)));
} catch (Throwable $e) {
    qa_check($R, 'KX', 'fatal', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $wpdb->query($wpdb->prepare("DELETE FROM {$GLOBALS['QA_T']} WHERE session_id LIKE %s OR device_fingerprint LIKE %s OR email LIKE %s", 't_' . $GLOBALS['QA_TAG'] . '%', $GLOBALS['QA_TAG'] . '%', $GLOBALS['QA_TAG'] . '%'));
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
    restore_error_handler();
}
$pass = count(array_filter($R, function ($r) { return $r[2] === 'PASS'; }));
foreach ($R as $r) {
    echo implode(' | ', $r), "\n";
}
echo 'SUMMARY ' . $pass . '/' . count($R) . ' ' . ($pass === count($R) ? 'PASS' : 'FAIL') . "\n";
