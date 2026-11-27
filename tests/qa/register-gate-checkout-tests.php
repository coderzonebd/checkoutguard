<?php
/**
 * Register gate on the checkout path (BUG-01 on dev b2bae0b): with no
 * key and HQ unreachable, every guest checkout save that misses the plan
 * cache ran cg_handle_save_checkout_data() -> cg_can_perform_action() ->
 * cg_sync_with_hq() -> cg_register_site_with_hq(), and the register gate
 * (cg_register_next_allowed_at) was written but never read, so one checkout
 * sent several POST /sites/register. Rule: at most one register per gate
 * (60 s, or Retry-After) on this path. The admin's Reconnect and Sync Now
 * pass the gate (one register each, never two at once: Reconnect lock); a
 * failed try writes a new gate, a good one clears it. Cron and page-load
 * syncs stay gated.
 * The same holds when HQ answers 2xx without a key and for "already taken".
 *
 * Run on a local test site with WooCommerce only (never production):
 *   wp eval-file tests/qa/register-gate-checkout-tests.php
 * HTTP is answered by a pre_http_request filter in this process; nothing
 * leaves the machine. No checkout row is written (the limit check refuses
 * first). Options are restored at the end.
 */
if (!defined('ABSPATH')) {
    exit;
}
require_once __DIR__ . '/inc-reconnect-lock.php';
$GLOBALS['QA_NOW'] = 1800000000;
$GLOBALS['QA_ORIG'] = [];
foreach (['cg_plugin_options', 'cg_license_status', 'cg_register_next_allowed_at', 'cg_register_manual_next_allowed_at', 'cg_sitekey_state'] as $o) {
    $GLOBALS['QA_ORIG'][$o] = get_option($o, null);
}
$GLOBALS['QA_CACHE_ORIG'] = get_transient('cg_api_cache');
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
        return new WP_Error('http_request_failed', 'cURL error 7: Failed to connect');
    }
    $h = isset($r['ra']) ? ['retry-after' => $r['ra']] : [];
    return ['headers' => new WpOrg\Requests\Utility\CaseInsensitiveDictionary($h), 'body' => json_encode($r['body'] ?? []), 'response' => ['code' => $r['code'], 'message' => ''], 'cookies' => [], 'filename' => null];
}, PHP_INT_MAX, 3);
// wp_send_json_*() ends an AJAX request with wp_die(); turn that into an exception here.
class QA_Ajax_Done extends Exception {}
add_filter('wp_doing_ajax', '__return_true');
add_filter('wp_die_ajax_handler', function () { return function () { throw new QA_Ajax_Done(); }; });
$GLOBALS['QA_WARN'] = [];
set_error_handler(function ($no, $str, $file, $line) {
    if (strpos($file, 'checkoutguard') !== false) {
        $GLOBALS['QA_WARN'][] = "$str @ " . basename($file) . ":$line";
    }
    return false;
});
$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
$GLOBALS['QA_ADMIN'] = (int) ($admins[0] ?? 1);

function qa_reset() {
    global $wpdb;
    foreach (['cg_sitekey_state', 'cg_sitekey_next_allowed_at', 'cg_sitekey_lock', 'cg_register_next_allowed_at', 'cg_register_manual_next_allowed_at', 'cg_register_already_registered_at'] as $o) {
        $wpdb->delete($wpdb->options, ['option_name' => $o]);
        wp_cache_delete($o, 'options');
    }
    wp_cache_delete('notoptions', 'options'); wp_cache_delete('alloptions', 'options');
    wp_clear_scheduled_hook('cg_sitekey_request_event');
    delete_transient('cg_api_cache');
    qa_rl_clear();
    $o = is_array($GLOBALS['QA_ORIG']['cg_plugin_options']) ? $GLOBALS['QA_ORIG']['cg_plugin_options'] : [];
    unset($o['api_key']);
    cg_update_options_from_hq($o);
    update_option('cg_license_status', 'active');
    $GLOBALS['QA_LOG'] = []; $GLOBALS['QA_ROUTES'] = []; $GLOBALS['QA_NOW'] = 1800000000;
    $GLOBALS['QA_ON_REGISTER'] = null;
    wp_set_current_user(0);
}
// The admin's "Sync Now" button (AJAX cg_handle_force_sync_ajax). Returns
// 'ok', 'error' or 'none' (no JSON sent).
function qa_sync_now() {
    $u = get_current_user_id();
    wp_set_current_user($GLOBALS['QA_ADMIN']);
    $_POST = $_REQUEST = ['nonce' => wp_create_nonce('cg_force_sync_ajax')];
    ob_start();
    try {
        cg_handle_force_sync_ajax();
    } catch (QA_Ajax_Done $e) {
    }
    $raw = (string) ob_get_clean();
    $GLOBALS['QA_SYNC_RAW'] = $raw;
    $j = json_decode($raw, true);
    $_POST = $_REQUEST = [];
    wp_set_current_user($u);
    return is_array($j) ? (!empty($j['success']) ? 'ok' : 'error') : 'none';
}
// One guest checkout-field save (classic or block checkout AJAX), a new
// session each time so the limit check runs (no existing row). Placeholder
// values only; never a real customer.
function qa_checkout_save($i) {
    wp_set_current_user(0);
    $_POST = $_REQUEST = [
        'nonce' => wp_create_nonce('cg_save_checkout_data_nonce'),
        'device_fingerprint' => 'qafp' . $GLOBALS['QA_NOW'] . 'n' . $i,
        'billing_first_name' => 'QA',
    ];
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    try {
        cg_handle_save_checkout_data();
    } catch (QA_Ajax_Done $e) {
    }
    $_POST = $_REQUEST = [];
}
function qa_registers() {
    return count(array_filter($GLOBALS['QA_LOG'], function ($p) { return $p === '/sites/register'; }));
}
$GLOBALS['R'] = [];
function ok($id, $name, $pass, $detail) { $GLOBALS['R'][] = [$id, $name, $pass ? 'PASS' : 'FAIL', $detail]; }
ob_start();

