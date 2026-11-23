<?php
/**
 * H1 (1.5.1): placing an order must not wait for HQ. The incomplete-checkout
 * cleanup that runs on woocommerce_checkout_order_processed / new_order /
 * thankyou lowers the local usage copy at once and queues the HQ
 * decrement-usage calls in a WP-Cron single event; the event handler sends
 * them later (at most CG_USAGE_DECREMENT_MAX per run).
 *
 * Run on a local test site only (never production):
 *   wp eval-file tests/qa/usage-decrement-defer-tests.php
 * HTTP is answered by a pre_http_request filter in this process (each call
 * sleeps QA_DELAY seconds to stand for a slow HQ); nothing leaves the
 * machine. Options, rows, orders and cron events made here are removed.
 */
if (!defined('ABSPATH')) {
    exit;
}
// Fallbacks so the file also runs (and fails) on a build without H1.
if (!defined('CG_USAGE_DECREMENT_HOOK')) {
    define('CG_USAGE_DECREMENT_HOOK', 'cg_send_usage_decrements');
}
if (!defined('CG_USAGE_DECREMENT_MAX')) {
    define('CG_USAGE_DECREMENT_MAX', 20);
}
if (!defined('CG_USAGE_DECREMENT_TIMEOUT')) {
    define('CG_USAGE_DECREMENT_TIMEOUT', 5);
}
global $wpdb;
$GLOBALS['QA_ORIG'] = ['cg_plugin_options' => get_option('cg_plugin_options', null), 'cg_api_cache' => get_transient('cg_api_cache')];
$GLOBALS['QA_LOG'] = [];
$GLOBALS['QA_DELAY'] = 0;
add_filter('pre_http_request', function ($pre, $args, $url) {
    $path = preg_replace('#^.*?/api/v1#', '', (string) parse_url($url, PHP_URL_PATH));
    $GLOBALS['QA_LOG'][] = [$path, json_decode((string) ($args['body'] ?? ''), true)];
    if ($GLOBALS['QA_DELAY'] > 0) {
        sleep($GLOBALS['QA_DELAY']);
    }
    return ['headers' => new WpOrg\Requests\Utility\CaseInsensitiveDictionary([]), 'body' => '{"status":"success"}', 'response' => ['code' => 200, 'message' => ''], 'cookies' => [], 'filename' => null];
}, PHP_INT_MAX, 3);
// Seen by each decrement call: its HTTP timeout and how many queued events
// already exist at that moment (Zahid H1 review N1).
$GLOBALS['QA_CALLS'] = [];
add_filter('pre_http_request', function ($pre, $args, $url) {
    if (strpos((string) $url, '/sites/decrement-usage') !== false) {
        $GLOBALS['QA_CALLS'][] = ['timeout' => (int) ($args['timeout'] ?? 0), 'events' => count(qa_events())];
    }
    return $pre;
}, 1, 3);
$GLOBALS['QA_WARN'] = [];
set_error_handler(function ($no, $str, $file, $line) {
    if (strpos($file, 'checkoutguard') !== false) {
        $GLOBALS['QA_WARN'][] = "$str @ " . basename($file) . ":$line";
    }
    return false;
});
$o = is_array($GLOBALS['QA_ORIG']['cg_plugin_options']) ? $GLOBALS['QA_ORIG']['cg_plugin_options'] : [];
$o['api_key'] = 'qa-h1-key';
update_option('cg_plugin_options', $o);
$GLOBALS['QA_ORDERS'] = [];
$GLOBALS['QA_TAG'] = 'qah1' . wp_rand(10000, 99999);

