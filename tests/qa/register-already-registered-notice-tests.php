<?php
/**
 * Already-registered notice (CG-151 follow-up, spec from Fahim/Tanvir 22 Nov).
 * HQ answers a register for a domain that already has a key with 409
 * {"error":"already_registered"} (older HQ: "... already taken"). The status
 * is kept (e557a83), but the site still has no key, so the admin must see it:
 *
 * A1  409 already_registered: cg_register_already_registered_at > 0, not
 *     autoloaded; status kept; register gate written.
 * A2  admin_notices on the dashboard has its own error notice that says
 *     "already registered", never the word "key"; after the gate it has a
 *     Reconnect button (nonce link).
 * A3  a later register that stores a key deletes the option; notice gone.
 * A3k a key saved on the settings page (cg_plugin_options api_key) deletes
 *     the option; notice gone.
 * A4  old HQ "Domain already taken": same option and notice (A4n); status
 *     kept (A4s, already true before the notice fix).
 * A5  HQ down: no option, no already-registered notice (the plain register
 *     failed notice is unchanged).
 * A6  not shown to non-admins.
 * A7  the Reconnect button works: after the gate, cg_register_reconnect_attempt()
 *     sends one register (not 'not_needed').
 * A8  status already 'failed: ...' (HQ was down), then 409 already_registered:
 *     exactly one error notice, the already-registered one, with Reconnect
 *     (the plain failed notice is not shown next to it).
 *
 * Run on a local test site only (never production):
 *   wp eval-file tests/qa/register-already-registered-notice-tests.php
 * HTTP is answered by a pre_http_request filter in this process; nothing
 * leaves the machine. Options are restored at the end.
 */