// G1: HQ down, no key, cache miss: six checkout saves within 13 s -> at most one register.
qa_reset();
for ($i = 0; $i < 6; $i++) { qa_checkout_save($i); $GLOBALS['QA_NOW'] += 2; }
$n1 = qa_registers();
ok('G1', 'HQ down: 6 checkout saves in 13 s -> <= 1 register', $n1 <= 1 && $n1 >= 1, "register=$n1 calls=" . count($GLOBALS['QA_LOG']));

// G2: still inside the 60 s gate of the first try: more saves send no register.
$GLOBALS['QA_NOW'] = 1800000000 + 59; $GLOBALS['QA_LOG'] = [];
for ($i = 10; $i < 13; $i++) { qa_checkout_save($i); }
ok('G2', 'gate open for 59 s: 3 more saves -> 0 register', qa_registers() === 0, 'register=' . qa_registers());

// G3: over a full 60 s window (saves every 5 s for 60 s) -> at most one register per 60 s.
qa_reset();
for ($t = 0; $t < 60; $t += 5) { $GLOBALS['QA_NOW'] = 1800000000 + $t; qa_checkout_save($t); }
ok('G3', '12 saves spread over 60 s -> <= 1 register', qa_registers() <= 1, 'register=' . qa_registers());

// G4: gate set in the future (as after an earlier failure): cg_can_perform_action sends no HTTP at all.
qa_reset();
update_option('cg_register_next_allowed_at', $GLOBALS['QA_NOW'] + 600, false);
$allowed = cg_can_perform_action('checkouts');
ok('G4', 'gate in the future: cg_can_perform_action(checkouts) -> no HTTP call', count($GLOBALS['QA_LOG']) === 0 && $allowed === false, 'calls=' . json_encode($GLOBALS['QA_LOG']));

// G5: 429 Retry-After 300 -> no second register for 300 s.
qa_reset();
$GLOBALS['QA_ROUTES'] = ['/sites/register' => ['code' => 429, 'ra' => '300', 'body' => ['error' => 'Too Many Requests']]];
for ($t = 0; $t < 300; $t += 30) { $GLOBALS['QA_NOW'] = 1800000000 + $t; qa_checkout_save($t); }
ok('G5', '429 Retry-After 300: saves for 300 s -> 1 register', qa_registers() === 1, 'register=' . qa_registers());

// G6: after the gate the site may try again (not blocked for good), still one per gate.
qa_reset();
qa_checkout_save(1); $GLOBALS['QA_NOW'] += 61; qa_checkout_save(2); qa_checkout_save(3);
ok('G6', 'after 61 s: one more register allowed, not two', qa_registers() <= 2, 'register=' . qa_registers());

// G7: the admin's Reconnect ignores the register gate (Tanvir, 22 Nov: same
// rule as Sync now): with the gate still open from a failed auto register,
// Reconnect sends exactly one register (the sync inside it adds none).
qa_reset();
qa_checkout_save(1); $GLOBALS['QA_NOW'] = 1800000000 + 10; $GLOBALS['QA_LOG'] = [];
$gate_before = (int) get_option('cg_register_next_allowed_at', 0);
wp_set_current_user($GLOBALS['QA_ADMIN']);
$a = cg_register_reconnect_attempt();
ok('G7', 'Reconnect while the gate is open: tried, exactly one register', $gate_before > $GLOBALS['QA_NOW'] && $a === 'tried' && qa_registers() === 1, "gate_left=" . ($gate_before - $GLOBALS['QA_NOW']) . " a=$a register=" . qa_registers());

// G15: the failed Reconnect at +10 s writes a new gate (+70 s); the auto path
// (checkout saves) still honours it: none in +11..+69 s, one at +70 s.
// G15 runs on the state G7 leaves (no qa_reset). When G7 fails because
// Reconnect is still gated, no new gate is written at +10 s and the old gate
// ends inside +11..+69 s, so G15 fails too (e.g. in_gate=1 at70=0). Read a
// G15 FAIL together with G7.
$GLOBALS['QA_LOG'] = [];
foreach ([11, 20, 40, 60, 69] as $t) { $GLOBALS['QA_NOW'] = 1800000000 + $t; qa_checkout_save($t); }
$b = qa_registers();
$GLOBALS['QA_NOW'] = 1800000000 + 70; $GLOBALS['QA_LOG'] = [];
qa_checkout_save(70); qa_checkout_save(71);
$c = qa_registers();
ok('G15', 'after a failed Reconnect at +10 s: auto saves +11..+69 s -> 0 register, +70 s -> 1', $b === 0 && $c === 1, "in_gate=$b at70=$c");

