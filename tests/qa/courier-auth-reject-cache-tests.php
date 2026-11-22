<?php
/**
 * Courier check: a 401/403 without a body "code" is kept for 5 minutes per
 * key, so checkouts in that window do not call HQ again (they fail open as
 * cache_source "error"). 429, 5xx, timeouts and coded 401/403 are not kept;
 * a new key, any 2xx from HQ, or an admin "force refresh" ends it.
 *
 * Run on a local test site with WooCommerce only (never production):
 *   wp eval-file tests/qa/courier-auth-reject-cache-tests.php
 * HTTP is answered by a pre_http_request filter in this process; nothing
 * leaves the machine. Options, transients and blocked-orders rows it adds
 * are restored at the end.
 */
if (!defined('ABSPATH')) {
    exit;
}
global $wpdb;
$GLOBALS['QA_OPTS_ORIG'] = get_option('cg_plugin_options', []);
$GLOBALS['QA_REJECT_ORIG'] = get_transient('cg_courier_auth_reject');
$GLOBALS['QA_HTTP'] = ['code' => 'down'];
$GLOBALS['QA_CALLS'] = [];
add_filter('pre_http_request', function ($pre, $args, $url) {
    $path = (string) wp_parse_url($url, PHP_URL_PATH);
    $GLOBALS['QA_CALLS'][] = $path;
    if (strpos($path, '/sites/courier-check') !== false) {
        $r = $GLOBALS['QA_HTTP'];
    } elseif (strpos($path, '/sites/status') !== false && isset($GLOBALS['QA_STATUS'])) {
        $r = $GLOBALS['QA_STATUS'];
    } else {
        return new WP_Error('qa_blocked', 'blocked in test');
    }
    if ($r['code'] === 'down') {
        return new WP_Error('http_request_failed', 'cURL error 7: Failed to connect');
    }
    return ['headers' => new WpOrg\Requests\Utility\CaseInsensitiveDictionary($r['h'] ?? []), 'body' => json_encode($r['body'] ?? []), 'response' => ['code' => $r['code'], 'message' => ''], 'cookies' => [], 'filename' => null];
}, PHP_INT_MAX, 3);
$GLOBALS['QA_WARN'] = [];
set_error_handler(function ($no, $str, $file, $line) {
    if (strpos($file, 'checkoutguard') !== false) {
        $GLOBALS['QA_WARN'][] = "$str @ " . basename($file) . ":$line";
    }
    return false;
});
$table = $wpdb->prefix . 'cg_blocked_orders';
$max_id_before = (int) $wpdb->get_var("SELECT COALESCE(MAX(id), 0) FROM $table");
$GLOBALS['R'] = [];
function ok($id, $name, $pass, $detail) { $GLOBALS['R'][] = [$id, $name, $pass ? 'PASS' : 'FAIL', $detail]; }
function qa_set_key($key, $grace = 5) {
    $o = is_array($GLOBALS['QA_OPTS_ORIG']) ? $GLOBALS['QA_OPTS_ORIG'] : [];
    if ($key === null) { unset($o['api_key']); } else { $o['api_key'] = $key; }
    $o['enable_courier_service'] = 1; $o['enable_fraud_blocker'] = 0; $o['duplicate_blocker_enabled'] = 0;
    $o['ratio_blocker_grace_period'] = $grace; $o['ratio_blocker_threshold'] = 20;
    cg_update_options_from_hq($o);
}
$GLOBALS['QA_N'] = 0;
$GLOBALS['QA_PHONES'] = [];
// One courier check for a new phone; returns [courier-check calls made, result].
function qa_check($http, $bypass = false) {
    $GLOBALS['QA_N']++;
    $phone = '0171900' . str_pad((string) $GLOBALS['QA_N'], 4, '0', STR_PAD_LEFT);
    delete_transient('cg_courier_cache_' . md5($phone));
    $GLOBALS['QA_PHONES'][] = $phone;
    $GLOBALS['QA_HTTP'] = $http;
    $GLOBALS['QA_CALLS'] = [];
    $r = cg_perform_all_api_calls($phone, 0, $bypass);
    $n = count(array_filter($GLOBALS['QA_CALLS'], function ($p) { return strpos($p, '/sites/courier-check') !== false; }));
    return [$n, is_array($r) ? ($r['cache_source'] ?? '') : 'not-array'];
}
function qa_expire() {
    update_option('_transient_timeout_cg_courier_auth_reject', time() - 1, false);
    wp_cache_delete('_transient_timeout_cg_courier_auth_reject', 'options');
    wp_cache_delete('_transient_cg_courier_auth_reject', 'options');
}
function qa_kept() { return get_transient('cg_courier_auth_reject') !== false; }

$u401 = ['code' => 401, 'body' => ['error' => 'Unauthorized']];
$u403 = ['code' => 403, 'body' => ['error' => 'Forbidden']];
$good = ['code' => 200, 'body' => ['cache_source' => 'fresh', 'result' => ['summary' => ['success_rate' => 95, 'total_deliveries' => 20, 'successful_deliveries' => 19, 'cancelled_deliveries' => 1]]]];

qa_set_key('qa-key-one-not-real');
delete_transient('cg_courier_auth_reject');

