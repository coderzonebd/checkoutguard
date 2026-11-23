<?php
/**
 * Register failure notice (Sakib F3 on 1.5.0 ada4b91): when registration
 * fails (HQ down, 429, timeout) the site has no key and checks are off, so
 * admins see a plain notice on the dashboard and CheckoutGuard pages with a
 * "Reconnect now" (button hidden while gated; the action itself ignores the
 * gate). A SiteKey refusal keeps only the SiteKey notice.
 *
 * Run on a local test site only (never production):
 *   wp eval-file tests/qa/register-failed-notice-tests.php
 * HTTP is answered by a pre_http_request filter in this process; nothing
 * leaves the machine. Options are restored at the end.
 */
if (!defined('ABSPATH')) {
    exit;
}
$GLOBALS['QA_NOW'] = 1800000000;
$GLOBALS['QA_ORIG'] = [];
foreach (['cg_plugin_options', 'cg_license_status', 'cg_register_next_allowed_at', 'cg_sitekey_state'] as $o) {
    $GLOBALS['QA_ORIG'][$o] = get_option($o, null);
}
$GLOBALS['QA_USER_ORIG'] = get_current_user_id();
add_filter('cg_sitekey_now', function () { return $GLOBALS['QA_NOW']; });
$GLOBALS['QA_LOG'] = []; $GLOBALS['QA_ROUTES'] = [];
add_filter('pre_http_request', function ($pre, $args, $url) {
    $path = preg_replace('#^.*?/api/v1#', '', (string) parse_url($url, PHP_URL_PATH));
    $GLOBALS['QA_LOG'][] = $path;
    if ($path === '/sites/register' && !empty($GLOBALS['QA_ON_REGISTER'])) {
        $cb = $GLOBALS['QA_ON_REGISTER'];
        $GLOBALS['QA_ON_REGISTER'] = null;
        $cb();
    }
    $r = $GLOBALS['QA_ROUTES'][$path] ?? ['code' => 'down'];
    if ($r['code'] === 'down') {
        return new WP_Error('http_request_failed', 'cURL error 7: Failed to connect to 127.0.0.1 port 8099');
    }
    $h = isset($r['ra']) ? ['retry-after' => $r['ra']] : [];
    return ['headers' => new WpOrg\Requests\Utility\CaseInsensitiveDictionary($h), 'body' => json_encode($r['body'] ?? []), 'response' => ['code' => $r['code'], 'message' => ''], 'cookies' => [], 'filename' => null];
}, PHP_INT_MAX, 3);
$GLOBALS['QA_WARN'] = [];
set_error_handler(function ($no, $str, $file, $line) {
    if (strpos($file, 'checkoutguard') !== false) {
        $GLOBALS['QA_WARN'][] = "$str @ " . basename($file) . ":$line";
    }
    return false;
});
require_once ABSPATH . 'wp-admin/includes/screen.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
$GLOBALS['QA_ADMIN'] = (int) ($admins[0] ?? 1);