// G16: Reconnect that succeeds while the gate is open stores the key and clears the gate.
qa_reset();
qa_checkout_save(1); $GLOBALS['QA_NOW'] = 1800000000 + 10; $GLOBALS['QA_LOG'] = [];
$GLOBALS['QA_ROUTES'] = ['/sites/register' => ['code' => 201, 'body' => ['api_key' => 'qa-shared-key-not-real']]];
wp_set_current_user($GLOBALS['QA_ADMIN']);
$a = cg_register_reconnect_attempt();
$o = get_option('cg_plugin_options', []);
ok('G16', 'Reconnect succeeds while the gate is open: key stored, gate cleared', $a === 'tried' && qa_registers() === 1 && ($o['api_key'] ?? '') === 'qa-shared-key-not-real' && get_option('cg_register_next_allowed_at', null) === null, "a=$a register=" . qa_registers() . ' gate=' . var_export(get_option('cg_register_next_allowed_at', null), true));

// G8: register succeeds -> key stored, gate cleared, later saves send no register.
qa_reset();
$GLOBALS['QA_ROUTES'] = ['/sites/register' => ['code' => 201, 'body' => ['api_key' => 'qa-shared-key-not-real']]];
qa_checkout_save(1); $GLOBALS['QA_NOW'] += 5; qa_checkout_save(2);
$o = get_option('cg_plugin_options', []);
ok('G8', 'HQ up: one register, key stored, no second register', qa_registers() === 1 && ($o['api_key'] ?? '') === 'qa-shared-key-not-real' && get_option('cg_register_next_allowed_at', null) === null, 'register=' . qa_registers());

// G9: HQ answers 2xx without a key (HQ-side setup issue): six saves in 13 s -> one register.
qa_reset();
$GLOBALS['QA_ROUTES'] = ['/sites/register' => ['code' => 201, 'body' => ['site' => ['domain' => 'example.test']]]];
for ($i = 0; $i < 6; $i++) { qa_checkout_save($i); $GLOBALS['QA_NOW'] += 2; }
$st = (string) get_option('cg_license_status', '');
ok('G9', '2xx without a key: 6 saves in 13 s -> 1 register, failed status kept', qa_registers() === 1 && strpos($st, 'failed') === 0, 'register=' . qa_registers() . ' status=' . substr($st, 0, 40));

// G10: 2xx without a key is not blocked for good: after 61 s one more register, not two.
$GLOBALS['QA_NOW'] = 1800000000 + 61; $GLOBALS['QA_LOG'] = [];
qa_checkout_save(20); qa_checkout_save(21);
ok('G10', '2xx without a key: after 61 s exactly one more register', qa_registers() === 1, 'register=' . qa_registers());

// G11: "already taken": six saves in 13 s -> one register, SiteKey state untouched.
qa_reset();
$GLOBALS['QA_ROUTES'] = ['/sites/register' => ['code' => 409, 'body' => ['error' => 'Domain already taken']]];
for ($i = 0; $i < 6; $i++) { qa_checkout_save($i); $GLOBALS['QA_NOW'] += 2; }
$sk = get_option('cg_sitekey_state', null);
ok('G11', 'already taken: 6 saves in 13 s -> 1 register, no SiteKey state written', qa_registers() === 1 && empty($sk['legacy_blocked_at']) && empty($sk['pending_until']), 'register=' . qa_registers() . ' sitekey=' . json_encode($sk));

// G12: "already taken" after one Reconnect: saves inside the next 60 s send no register.
qa_reset();
$GLOBALS['QA_ROUTES'] = ['/sites/register' => ['code' => 409, 'body' => ['error' => 'Domain already taken']]];
update_option('cg_license_status', 'failed: qa');
wp_set_current_user($GLOBALS['QA_ADMIN']);
$a = cg_register_reconnect_attempt();
$after_reconnect = qa_registers();
for ($t = 5; $t < 60; $t += 5) { $GLOBALS['QA_NOW'] = 1800000000 + $t; qa_checkout_save($t); }
ok('G12', 'already taken: Reconnect once, then saves for 55 s -> no further register', $a === 'tried' && $after_reconnect === 1 && qa_registers() === 1, "a=$a after_reconnect=$after_reconnect register=" . qa_registers());

// G13: the real HQ answer for a domain that already has a key is 409
// {"error":"already_registered"} (no "already taken" text). Whatever status
// the plugin shows for it, the register gate must hold: one register in
// 0..13 s, none at +59 s, one more at +60 s.
qa_reset();
$GLOBALS['QA_ROUTES'] = ['/sites/register' => ['code' => 409, 'body' => ['error' => 'already_registered']]];
for ($i = 0; $i < 6; $i++) { qa_checkout_save($i); $GLOBALS['QA_NOW'] += 2; }
$a = qa_registers();
$GLOBALS['QA_NOW'] = 1800000000 + 59; $GLOBALS['QA_LOG'] = [];
qa_checkout_save(30); qa_checkout_save(31);
$b = qa_registers();
$GLOBALS['QA_NOW'] = 1800000000 + 60; $GLOBALS['QA_LOG'] = [];
qa_checkout_save(40); qa_checkout_save(41);
$c = qa_registers();
ok('G13', '409 already_registered: 6 saves in 13 s -> 1 register, +59 s -> 0, +60 s -> 1', $a === 1 && $b === 0 && $c === 1, "first=$a at59=$b at60=$c gate=" . (int) get_option('cg_register_next_allowed_at', 0));

