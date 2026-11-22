<?php
/**
 * SiteKey T1 tests (request-site-key budget, gate, lock, Retry-After), ported
 * from the 1.4.2 tests of includes/sitekey-request.php (c4d17e6, 20dbfbc,
 * e7c2876) to includes/site-key.php. Same safety limits: at most 2 calls in
 * any 60 minutes, never a loop, a 429 never adds a notice.
 *
 * Run on a local test site only (never production):
 *   wp eval-file tests/qa/sitekey-request-t1-tests.php
 * HTTP is answered by a pre_http_request filter in this process; nothing
 * leaves the machine. It sets a dummy shared key, writes the site key
 * options and restores the previous options at the end.
 */
if (!defined('ABSPATH')) {
    exit;
}
$GLOBALS['QA_NOW'] = 1800000000; // fixed UTC base
$GLOBALS['QA_TZ_ORIG'] = get_option('timezone_string');
$GLOBALS['QA_OPTS_ORIG'] = get_option('cg_plugin_options', []);
add_filter('cg_sitekey_now', function () { return $GLOBALS['QA_NOW']; });
$GLOBALS['QA_CALLS'] = []; $GLOBALS['QA_PLAN'] = []; $GLOBALS['QA_DEFAULT'] = ['code' => 429];
add_filter('pre_http_request', function ($pre, $args, $url) {
    if (strpos($url, '/sites/request-site-key') === false) {
        return new WP_Error('qa_blocked', 'blocked in test');
    }
    $GLOBALS['QA_CALLS'][] = $GLOBALS['QA_NOW'];
    $r = $GLOBALS['QA_PLAN'] ? array_shift($GLOBALS['QA_PLAN']) : $GLOBALS['QA_DEFAULT'];
    if (($r['code'] ?? 0) === 'timeout') {
        return new WP_Error('http_request_failed', 'cURL error 28: timed out');
    }
    $h = [];
    if (array_key_exists('ra', $r)) {
        $h['retry-after'] = $r['ra'];
    }
    return ['headers' => new WpOrg\Requests\Utility\CaseInsensitiveDictionary($h), 'body' => json_encode($r['code'] >= 300 ? ['error' => 'x'] : ['status' => 'accepted']), 'response' => ['code' => $r['code'], 'message' => ''], 'cookies' => [], 'filename' => null];
}, PHP_INT_MAX, 3);
$GLOBALS['QA_WARN'] = [];
set_error_handler(function ($no, $str, $file, $line) {
    if (strpos($file, 'checkoutguard') !== false) {
        $GLOBALS['QA_WARN'][] = "$str @ " . basename($file) . ":$line";
    }
    return false;
});
$o = is_array($GLOBALS['QA_OPTS_ORIG']) ? $GLOBALS['QA_OPTS_ORIG'] : [];
$o['api_key'] = 'qa-shared-key-not-real';
cg_update_options_from_hq($o);

function qa_reset() {
    global $wpdb;
    foreach (['cg_sitekey_state', 'cg_sitekey_next_allowed_at', 'cg_sitekey_lock', 'cg_sitekey_last_drop'] as $o) {
        $wpdb->delete($wpdb->options, ['option_name' => $o]);
        wp_cache_delete($o, 'options');
    }
    wp_cache_delete('notoptions', 'options'); wp_cache_delete('alloptions', 'options');
    wp_clear_scheduled_hook('cg_sitekey_request_event'); wp_clear_scheduled_hook('cg_sitekey_claim_event'); wp_clear_scheduled_hook('cg_sitekey_retry_event');
    $GLOBALS['QA_CALLS'] = []; $GLOBALS['QA_PLAN'] = []; $GLOBALS['QA_DEFAULT'] = ['code' => 429];
}
function qa_gate() { return cg_sitekey_gate(); }
function qa_events() {
    $n = 0;
    foreach (['cg_sitekey_request_event', 'cg_sitekey_claim_event', 'cg_sitekey_retry_event'] as $h) {
        if (wp_next_scheduled($h) !== false) {
            $n++;
        }
    }
    return $n;
}
function qa_fire_due() { // what WP-Cron does: run a due request event once
    $ts = wp_next_scheduled('cg_sitekey_request_event');
    if ($ts && $ts <= $GLOBALS['QA_NOW']) {
        wp_unschedule_event($ts, 'cg_sitekey_request_event');
        do_action('cg_sitekey_request_event');
        return true;
    }
    return false;
}
function qa_max_in_window($calls) { $m = 0; foreach ($calls as $a) { $n = 0; foreach ($calls as $b) if ($b >= $a && $b < $a + 3600) $n++; $m = max($m, $n); } return $m; }
function qa_mins($c, $t0) { return implode(',', array_map(function ($t) use ($t0) { return round(($t - $t0) / 60, 1); }, $c)); }
$GLOBALS['R'] = [];
function ok($id, $name, $pass, $detail) { $GLOBALS['R'][] = [$id, $name, $pass ? 'PASS' : 'FAIL', $detail]; }
$B0 = cg_sitekey_backoff_seconds(0); $B1 = cg_sitekey_backoff_seconds(1);
$J = function ($g, $b) { return $g >= $b && $g <= $b + (int) floor($b * 0.25); };

