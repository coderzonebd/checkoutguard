<?php
// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Extract a billing phone number from mixed checkout payloads (classic + block checkout).
 */
function checkoutguard_extract_billing_phone($data = null) {
    $candidates = array();

    if (is_array($data)) {
        $candidates[] = $data['billing_phone'] ?? '';
        $candidates[] = isset($data['billing']['phone']) ? $data['billing']['phone'] : '';
        $candidates[] = isset($data['billing_address']['phone']) ? $data['billing_address']['phone'] : '';
    }

    // Posted form / Store API request payloads
    $candidates[] = isset($_POST['billing_phone']) ? $_POST['billing_phone'] : '';
    $candidates[] = (isset($_POST['billing']['phone']) && is_array($_POST['billing'])) ? $_POST['billing']['phone'] : '';
    $candidates[] = (isset($_POST['billing_address']['phone']) && is_array($_POST['billing_address'])) ? $_POST['billing_address']['phone'] : '';

    // Fallback to customer session
    if (WC()->customer) {
        $candidates[] = WC()->customer->get_billing_phone();
    }

    foreach ($candidates as $candidate) {
        if (!empty($candidate)) {
            return sanitize_text_field( wp_unslash( $candidate ) );
        }
    }

    return '';
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

        // Update status to 'recovered' instead of deleting to preserve analytics
        $wpdb->update(
            $table_name,
            [
                'status' => 'recovered',
                'recovered_order_id' => $order_id,
                'updated_at' => current_time('mysql')
            ],
            ['session_id' => $session_id_to_delete],
            ['%s', '%d', '%s'],
            ['%s']
        );
    }
}

/**
 * Checks customer's phone number against the blocklist during checkout processing.
 * This is the simplified version for the free plugin.
 * 
 * @param array $data Posted checkout data (optional, for woocommerce_after_checkout_validation hook)
 * @param WP_Error $errors Error object (optional, for woocommerce_after_checkout_validation hook)
 */
function checkoutguard_check_customer_against_blocklists($data = null, $errors = null)
{
    // Prevent duplicate error messages across multiple hooks
    static $error_already_added = false;
    
    // Check if fraud blocker is enabled
    if (!checkoutguard_get_setting('enable_fraud_blocker', true)) {
        return;
    }

    // Get phone number from either $data parameter, Store API shapes, or posted form data
    $customer_phone = checkoutguard_extract_billing_phone($data);

    if (empty($customer_phone)) {
        return;
    }

    // Normalize the phone number
    $normalized_phone = checkoutguard_normalize_phone_number($customer_phone);
    
    if (empty($normalized_phone)) {
        return;
    }

    global $wpdb;
    $table_blocked_numbers = $wpdb->prefix . 'checkoutguard_blocked_numbers';

    // Check if phone is blocked
    $is_phone_blocked = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$table_blocked_numbers} WHERE phone_number = %s LIMIT 1",
        $normalized_phone
    ));

    if ($is_phone_blocked && !$error_already_added) {
        // Mark that we've added the error
        $error_already_added = true;
        
        // Get custom error message from settings
        $error_message = checkoutguard_get_setting(
            'blocked_phone_error_message',
            'Your order cannot be processed at this time. Please contact support.'
        );
        
        // Add error to WP_Error object if available (for woocommerce_after_checkout_validation hook)
        if ($errors && is_wp_error($errors)) {
            $errors->add('blocked_phone_number', esc_html($error_message));
        }
        
        // Also add notice for woocommerce_checkout_process hook (only if WP_Error not available)
        if (!$errors && function_exists('wc_add_notice')) {
            wc_add_notice(esc_html($error_message), 'error');
        }
    }
}

/**
 * Validate blocked phone before processing checkout data
 * This runs very early in the checkout process
 * 
 * @param array $data Posted checkout data
 * @return array Modified checkout data
 */
function checkoutguard_validate_blocked_phone_on_checkout($data)
{
    // Skip - main validation happens in checkoutguard_check_customer_against_blocklists
    // This filter just normalizes the phone data for consistency
    if (!checkoutguard_get_setting('enable_fraud_blocker', true)) {
        return $data;
    }

    $customer_phone = checkoutguard_extract_billing_phone($data);

    if (!empty($customer_phone)) {
        // Ensure downstream filters can also see the phone in the classic key
        $data['billing_phone'] = $customer_phone;
    }
    
    return $data;
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