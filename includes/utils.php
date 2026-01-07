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
        <p><strong>CheckoutGuard</strong> requires WooCommerce to be installed and activated to function properly.
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

/**
 * Validates if a phone number looks suspicious
 * 
 * @param string $phone The phone number to check
 * @return array Array with 'is_suspicious' boolean and 'reason' string
 */
function checkoutguard_check_suspicious_phone($phone) {
    $normalized = checkoutguard_normalize_phone_number($phone);
    
    if (empty($normalized)) {
        return array('is_suspicious' => false, 'reason' => '');
    }

    // Check for repeated digits (e.g., 01111111111)
    if (preg_match('/(.)\1{7,}/', $normalized)) {
        return array('is_suspicious' => true, 'reason' => 'Repeated digits pattern');
    }

    // Check for sequential numbers (e.g., 01234567890)
    if (preg_match('/012345|123456|234567|345678|456789|567890|987654|876543|765432|654321|543210/', $normalized)) {
        return array('is_suspicious' => true, 'reason' => 'Sequential number pattern');
    }

    // Check for all same digits except prefix
    $digits_only = substr($normalized, 2); // Remove '01' prefix
    if (strlen($digits_only) === count_chars($digits_only, 3)) {
        // Only one unique digit
        return array('is_suspicious' => true, 'reason' => 'All same digits');
    }

    // Check for invalid BD mobile operator prefixes
    $prefix = substr($normalized, 0, 4);
    $valid_prefixes = array('0130', '0131', '0132', '0133', '0134', '0135', '0136', '0137', '0138', '0139',
                            '0140', '0141', '0142', '0143', '0144', '0145', '0146', '0147', '0148', '0149',
                            '0150', '0151', '0152', '0153', '0154', '0155', '0156', '0157', '0158', '0159',
                            '0160', '0161', '0162', '0163', '0164', '0165', '0166', '0167', '0168', '0169',
                            '0170', '0171', '0172', '0173', '0174', '0175', '0176', '0177', '0178', '0179',
                            '0180', '0181', '0182', '0183', '0184', '0185', '0186', '0187', '0188', '0189',
                            '0190', '0191', '0192', '0193', '0194', '0195', '0196', '0197', '0198', '0199');
    
    $operator = substr($normalized, 0, 3);
    if (!in_array($operator, array('013', '014', '015', '016', '017', '018', '019'))) {
        return array('is_suspicious' => true, 'reason' => 'Invalid operator prefix');
    }

    return array('is_suspicious' => false, 'reason' => '');
}

/**
 * Get fraud statistics for phone number
 * 
 * @param string $phone The phone number to check
 * @return array Statistics about the phone number
 */
function checkoutguard_get_phone_fraud_stats($phone) {
    global $wpdb;
    $normalized = checkoutguard_normalize_phone_number($phone);
    
    if (empty($normalized)) {
        return array(
            'incomplete_count' => 0,
            'completed_count' => 0,
            'success_rate' => 0,
            'total_value' => 0,
        );
    }

    // Check incomplete checkouts
    $incomplete_table = $wpdb->prefix . 'checkoutguard_incomplete_checkouts';
    $incomplete_count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$incomplete_table} WHERE phone = %s AND status = 'incomplete'",
        $normalized
    ));

    // Check completed orders (in WooCommerce)
    $completed_count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
        WHERE p.post_type = 'shop_order'
        AND p.post_status IN ('wc-completed', 'wc-processing')
        AND pm.meta_key = '_billing_phone'
        AND pm.meta_value = %s",
        $normalized
    ));

    // Calculate success rate
    $total = $incomplete_count + $completed_count;
    $success_rate = $total > 0 ? round(($completed_count / $total) * 100, 2) : 0;

    // Get total order value
    $total_value = $wpdb->get_var($wpdb->prepare(
        "SELECT SUM(pm2.meta_value) FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
        INNER JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id
        WHERE p.post_type = 'shop_order'
        AND p.post_status IN ('wc-completed', 'wc-processing')
        AND pm.meta_key = '_billing_phone'
        AND pm.meta_value = %s
        AND pm2.meta_key = '_order_total'",
        $normalized
    ));

    return array(
        'incomplete_count' => (int) $incomplete_count,
        'completed_count' => (int) $completed_count,
        'success_rate' => $success_rate,
        'total_value' => (float) $total_value,
        'total_attempts' => $total,
    );
}
