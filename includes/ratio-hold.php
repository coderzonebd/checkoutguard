<?php
/**
 * Courier success ratio: hold instead of block on paid plans (1.5.0).
 *
 * Decisions (Tanvir, 21 Nov):
 *   - Free blocks the checkout as before. There is no "warning only" mode.
 *   - Paid plans (cg_is_paid_plan()) choose ratio_action "hold" or "block";
 *     the default for paid is "hold". A downgraded site blocks again.
 *   - Hold is only for the ratio check (CHECK 3). Blocklists, velocity and
 *     the fraud risk score still block.
 *   - Prepaid orders (bKash, card) are held too, with the note "payment
 *     received, on hold for review". No automatic refund or cancel.
 *
 * Flow: CHECK 3 returns CG_Validation_Result::hold(); the checkout guards call
 * cg_mark_ratio_hold() to flag the order. The status is set where the gateway
 * sets it (COD, payment_complete), so a prepaid order can still be paid
 * first. A safety net puts a flagged order back on hold when anything other
 * than a shop manager in wp-admin moves it to processing or completed.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * "hold" or "block" for a low courier success ratio. Free (and expired,
 * inactive or unknown plans) always "block", whatever value is saved.
 *
 * @return string
 */
function cg_ratio_action()
{
    if (!function_exists('cg_is_paid_plan') || !cg_is_paid_plan()) {
        return 'block';
    }
    $options = get_option('cg_plugin_options', []);
    return (isset($options['ratio_action']) && $options['ratio_action'] === 'block') ? 'block' : 'hold';
}

/**
 * Flags an order for a ratio hold when the validation result says so.
 * The order is not saved here; the caller's save stores the meta.
 *
 * @return bool True when the order is flagged.
 */
function cg_mark_ratio_hold($order, $result)
{
    if (!($order instanceof WC_Order) || !($result instanceof CG_Validation_Result) || !$result->is_held()) {
        return false;
    }
    $order->update_meta_data('_cg_ratio_hold', '1');
    $order->update_meta_data('_cg_ratio_hold_rate', (int) ($result->details['success_rate'] ?? 0));
    $order->update_meta_data('_cg_ratio_hold_threshold', (int) ($result->details['threshold'] ?? 0));
    return true;
}

/**
 * True while the order is flagged and a shop manager has not released it.
 */
function cg_ratio_hold_active($order)
{
    return $order instanceof WC_Order
        && $order->get_meta('_cg_ratio_hold', true) === '1'
        && $order->get_meta('_cg_ratio_hold_released', true) !== '1';
}

/**
 * Order note text. $paid: the payment came in before the hold.
 */
function cg_ratio_hold_note($order, $paid)
{
    $rate = (int) $order->get_meta('_cg_ratio_hold_rate', true);
    $threshold = (int) $order->get_meta('_cg_ratio_hold_threshold', true);
    if ($paid) {
        /* translators: 1: success ratio, 2: threshold */
        return sprintf(__('CheckoutGuard: payment received, on hold for review (courier success ratio %1$d%% <= threshold %2$d%%).', 'checkoutguard'), $rate, $threshold);
    }
    /* translators: 1: success ratio, 2: threshold */
    return sprintf(__('CheckoutGuard: on hold for review (courier success ratio %1$d%% <= threshold %2$d%%).', 'checkoutguard'), $rate, $threshold);
}

/**
 * A shop manager acting in wp-admin (order edit, bulk action, order preview).
 * Checkout, Store API, gateway callbacks and cron are never this.
 */
function cg_ratio_hold_is_manager_action()
{
    return is_admin() && !wp_doing_cron() && current_user_can('edit_shop_orders')
        && !(defined('REST_REQUEST') && REST_REQUEST);
}

/**
 * Writes one row to cg_blocked_orders for a held order (once per order).
 */
