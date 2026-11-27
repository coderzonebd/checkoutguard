<?php
/**
 * Recover with no usable cart must not leave an order behind.
 *
 * cg_recover_order_from_entry() calls wc_create_order() (status processing)
 * before it reads the cart. When the entry has no cart items it throws, and
 * the order that was already created stays as an empty Processing order.
 * The failed Recover must leave no new order and keep the entry recoverable.
 * A PHP Error (not only an Exception) after the order exists is handled the
 * same way (E5), and the order turns Processing only once it is complete, so
 * status hooks such as the New Order email see its items (E6).
 * woocommerce_new_order still fires exactly once (E7).
 *
 * Run on a local test site only (never production):
 *   wp eval-file tests/qa/recover-empty-cart-order-tests.php
 * HTTP is answered by a pre_http_request filter in this process; nothing
 * leaves the machine. Rows, orders and the product made here are removed.
 */
if (!defined('ABSPATH')) {
    exit;
}
global $wpdb;
$GLOBALS['QA_T'] = $wpdb->prefix . 'cg_incomplete_checkouts';
$GLOBALS['QA_ORIG_CACHE'] = get_transient('cg_api_cache');
add_filter('pre_http_request', function () {
    return ['headers' => new WpOrg\Requests\Utility\CaseInsensitiveDictionary([]), 'body' => '{"status":"success"}', 'response' => ['code' => 200, 'message' => ''], 'cookies' => [], 'filename' => null];
}, PHP_INT_MAX, 3);
$GLOBALS['QA_WARN'] = [];
// The plugin may be loaded through a symlink, so match its real directory.
$GLOBALS['QA_PLUGIN_DIR'] = realpath(dirname(__DIR__, 2));
set_error_handler(function ($no, $str, $file, $line) {
    if (strpos($file, 'checkoutguard') !== false || strpos((string) realpath($file), $GLOBALS['QA_PLUGIN_DIR']) === 0) {
        $GLOBALS['QA_WARN'][] = "$str @ " . basename($file) . ":$line";
    }
    return false;
});
$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
wp_set_current_user((int) ($admins[0] ?? 1));
if (!function_exists('cg_recover_order_from_entry')) {
    require_once CG_INCLUDES_DIR . 'ajax/ajax-checkouts.php';
}
$GLOBALS['QA_ROWS'] = [];
$GLOBALS['QA_NEW_ORDERS'] = [];

function qa_order_ids() {
    $statuses = array_merge(array_keys(wc_get_order_statuses()), ['trash', 'auto-draft', 'draft']);
    return array_map('intval', wc_get_orders(['limit' => -1, 'return' => 'ids', 'status' => $statuses, 'type' => 'shop_order']));
}
function qa_entry($cart_details) {
    global $wpdb;
    $wpdb->insert($GLOBALS['QA_T'], [
        'session_id' => 'qarec' . wp_rand(100000, 999999),
        'first_name' => 'QA', 'email' => 'qarec@example.test', 'phone' => '01700000000',
        'cart_details' => $cart_details, 'status' => 'incomplete',
    ]);
    $id = (int) $wpdb->insert_id;
    $GLOBALS['QA_ROWS'][] = $id;
    return $id;
}
function qa_recover($entry_id) {
    $before = qa_order_ids();
    $r = cg_recover_order_from_entry($entry_id);
    $new = array_values(array_diff(qa_order_ids(), $before));
    $GLOBALS['QA_NEW_ORDERS'] = array_merge($GLOBALS['QA_NEW_ORDERS'], $new);
    return [$r, $new];
}
function qa_status($entry_id) {
    global $wpdb;
    return $wpdb->get_var($wpdb->prepare("SELECT status FROM {$GLOBALS['QA_T']} WHERE id = %d", $entry_id));
}
$GLOBALS['R'] = [];
function ok($id, $name, $pass, $detail) { $GLOBALS['R'][] = [$id, $name, $pass ? 'PASS' : 'FAIL', $detail]; }

// E1: cart_details is an empty JSON list.
$e = qa_entry('[]');
list($r, $new) = qa_recover($e);
ok('E1', "empty cart '[]': Recover fails, no order left, entry still incomplete", empty($r['success']) && count($new) === 0 && qa_status($e) === 'incomplete', 'success=' . var_export(!empty($r['success']), true) . ' new_orders=' . json_encode($new) . ' entry=' . qa_status($e));

