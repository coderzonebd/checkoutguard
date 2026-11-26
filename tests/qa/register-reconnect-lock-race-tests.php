<?php
/**
 * Reconnect / Sync Now lock must be taken atomically (register-gate
 * follow-up). Two requests that both read "no lock" before either writes it
 * must not both send a register.
 *
 * The race is staged with a 'query' filter: right before this request writes
 * the lock row, a competing request's lock row is inserted straight into the
 * options table (same option_name), as if another click took the lock in
 * between. A read-then-write lock still writes over it and registers; an
 * atomic lock sees the row and backs off.
 *
 * Run on a local test site only (never production):
 *   wp eval-file tests/qa/register-reconnect-lock-race-tests.php
 * HTTP is answered by a pre_http_request filter in this process; nothing
 * leaves the machine. Options are restored at the end.
 */
if (!defined('ABSPATH')) {
    exit;
}
global $wpdb;
$GLOBALS['QA_NOW'] = 1800000000;
$GLOBALS['QA_ORIG'] = [];
foreach (['cg_plugin_options', 'cg_license_status', 'cg_register_next_allowed_at', 'cg_sitekey_state'] as $o) {
    $GLOBALS['QA_ORIG'][$o] = get_option($o, null);
}
add_filter('cg_sitekey_now', function () { return $GLOBALS['QA_NOW']; });
$GLOBALS['QA_LOG'] = [];
add_filter('pre_http_request', function ($pre, $args, $url) {
    $path = preg_replace('#^.*?/api/v1#', '', (string) parse_url($url, PHP_URL_PATH));
    $GLOBALS['QA_LOG'][] = $path;
    return new WP_Error('http_request_failed', 'cURL error 7: Failed to connect');
}, PHP_INT_MAX, 3);
$GLOBALS['QA_WARN'] = [];
set_error_handler(function ($no, $str, $file, $line) {
    if (strpos($file, 'checkoutguard') !== false) {
        $GLOBALS['QA_WARN'][] = "$str @ " . basename($file) . ":$line";
    }
    return false;
});
$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
wp_set_current_user((int) ($admins[0] ?? 1));

$GLOBALS['QA_LK'] = defined('CG_REGISTER_RECONNECT_LOCK') ? CG_REGISTER_RECONNECT_LOCK : 'cg_register_reconnect_lock';
function qa_lock_rows_delete() {
    global $wpdb;
    $lk = $GLOBALS['QA_LK'];
    foreach ([$lk, '_transient_' . $lk, '_transient_timeout_' . $lk] as $n) {
        $wpdb->delete($wpdb->options, ['option_name' => $n]);
        wp_cache_delete($n, 'options');
    }
    wp_cache_delete('notoptions', 'options'); wp_cache_delete('alloptions', 'options');
}
function qa_reset() {
    global $wpdb;
    foreach (['cg_sitekey_state', 'cg_sitekey_next_allowed_at', 'cg_sitekey_lock', 'cg_register_next_allowed_at', 'cg_register_already_registered_at'] as $o) {
        $wpdb->delete($wpdb->options, ['option_name' => $o]);
        wp_cache_delete($o, 'options');
    }
    qa_lock_rows_delete();
    $o = is_array($GLOBALS['QA_ORIG']['cg_plugin_options']) ? $GLOBALS['QA_ORIG']['cg_plugin_options'] : [];
    unset($o['api_key']);
    cg_update_options_from_hq($o);
    update_option('cg_license_status', 'active');
    $GLOBALS['QA_LOG'] = []; $GLOBALS['QA_NOW'] = 1800000000;
}
function qa_regs() {
    return count(array_filter($GLOBALS['QA_LOG'], function ($p) { return $p === '/sites/register'; }));
}
// Before the first INSERT/UPDATE that writes the lock row, insert the
// competitor's row (value = a lock still valid for 45 s) with the same name.
function qa_arm_competitor() {
    $GLOBALS['QA_RACED'] = false;
    $GLOBALS['QA_RACE_CB'] = function ($q) {
        global $wpdb;
        $lk = $GLOBALS['QA_LK'];
        if ($GLOBALS['QA_RACED'] || !preg_match('/^\s*(INSERT|UPDATE|REPLACE)\b/i', $q)) {
            return $q;
        }
        if (!preg_match("/'((?:_transient_)?" . preg_quote($lk, '/') . ")'/", $q, $m)) {
            return $q;
        }
        $GLOBALS['QA_RACED'] = true;
        remove_filter('query', $GLOBALS['QA_RACE_CB']);
        $val = (strpos($m[1], '_transient_') === 0) ? '1' : (string) (time() + 45);
        $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $m[1], $val));
        if ($m[1] !== $lk) {
            $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", '_transient_timeout_' . $lk, (string) (time() + 45)));
        }
        return $q;
    };
    add_filter('query', $GLOBALS['QA_RACE_CB']);
}
$GLOBALS['R'] = [];
function ok($id, $name, $pass, $detail) { $GLOBALS['R'][] = [$id, $name, $pass ? 'PASS' : 'FAIL', $detail]; }