// G13s: 409 {"error":"already_registered"} is the same case as "already
// taken" (Tanvir, 22 Nov): the shown status stays as it was, and the
// register gate is still written (now + CG_REGISTER_MIN_GAP).
qa_reset();
$GLOBALS['QA_ROUTES'] = ['/sites/register' => ['code' => 409, 'body' => ['error' => 'already_registered']]];
$st_before = get_option('cg_license_status', null);
cg_register_site_with_hq();
$st_after = get_option('cg_license_status', null);
$gate = (int) get_option('cg_register_next_allowed_at', 0) - $GLOBALS['QA_NOW'];
ok('G13s', '409 already_registered: status unchanged, gate = MIN_GAP', qa_registers() === 1 && $st_after === $st_before && $gate === CG_REGISTER_MIN_GAP, 'register=' . qa_registers() . ' status_before=' . var_export($st_before, true) . ' status_after=' . var_export(substr((string) $st_after, 0, 40), true) . " gate=$gate");

// G13s-m/G13s-c (review N1; were G13m/G13c, renamed so they do not clash
// with G13c Sync Now and G13m force): HQ may send "message" next to "error", or the
// reason in "code". The 409 is matched by status + reason field, not by the
// shown message text, so both keep the status and write the gate.
foreach ([
    'G13s-m' => ['error' => 'already_registered', 'message' => 'This domain already has a key'],
    'G13s-c' => ['code' => 'already_registered', 'message' => 'This domain already has a key'],
] as $qa_id => $qa_body) {
    qa_reset();
    $GLOBALS['QA_ROUTES'] = ['/sites/register' => ['code' => 409, 'body' => $qa_body]];
    $st_before = get_option('cg_license_status', null);
    cg_register_site_with_hq();
    $st_after = get_option('cg_license_status', null);
    $gate = (int) get_option('cg_register_next_allowed_at', 0) - $GLOBALS['QA_NOW'];
    ok($qa_id, '409 already_registered with a message: status unchanged, gate = MIN_GAP', qa_registers() === 1 && $st_after === $st_before && $gate === CG_REGISTER_MIN_GAP, 'register=' . qa_registers() . ' status_after=' . var_export(substr((string) $st_after, 0, 40), true) . " gate=$gate");
}

// G13o: another 409 with a message and no reason field is still a failure.
qa_reset();
$GLOBALS['QA_ROUTES'] = ['/sites/register' => ['code' => 409, 'body' => ['error' => 'conflict', 'message' => 'Conflict']]];
cg_register_site_with_hq();
$st_after = (string) get_option('cg_license_status', '');
ok('G13o', 'other 409: status becomes failed', str_starts_with($st_after, 'failed: '), 'status_after=' . var_export(substr($st_after, 0, 40), true));

// G14: gate edge. The gate ends exactly at first try + 60 s: a save at
// +59 s sends no register, a save at +60 s sends one (gate > now, not >=).
qa_reset();
qa_checkout_save(1);
$a = qa_registers();
$GLOBALS['QA_NOW'] = 1800000000 + 59; $GLOBALS['QA_LOG'] = [];
qa_checkout_save(2);
$b = qa_registers();
$GLOBALS['QA_NOW'] = 1800000000 + 60; $GLOBALS['QA_LOG'] = [];
qa_checkout_save(3); qa_checkout_save(4);
$c = qa_registers();
ok('G14', 'HQ down: gate edge, +59 s -> 0 register, +60 s -> exactly 1', $a === 1 && $b === 0 && $c === 1, "first=$a at59=$b at60=$c");

// G17: "already taken" with Retry-After 300 holds the gate for the full
// 300 s (not just the 60 s floor): saves at 0..299 s -> 1 register, a save
// at +300 s -> exactly one more.
qa_reset();
$GLOBALS['QA_ROUTES'] = ['/sites/register' => ['code' => 409, 'ra' => '300', 'body' => ['error' => 'Domain already taken']]];
for ($t = 0; $t < 300; $t += 30) { $GLOBALS['QA_NOW'] = 1800000000 + $t; qa_checkout_save($t); }
$GLOBALS['QA_NOW'] = 1800000000 + 299; qa_checkout_save(299);
$a = qa_registers();
$GLOBALS['QA_NOW'] = 1800000000 + 300; $GLOBALS['QA_LOG'] = [];
qa_checkout_save(300); qa_checkout_save(301);
$b = qa_registers();
ok('G17', 'already taken + Retry-After 300: saves for 300 s -> 1 register, +300 s -> exactly 1', $a === 1 && $b === 1, "first=$a at300=$b gate=" . (int) get_option('cg_register_next_allowed_at', 0));

// S9: "already taken" as HTTP 422 (same text, another status): the gate holds
// (one register in 0..13 s, none at +59 s, one more at +60 s) and the shown
// license status is left as it was, like the 409 form.
qa_reset();
$GLOBALS['QA_ROUTES'] = ['/sites/register' => ['code' => 422, 'body' => ['error' => 'Domain already taken']]];
for ($i = 0; $i < 6; $i++) { qa_checkout_save($i); $GLOBALS['QA_NOW'] += 2; }
$a = qa_registers();
$st = (string) get_option('cg_license_status', '');
$GLOBALS['QA_NOW'] = 1800000000 + 59; $GLOBALS['QA_LOG'] = [];
qa_checkout_save(30); qa_checkout_save(31);
$b = qa_registers();
$GLOBALS['QA_NOW'] = 1800000000 + 60; $GLOBALS['QA_LOG'] = [];
qa_checkout_save(40); qa_checkout_save(41);
$c = qa_registers();
ok('S9', 'already taken (422): 1 register, +59 s -> 0, +60 s -> 1, status unchanged', $a === 1 && $b === 0 && $c === 1 && $st === 'active', "first=$a at59=$b at60=$c status=" . substr($st, 0, 40));