// E2: cart_details is NULL (never captured).
$e = qa_entry(null);
list($r, $new) = qa_recover($e);
ok('E2', 'cart NULL: Recover fails, no order left, entry still incomplete', empty($r['success']) && count($new) === 0 && qa_status($e) === 'incomplete', 'success=' . var_export(!empty($r['success']), true) . ' new_orders=' . json_encode($new) . ' entry=' . qa_status($e));

// E3: the cart lists only a product that no longer exists: nothing can be
// added, so Recover fails instead of creating an empty Processing order.
$e = qa_entry(wp_json_encode([['product_id' => 999999999, 'variation_id' => 0, 'quantity' => 1]]));
list($r, $new) = qa_recover($e);
ok('E3', 'cart with only a deleted product: Recover fails, no order left, entry still incomplete', empty($r['success']) && count($new) === 0 && qa_status($e) === 'incomplete', 'success=' . var_export(!empty($r['success']), true) . ' new_orders=' . json_encode($new) . ' entry=' . qa_status($e));

// E-ctl: a real product in the cart: Recover succeeds with exactly 1 order
// holding that product, entry recovered.
$p = new WC_Product_Simple();
$p->set_name('QA recover product'); $p->set_regular_price('100'); $p->set_status('publish');
$pid = $p->save();
$e = qa_entry(wp_json_encode([['product_id' => $pid, 'variation_id' => 0, 'quantity' => 2]]));
list($r, $new) = qa_recover($e);
$items = count($new) === 1 ? count(wc_get_order($new[0])->get_items()) : -1;
ok('E-ctl', 'real product: success, exactly 1 order with 1 line, entry recovered', !empty($r['success']) && count($new) === 1 && $items === 1 && qa_status($e) === 'recovered', 'success=' . var_export(!empty($r['success']), true) . ' new_orders=' . json_encode($new) . " items=$items entry=" . qa_status($e));

// E4: a failure after wc_create_order() (here: totals throw) removes the
// half-built order; the entry stays incomplete.
$e = qa_entry(wp_json_encode([['product_id' => $pid, 'variation_id' => 0, 'quantity' => 1]]));
$qa_throw = function () { throw new Exception('qa: totals failed'); };
add_action('woocommerce_order_before_calculate_totals', $qa_throw);
list($r, $new) = qa_recover($e);
remove_action('woocommerce_order_before_calculate_totals', $qa_throw);
ok('E4', 'failure after the order was created: no order left, entry still incomplete', empty($r['success']) && count($new) === 0 && qa_status($e) === 'incomplete', 'success=' . var_export(!empty($r['success']), true) . ' msg=' . ($r['data']['message'] ?? '') . ' new_orders=' . json_encode($new) . ' entry=' . qa_status($e));

// E5: a PHP Error (not an Exception) after wc_create_order(), e.g. a
// TypeError from another plugin's hook, is handled the same way: Recover
// fails, nothing escapes, no order left, entry still incomplete.
$e = qa_entry(wp_json_encode([['product_id' => $pid, 'variation_id' => 0, 'quantity' => 1]]));
$qa_err = function () { throw new Error('qa: totals error'); };
add_action('woocommerce_order_before_calculate_totals', $qa_err);
$escaped = null;
$before5 = qa_order_ids();
try {
    list($r, $new) = qa_recover($e);
} catch (\Throwable $t) {
    $escaped = get_class($t);
    $r = [];
    $new = array_values(array_diff(qa_order_ids(), $before5));
    $GLOBALS['QA_NEW_ORDERS'] = array_merge($GLOBALS['QA_NEW_ORDERS'], $new);
}
remove_action('woocommerce_order_before_calculate_totals', $qa_err);
ok('E5', 'PHP Error after the order was created: handled, no order left, entry still incomplete', $escaped === null && empty($r['success']) && count($new) === 0 && qa_status($e) === 'incomplete', 'escaped=' . var_export($escaped, true) . ' success=' . var_export(!empty($r['success']), true) . ' new_orders=' . json_encode($new) . ' entry=' . qa_status($e));

