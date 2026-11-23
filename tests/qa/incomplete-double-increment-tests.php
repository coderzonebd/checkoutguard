<?php
/**
 * Incomplete checkout double increment: two tracker requests
 * (cg_save_checkout_data) for a new customer can both find no record, both
 * insert one and both count it. The new-record path holds a named lock and
 * looks again once the lock is held.
 *
 * Run on a local test site only (never production):
 *   wp eval-file tests/qa/incomplete-double-increment-tests.php
 * HTTP is answered by a pre_http_request filter in this process; nothing
 * leaves the machine. Rows, options and transients made here are removed.
 */
if (!defined('ABSPATH')) {
    exit;
}
global $wpdb;
$GLOBALS['QA_T'] = $wpdb->prefix . 'cg_incomplete_checkouts';
$GLOBALS['QA_ORIG'] = ['cg_plugin_options' => get_option('cg_plugin_options', null), 'cg_api_cache' => get_transient('cg_api_cache')];
$GLOBALS['QA_LOG'] = [];
add_filter('pre_http_request', function ($pre, $args, $url) {
    $body = json_decode((string) ($args['body'] ?? ''), true);
    $GLOBALS['QA_LOG'][] = preg_replace('#^.*?/api/v1#', '', (string) parse_url($url, PHP_URL_PATH)) . ':' . (is_array($body) ? (string) ($body['action_type'] ?? '') : '');
    if (strpos((string) $url, '/sites/status') !== false && is_array($GLOBALS['QA_SLOW'] ?? null)) {
        return qa_slow_status();
    }
    return ['headers' => new WpOrg\Requests\Utility\CaseInsensitiveDictionary([]), 'body' => '{"status":"success"}', 'response' => ['code' => 200, 'message' => ''], 'cookies' => [], 'filename' => null];
}, PHP_INT_MAX, 3);
$GLOBALS['QA_WARN'] = [];
set_error_handler(function ($no, $str, $file, $line) {
    if (strpos($file, 'checkoutguard') !== false) {
        $GLOBALS['QA_WARN'][] = "$str @ " . basename($file) . ":$line";
    }
    return false;
});
class QA_DI_Die extends Exception {}
add_filter('wp_doing_ajax', '__return_true', PHP_INT_MAX);
add_filter('wp_die_ajax_handler', function () {
    return function ($msg = '') {
        throw new QA_DI_Die(is_string($msg) ? $msg : '');
    };
}, PHP_INT_MAX);
$o = is_array($GLOBALS['QA_ORIG']['cg_plugin_options']) ? $GLOBALS['QA_ORIG']['cg_plugin_options'] : [];
$o['api_key'] = 'qa-di-key';
update_option('cg_plugin_options', $o);
$GLOBALS['QA_TAG'] = 'qadi' . wp_rand(10000, 99999);
// A second database connection plays the other tracker request.
$GLOBALS['QA_DB2'] = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
$GLOBALS['QA_DB2']->suppress_errors(true);
// The second tracker request's own connection (I8).
$GLOBALS['QA_DB3'] = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
$GLOBALS['QA_DB3']->set_prefix($wpdb->prefix);
$GLOBALS['QA_RACE'] = null;
$GLOBALS['QA_SLOW'] = null;
$GLOBALS['QA_ORDER_IDS'] = [];
$GLOBALS['QA_MARK_KEYS'] = [];
$GLOBALS['QA_ORIG']['cg_license_status'] = get_option('cg_license_status', null);
$GLOBALS['QA_ORIG']['cg_last_synced'] = get_transient('cg_last_synced');