function qa_events() {
    $out = [];
    foreach ((array) _get_cron_array() as $ts => $hooks) {
        foreach ((array) ($hooks[CG_USAGE_DECREMENT_HOOK] ?? []) as $ev) {
            $out[] = ['ts' => (int) $ts, 'args' => $ev['args']];
        }
    }
    return $out;
}
function qa_reset() {
    wp_clear_scheduled_hook(CG_USAGE_DECREMENT_HOOK);
    foreach (qa_events() as $e) {
        wp_unschedule_event($e['ts'], CG_USAGE_DECREMENT_HOOK, $e['args']);
    }
    set_transient('cg_api_cache', ['usage' => ['checkouts' => 10, 'contact_reveals' => 4], 'limits' => ['checkouts' => 100]], DAY_IN_SECONDS);
    $GLOBALS['QA_LOG'] = [];
    $GLOBALS['QA_DELAY'] = 0;
}
function qa_dec_calls() {
    return array_values(array_filter($GLOBALS['QA_LOG'], function ($l) { return $l[0] === '/sites/decrement-usage'; }));
}
function qa_order_with_rows($n, $sfx) {
    global $wpdb;
    $email = $GLOBALS['QA_TAG'] . $sfx . '@example.test';
    for ($i = 0; $i < $n; $i++) {
        $wpdb->insert($wpdb->prefix . 'cg_incomplete_checkouts', ['session_id' => $GLOBALS['QA_TAG'] . "-$sfx-$i", 'email' => $email, 'status' => 'incomplete']);
    }
    $order = wc_create_order();
    $order->set_billing_email($email);
    $order->save();
    // wc_create_order()/save() fire woocommerce_new_order, which already ran
    // the cleanup (and set the guard) before the email was there.
    $order = wc_get_order($order->get_id());
    $order->delete_meta_data('_cg_incomplete_cleaned');
    $order->save_meta_data();
    $GLOBALS['QA_ORDERS'][] = $order->get_id();
    return [$order->get_id(), $email];
}
function qa_rows($email) {
    global $wpdb;
    return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}cg_incomplete_checkouts WHERE email = %s", $email));
}
function qa_usage($k) {
    $c = get_transient('cg_api_cache');
    return (int) ($c['usage'][$k] ?? -1);
}
$GLOBALS['R'] = [];
function ok($id, $name, $pass, $detail) { $GLOBALS['R'][] = [$id, $name, $pass ? 'PASS' : 'FAIL', $detail]; }

// D1: order with 3 matching incomplete rows, HQ slow (2 s per call): no HQ call, fast
qa_reset();
list($oid, $email) = qa_order_with_rows(3, 'a');
$GLOBALS['QA_DELAY'] = 2;
$t = microtime(true);
cg_delete_incomplete_checkout_on_order_completion($oid);
$ms = (int) round((microtime(true) - $t) * 1000);
$ev = qa_events();
ok('D1', 'order cleanup: no HQ call in the request, under 1 s with a slow HQ', count($GLOBALS['QA_LOG']) === 0 && $ms < 1000, "http=" . count($GLOBALS['QA_LOG']) . " ms=$ms");
ok('D1b', 'rows deleted, local usage lowered by 3 at once', qa_rows($email) === 0 && qa_usage('checkouts') === 7, 'rows=' . qa_rows($email) . ' usage=' . qa_usage('checkouts'));
ok('D1c', 'one event: checkouts x3, ref order id, due now', count($ev) === 1 && $ev[0]['args'] === ['checkouts', 3, 'order-' . $oid] && abs($ev[0]['ts'] - time()) <= 5, wp_json_encode($ev));

// D2: the event handler sends exactly 3 decrements for checkouts
$GLOBALS['QA_DELAY'] = 0;
$GLOBALS['QA_LOG'] = [];
do_action_ref_array(CG_USAGE_DECREMENT_HOOK, $ev[0]['args'] ?? ['checkouts', 0, '']);
$calls = qa_dec_calls();
$types = array_unique(array_map(function ($c) { return $c[1]['action_type'] ?? ''; }, $calls));
ok('D2', 'cron run: 3 decrement-usage calls, all checkouts, local copy not lowered again', count($calls) === 3 && $types === ['checkouts'] && qa_usage('checkouts') === 7, 'calls=' . count($calls) . ' types=' . implode(',', $types) . ' usage=' . qa_usage('checkouts'));

