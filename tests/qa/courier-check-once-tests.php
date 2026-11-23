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
    if ($r['code'] === 'refused') {
        return new WP_Error('http_request_failed', 'cURL error 7: Failed to connect to hq.invalid port 443: Connection refused');
    }
    return ['headers' => new WpOrg\Requests\Utility\CaseInsensitiveDictionary([]), 'body' => array_key_exists('raw', $r) ? (string) $r['raw'] : json_encode($r['body'] ?? []), 'response' => ['code' => $r['code'], 'message' => ''], 'cookies' => [], 'filename' => null];
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
    // A later fix may keep a site-wide wait after a timeout; start each case without it.
    if (defined('CG_COURIER_TIMEOUT_WAIT_TRANSIENT')) { delete_transient(CG_COURIER_TIMEOUT_WAIT_TRANSIENT); }
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

// C9: the same phone in the same format three times (one classic checkout:
// checkout_process, after_checkout_validation, create_order) with HQ timing out.
qa_reset(['01721222333']);
$GLOBALS['QA_HTTP'] = ['code' => 'timeout'];
qa_validate('01721222333', 9); qa_validate('01721222333', 10); qa_validate('01721222333', 11);
$n = count($GLOBALS['QA_CALLS']);
ok('C9', 'timeout: one courier-check for one phone in one format, 3 validations', $n === 1, "calls=$n");

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

// C10: an error that starts no wait and is not cached (HQ 400 or 429) is
// still checked once per request: three validations
// of one checkout, same phone, same format -> one courier-check. This is the
// only case where the request memo alone keeps it to one call.
foreach ([['C10a', 'HQ 400', ['code' => 400, 'body' => ['error' => 'bad_request']], '01731222333', 12], ['C10b', 'HQ 429', ['code' => 429, 'body' => ['error' => 'too_many_requests']], '01741222333', 15]] as $c) {
    $GLOBALS['QA_HTTP'] = $c[2];
    // Own phone and emails per case: the engine reuses a result for an identical context.
    qa_reset([$c[3]]);
    qa_validate($c[3], $c[4]); qa_validate($c[3], $c[4] + 1); qa_validate($c[3], $c[4] + 2);
    $n = count($GLOBALS['QA_CALLS']);
    $w = defined('CG_COURIER_TIMEOUT_WAIT_TRANSIENT') ? (get_transient(CG_COURIER_TIMEOUT_WAIT_TRANSIENT) !== false ? 'on' : 'off') : 'n/a';
    $tc = get_transient('cg_courier_cache_' . md5($c[3])) !== false ? 'yes' : 'no';
    ok($c[0], $c[1] . ': one courier-check for 3 validations (no wait, not cached)', $n === 1 && $w !== 'on' && $tc === 'no', "calls=$n wait=$w cached=$tc");
}
// C10c: a transport error that is not a timeout (cURL 7, connection refused)
// starts no wait either; three validations still send one courier-check.
$GLOBALS['QA_HTTP'] = ['code' => 'refused'];
qa_reset(['01751222333']);
qa_validate('01751222333', 18); qa_validate('01751222333', 19); qa_validate('01751222333', 20);
$n = count($GLOBALS['QA_CALLS']);
$w = defined('CG_COURIER_TIMEOUT_WAIT_TRANSIENT') ? (get_transient(CG_COURIER_TIMEOUT_WAIT_TRANSIENT) !== false ? 'on' : 'off') : 'n/a';
$tc = get_transient('cg_courier_cache_' . md5('01751222333')) !== false ? 'yes' : 'no';
ok('C10c', 'cURL 7: one courier-check for 3 validations (no wait, not cached)', $n === 1 && $w !== 'on' && $tc === 'no', "calls=$n wait=$w cached=$tc");

