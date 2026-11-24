<?php
/**
 * OBS-HQL-11a: the tracker request (cg_save_checkout_data) looks up the
 * customer's incomplete checkout by session, device fingerprint or phone.
 * A row the merchant recovered (status 'recovered') is the record of that
 * recovery: a new checkout by the same customer must not overwrite it, it
 * must get a new row (and be counted once).
 *
 * Run on a local test site only (never production):
 *   wp eval-file tests/qa/incomplete-recovered-row-lookup-tests.php
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
class QA_RR_Die extends Exception {}
add_filter('wp_doing_ajax', '__return_true', PHP_INT_MAX);
add_filter('wp_die_ajax_handler', function () {
    return function ($msg = '') {
        throw new QA_RR_Die(is_string($msg) ? $msg : '');
    };
}, PHP_INT_MAX);
$o = is_array($GLOBALS['QA_ORIG']['cg_plugin_options']) ? $GLOBALS['QA_ORIG']['cg_plugin_options'] : [];
$o['api_key'] = 'qa-rr-key';
update_option('cg_plugin_options', $o);
$GLOBALS['QA_TAG'] = 'qarr' . wp_rand(10000, 99999);
$GLOBALS['QA_KEYS'] = [];
// A fixed old time, so any write to a recovered row shows in updated_at.
const QA_RR_OLD = '2020-01-01 00:00:00';

function qa_reset($usage = 10, $limit = 100) {
    set_transient('cg_api_cache', ['usage' => ['checkouts' => $usage, 'contact_reveals' => 0], 'limits' => ['checkouts' => $limit, 'contact_reveals' => 0]], DAY_IN_SECONDS);
    $GLOBALS['QA_LOG'] = [];
}
function qa_incr_calls() {
    return count(array_filter($GLOBALS['QA_LOG'], function ($p) { return $p === '/sites/increment-usage:checkouts'; }));
}
function qa_cust($sfx, $phone = null) {
    $c = ['fp' => $GLOBALS['QA_TAG'] . "fp$sfx", 'phone' => $phone ?: '017' . wp_rand(10000000, 99999999), 'email' => $GLOBALS['QA_TAG'] . "$sfx@example.test"];
    $GLOBALS['QA_KEYS'] = array_merge($GLOBALS['QA_KEYS'], [$c['fp'], 't_' . $c['fp'], $c['email'], cg_normalize_phone_number($c['phone'])]);
    return $c;
}
function qa_session(array $c) {
    if (function_exists('WC') && WC() && !WC()->session && function_exists('wc_load_cart')) {
        wc_load_cart();
    }
    if (function_exists('WC') && WC() && WC()->session) {
        $p = new ReflectionProperty(get_class(WC()->session), '_customer_id');
        $p->setAccessible(true);
        $p->setValue(WC()->session, 't_' . $c['fp']);
    }
}
function qa_save(array $c) {
    $_POST = [
        'action' => 'cg_save_checkout_data',
        'nonce' => wp_create_nonce('cg_save_checkout_data_nonce'),
        'billing_first_name' => 'QA',
        'billing_phone' => $c['phone'],
        'billing_email' => $c['email'],
        'device_fingerprint' => $c['fp'],
    ];
    $_REQUEST = $_POST;
    unset($_COOKIE['cg_device_fp']);
    qa_session($c);
    ob_start();
    try {
        cg_handle_save_checkout_data();
    } catch (QA_RR_Die $e) {
    }
    $out = (string) ob_get_clean();
    $_POST = $_REQUEST = [];
    return json_decode($out, true);
}
// The row the tracker wrote for this customer (newest by fingerprint).
function qa_row_id(array $c) {
    global $wpdb;
    return (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$GLOBALS['QA_T']} WHERE device_fingerprint = %s ORDER BY id DESC LIMIT 1", $c['fp']));
}
function qa_row($id) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare("SELECT id, status, email, session_id, device_fingerprint, phone, updated_at, recovered_order_id FROM {$GLOBALS['QA_T']} WHERE id = %d", $id), ARRAY_A);
}
// What Recover leaves behind: status 'recovered' with the order id. Written
// directly, so no order-placed mark is set (that mark is for 60s only).
function qa_set_status($id, $status) {
    global $wpdb;
    $wpdb->update($GLOBALS['QA_T'], ['status' => $status, 'recovered_order_id' => $status === 'recovered' ? 990000 + $id : null, 'updated_at' => QA_RR_OLD], ['id' => $id]);
    return qa_row($id);
}
function qa_rows_by_phone($phone) {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare("SELECT id, status, email FROM {$GLOBALS['QA_T']} WHERE phone = %s ORDER BY id", cg_normalize_phone_number($phone)), ARRAY_A);
}
function qa_new_incomplete(array $rows, $after_id, $email) {
    foreach ($rows as $r) {
        if ((int) $r['id'] > $after_id && $r['status'] === 'incomplete' && $r['email'] === $email) {
            return (int) $r['id'];
        }
    }
    return 0;
}

$R = [];
function qa_check(&$R, $id, $name, $ok, $detail) {
    $R[] = [$id, $name, $ok ? 'PASS' : 'FAIL', $detail];
}

try {
    // R1: Mahin's case. Same phone, new device and session after Recover:
    // the recovered row is untouched, a new incomplete row, counted once.
    qa_reset();
    $a = qa_cust('r1a');
    qa_save($a);
    $rid = qa_row_id($a);
    $before = qa_set_status($rid, 'recovered');
    $b = qa_cust('r1b', $a['phone']);
    qa_reset();
    $res = qa_save($b);
    $after = qa_row($rid);
    $rows = qa_rows_by_phone($a['phone']);
    $new = qa_new_incomplete($rows, $rid, $b['email']);
    qa_check($R, 'R1', 'same phone after Recover: recovered row unchanged, new incomplete row, 1 increment', $rid > 0 && !empty($res['success']) && $after === $before && $new > 0 && count($rows) === 2 && qa_incr_calls() === 1, 'res=' . wp_json_encode($res) . ' before=' . wp_json_encode($before) . ' after=' . wp_json_encode($after) . ' rows=' . wp_json_encode($rows) . ' incr=' . qa_incr_calls());

    // R2: same device and session (and phone) after Recover, new email.
    qa_reset();
    $a = qa_cust('r2');
    qa_save($a);
    $rid = qa_row_id($a);
    $before = qa_set_status($rid, 'recovered');
    $a2 = $a;
    $a2['email'] = $GLOBALS['QA_TAG'] . 'r2new@example.test';
    $GLOBALS['QA_KEYS'][] = $a2['email'];
    qa_reset();
    $res = qa_save($a2);
    $after = qa_row($rid);
    $rows = qa_rows_by_phone($a['phone']);
    $new = qa_new_incomplete($rows, $rid, $a2['email']);
    qa_check($R, 'R2', 'same device+session+phone after Recover: recovered row unchanged, new row, 1 increment', $rid > 0 && !empty($res['success']) && $after === $before && $new > 0 && count($rows) === 2 && qa_incr_calls() === 1, 'res=' . wp_json_encode($res) . ' after=' . wp_json_encode($after) . ' rows=' . wp_json_encode($rows) . ' incr=' . qa_incr_calls());

    // R3: the next save of that customer updates the new row, not the
    // recovered one, and counts nothing.
    qa_reset();
    $res = qa_save($a2);
    $after3 = qa_row($rid);
    $rows3 = qa_rows_by_phone($a['phone']);
    qa_check($R, 'R3', 'next save updates the new row: still 2 rows, recovered row unchanged, 0 increments', !empty($res['success']) && $after3 === $before && count($rows3) === 2 && qa_incr_calls() === 0, 'after=' . wp_json_encode($after3) . ' rows=' . wp_json_encode($rows3) . ' incr=' . qa_incr_calls());

    // R4: control. An incomplete row is still found and updated (no new
    // row, no increment): the filter skips only recovered rows.
    qa_reset();
    $a = qa_cust('r4');
    qa_save($a);
    $rid = qa_row_id($a);
    qa_set_status($rid, 'incomplete');
    $a4 = $a;
    $a4['email'] = $GLOBALS['QA_TAG'] . 'r4new@example.test';
    $GLOBALS['QA_KEYS'][] = $a4['email'];
    qa_reset();
    $res = qa_save($a4);
    $after = qa_row($rid);
    $rows = qa_rows_by_phone($a['phone']);
    qa_check($R, 'R4', 'incomplete row: updated in place, 1 row, 0 increments', !empty($res['success']) && count($rows) === 1 && $after['email'] === $a4['email'] && $after['updated_at'] !== QA_RR_OLD && qa_incr_calls() === 0, 'after=' . wp_json_encode($after) . ' rows=' . wp_json_encode($rows) . ' incr=' . qa_incr_calls());

    // R5: control. A row on hold is still the customer's row: updated in
    // place, status kept, no new row, no increment.
    qa_reset();
    $a = qa_cust('r5');
    qa_save($a);
    $rid = qa_row_id($a);
    qa_set_status($rid, 'hold');
    qa_reset();
    $res = qa_save($a);
    $after = qa_row($rid);
    $rows = qa_rows_by_phone($a['phone']);
    qa_check($R, 'R5', 'hold row: updated in place, status hold, 1 row, 0 increments', !empty($res['success']) && count($rows) === 1 && $after['status'] === 'hold' && $after['updated_at'] !== QA_RR_OLD && qa_incr_calls() === 0, 'after=' . wp_json_encode($after) . ' rows=' . wp_json_encode($rows) . ' incr=' . qa_incr_calls());

    // R6: two open rows share the phone (left by two earlier sessions).
    // The lookup takes the newest one (ORDER BY id DESC): it is updated,
    // the older row is untouched, nothing is counted.
    qa_reset();
    $a = qa_cust('r6');
    $norm = cg_normalize_phone_number($a['phone']);
    $ids = [];
    foreach (['o', 'n'] as $k) {
        $wpdb->insert($GLOBALS['QA_T'], ['session_id' => 't_' . $GLOBALS['QA_TAG'] . "r6$k", 'device_fingerprint' => $GLOBALS['QA_TAG'] . "fpr6$k", 'phone' => $norm, 'email' => $GLOBALS['QA_TAG'] . "r6$k@example.test", 'first_name' => 'Old', 'status' => 'incomplete', 'created_at' => QA_RR_OLD, 'updated_at' => QA_RR_OLD]);
        $ids[$k] = (int) $wpdb->insert_id;
    }
    $before_old = qa_row($ids['o']);
    qa_reset();
    $res = qa_save($a);
    $old = qa_row($ids['o']);
    $new = qa_row($ids['n']);
    $rows = qa_rows_by_phone($a['phone']);
    qa_check($R, 'R6', 'two open rows with the phone: the newest is updated, the older unchanged, 0 increments', $ids['n'] > $ids['o'] && (int) ($res['data']['record_id'] ?? 0) === $ids['n'] && $old === $before_old && $new['email'] === $a['email'] && $new['updated_at'] !== QA_RR_OLD && count($rows) === 2 && qa_incr_calls() === 0, 'res=' . wp_json_encode($res) . ' old=' . wp_json_encode($old) . ' new=' . wp_json_encode($new) . ' incr=' . qa_incr_calls());

    qa_check($R, 'R9', 'no PHP warnings from the plugin', empty($GLOBALS['QA_WARN']), implode(' ; ', array_slice($GLOBALS['QA_WARN'], 0, 3)));
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
