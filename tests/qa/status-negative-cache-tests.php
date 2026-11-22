<?php
/**
 * Negative cache for GET /sites/status on the checkout save path (1.5.1).
 * On dev b2bae0b a failed sync writes nothing: cg_sync_with_hq() only sets
 * cg_api_cache after a verified status (admin-settings-page.php:47-49) and
 * returns false otherwise (:64), so cg_can_perform_action() misses the cache
 * again on the next save (ajax-usage-limits.php:30-36) and calls status
 * again. With the key present and status answering 403, seven checkout saves
 * sent seven GET /sites/status. Rule: after a failed status call, at most one
 * status call per 60 s, longer when HQ sends Retry-After; a verified status
 * clears the wait.
 *
 * The wait must use the plugin clock (cg_register_now() / cg_sitekey_now(),
 * filter cg_sitekey_now), so this test can move time without sleeping.
 *
 * Run on a local test site with WooCommerce only (never production):
 *   wp eval-file tests/qa/status-negative-cache-tests.php
 * HTTP is answered by a pre_http_request filter in this process; nothing
 * leaves the machine. Case N9 (recovery after a verified status) needs a
 * test key pair: it runs only when QA_MOCK_HQ_PK_FILE is defined, a
 * mock_sk.b64 sits next to it and that public key is CG_HQ_PUBLIC_KEY;
 * otherwise it is reported as SKIP. Options are restored at the end.
 */
