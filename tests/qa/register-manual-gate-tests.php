<?php
/**
 * Manual register gate (OBS-PRERC-01, pre-RC smoke on dev cfaaaf1): with no
 * key, the admin's Reconnect and Sync Now passed the register gate every
 * time, so a Reconnect from an old tab 7 s after the last one, or Sync Now
 * clicked twice, sent a new POST /sites/register each time. The Reconnect
 * lock only stopped two at the same moment.
 *
 * Rule: a manual try (Reconnect or Sync Now) still passes the automatic
 * gate (Tanvir, 22 Nov), but each manual try opens a manual gate of
 * CG_REGISTER_MIN_GAP, or longer when HQ sent Retry-After (capped at
 * CG_REGISTER_MAX_WAIT). Inside it the next manual try sends nothing:
 * Reconnect returns 'gated', Sync Now answers code 'gated' with the time.
 * A key stored clears it. Activation still always registers.
 *
 * Run on a local test site with WooCommerce only (never production):
 *   wp eval-file tests/qa/register-manual-gate-tests.php
 * HTTP is answered by a pre_http_request filter in this process; nothing
 * leaves the machine. Options are restored at the end.
 */
if (!defined('ABSPATH')) {
    exit;
}
require_once __DIR__ . '/inc-reconnect-lock.php';
$GLOBALS['QA_MG'] = defined('CG_REGISTER_MANUAL_GATE_OPTION') ? CG_REGISTER_MANUAL_GATE_OPTION : 'cg_register_manual_next_allowed_at';
$GLOBALS['QA_NOW'] = 1800000000;
$GLOBALS['QA_ORIG'] = [];
foreach (['cg_plugin_options', 'cg_license_status', 'cg_register_next_allowed_at', 'cg_sitekey_state', $GLOBALS['QA_MG']] as $o) {
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
class QA_MG_Ajax_Done extends Exception {}
add_filter('wp_doing_ajax', '__return_true');
add_filter('wp_die_ajax_handler', function () { return function () { throw new QA_MG_Ajax_Done(); }; });
$GLOBALS['QA_WARN'] = [];
set_error_handler(function ($no, $str, $file, $line) {
    if (strpos($file, 'checkoutguard') !== false) {
        $GLOBALS['QA_WARN'][] = "$str @ " . basename($file) . ":$line";
    }
    return false;
});
$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
$GLOBALS['QA_ADMIN'] = (int) ($admins[0] ?? 1);

function qa_mg_reset() {
    global $wpdb;
    foreach (['cg_sitekey_state', 'cg_sitekey_next_allowed_at', 'cg_sitekey_lock', 'cg_register_next_allowed_at', 'cg_register_already_registered_at', $GLOBALS['QA_MG']] as $o) {
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
    // No key and a failed register: the state the Reconnect notice is for.
    update_option('cg_license_status', 'failed: cURL error 7: Failed to connect');
    $GLOBALS['QA_LOG'] = []; $GLOBALS['QA_ROUTES'] = []; $GLOBALS['QA_NOW'] = 1800000000;
    wp_set_current_user($GLOBALS['QA_ADMIN']);
}
// Sync Now (AJAX cg_handle_force_sync_ajax): [result, code, message].
function qa_mg_sync_now() {
    $_POST = $_REQUEST = ['nonce' => wp_create_nonce('cg_force_sync_ajax')];
    ob_start();
    try {
        cg_handle_force_sync_ajax();
    } catch (QA_MG_Ajax_Done $e) {
    }
    $j = json_decode((string) ob_get_clean(), true);
    $_POST = $_REQUEST = [];
    if (!is_array($j)) {
        return ['none', '', ''];
    }
    return [!empty($j['success']) ? 'ok' : 'error', (string) ($j['data']['code'] ?? ''), (string) ($j['data']['message'] ?? '')];
}
function qa_mg_registers() {
    return count(array_filter($GLOBALS['QA_LOG'], function ($p) { return $p === '/sites/register'; }));
}
function qa_mg_at($t) { $GLOBALS['QA_NOW'] = 1800000000 + $t; }
$GLOBALS['R'] = [];
function ok($id, $name, $pass, $detail) { $GLOBALS['R'][] = [$id, $name, $pass ? 'PASS' : 'FAIL', $detail]; }
ob_start();

// M1 (T1c): Reconnect, it fails; Reconnect again from an old tab at +7 s
// -> 'gated', no second register.
qa_mg_reset();
$a = cg_register_reconnect_attempt();
qa_mg_at(7);
$b = cg_register_reconnect_attempt();
ok('M1', 'Reconnect, then Reconnect again at +7 s: 1 register, second gated', $a === 'tried' && $b === 'gated' && qa_mg_registers() === 1, "a=$a b=$b register=" . qa_mg_registers());

// M2 (T1e): Sync Now twice inside 60 s -> 1 register; the second answers
// code 'gated' with a "try again after" message.
qa_mg_reset();
$s1 = qa_mg_sync_now();
qa_mg_at(20);
$s2 = qa_mg_sync_now();
ok('M2', 'Sync Now, then Sync Now at +20 s: 1 register, second gated with time', qa_mg_registers() === 1 && $s2[0] === 'error' && $s2[1] === 'gated' && stripos($s2[2], 'try again after') !== false, 's1=' . json_encode($s1) . ' s2=' . json_encode($s2) . ' register=' . qa_mg_registers());

// M3: Reconnect then Sync Now at +7 s, and the other way round -> 1 register each.
qa_mg_reset();
cg_register_reconnect_attempt(); qa_mg_at(7); $s = qa_mg_sync_now();
$x = qa_mg_registers();
qa_mg_reset();
qa_mg_sync_now(); qa_mg_at(7); $r = cg_register_reconnect_attempt();
$y = qa_mg_registers();
ok('M3', 'Reconnect -> Sync Now (+7 s) and Sync Now -> Reconnect (+7 s): 1 register each', $x === 1 && $s[1] === 'gated' && $y === 1 && $r === 'gated', "x=$x s=" . json_encode($s) . " y=$y r=$r");

// M4: edge. Manual gate ends at first try + 60 s: +59 s gated, +60 s sends one.
qa_mg_reset();
cg_register_reconnect_attempt();
qa_mg_at(59); $b = cg_register_reconnect_attempt(); $n59 = qa_mg_registers();
qa_mg_at(60); $c = cg_register_reconnect_attempt(); $n60 = qa_mg_registers();
ok('M4', 'manual gate edge: +59 s gated, +60 s exactly one more register', $b === 'gated' && $n59 === 1 && $c === 'tried' && $n60 === 2, "b=$b n59=$n59 c=$c n60=$n60");

// M5 (22 Nov rule kept): a gate left by an automatic register does not hold
// the admin's first manual try.
qa_mg_reset();
cg_register_site_with_hq();
qa_mg_at(10); $GLOBALS['QA_LOG'] = [];
$auto_open = cg_register_gate_left() > 0;
$a = cg_register_reconnect_attempt();
$n1 = qa_mg_registers();
qa_mg_reset();
cg_register_site_with_hq();
qa_mg_at(10); $GLOBALS['QA_LOG'] = [];
$auto_open2 = cg_register_gate_left() > 0;
$s = qa_mg_sync_now();
$n2 = qa_mg_registers();
ok('M5', 'automatic gate open: first Reconnect / first Sync Now still send 1 register', $auto_open && $auto_open2 && $a === 'tried' && $n1 === 1 && $s[1] !== 'gated' && $n2 === 1, 'auto_open=' . var_export($auto_open && $auto_open2, true) . " a=$a n1=$n1 sync=" . json_encode($s) . " n2=$n2");

// M6: HQ answers 429 with Retry-After 300 to a Reconnect: the next manual
// try waits the 300 s too (+299 gated, +300 one register).
qa_mg_reset();
$GLOBALS['QA_ROUTES'] = ['/sites/register' => ['code' => 429, 'ra' => '300', 'body' => ['error' => 'Too many requests']]];
cg_register_reconnect_attempt();
qa_mg_at(299); $b = cg_register_reconnect_attempt(); $n1 = qa_mg_registers();
qa_mg_at(300); $c = cg_register_reconnect_attempt(); $n2 = qa_mg_registers();
ok('M6', 'Retry-After 300 on a Reconnect: +299 s gated, +300 s one more', $b === 'gated' && $n1 === 1 && $c === 'tried' && $n2 === 2, "b=$b n1=$n1 c=$c n2=$n2 mg=" . (int) get_option($GLOBALS['QA_MG'], 0));

// M7: cap. A manual gate more than CG_REGISTER_MAX_WAIT ahead is ignored.
qa_mg_reset();
update_option($GLOBALS['QA_MG'], 1800000000 + CG_REGISTER_MAX_WAIT + 1, false);
$a = cg_register_reconnect_attempt();
qa_mg_reset();
update_option($GLOBALS['QA_MG'], 1800000000 + CG_REGISTER_MAX_WAIT, false);
$b = cg_register_reconnect_attempt();
ok('M7', 'manual gate at now+601 s ignored (tried), at now+600 s honoured (gated)', $a === 'tried' && $b === 'gated', "a=$a b=$b");

// M8: a successful Reconnect stores the key and clears the manual gate.
qa_mg_reset();
$GLOBALS['QA_ROUTES'] = ['/sites/register' => ['code' => 201, 'body' => ['api_key' => 'qa-shared-key-not-real']]];
$a = cg_register_reconnect_attempt();
$o = get_option('cg_plugin_options', []);
$mg = get_option($GLOBALS['QA_MG'], null);
ok('M8', 'Reconnect succeeds: key stored, manual gate cleared', $a === 'tried' && ($o['api_key'] ?? '') === 'qa-shared-key-not-real' && $mg === null, "a=$a mg=" . var_export($mg, true));

// M9: activation still always registers, even inside the manual gate.
qa_mg_reset();
cg_register_reconnect_attempt();
qa_mg_at(10); $GLOBALS['QA_LOG'] = [];
cg_register_site_with_hq(true);
ok('M9', 'manual gate open: activation (force) still sends 1 register', qa_mg_registers() === 1, 'register=' . qa_mg_registers());

// M10: a gated Reconnect does not move the manual gate (old tab clicks do
// not push the next allowed time further out).
qa_mg_reset();
cg_register_reconnect_attempt();
$g1 = (int) get_option($GLOBALS['QA_MG'], 0);
qa_mg_at(30); cg_register_reconnect_attempt(); qa_mg_sync_now();
$g2 = (int) get_option($GLOBALS['QA_MG'], 0);
ok('M10', 'gated tries leave the manual gate where it was', $g1 === 1800000000 + CG_REGISTER_MIN_GAP && $g2 === $g1, "g1=$g1 g2=$g2");

// M11: the notices hide the Reconnect button while the manual gate is open.
qa_mg_reset();
update_option('cg_register_next_allowed_at', 0, false);
update_option($GLOBALS['QA_MG'], 1800000000 + 30, false);
$_GET['page'] = 'cg-settings';
ob_start(); cg_render_register_failed_notice(); $html = (string) ob_get_clean();
unset($_GET['page']);
ok('M11', 'failed notice inside the manual gate: no Reconnect button, shows try again', $html !== '' && strpos($html, 'cg_register_reconnect') === false && stripos($html, 'try again after') !== false, 'html=' . substr(wp_strip_all_tags($html), 0, 160));

// M12: uninstall removes the manual gate option.
$un = (string) file_get_contents(CG_PLUGIN_DIR . 'uninstall.php');
ok('M12', 'uninstall.php deletes the manual gate option', strpos($un, "'" . $GLOBALS['QA_MG'] . "'") !== false, 'found=' . var_export(strpos($un, $GLOBALS['QA_MG']) !== false, true));

// M13: Sync Now edge on a 409 "Domain already taken" answer: +59 s gated,
// +60 s sends one.
qa_mg_reset();
$GLOBALS['QA_ROUTES'] = ['/sites/register' => ['code' => 409, 'body' => ['error' => 'Domain already taken']]];
qa_mg_sync_now();
$auto = cg_register_gate_left();
qa_mg_at(CG_REGISTER_MIN_GAP - 1); $s59 = qa_mg_sync_now(); $n59 = qa_mg_registers();
qa_mg_at(CG_REGISTER_MIN_GAP); $s60 = qa_mg_sync_now(); $n60 = qa_mg_registers();
ok('M13', 'already taken via Sync Now: +59 s gated, +60 s exactly one more', $s59[1] === 'gated' && $n59 === 1 && $s60[1] !== 'gated' && $n60 === 2, "auto_left=$auto s59=" . json_encode($s59) . " n59=$n59 s60=" . json_encode($s60) . " n60=$n60");

// M14: a gated Sync Now answers through wp_send_json_error (exit), so it
// must release the Reconnect lock itself first.
qa_mg_reset();
qa_mg_sync_now();
qa_mg_at(20); $s = qa_mg_sync_now();
$held = cg_register_reconnect_lock_held();
ok('M14', 'gated Sync Now leaves no Reconnect lock behind', $s[1] === 'gated' && !$held, 's=' . json_encode($s) . ' held=' . var_export($held, true));

// M15: uninstall deletes the manual gate wherever it deletes the automatic
// gate (single site and each site of a network).
$c_auto = substr_count($un, "delete_option('cg_register_next_allowed_at')");
$c_man = substr_count($un, "delete_option('" . $GLOBALS['QA_MG'] . "')");
ok('M15', 'uninstall.php: manual gate deleted as often as the automatic gate', $c_auto > 0 && $c_man === $c_auto, "auto=$c_auto manual=$c_man");

$out = ob_get_clean();
// restore
qa_mg_reset();
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