function qa_reset() {
    global $wpdb;
    foreach (['cg_sitekey_state', 'cg_sitekey_next_allowed_at', 'cg_sitekey_lock', 'cg_register_next_allowed_at'] as $o) {
        $wpdb->delete($wpdb->options, ['option_name' => $o]);
        wp_cache_delete($o, 'options');
    }
    wp_cache_delete('notoptions', 'options'); wp_cache_delete('alloptions', 'options');
    wp_clear_scheduled_hook('cg_sitekey_request_event');
    // The Reconnect double-click lock must not leak from one case into the next.
    delete_transient(defined('CG_REGISTER_RECONNECT_LOCK') ? CG_REGISTER_RECONNECT_LOCK : 'cg_register_reconnect_lock');
    $o = is_array($GLOBALS['QA_ORIG']['cg_plugin_options']) ? $GLOBALS['QA_ORIG']['cg_plugin_options'] : [];
    unset($o['api_key']);
    cg_update_options_from_hq($o);
    update_option('cg_license_status', 'active');
    $GLOBALS['QA_LOG'] = []; $GLOBALS['QA_ROUTES'] = []; $GLOBALS['QA_NOW'] = 1800000000;
    $GLOBALS['QA_ON_REGISTER'] = null;
    wp_set_current_user($GLOBALS['QA_ADMIN']);
    $_GET = [];
    set_current_screen('dashboard');
}
function qa_notices() {
    ob_start();
    cg_render_register_failed_notice();
    $reg = ob_get_clean();
    ob_start();
    cg_render_sitekey_notice();
    $sk = ob_get_clean();
    return [$reg, $sk];
}
function qa_regs() {
    return count(array_filter($GLOBALS['QA_LOG'], function ($p) { return $p === '/sites/register'; }));
}
// A second Reconnect click that arrives while the first one is still
// waiting for HQ (the real double click): it runs inside the first
// register call. Returns a reference to its result.
function &qa_second_click_during_register() {
    $GLOBALS['QA_SECOND'] = null;
    $GLOBALS['QA_ON_REGISTER'] = function () { $GLOBALS['QA_SECOND'] = cg_register_reconnect_attempt(); };
    return $GLOBALS['QA_SECOND'];
}
$GLOBALS['R'] = [];
function ok($id, $name, $pass, $detail) { $GLOBALS['R'][] = [$id, $name, $pass ? 'PASS' : 'FAIL', $detail]; }
$TITLE = 'CheckoutGuard is not protecting checkouts right now.';

// N1: HQ down at registration -> notice with Reconnect, no raw error, no "key"
qa_reset();
cg_register_site_with_hq();
list($reg, $sk) = qa_notices();
$txt = wp_strip_all_tags($reg);
ok('N1', 'HQ down: notice, plain words, button gated 1 min', strpos($reg, $TITLE) !== false && strpos($reg, 'action=cg_register_reconnect') === false && stripos($txt, 'curl') === false && stripos($txt, ' key') === false && $sk === '', 'gated_after_fail=' . ((int) get_option('cg_register_next_allowed_at') - $GLOBALS['QA_NOW']));
// after the 1-minute gate the button is shown
$GLOBALS['QA_NOW'] += 61;
list($reg) = qa_notices();
ok('N2', 'after a minute: Reconnect button', strpos($reg, 'action=cg_register_reconnect') !== false && strpos($reg, '_wpnonce=') !== false, '');

// N3: 429 with Retry-After 3600 -> gate clamped to the 600 s upper bound
// (CG_REGISTER_MAX_WAIT), no button, try-again time
qa_reset();
$GLOBALS['QA_ROUTES'] = ['/sites/register' => ['code' => 429, 'ra' => '3600', 'body' => ['error' => 'Too Many Requests']]];
cg_register_site_with_hq();
$gate = (int) get_option('cg_register_next_allowed_at') - $GLOBALS['QA_NOW'];
$GLOBALS['QA_NOW'] += 120;
list($reg) = qa_notices();
ok('N3', '429 Retry-After 3600: gate clamped to 600 s, no button', $gate === 600 && strpos($reg, $TITLE) !== false && strpos($reg, 'action=cg_register_reconnect') === false && strpos($reg, 'try again after') !== false, "gate=$gate");

// N4: Reconnect ignores the gate (Tanvir, 22 Nov): still inside the 600 s
// gate from N3, Reconnect sends one register, stores the key, clears the
// gate; notice gone
$GLOBALS['QA_LOG'] = [];
$gate_left = (int) get_option('cg_register_next_allowed_at', 0) - $GLOBALS['QA_NOW'];
$GLOBALS['QA_ROUTES'] = ['/sites/register' => ['code' => 201, 'body' => ['api_key' => 'qa-shared-key-not-real']], '/sites/status' => ['code' => 'down']];
$g1 = cg_register_reconnect_attempt();
$reg_calls = count(array_filter($GLOBALS['QA_LOG'], function ($p) { return $p === '/sites/register'; }));
$o = get_option('cg_plugin_options', []);
list($reg) = qa_notices();
ok('N4', 'Reconnect inside the gate: one register, key stored, notice gone', $gate_left > 0 && $g1 === 'tried' && $reg_calls === 1 && ($o['api_key'] ?? '') === 'qa-shared-key-not-real' && $reg === '' && get_option('cg_register_next_allowed_at', null) === null, "gate_left=$gate_left g1=$g1 register_calls=$reg_calls");