function cg_ratio_hold_record($order)
{
    if (!($order instanceof WC_Order) || !$order->get_id() || $order->get_meta('_cg_ratio_hold_logged', true) === '1') {
        return;
    }
    global $wpdb;
    $items = [];
    foreach ($order->get_items() as $item) {
        $items[] = ['name' => $item->get_name(), 'quantity' => (int) $item->get_quantity()];
    }
    $row = [
        'blocked_at'         => current_time('mysql'),
        'first_name'         => $order->get_billing_first_name(),
        'last_name'          => $order->get_billing_last_name(),
        'phone_number'       => cg_normalize_phone_number($order->get_billing_phone()),
        'email_address'      => $order->get_billing_email(),
        'cart_details'       => wp_json_encode($items),
        'cart_value'         => (float) $order->get_total(),
        'success_ratio'      => (int) $order->get_meta('_cg_ratio_hold_rate', true),
        'threshold_at_block' => (int) $order->get_meta('_cg_ratio_hold_threshold', true),
        'ip_address'         => (string) $order->get_customer_ip_address(),
        'action'             => 'hold',
        'order_id'           => $order->get_id(),
    ];
    $table = $wpdb->prefix . 'cg_blocked_orders';
    $ok = $wpdb->insert($table, $row);
    if ($ok === false) {
        // Migration not done yet (no action/order_id columns): keep the row anyway.
        unset($row['action'], $row['order_id']);
        $wpdb->insert($table, $row);
    }
    $order->update_meta_data('_cg_ratio_hold_logged', '1');
    $order->save_meta_data();
}

/**
 * COD: on-hold instead of processing.
 */
function cg_ratio_hold_cod_status($status, $order = null)
{
    if (cg_ratio_hold_active($order)) {
        return 'on-hold';
    }
    return $status;
}
add_filter('woocommerce_cod_process_payment_order_status', 'cg_ratio_hold_cod_status', 20, 2);

/**
 * Orders inside WC_Order::payment_complete() right now. WooCommerce also
 * applies woocommerce_payment_complete_order_status on every status change
 * (maybe_set_date_paid), so without this a COD order put on hold would be
 * marked paid.
 */
function cg_ratio_hold_paying($order_id = null, $set = null)
{
    static $paying = [];
    if ($set === true) {
        $paying[(int) $order_id] = true;
    } elseif ($set === false) {
        unset($paying[(int) $order_id]);
    }
    return !empty($paying[(int) $order_id]);
}
add_action('woocommerce_pre_payment_complete', function ($order_id) {
    cg_ratio_hold_paying($order_id, true);
}, 1);
add_action('woocommerce_payment_complete', function ($order_id) {
    cg_ratio_hold_paying($order_id, false);
}, 999);

/**
 * Prepaid gateways and free orders call payment_complete(): on-hold, with
 * the "payment received" note. Never refunds or cancels.
 */
function cg_ratio_hold_payment_complete_status($status, $order_id = 0, $order = null)
{
    if (!$order_id || !cg_ratio_hold_paying($order_id)) {
        return $status;
    }
    if (!($order instanceof WC_Order)) {
        $order = wc_get_order($order_id);
    }
    if (!cg_ratio_hold_active($order)) {
        return $status;
    }
    if ((float) $order->get_total() > 0) {
        $order->update_meta_data('_cg_ratio_hold_paid', '1');
    }
    return 'on-hold';
}
add_filter('woocommerce_payment_complete_order_status', 'cg_ratio_hold_payment_complete_status', 20, 3);

/**
 * Status changes: note + blocked_orders row when the hold starts; a manager
 * moving the order on releases it; anything else that moves a held order to
 * processing or completed puts it back on hold.
 */
function cg_ratio_hold_status_changed($order_id, $from, $to, $order = null)
{
    if (!($order instanceof WC_Order)) {
        $order = wc_get_order($order_id);
    }
    if (!cg_ratio_hold_active($order)) {
        return;
    }
    if ($to === 'on-hold') {
        if ($order->get_meta('_cg_ratio_hold_logged', true) !== '1') {
            $order->add_order_note(cg_ratio_hold_note($order, $order->get_meta('_cg_ratio_hold_paid', true) === '1'));
            cg_ratio_hold_record($order);
        }
        return;
    }
    if ($from === 'on-hold' && $order->get_meta('_cg_ratio_hold_logged', true) === '1' && cg_ratio_hold_is_manager_action()) {
        $order->update_meta_data('_cg_ratio_hold_released', '1');
        $order->save_meta_data();
        return;
    }
    if (in_array($to, ['processing', 'completed'], true) && !cg_ratio_hold_is_manager_action()) {
        if ($from !== 'on-hold' && $order->is_paid()) {
            $order->update_meta_data('_cg_ratio_hold_paid', '1');
        }
        $order->update_status('on-hold', __('CheckoutGuard: kept on hold for review (courier success ratio). A shop manager can release it from the order page.', 'checkoutguard'));
    }
}
add_action('woocommerce_order_status_changed', 'cg_ratio_hold_status_changed', 20, 4);
