<?php
/**
 * Order cleanup must not match an incomplete checkout by the device
 * fingerprint alone (checkout-integration.php).
 *
 * Browsers of the same phone model and version send the same fingerprint.
 * When customer B placed an order, customer A's incomplete row with the same
 * fingerprint (other phone, other email) was deleted and usage was given
 * back for it.
 *
 * Run on a local test site only (never production):
 *   wp eval-file tests/qa/order-cleanup-fingerprint-tests.php
 * It inserts and deletes its own cg_incomplete_checkouts rows (session_id
 * prefix cgof-), creates and force-deletes its own orders, and restores
 * cg_api_cache and cg_last_known_plan. HQ calls are stubbed (no network).
 */
if (!defined('ABSPATH')) {
    exit;
}
$GLOBALS['OF'] = ['pass' => 0, 'fail' => 0, 'n' => 0];
$GLOBALS['OF_WARN'] = [];
set_error_handler(function ($no, $str, $file) {
    if (strpos((string) $file, 'checkoutguard') !== false) {
        $GLOBALS['OF_WARN'][] = $str . ' @ ' . basename($file);
    }
    return false;
});
function of_check($id, $label, $cond, $detail = '') {
    $GLOBALS['OF']['n']++;
    $GLOBALS['OF'][$cond ? 'pass' : 'fail']++;
    echo ($cond ? 'PASS ' : 'FAIL ') . $id . ' ' . $label . ($cond || $detail === '' ? '' : ' :: ' . $detail), "\n";
}

global $wpdb;
$T = $wpdb->prefix . 'cg_incomplete_checkouts';
$GLOBALS['OF_HQ'] = [];
add_filter('pre_http_request', function ($pre, $args, $url) {
    $GLOBALS['OF_HQ'][] = $url;
    return ['headers' => [], 'body' => '{}', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
}, 10, 3);
// Deleted rows give usage back through WP-Cron: run the queued events.
function of_decrements() {
    if (defined('CG_USAGE_DECREMENT_HOOK')) {
        for ($guard = 0; $guard < 50; $guard++) {
            $events = [];
            foreach ((array) _get_cron_array() as $ts => $hooks) {
                foreach ((array) ($hooks[CG_USAGE_DECREMENT_HOOK] ?? []) as $ev) {
                    $events[] = [$ts, $ev['args']];
                }
            }
            if (!$events) {
                break;
            }
            foreach ($events as $e) {
                wp_unschedule_event($e[0], CG_USAGE_DECREMENT_HOOK, $e[1]);
                do_action_ref_array(CG_USAGE_DECREMENT_HOOK, $e[1]);
            }
        }
    }
    return count(array_filter($GLOBALS['OF_HQ'], function ($u) { return strpos($u, 'decrement-usage') !== false; }));
}

$saved_cache = get_transient('cg_api_cache');
$saved_last = get_option('cg_last_known_plan', null);
set_transient('cg_api_cache', ['plan_slug' => 'pro', 'status' => 'active', 'usage' => ['checkouts' => 50]], DAY_IN_SECONDS);
update_option('cg_last_known_plan', ['plan_slug' => 'pro', 'status' => 'active', 'synced_at' => time()], false);
// Storefront request: no admin screen.
unset($GLOBALS['current_screen']);

$GLOBALS['OF_MARKS'] = [];
$created_orders = [];
function of_fp($sfx) {
    return 'cgof-fp-' . $sfx . '-' . wp_generate_password(6, false);
}
function of_row($fields) {
    global $wpdb;
    $T = $wpdb->prefix . 'cg_incomplete_checkouts';
    $wpdb->insert($T, array_merge([
        'session_id' => 'cgof-' . wp_generate_password(8, false),
        'status' => 'incomplete',
        'cart_details' => '[]',
        'created_at' => current_time('mysql'),
        'updated_at' => current_time('mysql'),
    ], $fields));
    return (int) $wpdb->insert_id;
}
function of_get($id) {
    global $wpdb;
    $T = $wpdb->prefix . 'cg_incomplete_checkouts';
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$T} WHERE id = %d", $id), ARRAY_A);
}
// Places a storefront order. $fp_via: 'cookie' or 'meta'. The cookie is
// set before save(): woocommerce_new_order runs the cleanup during save.
function of_order($fp, $phone, $email, $fp_via = 'cookie') {
    unset($_COOKIE['cg_device_fp']);
    if ($fp !== '' && $fp_via === 'cookie') {
        $_COOKIE['cg_device_fp'] = $fp;
    }
    $o = new WC_Order();
    $o->set_created_via('checkout');
    if ($phone !== '') {
        $o->set_billing_phone($phone);
    }
    if ($email !== '') {
        $o->set_billing_email($email);
    }
    if ($fp !== '' && $fp_via === 'meta') {
        $o->update_meta_data('_cg_device_fingerprint', $fp);
    }
    $o->save();
    $GLOBALS['OF_ORDERS'][] = $o->get_id();
    foreach ([$fp, $phone, $email] as $v) {
        if ($v !== '') {
            $GLOBALS['OF_MARKS'][] = $v;
        }
    }
    cg_delete_incomplete_checkout_on_order_completion($o->get_id());
    unset($_COOKIE['cg_device_fp']);
    return wc_get_order($o->get_id());
}
$GLOBALS['OF_ORDERS'] = [];
function of_clear() {
    global $wpdb;
    $T = $wpdb->prefix . 'cg_incomplete_checkouts';
    $wpdb->query("DELETE FROM {$T} WHERE session_id LIKE 'cgof-%'");
    foreach ($GLOBALS['OF_MARKS'] as $v) {
        delete_transient(cg_order_placed_mark_key($v));
    }
}

