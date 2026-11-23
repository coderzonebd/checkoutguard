<?php
/**
 * QA tests: the duplicate blocker never shows an order number (F1, privacy).
 *
 * Run with: wp eval-file tests/qa/duplicate-blocker-order-number-tests.php
 * Local test site only. All outgoing HTTP and mail are blocked while the tests run.
 * It sets a Pro plan in cg_api_cache / cg_last_known_plan and restores them after.
 *
 * GC-ctl  the Pro plan is active and the custom message is really used
 *         ({time_window} is filled), so the GC cases cannot pass vacuously.
 * GC      with a custom message that uses {order_id}, no blocked attempt shows the
 *         earlier order's number: not for another customer who shares only the IP
 *         or only the device, and not for a match by email or by phone either.
 * GC-only a custom message that is only "{order_id}" still blocks, with the
 *         default notice.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_filter('pre_http_request', function () {
    return new WP_Error('qa_blocked', 'HTTP blocked in QA test');
}, 1);
add_filter('pre_wp_mail', '__return_false');

$GLOBALS['qa_on'] = ['pass' => 0, 'fail' => 0, 'orders' => []];

function qa_on_check($name, $ok, $detail = '')
{
    $GLOBALS['qa_on'][$ok ? 'pass' : 'fail']++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . (!$ok && $detail !== '' ? " :: {$detail}" : '') . "\n";
}

function qa_on_order($product, $phone, $email, $ip, $fp)
{
    $o = wc_create_order();
    $o->set_billing_first_name('Alpha');
    $o->set_billing_last_name('QA');
    $o->add_product($product, 1);
    $o->set_billing_phone($phone);
    $o->set_billing_email($email);
    $o->set_customer_ip_address($ip);
    $o->update_meta_data('_cg_device_fingerprint', $fp);
    $o->calculate_totals();
    $o->set_status('processing');
    $o->save();
    $GLOBALS['qa_on']['orders'][] = $o->get_id();
    return $o;
}

function qa_on_customer($product, $phone, $email, $ip, $fp)
{
    return [
        'name' => 'Someone QA', 'phone' => $phone, 'email' => $email, 'ip' => $ip,
        'device_fp' => $fp, 'address_hash' => '',
        'products' => [$product->get_id() => 1], 'total_amount' => 500.0,
    ];
}

function qa_on_has_number($msg, $num)
{
    return is_string($msg)
        && (preg_match('/#\s*\d+/', $msg) === 1 || preg_match('/(?<!\d)' . preg_quote($num, '/') . '(?!\d)/', $msg) === 1);
}

function qa_on_options($extra)
{
    $o = get_option('cg_plugin_options', []);
    foreach (array_keys($o) as $k) {
        if (strpos($k, 'duplicate_blocker_') === 0) {
            unset($o[$k]);
        }
    }
    update_option('cg_plugin_options', array_merge($o, [
        'duplicate_blocker_enabled'      => 1,
        'duplicate_blocker_match_phone'  => 1,
        'duplicate_blocker_match_email'  => 1,
        'duplicate_blocker_match_ip'     => 1,
        'duplicate_blocker_match_device' => 1,
        'duplicate_blocker_window'       => 10,
    ], $extra));
}

// Saved state, restored at the end.
$saved_options = get_option('cg_plugin_options', []);
$saved_cache   = get_transient('cg_api_cache');
$saved_last    = get_option('cg_last_known_plan', null);

// Pro plan, as pro-plan-tests.php sets it.
delete_transient('cg_api_cache');
delete_option('cg_last_known_plan');
wp_cache_delete('cg_last_known_plan', 'options');
set_transient('cg_api_cache', ['plan_slug' => 'pro', 'status' => 'active'], DAY_IN_SECONDS);
update_option('cg_last_known_plan', ['plan_slug' => 'pro', 'status' => 'active', 'synced_at' => time()], false);

$product = new WC_Product_Simple();
$product->set_name('QA dup order-number product');
$product->set_regular_price('500');
$product->set_status('publish');
$product->save();

// Test data only (made-up numbers and addresses).
$A = ['01700000111', 'qa-on-a@example.test', '203.0.113.31', 'qa-on-fp-aaaa'];
$B = ['01800000222', 'qa-on-b@example.test', '198.51.100.42', 'qa-on-fp-bbbb'];

qa_on_check('GC-ctl Pro plan is active for the test', function_exists('cg_is_pro_plan') && cg_is_pro_plan() === true);

$messages = [
    'plain'  => 'Order {order_id} exists, wait {time_window} min',
    'hashed' => 'You ordered (#{order_id}) already, wait {time_window} min',
];
$cases = [
    'same IP only, other customer'     => [$B[0], $B[1], $A[2], $B[3]],
    'same device only, other customer' => [$B[0], $B[1], $B[2], $A[3]],
    'same email'                       => ['01900000333', $A[1], '192.0.2.77', 'qa-on-fp-cccc'],
    'same phone'                       => [$A[0], 'qa-on-other@example.test', '192.0.2.78', 'qa-on-fp-dddd'],
];

foreach ($messages as $mlabel => $tpl) {
    qa_on_options(['duplicate_blocker_message' => $tpl]);
    $a = qa_on_order($product, $A[0], $A[1], $A[2], $A[3]);
    $num = (string) $a->get_order_number();
    foreach ($cases as $clabel => $c) {
        $r = cg_check_duplicate_order_core(qa_on_customer($product, $c[0], $c[1], $c[2], $c[3]));
        qa_on_check(
            "GC-ctl [{$mlabel}] [{$clabel}] blocked with the custom message",
            is_string($r) && strpos($r, 'wait 10 min') !== false,
            var_export($r, true)
        );
        qa_on_check(
            "GC [{$mlabel}] [{$clabel}] message has no order number",
            is_string($r) && !qa_on_has_number($r, $num) && strpos($r, '{order_id}') === false,
            var_export($r, true)
        );
    }
    foreach ($GLOBALS['qa_on']['orders'] as $id) {
        if ($o = wc_get_order($id)) {
            $o->delete(true);
        }
    }
    $GLOBALS['qa_on']['orders'] = [];
}

// GC-only: the placeholder alone must not turn into an empty (non-blocking) message.
qa_on_options(['duplicate_blocker_message' => '#{order_id}']);
$a = qa_on_order($product, $A[0], $A[1], $A[2], $A[3]);
$r = cg_check_duplicate_order_core(qa_on_customer($product, $A[0], $A[1], '192.0.2.79', ''));
qa_on_check(
    'GC-only message "#{order_id}" still blocks, default notice, no number',
    is_string($r) && $r !== '' && strpos($r, 'already placed an order') !== false && !qa_on_has_number($r, (string) $a->get_order_number()),
    var_export($r, true)
);
foreach ($GLOBALS['qa_on']['orders'] as $id) {
    if ($o = wc_get_order($id)) {
        $o->delete(true);
    }
}

// Restore.
update_option('cg_plugin_options', $saved_options);
delete_transient('cg_api_cache');
if ($saved_cache !== false) {
    set_transient('cg_api_cache', $saved_cache, DAY_IN_SECONDS);
}
delete_option('cg_last_known_plan');
if ($saved_last !== null) {
    update_option('cg_last_known_plan', $saved_last, false);
}
$product->delete(true);

echo 'RESULT pass=' . $GLOBALS['qa_on']['pass'] . ' fail=' . $GLOBALS['qa_on']['fail'] . "\n";
