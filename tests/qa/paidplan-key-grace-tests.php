<?php
/**
 * Paid plan while HQ refuses the site key (SiteKey contract §7).
 *
 * Run on a local machine only (never production), plain PHP CLI, no WordPress:
 *   php tests/qa/paidplan-key-grace-tests.php [plugin_dir]
 * WordPress functions are stubbed in memory. No HQ call, no database.
 */
if (PHP_SAPI !== 'cli') {
    exit;
}
define('ABSPATH', '/tmp/');
define('MINUTE_IN_SECONDS', 60); define('HOUR_IN_SECONDS', 3600); define('DAY_IN_SECONDS', 86400); define('WEEK_IN_SECONDS', 604800);
define('CG_HQ_PUBLIC_KEY', '');
$GLOBALS['__N'] = 2000000000;
$GLOBALS['__opt'] = []; $GLOBALS['__tr'] = []; $GLOBALS['__now'] = $GLOBALS['__N'];
function get_option($k, $d = false) { return array_key_exists($k, $GLOBALS['__opt']) ? $GLOBALS['__opt'][$k] : $d; }
function update_option($k, $v, $a = null) { $GLOBALS['__opt'][$k] = $v; return true; }
function delete_option($k) { unset($GLOBALS['__opt'][$k]); return true; }
function get_transient($k) { return $GLOBALS['__tr'][$k] ?? false; }
function set_transient($k, $v, $t = 0) { $GLOBALS['__tr'][$k] = $v; return true; }
function delete_transient($k) { unset($GLOBALS['__tr'][$k]); return true; }
function add_action(...$a) {} function add_filter(...$a) {}
function __($s, $d = null) { return $s; } function esc_html($s) { return $s; } function esc_html__($s, $d = null) { return $s; }
function wp_date($f, $ts) { return gmdate('Y-m-d', $ts); }
function cg_reveal_now() { return $GLOBALS['__now']; }
function apply_filters($h, $v) { return $h === 'cg_sitekey_now' ? $GLOBALS['__now'] : $v; }
function wp_cache_delete(...$a) {}
function maybe_unserialize($v) { return is_string($v) ? unserialize($v) : $v; }
class StubWpdb { public $options = 'wp_options';
  function prepare($q, ...$a) { return $a[0]; }
  function get_var($name) { return array_key_exists($name, $GLOBALS['__opt']) ? serialize($GLOBALS['__opt'][$name]) : null; } }
$GLOBALS['wpdb'] = new StubWpdb();