// K1: Reconnect loses the race: another click took the lock between this
// request's check and its write. This request must send no register.
qa_reset();
cg_register_site_with_hq();
$GLOBALS['QA_NOW'] += 61; $GLOBALS['QA_LOG'] = [];
qa_arm_competitor();
$a = cg_register_reconnect_attempt();
remove_filter('query', $GLOBALS['QA_RACE_CB']);
$n = qa_regs();
ok('K1', 'Reconnect, lock taken by another request in between: running, 0 register', $GLOBALS['QA_RACED'] && $a === 'running' && $n === 0, 'raced=' . var_export($GLOBALS['QA_RACED'], true) . " a=$a register_calls=$n");

// K1-ctl: no competitor: Reconnect sends exactly 1 register and frees the lock.
qa_reset();
cg_register_site_with_hq();
$GLOBALS['QA_NOW'] += 61; $GLOBALS['QA_LOG'] = [];
$a = cg_register_reconnect_attempt();
$n = qa_regs();
$left = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name IN (%s, %s)", $GLOBALS['QA_LK'], '_transient_' . $GLOBALS['QA_LK']));
ok('K1-ctl', 'no competitor: tried, exactly 1 register, lock row gone', $a === 'tried' && $n === 1 && (int) $left === 0, "a=$a register_calls=$n left=$left");

// K2: a stale lock (expired 1 s ago, its request died) does not block
// Reconnect for good: one register.
qa_reset();
cg_register_site_with_hq();
$GLOBALS['QA_NOW'] += 61; $GLOBALS['QA_LOG'] = [];
$wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $GLOBALS['QA_LK'], (string) (time() - 1)));
$wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'no'), (%s, %s, 'no')", '_transient_' . $GLOBALS['QA_LK'], '_transient_timeout_' . $GLOBALS['QA_LK'], (string) (time() - 1)));
wp_cache_delete('notoptions', 'options'); wp_cache_delete('alloptions', 'options');
$a = cg_register_reconnect_attempt();
$n = qa_regs();
ok('K2', 'stale lock (expired): tried, 1 register', $a === 'tried' && $n === 1, "a=$a register_calls=$n");

// K3: two requests race to take over the same stale lock. The competitor
// takes it over right before this request writes; this request must not
// take it too (only one stale takeover wins), and the row keeps the
// competitor's value.
qa_reset();
cg_register_site_with_hq();
$GLOBALS['QA_NOW'] += 61; $GLOBALS['QA_LOG'] = [];
$wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $GLOBALS['QA_LK'], (string) (time() - 1)));
$wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'no'), (%s, %s, 'no')", '_transient_' . $GLOBALS['QA_LK'], '_transient_timeout_' . $GLOBALS['QA_LK'], (string) (time() - 1)));
wp_cache_delete('notoptions', 'options'); wp_cache_delete('alloptions', 'options');
$GLOBALS['QA_K3_VAL'] = (string) (time() + 45);
$GLOBALS['QA_RACED'] = false;
$GLOBALS['QA_RACE_CB'] = function ($q) {
    global $wpdb;
    $lk = $GLOBALS['QA_LK'];
    if ($GLOBALS['QA_RACED'] || !preg_match('/^\s*(INSERT|UPDATE|REPLACE)\b/i', $q) || strpos($q, $lk) === false) {
        return $q;
    }
    $GLOBALS['QA_RACED'] = true;
    remove_filter('query', $GLOBALS['QA_RACE_CB']);
    // The competitor's own stale takeover (same conditional UPDATE).
    $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value < %d", $GLOBALS['QA_K3_VAL'], $lk, time()));
    $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s", $GLOBALS['QA_K3_VAL'], '_transient_timeout_' . $lk));
    return $q;
};
add_filter('query', $GLOBALS['QA_RACE_CB']);
$a = cg_register_reconnect_attempt();
remove_filter('query', $GLOBALS['QA_RACE_CB']);
$n = qa_regs();
$row = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $GLOBALS['QA_LK']));
ok('K3', 'stale lock taken over by a competitor first: running, 0 register, competitor value kept', $GLOBALS['QA_RACED'] && $a === 'running' && $n === 0 && $row === $GLOBALS['QA_K3_VAL'], 'raced=' . var_export($GLOBALS['QA_RACED'], true) . " a=$a register_calls=$n row=" . var_export($row, true));

