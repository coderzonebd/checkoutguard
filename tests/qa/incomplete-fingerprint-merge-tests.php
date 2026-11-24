<?php
/**
 * Record 64: the tracker request (cg_save_checkout_data) looked up the
 * customer's incomplete checkout by session OR device fingerprint OR phone.
 * Two phones of the same model and browser send the same fingerprint, so a
 * second customer (new session, other phone and email) was written into the
 * first customer's row: phone and session of the first customer were lost
 * and the second customer was not counted. The fingerprint may only match
 * together with the phone or the email of the request.
 *
 * Only incomplete rows are used here, so these tests hold with and without
 * the recovered/cancelled lookup filter (HQL-11a).
 *
 * Run on a local test site only (never production):
 *   wp eval-file tests/qa/incomplete-fingerprint-merge-tests.php
 * HTTP is answered by a pre_http_request filter in this process; nothing
 * leaves the machine. Rows, options and transients made here are removed.
 */
if (!defined('ABSPATH')) {
    exit;
}
global $wpdb;
$GLOBALS['QA_T'] = $wpdb->prefix . 'cg_incomplete_checkouts';
$GLOBALS['QA_ORIG'] = [
    'cg_plugin_options' => get_option('cg_plugin_options', null),
    'cg_api_cache' => get_transient('cg_api_cache'),
    'cg_license_status' => get_option('cg_license_status', null),
    'cg_last_synced' => get_transient('cg_last_synced'),
];
$GLOBALS['QA_LOG'] = [];
add_filter('pre_http_request', function ($pre, $args, $url) {
    $body = json_decode((string) ($args['body'] ?? ''), true);
    $GLOBALS['QA_LOG'][] = preg_replace('#^.*?/api/v1#', '', (string) parse_url($url, PHP_URL_PATH)) . ':' . (is_array($body) ? (string) ($body['action_type'] ?? '') : '');
    return ['headers' => new WpOrg\Requests\Utility\CaseInsensitiveDictionary([]), 'body' => '{"status":"success"}', 'response' => ['code' => 200, 'message' => ''], 'cookies' => [], 'filename' => null];
}, PHP_INT_MAX, 3);
$GLOBALS['QA_WARN'] = [];
set_error_handler(function ($no, $str, $file, $line) {
    if (strpos($file, 'checkoutguard') !== false) {
        $GLOBALS['QA_WARN'][] = "$str @ " . basename($file) . ":$line";
    }
    return false;
});
class QA_FP_Die extends Exception {}
add_filter('wp_doing_ajax', '__return_true', PHP_INT_MAX);
add_filter('wp_die_ajax_handler', function () {
    return function ($msg = '') {
        throw new QA_FP_Die(is_string($msg) ? $msg : '');
    };
}, PHP_INT_MAX);
$o = is_array($GLOBALS['QA_ORIG']['cg_plugin_options']) ? $GLOBALS['QA_ORIG']['cg_plugin_options'] : [];
$o['api_key'] = 'qa-fp-key';
update_option('cg_plugin_options', $o);
$GLOBALS['QA_TAG'] = 'qafp' . wp_rand(10000, 99999);
$GLOBALS['QA_KEYS'] = [];

