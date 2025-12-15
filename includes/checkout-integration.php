<?php
// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Deletes the corresponding incomplete checkout record when a WooCommerce order is completed.
 */
function checkoutguard_delete_incomplete_checkout_on_order_completion($order_id)
{
    if (!$order_id) {
        return;
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        return;
    }

    // Use the session ID stored in the order meta if available, otherwise fallback to current session.
    $session_id_to_delete = $order->get_meta('_checkoutguard_session_id');
    if (empty($session_id_to_delete) && WC()->session && WC()->session->get_customer_id()) {
        $session_id_to_delete = WC()->session->get_customer_id();
    }

    if ($session_id_to_delete) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'checkoutguard_incomplete_checkouts';

        $wpdb->delete(
            $table_name,
            ['session_id' => $session_id_to_delete],
            ['%s']
        );
    }
}

/**
 * Checks customer's phone number against the blocklist during checkout processing.
 * This is the simplified version for the free plugin.
 */
function checkoutguard_check_customer_against_blocklists()
{
    // Only check phone numbers in the free version.
    if (isset($_POST['billing_phone'])) {
        $customer_phone = checkoutguard_normalize_phone_number(sanitize_text_field(wp_unslash($_POST['billing_phone'])));

        if (!empty($customer_phone)) {
            global $wpdb;
            $table_blocked_numbers = $wpdb->prefix . 'checkoutguard_blocked_numbers';

            $is_phone_blocked = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table_blocked_numbers} WHERE phone_number = %s LIMIT 1",
                $customer_phone
            ));

            if ($is_phone_blocked) {
                wc_add_notice(esc_html__('Your order cannot be processed at this time. Please contact support.', 'checkoutguard'), 'error');
            }
        }
    }
}

/**
 * Stores the session ID in the order meta when the order is created.
 * This helps reliably find and delete the incomplete record later.
 */
function checkoutguard_add_session_id_to_order_meta($order_id)
{
    $order = wc_get_order($order_id);
    if ($order && WC()->session && WC()->session->get_customer_id()) {
        $order->update_meta_data('_checkoutguard_session_id', WC()->session->get_customer_id());
        $order->save();
    }
}
add_action('woocommerce_checkout_update_order_meta', 'checkoutguard_add_session_id_to_order_meta');