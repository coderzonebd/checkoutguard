<?php
/**
 * Local courier usage when every courier failed (1.5.0, ajax-courier.php
 * cg_perform_all_api_calls): HQ answers 200 "fresh" but every courier in the
 * result has error=true. No courier data came back, so the local
 * courier_checks counter in cg_api_cache must not go up.
 *
 * U1  all five couriers error, cache_source fresh: local usage unchanged.
 * U2  control: all couriers ok, fresh: local usage +1.
 * U2b control: one courier error, the others ok, fresh: local usage +1
 *     (only an all-failed answer is skipped).
 * U3  control: all couriers error, server_cache: local usage unchanged.
 *
 * Run on a local test site only (never production):
 *   wp eval-file tests/qa/courier-all-fail-usage-tests.php
 * HTTP is answered by a pre_http_request filter in this process; nothing
 * leaves the machine. Options and cg_api_cache are restored at the end.
 */
if (!defined('ABSPATH')) {
    exit;
}
$GLOBALS['QA_OPTS_ORIG'] = get_option('cg_plugin_options', []);
$GLOBALS['QA_API_CACHE_ORIG'] = get_transient('cg_api_cache');
$GLOBALS['QA_API_CACHE_TTL'] = max(60, (int) get_option('_transient_timeout_cg_api_cache', 0) - time());
$GLOBALS['QA_HTTP'] = ['code' => 'down'];
add_filter('pre_http_request', function ($pre, $args, $url) {
    if (strpos($url, '/sites/courier-check') === false) {
        return new WP_Error('qa_blocked', 'blocked in test');
    }
    $r = $GLOBALS['QA_HTTP'];
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
$o['enable_courier_service'] = 1;
cg_update_options_from_hq($o);

function qa_courier_result($failed, $source) {
    $res = ['summary' => ['success_rate' => 0, 'total_deliveries' => 0, 'successful_deliveries' => 0, 'cancelled_deliveries' => 0]];
    foreach (array_keys(cg_get_supported_couriers()) as $k) {
        $err = in_array($k, $failed, true);
        $res[$k] = $err ? ['error' => true] : ['error' => false, 'total' => 2, 'success' => 2, 'cancelled' => 0, 'success_rate' => 100];
    }
    if (count($failed) < count(cg_get_supported_couriers())) {
        $res['summary'] = ['success_rate' => 100, 'total_deliveries' => 2, 'successful_deliveries' => 2, 'cancelled_deliveries' => 0];
    }
    return ['code' => 200, 'body' => ['cache_source' => $source, 'result' => $res]];
}
$GLOBALS['QA_N'] = 0;
function qa_usage_delta($http) {
    $GLOBALS['QA_N']++;
    set_transient('cg_api_cache', ['limits' => ['courier_checks' => 100], 'usage' => ['courier_checks' => 5]], DAY_IN_SECONDS);
    delete_transient(CG_COURIER_AUTH_REJECT_TRANSIENT);
    $phone = '0171111' . str_pad((string) $GLOBALS['QA_N'], 4, '0', STR_PAD_LEFT);
    delete_transient('cg_courier_cache_' . md5($phone));
    $GLOBALS['QA_HTTP'] = $http;
    $r = cg_perform_all_api_calls($phone, 0);
    $c = get_transient('cg_api_cache');
    $u = is_array($c) ? (int) ($c['usage']['courier_checks'] ?? -1) : -1;
    delete_transient('cg_courier_cache_' . md5($phone));
    return [$u - 5, is_array($r) ? ($r['cache_source'] ?? '') : 'not-array'];
}
$all = array_keys(cg_get_supported_couriers());

[$d, $src] = qa_usage_delta(qa_courier_result($all, 'fresh'));
ok('U1', 'all couriers error, fresh: local courier usage unchanged', $d === 0, "delta=$d source=$src");
[$d, $src] = qa_usage_delta(qa_courier_result([], 'fresh'));
ok('U2', 'control: all couriers ok, fresh: local courier usage +1', $d === 1, "delta=$d source=$src");
[$d, $src] = qa_usage_delta(qa_courier_result(['pathao'], 'fresh'));
ok('U2b', 'control: one courier error, others ok, fresh: local courier usage +1', $d === 1, "delta=$d source=$src");
[$d, $src] = qa_usage_delta(qa_courier_result($all, 'server_cache'));
ok('U3', 'control: all couriers error, server_cache: local courier usage unchanged', $d === 0, "delta=$d source=$src");

// restore
delete_transient('cg_api_cache');
if ($GLOBALS['QA_API_CACHE_ORIG'] !== false) { set_transient('cg_api_cache', $GLOBALS['QA_API_CACHE_ORIG'], $GLOBALS['QA_API_CACHE_TTL']); }
cg_update_options_from_hq(is_array($GLOBALS['QA_OPTS_ORIG']) ? $GLOBALS['QA_OPTS_ORIG'] : []);
restore_error_handler();
$R = $GLOBALS['R'];
foreach ($R as $r) echo implode(' | ', $r), "\n";
echo 'warnings_total=' . count($GLOBALS['QA_WARN']) . "\n"; foreach (array_unique($GLOBALS['QA_WARN']) as $w) echo "  W: $w\n";
$fl = count(array_filter($R, function ($r) { return $r[2] !== 'PASS'; })) + (count($GLOBALS['QA_WARN']) ? 1 : 0);
echo "SUMMARY " . (count($R) - $fl) . '/' . count($R) . " PASS\n";