function qa_reset($usage = 10, $limit = 100) {
    set_transient('cg_api_cache', ['usage' => ['checkouts' => $usage, 'contact_reveals' => 0], 'limits' => ['checkouts' => $limit, 'contact_reveals' => 0]], DAY_IN_SECONDS);
    $GLOBALS['QA_LOG'] = [];
}
function qa_incr_calls() {
    return count(array_filter($GLOBALS['QA_LOG'], function ($p) { return $p === '/sites/increment-usage:checkouts'; }));
}
// A customer in a new browser context: own WC session, the given device
// fingerprint (shared when the phones are the same model), phone and email
// ('' leaves the field out of the request).
function qa_cust($sfx, $fp, $phone = null, $email = null) {
    $c = [
        'sid' => 't_' . $GLOBALS['QA_TAG'] . "s$sfx",
        'fp' => $fp,
        'phone' => $phone === null ? '017' . wp_rand(10000000, 99999999) : $phone,
        'email' => $email === null ? $GLOBALS['QA_TAG'] . "$sfx@example.test" : $email,
    ];
    $GLOBALS['QA_KEYS'] = array_merge($GLOBALS['QA_KEYS'], [$c['fp'], $c['sid'], $c['email'], cg_normalize_phone_number($c['phone'])]);
    return $c;
}
function qa_fp($sfx) {
    return $GLOBALS['QA_TAG'] . "fp$sfx";
}
function qa_session(array $c) {
    if (function_exists('WC') && WC() && !WC()->session && function_exists('wc_load_cart')) {
        wc_load_cart();
    }
    if (function_exists('WC') && WC() && WC()->session) {
        $p = new ReflectionProperty(get_class(WC()->session), '_customer_id');
        $p->setAccessible(true);
        $p->setValue(WC()->session, $c['sid']);
    }
}
function qa_save(array $c) {
    $_POST = [
        'action' => 'cg_save_checkout_data',
        'nonce' => wp_create_nonce('cg_save_checkout_data_nonce'),
        'billing_first_name' => 'QA',
        'device_fingerprint' => $c['fp'],
    ];
    if ($c['phone'] !== '') {
        $_POST['billing_phone'] = $c['phone'];
    }
    if ($c['email'] !== '') {
        $_POST['billing_email'] = $c['email'];
    }
    $_REQUEST = $_POST;
    unset($_COOKIE['cg_device_fp']);
    qa_session($c);
    ob_start();
    try {
        cg_handle_save_checkout_data();
    } catch (QA_FP_Die $e) {
    }
    $out = (string) ob_get_clean();
    $_POST = $_REQUEST = [];
    return json_decode($out, true);
}
function qa_row($id) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare("SELECT id, status, email, session_id, device_fingerprint, phone, first_name, updated_at FROM {$GLOBALS['QA_T']} WHERE id = %d", $id), ARRAY_A);
}
function qa_rows_by_fp($fp) {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare("SELECT id, session_id, phone, email FROM {$GLOBALS['QA_T']} WHERE device_fingerprint = %s ORDER BY id", $fp), ARRAY_A);
}
function qa_rid($res) {
    return (int) ($res['data']['record_id'] ?? 0);
}
function qa_d(array $kv) {
    return wp_json_encode($kv);
}

$R = [];
function qa_check(&$R, $id, $name, $ok, $detail) {
    $R[] = [$id, $name, $ok ? 'PASS' : 'FAIL', $detail];
}