// S10: 2xx without a key writes the gate at exactly first try + 60 s and the
// server-side setup message: +59 s -> 0 register, +60 s -> exactly 1.
qa_reset();
$GLOBALS['QA_ROUTES'] = ['/sites/register' => ['code' => 200, 'body' => ['api_key' => '']]];
qa_checkout_save(1);
$a = qa_registers();
$gate = (int) get_option('cg_register_next_allowed_at', 0);
$st = (string) get_option('cg_license_status', '');
$GLOBALS['QA_NOW'] = 1800000000 + 59; $GLOBALS['QA_LOG'] = [];
qa_checkout_save(2);
$b = qa_registers();
$GLOBALS['QA_NOW'] = 1800000000 + 60; $GLOBALS['QA_LOG'] = [];
qa_checkout_save(3); qa_checkout_save(4);
$c = qa_registers();
ok('S10', '2xx without a key: gate = first + 60 s, +59 s -> 0, +60 s -> 1, setup message', $a === 1 && $b === 0 && $c === 1 && $gate === 1800000000 + 60 && strpos($st, 'failed: Headquarters accepted registration') === 0, "first=$a at59=$b at60=$c gate=$gate status=" . substr($st, 0, 40));

// G13b: Sync Now honours the Reconnect lock. The admin clicks Sync Now
// (another tab) while a Reconnect is still waiting for HQ: Sync Now sends
// no register of its own, so the pair sends exactly one. A Sync Now that
// forced its register past the gate without checking the lock would send two.
qa_reset();
cg_register_site_with_hq();
$GLOBALS['QA_NOW'] += 61; $GLOBALS['QA_LOG'] = [];
wp_set_current_user($GLOBALS['QA_ADMIN']);
$GLOBALS['QA_SYNC'] = null; $GLOBALS['QA_LOCKED'] = null;
$GLOBALS['QA_ON_REGISTER'] = function () {
    $GLOBALS['QA_LOCKED'] = qa_rl_held();
    $GLOBALS['QA_SYNC'] = qa_sync_now();
};
$a = cg_register_reconnect_attempt();
$n = qa_registers();
ok('G13b', 'Sync Now during a running Reconnect: no register of its own, 1 in total', $a === 'tried' && $GLOBALS['QA_LOCKED'] === true && $GLOBALS['QA_SYNC'] === 'error' && $n === 1, "a=$a locked=" . var_export($GLOBALS['QA_LOCKED'], true) . ' sync=' . var_export($GLOBALS['QA_SYNC'], true) . " register=$n calls=" . json_encode($GLOBALS['QA_LOG']));

// G13b-ctl: with no Reconnect running and the gate over, Sync Now does send
// one register (so G13b is not passing because Sync Now never registers).
qa_reset();
cg_register_site_with_hq();
$GLOBALS['QA_NOW'] += 61; $GLOBALS['QA_LOG'] = [];
$s = qa_sync_now();
$n = qa_registers();
ok('G13b-ctl', 'Sync Now, no Reconnect running, gate over: exactly 1 register', $s === 'error' && $n === 1, "sync=$s register=$n");

// G13d: Sync Now with force while the Reconnect lock is held (lock set
// directly by the test): no HQ call at all. The gate is over (+61 s), so
// only the lock can stop it.
qa_reset();
cg_register_site_with_hq();
$GLOBALS['QA_NOW'] += 61; $GLOBALS['QA_LOG'] = [];
qa_rl_set(10);
$s = qa_sync_now();
$n = count($GLOBALS['QA_LOG']);
ok('G13d', 'Sync Now (force) with the Reconnect lock held: 0 HQ calls', $n === 0, "sync=" . var_export($s, true) . " hq_calls=$n log=" . json_encode($GLOBALS['QA_LOG']));

// G13e: Sync Now with no key and no Reconnect lock (the case the force fix
// is for). Inside the 60 s gate it sets the Reconnect lock before the
// register (TTL CG_REGISTER_RECONNECT_LOCK_TTL = 45 s), forces exactly one
// register, and the lock is gone again when the click ends (finally).
qa_reset();
cg_register_site_with_hq();
$GLOBALS['QA_NOW'] += 10; $GLOBALS['QA_LOG'] = [];
$qa_lk = defined('CG_REGISTER_RECONNECT_LOCK') ? CG_REGISTER_RECONNECT_LOCK : 'cg_register_reconnect_lock';
$gate_open = (int) get_option('cg_register_next_allowed_at', 0) > $GLOBALS['QA_NOW'];
$lock_before = qa_rl_held();
$GLOBALS['QA_E_LOCK'] = null; $GLOBALS['QA_E_TTL'] = null; $GLOBALS['QA_E_SET_AT'] = null;
// The TTL is measured from the second the lock row was written (the
// INSERT seen by a query filter), not from the second the register
// callback runs, so a slow register request does not shrink it.
$qa_e_rec = function ($q) use ($qa_lk) {
    if ($GLOBALS['QA_E_SET_AT'] === null && preg_match('/^\s*INSERT\b/i', $q) && strpos($q, "'" . $qa_lk . "'") !== false) {
        $GLOBALS['QA_E_SET_AT'] = time();
    }
    return $q;
};
add_filter('query', $qa_e_rec);
$GLOBALS['QA_ON_REGISTER'] = function () use ($qa_lk) {
    $GLOBALS['QA_E_LOCK'] = qa_rl_held();
    $v = qa_rl_value();
    if ($GLOBALS['QA_E_SET_AT'] !== null && $v !== null) {
        $GLOBALS['QA_E_TTL'] = $v - $GLOBALS['QA_E_SET_AT'];
    }
};
$s = qa_sync_now();
remove_filter('query', $qa_e_rec);
$n = qa_registers();
$lock_after = qa_rl_held();
$ttl_const = defined('CG_REGISTER_RECONNECT_LOCK_TTL') ? (int) CG_REGISTER_RECONNECT_LOCK_TTL : 0;
ok('G13e', 'Sync Now, no key, no lock, inside the gate: lock set during the register, exactly 1 register, lock gone after', $gate_open && !$lock_before && $GLOBALS['QA_E_LOCK'] === true && $n === 1 && !$lock_after, 'gate_open=' . var_export($gate_open, true) . ' lock_before=' . var_export($lock_before, true) . ' lock_during=' . var_export($GLOBALS['QA_E_LOCK'], true) . " register=$n lock_after=" . var_export($lock_after, true) . " sync=$s");
ok('G13e-ttl', 'the Reconnect lock TTL is 45 s (constant and the stored expiry)', $ttl_const === 45 && $GLOBALS['QA_E_TTL'] !== null && $GLOBALS['QA_E_TTL'] >= 44 && $GLOBALS['QA_E_TTL'] <= 45, "const=$ttl_const measured=" . var_export($GLOBALS['QA_E_TTL'], true));

