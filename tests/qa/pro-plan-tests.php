<?php
/**
 * Plan gate tests: Pro implies paid, and a subscription that is not active is never Pro.
 *
 * Run on a local test site only (never production):
 *   wp eval-file tests/qa/pro-plan-tests.php
 * It writes and then deletes cg_api_cache, cg_last_known_plan and cg_last_synced.
 * No HQ call is made. The clock is moved with the cg_reveal_now filter.
 */
if (!defined('ABSPATH')) {
    exit;
}
$GLOBALS['R'] = ['pass' => 0, 'fail' => 0];
function r5_check($id, $cond, $detail = '') {
    $GLOBALS['R'][$cond ? 'pass' : 'fail']++;
    echo ($cond ? 'PASS ' : 'FAIL ') . $id . ($detail !== '' ? ' - ' . $detail : ''), "\n";
}
function r5_now($ts) { $GLOBALS['R5_NOW'] = $ts; }
add_filter('cg_reveal_now', function ($t) { return $GLOBALS['R5_NOW'] ?? $t; });
function r5_summary() { $R = $GLOBALS['R']; echo "== {$R['pass']} pass, {$R['fail']} fail\n"; }
$T = time(); $H = HOUR_IN_SECONDS; $GLOBALS['T'] = $T;
function pr_set($cache, $last, $legacy = null) {
    delete_transient('cg_api_cache'); delete_option('cg_last_known_plan'); delete_transient('cg_last_synced');
    wp_cache_delete('cg_last_known_plan', 'options');
    if ($cache !== null) set_transient('cg_api_cache', $cache, DAY_IN_SECONDS);
    if ($last !== null) update_option('cg_last_known_plan', $last, false);
    if ($legacy !== null) set_transient('cg_last_synced', $legacy, WEEK_IN_SECONDS);
}
$c = function ($slug, $status = 'active', $gf = null) { $a = ['plan_slug' => $slug, 'status' => $status]; if ($gf !== null) $a['grandfathered_until'] = $gf; return $a; };
$l = function ($slug, $status, $at, $gf = null) { $a = ['plan_slug' => $slug, 'status' => $status, 'synced_at' => $at]; if ($gf !== null) $a['grandfathered_until'] = $gf; return $a; };
$FUT = gmdate('Y-m-d', $T + 30 * DAY_IN_SECONDS); $PAST = gmdate('Y-m-d', $T - 2 * DAY_IN_SECONDS);
r5_now($T);
$cases = [
  'X1 pro active, fresh -> Pro' => [[$c('pro'), $l('pro', 'active', $T)], true],
  'X1b custom active, fresh -> Pro' => [[$c('custom'), $l('custom', 'active', $T)], true],
  'X2 cache kept alive by usage writes, last sync 73h -> not Pro (was Pro)' => [[$c('pro'), $l('pro', 'active', $T - 73 * $H)], false],
  'X3 cache deleted, last-known pro 24h -> Pro (was not Pro)' => [[null, $l('pro', 'active', $T - 24 * $H)], true],
  'X3b cache deleted, last-known pro exactly 72h -> Pro' => [[null, $l('pro', 'active', $T - 72 * $H)], true],
  'X4 cache deleted, last-known pro 73h -> not Pro' => [[null, $l('pro', 'active', $T - 73 * $H)], false],
  'X5 pro expired -> not Pro' => [[$c('pro', 'expired'), $l('pro', 'expired', $T)], false],
  'X5b pro status missing -> not Pro' => [[['plan_slug' => 'pro'], $l('pro', null, $T)], false],
  'X5c pro suspended -> not Pro' => [[$c('pro', 'suspended'), $l('pro', 'suspended', $T)], false],
  'X5d pro cancelled -> not Pro' => [[$c('pro', 'cancelled'), $l('pro', 'cancelled', $T)], false],
  'X5e custom cancelled -> not Pro' => [[$c('custom', 'cancelled'), $l('custom', 'cancelled', $T)], false],
  'X5f pro " Suspended " (case/space) -> not Pro' => [[$c('pro', ' Suspended '), $l('pro', 'suspended', $T)], false],
  'X5g pro " ACTIVE " (case/space) in cache -> Pro' => [[$c('pro', ' ACTIVE '), $l('pro', 'active', $T)], true],
  'X5h cache deleted, last-known pro suspended 1h -> not Pro' => [[null, $l('pro', 'suspended', $T - $H)], false],
  'X5i cache deleted, last-known pro cancelled 1h -> not Pro' => [[null, $l('pro', 'cancelled', $T - $H)], false],
  'X6 basic grandfathered (cache, future) -> Pro' => [[$c('basic', 'active', $FUT), $l('basic', 'active', $T, $FUT)], true],
  'X6b basic grandfathered but suspended -> not Pro' => [[$c('basic', 'suspended', $FUT), $l('basic', 'suspended', $T, $FUT)], false],
  'X6c basic grandfather passed -> not Pro (still paid)' => [[$c('basic', 'active', $PAST), $l('basic', 'active', $T, $PAST)], false],
  'X7 cache deleted, last-known basic gf 2099-12-31 24h -> Pro' => [[null, $l('basic', 'active', $T - 24 * $H, '2099-12-31')], true],
  'X7b cache deleted, last-known basic without gf -> not Pro' => [[null, $l('basic', 'active', $T - 24 * $H)], false],
  'X7c cache deleted, last-known basic gf 2099-12-31, 73h -> not Pro' => [[null, $l('basic', 'active', $T - 73 * $H, '2099-12-31')], false],
  'X8 free active -> not Pro' => [[$c('free'), $l('free', 'active', $T)], false],
  'X9 cache but no sync time at all -> not Pro' => [[$c('pro'), null], false],
  'X9b 1.4.x upgrade: cache + cg_last_synced 1h -> Pro' => [[$c('pro'), null, $T - $H], true],
  'X9c synced_at in the future -> not Pro' => [[$c('pro'), $l('pro', 'active', $T + $H)], false],
];
$viol = [];
foreach ($cases as $name => [$args, $want]) {
    pr_set(...$args); $pro = cg_is_pro_plan(); $paid = cg_is_paid_plan();
    if ($pro && !$paid) $viol[] = $name;
    r5_check($name, $pro === $want, 'pro=' . var_export($pro, true) . ' paid=' . var_export($paid, true));
}
// X10 sync path: verified payload stores grandfathered_until; after cache loss Pro stays (<=72h)
pr_set(null, null); r5_now($T);
cg_remember_last_known_plan(['plan_slug' => 'basic', 'status' => 'active', 'grandfathered_until' => '2099-12-31']);
wp_cache_delete('cg_last_known_plan', 'options'); $o = get_option('cg_last_known_plan');
r5_now($T + 48 * $H); $x10 = cg_is_pro_plan(); r5_now($T + 73 * $H); $x10b = cg_is_pro_plan(); r5_now($T);
r5_check('X10 cg_remember_last_known_plan keeps grandfathered_until; Pro at +48h, not at +73h', ($o['grandfathered_until'] ?? null) === '2099-12-31' && $x10 === true && $x10b === false, json_encode([$o['grandfathered_until'] ?? null, $x10, $x10b]));
// X11 invariant sweep: slugs x statuses x ages x gf x cache/no-cache, Pro => paid always
$n = 0;
foreach (['pro', 'custom', 'basic', 'standard', 'free', 'unknown', null] as $s)
 foreach (['active', 'expired', 'suspended', 'cancelled', null] as $st)
  foreach ([0, 24, 72, 73, -1] as $age)
   foreach ([null, $FUT, $PAST, '2099-12-31', 'junk'] as $gf)
    foreach ([true, false] as $withCache) {
        pr_set($withCache ? $c($s, $st, $gf) : null, $l($s, $st, $T - $age * $H, $gf)); $n++;
        if (cg_is_pro_plan() && !cg_is_paid_plan()) $viol[] = json_encode([$s, $st, $age, $gf, $withCache]);
    }
r5_check("X11 invariant Pro => paid over $n combos + all cases", $viol === [], json_encode(array_slice($viol, 0, 3)));
pr_set(null, null); delete_transient('cg_api_cache');
r5_summary();
