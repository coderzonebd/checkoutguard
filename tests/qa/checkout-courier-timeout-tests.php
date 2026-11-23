<?php
/**
 * CG 1.5.1 spec (checkout courier-check timeout):
 * 1. Checkout path: courier-check timeout 5 s (cg_hq_timeout_override); admin
 *    and other paths keep 30 s; a timeout fails open.
 * 2. Timeout or 5xx: 60 s site-wide wait (not per phone): no courier-check
 *    call, orders fail open; nothing verified/blocked is ever cached for it;
 *    a successful courier answer ends it. The 401/403 cache from #12 stays
 *    separate.
 *    5xx means 500..599: 504 starts the wait, 600 does not (C6b).
 *
 * C1 needs a local slow server (answers after 35 s) on 127.0.0.1:QA_SLOW_PORT;
 * the "slow" route makes a real cURL request to it with the plugin's timeout.
 *
 * Run on a local test site with WooCommerce only (never production):
 *   wp eval-file tests/qa/checkout-courier-timeout-tests.php
 * HTTP is answered by a pre_http_request filter in this process; nothing
 * leaves the machine. Options, transients and blocked-orders rows it adds
 * are restored at the end.
 */
if (!defined('ABSPATH')) {
    exit;
}
global $wpdb;
const QA_MAX_CHECKOUT_TIMEOUT = 5;
const QA_MAX_CHECKOUT_SECONDS = 6.5;
const QA_ADMIN_TIMEOUT = 30;
const QA_WAIT_MIN = 55;
const QA_WAIT_MAX = 60;
if (!defined('QA_SLOW_PORT')) { define('QA_SLOW_PORT', (int) (getenv('QA_SLOW_PORT') ?: 8191)); }
$GLOBALS['QA_OPTS_ORIG'] = get_option('cg_plugin_options', []);
$GLOBALS['QA_HTTP'] = ['code' => 'down'];
$GLOBALS['QA_CALLS'] = [];
add_filter('pre_http_request', function ($pre, $args, $url) {
    $path = (string) wp_parse_url($url, PHP_URL_PATH);
    if (strpos($path, '/sites/courier-check') === false) {
        $GLOBALS['QA_OTHER_CALLS'][] = $path;
        if (!empty($GLOBALS['QA_OTHER_2XX'])) {
            return ['headers' => new WpOrg\Requests\Utility\CaseInsensitiveDictionary([]), 'body' => json_encode(['ok' => true]), 'response' => ['code' => 200, 'message' => ''], 'cookies' => [], 'filename' => null];
        }
        return new WP_Error('qa_blocked', 'blocked in test');
    }
    $GLOBALS['QA_CALLS'][] = (int) ($args['timeout'] ?? -1);
    $r = $GLOBALS['QA_HTTP'];
    if ($r['code'] === 'down') {
        return new WP_Error('http_request_failed', 'cURL error 7: Failed to connect');
    }
    if ($r['code'] === 'slow') {
        try {
            WpOrg\Requests\Requests::request('http://127.0.0.1:' . QA_SLOW_PORT . '/slow', [], [], 'GET', ['timeout' => (float) $args['timeout'], 'connect_timeout' => 2]);
        } catch (Exception $e) {
            return new WP_Error('http_request_failed', $e->getMessage());
        }
        return new WP_Error('qa_slow_answered', 'slow server answered before the timeout');
    }
    if ($r['code'] === 'timeout') {
        return new WP_Error('http_request_failed', 'cURL error 28: Operation timed out after ' . ((int) $args['timeout'] * 1000) . ' milliseconds with 0 bytes received');
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
function qa_set_key($key, $grace = 0) {
    $o = is_array($GLOBALS['QA_OPTS_ORIG']) ? $GLOBALS['QA_OPTS_ORIG'] : [];
    $o['api_key'] = $key;
    $o['enable_courier_service'] = 1; $o['enable_fraud_blocker'] = 0; $o['duplicate_blocker_enabled'] = 0;
    $o['ratio_blocker_grace_period'] = $grace; $o['ratio_blocker_threshold'] = 20;
    cg_update_options_from_hq($o);
}
$GLOBALS['QA_N'] = 0;
$GLOBALS['QA_PHONES'] = [];
function qa_phone() {
    $GLOBALS['QA_N']++;
    $phone = '0171800' . str_pad((string) $GLOBALS['QA_N'], 4, '0', STR_PAD_LEFT);
    delete_transient('cg_courier_cache_' . md5($phone));
    $GLOBALS['QA_PHONES'][] = $phone;
    return $phone;
}
// Every courier-check timer the plugin might use for "wait after timeout".
// (Not the per-phone result cache, not the #12 auth-reject cache.)
function qa_wait_rows() {
    global $wpdb;
    return $wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_timeout\\_cg\\_courier\\_%' AND option_name NOT LIKE '\\_transient\\_timeout\\_cg\\_courier\\_cache\\_%' AND option_name <> '_transient_timeout_cg_courier_auth_reject'");
}
function qa_clear_waits() {
    global $wpdb;
    // Also a wait stored without expiry (no _transient_timeout_ row).
    $names = $wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_cg\\_courier\\_%' AND option_name NOT LIKE '\\_transient\\_cg\\_courier\\_cache\\_%' AND option_name <> '_transient_cg_courier_auth_reject'");
    foreach ($names as $row) { delete_transient(substr($row, strlen('_transient_'))); }
}
function qa_expire_waits() {
    foreach (qa_wait_rows() as $row) {
        update_option($row, time() - 1, false);
        wp_cache_delete($row, 'options');
        wp_cache_delete('_transient_' . substr($row, strlen('_transient_timeout_')), 'options');
    }
}
function qa_wait_ttl() {
    $t = [];
    foreach (qa_wait_rows() as $row) {
        $t[] = (int) get_option($row) - time();
    }
    return $t ? max($t) : null;
}
// One checkout through the validation engine; returns [calls, timeouts, allowed].
function qa_checkout($http) {
    $phone = qa_phone();
    $GLOBALS['QA_HTTP'] = $http;
    $GLOBALS['QA_CALLS'] = [];
    $n = $GLOBALS['QA_N'];
    $ctx = new CG_Validation_Context(['phone' => $phone, 'ip' => '10.79.0.' . $n, 'email' => "qa-ct$n@example.invalid", 'order_source' => 'qa', 'request_type' => 'ajax']);
    $t0 = microtime(true);
    $res = get_object_vars(CG_Validation_Engine::validate($ctx));
    $GLOBALS['QA_LAST_SECONDS'] = round(microtime(true) - $t0, 1);
    $GLOBALS['QA_LAST_PHONE'] = $phone;
    $allowed = empty($res['is_blocked']) && empty($res['is_held']);
    return [count($GLOBALS['QA_CALLS']), $GLOBALS['QA_CALLS'], $allowed];
}
// One admin courier check (popup / order screen path).
function qa_admin($http, $bypass = false) {
    $phone = qa_phone();
    $GLOBALS['QA_HTTP'] = $http;
    $GLOBALS['QA_CALLS'] = [];
    $r = cg_perform_all_api_calls($phone, 0, $bypass);
    return [count($GLOBALS['QA_CALLS']), $GLOBALS['QA_CALLS'], is_array($r) ? ($r['cache_source'] ?? '') : 'not-array'];
}

$good = ['code' => 200, 'body' => ['cache_source' => 'fresh', 'result' => ['summary' => ['success_rate' => 95, 'total_deliveries' => 20, 'successful_deliveries' => 19, 'cancelled_deliveries' => 1]]]];
$bad = ['code' => 200, 'body' => ['cache_source' => 'fresh', 'result' => ['summary' => ['success_rate' => 5, 'total_deliveries' => 20, 'successful_deliveries' => 1, 'cancelled_deliveries' => 19]]]];
$slow = ['code' => 'slow'];
$t28 = ['code' => 'timeout'];
$down = ['code' => 'down'];
$u401 = ['code' => 401, 'body' => ['error' => 'Unauthorized']];

qa_set_key('qa-key-one-not-real', 0);
delete_transient('cg_courier_auth_reject');
qa_clear_waits();
$GLOBALS['cg_hq_timeout_override_before'] = $GLOBALS['cg_hq_timeout_override'] ?? null;
function qa_cached($phone) { return get_transient('cg_courier_cache_' . md5($phone)) !== false; }

// (ka) HQ answers after 35 s: checkout done in <= 6.5 s, 5 s timeout, allowed.
$c1 = qa_checkout($slow);
ok('C1', '(ka) HQ 35 s slow: checkout ends in <= ' . QA_MAX_CHECKOUT_SECONDS . ' s, timeout <= ' . QA_MAX_CHECKOUT_TIMEOUT . ' s, allowed', $c1[0] === 1 && $c1[1][0] > 0 && $c1[1][0] <= QA_MAX_CHECKOUT_TIMEOUT && $GLOBALS['QA_LAST_SECONDS'] <= QA_MAX_CHECKOUT_SECONDS && $c1[2], json_encode($c1) . ' seconds=' . $GLOBALS['QA_LAST_SECONDS']);

// (kha) timeout -> 60 s site-wide wait: new phones, 0 calls, allowed.
qa_clear_waits();
$a = qa_checkout($t28); $b = qa_checkout($good); $bp = $GLOBALS['QA_LAST_PHONE']; $c = qa_checkout($bad);
ok('C2', '(kha) after a timeout: next 2 checkouts (new phones) 0 HQ calls, all allowed', $a[0] === 1 && $a[2] && $b[0] === 0 && $b[2] && $c[0] === 0 && $c[2], json_encode([$a, $b, $c]));
$ttl = qa_wait_ttl();
ok('C3', '(kha) wait is 60 s (TTL ' . QA_WAIT_MIN . '..' . QA_WAIT_MAX . ')', $ttl !== null && $ttl >= QA_WAIT_MIN && $ttl <= QA_WAIT_MAX, 'ttl=' . var_export($ttl, true));
ok('C4', 'nothing cached for a phone skipped in the wait, no 401 cache written', !qa_cached($bp) && get_transient('cg_courier_auth_reject') === false, 'cached=' . var_export(qa_cached($bp), true) . ' auth_reject=' . var_export(get_transient('cg_courier_auth_reject') !== false, true));

// (ga) after 60 s: calls again.
$d0 = qa_checkout($good);
qa_expire_waits();
$d = qa_checkout($good);
ok('C5', '(ga) in the wait 0 calls; wait over: checkout calls HQ again (1 call)', $d0[0] === 0 && $d[0] === 1 && $d[2], json_encode([$d0, $d]));

// (gha) 5xx behaves like a timeout.
foreach ([['C6', 500], ['C7', 503]] as $t) {
    qa_clear_waits();
    $e1 = qa_checkout(['code' => $t[1], 'body' => ['error' => 'x']]); $e2 = qa_checkout($good); $e3 = qa_checkout($good);
    $tt = qa_wait_ttl();
    ok($t[0], '(gha) HQ ' . $t[1] . ': allowed, 60 s wait, next 2 checkouts 0 HQ calls', $e1[0] === 1 && $e1[2] && $e2[0] === 0 && $e3[0] === 0 && $e2[2] && $e3[2] && $tt !== null && $tt >= QA_WAIT_MIN && $tt <= QA_WAIT_MAX, json_encode([$e1, $e2, $e3]) . ' ttl=' . var_export($tt, true));
}

// C6b: the 5xx range is 500..599 on both ends. 504 starts the wait like
// 500/503; 600 is not 5xx, so no wait and the next checkout calls HQ again.
qa_clear_waits();
$x1 = qa_checkout(['code' => 504, 'body' => ['error' => 'x']]); $x2 = qa_checkout($good);
$xt = qa_wait_ttl();
ok('C6b', '(gha) HQ 504: allowed, 60 s wait, next checkout 0 HQ calls', $x1[0] === 1 && $x1[2] && $x2[0] === 0 && $x2[2] && $xt !== null && $xt >= QA_WAIT_MIN && $xt <= QA_WAIT_MAX, json_encode([$x1, $x2]) . ' ttl=' . var_export($xt, true));
qa_clear_waits();
$y1 = qa_checkout(['code' => 600, 'body' => ['error' => 'x']]); $y2 = qa_checkout($good);
$yt = qa_wait_ttl();
ok('C6b-ctl', 'HQ 600 is not 5xx: allowed, no wait, next checkout calls HQ (1 call)', $y1[0] === 1 && $y1[2] && $y2[0] === 1 && $y2[2] && $yt === null, json_encode([$y1, $y2]) . ' ttl=' . var_export($yt, true));

// A successful courier answer ends the wait (admin force refresh is the only call made in it).
qa_clear_waits();
qa_checkout($t28);
$f0 = qa_checkout($good);
$f = qa_admin($good, true);
$g = qa_checkout($good);
ok('C8', 'in the wait 0 calls; success (admin force refresh, fresh) ends it, next checkout calls again', $f0[0] === 0 && $f[0] === 1 && $f[2] === 'fresh' && $g[0] === 1 && qa_wait_ttl() === null, json_encode([$f0, $f, $g]));

// (nga) admin path keeps 30 s, also right after a checkout (override not leaked).
qa_clear_waits();
qa_checkout($good);
$h = qa_admin($good);
ok('C9', '(nga) admin path after a checkout: 30 s (guard, passes on control too)', $h[0] === 1 && $h[1][0] === QA_ADMIN_TIMEOUT && ($GLOBALS['cg_hq_timeout_override'] ?? null) === $GLOBALS['cg_hq_timeout_override_before'], json_encode($h) . ' override=' . var_export($GLOBALS['cg_hq_timeout_override'] ?? null, true));

// Separate from the #12 401/403 cache.
qa_clear_waits(); delete_transient('cg_courier_auth_reject');
qa_checkout($u401);
$w401 = qa_wait_ttl(); $ar = get_transient('cg_courier_auth_reject') !== false;
delete_transient('cg_courier_auth_reject');
ok('C10', '401 (no code): only the #12 cache, no 60 s wait (guard)', $w401 === null && $ar, 'wait=' . var_export($w401, true) . ' auth_reject=' . var_export($ar, true));

qa_clear_waits();
$k1 = qa_checkout(['code' => 429, 'h' => ['retry-after' => '60'], 'body' => ['error' => 'Too Many Requests']]); $k2 = qa_checkout($good);
ok('C11', '429 is not 5xx: no wait, next checkout calls (guard)', $k1[0] === 1 && $k2[0] === 1 && qa_wait_ttl() === null, json_encode([$k1, $k2]));

qa_clear_waits();
$m1 = qa_checkout($down); $m2 = qa_checkout($down);
ok('C12', 'HQ down (cURL 7, not a timeout): no wait, every checkout calls (guard, as before)', $m1[0] === 1 && $m2[0] === 1 && $m1[2] && $m2[2] && qa_wait_ttl() === null, json_encode([$m1, $m2]));

qa_clear_waits();
$n = qa_admin($t28);
ok('C13', 'admin path timeout: 30 s used, fails open as error (guard)', $n[0] === 1 && $n[1][0] === QA_ADMIN_TIMEOUT && $n[2] === 'error', json_encode($n));

// C14: only a successful courier-check answer ends the wait. Another HQ
// endpoint answering 2xx (here GET /sites/status) says nothing about the
// courier path: the wait stays and the next checkout makes no call.
qa_clear_waits();
$GLOBALS['QA_OTHER_CALLS'] = [];
$p1 = qa_checkout($t28);
$GLOBALS['QA_OTHER_2XX'] = true;
$other = cg_call_hq_api('/sites/status', [], 'GET');
$GLOBALS['QA_OTHER_2XX'] = false;
$p2 = qa_checkout($good);
$tt14 = qa_wait_ttl();
ok('C14', 'other HQ endpoint 2xx in the wait: wait stays, next checkout 0 calls', $p1[0] === 1 && count($GLOBALS['QA_OTHER_CALLS']) === 1 && !is_wp_error($other) && $p2[0] === 0 && $p2[2] && $tt14 !== null, json_encode([$p1, $p2]) . ' other=' . (is_wp_error($other) ? $other->get_error_code() : 'ok') . ' ttl=' . var_export($tt14, true));

// C15: the admin popup (no bypass) honours the wait: 0 calls, fails open as
// error, wait stays. Only "try again" / bypass makes a call (C8).
qa_clear_waits();
$q1 = qa_checkout($t28);
$q = qa_admin($good, false);
$tt15 = qa_wait_ttl();
ok('C15', 'admin popup without bypass in the wait: 0 calls, error, wait stays', $q1[0] === 1 && $q[0] === 0 && $q[2] === 'error' && $tt15 !== null, json_encode([$q1, $q]) . ' ttl=' . var_export($tt15, true));

foreach ($GLOBALS['QA_PHONES'] as $p) { delete_transient('cg_courier_cache_' . md5($p)); }
qa_clear_waits();
delete_transient('cg_courier_auth_reject');
$wpdb->query("DELETE FROM $table WHERE id > $max_id_before");
cg_update_options_from_hq(is_array($GLOBALS['QA_OPTS_ORIG']) ? $GLOBALS['QA_OPTS_ORIG'] : []);
restore_error_handler();
$R = $GLOBALS['R'];
foreach ($R as $r) echo implode(' | ', $r), "\n";
echo 'warnings_total=' . count($GLOBALS['QA_WARN']) . "\n"; foreach (array_unique($GLOBALS['QA_WARN']) as $w) echo "  W: $w\n";
$fl = count(array_filter($R, function ($r) { return $r[2] !== 'PASS'; })) + (count($GLOBALS['QA_WARN']) ? 1 : 0);
echo "SUMMARY " . (count($R) - $fl) . '/' . count($R) . " PASS\n";