// N5: double click: the second click arrives while the first is still
// calling HQ (Reconnect lock held, Tanvir R2 = 2) and sends nothing. The
// lock is not the register gate, so this holds with no gate at all.
qa_reset();
cg_register_site_with_hq();
$GLOBALS['QA_NOW'] += 61; $GLOBALS['QA_LOG'] = [];
$b = &qa_second_click_during_register();
$a = cg_register_reconnect_attempt();
ok('N5', 'double click: one register only', $a === 'tried' && $b === 'running' && qa_regs() === 1, "a=$a b=" . var_export($b, true) . " log=" . json_encode($GLOBALS['QA_LOG']));
unset($b);

// N6: 409 claim_required -> only the SiteKey notice
qa_reset();
$GLOBALS['QA_ROUTES'] = ['/sites/register' => ['code' => 409, 'body' => ['error' => 'x', 'code' => 'claim_required']]];
cg_register_site_with_hq();
list($reg, $sk) = qa_notices();
ok('N6', 'claim_required: SiteKey notice only', $reg === '' && strpos($sk, $TITLE) !== false, 'reg=' . strlen($reg) . ' sk=' . strlen($sk));

// N7: not shown to non-admins, nor on other admin pages
qa_reset();
cg_register_site_with_hq();
wp_set_current_user(0);
list($reg) = qa_notices();
wp_set_current_user($GLOBALS['QA_ADMIN']);
set_current_screen('edit-post');
list($reg2) = qa_notices();
$_GET['page'] = 'cg-settings';
list($reg3) = qa_notices();
ok('N7', 'hidden for non-admin and on other pages, shown on cg- page', $reg === '' && $reg2 === '' && strpos($reg3, $TITLE) !== false, '');

// N8: a site with a key and a failed sync status: no notice
qa_reset();
$o = get_option('cg_plugin_options', []); $o['api_key'] = 'qa-shared-key-not-real'; cg_update_options_from_hq($o);
update_option('cg_license_status', 'failed: cURL error 28');
list($reg) = qa_notices();
ok('N8', 'key present: no register notice', $reg === '', '');

// N9: no key but no failed registration recorded (status active): no notice
qa_reset();
list($reg) = qa_notices();
ok('N9', 'no key, status not failed: no register notice', $reg === '', '');

// N10: reconnect where register answers 2xx without a key: the gate set
// before the call is the only gate, so a second click stays gated
qa_reset();
cg_register_site_with_hq();
$GLOBALS['QA_NOW'] += 61; $GLOBALS['QA_LOG'] = [];
$GLOBALS['QA_ROUTES'] = ['/sites/register' => ['code' => 201, 'body' => ['site' => ['domain' => 'qa.test']]], '/sites/status' => ['code' => 'down']];
$b = &qa_second_click_during_register();
$a = cg_register_reconnect_attempt();
$n = qa_regs();
ok('N10', '2xx without key: second click during the first sends nothing, one register', $a === 'tried' && $b === 'running' && $n === 1 && cg_register_failed(), "a=$a b=" . var_export($b, true) . " register_calls=$n");
unset($b);

