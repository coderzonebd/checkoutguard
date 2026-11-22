<?php
/**
 * Courier check runs once per checkout request (1.5.1): classic checkout
 * validates in checkout_process, after_checkout_validation and create_order.
 * The three contexts can carry the same phone in different formats, and a
 * failed check is not cached, so a slow HQ cost 30 s per call (~60 s seen).
 * One request must send at most one courier-check per customer phone, also
 * when the check fails, and the checkout path must use a short timeout.
 *
 * Run on a local test site with WooCommerce only (never production):
 *   wp eval-file tests/qa/courier-check-once-tests.php
 * HTTP is answered by a pre_http_request filter in this process; nothing
 * leaves the machine. Options and transients it touches are restored.
 */
if (!defined('ABSPATH')) {
    exit;
}
$GLOBALS['QA_OPTS_ORIG'] = get_option('cg_plugin_options', []);
$GLOBALS['QA_HTTP'] = ['code' => 'timeout'];
$GLOBALS['QA_CALLS'] = [];
add_filter('pre_http_request', function ($pre, $args, $url) {
    if (strpos($url, '/sites/courier-check') === false) {
        return new WP_Error('qa_blocked', 'blocked in test');
    }
    $GLOBALS['QA_CALLS'][] = ['timeout' => (int) ($args['timeout'] ?? 0), 'body' => json_decode((string) ($args['body'] ?? ''), true)];
    $r = $GLOBALS['QA_HTTP'];
    if ($r['code'] === 'timeout') {
        return new WP_Error('http_request_failed', 'cURL error 28: Operation timed out after 30001 milliseconds');
    }
    return ['headers' => new WpOrg\Requests\Utility\CaseInsensitiveDictionary([]), 'body' => json_encode($r['body'] ?? []), 'response' => ['code' => $r['code'], 'message' => ''], 'cookies' => [], 'filename' => null];
}, PHP_INT_MAX, 3);
$GLOBALS['QA_WARN'] = [];
set_error_handler(function ($no, $str, $file, $line) {
    if (strpos($file, 'checkoutguard') !== false) {
        $GLOBALS['QA_WARN'][] = "$str @ " . basename($file) . ":$line";
    }
    return false;
});
$GLOBALS['R'] = [];
function ok($id, $name, $pass, $detail) { $GLOBALS['R'][] = [$id, $name, $pass ? 'PASS' : 'FAIL', $detail]; }

$o = is_array($GLOBALS['QA_OPTS_ORIG']) ? $GLOBALS['QA_OPTS_ORIG'] : [];
$o['api_key'] = 'qa-shared-key-not-real';
$o['enable_courier_service'] = 1; $o['enable_fraud_blocker'] = 0;
$o['ratio_blocker_grace_period'] = 0; $o['ratio_blocker_threshold'] = 20;
cg_update_options_from_hq($o);

function qa_validate($phone, $n) {
    $ctx = new CG_Validation_Context(['phone' => $phone, 'ip' => '10.78.0.1', 'email' => "qa$n@example.invalid", 'order_source' => 'qa', 'request_type' => 'classic']);
    return get_object_vars(CG_Validation_Engine::validate($ctx));
}
function qa_reset($phones) {
    $GLOBALS['QA_CALLS'] = [];
    foreach ($phones as $p) { delete_transient('cg_courier_cache_' . md5($p)); }
    delete_transient(CG_COURIER_AUTH_REJECT_TRANSIENT);
    if (function_exists('cg_courier_request_memo_reset')) { cg_courier_request_memo_reset(); }
}

// C1: HQ times out; three validations of one checkout, same phone in three formats.
$fmts = ['+880 1711-222333', '01711222333', '8801711222333'];
qa_reset(array_merge($fmts, ['01711222333']));
$r1 = qa_validate($fmts[0], 1); $r2 = qa_validate($fmts[1], 2); $r3 = qa_validate($fmts[2], 3);
$n = count($GLOBALS['QA_CALLS']);
ok('C1', 'timeout: one courier-check for one phone in 3 formats', $n === 1, "calls=$n");
ok('C2', 'timeout: all three allowed (fail-open)', empty($r1['is_blocked']) && empty($r2['is_blocked']) && empty($r3['is_blocked']), json_encode([$r1['is_blocked'] ?? null, $r2['is_blocked'] ?? null, $r3['is_blocked'] ?? null]));
$to = $GLOBALS['QA_CALLS'][0]['timeout'] ?? 0;
ok('C3', 'checkout path uses a short timeout (<= 5 s)', $to > 0 && $to <= 5, "timeout=$to");
$ph = $GLOBALS['QA_CALLS'][0]['body']['phone_number'] ?? '';
ok('C4', 'HQ gets the normalized phone', $ph === '01711222333', "phone=$ph");
ok('C5', 'a failed check is still not cached in the transient', get_transient('cg_courier_cache_' . md5('01711222333')) === false, '');

// C6: success is shared too, and a different phone still gets its own check.
qa_reset(['01811222333', '+8801811222333', '01911222333']);
$GLOBALS['QA_HTTP'] = ['code' => 200, 'body' => ['cache_source' => 'fresh', 'result' => ['summary' => ['success_rate' => 90, 'total_deliveries' => 10, 'successful_deliveries' => 9, 'cancelled_deliveries' => 1]]]];
qa_validate('01811222333', 6); qa_validate('+8801811222333', 7); qa_validate('01911222333', 8);
$n = count($GLOBALS['QA_CALLS']);
ok('C6', 'success: one call per distinct phone (2 phones -> 2 calls)', $n === 2, "calls=$n");

// C7: the admin manual check keeps its 30 s timeout.
qa_reset(['01611222333']);
$GLOBALS['QA_HTTP'] = ['code' => 'timeout'];
cg_perform_all_api_calls('01611222333', 0, true);
$to = $GLOBALS['QA_CALLS'][0]['timeout'] ?? 0;
ok('C7', 'admin/manual path keeps 30 s timeout', $to === 30, "timeout=$to");

// C8: a forced refresh (admin "Refresh") bypasses the request memo.
qa_reset(['01511222333']);
cg_perform_all_api_calls('01511222333', 0); cg_perform_all_api_calls('01511222333', 0, true);
$n = count($GLOBALS['QA_CALLS']);
ok('C8', 'bypass_cache sends a new call', $n === 2, "calls=$n");

foreach (['01711222333', '+880 1711-222333', '8801711222333', '01811222333', '+8801811222333', '01911222333', '01611222333', '01511222333'] as $p) { delete_transient('cg_courier_cache_' . md5($p)); }
cg_update_options_from_hq(is_array($GLOBALS['QA_OPTS_ORIG']) ? $GLOBALS['QA_OPTS_ORIG'] : []);
restore_error_handler();
$R = $GLOBALS['R'];
foreach ($R as $r) echo implode(' | ', $r), "\n";
echo 'warnings_total=' . count($GLOBALS['QA_WARN']) . "\n"; foreach (array_unique($GLOBALS['QA_WARN']) as $w) echo "  W: $w\n";
$fl = count(array_filter($R, function ($r) { return $r[2] !== 'PASS'; })) + (count($GLOBALS['QA_WARN']) ? 1 : 0);
echo "SUMMARY " . (count($R) - $fl) . '/' . count($R) . " PASS\n";
