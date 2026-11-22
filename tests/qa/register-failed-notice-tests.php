<?php
/**
 * Register failure notice (Sakib F3 on 1.5.0 ada4b91): when registration
 * fails (HQ down, 429, timeout) the site has no key and checks are off, so
 * admins see a plain notice on the dashboard and CheckoutGuard pages with a
 * gated "Reconnect now". A SiteKey refusal keeps only the SiteKey notice.
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
    $o = is_array($GLOBALS['QA_ORIG']['cg_plugin_options']) ? $GLOBALS['QA_ORIG']['cg_plugin_options'] : [];
    unset($o['api_key']);
    cg_update_options_from_hq($o);
    update_option('cg_license_status', 'active');
    $GLOBALS['QA_LOG'] = []; $GLOBALS['QA_ROUTES'] = []; $GLOBALS['QA_NOW'] = 1800000000;
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

// N3: 429 with Retry-After 3600 -> gate one hour, no button, try-again time
qa_reset();
$GLOBALS['QA_ROUTES'] = ['/sites/register' => ['code' => 429, 'ra' => '3600', 'body' => ['error' => 'Too Many Requests']]];
cg_register_site_with_hq();
$gate = (int) get_option('cg_register_next_allowed_at') - $GLOBALS['QA_NOW'];
$GLOBALS['QA_NOW'] += 120;
list($reg) = qa_notices();
ok('N3', '429 Retry-After 3600: gated an hour, no button', $gate === 3600 && strpos($reg, $TITLE) !== false && strpos($reg, 'action=cg_register_reconnect') === false && strpos($reg, 'try again after') !== false, "gate=$gate");

// N4: Reconnect attempt is gated, then sends one register and stores the key; notice gone
$GLOBALS['QA_LOG'] = [];
$g1 = cg_register_reconnect_attempt();
$calls_gated = count($GLOBALS['QA_LOG']);
$GLOBALS['QA_NOW'] += 3600;
$GLOBALS['QA_ROUTES'] = ['/sites/register' => ['code' => 201, 'body' => ['api_key' => 'qa-shared-key-not-real']], '/sites/status' => ['code' => 'down']];
$g2 = cg_register_reconnect_attempt();
$reg_calls = count(array_filter($GLOBALS['QA_LOG'], function ($p) { return $p === '/sites/register'; }));
$o = get_option('cg_plugin_options', []);
list($reg) = qa_notices();
ok('N4', 'Reconnect: gated, then one register, key stored, notice gone', $g1 === 'gated' && $calls_gated === 0 && $g2 === 'tried' && $reg_calls === 1 && ($o['api_key'] ?? '') === 'qa-shared-key-not-real' && $reg === '' && get_option('cg_register_next_allowed_at', null) === null, "g1=$g1 g2=$g2 register_calls=$reg_calls");

// N5: double click: the second attempt within the minute sends nothing
qa_reset();
cg_register_site_with_hq();
$GLOBALS['QA_NOW'] += 61; $GLOBALS['QA_LOG'] = [];
$a = cg_register_reconnect_attempt(); $b = cg_register_reconnect_attempt();
ok('N5', 'double click: one register only', $a === 'tried' && $b === 'gated' && count(array_filter($GLOBALS['QA_LOG'], function ($p) { return $p === '/sites/register'; })) === 1, "a=$a b=$b log=" . json_encode($GLOBALS['QA_LOG']));

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
$a = cg_register_reconnect_attempt(); $b = cg_register_reconnect_attempt();
$n = count(array_filter($GLOBALS['QA_LOG'], function ($p) { return $p === '/sites/register'; }));
ok('N10', '2xx without key: second reconnect gated, one register', $a === 'tried' && $b === 'gated' && $n === 1 && cg_register_failed(), "a=$a b=$b register_calls=$n");

// N11: same for "already taken" (register sets no gate on that path)
qa_reset();
cg_register_site_with_hq();
$GLOBALS['QA_NOW'] += 61; $GLOBALS['QA_LOG'] = [];
$GLOBALS['QA_ROUTES'] = ['/sites/register' => ['code' => 409, 'body' => ['error' => 'Domain already taken']], '/sites/status' => ['code' => 'down']];
$a = cg_register_reconnect_attempt(); $b = cg_register_reconnect_attempt();
$n = count(array_filter($GLOBALS['QA_LOG'], function ($p) { return $p === '/sites/register'; }));
ok('N11', 'already taken: second reconnect gated, one register', $a === 'tried' && $b === 'gated' && $n === 1, "a=$a b=$b register_calls=$n");

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
