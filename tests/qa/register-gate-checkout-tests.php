<?php
/**
 * Register gate on the checkout path (BUG-01 on dev b2bae0b): with no
 * key and HQ unreachable, every guest checkout save that misses the plan
 * cache ran cg_handle_save_checkout_data() -> cg_can_perform_action() ->
 * cg_sync_with_hq() -> cg_register_site_with_hq(), and the register gate
 * (cg_register_next_allowed_at) was written but never read, so one checkout
 * sent several POST /sites/register. Rule: at most one register per gate
 * (60 s, or Retry-After) on this path; the admin's Reconnect still works.
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

// G7: the admin's Reconnect still sends exactly one register once the gate has passed.
qa_reset();
qa_checkout_save(1); $GLOBALS['QA_NOW'] += 61; $GLOBALS['QA_LOG'] = [];
wp_set_current_user($GLOBALS['QA_ADMIN']);
$a = cg_register_reconnect_attempt(); $b = cg_register_reconnect_attempt();
ok('G7', 'Reconnect after the gate: tried then gated, exactly one register', $a === 'tried' && $b === 'gated' && qa_registers() === 1, "a=$a b=$b register=" . qa_registers());

// G8: register succeeds -> key stored, gate cleared, later saves send no register.
qa_reset();
$GLOBALS['QA_ROUTES'] = ['/sites/register' => ['code' => 201, 'body' => ['api_key' => 'qa-shared-key-not-real']]];
qa_checkout_save(1); $GLOBALS['QA_NOW'] += 5; qa_checkout_save(2);
$o = get_option('cg_plugin_options', []);
ok('G8', 'HQ up: one register, key stored, no second register', qa_registers() === 1 && ($o['api_key'] ?? '') === 'qa-shared-key-not-real' && get_option('cg_register_next_allowed_at', null) === null, 'register=' . qa_registers());

$out = ob_get_clean();
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