$dir = $argv[1] ?? dirname(__DIR__, 2);
require $dir . '/includes/utils.php';
require $dir . '/includes/site-key.php';
$pass = 0; $fail = 0;
$CTL = !function_exists('cg_parse_legacy_key_deadline');
if ($CTL) { function cg_parse_legacy_key_deadline(...$a) { return 'missing'; } function cg_sitekey_plan_note() { return 'missing'; } }
function ok($name, $cond) { global $pass, $fail; if ($cond) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name\n"; } }
function reset_all() { $GLOBALS['__opt'] = []; $GLOBALS['__tr'] = []; $GLOBALS['__now'] = $GLOBALS['__N']; }
function sync($cfg) { cg_remember_last_known_plan($cfg); set_transient('cg_api_cache', $cfg); }
function refuse($code, $at = null) { $s = get_option('cg_sitekey_state', []); $s['legacy_blocked_at'] = $at ?? $GLOBALS['__now']; $s['legacy_code'] = $code; update_option('cg_sitekey_state', $s); }
function age($sec) { $GLOBALS['__now'] += $sec; }
function drop_cache() { delete_transient('cg_api_cache'); }
function src() { return cg_effective_plan()['source']; }
$D = 86400; $H = 3600;
// Base time 200 days before the hard end, so the signed deadline and the +60 day bound
// are the earlier bounds in every case except H1-H3.
$HE = defined('CG_LEGACY_HARD_END') ? CG_LEGACY_HARD_END : null;
if ($HE !== null) { $GLOBALS['__N'] = $HE - 200 * $D; }
$N = $GLOBALS['__N']; reset_all();
$iso = function ($ts) { return gmdate('Y-m-d\TH:i:s\Z', $ts); };
$paid = function ($dl = null) { $c = ['status' => 'active', 'plan' => 'pro', 'plan_slug' => 'pro']; if ($dl !== null) $c['legacy_key_deadline'] = $dl; return $c; };

// P1 parse
ok('P1a valid Z', cg_parse_legacy_key_deadline($iso($N + 2 * $D), $N) === $N + 2 * $D);
ok('P1b date-only rejected', cg_parse_legacy_key_deadline('2033-05-20', $N) === null);
ok('P1c offset rejected', cg_parse_legacy_key_deadline('2033-05-20T00:00:00+06:00', $N) === null);
ok('P1d null/int/empty rejected', cg_parse_legacy_key_deadline(null, $N) === null && cg_parse_legacy_key_deadline(1, $N) === null && cg_parse_legacy_key_deadline('', $N) === null);
ok('P1e bad date rejected', cg_parse_legacy_key_deadline('2033-02-30T00:00:00Z', $N) === null);
ok('P1f trailing newline rejected', cg_parse_legacy_key_deadline("2033-05-20T00:00:00Z\n", $N) === null);
ok('P2 clamp to synced+60d', cg_parse_legacy_key_deadline('2099-12-31T00:00:00Z', $N) === $N + 60 * $D);
ok('P2b exactly +60d kept', cg_parse_legacy_key_deadline($iso($N + 60 * $D), $N) === $N + 60 * $D);
ok('P2c past kept', cg_parse_legacy_key_deadline($iso($N - $D), $N) === $N - $D);

// B1 key refusal, valid deadline +10d: paid past 72h until deadline, Free after
reset_all(); sync($paid($iso($N + 10 * $D))); age(60); refuse('site_key_required'); drop_cache();
age(4 * $D); ok('B1a key_grace at 4d', src() === 'key_grace' && cg_is_paid_plan());
$GLOBALS['__now'] = $N + 10 * $D - 1; ok('B1b paid 1s before deadline', cg_is_paid_plan());
$GLOBALS['__now'] = $N + 10 * $D; ok('B1c free at deadline', !cg_is_paid_plan());
// B2 each key code
foreach (['claim_required', 'site_key_mismatch'] as $c) { reset_all(); sync($paid($iso($N + 10 * $D))); refuse($c); drop_cache(); age(5 * $D); ok("B2 $c grace", src() === 'key_grace'); }
// B3 legacy_expired: free immediately, even inside 72h with cache
reset_all(); sync($paid($iso($N + 10 * $D))); age(60); refuse('legacy_expired'); ok('B3 legacy_expired -> free now', !cg_is_paid_plan() && src() === 'none');
// B4 5xx/network (no refusal recorded): 72h rule
reset_all(); sync($paid($iso($N + 10 * $D))); drop_cache(); age(71 * $H); $a = cg_is_paid_plan(); age(2 * $H); ok('B4 no refusal: 72h', $a && !cg_is_paid_plan());
// B5 key refusal, no deadline: 72h
reset_all(); sync($paid()); refuse('site_key_required'); drop_cache(); age(71 * $H); $a = cg_is_paid_plan(); age(2 * $H); ok('B5 missing deadline: 72h', $a && !cg_is_paid_plan());
// B6 key refusal, invalid deadline: 72h
reset_all(); sync($paid('2033-05-20')); refuse('site_key_required'); drop_cache(); age(73 * $H); ok('B6 invalid deadline: 72h', !cg_is_paid_plan());
// B7 refusal older than last good sync is ignored
reset_all(); refuse('legacy_expired', $N - 10); sync($paid()); ok('B7 old legacy_expired ignored', cg_is_paid_plan());
reset_all(); refuse('site_key_required', $N - 10); sync($paid($iso($N + 10 * $D))); drop_cache(); age(4 * $D); ok('B7b old key refusal ignored', !cg_is_paid_plan());
// B8 deadline only from signed sync; huge deadline clamped
reset_all(); sync($paid('2099-12-31T00:00:00Z')); refuse('site_key_required'); drop_cache(); $GLOBALS['__now'] = $N + 60 * $D - 1; $a = cg_is_paid_plan(); $GLOBALS['__now'] = $N + 60 * $D; ok('B8 clamp R+60 enforced', $a && !cg_is_paid_plan());
// B9 free plan stays free under grace
reset_all(); sync(['status' => 'active', 'plan_slug' => 'free', 'legacy_key_deadline' => $iso($N + 10 * $D)]); refuse('site_key_required'); drop_cache(); age(4 * $D); ok('B9 free stays free', !cg_is_paid_plan());
// H hard end (CG_LEGACY_HARD_END): third bound of the effective deadline
$HE = $HE ?? 0;
ok('H0 hard end is an int timestamp', is_int($HE) && $HE > $N);
ok('H1a later signed deadline clamped to hard end', $HE > 0 && cg_parse_legacy_key_deadline($iso($HE + 10 * $D), $HE - 30 * $D) === $HE);
ok('H1b deadline exactly at hard end kept', $HE > 0 && cg_parse_legacy_key_deadline($iso($HE), $HE - 30 * $D) === $HE);
ok('H1c deadline 1s before hard end kept', $HE > 0 && cg_parse_legacy_key_deadline($iso($HE - 1), $HE - 30 * $D) === $HE - 1);
ok('H2 synced+60d past hard end still clamped', $HE > 0 && cg_parse_legacy_key_deadline('2099-12-31T00:00:00Z', $HE - $D) === $HE);
if ($HE > 0) { $GLOBALS['__N'] = $HE - 30 * $D; }
reset_all(); sync($paid($iso($HE + 10 * $D))); refuse('site_key_required'); drop_cache();
$GLOBALS['__now'] = $HE - 1; $a = cg_is_paid_plan(); $GLOBALS['__now'] = $HE;
ok('H3 key grace ends at hard end', $HE > 0 && $a && !cg_is_paid_plan());
$GLOBALS['__N'] = $N;
// B10 notice sentence
reset_all(); sync($paid($iso($N + 10 * $D))); refuse('site_key_required'); ok('N1 note with date', strpos(cg_sitekey_plan_note(), gmdate('Y-m-d', $N + 10 * $D)) !== false);
reset_all(); sync($paid()); refuse('site_key_required'); ok('N2 note 72h', strpos(cg_sitekey_plan_note(), '72 hours') !== false);
reset_all(); sync($paid()); refuse('legacy_expired'); ok('N3 note off', strpos(cg_sitekey_plan_note(), 'are off') !== false);
reset_all(); sync($paid()); ok('N4 no refusal no note', cg_sitekey_plan_note() === '');
echo "RESULT $pass/" . ($pass + $fail) . "\n";