try {
    of_clear();

    // O1: customer A's row (fp, phone A, email A). Customer B on a browser
    // with the same fingerprint orders with phone B and email B: A's row
    // stays and no usage is given back.
    $fp = of_fp('o1');
    $a = of_row(['device_fingerprint' => $fp, 'phone' => '01799100111', 'email' => 'a1@cgof.test']);
    $before = of_get($a);
    $h = of_decrements();
    of_order($fp, '01799100222', 'b1@cgof.test');
    of_check('O1', 'same fingerprint (cookie), other phone and email: A row kept, 0 decrements', of_get($a) === $before && of_decrements() - $h === 0, 'row=' . (of_get($a) ? 'kept' : 'deleted') . ' dec=' . (of_decrements() - $h));
    of_clear();

    // O2: the same, with the fingerprint saved on the order (block checkout).
    $fp = of_fp('o2');
    $a = of_row(['device_fingerprint' => $fp, 'phone' => '01799100311', 'email' => 'a2@cgof.test']);
    $h = of_decrements();
    of_order($fp, '01799100322', 'b2@cgof.test', 'meta');
    of_check('O2', 'same fingerprint (order meta), other phone and email: A row kept, 0 decrements', of_get($a) !== null && of_decrements() - $h === 0, 'dec=' . (of_decrements() - $h));
    of_clear();

    // O3: A's row has only the fingerprint and an email; B orders with
    // other phone and email.
    $fp = of_fp('o3');
    $a = of_row(['device_fingerprint' => $fp, 'email' => 'a3@cgof.test']);
    $h = of_decrements();
    of_order($fp, '01799100422', 'b3@cgof.test');
    of_check('O3', 'same fingerprint, A row without phone: A row kept, 0 decrements', of_get($a) !== null && of_decrements() - $h === 0, 'dec=' . (of_decrements() - $h));
    of_clear();

    // O4: an order with only the fingerprint (no phone, no email) does
    // not identify the customer: nothing is deleted.
    $fp = of_fp('o4');
    $a = of_row(['device_fingerprint' => $fp, 'phone' => '01799100511']);
    $h = of_decrements();
    of_order($fp, '', '');
    of_check('O4', 'order with fingerprint only: row kept, 0 decrements', of_get($a) !== null && of_decrements() - $h === 0, 'dec=' . (of_decrements() - $h));
    of_clear();

    // O4b: same as O4 with no WC session. Under wp-cli WC()->session is set,
    // so O4 always has a session_id clause; here the order gives no
    // identifier at all and the fingerprint must still not be used.
    $fp = of_fp('o4b');
    $a = of_row(['device_fingerprint' => $fp, 'phone' => '01799100512']);
    $h = of_decrements();
    $saved_session = WC()->session;
    WC()->session = null;
    try {
        of_order($fp, '', '');
    } finally {
        WC()->session = $saved_session;
    }
    of_check('O4b', 'order with fingerprint only and no WC session: row kept, 0 decrements', of_get($a) !== null && of_decrements() - $h === 0, 'dec=' . (of_decrements() - $h));
    of_clear();

    // O5: a phone shorter than 7 digits is not an identifier, alone or with
    // the fingerprint.
    $fp = of_fp('o5');
    $a = of_row(['device_fingerprint' => $fp, 'phone' => '12345', 'email' => 'a5@cgof.test']);
    $h = of_decrements();
    of_order($fp, '12345', 'b5@cgof.test');
    of_check('O5', 'same fingerprint + same short phone (<7), other email: A row kept, 0 decrements', of_get($a) !== null && of_decrements() - $h === 0, 'dec=' . (of_decrements() - $h));
    of_clear();

    // O6 control: same fingerprint and same phone: the row is the
    // customer's own and is deleted, 1 decrement.
    $fp = of_fp('o6');
    $a = of_row(['device_fingerprint' => $fp, 'phone' => '01799100611']);
    $h = of_decrements();
    of_order($fp, '01799100611', 'c6@cgof.test');
    of_check('O6', 'control: same fingerprint + same phone: row deleted, 1 decrement', of_get($a) === null && of_decrements() - $h === 1, 'dec=' . (of_decrements() - $h));
    of_clear();

    // O7 control: other fingerprint, same email: deleted, 1 decrement.
    $a = of_row(['device_fingerprint' => of_fp('o7a'), 'email' => 'c7@cgof.test']);
    $h = of_decrements();
    of_order(of_fp('o7b'), '01799100722', 'c7@cgof.test');
    of_check('O7', 'control: other fingerprint, same email: row deleted, 1 decrement', of_get($a) === null && of_decrements() - $h === 1, 'dec=' . (of_decrements() - $h));
    of_clear();

    // O8 control: B's own row (same phone) is deleted, A's row with the
    // same fingerprint stays: 1 decrement in total.
    $fp = of_fp('o8');
    $a = of_row(['device_fingerprint' => $fp, 'phone' => '01799100811', 'email' => 'a8@cgof.test']);
    $b = of_row(['device_fingerprint' => $fp, 'phone' => '01799100822']);
    $h = of_decrements();
    of_order($fp, '01799100822', 'b8@cgof.test');
    of_check('O8', 'same fingerprint, two customers: only B row deleted, A kept, 1 decrement', of_get($b) === null && of_get($a) !== null && of_decrements() - $h === 1, 'a=' . (of_get($a) ? 'kept' : 'deleted') . ' b=' . (of_get($b) ? 'kept' : 'deleted') . ' dec=' . (of_decrements() - $h));
    of_clear();

    of_check('O9', 'no PHP warnings from the plugin', empty($GLOBALS['OF_WARN']), implode(' ; ', array_slice($GLOBALS['OF_WARN'], 0, 3)));
} catch (Throwable $e) {
    of_check('OX', 'fatal', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

of_clear();
foreach ($GLOBALS['OF_ORDERS'] as $id) {
    $x = wc_get_order($id);
    if ($x) {
        $x->delete(true);
    }
}
unset($_COOKIE['cg_device_fp']);
restore_error_handler();
if ($saved_cache === false) { delete_transient('cg_api_cache'); } else { set_transient('cg_api_cache', $saved_cache, DAY_IN_SECONDS); }
if ($saved_last === null) { delete_option('cg_last_known_plan'); } else { update_option('cg_last_known_plan', $saved_last, false); }
$R = $GLOBALS['OF'];
echo 'SUMMARY ' . $R['pass'] . '/' . $R['n'] . ' ' . ($R['fail'] === 0 ? 'PASS' : 'FAIL') . "\n";
