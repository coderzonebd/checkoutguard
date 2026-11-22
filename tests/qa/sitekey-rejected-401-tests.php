<?php
/**
 * Committed own key answered with 401: the admin sees the SiteKey reconnect
 * notice, no claim round is scheduled (no auto-reconnect, no loop), only the
 * admin's Reconnect starts one, and a 2xx or a new committed key clears it.
 *
 * Run on a local machine only (never production), plain PHP CLI, no WordPress:
 *   php tests/qa/sitekey-rejected-401-tests.php [plugin_dir]
 * WordPress functions are stubbed in memory. No HQ call, no database.
 */
if (PHP_SAPI !== 'cli') {
    exit;
}
define('ABSPATH', '/tmp/');
define('MINUTE_IN_SECONDS', 60); define('HOUR_IN_SECONDS', 3600); define('DAY_IN_SECONDS', 86400); define('WEEK_IN_SECONDS', 604800);
define('CG_HQ_PUBLIC_KEY', '');
$GLOBALS['__opt'] = []; $GLOBALS['__tr'] = []; $GLOBALS['__now'] = 2000000000; $GLOBALS['__admin'] = true; $GLOBALS['__screen'] = 'dashboard';
function get_option($k, $d = false) {
    if ($k === 'date_format') return 'Y-m-d';
    if ($k === 'time_format') return 'H:i';
    return array_key_exists($k, $GLOBALS['__opt']) ? $GLOBALS['__opt'][$k] : $d;
}
function update_option($k, $v, $a = null) { $GLOBALS['__opt'][$k] = $v; return true; }
function delete_option($k) { unset($GLOBALS['__opt'][$k]); return true; }
function get_transient($k) { return $GLOBALS['__tr'][$k] ?? false; }
function set_transient($k, $v, $t = 0) { $GLOBALS['__tr'][$k] = $v; return true; }
function delete_transient($k) { unset($GLOBALS['__tr'][$k]); return true; }
function add_action(...$a) {} function add_filter(...$a) {}
function __($s, $d = null) { return $s; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_html__($s, $d = null) { return esc_html($s); }
function esc_url($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function wp_date($f, $ts) { return gmdate($f, $ts); }
function cg_reveal_now() { return $GLOBALS['__now']; }
function apply_filters($h, $v) { return $h === 'cg_sitekey_now' ? $GLOBALS['__now'] : $v; }
function wp_cache_delete(...$a) {}
function maybe_unserialize($v) { if (!is_string($v)) return $v; $u = @unserialize($v); return ($u !== false || $v === 'b:0;') ? $u : $v; }
function current_user_can($c) { return $GLOBALS['__admin']; }
function sanitize_key($k) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $k)); }
function wp_unslash($v) { return $v; }
function get_current_screen() { return $GLOBALS['__screen'] === null ? null : (object) ['id' => $GLOBALS['__screen']]; }
function admin_url($p = '') { return 'http://example.test/wp-admin/' . $p; }
function wp_nonce_url($u, $a) { return $u . '&_wpnonce=abc'; }
$GLOBALS['__sched'] = 0;
function wp_next_scheduled($h) { return false; }
function wp_schedule_single_event(...$a) { $GLOBALS['__sched']++; return true; }
class StubWpdb { public $options = 'wp_options';
  function prepare($q, ...$a) { return $a[0]; }
  // As WordPress stores it: arrays serialized, scalars as plain strings.
  function get_var($name) { if (!array_key_exists($name, $GLOBALS['__opt'])) return null; $v = $GLOBALS['__opt'][$name]; return (is_array($v) || is_object($v)) ? serialize($v) : (string) $v; } }
$GLOBALS['wpdb'] = new StubWpdb();

$dir = $argv[1] ?? dirname(__DIR__, 2);
require $dir . '/includes/utils.php';
require $dir . '/includes/site-key.php';
$pass = 0; $fail = 0;
function ok($name, $cond) { global $pass, $fail; if ($cond) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name\n"; } }

// Placeholder keys for the stubbed options only; never a real key.
const SHARED = 'cgtest_shared_placeholder';
const OWN = 'czsite_test_placeholder_a';
const OWN2 = 'czsite_test_placeholder_b';
const N = 2000000000;
function reset_all($key) {
    $GLOBALS['__opt'] = ['cg_plugin_options' => ['api_key' => $key]]; $GLOBALS['__tr'] = [];
    $GLOBALS['__now'] = N; $GLOBALS['__admin'] = true; $GLOBALS['__screen'] = 'dashboard'; $GLOBALS['__sched'] = 0; unset($_GET['page']);
    if (strpos($key, 'czsite_') === 0) { cg_sitekey_save_state(['committed_at' => N - 86400]); }
}
function notice() { ob_start(); cg_render_sitekey_notice(); return ob_get_clean(); }
function text($html) { return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES))); }
function st() { return cg_sitekey_state(); }
function rej() { return !empty(cg_sitekey_state()["rejected_at"]); }

$T2 = 'CheckoutGuard is not protecting checkouts right now. A security update needs to finish on this site. Orders still go through, but without the fraud check.';
$A1 = 'Click "Reconnect now". If it keeps failing, contact CheckoutGuard support.';