// K3b: the helper itself: of two takeovers of one stale row, only the
// first gets the lock.
qa_reset();
$wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $GLOBALS['QA_LK'], (string) (time() - 1)));
$f3 = function_exists('cg_register_reconnect_lock_acquire');
$l1 = $f3 ? cg_register_reconnect_lock_acquire() : false;
$l2 = $f3 ? cg_register_reconnect_lock_acquire() : false;
ok('K3b', 'stale row: first acquire wins, second gets false', $f3 && $l1 !== false && $l2 === false, 'helper=' . var_export($f3, true) . ' l1=' . var_export($l1, true) . ' l2=' . var_export($l2, true));

// K4: release removes only this request's own lock. After its lock expired
// and another request took it over, a late release must leave that row.
qa_reset();
$f4 = function_exists('cg_register_reconnect_lock_release');
$mine = $f3 ? cg_register_reconnect_lock_acquire() : false;
$other = (string) (time() + 90);
$wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s", $other, $GLOBALS['QA_LK']));
if ($f4) {
    cg_register_reconnect_lock_release($mine);
}
$row4 = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $GLOBALS['QA_LK']));
ok('K4', 'release of an old value leaves the other request\'s lock', $f4 && $mine !== false && $row4 === $other, 'mine=' . var_export($mine, true) . ' row=' . var_export($row4, true));

// K5: held() reads the table, not the options cache. A stale cache that
// says the option does not exist (notoptions) must not hide a held lock.
qa_reset();
$mine5 = $f3 ? cg_register_reconnect_lock_acquire() : false;
wp_cache_set('notoptions', [$GLOBALS['QA_LK'] => true], 'options');
wp_cache_delete($GLOBALS['QA_LK'], 'options');
$held5 = function_exists('cg_register_reconnect_lock_held') ? cg_register_reconnect_lock_held() : false;
$auto5 = $wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $GLOBALS['QA_LK']));
ok('K5', 'held() true despite a stale notoptions cache; row autoload=no; value is an int expiry', $mine5 !== false && $held5 === true && $auto5 === 'no' && ctype_digit((string) $mine5), 'mine=' . var_export($mine5, true) . ' held=' . var_export($held5, true) . ' autoload=' . var_export($auto5, true));
wp_cache_delete('notoptions', 'options');

// restore
qa_reset();
foreach ($GLOBALS['QA_ORIG'] as $name => $val) {
    if ($val === null) { delete_option($name); } elseif ($name === 'cg_plugin_options') { cg_update_options_from_hq($val); } else { update_option($name, $val); }
}
restore_error_handler();
$R = $GLOBALS['R'];
foreach ($R as $r) echo implode(' | ', $r), "\n";
echo 'warnings_total=' . count($GLOBALS['QA_WARN']) . "\n"; foreach (array_unique($GLOBALS['QA_WARN']) as $w) echo "  W: $w\n";
$f = count(array_filter($R, function ($r) { return $r[2] !== 'PASS'; })) + (count($GLOBALS['QA_WARN']) ? 1 : 0);
echo "SUMMARY " . (count($R) - $f) . '/' . count($R) . " PASS\n";