function qa_reset($usage = 10, $limit = 100) {
    set_transient('cg_api_cache', ['usage' => ['checkouts' => $usage, 'contact_reveals' => 0], 'limits' => ['checkouts' => $limit, 'contact_reveals' => 0]], DAY_IN_SECONDS);
    $GLOBALS['QA_LOG'] = [];
}
function qa_usage() {
    $c = get_transient('cg_api_cache');
    return (int) ($c['usage']['checkouts'] ?? -1);
}
function qa_incr_calls() {
    return count(array_filter($GLOBALS['QA_LOG'], function ($p) { return $p === '/sites/increment-usage:checkouts'; }));
}
function qa_rows($fp) {
    global $wpdb;
    return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$GLOBALS['QA_T']} WHERE device_fingerprint = %s", $fp));
}
function qa_cust($sfx) {
    return ['fp' => $GLOBALS['QA_TAG'] . "fp$sfx", 'phone' => '017' . wp_rand(10000000, 99999999), 'email' => $GLOBALS['QA_TAG'] . "$sfx@example.test"];
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
    } catch (QA_DI_Die $e) {
    }
    $out = (string) ob_get_clean();
    $_POST = $_REQUEST = [];
    return json_decode($out, true);
}
// The other request's row, written on the second connection.
function qa_other_insert(array $c) {
    $db2 = $GLOBALS['QA_DB2'];
    $now = current_time('mysql');
    $db2->insert($GLOBALS['QA_T'], [
        'email' => $c['email'], 'first_name' => 'QA', 'phone' => cg_normalize_phone_number($c['phone']),
        'session_id' => 't_' . $c['fp'], 'device_fingerprint' => $c['fp'], 'status' => 'incomplete',
        'cart_details' => '[]', 'cart_value' => 0, 'created_at' => $now, 'updated_at' => $now,
    ]);
    return (int) $db2->insert_id;
}
// Race: the other request inserts its row right after this request's first
// lookup ran and before anything else (the window between SELECT and INSERT).
add_filter('query', function ($q) {
    $r = &$GLOBALS['QA_RACE'];
    if (!is_array($r) || $r['done']) {
        return $q;
    }
    if (!$r['seen']) {
        if (strpos($q, 'ORDER BY id DESC LIMIT 1') !== false && strpos($q, $r['c']['fp']) !== false) {
            $r['seen'] = true;
        }
        return $q;
    }
    $r['done'] = true;
    $r['id'] = qa_other_insert($r['c']);
    return $q;
}, PHP_INT_MAX);
// Mahin's case: no cg_api_cache and a slow /sites/status. While the first
// tracker request waits for HQ, the second one (tracker.js sends it a moment
// later) runs on its own database connection. HQ's answer is played by
// writing cg_api_cache before the status call returns; the call itself
// returns an error, so no signed payload is needed.
function qa_slow_status() {
    $s = &$GLOBALS['QA_SLOW'];
    $s['status_calls']++;
    // I10: is the insert lock held while this request waits for HQ?
    $s['free_at_status'][] = qa_lock_free();
    usleep(300000);
    if (!$s['second_done']) {
        $s['second_done'] = true;
        $keep = $GLOBALS['wpdb'];
        $GLOBALS['wpdb'] = $GLOBALS['QA_DB3'];
        try {
            $s['second'] = qa_save($s['c']);
        } finally {
            $GLOBALS['wpdb'] = $keep;
        }
    }
    set_transient('cg_api_cache', ['usage' => ['checkouts' => 10, 'contact_reveals' => 0], 'limits' => ['checkouts' => 100, 'contact_reveals' => 0]], DAY_IN_SECONDS);
    return new WP_Error('qa_slow_hq', 'status answered by the test');
}
function qa_decr_calls() {
    return count(array_filter($GLOBALS['QA_LOG'], function ($p) { return $p === '/sites/decrement-usage:checkouts'; }));
}
function qa_lock_name() {
    return function_exists('cg_incomplete_insert_lock_name') ? cg_incomplete_insert_lock_name() : '';
}
function qa_lock_free() {
    $n = qa_lock_name();
    if ($n === '') {
        return null;
    }
    return (string) $GLOBALS['QA_DB2']->get_var($GLOBALS['QA_DB2']->prepare('SELECT IS_FREE_LOCK(%s)', $n)) === '1';
}

$R = [];
function qa_check(&$R, $id, $name, $ok, $detail) {
    $R[] = [$id, $name, $ok ? 'PASS' : 'FAIL', $detail];
}