// C11: a failed check reused from the request memo writes no courier data to
// the order (no _cg_success_ratio_data with an error in it). C11-ctl: a
// successful check reused from the memo still writes it, with this order's id.
// The orders are marked _cg_validated so the save guard does not check them.
$qa_mk_order = function () {
    $ord = new WC_Order();
    $ord->update_meta_data('_cg_validated', '1');
    $ord->save();
    return $ord->get_id();
};
$GLOBALS['QA_HTTP'] = ['code' => 400, 'body' => ['error' => 'bad_request']];
qa_reset(['01761222333']);
$oid = $qa_mk_order();
$GLOBALS['QA_CALLS'] = [];
$e1 = cg_perform_all_api_calls('01761222333', 0);
$e2 = cg_perform_all_api_calls('01761222333', $oid);
$n = count($GLOBALS['QA_CALLS']);
$meta = wc_get_order($oid)->get_meta('_cg_success_ratio_data', true);
ok('C11', 'error memo hit with an order id writes no courier meta', $n === 1 && ($e2['cache_source'] ?? '') === 'error' && empty($meta), "calls=$n src=" . ($e2['cache_source'] ?? '') . ' meta=' . (empty($meta) ? 'none' : (is_array($meta) ? ($meta['cache_source'] ?? '?') : 'set')));
wc_get_order($oid)->delete(true);

$GLOBALS['QA_HTTP'] = ['code' => 200, 'body' => ['cache_source' => 'fresh', 'result' => ['summary' => ['success_rate' => 90, 'total_deliveries' => 10, 'successful_deliveries' => 9, 'cancelled_deliveries' => 1]]]];
qa_reset(['01771222333']);
$oid = $qa_mk_order();
$GLOBALS['QA_CALLS'] = [];
delete_transient('cg_courier_cache_' . md5('01771222333'));
cg_perform_all_api_calls('01771222333', 0);
// Drop the transient so the second call can only be served by the memo.
delete_transient('cg_courier_cache_' . md5('01771222333'));
$s2 = cg_perform_all_api_calls('01771222333', $oid);
$n = count($GLOBALS['QA_CALLS']);
$meta = wc_get_order($oid)->get_meta('_cg_success_ratio_data', true);
$mok = is_array($meta) && (int) ($meta['order_id'] ?? 0) === $oid && (int) ($meta['summary']['total_deliveries'] ?? -1) === 10;
ok('C11-ctl', 'success memo hit with an order id writes the courier meta (this order id)', $n === 1 && $mok, "calls=$n meta_order=" . (is_array($meta) ? (int) ($meta['order_id'] ?? 0) : 'none') . ' oid_match=' . ($mok ? 'yes' : 'no'));
wc_get_order($oid)->delete(true);
$GLOBALS['QA_HTTP'] = ['code' => 'timeout'];

