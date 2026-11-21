<?php
/**
 * grandfathered_until tests: the full RFC 3339 form HQ sends (with and
 * without a fraction), null, lifetime, bad strings, the exact boundary, and
 * a value that cannot be parsed keeping the last verified one.
 *
 * Run on a local test site only (never production):
 *   wp eval-file tests/qa/grandfather-tests.php
 * It writes and then deletes cg_api_cache, cg_last_known_plan and cg_last_synced.
 * No HQ call is made. The clock is moved with the cg_reveal_now filter.
 */
if (!defined('ABSPATH')) {
    exit;
}
$GLOBALS['R'] = ['pass' => 0, 'fail' => 0];
function gf_check($id, $cond, $detail = '') {
    $GLOBALS['R'][$cond ? 'pass' : 'fail']++;
    echo ($cond ? 'PASS ' : 'FAIL ') . $id . ($detail !== '' ? ' - ' . $detail : ''), "\n";
}
function gf_now($ts) { $GLOBALS['GF_NOW'] = $ts; }
add_filter('cg_reveal_now', function ($t) { return $GLOBALS['GF_NOW'] ?? $t; });
function gf_reset() {
    delete_transient('cg_api_cache'); delete_option('cg_last_known_plan'); delete_transient('cg_last_synced');
    wp_cache_delete('cg_last_known_plan', 'options');
}
// A verified sync just now: the cache and the last-known plan, as cg_sync_with_hq() writes them.
function gf_sync($config) {
    set_transient('cg_api_cache', $config, DAY_IN_SECONDS);
    cg_remember_last_known_plan($config);
}
function gf_state() { return [cg_is_paid_plan(), cg_is_pro_plan()]; }
$p = 'cg_parse_grandfathered_until';
$U = gmmktime(10, 23, 45, 12, 15, 2026);
$basic = function ($gf) { return ['plan_slug' => 'basic', 'status' => 'active', 'grandfathered_until' => $gf]; };

// Parsing.
gf_check('G1 fraction (6 digits) -> next whole second', $p('2026-12-15T10:23:45.123456Z') === $U + 1, var_export($p('2026-12-15T10:23:45.123456Z'), true));
gf_check('G2 no fraction -> that second', $p('2026-12-15T10:23:45Z') === $U);
gf_check('G3 fraction 1 and 9 digits, lower-case t/z', $p('2026-12-15T10:23:45.5Z') === $U + 1 && $p('2026-12-15t10:23:45.000000001z') === $U + 1);
gf_check('G4 zero fraction is the same second', $p('2026-12-15T10:23:45.000Z') === $U && $p('2026-12-15T10:23:45.000000000Z') === $U);
gf_check('G5 offset form is read in UTC', $p('2026-12-15T16:23:45+06:00') === $U && $p('2026-12-15T05:23:45.250-05:00') === $U + 1);
gf_check('G6 lifetime 2099-12-31T00:00:00Z', $p('2099-12-31T00:00:00Z') === 4102358400);
gf_check('G7 null and missing -> null', $p(null) === null && $p('') === null);
$bad = ['junk', '2026-12-15T10:23:45', '2026-12-15T10:23:45.Z', '2026-12-15T10:23:45.1234567890Z',
    '2026-02-30T10:23:45Z', '2026-12-15T24:00:00Z', '2026-12-15T10:60:00Z', '2026-12-15T10:23:60Z',
    '2026-12-15T10:23:45+24:00', '2026-12-15 10:23:45Z', ' 2026-12-15T10:23:45Z', "2026-12-15T10:23:45Z\n",
    '2026-12-15T10:23:45+0600', '1797330225', 1797330225, true, ['2026-12-15T10:23:45Z']];
$ok = true; foreach ($bad as $b) { if ($p($b) !== null) { $ok = false; echo '  accepted: ', json_encode($b), "\n"; } }
gf_check('G8 bad strings and types -> null', $ok);

// Boundary: the window ends when now >= until.
gf_reset();
foreach (['2026-12-15T10:23:45Z' => [$U - 1, $U], '2026-12-15T10:23:45.123456Z' => [$U, $U + 1]] as $gf => [$in, $out]) {
    gf_reset(); gf_now($in); gf_sync($basic($gf)); $a = gf_state(); gf_now($out); $b = gf_state(); gf_now($out + 1); $c = gf_state();
    gf_check("G9 boundary $gf: Pro 1 s before, not at/after until, paid throughout", $a === [true, true] && $b === [true, false] && $c === [true, false], json_encode([$a, $b, $c]));
}
gf_reset(); gf_now($U - 1); gf_sync($basic('2026-12-15T10:23:45Z'));
gf_check('G10 cg_is_grandfathered direct: until-1 true, until false', (gf_now($U - 1) || true) && cg_is_grandfathered($basic('2026-12-15T10:23:45Z')) && (gf_now($U) || true) && !cg_is_grandfathered($basic('2026-12-15T10:23:45Z')));

// End to end through the cache and the last-known plan.
gf_reset(); gf_now($U - 3600); gf_sync($basic('2099-12-31T00:00:00Z'));
gf_check('G11 lifetime 2099 -> Pro and paid', gf_state() === [true, true]);
gf_reset(); gf_sync($basic(null));
gf_check('G12 null -> paid, not Pro', gf_state() === [true, false]);
gf_reset(); gf_sync(['plan_slug' => 'pro', 'status' => 'active', 'grandfathered_until' => null]);
gf_check('G13 pro + null -> Pro', gf_state() === [true, true]);

// A value that cannot be parsed keeps the last verified one and is logged.
$log = tempnam(sys_get_temp_dir(), 'gf'); $old_log = ini_set('error_log', $log);
gf_reset(); gf_sync($basic('2026-12-15T10:23:45.123456Z')); gf_sync($basic('garbage'));
$lk = get_option('cg_last_known_plan'); $logged = (string) file_get_contents($log);
gf_check('G14 garbage after a valid value: still Pro, last-known keeps the valid value', gf_state() === [true, true] && ($lk['grandfathered_until'] ?? null) === '2026-12-15T10:23:45.123456Z', json_encode([gf_state(), $lk['grandfathered_until'] ?? null]));
gf_check('G15 the bad value is logged once', substr_count($logged, 'grandfathered_until from HQ could not be parsed') === 1 && strpos($logged, 'keeping the last verified value') !== false);
gf_now($U + 1);
gf_check('G16 kept value still ends on its own date', gf_state() === [true, false]);
gf_now($U - 3600);
gf_reset(); gf_sync($basic('garbage'));
gf_check('G17 garbage with no earlier value: paid, not Pro (never Free)', gf_state() === [true, false] && ($lk2 = get_option('cg_last_known_plan')) && array_key_exists('grandfathered_until', $lk2) && $lk2['grandfathered_until'] === null, json_encode(gf_state()));
gf_reset(); gf_sync($basic('2026-12-15T10:23:45Z')); gf_sync($basic(null));
gf_check('G18 null after a valid value ends the grandfather (null is a real answer)', gf_state() === [true, false]);
gf_reset(); gf_sync($basic('2026-12-15T10:23:45Z')); gf_sync(['plan_slug' => 'free', 'status' => 'active', 'grandfathered_until' => 'garbage']);
gf_check('G19 garbage never makes a Free plan Pro', gf_state() === [false, false]);
ini_set('error_log', $old_log === false ? '' : $old_log); @unlink($log);

gf_reset(); delete_transient('cg_api_cache');
echo "== {$GLOBALS['R']['pass']} pass, {$GLOBALS['R']['fail']} fail\n";
