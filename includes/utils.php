<?php
// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Displays an admin notice if WooCommerce is not active.
 */
function checkoutguard_woocommerce_missing_notice()
{
    ?>
    <div class="notice notice-error is-dismissible">
        <p><?php esc_html_e('<strong>CheckoutGuard</strong> requires WooCommerce to be installed and activated to function properly.', 'checkoutguard'); ?>
        </p>
    </div>
    <?php
}

/**
 * REMOVED: Daily cleanup function.
 * This is no longer needed as the free version does not have data limits.
 */
// function checkoutguard_run_daily_cleanup() { ... }


/**
 * Helper function to get an array of formats for $wpdb->insert/update.
 * @param array $data Data array to get formats for.
 * @return array Array of formats.
 */
function checkoutguard_get_db_formats($data)
{
    $formats = array();
    // Define default formats for columns
    $column_formats = array(
        'session_id' => '%s',
        'user_id' => '%d',
        'email' => '%s',
        'first_name' => '%s',
        'last_name' => '%s',
        'phone' => '%s',
        'address_1' => '%s',
        'address_2' => '%s',
        'city' => '%s',
        'state' => '%s',
        'postcode' => '%s',
        'country' => '%s',
        'ip_address' => '%s',
        'cart_details' => '%s',
        'cart_value' => '%f',
        'status' => '%s',
        'created_at' => '%s',
        'updated_at' => '%s'
    );
    foreach ($data as $key => $value) {
        $formats[] = $column_formats[$key] ?? '%s';
    }
    return $formats;
}

/**
 * Normalizes a phone number to a consistent format.
 * This is especially important for the context in Bangladesh.
 *
 * @param string $phone The phone number to normalize.
 * @return string The normalized 11-digit phone number.
 */
function checkoutguard_normalize_phone_number($phone)
{
    // Return empty if the input is empty
    if (empty($phone)) {
        return '';
    }

    // 1. Remove all characters except digits.
    $cleaned_phone = preg_replace('/\D/', '', $phone);

    // 2. Handle country code prefixes (e.g., 880).
    // If it starts with '880' and is 13 digits long (e.g., 88017...), strip '88'.
    if (strpos($cleaned_phone, '880') === 0 && strlen($cleaned_phone) === 13) {
        return substr($cleaned_phone, 2);
    }

    // If it starts with '0' and is 11 digits long, it's already in the correct format.
    if (strpos($cleaned_phone, '0') === 0 && strlen($cleaned_phone) === 11) {
        return $cleaned_phone;
    }

    // If it's 10 digits long and doesn't start with '0' (e.g., 17...), prepend '0'.
    if (strlen($cleaned_phone) === 10 && strpos($cleaned_phone, '0') !== 0) {
        return '0' . $cleaned_phone;
    }

    // Fallback for other formats.
    return $cleaned_phone;
}
