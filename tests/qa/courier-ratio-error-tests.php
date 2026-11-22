<?php
/**
 * Courier ratio blocker on a failed courier check (Sakib F2 on 1.5.0
 * ada4b91): with grace period 0, a courier check that failed (no key, HQ
 * down, 429, timeout) returned a zeroed summary and every order was blocked
 * as "0%". A failed check, or a customer with no deliveries, must never
 * block or hold; a real low ratio still does.
 *
 * Run on a local test site with WooCommerce only (never production):
 *   wp eval-file tests/qa/courier-ratio-error-tests.php
 * HTTP is answered by a pre_http_request filter in this process; nothing
 * leaves the machine. Options and the blocked-orders rows it adds are
 * restored at the end (cg_last_known_plan too).
 */
if (!defined('ABSPATH')) {
    exit;
}
global $wpdb;
$GLOBALS['QA_OPTS_ORIG'] = get_option('cg_plugin_options', []);
$GLOBALS['QA_HTTP'] = ['code' => 'down'];
$GLOBALS['QA_PAID'] = false;
add_filter('pre_http_request', function ($pre, $args, $url) {
    if (strpos($url, '/sites/courier-check') === false) {
        return new WP_Error('qa_blocked', 'blocked in test');
    }
    $r = $GLOBALS['QA_HTTP'];
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
$GLOBALS['QA_N'] = 0;
function qa_run($key, $grace, $http, $action = null) {
    $GLOBALS['QA_N']++;
    $o = is_array($GLOBALS['QA_OPTS_ORIG']) ? $GLOBALS['QA_OPTS_ORIG'] : [];
    if ($key === null) { unset($o['api_key']); } else { $o['api_key'] = $key; }
    $o['enable_courier_service'] = 1; $o['enable_fraud_blocker'] = 0;
    $o['ratio_blocker_grace_period'] = $grace; $o['ratio_blocker_threshold'] = 20;
    if ($action !== null) { $o['ratio_action'] = $action; }
    cg_update_options_from_hq($o);
    $GLOBALS['QA_HTTP'] = $http;
    $n = $GLOBALS['QA_N'];
    $phone = '0170000' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    delete_transient('cg_courier_cache_' . md5($phone));
    $ctx = new CG_Validation_Context(['phone' => $phone, 'ip' => '10.77.0.' . $n, 'email' => "qa$n@example.invalid", 'order_source' => 'qa', 'request_type' => 'ajax']);
    $r = CG_Validation_Engine::validate($ctx);
    return get_object_vars($r);
}
function qa_is_allow($r) { return empty($r['is_blocked']) && empty($r['is_held']); }
function qa_short($r) { return json_encode(array_intersect_key($r, array_flip(['is_blocked', 'is_held', 'block_code']))); }

$low = ['code' => 200, 'body' => ['cache_source' => 'fresh', 'result' => ['summary' => ['success_rate' => 10, 'total_deliveries' => 10, 'successful_deliveries' => 1, 'cancelled_deliveries' => 9]]]];
$rows0 = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE id > $max_id_before");

$a = qa_run(null, 0, ['code' => 'down']);
ok('E1', 'no key, grace 0: allowed, no blocked row', qa_is_allow($a) && (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE id > $max_id_before") === $rows0, qa_short($a));
$b = qa_run('qa-shared-key-not-real', 0, ['code' => 'down']);
ok('E2', 'HQ down, grace 0: allowed', qa_is_allow($b), qa_short($b));
$c = qa_run('qa-shared-key-not-real', 0, ['code' => 429, 'h' => ['retry-after' => '60'], 'body' => ['error' => 'Too Many Requests']]);
ok('E3', 'HQ 429, grace 0: allowed', qa_is_allow($c), qa_short($c));
$d = qa_run('qa-shared-key-not-real', 0, ['code' => 500, 'body' => ['error' => 'x']]);
ok('E4', 'HQ 500, grace 0: allowed', qa_is_allow($d), qa_short($d));
$e = qa_run('qa-shared-key-not-real', 5, $low);
ok('E5', 'real ratio 10% over 10 deliveries, grace 5: still blocked', !qa_is_allow($e) && ($e['block_code'] ?? '') === 'blocked_ratio', qa_short($e));
$f = qa_run('qa-shared-key-not-real', 0, $low);
ok('E6', 'real ratio 10%, grace 0: still blocked', !qa_is_allow($f) && ($f['block_code'] ?? '') === 'blocked_ratio', qa_short($f));

$none = ['code' => 200, 'body' => ['cache_source' => 'fresh', 'result' => ['summary' => ['success_rate' => 0, 'total_deliveries' => 0, 'successful_deliveries' => 0, 'cancelled_deliveries' => 0]]]];
$i = qa_run('qa-shared-key-not-real', 0, $none);
ok('E9', 'real check, no deliveries yet, grace 0: allowed', qa_is_allow($i), qa_short($i));

// Paid plan, ratio_action hold: a failed check must not put the order on hold either.
$GLOBALS['QA_PLAN_ORIG'] = get_option('cg_last_known_plan', null);
cg_remember_last_known_plan(['plan_slug' => 'pro', 'status' => 'active']);
wp_cache_delete('cg_last_known_plan', 'options');
$paid = cg_is_paid_plan();
$g = qa_run('qa-shared-key-not-real', 0, ['code' => 'down'], 'hold');
ok('E7', 'paid plan (hold), HQ down, grace 0: not held', $paid && qa_is_allow($g), 'paid=' . var_export($paid, true) . ' ' . qa_short($g));
$h = qa_run('qa-shared-key-not-real', 0, $low, 'hold');
ok('E8', 'paid plan (hold), real ratio 10%: still held', $paid && !empty($h['is_held']) && empty($h['is_blocked']), qa_short($h));
if ($GLOBALS['QA_PLAN_ORIG'] === null) { delete_option('cg_last_known_plan'); } else { update_option('cg_last_known_plan', $GLOBALS['QA_PLAN_ORIG'], false); }
wp_cache_delete('cg_last_known_plan', 'options');

$wpdb->query("DELETE FROM $table WHERE id > $max_id_before");
cg_update_options_from_hq(is_array($GLOBALS['QA_OPTS_ORIG']) ? $GLOBALS['QA_OPTS_ORIG'] : []);
restore_error_handler();
$R = $GLOBALS['R'];
foreach ($R as $r) echo implode(' | ', $r), "\n";
echo 'warnings_total=' . count($GLOBALS['QA_WARN']) . "\n"; foreach (array_unique($GLOBALS['QA_WARN']) as $w) echo "  W: $w\n";
$fl = count(array_filter($R, function ($r) { return $r[2] !== 'PASS'; })) + (count($GLOBALS['QA_WARN']) ? 1 : 0);
echo "SUMMARY " . (count($R) - $fl) . '/' . count($R) . " PASS\n";
