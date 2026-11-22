<?php
/**
 * Negative cache for GET /sites/status on the checkout save path (1.5.1).
 * On dev b2bae0b a failed sync writes nothing: cg_sync_with_hq() only sets
 * cg_api_cache after a verified status (admin-settings-page.php:47-49) and
 * returns false otherwise (:64), so cg_can_perform_action() misses the cache
 * again on the next save (ajax-usage-limits.php:30-36) and calls status
 * again. With the key present and status answering 403, seven checkout saves
 * sent seven GET /sites/status. Rule: after a failed status call (403, 401,
 * 5xx, timeout, HQ down, bad signature), at most one status call per 60 s,
 * or Retry-After when HQ sends one, capped at 600 s; nothing verified is
 * cached while it fails. A verified status clears the wait. The admin
 * "Sync now" button calls status even during a wait; if that call fails it
 * writes a new wait, if it succeeds it clears the wait.
 *
 * The wait must use the plugin clock (cg_register_now() / cg_sitekey_now(),
 * filter cg_sitekey_now), so this test can move time without sleeping.
 *
 * Run on a local test site with WooCommerce only (never production):
 *   wp eval-file tests/qa/status-negative-cache-tests.php
 * HTTP is answered by a pre_http_request filter in this process; nothing
 * leaves the machine. Cases N9 and N11b (verified status) need a
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
// Whatever a fix uses to keep the wait (option or transient, any name), the
// cases must not share it: every cg_* option or transient that was not there
// when the run started is removed at each reset and at the end.
function qa_cg_option_names() {
    global $wpdb;
    return $wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'cg\\_%' OR option_name LIKE '\\_transient\\_cg\\_%' OR option_name LIKE '\\_transient\\_timeout\\_cg\\_%'");
}
$GLOBALS['QA_OPT_SNAP'] = array_flip(qa_cg_option_names());
function qa_drop_new_options() {
    foreach (qa_cg_option_names() as $name) {
        if (!isset($GLOBALS['QA_OPT_SNAP'][$name])) { delete_option($name); wp_cache_delete($name, 'options'); }
    }
    wp_cache_delete('notoptions', 'options'); wp_cache_delete('alloptions', 'options');
}
foreach (['cg_plugin_options', 'cg_license_status', 'cg_register_next_allowed_at', 'cg_sitekey_state', 'cg_status_next_allowed_at'] as $o) {
    $GLOBALS['QA_ORIG'][$o] = get_option($o, null);
}
$GLOBALS['QA_CACHE_ORIG'] = get_transient('cg_api_cache');
$GLOBALS['QA_USER_ORIG'] = get_current_user_id();
add_filter('cg_sitekey_now', function () { return $GLOBALS['QA_NOW']; });
// Expiry of each cg_* transient on the plugin clock (see qa_set_now).
$GLOBALS['QA_TEXP'] = [];
// WP 6.8 renamed setted_transient to set_transient (the old name is deprecated).
add_action(version_compare($GLOBALS['wp_version'], '6.8', '>=') ? 'set_transient' : 'setted_transient', function ($transient, $value, $expiration) {
    if (strpos($transient, 'cg_') === 0 && (int) $expiration > 0) {
        $GLOBALS['QA_TEXP'][$transient] = $GLOBALS['QA_NOW'] + (int) $expiration;
    }
}, 10, 3);
$GLOBALS['QA_LOG'] = []; $GLOBALS['QA_ROUTES'] = [];
add_filter('pre_http_request', function ($pre, $args, $url) {
    $path = preg_replace('#^.*?/api/v1#', '', (string) parse_url($url, PHP_URL_PATH));
    $GLOBALS['QA_LOG'][] = $path;
    $r = $GLOBALS['QA_ROUTES'][$path] ?? ['code' => 'down'];
    if ($r['code'] === 'down') {
        return new WP_Error('http_request_failed', 'cURL error 7: Failed to connect');
    }
    if ($r['code'] === 'timeout') {
        return new WP_Error('http_request_failed', 'cURL error 28: Operation timed out');
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

// Rows this test writes all have a device_fingerprint starting with 'qafp';
// they are removed before the run, in every reset and at the end.
function qa_drop_rows() {
    global $wpdb;
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}cg_incomplete_checkouts WHERE device_fingerprint LIKE %s", 'qafp%'));
}
function qa_reset() {
    global $wpdb;
    foreach (['cg_sitekey_state', 'cg_sitekey_next_allowed_at', 'cg_sitekey_lock', 'cg_register_next_allowed_at', 'cg_status_next_allowed_at'] as $o) {
        $wpdb->delete($wpdb->options, ['option_name' => $o]);
        wp_cache_delete($o, 'options');
    }
    wp_cache_delete('notoptions', 'options'); wp_cache_delete('alloptions', 'options');
    wp_clear_scheduled_hook('cg_sitekey_request_event');
    qa_drop_new_options();
    delete_transient('cg_api_cache');
    $o = is_array($GLOBALS['QA_ORIG']['cg_plugin_options']) ? $GLOBALS['QA_ORIG']['cg_plugin_options'] : [];
    $o['api_key'] = 'qa-own-key-not-real';
    cg_update_options_from_hq($o);
    update_option('cg_license_status', 'active');
    $GLOBALS['QA_LOG'] = []; $GLOBALS['QA_ROUTES'] = []; $GLOBALS['QA_NOW'] = $GLOBALS['QA_T0']; $GLOBALS['QA_TEXP'] = [];
    wp_set_current_user(0);
    // A save that passed the limit check stored a row under this process's
    // WooCommerce session; drop it so the next case starts with no row.
    qa_drop_rows();
}
// One guest checkout-field save, a new fingerprint each time (unique in this
// run and across runs) so no existing row is found and the limit check runs.
// Placeholder values only; never a real customer.
function qa_checkout_save($i) {
    wp_set_current_user(0);
    $GLOBALS['QA_SEQ'] = ($GLOBALS['QA_SEQ'] ?? 0) + 1;
    $_POST = $_REQUEST = [
        'nonce' => wp_create_nonce('cg_save_checkout_data_nonce'),
        'device_fingerprint' => 'qafp' . $GLOBALS['QA_RUN'] . 's' . $GLOBALS['QA_SEQ'] . 'n' . $i,
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
// Move the plugin clock to T0 + $t. Transient expiry uses the real clock, so
// every cg_* transient's timeout is moved back by the same step: a wait kept
// in a transient then ends when the plugin clock says so, and a transient
// whose own expiry is shorter than the wait it stores is caught.
function qa_set_now($t) {
    global $wpdb;
    $new = $GLOBALS['QA_T0'] + $t;
    $d = $new - $GLOBALS['QA_NOW'];
    $GLOBALS['QA_NOW'] = $new;
    // Re-anchor also when the plugin clock does not move (several saves at
    // the same second): real seconds still pass between them.
    $d = max(0, $d);
    // WP expires a transient against the real time(), and real seconds pass
    // while the test runs. Re-anchor each cg_* transient the plugin set in
    // this case to its expiry on the plugin clock (set time + TTL), so its
    // remaining life is the same on a fast or a slow machine. A cg_*
    // transient set some other way is only moved back by the clock step.
    $rn = time();
    $names = $wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_timeout\\_cg\\_%'");
    foreach ($names as $name) {
        $tr = substr($name, strlen('_transient_timeout_'));
        if (isset($GLOBALS['QA_TEXP'][$tr])) {
            $left = $GLOBALS['QA_TEXP'][$tr] - $new;
            $wpdb->update($wpdb->options, ['option_value' => (string) ($left > 0 ? $rn + $left : $rn - 1)], ['option_name' => $name]);
        } else {
            $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = CAST(option_value AS SIGNED) - %d WHERE option_name = %s", $d, $name));
        }
        wp_cache_delete($name, 'options');
    }
    wp_cache_delete('alloptions', 'options'); wp_cache_delete('notoptions', 'options');
}
// Saves at the given offsets (seconds from T0); returns status calls made.
function qa_saves_at(array $offsets) {
    $before = qa_status();
    foreach ($offsets as $k => $t) { qa_set_now($t); qa_checkout_save($t * 100 + $k); }
    return qa_status() - $before;
}
// The admin "Sync now" button: wp_ajax_cg_force_sync_ajax as an administrator.
// Returns the status calls it made.
function qa_force_sync() {
    $before = qa_status();
    $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
    wp_set_current_user($admins ? (int) $admins[0] : 0);
    $_POST = $_REQUEST = ['nonce' => wp_create_nonce('cg_force_sync_ajax')];
    try {
        cg_handle_force_sync_ajax();
    } catch (QA_Ajax_Done $e) {
    }
    $_POST = $_REQUEST = [];
    wp_set_current_user(0);
    return qa_status() - $before;
}
$GLOBALS['R'] = [];
function ok($id, $name, $pass, $detail) { $GLOBALS['R'][] = [$id, $name, $pass === null ? 'SKIP' : ($pass ? 'PASS' : 'FAIL'), $detail]; }
$GLOBALS['QA_RUN'] = bin2hex(random_bytes(4));
qa_drop_rows();
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

// N8: other failed syncs (HQ down, timeout, 401, 500, 200 with a bad signature) -> one status call per 60 s each.
$n8 = [];
foreach (['down' => ['code' => 'down'], 'timeout' => ['code' => 'timeout'], '401' => ['code' => 401, 'body' => ['message' => 'qa']], '500' => ['code' => 500, 'body' => ['message' => 'qa']], 'badsig' => ['code' => 200, 'body' => ['payload_string' => '{}', 'signature' => 'AAAA']]] as $k => $route) {
    qa_reset(); $GLOBALS['QA_ROUTES'] = ['/sites/status' => $route];
    $n8[$k] = qa_saves_at([0, 2, 4, 6, 8, 10, 12]);
}
ok('N8', 'HQ down / timeout / 401 / 500 / bad signature: 7 saves in 13 s -> 1 status call each', $n8 === ['down' => 1, 'timeout' => 1, '401' => 1, '500' => 1, 'badsig' => 1], json_encode($n8));

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

// N10: Retry-After longer than the cap (3600 s) -> the wait is capped at 600 s:
// one status call for 0..599 s, one more at +601 s, and no verified status is
// ever stored while HQ keeps failing.
qa_reset(); $GLOBALS['QA_ROUTES'] = ['/sites/status' => ['code' => 403, 'ra' => '3600', 'body' => ['message' => 'qa forbidden']]];
$a = qa_saves_at([0, 30, 120, 300, 450, 599]);
$b = qa_saves_at([601, 605]);
$cache = get_transient('cg_api_cache');
ok('N10', '403 Retry-After 3600: wait capped at 600 s (1 call in 0..599 s, 1 at +601 s), nothing verified cached', $a === 1 && $b === 1 && $cache === false, "first=$a after=$b cache=" . ($cache === false ? 'none' : 'set'));

// N11a: "Sync now" during a wait calls status anyway; when it fails it writes a
// new wait from that moment (checkout saves then wait 60 s from the click).
qa_reset(); $GLOBALS['QA_ROUTES'] = $R403;
$a = qa_saves_at([0]);
qa_set_now(10);
$f = qa_force_sync();
$gate = (int) get_option('cg_status_next_allowed_at', 0);
$c = qa_saves_at([65]);
$d = qa_saves_at([71]);
// Judged by behaviour only (a fix may keep the wait under any name); the gate
// value in the detail is read from the reference fix's option, if present.
ok('N11a', 'Sync now in a wait: 1 status call; failure writes a new wait (+10 s -> +70 s)', $a === 1 && $f === 1 && $c === 0 && $d === 1, "first=$a sync=$f gate=" . ($gate ? '+' . ($gate - $GLOBALS['QA_T0']) : 'none') . " at65=$c at71=$d");

// N11b: "Sync now" during a wait, HQ answers with a verified status -> the wait
// is cleared and the status is cached. Needs the test key pair (as N9).
if ($sk === null) {
    ok('N11b', 'Sync now in a wait, verified status -> wait cleared, cached', null, 'no test key pair (QA_MOCK_HQ_PK_FILE)');
} else {
    qa_reset(); $GLOBALS['QA_ROUTES'] = $R403;
    $a = qa_saves_at([0]);
    $GLOBALS['QA_ROUTES'] = ['/sites/status' => $okres];
    qa_set_now(10);
    $f = qa_force_sync();
    $cache = get_transient('cg_api_cache');
    // Wait cleared: with the cache gone and HQ failing again, the next save
    // inside the old window (+15 s) syncs at once.
    delete_transient('cg_api_cache');
    $GLOBALS['QA_ROUTES'] = $R403;
    $e = qa_saves_at([15]);
    ok('N11b', 'Sync now in a wait, verified status -> wait cleared, cached', $a === 1 && $f === 1 && $e === 1 && is_array($cache) && isset($cache['limits']['checkouts']), "first=$a sync=$f cache=" . (is_array($cache) ? 'yes' : 'no') . " save_at15=$e");
}

// N12: a Retry-After belongs to the call that sent it. 403 with Retry-After 300,
// then after that wait a failure without one (200 with a bad signature) in the
// same PHP process -> the new wait is the 60 s minimum, not 300 s again
// (the kept Retry-After must be reset when a sync starts).
qa_reset(); $GLOBALS['QA_ROUTES'] = ['/sites/status' => ['code' => 403, 'ra' => '300', 'body' => ['message' => 'qa forbidden']]];
$a = qa_saves_at([0]);
$GLOBALS['QA_ROUTES'] = ['/sites/status' => ['code' => 200, 'body' => ['payload_string' => '{}', 'signature' => 'AAAA']]];
$b = qa_saves_at([301, 330]);
$c = qa_saves_at([362]);
ok('N12', '403 RA 300, then bad signature at +301 s -> next wait 60 s (1 call at +362 s)', $a === 1 && $b === 1 && $c === 1, "first=$a badsig=$b at362=$c");

$out = ob_get_clean();
// restore
qa_reset();
qa_drop_rows();
qa_drop_new_options();
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