try {
    // I1: new customer, one request: one row, counted once, lock free after.
    qa_reset();
    $c = qa_cust('i1');
    $res = qa_save($c);
    $free = qa_lock_free();
    qa_check($R, 'I1', 'new customer: 1 row, 1 increment, usage +1, lock free after', !empty($res['success']) && qa_rows($c['fp']) === 1 && qa_incr_calls() === 1 && qa_usage() === 11 && $free === true, 'res=' . wp_json_encode($res) . ' rows=' . qa_rows($c['fp']) . ' incr=' . qa_incr_calls() . ' usage=' . qa_usage() . ' free=' . var_export($free, true));

    // I2: same customer again: update only, no increment, lock free after.
    $GLOBALS['QA_LOG'] = [];
    $res = qa_save($c);
    $free = qa_lock_free();
    qa_check($R, 'I2', 'same customer again: update, no increment, lock free after', !empty($res['success']) && qa_rows($c['fp']) === 1 && qa_incr_calls() === 0 && qa_usage() === 11 && $free === true, 'res=' . wp_json_encode($res) . ' rows=' . qa_rows($c['fp']) . ' incr=' . qa_incr_calls() . ' free=' . var_export($free, true));

    // I3: the other request inserts between this request's lookup and insert:
    // still 1 row, this request counts nothing and updates the other row.
    qa_reset();
    $c = qa_cust('i3');
    $GLOBALS['QA_RACE'] = ['c' => $c, 'seen' => false, 'done' => false, 'id' => 0];
    $res = qa_save($c);
    $race = $GLOBALS['QA_RACE'];
    $GLOBALS['QA_RACE'] = null;
    $free = qa_lock_free();
    qa_check($R, 'I3', 'insert between lookup and insert: 1 row, 0 increment, updates the other row', $race['done'] && $race['id'] > 0 && !empty($res['success']) && (int) ($res['data']['record_id'] ?? 0) === $race['id'] && qa_rows($c['fp']) === 1 && qa_incr_calls() === 0 && qa_usage() === 10 && $free === true, 'race=' . wp_json_encode($race) . ' res=' . wp_json_encode($res) . ' rows=' . qa_rows($c['fp']) . ' incr=' . qa_incr_calls() . ' usage=' . qa_usage() . ' free=' . var_export($free, true));

    // I12: limit reached, but the other request inserts the row before this
    // request's lookup under the lock: that row is updated (success, 0
    // increment), not refused with 403.
    qa_reset(100, 100);
    $c = qa_cust('i12');
    $GLOBALS['QA_RACE'] = ['c' => $c, 'seen' => false, 'done' => false, 'id' => 0];
    $res = qa_save($c);
    $race = $GLOBALS['QA_RACE'];
    $GLOBALS['QA_RACE'] = null;
    $free = qa_lock_free();
    qa_check($R, 'I12', 'limit reached, other row found under the lock: update it, 0 increment, no 403', $race['done'] && $race['id'] > 0 && !empty($res['success']) && empty($res['data']['limit_reached']) && (int) ($res['data']['record_id'] ?? 0) === $race['id'] && qa_rows($c['fp']) === 1 && qa_incr_calls() === 0 && qa_usage() === 100 && $free === true, 'race=' . wp_json_encode($race) . ' res=' . wp_json_encode($res) . ' rows=' . qa_rows($c['fp']) . ' incr=' . qa_incr_calls() . ' usage=' . qa_usage() . ' free=' . var_export($free, true));

    // I4: the lock is held by the other request: nothing is written or
    // counted, the answer says retry; once it is released, one row.
    qa_reset();
    $c = qa_cust('i4');
    $n = qa_lock_name();
    $got = $n !== '' ? (string) $GLOBALS['QA_DB2']->get_var($GLOBALS['QA_DB2']->prepare('SELECT GET_LOCK(%s, 0)', $n)) : '';
    $t = microtime(true);
    $res = qa_save($c);
    $waited = microtime(true) - $t;
    $rows_held = qa_rows($c['fp']);
    $incr_held = qa_incr_calls();
    if ($n !== '') {
        $GLOBALS['QA_DB2']->get_var($GLOBALS['QA_DB2']->prepare('SELECT RELEASE_LOCK(%s)', $n));
    }
    qa_check($R, 'I4', 'lock held elsewhere: no row, no increment, answer retry, waited at most the lock timeout', $got === '1' && empty($res['success']) && !empty($res['data']['retry']) && $rows_held === 0 && $incr_held === 0 && qa_usage() === 10 && $waited < 10, "got=$got res=" . wp_json_encode($res) . " rows=$rows_held incr=$incr_held waited=" . round($waited, 1));
    $GLOBALS['QA_LOG'] = [];
    $res = qa_save($c);
    qa_check($R, 'I4b', 'after the lock is released: 1 row, 1 increment', !empty($res['success']) && qa_rows($c['fp']) === 1 && qa_incr_calls() === 1 && qa_usage() === 11, 'res=' . wp_json_encode($res) . ' rows=' . qa_rows($c['fp']) . ' incr=' . qa_incr_calls());

    // I5: limit reached for a new customer: no row, no increment, lock free.
    qa_reset(100, 100);
    $c = qa_cust('i5');
    $res = qa_save($c);
    $free = qa_lock_free();
    qa_check($R, 'I5', 'limit reached: no row, no increment, lock free after', empty($res['success']) && !empty($res['data']['limit_reached']) && qa_rows($c['fp']) === 0 && qa_incr_calls() === 0 && $free === true, 'res=' . wp_json_encode($res) . ' rows=' . qa_rows($c['fp']) . ' free=' . var_export($free, true));

    // I7: a request skipped because the order was just placed (H2) leaves
    // the lock free.
    if (function_exists('cg_recently_placed_order') && defined('CG_ORDER_PLACED_MARK_TTL')) {
        qa_reset();
        $c = qa_cust('i7');
        set_transient('cg_ordered_' . md5($c['fp']), 1, CG_ORDER_PLACED_MARK_TTL);
        $res = qa_save($c);
        delete_transient('cg_ordered_' . md5($c['fp']));
        $free = qa_lock_free();
        qa_check($R, 'I7', 'order just placed: skipped, no row, no increment, lock free after', !empty($res['success']) && ($res['data']['skipped'] ?? '') === 'order_placed' && qa_rows($c['fp']) === 0 && qa_incr_calls() === 0 && $free === true, 'res=' . wp_json_encode($res) . ' rows=' . qa_rows($c['fp']) . ' free=' . var_export($free, true));
    }

    // I8 (Mahin's repro): no cg_api_cache, slow HQ status, a second tracker
    // request while the first waits: 1 row and 1 increment. After the order
    // the row is gone and the decrement evens it out: net 0.
    $GLOBALS['QA_LOG'] = [];
    delete_transient('cg_api_cache');
    $c = qa_cust('i8');
    $GLOBALS['QA_SLOW'] = ['c' => $c, 'status_calls' => 0, 'second_done' => false, 'second' => null];
    $res = qa_save($c);
    $slow = $GLOBALS['QA_SLOW'];
    $GLOBALS['QA_SLOW'] = null;
    $rows = qa_rows($c['fp']);
    $incr = qa_incr_calls();
    $free = qa_lock_free();
    qa_check($R, 'I8', 'no cache, slow HQ, second request meanwhile: 1 row, 1 increment', $slow['second_done'] && !empty($res['success']) && $rows === 1 && $incr === 1 && $free === true, 'first=' . wp_json_encode($res) . ' second=' . wp_json_encode($slow['second']) . " status_calls={$slow['status_calls']} rows=$rows incr=$incr free=" . var_export($free, true));
    qa_session($c);
    $order = wc_create_order(['status' => 'pending']);
    $order->set_billing_email($c['email']);
    $order->set_billing_phone($c['phone']);
    $order->update_meta_data('_cg_device_fingerprint', $c['fp']);
    $order->save();
    $GLOBALS['QA_ORDER_IDS'][] = $order->get_id();
    $GLOBALS['QA_MARK_KEYS'] = array_merge($GLOBALS['QA_MARK_KEYS'], ['t_' . $c['fp'], $c['fp'], $c['email'], cg_normalize_phone_number($c['phone'])]);
    cg_delete_incomplete_checkout_on_order_completion($order->get_id());
    // With the async decrement (H1) the decrement is a WP-Cron event: run
    // the events scheduled for this order now and remove them. Events
    // queued by earlier tests (other orders) are not part of this count.
    foreach ((array) _get_cron_array() as $ts => $hooks) {
        foreach ((array) ($hooks['cg_send_usage_decrements'] ?? []) as $ev) {
            if ((string) ($ev['args'][2] ?? '') !== 'order-' . $order->get_id()) {
                continue;
            }
            wp_unschedule_event($ts, 'cg_send_usage_decrements', $ev['args']);
            do_action_ref_array('cg_send_usage_decrements', $ev['args']);
        }
    }
    $decr = qa_decr_calls();
    qa_check($R, 'I8b', 'after the order: 0 rows, increments - decrements = 0', qa_rows($c['fp']) === 0 && $incr - $decr === 0, 'rows=' . qa_rows($c['fp']) . " incr=$incr decr=$decr");

    // I10: the insert lock is not held while HQ is asked. With no
    // cg_api_cache the limit check syncs with HQ (up to 15s); every other
    // new customer's tracker request would wait for that lock meanwhile.
    $GLOBALS['QA_LOG'] = [];
    delete_transient('cg_api_cache');
    $c = qa_cust('i10');
    $GLOBALS['QA_SLOW'] = ['c' => $c, 'status_calls' => 0, 'second_done' => true, 'second' => null, 'free_at_status' => []];
    $res = qa_save($c);
    $slow = $GLOBALS['QA_SLOW'];
    $GLOBALS['QA_SLOW'] = null;
    $rows = qa_rows($c['fp']);
    $incr = qa_incr_calls();
    $at = $slow['free_at_status'];
    qa_check($R, 'I10', 'no cache: the HQ sync runs with the insert lock free; 1 row, 1 increment', $slow['status_calls'] >= 1 && $at === array_fill(0, count($at), true) && !empty($res['success']) && $rows === 1 && $incr === 1 && qa_lock_free() === true, 'res=' . wp_json_encode($res) . " status_calls={$slow['status_calls']} free_at_status=" . wp_json_encode($at) . " rows=$rows incr=$incr");

    // I10b: the same for the contact reveal period sync (the cached period
    // is over and its reveals are used up, so the decision asks HQ first).
    $GLOBALS['QA_LOG'] = [];
    $GLOBALS['QA_ORIG']['period'] = [get_option(CG_PERIOD_SYNCED_FOR_OPTION, null), get_transient(CG_PERIOD_SYNC_BACKOFF_TRANSIENT)];
    delete_option(CG_PERIOD_SYNCED_FOR_OPTION);
    delete_transient(CG_PERIOD_SYNC_BACKOFF_TRANSIENT);
    set_transient('cg_api_cache', ['usage' => ['checkouts' => 10, 'contact_reveals' => 1], 'limits' => ['checkouts' => 100, 'contact_reveals' => 1], 'usage_period_end' => gmdate('Y-m-d\TH:i:s\Z', time() - 3600)], DAY_IN_SECONDS);
    $c = qa_cust('i10b');
    $GLOBALS['QA_SLOW'] = ['c' => $c, 'status_calls' => 0, 'second_done' => true, 'second' => null, 'free_at_status' => []];
    $res = qa_save($c);
    $slow = $GLOBALS['QA_SLOW'];
    $GLOBALS['QA_SLOW'] = null;
    $rows = qa_rows($c['fp']);
    $incr = qa_incr_calls();
    $at = $slow['free_at_status'];
    qa_check($R, 'I10b', 'reveal period sync runs with the insert lock free; 1 row, 1 increment', $slow['status_calls'] >= 1 && $at === array_fill(0, count($at), true) && !empty($res['success']) && $rows === 1 && $incr === 1 && qa_lock_free() === true, 'res=' . wp_json_encode($res) . " status_calls={$slow['status_calls']} free_at_status=" . wp_json_encode($at) . " rows=$rows incr=$incr");

    // I11: GET_LOCK returns NULL (a database error, not a timeout): the
    // request does not fail closed. It saves without the lock as before the
    // lock existed (1 row, 1 increment), writes one log line and releases
    // nothing it does not hold.
    qa_reset();
    $c = qa_cust('i11');
    $GLOBALS['QA_NULL_LOCK'] = 0;
    $null_lock = function ($q) {
        if (stripos($q, 'SELECT GET_LOCK(') === 0) {
            $GLOBALS['QA_NULL_LOCK']++;
            return 'SELECT NULL';
        }
        return $q;
    };
    add_filter('query', $null_lock, PHP_INT_MAX - 1);
    $log = wp_tempnam('qadi-log');
    $old_log = ini_set('error_log', $log);
    $res = qa_save($c);
    ini_set('error_log', (string) $old_log);
    remove_filter('query', $null_lock, PHP_INT_MAX - 1);
    $lines = array_values(array_filter(array_map('trim', (array) @file($log)), function ($l) { return stripos($l, 'CheckoutGuard') !== false && stripos($l, 'lock') !== false; }));
    @unlink($log);
    $free = qa_lock_free();
    qa_check($R, 'I11', 'GET_LOCK returns NULL: saved without the lock (1 row, 1 increment), one log line', $GLOBALS['QA_NULL_LOCK'] === 1 && !empty($res['success']) && qa_rows($c['fp']) === 1 && qa_incr_calls() === 1 && qa_usage() === 11 && count($lines) === 1 && $free === true && empty($GLOBALS['cg_incomplete_insert_locked']), 'res=' . wp_json_encode($res) . ' get_lock_calls=' . $GLOBALS['QA_NULL_LOCK'] . ' rows=' . qa_rows($c['fp']) . ' incr=' . qa_incr_calls() . ' log_lines=' . count($lines) . ' free=' . var_export($free, true));

    // I6: the lock name fits GET_LOCK (64 chars) and differs per table
    // prefix, so two sites on one database do not wait for each other.
    $n = qa_lock_name();
    $p = $wpdb->prefix;
    $wpdb->prefix = 'qadi_other_';
    $n2 = qa_lock_name();
    $wpdb->prefix = $p;
    qa_check($R, 'I6', 'lock name exists, <= 64 chars, differs per table prefix', $n !== '' && strlen($n) <= 64 && strpos($n, 'cg_') === 0 && $n2 !== $n, "name=$n other=$n2");

    qa_check($R, 'I9', 'no PHP warnings from the plugin', empty($GLOBALS['QA_WARN']), implode(' ; ', array_slice($GLOBALS['QA_WARN'], 0, 3)));
} catch (Throwable $e) {
    qa_check($R, 'IX', 'fatal', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $GLOBALS['QA_RACE'] = null;
    $wpdb->query($wpdb->prepare("DELETE FROM {$GLOBALS['QA_T']} WHERE device_fingerprint LIKE %s OR session_id LIKE %s OR email LIKE %s", $GLOBALS['QA_TAG'] . '%', 't_' . $GLOBALS['QA_TAG'] . '%', $GLOBALS['QA_TAG'] . '%'));
    $wpdb->get_var('SELECT RELEASE_ALL_LOCKS()');
    $GLOBALS['QA_DB2']->close();
    $GLOBALS['QA_DB3']->close();
    foreach ($GLOBALS['QA_ORDER_IDS'] as $id) {
        $o2 = wc_get_order($id);
        if ($o2) {
            $o2->delete(true);
        }
    }
    foreach ($GLOBALS['QA_MARK_KEYS'] as $v) {
        delete_transient('cg_ordered_' . md5(strtolower(trim((string) $v))));
    }
    if ($GLOBALS['QA_ORIG']['cg_license_status'] === null) {
        delete_option('cg_license_status');
    } else {
        update_option('cg_license_status', $GLOBALS['QA_ORIG']['cg_license_status']);
    }
    if ($GLOBALS['QA_ORIG']['cg_last_synced'] === false) {
        delete_transient('cg_last_synced');
    } else {
        set_transient('cg_last_synced', $GLOBALS['QA_ORIG']['cg_last_synced'], WEEK_IN_SECONDS);
    }
    if ($GLOBALS['QA_ORIG']['cg_plugin_options'] === null) {
        delete_option('cg_plugin_options');
    } else {
        update_option('cg_plugin_options', $GLOBALS['QA_ORIG']['cg_plugin_options']);
    }
    if (isset($GLOBALS['QA_ORIG']['period'])) {
        if ($GLOBALS['QA_ORIG']['period'][0] === null) {
            delete_option(CG_PERIOD_SYNCED_FOR_OPTION);
        } else {
            update_option(CG_PERIOD_SYNCED_FOR_OPTION, $GLOBALS['QA_ORIG']['period'][0], false);
        }
        if ($GLOBALS['QA_ORIG']['period'][1] === false) {
            delete_transient(CG_PERIOD_SYNC_BACKOFF_TRANSIENT);
        } else {
            set_transient(CG_PERIOD_SYNC_BACKOFF_TRANSIENT, $GLOBALS['QA_ORIG']['period'][1], HOUR_IN_SECONDS);
        }
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
echo 'SUMMARY ' . $pass . '/' . count($R) . " PASS\n";