try {
    // F1: Mahin's record 64. A types only a phone; B (new session, same
    // fingerprint, other phone and email) must get a new row, counted once,
    // and A's row keeps A's phone and session.
    $fp = qa_fp('f1');
    $a = qa_cust('f1a', $fp, null, '');
    qa_reset();
    $ra = qa_save($a);
    $ida = qa_rid($ra);
    $before = qa_row($ida);
    $b = qa_cust('f1b', $fp);
    qa_reset();
    $rb = qa_save($b);
    $idb = qa_rid($rb);
    $after = qa_row($ida);
    $rows = qa_rows_by_fp($fp);
    qa_check($R, 'F1', 'same fingerprint, new session, other phone+email: new row, A unchanged, 1 increment', $ida > 0 && $idb > 0 && $idb !== $ida && !empty($rb['success']) && $after === $before && count($rows) === 2 && qa_incr_calls() === 1, qa_d(['res' => $rb, 'before' => $before, 'after' => $after, 'rows' => $rows, 'incr' => qa_incr_calls()]));

    // F2: same as F1 but B sends no email either (phone only). An empty
    // email must not match A's empty email.
    $fp = qa_fp('f2');
    $a = qa_cust('f2a', $fp, null, '');
    qa_reset();
    $ida = qa_rid(qa_save($a));
    $before = qa_row($ida);
    $b = qa_cust('f2b', $fp, null, '');
    qa_reset();
    $rb = qa_save($b);
    $after = qa_row($ida);
    $rows = qa_rows_by_fp($fp);
    qa_check($R, 'F2', 'same fingerprint, new session, other phone, both emails empty: new row, A unchanged, 1 increment', $ida > 0 && qa_rid($rb) > $ida && $after === $before && count($rows) === 2 && qa_incr_calls() === 1, qa_d(['res' => $rb, 'after' => $after, 'rows' => $rows, 'incr' => qa_incr_calls()]));

    // F3: B sends only a name (no phone, no email). Nothing but the
    // fingerprint is shared: new row, A unchanged, 1 increment.
    $fp = qa_fp('f3');
    $a = qa_cust('f3a', $fp);
    qa_reset();
    $ida = qa_rid(qa_save($a));
    $before = qa_row($ida);
    $b = qa_cust('f3b', $fp, '', '');
    qa_reset();
    $rb = qa_save($b);
    $after = qa_row($ida);
    $rows = qa_rows_by_fp($fp);
    qa_check($R, 'F3', 'same fingerprint, new session, no phone/email: new row, A unchanged, 1 increment', $ida > 0 && qa_rid($rb) > $ida && $after === $before && count($rows) === 2 && qa_incr_calls() === 1, qa_d(['res' => $rb, 'after' => $after, 'rows' => $rows, 'incr' => qa_incr_calls()]));

    // F4: control (Tanvir). Same phone in a new context (new session, same
    // fingerprint): A's row is updated, no new row, no increment.
    $fp = qa_fp('f4');
    $a = qa_cust('f4a', $fp);
    qa_reset();
    $ida = qa_rid(qa_save($a));
    $b = qa_cust('f4b', $fp, $a['phone']);
    qa_reset();
    $rb = qa_save($b);
    $after = qa_row($ida);
    $rows = qa_rows_by_fp($fp);
    qa_check($R, 'F4', 'control: same phone, new session: A row updated, 1 row, 0 increments', $ida > 0 && qa_rid($rb) === $ida && $after['email'] === $b['email'] && $after['session_id'] === $b['sid'] && count($rows) === 1 && qa_incr_calls() === 0, qa_d(['res' => $rb, 'after' => $after, 'rows' => $rows, 'incr' => qa_incr_calls()]));

    // F5: same fingerprint and same email, other phone, new session: the
    // fingerprint matches together with the email, A's row is updated.
    $fp = qa_fp('f5');
    $a = qa_cust('f5a', $fp);
    qa_reset();
    $ida = qa_rid(qa_save($a));
    $b = qa_cust('f5b', $fp, null, $a['email']);
    qa_reset();
    $rb = qa_save($b);
    $after = qa_row($ida);
    $rows = qa_rows_by_fp($fp);
    qa_check($R, 'F5', 'same fingerprint + same email, new session: A row updated, 1 row, 0 increments', $ida > 0 && qa_rid($rb) === $ida && $after['phone'] === cg_normalize_phone_number($b['phone']) && count($rows) === 1 && qa_incr_calls() === 0, qa_d(['res' => $rb, 'after' => $after, 'rows' => $rows, 'incr' => qa_incr_calls()]));

    // F6: same email but another device (fingerprint differs), new session:
    // email alone was never a lookup key and still is not. New row.
    $a = qa_cust('f6a', qa_fp('f6a'));
    qa_reset();
    $ida = qa_rid(qa_save($a));
    $before = qa_row($ida);
    $b = qa_cust('f6b', qa_fp('f6b'), null, $a['email']);
    qa_reset();
    $rb = qa_save($b);
    $after = qa_row($ida);
    qa_check($R, 'F6', 'same email, other fingerprint and phone, new session: new row, A unchanged, 1 increment', $ida > 0 && qa_rid($rb) > $ida && $after === $before && qa_incr_calls() === 1, qa_d(['res' => $rb, 'after' => $after, 'incr' => qa_incr_calls()]));

    // F7: same session (the customer edits the phone): still the same row.
    $fp = qa_fp('f7');
    $a = qa_cust('f7a', $fp);
    qa_reset();
    $ida = qa_rid(qa_save($a));
    $a7 = $a;
    $a7['phone'] = '017' . wp_rand(10000000, 99999999);
    $a7['email'] = '';
    $GLOBALS['QA_KEYS'][] = cg_normalize_phone_number($a7['phone']);
    qa_reset();
    $rb = qa_save($a7);
    $after = qa_row($ida);
    $rows = qa_rows_by_fp($fp);
    qa_check($R, 'F7', 'same session, new phone: same row updated, 1 row, 0 increments', $ida > 0 && qa_rid($rb) === $ida && $after['phone'] === cg_normalize_phone_number($a7['phone']) && count($rows) === 1 && qa_incr_calls() === 0, qa_d(['res' => $rb, 'after' => $after, 'rows' => $rows, 'incr' => qa_incr_calls()]));

    // F8: B's next save (same session as before) updates B's own row.
    $fp = qa_fp('f8');
    $a = qa_cust('f8a', $fp);
    qa_reset();
    $ida = qa_rid(qa_save($a));
    $before = qa_row($ida);
    $b = qa_cust('f8b', $fp);
    qa_reset();
    $idb = qa_rid(qa_save($b));
    qa_reset();
    $rb2 = qa_save($b);
    $after = qa_row($ida);
    $rows = qa_rows_by_fp($fp);
    qa_check($R, 'F8', 'B saves again: B row updated, A unchanged, 2 rows, 0 increments', $idb > $ida && qa_rid($rb2) === $idb && $after === $before && count($rows) === 2 && qa_incr_calls() === 0, qa_d(['res' => $rb2, 'after' => $after, 'rows' => $rows, 'incr' => qa_incr_calls()]));

    // F10: control. Same phone on another device (other fingerprint), new
    // session: the phone alone still finds the row, as before.
    $a = qa_cust('f10a', qa_fp('f10a'));
    qa_reset();
    $ida = qa_rid(qa_save($a));
    $b = qa_cust('f10b', qa_fp('f10b'), $a['phone']);
    qa_reset();
    $rb = qa_save($b);
    $after = qa_row($ida);
    qa_check($R, 'F10', 'control: same phone, other fingerprint, new session: A row updated, 0 increments', $ida > 0 && qa_rid($rb) === $ida && $after['email'] === $b['email'] && qa_incr_calls() === 0, qa_d(['res' => $rb, 'after' => $after, 'incr' => qa_incr_calls()]));

    // F11: a phone shorter than 7 digits is not an identifier, alone or
    // with the fingerprint. Same fingerprint and the same short phone, new
    // session, other email: new row, A unchanged, 1 increment.
    $fp = qa_fp('f11');
    $a = qa_cust('f11a', $fp, '12345');
    qa_reset();
    $ida = qa_rid(qa_save($a));
    $before = qa_row($ida);
    $b = qa_cust('f11b', $fp, '12345');
    qa_reset();
    $rb = qa_save($b);
    $after = qa_row($ida);
    $rows = qa_rows_by_fp($fp);
    qa_check($R, 'F11', 'same fingerprint + same short phone (<7), other email, new session: new row, A unchanged, 1 increment', $ida > 0 && qa_rid($rb) > $ida && $after === $before && count($rows) === 2 && qa_incr_calls() === 1, qa_d(['res' => $rb, 'after' => $after, 'rows' => $rows, 'incr' => qa_incr_calls()]));

    qa_check($R, 'F9', 'no PHP warnings from the plugin', empty($GLOBALS['QA_WARN']), implode(' ; ', array_slice($GLOBALS['QA_WARN'], 0, 3)));
} catch (Throwable $e) {
    qa_check($R, 'RX', 'fatal', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $wpdb->query($wpdb->prepare("DELETE FROM {$GLOBALS['QA_T']} WHERE device_fingerprint LIKE %s OR session_id LIKE %s OR email LIKE %s", $GLOBALS['QA_TAG'] . '%', 't_' . $GLOBALS['QA_TAG'] . '%', $GLOBALS['QA_TAG'] . '%'));
    $wpdb->get_var('SELECT RELEASE_ALL_LOCKS()');
    foreach ($GLOBALS['QA_KEYS'] as $v) {
        delete_transient('cg_ordered_' . md5(strtolower(trim((string) $v))));
    }
    foreach (['cg_license_status' => 'option', 'cg_plugin_options' => 'option'] as $k => $t) {
        if ($GLOBALS['QA_ORIG'][$k] === null) {
            delete_option($k);
        } else {
            update_option($k, $GLOBALS['QA_ORIG'][$k]);
        }
    }
    if ($GLOBALS['QA_ORIG']['cg_last_synced'] === false) {
        delete_transient('cg_last_synced');
    } else {
        set_transient('cg_last_synced', $GLOBALS['QA_ORIG']['cg_last_synced'], WEEK_IN_SECONDS);
    }
    if ($GLOBALS['QA_ORIG']['cg_api_cache'] === false) {
        delete_transient('cg_api_cache');
    } else {
        set_transient('cg_api_cache', $GLOBALS['QA_ORIG']['cg_api_cache'], DAY_IN_SECONDS);
    }
}
$pass = 0;
foreach ($R as $row) {
    echo implode(' | ', $row) . "\n";
    $pass += $row[2] === 'PASS' ? 1 : 0;
}
echo 'SUMMARY ' . $pass . '/' . count($R) . ' ' . ($pass === count($R) ? 'PASS' : 'FAIL') . "\n";