// G13d-run: with the Reconnect lock held, Sync Now answers with
// data.code === 'running' (exact field, no other field accepted) and sends
// no HQ call.
qa_reset();
cg_register_site_with_hq();
$GLOBALS['QA_NOW'] += 61; $GLOBALS['QA_LOG'] = [];
qa_rl_set(45);
$s = qa_sync_now();
$n = count($GLOBALS['QA_LOG']);
$j = json_decode((string) $GLOBALS['QA_SYNC_RAW'], true);
$d = is_array($j) && isset($j['data']) && is_array($j['data']) ? $j['data'] : [];
$running = is_array($d) && isset($d['code']) && $d['code'] === 'running';
ok('G13d-run', "Sync Now with the Reconnect lock held: result 'running', 0 HQ calls", $s === 'error' && $running && $n === 0, "sync=$s hq_calls=$n raw=" . substr((string) $GLOBALS['QA_SYNC_RAW'], 0, 160));

// G13a: activation passes force: re-activating the plugin
// inside the 60 s gate of a failed register still sends one register. The
// sync that activation runs after it stays gated (the forced try wrote a new
// gate), so it is exactly one.
qa_reset();
cg_register_site_with_hq();
$a = qa_registers();
$GLOBALS['QA_NOW'] = 1800000000 + 10; $GLOBALS['QA_LOG'] = [];
$gate_open = (int) get_option('cg_register_next_allowed_at', 0) > $GLOBALS['QA_NOW'];
cg_plugin_activate();
$b = qa_registers();
ok('G13a', 'HQ down: activate again at +10 s (gate open) -> exactly 1 register', $a === 1 && $gate_open && $b === 1, "first=$a gate_open=" . var_export($gate_open, true) . " on_activate=$b calls=" . json_encode($GLOBALS['QA_LOG']));

// G13c: Sync Now is an explicit admin action and passes the
// register gate like Reconnect. No key, a failed register 10 s ago (gate
// open until +60 s): one Sync Now click sends exactly one register.
qa_reset();
cg_register_site_with_hq();
$GLOBALS['QA_NOW'] += 10; $GLOBALS['QA_LOG'] = [];
$gate_open = (int) get_option('cg_register_next_allowed_at', 0) > $GLOBALS['QA_NOW'];
$s = qa_sync_now();
$n = qa_registers();
ok('G13c', 'Sync Now inside the gate (no key): exactly 1 register', $gate_open && $n === 1, 'gate_open=' . var_export($gate_open, true) . " sync=$s register=$n calls=" . json_encode($GLOBALS['QA_LOG']));

// G13c-lock: a second Sync Now click while the first one's register is still
// waiting for HQ sends no register of its own (Reconnect lock), so the pair
// sends exactly one. Without a lock it would be two.
qa_reset();
cg_register_site_with_hq();
$GLOBALS['QA_NOW'] += 10; $GLOBALS['QA_LOG'] = [];
$GLOBALS['QA_SYNC2'] = null;
$GLOBALS['QA_ON_REGISTER'] = function () { $GLOBALS['QA_SYNC2'] = qa_sync_now(); };
$s = qa_sync_now();
$n = qa_registers();
ok('G13c-lock', 'second Sync Now during the first: no register of its own, 1 in total', $GLOBALS['QA_SYNC2'] !== null && $n === 1, "sync=$s sync2=" . var_export($GLOBALS['QA_SYNC2'], true) . " register=$n calls=" . json_encode($GLOBALS['QA_LOG']));

// G13c-lock2: same double Sync Now, but the gate is over (+61 s), so only
// the lock can hold the second click: still exactly one register.
qa_reset();
cg_register_site_with_hq();
$GLOBALS['QA_NOW'] += 61; $GLOBALS['QA_LOG'] = [];
$GLOBALS['QA_SYNC2'] = null;
$GLOBALS['QA_ON_REGISTER'] = function () { $GLOBALS['QA_ON_REGISTER'] = null; $GLOBALS['QA_SYNC2'] = qa_sync_now(); };
$s = qa_sync_now();
$n = qa_registers();
ok('G13c-lock2', 'gate over: second Sync Now during the first sends no register, 1 in total', $GLOBALS['QA_SYNC2'] !== null && $n === 1, "sync=$s sync2=" . var_export($GLOBALS['QA_SYNC2'], true) . " register=$n calls=" . json_encode($GLOBALS['QA_LOG']));