$a1 = qa_check($u401); $a2 = qa_check($u401); $a3 = qa_check($good);
ok('N1', '401 no code: 1st check calls HQ, next two in the window do not, all fail open', $a1 === [1, 'error'] && $a2 === [0, 'error'] && $a3 === [0, 'error'], json_encode([$a1, $a2, $a3]));
$ttl = (int) get_option('_transient_timeout_cg_courier_auth_reject') - time();
ok('N2', 'kept for 5 minutes (TTL 295..300 s)', $ttl >= 295 && $ttl <= 300, "ttl=$ttl");
qa_expire();
$b1 = qa_check($good);
ok('N3', 'after 5 minutes: one HQ call again, real answer used', $b1 === [1, 'fresh'], json_encode($b1));

delete_transient('cg_courier_auth_reject');
$c1 = qa_check($u403); $c2 = qa_check($u403);
ok('N4', '403 no code: kept the same way', $c1 === [1, 'error'] && $c2 === [0, 'error'], json_encode([$c1, $c2]));

delete_transient('cg_courier_auth_reject');
$coded = ['code' => 403, 'body' => ['error' => 'x', 'code' => 'site_key_required']];
$d1 = qa_check($coded); $d2 = qa_check($coded);
ok('N5', '403 with a body code (site-key answer): not kept, every check calls', $d1 === [1, 'error'] && $d2 === [1, 'error'] && !qa_kept(), json_encode([$d1, $d2]));

foreach ([['N6', '429', ['code' => 429, 'h' => ['retry-after' => '60'], 'body' => ['error' => 'Too Many Requests']]], ['N7', '503', ['code' => 503, 'body' => ['error' => 'x']]], ['N8', 'HQ down (cURL 7)', ['code' => 'down']]] as $t) {
    delete_transient('cg_courier_auth_reject');
    $e1 = qa_check($t[2]); $e2 = qa_check($t[2]);
    ok($t[0], $t[1] . ': not kept, every check calls (as before)', $e1 === [1, 'error'] && $e2 === [1, 'error'] && !qa_kept(), json_encode([$e1, $e2]));
}

delete_transient('cg_courier_auth_reject');
qa_check($u401);
qa_set_key('qa-key-two-not-real');
$f1 = qa_check($good);
ok('N9', 'key changed: kept 401 ends at once, HQ called', $f1 === [1, 'fresh'] && !qa_kept(), json_encode($f1));

delete_transient('cg_courier_auth_reject');
qa_check($u401);
$GLOBALS['QA_STATUS'] = ['code' => 200, 'body' => ['status' => 'active']];
cg_call_hq_api('/sites/status', [], 'GET');
unset($GLOBALS['QA_STATUS']);
$g1 = qa_check($good);
ok('N10', 'any 2xx from HQ (status sync) clears it', $g1 === [1, 'fresh'], json_encode($g1) . ' kept_after_status=' . var_export(qa_kept(), true));

delete_transient('cg_courier_auth_reject');
qa_check($u401);
$h1 = qa_check($good, true);
ok('N11', 'admin force refresh (bypass_cache) calls HQ even while kept', $h1 === [1, 'fresh'], json_encode($h1));

delete_transient('cg_courier_auth_reject');
qa_set_key(null);
$i1 = qa_check($u401); $i2 = qa_check($u401);
ok('N12', 'no key at all: no HQ call, nothing kept (api_key_missing is local)', $i1 === [0, 'error'] && $i2 === [0, 'error'] && !qa_kept(), json_encode([$i1, $i2]));

// Checkout path: grace 0, kept 401 -> order allowed, HQ not called.
delete_transient('cg_courier_auth_reject');
qa_set_key('qa-key-one-not-real', 0);
qa_check($u401);
$GLOBALS['QA_CALLS'] = [];
$GLOBALS['QA_HTTP'] = $u401;
$ctx = new CG_Validation_Context(['phone' => '01719009999', 'ip' => '10.78.0.1', 'email' => 'qa-n13@example.invalid', 'order_source' => 'qa', 'request_type' => 'ajax']);
$res = get_object_vars(CG_Validation_Engine::validate($ctx));
$calls = count(array_filter($GLOBALS['QA_CALLS'], function ($p) { return strpos($p, '/sites/courier-check') !== false; }));
ok('N13', 'checkout while kept, grace 0: allowed, 0 HQ calls', empty($res['is_blocked']) && empty($res['is_held']) && $calls === 0, "calls=$calls blocked=" . var_export($res['is_blocked'] ?? null, true));

foreach (array_merge($GLOBALS['QA_PHONES'], ['01719009999']) as $p) { delete_transient('cg_courier_cache_' . md5($p)); }
delete_transient('cg_courier_auth_reject');
if ($GLOBALS['QA_REJECT_ORIG'] !== false) { set_transient('cg_courier_auth_reject', $GLOBALS['QA_REJECT_ORIG'], 5 * MINUTE_IN_SECONDS); }
$wpdb->query("DELETE FROM $table WHERE id > $max_id_before");
cg_update_options_from_hq(is_array($GLOBALS['QA_OPTS_ORIG']) ? $GLOBALS['QA_OPTS_ORIG'] : []);
restore_error_handler();
$R = $GLOBALS['R'];
foreach ($R as $r) echo implode(' | ', $r), "\n";
echo 'warnings_total=' . count($GLOBALS['QA_WARN']) . "\n"; foreach (array_unique($GLOBALS['QA_WARN']) as $w) echo "  W: $w\n";
$fl = count(array_filter($R, function ($r) { return $r[2] !== 'PASS'; })) + (count($GLOBALS['QA_WARN']) ? 1 : 0);
echo "SUMMARY " . (count($R) - $fl) . '/' . count($R) . " PASS\n";
