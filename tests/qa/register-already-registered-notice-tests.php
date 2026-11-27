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
 * A9  409 already_registered first, then a different failure: 503 (A9a),
 *     2xx with no key (A9b) or HQ down (A9c). The option is deleted, the
 *     already-registered notice is gone and the plain failed notice is the
 *     one error notice left.
 * A10 option present and cg_plugin_options has an api_key (the option was
 *     left behind by a path that skipped the settings hook): no
 *     already-registered notice.
 * A11 Reconnect lock held (a Reconnect is running): the notice says
 *     Reconnect is running and has no Reconnect button (same as the failed
 *     notice, L2).
 * A12 a stored gate further ahead than CG_REGISTER_MAX_WAIT: Reconnect
 *     button, no try-again time (same as the failed notice, N12).
 * A13 the reason field alone is not enough: HTTP 422 (A13a) or 400 (A13b)
 *     with {"error":"already_registered","message":...} is a plain refusal
 *     (no option, no already-registered notice, failed notice); the same
 *     body with 409 is already registered (A13c); 422 after an
 *     already-registered answer deletes the option (A13d).
 * A13e/A13f the same as A13a/A13b with a bare body {"error":"already_registered"}
 *     (no "message", so the error message is the reason itself).
 * A13g HTTP 409 with only {"message":"already_registered"} (no "error"
 *     field): still already registered (option set, already-registered
 *     notice, status kept, gate written).
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
    delete_transient('cg_register_reconnect_lock');
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

// A9: already registered, then another failure: the old note goes, the failed notice shows
foreach ([
    ['A9a', 'HQ 503', ['/sites/register' => ['code' => 503, 'body' => ['error' => 'unavailable']], '/sites/status' => ['code' => 'down']]],
    ['A9b', '2xx with no key', ['/sites/register' => ['code' => 201, 'body' => ['ok' => true]], '/sites/status' => ['code' => 'down']]],
    ['A9c', 'HQ down', ['/sites/register' => ['code' => 'down'], '/sites/status' => ['code' => 'down']]],
] as $c) {
    qa_reset();
    $GLOBALS['QA_ROUTES'] = $GLOBALS['QA_AR'];
    cg_register_site_with_hq();
    $before = qa_opt_row();
    $GLOBALS['QA_NOW'] += 61;
    $GLOBALS['QA_ROUTES'] = $c[2];
    cg_register_site_with_hq();
    $n = qa_registers();
    $row = qa_opt_row();
    $b = qa_ar_notice();
    $errs = array_values(array_filter(qa_notice_blocks(), function ($x) { return strpos(' ' . $x['class'] . ' ', ' notice-error ') !== false; }));
    $failed_notice = count($errs) === 1 && stripos($errs[0]['text'], 'could not connect') !== false;
    ok($c[0], 'already_registered, then ' . $c[1] . ': option deleted, failed notice is the one error notice', qa_opt_present($before) && $n === 2 && $row === null && $b === null && cg_register_failed() && $failed_notice, "registers=$n before " . qa_opt_fmt($before) . ', after ' . qa_opt_fmt($row) . ' ' . qa_ar_fmt($b) . ' error_notices=' . count($errs) . ' failed_notice=' . ($failed_notice ? 'yes' : 'no'));
}

// A10: the option is still there but the site has a key: no already-registered notice
qa_reset();
$GLOBALS['QA_ROUTES'] = $GLOBALS['QA_AR'];
cg_register_site_with_hq();
$o = get_option('cg_plugin_options', []);
$o = is_array($o) ? $o : [];
$o['api_key'] = 'qa-shared-key-not-real';
cg_update_options_from_hq($o);
// The settings hook deleted it; put it back as if another path had skipped the hook.
update_option($GLOBALS['QA_OPT'], $GLOBALS['QA_NOW'], false);
$row = qa_opt_row();
$b = qa_ar_notice();
ok('A10', 'option present but api_key set: no already-registered notice', qa_opt_present($row) && !cg_register_already_registered() && $b === null, qa_opt_fmt($row) . ' ' . qa_ar_fmt($b));

// A11: a Reconnect is running (lock held): "running" text, no button
qa_reset();
$GLOBALS['QA_ROUTES'] = $GLOBALS['QA_AR'];
cg_register_site_with_hq();
$GLOBALS['QA_NOW'] += 61;
set_transient('cg_register_reconnect_lock', 1, 45);
$b = qa_ar_notice();
delete_transient('cg_register_reconnect_lock');
$running = $b !== null && strpos($b['text'], 'Reconnect is running. Reload this page in a few seconds.') !== false;
ok('A11', 'already_registered, Reconnect lock held: notice says Reconnect is running, no Reconnect button', qa_ar_ok($b) && $running && !qa_has_button($b), qa_ar_fmt($b) . ' running_text=' . ($running ? 'yes' : 'no'));

// A12: gate further ahead than the 600 s cap is not trusted
qa_reset();
$GLOBALS['QA_ROUTES'] = $GLOBALS['QA_AR'];
cg_register_site_with_hq();
update_option('cg_register_next_allowed_at', $GLOBALS['QA_NOW'] + 3600, false);
$b = qa_ar_notice();
$try = $b !== null && stripos($b['text'], 'try again after') !== false;
ok('A12', 'already_registered, gate 3600 s ahead (over the 600 s cap): Reconnect button, no try-again time', qa_ar_ok($b) && qa_has_button($b) && !$try, qa_ar_fmt($b) . ' try_again=' . ($try ? 'yes' : 'no'));

