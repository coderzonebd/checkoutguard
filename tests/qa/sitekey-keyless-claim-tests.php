<?php
/**
 * Keyless claim (SiteKey contract §1a, Sakib F1 on 1.5.0 ada4b91): a fresh
 * install with no key whose register gets 409 claim_required must run a
 * claim round without X-Api-Key, commit the new key, roll back to "no key"
 * when the key is rejected, and the Reconnect action must start a round.
 * A keyless site without claim_required (register 429 / HQ down) never
 * calls request-site-key.
 *
 * Run on a local test site only (never production):
 *   wp eval-file tests/qa/sitekey-keyless-claim-tests.php
 * HTTP is answered by a pre_http_request filter in this process; nothing
 * leaves the machine. Options are restored at the end.
 */
if (!defined('ABSPATH')) {
    exit;
}
$GLOBALS['QA_NOW'] = 1800000000;
$GLOBALS['QA_OPTS_ORIG'] = get_option('cg_plugin_options', []);
$GLOBALS['QA_STATUS_ORIG'] = get_option('cg_license_status', null);
add_filter('cg_sitekey_now', function () { return $GLOBALS['QA_NOW']; });
$GLOBALS['QA_LOG'] = [];
$GLOBALS['QA_ROUTES'] = [];
add_filter('pre_http_request', function ($pre, $args, $url) {
    $path = preg_replace('#^.*?/api/v1#', '', (string) parse_url($url, PHP_URL_PATH));
    $hdr = isset($args['headers']) && is_array($args['headers']) ? $args['headers'] : [];
    $GLOBALS['QA_LOG'][] = ['p' => $path, 'key' => array_key_exists('X-Api-Key', $hdr) ? (string) $hdr['X-Api-Key'] : null];
    $r = $GLOBALS['QA_ROUTES'][$path] ?? ['code' => 'down'];
    if ($r['code'] === 'down') {
        return new WP_Error('http_request_failed', 'cURL error 7: Failed to connect');
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

function qa_reset() {
    global $wpdb;
    foreach (['cg_sitekey_state', 'cg_sitekey_next_allowed_at', 'cg_sitekey_lock', 'cg_sitekey_last_drop', 'cg_register_next_allowed_at', 'cg_register_manual_next_allowed_at'] as $o) {
        $wpdb->delete($wpdb->options, ['option_name' => $o]);
        wp_cache_delete($o, 'options');
    }
    wp_cache_delete('notoptions', 'options'); wp_cache_delete('alloptions', 'options');
    wp_clear_scheduled_hook('cg_sitekey_request_event'); wp_clear_scheduled_hook('cg_sitekey_claim_event');
    $o = is_array($GLOBALS['QA_OPTS_ORIG']) ? $GLOBALS['QA_OPTS_ORIG'] : [];
    unset($o['api_key']);
    cg_update_options_from_hq($o);
    $GLOBALS['QA_LOG'] = []; $GLOBALS['QA_ROUTES'] = []; $GLOBALS['QA_NOW'] = 1800000000;
}
function qa_calls($path) { return array_values(array_filter($GLOBALS['QA_LOG'], function ($c) use ($path) { return $c['p'] === $path; })); }
function qa_add_nonce() { // what cg_sitekey_receive_nonce stores after a verified delivery
    $s = cg_sitekey_state();
    $s['nonces'][] = ['n' => str_repeat('A', 43), 'at' => $GLOBALS['QA_NOW'], 'exp' => $GLOBALS['QA_NOW'] + 300];
    cg_sitekey_save_state($s);
}
$GLOBALS['R'] = [];
function ok($id, $name, $pass, $detail) { $GLOBALS['R'][] = [$id, $name, $pass ? 'PASS' : 'FAIL', $detail]; }
$KEY = 'czsite_' . str_repeat('k', 40);
$claim409 = ['code' => 409, 'body' => ['error' => 'x', 'code' => 'claim_required']];

// K1: keyless + register 409 claim_required -> a claim round is wanted and scheduled
qa_reset();
$GLOBALS['QA_ROUTES'] = ['/sites/register' => $claim409];
cg_register_site_with_hq();
$s = cg_sitekey_state();
ok('K1', 'keyless 409 claim_required: needed, round scheduled', cg_sitekey_needed() && ($s['legacy_code'] ?? '') === 'claim_required' && wp_next_scheduled('cg_sitekey_request_event') !== false, 'needed=' . var_export(cg_sitekey_needed(), true));

// K2: the round sends request-site-key without X-Api-Key
$GLOBALS['QA_ROUTES']['/sites/request-site-key'] = ['code' => 202, 'body' => ['status' => 'accepted']];
do_action('cg_sitekey_request_event');
$rq = qa_calls('/sites/request-site-key');
ok('K2', 'request-site-key sent once, no X-Api-Key', count($rq) === 1 && $rq[0]['key'] === null, json_encode($rq));

// K3: claim-key without X-Api-Key, then the first status call with the new key commits it
$GLOBALS['QA_ROUTES']['/sites/claim-key'] = ['code' => 200, 'body' => ['api_key' => $KEY, 'expires_in' => 3600]];
$GLOBALS['QA_ROUTES']['/sites/status'] = ['code' => 200, 'body' => ['payload' => 'x', 'signature' => 'x']];
qa_add_nonce();
$res = cg_sitekey_claim();
$cl = qa_calls('/sites/claim-key'); $st = qa_calls('/sites/status'); $s = cg_sitekey_state();
ok('K3', 'keyless claim commits the site key', $res === 'committed' && count($cl) === 1 && $cl[0]['key'] === null && count($st) >= 1 && $st[0]['key'] === $KEY && cg_sitekey_current_key() === $KEY && empty($s['pending_until']) && empty($s['legacy_code']) && empty($s['legacy_blocked_at']) && !cg_sitekey_needed(), "res=$res claim=" . json_encode($cl) . ' status_key_ok=' . var_export(($st[0]['key'] ?? null) === $KEY, true));

// K4: pending key rejected (401 on status) -> back to no key, still wanting a round, no loop
qa_reset();
$GLOBALS['QA_ROUTES'] = ['/sites/register' => $claim409, '/sites/request-site-key' => ['code' => 202, 'body' => []], '/sites/claim-key' => ['code' => 200, 'body' => ['api_key' => $KEY]], '/sites/status' => ['code' => 401, 'body' => ['error' => 'Invalid API key']]];
cg_register_site_with_hq();
cg_sitekey_request('deadline');
qa_add_nonce();
$res = cg_sitekey_claim();
$s = cg_sitekey_state();
ok('K4', 'rejected keyless key is dropped, not kept', cg_sitekey_current_key() === '' && empty($s['pending_until']) && cg_sitekey_needed() && (int) ($s['fails'] ?? 0) === 1 && cg_sitekey_gate() > $GLOBALS['QA_NOW'] && count(qa_calls('/sites/request-site-key')) === 1, "res=$res key_empty=" . var_export(cg_sitekey_current_key() === '', true) . ' fails=' . ($s['fails'] ?? 0));

// K5: pending key not confirmed within its hour -> expired, back to no key
qa_reset();
$GLOBALS['QA_ROUTES'] = ['/sites/register' => $claim409, '/sites/request-site-key' => ['code' => 202, 'body' => []], '/sites/claim-key' => ['code' => 200, 'body' => ['api_key' => $KEY]], '/sites/status' => ['code' => 'down']];
cg_register_site_with_hq();
cg_sitekey_request('deadline');
qa_add_nonce();
$res = cg_sitekey_claim();
$mid = cg_sitekey_current_key();
$GLOBALS['QA_NOW'] += 3601;
cg_sitekey_expire_pending();
ok('K5', 'unconfirmed keyless key expires to no key', $res === 'claimed' && $mid === $KEY && cg_sitekey_current_key() === '' && cg_sitekey_needed(), "res=$res");

// K6: Reconnect (manual) on a keyless claim_required site starts a round
qa_reset();
$GLOBALS['QA_ROUTES'] = ['/sites/register' => $claim409, '/sites/request-site-key' => ['code' => 202, 'body' => []]];
cg_register_site_with_hq();
$r = cg_sitekey_request('manual');
ok('K6', 'Reconnect on keyless claim_required: accepted', $r === 'accepted' && count(qa_calls('/sites/request-site-key')) === 1, "r=$r");

// K7: keyless after register 429 or HQ down: no claim round, no request-site-key
$out = [];
foreach (['429' => ['code' => 429, 'ra' => '3600', 'body' => ['error' => 'Too Many Requests']], 'down' => ['code' => 'down']] as $label => $route) {
    qa_reset();
    $GLOBALS['QA_ROUTES'] = ['/sites/register' => $route, '/sites/request-site-key' => ['code' => 202, 'body' => []]];
    cg_register_site_with_hq();
    $m = cg_sitekey_request('manual'); $a = cg_sitekey_request('scheduled');
    $out[$label] = [cg_sitekey_needed(), $m, $a, count(qa_calls('/sites/request-site-key'))];
}
ok('K7', 'keyless without claim_required: never requests', $out['429'] === [false, 'not_needed', 'not_needed', 0] && $out['down'] === [false, 'not_needed', 'not_needed', 0], json_encode($out));

// K8: a site on the shared key still sends it on request-site-key (no change)
qa_reset();
$o = get_option('cg_plugin_options', []); $o['api_key'] = 'qa-shared-key-not-real'; cg_update_options_from_hq($o);
$GLOBALS['QA_ROUTES'] = ['/sites/request-site-key' => ['code' => 202, 'body' => []]];
$r = cg_sitekey_request('scheduled');
$rq = qa_calls('/sites/request-site-key');
ok('K8', 'shared-key site sends X-Api-Key as before', $r === 'accepted' && count($rq) === 1 && $rq[0]['key'] === 'qa-shared-key-not-real', json_encode($rq));

// K9: rollback of a shared-key claim still restores the shared key
qa_reset();
$o = get_option('cg_plugin_options', []); $o['api_key'] = 'qa-shared-key-not-real'; cg_update_options_from_hq($o);
$GLOBALS['QA_ROUTES'] = ['/sites/request-site-key' => ['code' => 202, 'body' => []], '/sites/claim-key' => ['code' => 200, 'body' => ['api_key' => $KEY]], '/sites/status' => ['code' => 401, 'body' => []]];
cg_sitekey_request('scheduled'); qa_add_nonce(); cg_sitekey_claim();
ok('K9', 'shared-key rollback restores the shared key', cg_sitekey_current_key() === 'qa-shared-key-not-real', 'key=' . (cg_sitekey_current_key() === $KEY ? 'site key' : 'other'));

// K10: a status wait left from an earlier failure (cg_status_next_allowed_at,
// +300 s) must not hold back the status call that commits a claimed key:
// the claim clears the wait for that one call (no force, see G13k). Exactly one status call, result committed.
// Stands on its own (no other case sets the wait), so it does not rely on
// what K3, K4 or K9 leave behind.
qa_reset();
$GLOBALS['QA_ROUTES'] = ['/sites/register' => $claim409, '/sites/request-site-key' => ['code' => 202, 'body' => []], '/sites/claim-key' => ['code' => 200, 'body' => ['api_key' => $KEY, 'expires_in' => 3600]], '/sites/status' => ['code' => 200, 'body' => ['payload' => 'x', 'signature' => 'x']]];
cg_register_site_with_hq();
cg_sitekey_request('deadline');
qa_add_nonce();
update_option('cg_status_next_allowed_at', $GLOBALS['QA_NOW'] + 300, false);
$GLOBALS['QA_LOG'] = [];
$res = cg_sitekey_claim();
$st = qa_calls('/sites/status');
ok('K10', 'status wait set, then claim: exactly 1 status call, committed', $res === 'committed' && count($st) === 1 && $st[0]['key'] === $KEY && cg_sitekey_current_key() === $KEY, "res=$res status_calls=" . count($st));
delete_option('cg_status_next_allowed_at');

qa_reset();
cg_update_options_from_hq(is_array($GLOBALS['QA_OPTS_ORIG']) ? $GLOBALS['QA_OPTS_ORIG'] : []);
if ($GLOBALS['QA_STATUS_ORIG'] === null) { delete_option('cg_license_status'); } else { update_option('cg_license_status', $GLOBALS['QA_STATUS_ORIG']); }
restore_error_handler();
$R = $GLOBALS['R'];
foreach ($R as $r) echo implode(' | ', $r), "\n";
echo 'warnings_total=' . count($GLOBALS['QA_WARN']) . "\n"; foreach (array_unique($GLOBALS['QA_WARN']) as $w) echo "  W: $w\n";
$f = count(array_filter($R, function ($r) { return $r[2] !== 'PASS'; })) + (count($GLOBALS['QA_WARN']) ? 1 : 0);
echo "SUMMARY " . (count($R) - $f) . '/' . count($R) . " PASS\n";
