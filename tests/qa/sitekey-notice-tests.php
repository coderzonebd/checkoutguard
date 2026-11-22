<?php
/**
 * SiteKey admin notice (§B of the notice draft): one notice, the same title
 * and text for every refusal code, the support line next to the button, never
 * the word "key", gone with no refusal and after a new key is committed.
 *
 * Run on a local machine only (never production), plain PHP CLI, no WordPress:
 *   php tests/qa/sitekey-notice-tests.php [plugin_dir]
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
function wp_next_scheduled($h) { return false; }
function wp_schedule_single_event(...$a) { return true; }
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
function reset_all($key) {
    $GLOBALS['__opt'] = ['cg_plugin_options' => ['api_key' => $key]]; $GLOBALS['__tr'] = [];
    $GLOBALS['__now'] = 2000000000; $GLOBALS['__admin'] = true; $GLOBALS['__screen'] = 'dashboard'; unset($_GET['page']);
}
function notice() { ob_start(); cg_render_sitekey_notice(); return ob_get_clean(); }
function text($html) { return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES))); }

$T2_TITLE = 'CheckoutGuard is not protecting checkouts right now.';
$T2_BODY = 'A security update needs to finish on this site. Orders still go through, but without the fraud check.';
$A1 = 'Click "Reconnect now". If it keeps failing, contact CheckoutGuard support.';
$T1_L1 = ['keep working', 'Fraud checks', 'will stop until'];

// The four refusal codes and the key each answer was for.
$codes = [
    'claim_required' => [409, SHARED],
    'legacy_expired' => [403, SHARED],
    'site_key_required' => [403, SHARED],
    'site_key_mismatch' => [403, OWN],
];
foreach ($codes as $code => [$http, $key]) {
    reset_all($key);
    cg_sitekey_on_response($http, $code, $key);
    $html = notice(); $t = text($html);
    ok("S1 $code: title is T2", strpos($html, '<strong>' . esc_html($T2_TITLE) . '</strong>') !== false);
    ok("S2 $code: T2 text and A1 next to the button", strpos($t, $T2_TITLE . ' ' . $T2_BODY) === 0
        && strpos($t, 'Reconnect now ' . $A1) !== false && strpos($html, '_wpnonce=') !== false);
    ok("S3 $code: exactly one notice", substr_count($html, 'class="notice') === 1 && substr_count($html, $T2_TITLE) === 1);
    ok("S4 $code: the word key appears nowhere in the text", preg_match('/key/i', $t) === 0);
    $t1 = false; foreach ($T1_L1 as $s) { $t1 = $t1 || stripos($t, $s) !== false; }
    ok("S5 $code: no T1/L1 text", !$t1);

    // A new own key is committed (HQ accepts the pending key): the notice goes.
    $st = cg_sitekey_state(); $st['pending_until'] = $GLOBALS['__now'] + 3600; cg_sitekey_save_state($st);
    update_option('cg_plugin_options', ['api_key' => OWN2]);
    cg_sitekey_on_response(200, '', OWN2);
    $st = cg_sitekey_state();
    ok("S6 $code: committed new key -> no notice", notice() === '' && empty($st['legacy_blocked_at']) && !empty($st['committed_at']));
}

// No refusal: no notice, with the shared key or with an own key.
reset_all(SHARED); ok('S7 no refusal, shared key -> no notice', notice() === '');
reset_all(OWN); ok('S7b no refusal, own key -> no notice', notice() === '');
reset_all(SHARED); cg_sitekey_on_response(403, 'some_other_code', SHARED); cg_sitekey_on_response(500, '', SHARED);
ok('S7c unknown code or 5xx -> no notice', notice() === '');

// Shown only to admins and only on the dashboard or a CheckoutGuard page.
reset_all(SHARED); cg_sitekey_on_response(403, 'site_key_required', SHARED);
$GLOBALS['__admin'] = false; ok('S8 not shown to a non-admin', notice() === '');
$GLOBALS['__admin'] = true; $GLOBALS['__screen'] = 'edit-post'; ok('S8b not shown on another screen', notice() === '');
$_GET['page'] = 'cg-settings'; ok('S8c shown on a CheckoutGuard page', substr_count(notice(), 'class="notice') === 1); unset($_GET['page']);

// While the next automatic attempt is gated there is no button, so no A1 and
// no "Reconnect" at all, but the support line stays; still one notice with the
// T2 title and no key.
reset_all(SHARED); cg_sitekey_on_response(403, 'legacy_expired', SHARED); $GLOBALS['__screen'] = 'dashboard';
cg_sitekey_set_gate($GLOBALS['__now'] + 3600); $html = notice(); $t = text($html);
ok('S9 gated: T2, next attempt time, no button and no A1, no key', strpos($t, $T2_TITLE . ' ' . $T2_BODY) === 0
    && strpos($t, 'Next automatic attempt:') !== false && strpos($html, 'button') === false
    && strpos($t, $A1) === false && preg_match('/key/i', $t) === 0 && substr_count($html, 'class="notice') === 1);
ok('S9c gated: no "Reconnect" anywhere, the support line is shown', strpos($t, 'Reconnect') === false
    && preg_match('/Next automatic attempt: [^.]+\. If it keeps failing, contact CheckoutGuard support\.$/', $t) === 1);

// One second before the gate opens it is still gated: no button, no A1.
cg_sitekey_set_gate($GLOBALS['__now'] + 1); $html = notice(); $t = text($html);
ok('S9d gate == now+1: still gated, no button and no A1', strpos($html, 'button') === false
    && strpos($t, $A1) === false && strpos($t, 'Next automatic attempt:') !== false);

// The gate is the first second an attempt is allowed: at gate == now the
// button and A1 are back.
cg_sitekey_set_gate($GLOBALS['__now']); $html = notice(); $t = text($html);
ok('S9b gate == now: button and A1 shown, no next attempt line', strpos($html, 'class="button') !== false
    && strpos($t, 'Reconnect now ' . $A1) !== false && strpos($t, 'Next automatic attempt:') === false);

// Mismatch is never retried on its own (only Reconnect), so while gated the
// notice says when Reconnect is allowed again, never "Next automatic attempt".
reset_all(OWN); cg_sitekey_on_response(403, 'site_key_mismatch', OWN); $GLOBALS['__screen'] = 'dashboard';
cg_sitekey_set_gate($GLOBALS['__now'] + 3600); $html = notice(); $t = text($html);
ok('S9e mismatch gated: "You can try again after", no "Next automatic attempt", no button', $t !== ''
    && preg_match('/You can try again after [^.]+\. If it keeps failing, contact CheckoutGuard support\.$/', $t) === 1
    && strpos($t, 'Next automatic attempt') === false && strpos($html, 'button') === false);
// A legacy code is retried automatically, so it keeps the automatic line.
reset_all(SHARED); cg_sitekey_on_response(403, 'legacy_expired', SHARED);
cg_sitekey_set_gate($GLOBALS['__now'] + 3600); $t = text(notice());
ok('S9f legacy gated keeps "Next automatic attempt", no "try again after"', strpos($t, 'Next automatic attempt:') !== false
    && strpos($t, 'You can try again after') === false);

// Paid plan sentence inside the notice: a signed deadline 90 days out is shown
// as the +60 day bound (date only, no key).
$D = 86400; $N = $GLOBALS['__now'] = 2000000000;
reset_all(OWN); $GLOBALS['__now'] = $N;
$cfg = ['status' => 'active', 'plan' => 'pro', 'plan_slug' => 'pro', 'legacy_key_deadline' => gmdate('Y-m-d\TH:i:s\Z', $N + 90 * $D)];
cg_remember_last_known_plan($cfg); set_transient('cg_api_cache', $cfg);
$GLOBALS['__now'] = $N + 1; cg_sitekey_on_response(403, 'site_key_mismatch', OWN); $t = text(notice());
ok('S10 deadline +90d is shown as +60d', strpos($t, $T2_TITLE . ' ' . $T2_BODY . ' Your paid plan stays active until ' . gmdate('Y-m-d', $N + 60 * $D) . '. ') === 0
    && strpos($t, gmdate('Y-m-d', $N + 90 * $D)) === false && preg_match('/key/i', $t) === 0);

// The other two plan sentences inside the notice, full string, no key.
$GLOBALS['__now'] = $N + 60 * $D; $t = text(notice());
ok('S11 after the +60d bound: paused sentence in the notice, no key', strpos($t, $T2_TITLE . ' ' . $T2_BODY . ' Your paid plan is paused on this site until it is reconnected. ') === 0
    && preg_match('/key/i', $t) === 0);
reset_all(SHARED); $GLOBALS['__now'] = $N;
$cfg = ['status' => 'active', 'plan' => 'pro', 'plan_slug' => 'pro'];
cg_remember_last_known_plan($cfg); set_transient('cg_api_cache', $cfg);
$GLOBALS['__now'] = $N + 1; cg_sitekey_on_response(403, 'site_key_required', SHARED); $t = text(notice());
ok('S12 no deadline: 72-hour sentence in the notice, no key', strpos($t, $T2_TITLE . ' ' . $T2_BODY . ' Your paid plan stays active for up to 72 hours after the last successful connection. ') === 0
    && preg_match('/key/i', $t) === 0);

// HQ deadline passed: HQ answers 403 legacy_expired; the notice shows the
// paused sentence at once, inside 72h with the cache present.
reset_all(SHARED); $GLOBALS['__now'] = $N;
$cfg = ['status' => 'active', 'plan' => 'pro', 'plan_slug' => 'pro', 'legacy_key_deadline' => gmdate('Y-m-d\TH:i:s\Z', $N + 3600)];
cg_remember_last_known_plan($cfg); set_transient('cg_api_cache', $cfg);
$GLOBALS['__now'] = $N + 7200; cg_sitekey_on_response(403, 'legacy_expired', SHARED); $t = text(notice());
ok('S13 HQ deadline passed, 403 legacy_expired: T2 and paused sentence, no key', strpos($t, $T2_TITLE . ' ' . $T2_BODY . ' Your paid plan is paused on this site until it is reconnected. ') === 0
    && preg_match('/key/i', $t) === 0);

echo "== $pass pass, $fail fail\n";
exit($fail === 0 ? 0 : 1);