// A13: the already-registered path needs HTTP 409. Another status with the
// same "error" field (and a "message" next to it, so the message is not the
// bare reason) is a plain refusal: no option, no already-registered notice,
// status failed, the plain failed notice. A13c is the 409 control with the
// same body; A13d starts from an already-registered site.
function qa_a13_route($code) {
    return ['/sites/register' => ['code' => $code, 'body' => ['error' => 'already_registered', 'message' => 'Request refused']], '/sites/status' => ['code' => 'down']];
}
function qa_a13_errs() {
    return array_values(array_filter(qa_notice_blocks(), function ($x) { return strpos(' ' . $x['class'] . ' ', ' notice-error ') !== false; }));
}
foreach ([['A13a', 422], ['A13b', 400]] as $c) {
    qa_reset();
    $GLOBALS['QA_ROUTES'] = qa_a13_route($c[1]);
    cg_register_site_with_hq();
    $status = (string) get_option('cg_license_status');
    $row = qa_opt_row();
    $GLOBALS['QA_NOW'] += 61;
    $b = qa_ar_notice();
    $errs = qa_a13_errs();
    $failed_notice = count($errs) === 1 && stripos($errs[0]['text'], 'could not connect') !== false;
    ok($c[0], 'HTTP ' . $c[1] . ' with error already_registered: no option, no already-registered notice, status failed, failed notice', $row === null && $b === null && strpos($status, 'failed') === 0 && cg_register_failed() && $failed_notice, 'status=' . substr($status, 0, 7) . ' ' . qa_opt_fmt($row) . ' ' . qa_ar_fmt($b) . ' error_notices=' . count($errs) . ' failed_notice=' . ($failed_notice ? 'yes' : 'no'));
}
// A13e/A13f: the same with a bare body (no "message"): the client then uses
// "already_registered" as the error message, and that alone must not count
// without HTTP 409 either.
foreach ([['A13e', 422], ['A13f', 400]] as $c) {
    qa_reset();
    $GLOBALS['QA_ROUTES'] = ['/sites/register' => ['code' => $c[1], 'body' => ['error' => 'already_registered']], '/sites/status' => ['code' => 'down']];
    cg_register_site_with_hq();
    $status = (string) get_option('cg_license_status');
    $row = qa_opt_row();
    $GLOBALS['QA_NOW'] += 61;
    $b = qa_ar_notice();
    $errs = qa_a13_errs();
    $failed_notice = count($errs) === 1 && stripos($errs[0]['text'], 'could not connect') !== false;
    ok($c[0], 'HTTP ' . $c[1] . ' with a bare {"error":"already_registered"}: no option, no already-registered notice, status failed, failed notice', $row === null && $b === null && strpos($status, 'failed') === 0 && cg_register_failed() && $failed_notice, 'status=' . substr($status, 0, 7) . ' ' . qa_opt_fmt($row) . ' ' . qa_ar_fmt($b) . ' error_notices=' . count($errs) . ' failed_notice=' . ($failed_notice ? 'yes' : 'no'));
}
qa_reset();
$GLOBALS['QA_ROUTES'] = qa_a13_route(409);
cg_register_site_with_hq();
$status = (string) get_option('cg_license_status');
$row = qa_opt_row();
$GLOBALS['QA_NOW'] += 61;
$b = qa_ar_notice();
ok('A13c', 'HTTP 409 with the same body (control): option set, already-registered notice, status kept', qa_opt_set($row) && qa_ar_ok($b) && $status === 'active', "status=$status " . qa_opt_fmt($row) . ' ' . qa_ar_fmt($b));
// A13g: 409 with only a "message" field that says already_registered (no
// "error" field). The status is 409, so this is the already-registered path.
qa_reset();
$GLOBALS['QA_ROUTES'] = ['/sites/register' => ['code' => 409, 'body' => ['message' => 'already_registered']], '/sites/status' => ['code' => 'down']];
cg_register_site_with_hq();
$status = (string) get_option('cg_license_status');
$row = qa_opt_row();
$gate = (int) get_option('cg_register_next_allowed_at', 0) - $GLOBALS['QA_NOW'];
$GLOBALS['QA_NOW'] += 61;
$b = qa_ar_notice();
ok('A13g', 'HTTP 409 with only {"message":"already_registered"}: option set, already-registered notice, status kept, gate written', qa_opt_set($row) && qa_ar_ok($b) && $status === 'active' && $gate > 0, "status=$status gate=+$gate " . qa_opt_fmt($row) . ' ' . qa_ar_fmt($b));
qa_reset();
$GLOBALS['QA_ROUTES'] = $GLOBALS['QA_AR'];
cg_register_site_with_hq();
$before = qa_opt_row();
$GLOBALS['QA_NOW'] += 61;
$GLOBALS['QA_ROUTES'] = qa_a13_route(422);
cg_register_site_with_hq();
$n = qa_registers();
$row = qa_opt_row();
$b = qa_ar_notice();
$errs = qa_a13_errs();
$failed_notice = count($errs) === 1 && stripos($errs[0]['text'], 'could not connect') !== false;
ok('A13d', 'already_registered, then HTTP 422 with error already_registered: option deleted, failed notice is the one error notice', qa_opt_present($before) && $n === 2 && $row === null && $b === null && cg_register_failed() && $failed_notice, "registers=$n before " . qa_opt_fmt($before) . ', after ' . qa_opt_fmt($row) . ' ' . qa_ar_fmt($b) . ' error_notices=' . count($errs) . ' failed_notice=' . ($failed_notice ? 'yes' : 'no'));

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