// D3: the same order seen again (thankyou after order_processed): nothing new
qa_reset();
cg_delete_incomplete_checkout_on_order_completion($oid);
ok('D3', 'same order again: no event, no HQ call', count(qa_events()) === 0 && count($GLOBALS['QA_LOG']) === 0, 'events=' . count(qa_events()) . ' http=' . count($GLOBALS['QA_LOG']));

// D4: two orders with the same count get two events (ref keeps them apart)
qa_reset();
list($o1, ) = qa_order_with_rows(2, 'b');
list($o2, ) = qa_order_with_rows(2, 'c');
cg_delete_incomplete_checkout_on_order_completion($o1);
cg_delete_incomplete_checkout_on_order_completion($o2);
ok('D4', 'two orders, same count: two events', count(qa_events()) === 2, wp_json_encode(qa_events()));

// D5: more than CG_USAGE_DECREMENT_MAX: one run sends MAX, the rest in a new event +60 s
qa_reset();
$GLOBALS['QA_LOG'] = [];
do_action_ref_array(CG_USAGE_DECREMENT_HOOK, ['checkouts', CG_USAGE_DECREMENT_MAX + 5, 'order-x']);
$ev = qa_events();
ok('D5', 'over the cap: MAX calls now, rest 5 queued ~60 s later', count(qa_dec_calls()) === CG_USAGE_DECREMENT_MAX && count($ev) === 1 && $ev[0]['args'] === ['checkouts', 5, 'order-x'] && $ev[0]['ts'] >= time() + 50, 'calls=' . count(qa_dec_calls()) . ' ' . wp_json_encode($ev));

// D6: contact_reveals is never decremented, queued or not
qa_reset();
$q = function_exists('cg_queue_usage_decrement') ? cg_queue_usage_decrement('contact_reveals', 2, 'x') : null;
do_action_ref_array(CG_USAGE_DECREMENT_HOOK, ['contact_reveals', 2, 'x']);
ok('D6', 'contact_reveals: no event, no HQ call, local copy unchanged', $q === false && count(qa_events()) === 0 && count($GLOBALS['QA_LOG']) === 0 && qa_usage('contact_reveals') === 4, 'q=' . var_export($q, true) . ' http=' . count($GLOBALS['QA_LOG']) . ' usage=' . qa_usage('contact_reveals'));

// D7: zero or negative count: nothing
qa_reset();
$q0 = function_exists('cg_queue_usage_decrement') ? cg_queue_usage_decrement('checkouts', 0, 'z') : null;
ok('D7', 'count 0: no event, local copy unchanged', $q0 === false && count(qa_events()) === 0 && qa_usage('checkouts') === 10, 'q=' . var_export($q0, true) . ' usage=' . qa_usage('checkouts'));

// D8: admin path keeps the direct call (cg_decrement_usage_count)
qa_reset();
cg_decrement_usage_count('checkouts');
ok('D8', 'admin cg_decrement_usage_count: one direct HQ call, local -1, no event', count(qa_dec_calls()) === 1 && qa_usage('checkouts') === 9 && count(qa_events()) === 0, 'calls=' . count(qa_dec_calls()) . ' usage=' . qa_usage('checkouts'));

// D9: the cron hook is registered by the plugin
ok('D9', 'hook has the handler', has_action(CG_USAGE_DECREMENT_HOOK, 'cg_send_queued_usage_decrements') !== false, var_export(has_action(CG_USAGE_DECREMENT_HOOK, 'cg_send_queued_usage_decrements'), true));

// D10 (review N1): the rest event exists before the first HQ call, so a run
// cut off by max_execution_time loses at most its own share
qa_reset();
$GLOBALS['QA_CALLS'] = [];
do_action_ref_array(CG_USAGE_DECREMENT_HOOK, ['checkouts', CG_USAGE_DECREMENT_MAX + 3, 'order-r']);
$first = $GLOBALS['QA_CALLS'][0] ?? null;
ok('D10', 'over the cap: rest event already queued at the first HQ call', $first !== null && $first['events'] === 1, wp_json_encode($first));