// C16: HQ answers 200 but the body is not a JSON object (a proxy error page,
// or a bare JSON string). That is not a courier result: it must not be
// cached for 6 hours, so the next request asks HQ again. Each "request" is
// simulated by clearing the request memo between the two checks.
foreach ([['C16a', 'HQ 200 with an HTML body', '<html><body>502 Bad Gateway</body></html>', '01781222333'], ['C16b', 'HQ 200 with a bare JSON string', '"ok"', '01791222333']] as $c) {
    $GLOBALS['QA_HTTP'] = ['code' => 200, 'raw' => $c[2]];
    qa_reset([$c[3]]);
    $r1 = cg_perform_all_api_calls($c[3], 0);
    $tc = get_transient('cg_courier_cache_' . md5($c[3])) !== false ? 'yes' : 'no';
    if (function_exists('cg_courier_request_memo_reset')) { cg_courier_request_memo_reset(); }
    cg_perform_all_api_calls($c[3], 0);
    $n = count($GLOBALS['QA_CALLS']);
    ok($c[0], $c[1] . ': not cached, the next request calls HQ again (2 calls)', $tc === 'no' && $n === 2, "cached=$tc calls=$n src=" . (is_array($r1) ? ($r1['cache_source'] ?? '') : gettype($r1)));
}
// C16c: HQ answers 200 with a JSON object that has no courier result in it:
// "result" is null, or the "result" key is missing. That is the same empty
// answer as C16a/b: it must not be cached for 6 hours, so the next request
// asks HQ again.
foreach ([['C16c-null', 'HQ 200 with {"result": null}', ['cache_source' => 'fresh', 'result' => null], '01681222333'], ['C16c-nokey', 'HQ 200 with no "result" key', ['cache_source' => 'fresh'], '01691222333']] as $c) {
    $GLOBALS['QA_HTTP'] = ['code' => 200, 'body' => $c[2]];
    qa_reset([$c[3]]);
    $r1 = cg_perform_all_api_calls($c[3], 0);
    $tc = get_transient('cg_courier_cache_' . md5($c[3])) !== false ? 'yes' : 'no';
    if (function_exists('cg_courier_request_memo_reset')) { cg_courier_request_memo_reset(); }
    cg_perform_all_api_calls($c[3], 0);
    $n = count($GLOBALS['QA_CALLS']);
    ok($c[0], $c[1] . ': not cached, the next request calls HQ again (2 calls)', $tc === 'no' && $n === 2, "cached=$tc calls=$n src=" . (is_array($r1) ? ($r1['cache_source'] ?? '') : gettype($r1)));
}
// C16-ctl: a real 200 courier result is cached, so the next request makes no
// call (C16 is not passing because nothing is ever cached). The answer has
// one courier with data: a summary alone counts as an all-failed answer,
// which is never cached.
$GLOBALS['QA_HTTP'] = ['code' => 200, 'body' => ['cache_source' => 'fresh', 'result' => ['summary' => ['success_rate' => 90, 'total_deliveries' => 10, 'successful_deliveries' => 9, 'cancelled_deliveries' => 1], 'pathao' => ['error' => false, 'total' => 10, 'success' => 9, 'cancelled' => 1, 'success_rate' => 90]]]];
qa_reset(['01701222333']);
cg_perform_all_api_calls('01701222333', 0);
$tc = get_transient('cg_courier_cache_' . md5('01701222333')) !== false ? 'yes' : 'no';
if (function_exists('cg_courier_request_memo_reset')) { cg_courier_request_memo_reset(); }
cg_perform_all_api_calls('01701222333', 0);
$n = count($GLOBALS['QA_CALLS']);
ok('C16-ctl', 'HQ 200 with a courier result: cached, the next request makes no call (1 call)', $tc === 'yes' && $n === 1, "cached=$tc calls=$n");
$GLOBALS['QA_HTTP'] = ['code' => 'timeout'];

// C8: a forced refresh (admin "Refresh") bypasses the request memo.
qa_reset(['01511222333']);
cg_perform_all_api_calls('01511222333', 0); cg_perform_all_api_calls('01511222333', 0, true);
$n = count($GLOBALS['QA_CALLS']);
ok('C8', 'bypass_cache sends a new call', $n === 2, "calls=$n");

foreach (['01781222333', '01791222333', '01681222333', '01691222333', '01701222333', '01751222333', '01761222333', '01771222333', '01731222333', '01741222333', '01721222333', '01711222333', '+880 1711-222333', '8801711222333', '01811222333', '+8801811222333', '01911222333', '01611222333', '01511222333'] as $p) { delete_transient('cg_courier_cache_' . md5($p)); }
delete_transient(CG_COURIER_AUTH_REJECT_TRANSIENT);
if (defined('CG_COURIER_TIMEOUT_WAIT_TRANSIENT')) { delete_transient(CG_COURIER_TIMEOUT_WAIT_TRANSIENT); }
cg_update_options_from_hq(is_array($GLOBALS['QA_OPTS_ORIG']) ? $GLOBALS['QA_OPTS_ORIG'] : []);
restore_error_handler();
$R = $GLOBALS['R'];
foreach ($R as $r) echo implode(' | ', $r), "\n";
echo 'warnings_total=' . count($GLOBALS['QA_WARN']) . "\n"; foreach (array_unique($GLOBALS['QA_WARN']) as $w) echo "  W: $w\n";
$fl = count(array_filter($R, function ($r) { return $r[2] !== 'PASS'; })) + (count($GLOBALS['QA_WARN']) ? 1 : 0);
echo "SUMMARY " . (count($R) - $fl) . '/' . count($R) . " PASS\n";