// 1 & 2: always 429, 3 h; scheduled trigger every 10 min (the real one is six-hourly).
// 2 adds a manual (Reconnect) try every 5 min and a legacy_expired answer every minute (schedules the request event).
foreach ([1 => false, 2 => true] as $id => $busy) {
    qa_reset(); $t0 = $GLOBALS['QA_NOW'] = 1800000000;
    cg_sitekey_request('scheduled');
    for ($m = 1; $m <= 180; $m++) {
        $GLOBALS['QA_NOW'] = $t0 + $m * 60;
        if ($busy) {
            cg_sitekey_on_response(403, 'legacy_expired', 'qa-shared-key-not-real');
        }
        qa_fire_due();
        if ($m % 10 == 0) cg_sitekey_request('scheduled');
        if ($busy && $m % 5 == 0) cg_sitekey_request('manual');
    }
    $c = $GLOBALS['QA_CALLS']; $mx = qa_max_in_window($c);
    ok($id, $busy ? 'always 429 + manual + legacy_expired every minute, 3 h' : 'always 429, 3 h', $mx <= 2 && count($c) <= 6, 'calls=' . count($c) . ' max/60min=' . $mx . ' at min ' . qa_mins($c, $t0));
}

// 4: lock. 1.5.0 stale age is CG_SITEKEY_LOCK_STALE_AFTER (120 s; 1.4.2 had 15 min).
$S = CG_SITEKEY_LOCK_STALE_AFTER;
foreach (['4a' => [16 * 60, 'stale lock (16 min) taken over'], '4a2' => [$S + 1, 'stale lock (stale age + 1 s) taken over']] as $id => [$age, $name]) {
    qa_reset(); $GLOBALS['QA_NOW'] = 1800000000; $GLOBALS['QA_DEFAULT'] = ['code' => 202];
    add_option('cg_sitekey_lock', (1800000000 - $age) . '-111111', '', 'no');
    $r = cg_sitekey_request('scheduled'); $left = cg_sitekey_read_raw('cg_sitekey_lock');
    ok($id, $name, $r === 'accepted' && count($GLOBALS['QA_CALLS']) === 1 && $left === null, "result=$r calls=" . count($GLOBALS['QA_CALLS']) . ' lock_after=' . var_export($left, true));
}
qa_reset(); $GLOBALS['QA_DEFAULT'] = ['code' => 202];
add_option('cg_sitekey_lock', (1800000000 - 60) . '-222222', '', 'no');
$r = cg_sitekey_request('scheduled'); $left = cg_sitekey_read_raw('cg_sitekey_lock');
ok('4b', 'fresh lock (60 s) respected', $r === 'locked' && count($GLOBALS['QA_CALLS']) === 0 && $left === (1800000000 - 60) . '-222222', "result=$r calls=" . count($GLOBALS['QA_CALLS']) . ' lock kept=' . var_export($left === (1800000000 - 60) . '-222222', true));
qa_reset();
add_option('cg_sitekey_lock', (1800000000 - 20 * 60) . '-333333', '', 'no');
$a = cg_sitekey_acquire_lock(); $b = cg_sitekey_acquire_lock();
ok('4c', 'two takeovers of one stale lock: one wins', $a !== false && $b === false, 'first=' . ($a ? 'got' : 'no') . ' second=' . ($b ? 'got' : 'no')); if ($a) cg_sitekey_release_lock($a);
qa_reset();
add_option('cg_sitekey_lock', (1800000000 - $S) . '-444444', '', 'no');
$a = cg_sitekey_acquire_lock();
ok('4d', 'lock exactly at the stale age counts as stale', $a !== false, $a ? 'taken' : 'not taken'); if ($a) cg_sitekey_release_lock($a);
// 4e: a call still in flight after the stale age: its lock can be taken, but the gate set before the call stops a second call.
qa_reset(); $GLOBALS['QA_DEFAULT'] = ['code' => 202]; $GLOBALS['QA_NOW'] = 1800000000;
cg_sitekey_set_gate(1800000000 + $B0); add_option('cg_sitekey_lock', (1800000000 - 16 * 60) . '-555555', '', 'no');
$GLOBALS['QA_NOW'] = 1800000000 + $S + 5; $r = cg_sitekey_request('scheduled');
ok('4e', 'stale lock of an in-flight call: gate set before the call still blocks', $r === 'gated' && count($GLOBALS['QA_CALLS']) === 0, "result=$r calls=" . count($GLOBALS['QA_CALLS']));

