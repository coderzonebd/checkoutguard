<?php
/**
 * HQ cache_source "failed" (mainlicense courier-check): when every courier
 * failed, HQ refunds the courier_checks charge and answers cache_source
 * "failed" instead of "fresh". The plugin adds +1 to its local usage only
 * for "fresh", so a refunded lookup must not move the local counter.
 *
 * Runs on the current plugin and on an old one (1.4.2), so the PR can state
 * what an old plugin does with the new wire value:
 *   F1  failed, all couriers error: local usage unchanged.
 *   F2  failed, all couriers error: the answer keeps cache_source "failed".
 *   F3  control: fresh, all couriers ok: local usage +1.
 *   F7  no PHP warning or notice from the plugin.
 * Current plugin only (reported as LEGACY on an old plugin, not counted):
 *   F4  failed, all couriers error: not kept in the 6 h courier cache.
 *   F5  failed, but one courier ok in the answer: local usage unchanged and
 *       not cached (HQ refunded it, so HQ's word wins).
 *   F6  the admin badge for "failed" is not the "Unknown" fallback.
 *
 * Run on a local test site only (never production):
 *   wp eval-file tests/qa/courier-failed-source-tests.php
 * HTTP is answered by a pre_http_request filter in this process; nothing
 * leaves the machine. Options and cg_api_cache are restored at the end.
 */
if (!defined('ABSPATH')) {
    exit;
}
$GLOBALS['QA_LEGACY'] = !function_exists('cg_courier_error_results');
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
    if (strpos($file, 'checkoutguard') !== false || strpos($file, '/tmp/cg') === 0) {
        $GLOBALS['QA_WARN'][] = "$str @ " . basename($file) . ":$line";
    }
    return false;
});
$GLOBALS['R'] = [];
function ok($id, $name, $pass, $detail, $new_only = false)
{
    $state = $pass ? 'PASS' : 'FAIL';
    if ($new_only && $GLOBALS['QA_LEGACY']) {
        $state = 'LEGACY';
    }
    $GLOBALS['R'][] = [$id, $name, $state, $detail];
}
$o = is_array($GLOBALS['QA_OPTS_ORIG']) ? $GLOBALS['QA_OPTS_ORIG'] : [];
$o['api_key'] = 'qa-shared-key-not-real';
$o['enable_courier_service'] = 1;
cg_update_options_from_hq($o);

function qa_fs_answer($ok_couriers, $source)
{
    $res = ['summary' => ['success_rate' => 0, 'total_deliveries' => 0, 'successful_deliveries' => 0, 'cancelled_deliveries' => 0]];
    foreach (array_keys(cg_get_supported_couriers()) as $k) {
        $res[$k] = in_array($k, $ok_couriers, true)
            ? ['error' => false, 'total' => 2, 'success' => 2, 'cancelled' => 0, 'success_rate' => 100]
            : ['error' => true, 'message' => 'timeout'];
    }
    return ['code' => 200, 'body' => ['cache_source' => $source, 'result' => $res]];
}
$GLOBALS['QA_N'] = 0;
function qa_fs_run($http)
{
    $GLOBALS['QA_N']++;
    set_transient('cg_api_cache', ['limits' => ['courier_checks' => 100], 'usage' => ['courier_checks' => 5]], DAY_IN_SECONDS);
    foreach (['CG_COURIER_AUTH_REJECT_TRANSIENT', 'CG_COURIER_TIMEOUT_WAIT_TRANSIENT'] as $c) {
        if (defined($c)) {
            delete_transient(constant($c));
        }
    }
    $phone = '0171222' . str_pad((string) $GLOBALS['QA_N'], 4, '0', STR_PAD_LEFT);
    $key = 'cg_courier_cache_' . md5($phone);
    delete_transient($key);
    $GLOBALS['QA_HTTP'] = $http;
    $r = cg_perform_all_api_calls($phone, 0);
    $c = get_transient('cg_api_cache');
    $u = is_array($c) ? (int) ($c['usage']['courier_checks'] ?? -1) : -1;
    $cached = get_transient($key) !== false;
    delete_transient($key);
    return [$u - 5, is_array($r) ? ($r['cache_source'] ?? '') : 'not-array', $cached];
}

[$d, $src, $cached] = qa_fs_run(qa_fs_answer([], 'failed'));
ok('F1', 'failed, all couriers error: local courier usage unchanged', $d === 0, "delta=$d source=$src");
ok('F2', 'failed, all couriers error: answer keeps cache_source failed', $src === 'failed', "source=$src");
ok('F4', 'failed, all couriers error: not kept in the 6 h courier cache', !$cached, 'cached=' . ($cached ? 'yes' : 'no'), true);

[$d, $src, $cached] = qa_fs_run(qa_fs_answer(['redx'], 'failed'));
ok('F5', 'failed, one courier ok in the answer: usage unchanged and not cached', $d === 0 && !$cached, "delta=$d cached=" . ($cached ? 'yes' : 'no') . " source=$src", true);

[$d, $src, $cached] = qa_fs_run(qa_fs_answer(array_keys(cg_get_supported_couriers()), 'fresh'));
ok('F3', 'control: fresh, all couriers ok: local courier usage +1', $d === 1, "delta=$d source=$src");

$badge = cg_get_cache_badge_config('failed');
ok('F6', 'admin badge for failed is not the Unknown fallback', is_array($badge) && ($badge['label'] ?? '') !== 'Unknown', 'label=' . (is_array($badge) ? ($badge['label'] ?? '') : 'n/a'), true);

// restore
delete_transient('cg_api_cache');
if ($GLOBALS['QA_API_CACHE_ORIG'] !== false) {
    set_transient('cg_api_cache', $GLOBALS['QA_API_CACHE_ORIG'], $GLOBALS['QA_API_CACHE_TTL']);
}
cg_update_options_from_hq(is_array($GLOBALS['QA_OPTS_ORIG']) ? $GLOBALS['QA_OPTS_ORIG'] : []);
restore_error_handler();
ok('F7', 'no PHP warning or notice from the plugin', count($GLOBALS['QA_WARN']) === 0, 'warnings=' . count($GLOBALS['QA_WARN']));
$R = $GLOBALS['R'];
foreach ($R as $r) {
    echo implode(' | ', $r), "\n";
}
foreach (array_unique($GLOBALS['QA_WARN']) as $w) {
    echo "  W: $w\n";
}
$counted = array_filter($R, function ($r) { return $r[2] !== 'LEGACY'; });
$fl = count(array_filter($counted, function ($r) { return $r[2] !== 'PASS'; }));
echo 'PLUGIN ' . ($GLOBALS['QA_LEGACY'] ? 'legacy' : 'current') . "\n";
echo 'SUMMARY ' . (count($counted) - $fl) . '/' . count($counted) . ' ' . ($fl === 0 ? 'PASS' : 'FAIL') . "\n";