// E6: the order turns Processing only once it is complete. Status hooks
// (admin New Order email, customer Processing email, invoice number) must
// see the order with its line items, totals and the _cg_recovered_from
// link, and the move to Processing happens exactly once.
$e = qa_entry(wp_json_encode([['product_id' => $pid, 'variation_id' => 0, 'quantity' => 3]]));
$GLOBALS['QA_E6'] = [];
$qa_seen = function ($order_id, $order = null) {
    $o = $order instanceof WC_Order ? $order : wc_get_order($order_id);
    $GLOBALS['QA_E6'][] = [
        'items' => $o ? count($o->get_items()) : -1,
        'total' => $o ? (float) $o->get_total() : -1,
        'link' => $o ? (string) $o->get_meta('_cg_recovered_from', true) : '',
    ];
};
add_action('woocommerce_order_status_processing', $qa_seen, 10, 2);
list($r, $new) = qa_recover($e);
remove_action('woocommerce_order_status_processing', $qa_seen, 10);
$seen = $GLOBALS['QA_E6'];
$final = count($new) === 1 ? wc_get_order($new[0])->get_status() : null;
ok('E6', 'Processing hooks fire once, after items, totals and the recover link are saved', !empty($r['success']) && count($new) === 1 && $final === 'processing' && count($seen) === 1 && $seen[0]['items'] === 1 && $seen[0]['total'] > 0 && $seen[0]['link'] === (string) $e, 'success=' . var_export(!empty($r['success']), true) . ' new_orders=' . json_encode($new) . ' final=' . var_export($final, true) . ' seen=' . json_encode($seen));

// E7: woocommerce_new_order fires exactly once for a recovered order. WC core
// fires it inside wc_create_order(), before items are added, so at that
// moment the order is 'pending' with 0 items (CG's own new_order hooks saw
// 0 items before this change too; only the status changed from processing).
$e = qa_entry(wp_json_encode([['product_id' => $pid, 'variation_id' => 0, 'quantity' => 1]]));
$GLOBALS['QA_E7'] = [];
$qa_new = function ($order_id, $order = null) {
    $o = $order instanceof WC_Order ? $order : wc_get_order($order_id);
    $GLOBALS['QA_E7'][] = ['status' => $o ? $o->get_status() : '', 'items' => $o ? count($o->get_items()) : -1];
};
add_action('woocommerce_new_order', $qa_new, 10, 2);
list($r, $new) = qa_recover($e);
remove_action('woocommerce_new_order', $qa_new, 10);
$seen = $GLOBALS['QA_E7'];
ok('E7', 'woocommerce_new_order fires exactly once (pending, 0 items)', !empty($r['success']) && count($new) === 1 && count($seen) === 1 && $seen[0]['status'] === 'pending' && $seen[0]['items'] === 0 && qa_status($e) === 'recovered', 'success=' . var_export(!empty($r['success']), true) . ' new_orders=' . json_encode($new) . ' seen=' . json_encode($seen) . ' entry=' . qa_status($e));

// cleanup
foreach (array_unique($GLOBALS['QA_NEW_ORDERS']) as $oid) { $o = wc_get_order($oid); if ($o) { $o->delete(true); } }
wp_delete_post($pid, true);
foreach ($GLOBALS['QA_ROWS'] as $id) { $wpdb->delete($GLOBALS['QA_T'], ['id' => $id]); }
if ($GLOBALS['QA_ORIG_CACHE'] === false) { delete_transient('cg_api_cache'); } else { set_transient('cg_api_cache', $GLOBALS['QA_ORIG_CACHE'], DAY_IN_SECONDS); }
restore_error_handler();
$R = $GLOBALS['R'];
foreach ($R as $r) echo implode(' | ', $r), "\n";
echo 'warnings_total=' . count($GLOBALS['QA_WARN']) . "\n"; foreach (array_unique($GLOBALS['QA_WARN']) as $w) echo "  W: $w\n";
$f = count(array_filter($R, function ($r) { return $r[2] !== 'PASS'; })) + (count($GLOBALS['QA_WARN']) ? 1 : 0);
echo "SUMMARY " . (count($R) - $f) . '/' . count($R) . " PASS\n";