// 5: backoff steps (fixed) and the jitter on the failure gate (0-25%, never below the step).
$steps = array_map('cg_sitekey_backoff_seconds', range(0, 6));
$jit = []; $jok = true; for ($i = 0; $i < 200; $i++) { $w = cg_sitekey_jitter($B0); $jit[] = $w; $jok = $jok && $J($w, $B0); }
$fg = [];
for ($i = 0; $i < 12; $i++) { qa_reset(); $GLOBALS['QA_NOW'] = 1800000000; cg_sitekey_request('scheduled'); $fg[] = qa_gate() - 1800000000; }
$fok = count(array_filter($fg, function ($g) use ($J, $B0) { return !$J($g, $B0); })) === 0;
ok(5, 'backoff 31m,2h,6h,24h,24h..; failure gate in [step, step*1.25], jitter present',
    $steps === [1860, 7200, 21600, 86400, 86400, 86400, 86400] && $jok && count(array_unique($jit)) > 1 && $fok && count(array_unique($fg)) > 1,
    'steps=' . implode(',', $steps) . ' jitter(step0) min=' . min($jit) . ' max=' . max($jit) . ' distinct=' . count(array_unique($jit)) . ' failure gates distinct=' . count(array_unique($fg)) . " range=" . min($fg) . '..' . max($fg));

// 6, 7, 8, 9: Retry-After handling (first 429 at fails 0)
$back = function ($g) use ($J, $B0) { return $J($g, $B0); };
$cases = [[6, 'Retry-After 7200', '7200', function ($g) { return $g === 7200; }],
          ['6b', 'Retry-After repeated [7200, 5]: first value', ['7200', '5'], function ($g) { return $g === 7200; }],
          [7, 'Retry-After 100000 capped', '100000', function ($g) { return $g === 86400; }],
          ['7b', 'Retry-After 99999999999999999999 capped', '99999999999999999999', function ($g) { return $g === 86400; }],
          ['7c', 'Retry-After 1000000000 (10 digits) capped', '1000000000', function ($g) { return $g === 86400; }],
          ['8a', 'Retry-After -5', '-5', $back], ['8b', 'Retry-After abc', 'abc', $back], ['8c', 'Retry-After HTTP-date', 'Wed, 21 Oct 2026 07:28:00 GMT', $back],
          ['8d', 'Retry-After empty', '', $back], ['8e', 'Retry-After missing', '__none__', $back], ['8f', 'Retry-After 1.5', '1.5', $back],
          ['9a', 'Retry-After 0', '0', $back], ['9b', 'Retry-After 60', '60', $back], ['9c', 'Retry-After " 2400 "', ' 2400 ', function ($g) use ($J, $B0) { return $g >= 2400 && $g <= max(2400, $B0 + (int) floor($B0 * 0.25)); }]];