// G13c-ctl: the automatic paths stay gated. Inside the same gate the daily
// cron sync (cg_daily_sync_hook) and a plain cg_sync_with_hq() (what the
// stale-cache page-load sync at admin-settings-page.php:963 calls) send 0.
qa_reset();
cg_register_site_with_hq();
$GLOBALS['QA_NOW'] += 10; $GLOBALS['QA_LOG'] = [];
do_action('cg_daily_sync_hook');
$cron = qa_registers(); $GLOBALS['QA_LOG'] = [];
cg_sync_with_hq();
$plain = qa_registers();
ok('G13c-ctl', 'inside the gate: cron sync and plain sync -> 0 register', $cron === 0 && $plain === 0, "cron=$cron plain=$plain");

// G13m: only a real boolean true forces. A truthy non-boolean value (a hook
// that passes an argument, '1', 1) must not send the register past the gate.
qa_reset();
cg_register_site_with_hq();
$GLOBALS['QA_NOW'] += 10; $GLOBALS['QA_LOG'] = [];
do_action('cg_daily_sync_hook', '1');
$hook = qa_registers(); $GLOBALS['QA_LOG'] = [];
cg_sync_with_hq('1');
$str = qa_registers(); $GLOBALS['QA_LOG'] = [];
cg_sync_with_hq(1);
$int = qa_registers();
ok('G13m', "inside the gate: hook arg '1', cg_sync_with_hq('1') and (1) -> 0 register", $hook === 0 && $str === 0 && $int === 0, "hook=$hook str=$str int=$int");

// G13j: the forced register runs under the Reconnect lock. The register
// request itself sees the lock held, so a Reconnect or a second Sync Now in
// another tab started at that moment sends nothing.
qa_reset();
cg_register_site_with_hq();
$GLOBALS['QA_NOW'] = 1800000000 + 10; $GLOBALS['QA_LOG'] = [];
$GLOBALS['QA_LOCKED'] = null;
$GLOBALS['QA_ON_REGISTER'] = function () { $GLOBALS['QA_LOCKED'] = qa_rl_held(); };
$s = qa_sync_now();
$b = qa_registers();
$GLOBALS['QA_ON_REGISTER'] = null;
ok('G13j', 'forced register sees the Reconnect lock held', $s === 'error' && $b === 1 && $GLOBALS['QA_LOCKED'] === true, "sync=$s register=$b locked=" . var_export($GLOBALS['QA_LOCKED'], true));

// G13l: the Reconnect lock outlives one register (15 s) plus one sync
// (15 s). While the register request runs, the stored expiry is moved back
// as if the lock was taken 44 s ago (still held) and then 46 s ago (gone),
// read with the plugin's own cg_register_reconnect_lock_held() (held while
// expiry >= time()). Checked for the Reconnect button and for Sync Now; the
// expiry is then put back so the caller's finally can release its own lock,
// and the row must be gone after each call.
// The ages are counted from the second the lock row was written (the
// INSERT seen by a query filter), not from the second the callback runs.
// On its own G13l pins the TTL to 44 or 45 s (TTL 44 still passes);
// G13e-ttl and L4c pin the constant to exactly 45.
foreach (['G13l-reconnect', 'G13l-syncnow'] as $gid) {
    qa_reset();
    cg_register_site_with_hq();
    $GLOBALS['QA_NOW'] = 1800000000 + 61; $GLOBALS['QA_LOG'] = [];
    $GLOBALS['QA_L'] = null;
    $GLOBALS['QA_LOCK_SET_AT'] = null;
    $qa_rec = function ($q) {
        if ($GLOBALS['QA_LOCK_SET_AT'] === null && preg_match('/^\s*INSERT\b/i', $q) && strpos($q, "'" . CG_REGISTER_RECONNECT_LOCK . "'") !== false) {
            $GLOBALS['QA_LOCK_SET_AT'] = time();
        }
        return $q;
    };
    add_filter('query', $qa_rec);
    $GLOBALS['QA_ON_REGISTER'] = function () {
        global $wpdb;
        $v = qa_rl_value();
        $set = $GLOBALS['QA_LOCK_SET_AT'];
        $now = time();
        if ($set === null || $v === null || !function_exists('cg_register_reconnect_lock_held')) {
            $GLOBALS['QA_L'] = ['set_at' => $set, 'value' => $v];
            return;
        }
        $ttl = $v - $set;
        $put = function ($val) use ($wpdb) {
            $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %d WHERE option_name = %s", $val, CG_REGISTER_RECONNECT_LOCK));
            qa_rl_flush();
        };
        // Expiry as it would look from now if the lock was taken $age s ago.
        $put($now - 44 + $ttl);
        $h44 = cg_register_reconnect_lock_held();
        $put($now - 46 + $ttl);
        $h46 = cg_register_reconnect_lock_held();
        $put($v);
        $GLOBALS['QA_L'] = ['ttl' => $ttl, 'waited' => $now - $set, 'h44' => $h44, 'h46' => $h46];
    };
    $r = $gid === 'G13l-reconnect' ? cg_register_reconnect_attempt() : qa_sync_now();
    $b = qa_registers();
    $GLOBALS['QA_ON_REGISTER'] = null;
    remove_filter('query', $qa_rec);
    $lock_after = qa_rl_value();
    $L = $GLOBALS['QA_L'];
    ok($gid, 'Reconnect lock held at +44 s, gone at +46 s, released after the call', !wp_using_ext_object_cache() && is_array($L) && ($L['h44'] ?? null) === true && ($L['h46'] ?? null) === false && $b === 1 && $lock_after === null, 'r=' . var_export($r, true) . " register=$b lock=" . json_encode($L) . ' lock_after=' . var_export($lock_after, true));
}