if (!defined('ABSPATH')) {
    exit;
}
$GLOBALS['QA_T0'] = 1800000000;
$GLOBALS['QA_NOW'] = $GLOBALS['QA_T0'];
$GLOBALS['QA_ORIG'] = [];
foreach (['cg_plugin_options', 'cg_license_status', 'cg_register_next_allowed_at', 'cg_sitekey_state', 'cg_status_next_allowed_at'] as $o) {
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
    $body = isset($r['raw']) ? $r['raw'] : json_encode($r['body'] ?? []);
    return ['headers' => new WpOrg\Requests\Utility\CaseInsensitiveDictionary($h), 'body' => $body, 'response' => ['code' => $r['code'], 'message' => ''], 'cookies' => [], 'filename' => null];
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

function qa_reset() {
    global $wpdb;
    foreach (['cg_sitekey_state', 'cg_sitekey_next_allowed_at', 'cg_sitekey_lock', 'cg_register_next_allowed_at', 'cg_status_next_allowed_at'] as $o) {
        $wpdb->delete($wpdb->options, ['option_name' => $o]);
        wp_cache_delete($o, 'options');
    }
    wp_cache_delete('notoptions', 'options'); wp_cache_delete('alloptions', 'options');
    wp_clear_scheduled_hook('cg_sitekey_request_event');
    delete_transient('cg_api_cache');
    $o = is_array($GLOBALS['QA_ORIG']['cg_plugin_options']) ? $GLOBALS['QA_ORIG']['cg_plugin_options'] : [];
    $o['api_key'] = 'qa-own-key-not-real';
    cg_update_options_from_hq($o);
    update_option('cg_license_status', 'active');
    $GLOBALS['QA_LOG'] = []; $GLOBALS['QA_ROUTES'] = []; $GLOBALS['QA_NOW'] = $GLOBALS['QA_T0'];
    wp_set_current_user(0);
}
// One guest checkout-field save, a new session each time so the limit check
// runs (no existing row). Placeholder values only; never a real customer.
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
function qa_status() {
    return count(array_filter($GLOBALS['QA_LOG'], function ($p) { return $p === '/sites/status'; }));
}
// Saves at the given offsets (seconds from T0); returns status calls made.
function qa_saves_at(array $offsets) {
    $before = qa_status();
    foreach ($offsets as $k => $t) { $GLOBALS['QA_NOW'] = $GLOBALS['QA_T0'] + $t; qa_checkout_save($t * 100 + $k); }
    return qa_status() - $before;
}
$GLOBALS['R'] = [];
function ok($id, $name, $pass, $detail) { $GLOBALS['R'][] = [$id, $name, $pass === null ? 'SKIP' : ($pass ? 'PASS' : 'FAIL'), $detail]; }
$R403 = ['/sites/status' => ['code' => 403, 'body' => ['message' => 'qa forbidden']]];
ob_start();

// N1: status 403 (no code), seven saves within 13 s -> exactly one status call.
qa_reset(); $GLOBALS['QA_ROUTES'] = $R403;
$n = qa_saves_at([0, 2, 4, 6, 8, 10, 12]);
ok('N1', '403: 7 saves in 13 s -> 1 status call', $n === 1, "status=$n calls=" . count($GLOBALS['QA_LOG']));

// N2: still inside the 60 s wait: three more saves at 59 s -> no status call.
$n = qa_saves_at([59, 59, 59]);
ok('N2', '403: 3 more saves at +59 s -> 0 status calls', $n === 0, "status=$n");

// N3: the wait ends: at +61 s one more status call, not more (not blocked for good).
$n = qa_saves_at([61, 62, 63]);
ok('N3', '403: saves at +61..63 s -> exactly 1 status call', $n === 1, "status=$n");

// N4: 12 saves spread over 60 s (every 5 s) -> exactly one status call.
qa_reset(); $GLOBALS['QA_ROUTES'] = $R403;
$n = qa_saves_at(range(0, 55, 5));
ok('N4', '403: 12 saves over 60 s -> 1 status call', $n === 1, "status=$n");

// N5: 403 with Retry-After 300 -> one status call for 300 s, then one more.
qa_reset(); $GLOBALS['QA_ROUTES'] = ['/sites/status' => ['code' => 403, 'ra' => '300', 'body' => ['message' => 'qa forbidden']]];
$a = qa_saves_at(range(0, 299, 30));
$b = qa_saves_at([301, 302]);
ok('N5', '403 Retry-After 300: saves for 300 s -> 1 call, after 301 s -> 1 more', $a === 1 && $b === 1, "first=$a after=$b");

// N6: Retry-After shorter than the minimum (10) -> still one call per 60 s.
qa_reset(); $GLOBALS['QA_ROUTES'] = ['/sites/status' => ['code' => 403, 'ra' => '10', 'body' => ['message' => 'qa forbidden']]];
$n = qa_saves_at([0, 11, 20, 40, 59]);
ok('N6', '403 Retry-After 10: saves 0..59 s -> 1 status call (min 60 s)', $n === 1, "status=$n");

// N7: status 403 site_key_required (legacy deadline code) -> still one status call per 60 s.
qa_reset(); $GLOBALS['QA_ROUTES'] = ['/sites/status' => ['code' => 403, 'body' => ['code' => 'site_key_required', 'message' => 'qa']]];
$n = qa_saves_at([0, 2, 4, 6, 8, 10, 12]);
ok('N7', '403 site_key_required: 7 saves in 13 s -> 1 status call', $n === 1, "status=$n calls=" . json_encode(array_count_values($GLOBALS['QA_LOG'])));

// N8: other failed syncs (HQ down, 500, 200 with a bad signature) -> one status call per 60 s each.
$n8 = [];
foreach (['down' => ['code' => 'down'], '500' => ['code' => 500, 'body' => ['message' => 'qa']], 'badsig' => ['code' => 200, 'body' => ['payload_string' => '{}', 'signature' => 'AAAA']]] as $k => $route) {
    qa_reset(); $GLOBALS['QA_ROUTES'] = ['/sites/status' => $route];
    $n8[$k] = qa_saves_at([0, 2, 4, 6, 8, 10, 12]);
}
ok('N8', 'HQ down / 500 / bad signature: 7 saves in 13 s -> 1 status call each', $n8 === ['down' => 1, '500' => 1, 'badsig' => 1], json_encode($n8));

// N9: recovery: after the wait a verified status is stored, the wait is cleared
// and later saves send no status call (cache hit).
$sk = null;
if (defined('QA_MOCK_HQ_PK_FILE') && function_exists('sodium_crypto_sign_detached')) {
    $skf = dirname(QA_MOCK_HQ_PK_FILE) . '/mock_sk.b64';
    if (is_readable($skf)) {
        $cand = base64_decode(trim((string) file_get_contents($skf)), true);
        if (is_string($cand) && strlen($cand) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES
            && base64_encode(sodium_crypto_sign_publickey_from_secretkey($cand)) === CG_HQ_PUBLIC_KEY) {
            $sk = $cand;
        }
    }
}
if ($sk === null) {
    ok('N9', 'recovery: verified status after the wait -> cached, no more status calls', null, 'no test key pair (QA_MOCK_HQ_PK_FILE)');
} else {
    $ps = json_encode(['status' => 'active', 'plan' => 'free', 'iat' => time(),
        'limits' => ['checkouts' => 100, 'courier_checks' => 50, 'fraud_ips' => 10, 'fraud_emails' => 10, 'fraud_phones' => 10],
        'usage' => ['checkouts' => 0], 'settings' => ['checkout_tracking_enabled' => true]]);
    $okres = ['code' => 200, 'raw' => json_encode(['payload_string' => $ps, 'signature' => base64_encode(sodium_crypto_sign_detached($ps, $sk))])];
    qa_reset(); $GLOBALS['QA_ROUTES'] = $R403;
    $a = qa_saves_at([0, 5]);
    $GLOBALS['QA_ROUTES'] = ['/sites/status' => $okres];
    $b = qa_saves_at([61]);
    $GLOBALS['QA_ROUTES'] = $R403;
    $c = qa_saves_at([62, 70, 200]);
    $cache = get_transient('cg_api_cache');
    ok('N9', 'recovery: verified status after the wait -> cached, no more status calls', $a === 1 && $b === 1 && $c === 0 && is_array($cache) && isset($cache['limits']['checkouts']), "first=$a ok=$b later=$c cache=" . (is_array($cache) ? 'yes' : 'no'));
}

$out = ob_get_clean();
// restore
qa_reset();
foreach ($GLOBALS['QA_ORIG'] as $name => $val) {
    if ($val === null) { delete_option($name); } elseif ($name === 'cg_plugin_options') { cg_update_options_from_hq($val); } else { update_option($name, $val); }
}
delete_transient('cg_api_cache');
if ($GLOBALS['QA_CACHE_ORIG'] !== false) { set_transient('cg_api_cache', $GLOBALS['QA_CACHE_ORIG'], DAY_IN_SECONDS); }
wp_set_current_user($GLOBALS['QA_USER_ORIG']);
restore_error_handler();
$R = $GLOBALS['R'];
foreach ($R as $r) echo implode(' | ', $r), "\n";
echo 'warnings_total=' . count($GLOBALS['QA_WARN']) . "\n"; foreach (array_unique($GLOBALS['QA_WARN']) as $w) echo "  W: $w\n";
$f = count(array_filter($R, function ($r) { return $r[2] === 'FAIL'; })) + (count($GLOBALS['QA_WARN']) ? 1 : 0);
$s = count(array_filter($R, function ($r) { return $r[2] === 'SKIP'; }));
echo "SUMMARY " . (count($R) - $f - $s) . '/' . count($R) . " PASS" . ($s ? " ($s SKIP)" : '') . "\n";