foreach ($cases as [$id, $name, $ra, $check]) {
    qa_reset(); $GLOBALS['QA_NOW'] = $t0 = 1800000000; $w0 = count($GLOBALS['QA_WARN']);
    $GLOBALS['QA_DEFAULT'] = $ra === '__none__' ? ['code' => 429] : ['code' => 429, 'ra' => $ra];
    $r = cg_sitekey_request('scheduled'); $g = qa_gate() - $t0; $ev = qa_events();
    $GLOBALS['QA_NOW'] = $t0 + $g - 1; $before = cg_sitekey_request('manual');
    $pass = $r === 'failed' && $check($g) && $ev === 0 && $before === 'gated' && count($GLOBALS['QA_CALLS']) === 1 && count($GLOBALS['QA_WARN']) === $w0;
    ok($id, $name, $pass, "result=$r wait={$g}s events=$ev call_at_gate-1=$before calls=" . count($GLOBALS['QA_CALLS']) . ' warnings=' . (count($GLOBALS['QA_WARN']) - $w0));
}

// 10: a 429 schedules nothing by itself (1.4.2: at most one retry event; 1.5.0: none, the next try comes from maintenance or a legacy code answer, gated)
qa_reset(); $GLOBALS['QA_NOW'] = $t0 = 1800000000;
cg_sitekey_request('scheduled'); $e1 = qa_events(); $GLOBALS['QA_NOW'] = qa_gate(); $r2 = cg_sitekey_request('scheduled'); $e2 = qa_events();
ok(10, '429 -> no retry event; next try only at the gate; 429 again -> still none', $e1 === 0 && $r2 === 'failed' && $e2 === 0 && count($GLOBALS['QA_CALLS']) === 2, "events_after_first=$e1 second=$r2 events_after_second=$e2 calls=" . count($GLOBALS['QA_CALLS']));

// 11: single 429 then 202
qa_reset(); $GLOBALS['QA_NOW'] = $t0 = 1800000000; $GLOBALS['QA_PLAN'] = [['code' => 429, 'ra' => '60'], ['code' => 202]];
$na = has_action('admin_notices', 'cg_render_sitekey_notice'); cg_sitekey_request('scheduled'); $GLOBALS['QA_NOW'] = qa_gate(); $r = cg_sitekey_request('scheduled');
$st = cg_sitekey_state(); $g = qa_gate() - $GLOBALS['QA_NOW'];
ok(11, '429 then 202: accepted, round open, gate = next step, no event, notice hook unchanged', $r === 'accepted' && !empty($st['request_id']) && (int) $st['window_until'] === $GLOBALS['QA_NOW'] + CG_SITEKEY_NONCE_WINDOW && $g === $B1 && qa_events() === 0 && has_action('admin_notices', 'cg_render_sitekey_notice') === $na && count($GLOBALS['QA_CALLS']) === 2,
    "result=$r next_gate=+{$g}s fails=" . (int) ($st['fails'] ?? 0) . ' events=' . qa_events() . ' calls=' . count($GLOBALS['QA_CALLS']));

// 12: 429 then 500 then timeout, triggers every minute for 2 h
qa_reset(); $GLOBALS['QA_NOW'] = $t0 = 1800000000; $GLOBALS['QA_PLAN'] = [['code' => 429], ['code' => 500], ['code' => 'timeout'], ['code' => 503]];
cg_sitekey_request('scheduled');
for ($m = 1; $m <= 120; $m++) { $GLOBALS['QA_NOW'] = $t0 + $m * 60; qa_fire_due(); cg_sitekey_request($m % 2 ? 'manual' : 'scheduled'); }
$c = $GLOBALS['QA_CALLS']; $mx = qa_max_in_window($c);
ok(12, '429 then 5xx/timeout, trigger every minute 2 h', $mx <= 2 && count($c) <= 4, 'calls=' . count($c) . " max/60min=$mx at min " . qa_mins($c, $t0));