// N11: same for "already taken" (register sets no gate on that path)
qa_reset();
cg_register_site_with_hq();
$GLOBALS['QA_NOW'] += 61; $GLOBALS['QA_LOG'] = [];
$GLOBALS['QA_ROUTES'] = ['/sites/register' => ['code' => 409, 'body' => ['error' => 'Domain already taken']], '/sites/status' => ['code' => 'down']];
$b = &qa_second_click_during_register();
$a = cg_register_reconnect_attempt();
$n = qa_regs();
ok('N11', 'already taken: second click during the first sends nothing, one register', $a === 'tried' && $b === 'running' && $n === 1, "a=$a b=" . var_export($b, true) . " register_calls=$n");
unset($b);

// L1: the lock is released when the call ends (failed here): a new
// Reconnect right after (same second, well inside the 10 s TTL) sends
// exactly one more register. Catches a lock that is never deleted.
qa_reset();
cg_register_site_with_hq();
$GLOBALS['QA_NOW'] += 61; $GLOBALS['QA_LOG'] = [];
$a = cg_register_reconnect_attempt();
$n1 = qa_regs(); $GLOBALS['QA_LOG'] = [];
$b = cg_register_reconnect_attempt();
$n2 = qa_regs();
ok('L1', 'after a failed Reconnect the lock is gone: next Reconnect -> exactly 1 register', $a === 'tried' && $n1 === 1 && $b === 'tried' && $n2 === 1, "a=$a n1=$n1 b=$b n2=$n2");

// L2: while the lock is held (notice rendered during the register call):
// the notice is still there and shows no Reconnect button.
qa_reset();
cg_register_site_with_hq();
$GLOBALS['QA_NOW'] += 61; $GLOBALS['QA_LOG'] = [];
$GLOBALS['QA_IN'] = null;
$GLOBALS['QA_ON_REGISTER'] = function () { list($GLOBALS['QA_IN']) = qa_notices(); };
$a = cg_register_reconnect_attempt();
$in = (string) $GLOBALS['QA_IN'];
ok('L2', 'notice during the Reconnect call: shown, says Reconnect is running, no Reconnect button', $a === 'tried' && strpos($in, $TITLE) !== false && strpos($in, 'Reconnect is running. Reload this page in a few seconds.') !== false && strpos($in, 'action=cg_register_reconnect') === false, 'len=' . strlen($in));

// L3: lock and gate stay apart: a failed Reconnect at T leaves the register
// gate at T + 60 s (lock deleted, gate kept), so the auto path sends no
// register at T + 59 s and one at T + 60 s.
qa_reset();
cg_register_site_with_hq();
$GLOBALS['QA_NOW'] += 61; $T = $GLOBALS['QA_NOW']; $GLOBALS['QA_LOG'] = [];
$a = cg_register_reconnect_attempt();
$gate = (int) get_option('cg_register_next_allowed_at', 0) - $T;
$GLOBALS['QA_NOW'] = $T + 59; $GLOBALS['QA_LOG'] = [];
cg_register_site_with_hq();
$at59 = qa_regs();
$GLOBALS['QA_NOW'] = $T + 60; $GLOBALS['QA_LOG'] = [];
cg_register_site_with_hq();
$at60 = qa_regs();
ok('L3', 'failed Reconnect keeps the 60 s gate for the auto path: +59 s -> 0, +60 s -> 1', $a === 'tried' && $gate === 60 && $at59 === 0 && $at60 === 1, "a=$a gate=$gate at59=$at59 at60=$at60");

