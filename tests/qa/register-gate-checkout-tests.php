<?php
/**
 * Register gate on the checkout path (BUG-01 on dev b2bae0b): with no
 * key and HQ unreachable, every guest checkout save that misses the plan
 * cache ran cg_handle_save_checkout_data() -> cg_can_perform_action() ->
 * cg_sync_with_hq() -> cg_register_site_with_hq(), and the register gate
 * (cg_register_next_allowed_at) was written but never read, so one checkout
 * sent several POST /sites/register. Rule: at most one register per gate
 * (60 s, or Retry-After) on this path. The admin's Reconnect ignores the
 * gate (like Sync now); a failed Reconnect writes a new gate, a good one clears it.
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
$GLOBALS['QA_NOW'] = 1800000000;
$GLOBALS['QA_ORIG'] = [];
foreach (['cg_plugin_options', 'cg_license_status', 'cg_register_next_allowed_at', 'cg_sitekey_state'] as $o) {
    $GLOBALS['QA_ORIG'][$o] = get_option($o, null);
}
$GLOBALS['QA_CACHE_ORIG'] = get_transient('cg_api_cache');
$GLOBALS['QA_USER_ORIG'] = get_current_user_id();
add_filter('cg_sitekey_now', function () { return $GLOBALS['QA_NOW']; });
$GLOBALS['QA_LOG'] = []; $GLOBALS['QA_ROUTES'] = [];
add_filter('pre_http_request', function ($pre, $args, $url) {
    $path = preg_replace('#^.*?/api/v1#', '', (string) parse_url($url, PHP_URL_PATH));
    $GLOBALS['QA_LOG'][] = $path;
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
    foreach (['cg_sitekey_state', 'cg_sitekey_next_allowed_at', 'cg_sitekey_lock', 'cg_register_next_allowed_at'] as $o) {
        $wpdb->delete($wpdb->options, ['option_name' => $o]);
        wp_cache_delete($o, 'options');
    }
    wp_cache_delete('notoptions', 'options'); wp_cache_delete('alloptions', 'options');
    wp_clear_scheduled_hook('cg_sitekey_request_event');
    delete_transient('cg_api_cache');
    $o = is_array($GLOBALS['QA_ORIG']['cg_plugin_options']) ? $GLOBALS['QA_ORIG']['cg_plugin_options'] : [];
    unset($o['api_key']);
    cg_update_options_from_hq($o);
    update_option('cg_license_status', 'active');
    $GLOBALS['QA_LOG'] = []; $GLOBALS['QA_ROUTES'] = []; $GLOBALS['QA_NOW'] = 1800000000;
    wp_set_current_user(0);
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