if (!defined('ABSPATH')) {
    exit;
}
$GLOBALS['QA_NOW'] = 1800000000;
$GLOBALS['QA_ORIG'] = [];
$GLOBALS['QA_OPT'] = 'cg_register_already_registered_at';
foreach (['cg_plugin_options', 'cg_license_status', 'cg_register_next_allowed_at', 'cg_sitekey_state', $GLOBALS['QA_OPT']] as $o) {
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
    return ['headers' => new WpOrg\Requests\Utility\CaseInsensitiveDictionary([]), 'body' => json_encode($r['body'] ?? []), 'response' => ['code' => $r['code'], 'message' => ''], 'cookies' => [], 'filename' => null];
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

$GLOBALS['QA_AR'] = ['/sites/register' => ['code' => 409, 'body' => ['error' => 'already_registered']], '/sites/status' => ['code' => 'down']];
$GLOBALS['QA_AT'] = ['/sites/register' => ['code' => 409, 'body' => ['error' => 'Domain already taken']], '/sites/status' => ['code' => 'down']];
$GLOBALS['QA_OK'] = ['/sites/register' => ['code' => 201, 'body' => ['api_key' => 'qa-shared-key-not-real']], '/sites/status' => ['code' => 'down']];

function qa_reset() {
    global $wpdb;
    foreach (['cg_sitekey_state', 'cg_sitekey_next_allowed_at', 'cg_sitekey_lock', 'cg_register_next_allowed_at', $GLOBALS['QA_OPT']] as $o) {
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
// Raw row: value and autoload, read from the table (not the cache).
function qa_opt_row() {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare("SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $GLOBALS['QA_OPT']), ARRAY_A);
}
function qa_opt_set($row) {
    return $row !== null && (int) $row['option_value'] > 0 && in_array($row['autoload'], ['no', 'off'], true);
}
// Precondition for A3/A3k: the option is there (autoload is A1's job).
function qa_opt_present($row) {
    return $row !== null && (int) $row['option_value'] > 0;
}
function qa_opt_fmt($row) {
    return $row === null ? 'none' : ('value=' . $row['option_value'] . ' autoload=' . $row['autoload']);
}
// Everything admin_notices prints, split into top-level notice blocks.
function qa_notice_blocks() {
    ob_start();
    do_action('admin_notices');
    $html = ob_get_clean();
    $out = [];
    if (trim($html) === '') {
        return $out;
    }
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>');
    libxml_clear_errors();
    $xp = new DOMXPath($doc);
    foreach ($xp->query('//div[contains(concat(" ", normalize-space(@class), " "), " notice ")][not(ancestor::div[contains(concat(" ", normalize-space(@class), " "), " notice ")])]') as $n) {
        $out[] = ['class' => (string) $n->getAttribute('class'), 'html' => $doc->saveHTML($n), 'text' => trim(preg_replace('/\s+/', ' ', $n->textContent))];
    }
    return $out;
}
// The already-registered notice: an error notice whose text says "already registered".
function qa_ar_notice() {
    foreach (qa_notice_blocks() as $b) {
        if (stripos($b['text'], 'already registered') !== false) {
            return $b;
        }
    }
    return null;
}
function qa_ar_ok($b) {
    return $b !== null && strpos(' ' . $b['class'] . ' ', ' notice-error ') !== false && !preg_match('/\bkeys?\b/i', $b['text']);
}
function qa_ar_fmt($b) {
    return $b === null ? 'notice=none' : ('class="' . $b['class'] . '" key_word=' . (preg_match('/\bkeys?\b/i', $b['text']) ? 'yes' : 'no') . ' button=' . (strpos($b['html'], 'action=cg_register_reconnect') !== false ? 'yes' : 'no'));
}
function qa_has_button($b) {
    return $b !== null && strpos($b['html'], 'action=cg_register_reconnect') !== false && strpos($b['html'], '_wpnonce=') !== false;
}
function qa_registers() {
    return count(array_filter($GLOBALS['QA_LOG'], function ($p) { return $p === '/sites/register'; }));
}
$GLOBALS['R'] = [];
function ok($id, $name, $pass, $detail) { $GLOBALS['R'][] = [$id, $name, $pass ? 'PASS' : 'FAIL', $detail]; }

// A1 / A2: 409 already_registered
qa_reset();
$GLOBALS['QA_ROUTES'] = $GLOBALS['QA_AR'];
cg_register_site_with_hq();
$row = qa_opt_row();
$status = get_option('cg_license_status');
$gate = (int) get_option('cg_register_next_allowed_at', 0) - $GLOBALS['QA_NOW'];
ok('A1', '409 already_registered: option set (> 0, autoload no), status kept, gate written', qa_opt_set($row) && $status === 'active' && $gate > 0, qa_opt_fmt($row) . " status=$status gate=+$gate");
$b = qa_ar_notice();
ok('A2', 'already_registered: own error notice says "already registered", no word "key"', qa_ar_ok($b), qa_ar_fmt($b));
$GLOBALS['QA_NOW'] += 61;
$b = qa_ar_notice();
ok('A2b', 'already_registered: after the gate the notice has a Reconnect button (nonce link)', qa_ar_ok($b) && qa_has_button($b), qa_ar_fmt($b));

// A7: the button works: one register, not 'not_needed'
$GLOBALS['QA_LOG'] = [];
$a = cg_register_reconnect_attempt();
$n = qa_registers();
ok('A7', 'already_registered: Reconnect after the gate sends one register', $a === 'tried' && $n === 1, "attempt=$a register_calls=$n");

// A3: a later register that stores a key clears the option and the notice
$before = qa_opt_row();
$GLOBALS['QA_NOW'] += 61;
$GLOBALS['QA_ROUTES'] = $GLOBALS['QA_OK'];
cg_register_site_with_hq(true);
$o = get_option('cg_plugin_options', []);
$row = qa_opt_row();
$b = qa_ar_notice();
ok('A3', 'register stores a key: option deleted, notice gone', qa_opt_present($before) && ($o['api_key'] ?? '') === 'qa-shared-key-not-real' && $row === null && $b === null, 'before ' . qa_opt_fmt($before) . ', after ' . qa_opt_fmt($row) . ' ' . qa_ar_fmt($b));

// A3k: a key saved on the settings page clears the option and the notice
qa_reset();
$GLOBALS['QA_ROUTES'] = $GLOBALS['QA_AR'];
cg_register_site_with_hq();
$before = qa_opt_row();
$o = get_option('cg_plugin_options', []); $o['api_key'] = 'qa-shared-key-not-real'; update_option('cg_plugin_options', $o);
$row = qa_opt_row();
$b = qa_ar_notice();
ok('A3k', 'key saved in settings: option deleted, notice gone', qa_opt_present($before) && $row === null && $b === null, 'before ' . qa_opt_fmt($before) . ', after ' . qa_opt_fmt($row) . ' ' . qa_ar_fmt($b));

// A4: old HQ "already taken"
qa_reset();
$GLOBALS['QA_ROUTES'] = $GLOBALS['QA_AT'];
cg_register_site_with_hq();
$status = get_option('cg_license_status');
ok('A4s', 'already taken (old HQ): status kept (control, true before the notice fix)', $status === 'active', "status=$status");
$row = qa_opt_row();
$GLOBALS['QA_NOW'] += 61;
$b = qa_ar_notice();
ok('A4n', 'already taken (old HQ): option set, same notice with Reconnect', qa_opt_set($row) && qa_ar_ok($b) && qa_has_button($b), qa_opt_fmt($row) . ' ' . qa_ar_fmt($b));

// A5: HQ down is a different failure: no option, no already-registered notice
qa_reset();
cg_register_site_with_hq();
$row = qa_opt_row();
$b = qa_ar_notice();
ok('A5', 'HQ down: no option, no already-registered notice (plain failed notice only)', $row === null && $b === null && cg_register_failed(), qa_opt_fmt($row) . ' ' . qa_ar_fmt($b));

// A6: non-admins do not see it
qa_reset();
$GLOBALS['QA_ROUTES'] = $GLOBALS['QA_AR'];
cg_register_site_with_hq();
$admin_sees = qa_ar_notice() !== null;
wp_set_current_user(0);
$b = qa_ar_notice();
wp_set_current_user($GLOBALS['QA_ADMIN']);
ok('A6', 'hidden for non-admins (admin sees it)', $admin_sees && $b === null, 'admin_sees=' . var_export($admin_sees, true) . ' guest ' . qa_ar_fmt($b));

// A8: register failed first (HQ down), then 409 already_registered: one error notice only
qa_reset();
cg_register_site_with_hq();
$failed_first = cg_register_failed();
$GLOBALS['QA_NOW'] += 61;
$GLOBALS['QA_ROUTES'] = $GLOBALS['QA_AR'];
cg_register_site_with_hq();
$status = get_option('cg_license_status');
$GLOBALS['QA_NOW'] += 61;
$errs = array_values(array_filter(qa_notice_blocks(), function ($b) { return strpos(' ' . $b['class'] . ' ', ' notice-error ') !== false; }));
$one = count($errs) === 1 ? $errs[0] : null;
ok('A8', 'failed status, then already_registered: exactly 1 error notice, the already-registered one, with Reconnect', $failed_first && strpos((string) $status, 'failed') === 0 && $one !== null && stripos($one['text'], 'already registered') !== false && qa_ar_ok($one) && qa_has_button($one), 'failed_first=' . var_export($failed_first, true) . ' status=' . substr((string) $status, 0, 7) . ' error_notices=' . count($errs) . ' ' . qa_ar_fmt($one));

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