// D11 (review N1): each queued call uses the short timeout, override restored after
$tos = array_unique(array_map(function ($c) { return $c['timeout']; }, $GLOBALS['QA_CALLS']));
ok('D11', 'queued calls: timeout CG_USAGE_DECREMENT_TIMEOUT, override unset after the run', count($GLOBALS['QA_CALLS']) === CG_USAGE_DECREMENT_MAX && array_values($tos) === [(int) CG_USAGE_DECREMENT_TIMEOUT] && !isset($GLOBALS['cg_hq_timeout_override']), 'calls=' . count($GLOBALS['QA_CALLS']) . ' timeouts=' . implode(',', $tos) . ' override=' . var_export($GLOBALS['cg_hq_timeout_override'] ?? null, true));

// D11b: an override set by the caller survives the run
qa_reset();
$GLOBALS['cg_hq_timeout_override'] = 3;
do_action_ref_array(CG_USAGE_DECREMENT_HOOK, ['checkouts', 1, 'order-o']);
$kept = $GLOBALS['cg_hq_timeout_override'] ?? null;
unset($GLOBALS['cg_hq_timeout_override']);
ok('D11b', 'caller override 3 is put back after the run', $kept === 3, var_export($kept, true));

// D12 (review N2): uninstall clears the queued-decrement hook
$un = (string) @file_get_contents(CG_PLUGIN_DIR . 'uninstall.php');
ok('D12', "uninstall.php clears cg_send_usage_decrements", strpos($un, "wp_clear_scheduled_hook('cg_send_usage_decrements')") !== false, 'found=' . var_export(strpos($un, 'cg_send_usage_decrements') !== false, true));

// D13 (review N3): only rows this request really deleted are queued
qa_reset();
list($o13, $e13) = qa_order_with_rows(3, 'd');
$lim = function ($q) {
    if (stripos($q, 'DELETE FROM') === 0 && strpos($q, 'cg_incomplete_checkouts') !== false && stripos($q, ' LIMIT ') === false) {
        return $q . ' LIMIT 1';
    }
    return $q;
};
add_filter('query', $lim);
cg_delete_incomplete_checkout_on_order_completion($o13);
remove_filter('query', $lim);
$ev13 = qa_events();
ok('D13', '3 rows matched, 1 deleted: event count 1, local -1', count($ev13) === 1 && ($ev13[0]['args'][1] ?? null) === 1 && qa_usage('checkouts') === 9, wp_json_encode($ev13) . ' usage=' . qa_usage('checkouts'));

// cleanup
qa_reset();
wp_clear_scheduled_hook(CG_USAGE_DECREMENT_HOOK);
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}cg_incomplete_checkouts WHERE session_id LIKE %s", $wpdb->esc_like($GLOBALS['QA_TAG']) . '%'));
foreach ($GLOBALS['QA_ORDERS'] as $id) {
    $ord = wc_get_order($id);
    if ($ord) {
        $ord->delete(true);
    }
}
if ($GLOBALS['QA_ORIG']['cg_plugin_options'] === null) {
    delete_option('cg_plugin_options');
} else {
    update_option('cg_plugin_options', $GLOBALS['QA_ORIG']['cg_plugin_options']);
}
if ($GLOBALS['QA_ORIG']['cg_api_cache'] === false) {
    delete_transient('cg_api_cache');
} else {
    set_transient('cg_api_cache', $GLOBALS['QA_ORIG']['cg_api_cache'], DAY_IN_SECONDS);
}
restore_error_handler();

$pass = 0;
foreach ($GLOBALS['R'] as $r) {
    echo implode(' | ', $r), "\n";
    if ($r[2] === 'PASS') {
        $pass++;
    }
}
echo 'warnings_total=' . count($GLOBALS['QA_WARN']) . "\n";
foreach ($GLOBALS['QA_WARN'] as $w) {
    echo "WARN $w\n";
}
echo 'SUMMARY ' . $pass . '/' . count($GLOBALS['R']) . " PASS\n";