// 13a: object cache flush only (options stay in DB) -> no extra call
$runs = [];
foreach ([false, true] as $flush) {
    qa_reset(); $GLOBALS['QA_NOW'] = $t0 = 1800000000;
    cg_sitekey_request('scheduled');
    for ($i = 0; $i < 90; $i++) { $GLOBALS['QA_NOW'] += 60; if ($flush) wp_cache_flush(); cg_sitekey_request('manual'); }
    $runs[$flush ? 'flush' : 'no_flush'] = [count($GLOBALS['QA_CALLS']), qa_max_in_window($GLOBALS['QA_CALLS'])];
}
ok('13a', 'object cache flushed every minute: no extra call', $runs['flush'][0] <= $runs['no_flush'][0] && $runs['flush'][1] <= 2, '[calls, max/60min] ' . json_encode($runs));
// 13b: state and gate deleted from the DB mid-way -> behaves like a fresh install, no loop
// Accepted for 1.4.2 (Tanvir, 2026-11-21): options lost from the DB = fresh install; never a loop.
qa_reset(); $GLOBALS['QA_NOW'] = $t0 = 1800000000;
cg_sitekey_request('scheduled'); $GLOBALS['QA_NOW'] += 60;
global $wpdb; foreach (['cg_sitekey_state', 'cg_sitekey_next_allowed_at'] as $o) { $wpdb->delete($wpdb->options, ['option_name' => $o]); wp_cache_delete($o, 'options'); }
wp_cache_flush(); $wipe = $GLOBALS['QA_NOW']; $before = count($GLOBALS['QA_CALLS']);
for ($i = 0; $i < 180; $i++) { $GLOBALS['QA_NOW'] += 60; qa_fire_due(); cg_sitekey_request('manual'); }
$after = array_values(array_filter($GLOBALS['QA_CALLS'], function ($t) use ($wipe) { return $t >= $wipe; }));
ok('13b', 'state deleted: fresh-install budget, no loop', qa_max_in_window($after) <= 2 && count($after) <= 6, "calls before wipe=$before; after wipe in 3 h=" . count($after) . ' max/60min(after)=' . qa_max_in_window($after) . ' max/60min(incl. pre-wipe call)=' . qa_max_in_window($GLOBALS['QA_CALLS']));

// 14: WP timezone does not change timings
$g = [];
foreach (['UTC', 'Asia/Dhaka'] as $tz) { update_option('timezone_string', $tz); qa_reset(); $GLOBALS['QA_NOW'] = 1800000000; $GLOBALS['QA_DEFAULT'] = ['code' => 429, 'ra' => '20000']; cg_sitekey_request('scheduled'); $g[$tz] = qa_gate() - 1800000000; }
update_option('timezone_string', $GLOBALS['QA_TZ_ORIG'] ?? '');
ok(14, 'timezone UTC vs Asia/Dhaka', $g['UTC'] === $g['Asia/Dhaka'] && $g['UTC'] === 20000, json_encode($g));

// 15: the 1.4.2 module is gone (one implementation only)
ok(15, '1.4.2 request module not loaded', !function_exists('cg_sitekey_request_site_key') && !has_action('cg_sitekey_retry_event') && !defined('CG_SITEKEY_RETRY_HOOK'), '');

qa_reset();
cg_update_options_from_hq(is_array($GLOBALS['QA_OPTS_ORIG']) ? $GLOBALS['QA_OPTS_ORIG'] : []);
restore_error_handler();
$R = $GLOBALS['R'];
foreach ($R as $r) echo implode(' | ', $r), "\n";
echo 'warnings_total=' . count($GLOBALS['QA_WARN']) . "\n"; foreach (array_unique($GLOBALS['QA_WARN']) as $w) echo "  W: $w\n";
$f = count(array_filter($R, function ($r) { return $r[2] !== 'PASS'; })) + (count($GLOBALS['QA_WARN']) ? 1 : 0);
echo "SUMMARY " . (count($R) - $f) . '/' . count($R) . " PASS\n";