// R1 committed own key, plain 401: one notice with T2, the button and A1, no key.
reset_all(OWN); ok('R0 committed own key, no refusal -> no notice', notice() === '');
cg_sitekey_on_response(401, '', OWN); $html = notice(); $t = text($html);
ok('R1 plain 401 on the committed own key -> T2 notice with Reconnect and A1', strpos($t, $T2) === 0
    && strpos($t, 'Reconnect now ' . $A1) !== false && substr_count($html, 'class="notice') === 1 && strpos($html, 'cg_sitekey_reconnect') !== false);
ok('R1b the word key appears nowhere in the text', preg_match('/key/i', $t) === 0);
ok('R1c rejected_at set once', (st()['rejected_at'] ?? null) === N && rej());
// R2 no auto-reconnect: nothing scheduled, no gate moved, no legacy code (plan keeps the 72-hour rule).
ok('R2 no claim round scheduled, gate untouched, no legacy_blocked_at/legacy_code', $GLOBALS['__sched'] === 0
    && cg_sitekey_gate() === 0 && empty(st()['legacy_blocked_at']) && empty(st()['legacy_code']) && cg_plan_key_failure(N - 10) === '');
// R3 a repeat 401 later keeps the first time and still schedules nothing (no loop).
$GLOBALS['__now'] = N + 600; cg_sitekey_on_response(401, '', OWN); cg_sitekey_on_response(401, '', OWN);
ok('R3 repeat 401 keeps the first rejected_at, nothing scheduled', (st()['rejected_at'] ?? null) === N && $GLOBALS['__sched'] === 0);
// R4 an unknown code on a 401 counts as none (contract §5).
reset_all(OWN); cg_sitekey_on_response(401, 'some_future_code', OWN);
ok('R4 401 with an unknown code -> notice too', rej() && strpos(text(notice()), $T2) === 0);
// R5 the daily maintenance does not ask for a key on its own.
ok('R5 maintenance: cg_sitekey_needed() false, auto request not_needed, nothing scheduled', !cg_sitekey_needed()
    && cg_sitekey_request('scheduled') === 'not_needed' && cg_sitekey_request('deadline') === 'not_needed' && $GLOBALS['__sched'] === 0);
// R6 the admin's Reconnect is wanted (seen as gated here, so no HQ call in this test).
cg_sitekey_set_gate(N + 3600);
ok('R6 manual Reconnect is wanted while rejected (gated -> no call)', cg_sitekey_request('manual') === 'gated');
ok('R6b gated: no button, support line kept', strpos(notice(), 'button') === false && strpos(text(notice()), 'contact CheckoutGuard support.') !== false);
// R7 HQ accepts the key again (2xx for the current key): notice gone.
reset_all(OWN); cg_sitekey_on_response(401, '', OWN); cg_sitekey_on_response(200, '', OWN);
ok('R7 2xx for the current own key clears it -> no notice', !rej() && notice() === '' && !empty(st()['committed_at']));
// R8 a new own key is committed after a Reconnect round: notice gone.
reset_all(OWN); cg_sitekey_on_response(401, '', OWN);
$s = st(); $s['pending_until'] = N + 3600; $s['legacy_key'] = OWN; cg_sitekey_save_state($s); update_option('cg_plugin_options', ['api_key' => OWN2]);
ok('R8a while the new key is pending: no notice', notice() === '');
cg_sitekey_on_response(200, '', OWN2);
ok('R8 new key committed -> rejected_at cleared, no notice', !rej() && notice() === '' && empty(st()['pending_until']));
// R9 the pending key is refused: rollback to the old key, the notice is back (no loop).
reset_all(OWN); cg_sitekey_on_response(401, '', OWN);
$s = st(); $s['pending_until'] = N + 3600; $s['legacy_key'] = OWN; cg_sitekey_save_state($s); update_option('cg_plugin_options', ['api_key' => OWN2]);
cg_sitekey_on_response(401, '', OWN2);
ok('R9 pending key 401 -> rollback to the old key, notice back, nothing scheduled', cg_sitekey_current_key() === OWN
    && rej() && strpos(text(notice()), $T2) === 0 && $GLOBALS['__sched'] === 0);
// R10 a 401 for the shared key stays as in 1.4.x: no state, no notice.
reset_all(SHARED); cg_sitekey_on_response(401, '', SHARED);
ok('R10 shared key 401 -> no state write, no notice', !isset($GLOBALS['__opt']['cg_sitekey_state']) && notice() === '');
// R11 a late 401 for an older own key says nothing about the current key.
reset_all(OWN); cg_sitekey_on_response(401, '', OWN2);
ok('R11 401 for an older own key -> no notice', !rej() && notice() === '');
// R12 other answers for the committed key raise nothing.
reset_all(OWN); foreach ([0, 403, 429, 500, 503] as $h) { cg_sitekey_on_response($h, '', OWN); }
ok('R12 transport error, plain 403, 429, 5xx -> no notice', !rej() && notice() === '');
// R13 only admins, only the dashboard or a CheckoutGuard page.
reset_all(OWN); cg_sitekey_on_response(401, '', OWN);
$GLOBALS['__admin'] = false; ok('R13 not shown to a non-admin', notice() === '');
$GLOBALS['__admin'] = true; $GLOBALS['__screen'] = 'edit-post'; ok('R13b not shown on another screen', notice() === '');
$_GET['page'] = 'cg-settings'; ok('R13c shown on a CheckoutGuard page', substr_count(notice(), 'class="notice') === 1);

echo "== $pass pass, $fail fail\n";
exit($fail === 0 ? 0 : 1);