// G13k: only Sync Now may force. Across the plugin code (tests excluded) there
// is exactly one cg_sync_with_hq() call with an argument, and it is inside
// cg_handle_force_sync_ajax(). Cron, the SiteKey claim, activation, Reconnect,
// the usage-limit check, contact reveal and the admin refresh call it bare.
$forced = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(CG_PLUGIN_DIR, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $rel = substr($f->getPathname(), strlen(CG_PLUGIN_DIR));
    if (substr($rel, -4) !== '.php' || strpos($rel, 'tests/') === 0 || strpos($rel, 'vendor/') === 0) {
        continue;
    }
    $src = file_get_contents($f->getPathname());
    if (preg_match_all('/(?<!function )\bcg_sync_with_hq\s*\(\s*[^)\s]/', $src, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as $hit) {
            $before = substr($src, 0, $hit[1]);
            $fn = preg_match_all('/function\s+(\w+)\s*\(/', $before, $fm) ? end($fm[1]) : '';
            $forced[] = "$rel:$fn";
        }
    }
}
ok('G13k', 'only cg_handle_force_sync_ajax() passes force', $forced === ['includes/admin/admin-settings-page.php:cg_handle_force_sync_ajax'], 'forced=' . json_encode($forced));

$out = ob_get_clean();
// U1-U3: the register gate has an upper bound of 600 s (10 min), the same
// cap the status wait uses. A stored gate further ahead than that (a clock
// that jumped back, a bad Retry-After, a stale row) is ignored, so the site
// can never be locked out of registering for hours.
// U1: gate exactly at now + 600 s is still honoured: 2 saves -> 0 register.
qa_reset();
update_option('cg_register_next_allowed_at', 1800000000 + 600, false);
qa_checkout_save(1); qa_checkout_save(2);
ok('U1', 'gate at exactly now + 600 s is honoured: 2 saves -> 0 register', qa_registers() === 0, 'register=' . qa_registers());

// U2: gate at now + 601 s is past the bound and ignored: 2 saves -> exactly
// 1 register (the failed register then writes a normal 60 s gate).
qa_reset();
update_option('cg_register_next_allowed_at', 1800000000 + 601, false);
qa_checkout_save(1); qa_checkout_save(2);
$gate = (int) get_option('cg_register_next_allowed_at', 0);
ok('U2', 'gate at now + 601 s is ignored: 2 saves -> 1 register, new gate = now + 60 s', qa_registers() === 1 && $gate === 1800000000 + 60, 'register=' . qa_registers() . " gate=$gate");

// U3: a Retry-After above the bound is clamped when written: Retry-After
// 3600 stores now + 600 s; +599 s -> 0 register, +600 s -> exactly 1.
qa_reset();
$GLOBALS['QA_ROUTES'] = ['/sites/register' => ['code' => 429, 'ra' => '3600', 'body' => ['error' => 'Too many requests']]];
qa_checkout_save(1);
$a = qa_registers();
$gate = (int) get_option('cg_register_next_allowed_at', 0);
$GLOBALS['QA_NOW'] = 1800000000 + 599; $GLOBALS['QA_LOG'] = [];
qa_checkout_save(2);
$b = qa_registers();
$GLOBALS['QA_NOW'] = 1800000000 + 600; $GLOBALS['QA_LOG'] = [];
qa_checkout_save(3); qa_checkout_save(4);
$c = qa_registers();
ok('U3', 'Retry-After 3600 is clamped: gate = now + 600 s, +599 s -> 0, +600 s -> 1', $a === 1 && $gate === 1800000000 + 600 && $b === 0 && $c === 1, "first=$a gate=$gate at599=$b at600=$c");

// restore
qa_reset();
foreach ($GLOBALS['QA_ORIG'] as $name => $val) {
    if ($val === null) { delete_option($name); } elseif ($name === 'cg_plugin_options') { cg_update_options_from_hq($val); } else { update_option($name, $val); }
}
if ($GLOBALS['QA_CACHE_ORIG'] !== false) { set_transient('cg_api_cache', $GLOBALS['QA_CACHE_ORIG'], DAY_IN_SECONDS); }
wp_set_current_user($GLOBALS['QA_USER_ORIG']);
restore_error_handler();
$R = $GLOBALS['R'];
foreach ($R as $r) echo implode(' | ', $r), "\n";
echo 'warnings_total=' . count($GLOBALS['QA_WARN']) . "\n"; foreach (array_unique($GLOBALS['QA_WARN']) as $w) echo "  W: $w\n";
$f = count(array_filter($R, function ($r) { return $r[2] !== 'PASS'; })) + (count($GLOBALS['QA_WARN']) ? 1 : 0);
echo "SUMMARY " . (count($R) - $f) . '/' . count($R) . " PASS\n";
