<?php
/**
 * X-CG-Version header (1.5.0 F4): every request the plugin sends to HQ
 * carries X-CG-Version equal to CG_VERSION, so HQ can tell plugin versions
 * apart (support, rollout, compatibility rules).
 *
 * Run on a local test site only (never production):
 *   wp eval-file tests/qa/hq-version-header-tests.php
 * HTTP is answered by a pre_http_request filter in this process; nothing
 * leaves the machine. Options are restored at the end.
 */
if (!defined('ABSPATH')) {
    exit;
}
$GLOBALS['QA_ORIG'] = [];
foreach (['cg_plugin_options', 'cg_license_status'] as $o) {
    $GLOBALS['QA_ORIG'][$o] = get_option($o, null);
}
$GLOBALS['QA_REQ'] = [];
$GLOBALS['QA_OTHER'] = [];
add_filter('pre_http_request', function ($pre, $args, $url) {
    $hq = rtrim((string) CG_HEADQUARTERS_URL, '/');
    $headers = isset($args['headers']) && is_array($args['headers']) ? $args['headers'] : [];
    if (strpos((string) $url, $hq . '/') === 0) {
        $GLOBALS['QA_REQ'][] = ['path' => preg_replace('#^.*?/api/v1#', '', (string) parse_url($url, PHP_URL_PATH)), 'headers' => $headers];
    } else {
        $GLOBALS['QA_OTHER'][] = ['host' => (string) parse_url($url, PHP_URL_HOST), 'headers' => $headers];
    }
    return new WP_Error('http_request_failed', 'cURL error 7: Failed to connect to 127.0.0.1 port 8099');
}, PHP_INT_MAX, 3);

$GLOBALS['QA_PASS'] = 0; $GLOBALS['QA_FAIL'] = 0;
function ok($id, $name, $cond, $detail = '') {
    if ($cond) { $GLOBALS['QA_PASS']++; echo "$id | PASS | $name\n"; }
    else { $GLOBALS['QA_FAIL']++; echo "$id | FAIL | $name | $detail\n"; }
}
// Header names are case-insensitive in HTTP; look them up that way.
function qa_header($headers, $name) {
    foreach ($headers as $k => $v) {
        if (strcasecmp((string) $k, $name) === 0) {
            return $v;
        }
    }
    return null;
}

$opts = get_option('cg_plugin_options', []);
$opts = is_array($opts) ? $opts : [];
$opts['api_key'] = 'qa-test-key-not-real';
update_option('cg_plugin_options', $opts);

// V1: every endpoint the plugin calls through cg_call_hq_api().
$calls = [
    ['/sites/register', [], 'POST', false],
    ['/sites/status', [], 'GET', true],
    ['/sites/settings', ['settings' => []], 'POST', true],
    ['/sites/courier-check', ['phone_number' => '01700000000'], 'POST', true],
    ['/sites/increment-usage', ['action_type' => 'qa'], 'POST', true],
    ['/sites/decrement-usage', ['action_type' => 'qa'], 'POST', true],
    ['/sites/plugin-status', ['status' => 'qa'], 'POST', true],
    ['/sites/request-site-key', ['request_id' => 'qa'], 'POST', true],
    ['/sites/claim-key', ['nonce' => 'qa'], 'POST', true],
];
foreach ($calls as $c) {
    $GLOBALS['QA_REQ'] = [];
    cg_call_hq_api($c[0], $c[1], $c[2], $c[3]);
    $r = $GLOBALS['QA_REQ'];
    $v = count($r) === 1 ? qa_header($r[0]['headers'], 'X-CG-Version') : null;
    ok('V1' . $c[0], "{$c[2]} {$c[0]} sends X-CG-Version = CG_VERSION", count($r) === 1 && $v === CG_VERSION, 'requests=' . count($r) . ' header=' . var_export($v, true) . ' CG_VERSION=' . CG_VERSION);
}

// V1b: without an API key the register call still carries the version.
$opts2 = $opts; unset($opts2['api_key']);
update_option('cg_plugin_options', $opts2);
$GLOBALS['QA_REQ'] = [];
cg_call_hq_api('/sites/register', [], 'POST', false);
$r = $GLOBALS['QA_REQ'];
$v = count($r) === 1 ? qa_header($r[0]['headers'], 'X-CG-Version') : null;
ok('V1b', 'register with no key sends X-CG-Version', count($r) === 1 && $v === CG_VERSION, 'header=' . var_export($v, true));
update_option('cg_plugin_options', $opts);

// V2: analytics goes to HQ with its own wp_remote_post().
$GLOBALS['QA_REQ'] = [];
cg_send_plugin_analytics('weekly_analytics');
$r = $GLOBALS['QA_REQ'];
$v = count($r) >= 1 ? qa_header($r[0]['headers'], 'X-CG-Version') : null;
ok('V2', 'analytics sends X-CG-Version = CG_VERSION', count($r) === 1 && $v === CG_VERSION, 'requests=' . count($r) . ' header=' . var_export($v, true));

// V3: no other code path sends HTTP to HQ on its own. Every wp_remote_* /
// wp_safe_remote_* call in the plugin (vendored Appsero excluded) must be one
// of the two known HQ senders; a new one must add the header and this list.
$root = rtrim(CG_PLUGIN_DIR, '/');
$found = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
    if (substr($p, -4) !== '.php' || strpos($p, 'includes/admin/appsero/') === 0 || strpos($p, 'tests/') === 0 || strpos($p, 'vendor/') === 0) {
        continue;
    }
    // Whole-file match so a call split across lines ("wp_remote_post\n(") is
    // still seen; a file is listed once however many calls it has.
    if (preg_match('/\b(wp_remote_(get|post|head|request)|wp_safe_remote_(get|post|head|request))\s*\(/', (string) file_get_contents($f->getPathname()))) {
        $found[] = $p;
    }
}
$found = array_values(array_unique($found));
sort($found);
ok('V3', 'only cg_call_hq_api() and analytics send HTTP', $found === ['includes/analytics.php', 'includes/utils.php'], 'found=' . json_encode($found) . ' hint: a new HQ sender must add X-CG-Version and be added to this list; a match inside a comment only needs rewording');

// V4: the header is not leaked to non-HQ hosts. Make one real non-HQ request
// (answered by the filter above, never sent) so a global http_request_args
// filter that adds the header everywhere is caught.
wp_remote_get('https://example.invalid/qa');
$others = $GLOBALS['QA_OTHER'];
$probe = 0; $leak = 0;
foreach ($others as $x) {
    if ($x['host'] === 'example.invalid') { $probe++; }
    if (qa_header($x['headers'], 'X-CG-Version') !== null) { $leak++; }
}
ok('V4', 'no X-CG-Version on requests to other hosts', $probe === 1 && $leak === 0, 'other=' . count($others) . " probe=$probe leak=$leak");

foreach ($GLOBALS['QA_ORIG'] as $o => $v) {
    if ($v === null) { delete_option($o); } else { update_option($o, $v); }
}
$t = $GLOBALS['QA_PASS'] + $GLOBALS['QA_FAIL'];
echo "SUMMARY {$GLOBALS['QA_PASS']}/$t " . ($GLOBALS['QA_FAIL'] ? 'FAIL' : 'PASS') . "\n";