// L4: the lock is the transient 'cg_register_reconnect_lock' with the TTL
// CG_REGISTER_RECONNECT_LOCK_TTL, read while the register call is running.
// The TTL uses the real clock (set_transient), so TTL or TTL-1 is accepted.
// L4b keeps a separate check that the lock expires at all (TTL > 0).
qa_reset();
cg_register_site_with_hq();
$GLOBALS['QA_NOW'] += 61; $GLOBALS['QA_LOG'] = [];
$GLOBALS['QA_LK'] = null; $GLOBALS['QA_TTL'] = null;
$GLOBALS['QA_ON_REGISTER'] = function () {
    $GLOBALS['QA_LK'] = get_transient('cg_register_reconnect_lock');
    $GLOBALS['QA_TTL'] = (int) get_option('_transient_timeout_cg_register_reconnect_lock', 0) - time();
};
$a = cg_register_reconnect_attempt();
$after = get_transient('cg_register_reconnect_lock');
$ttl = $GLOBALS['QA_TTL'];
$want = defined('CG_REGISTER_RECONNECT_LOCK_TTL') ? (int) CG_REGISTER_RECONNECT_LOCK_TTL : null;
ok('L4', "lock is transient 'cg_register_reconnect_lock', TTL = CG_REGISTER_RECONNECT_LOCK_TTL, gone after the call", $a === 'tried' && defined('CG_REGISTER_RECONNECT_LOCK') && CG_REGISTER_RECONNECT_LOCK === 'cg_register_reconnect_lock' && $GLOBALS['QA_LK'] !== false && $GLOBALS['QA_LK'] !== null && $want !== null && ($ttl === $want || $ttl === $want - 1) && $after === false, "a=$a lk=" . var_export($GLOBALS['QA_LK'], true) . " ttl=" . var_export($ttl, true) . " want=" . var_export($want, true) . " after=" . var_export($after, true));
ok('L4b', 'the lock expires on its own: TTL constant and the measured TTL are both > 0', $want !== null && $want > 0 && is_int($ttl) && $ttl > 0, "want=" . var_export($want, true) . " ttl=" . var_export($ttl, true));
// L4c: Reconnect and Sync Now share this lock; the agreed TTL is at least
// 45 s so a slow HQ register call cannot outlive the lock.
ok('L4c', 'CG_REGISTER_RECONNECT_LOCK_TTL is at least 45 s', $want !== null && $want >= 45, "want=" . var_export($want, true));

// L5: the lock set directly by the test (no reliance on two clicks): while
// 'cg_register_reconnect_lock' is held, Reconnect returns 'running' and sends
// no HQ call at all. The gate is over (+61 s), so only the lock can stop it.
qa_reset();
cg_register_site_with_hq();
$GLOBALS['QA_NOW'] += 61; $GLOBALS['QA_LOG'] = [];
set_transient('cg_register_reconnect_lock', 1, 10);
$a = cg_register_reconnect_attempt();
$n = count($GLOBALS['QA_LOG']);
ok('L5', 'lock held (set by the test): Reconnect -> running, 0 HQ calls', $a === 'running' && $n === 0, "a=" . var_export($a, true) . " hq_calls=$n log=" . json_encode($GLOBALS['QA_LOG']));

// L5-ctl: same state with no lock: Reconnect sends exactly 1 register.
qa_reset();
cg_register_site_with_hq();
$GLOBALS['QA_NOW'] += 61; $GLOBALS['QA_LOG'] = [];
delete_transient('cg_register_reconnect_lock');
$a = cg_register_reconnect_attempt();
$n = qa_regs();
ok('L5-ctl', 'no lock: Reconnect -> tried, exactly 1 register', $a === 'tried' && $n === 1, "a=" . var_export($a, true) . " register_calls=$n");

// restore
qa_reset();
foreach ($GLOBALS['QA_ORIG'] as $name => $val) {
    if ($val === null) { delete_option($name); } elseif ($name === 'cg_plugin_options') { cg_update_options_from_hq($val); } else { update_option($name, $val); }
}
wp_set_current_user($GLOBALS['QA_USER_ORIG']);
restore_error_handler();
$R = $GLOBALS['R'];
foreach ($R as $r) echo implode(' | ', $r), "\n";
echo 'warnings_total=' . count($GLOBALS['QA_WARN']) . "\n"; foreach (array_unique($GLOBALS['QA_WARN']) as $w) echo "  W: $w\n";
$f = count(array_filter($R, function ($r) { return $r[2] !== 'PASS'; })) + (count($GLOBALS['QA_WARN']) ? 1 : 0);
echo "SUMMARY " . (count($R) - $f) . '/' . count($R) . " PASS\n";
