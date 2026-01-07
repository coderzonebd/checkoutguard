<?php
/**
 * Courier Check AJAX Handlers
 * 
 * Handles AJAX requests for courier checking functionality.
 * Communicates with the external Laravel API.
 * 
 * @package CheckoutGuard
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles the AJAX request to check courier success rates.
 */
function checkoutguard_handle_courier_check_ajax()
{
    // Verify nonce
    check_ajax_referer('checkoutguard_courier_check_nonce', 'nonce');

    // Check permissions
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error([
            'message' => esc_html__('Permission denied.', 'checkoutguard')
        ]);
        return;
    }

    // Get and validate phone number
    $phone_number = isset($_POST['phone_number']) ? sanitize_text_field($_POST['phone_number']) : '';
    $bypass_cache = isset($_POST['bypass_cache']) && $_POST['bypass_cache'] === 'true';

    if (empty($phone_number)) {
        wp_send_json_error([
            'message' => esc_html__('Phone number is required.', 'checkoutguard')
        ]);
        return;
    }

    // Validate phone number format (Bangladesh: 01XXXXXXXXX)
    if (!preg_match('/^01[3-9]\d{8}$/', $phone_number)) {
        wp_send_json_error([
            'message' => esc_html__('Invalid phone number format. Please enter a valid Bangladeshi phone number (e.g., 01700000000).', 'checkoutguard')
        ]);
        return;
    }

    // Hardcoded API settings for test purpose
    $api_url = 'https://licenses.coderzonebd.com';
    $api_key = 'open-for-everyone-czbd';

    // Check local cache first (unless bypass_cache is true)
    if (!$bypass_cache) {
        $cached_data = checkoutguard_get_cached_courier_data($phone_number);
        if ($cached_data !== false) {
            wp_send_json_success([
                'data' => $cached_data,
                'cached' => true,
                'cache_source' => 'local',
                'message' => esc_html__('Showing cached results from local database.', 'checkoutguard')
            ]);
            wp_die();
        }
    }

    // Prepare API endpoint
    $api_endpoint = trailingslashit($api_url) . 'api/v1/courier/check';

    // Prepare request data
    $body = array(
        'phone_number' => $phone_number,
        'bypass_cache' => $bypass_cache
    );

    // Make API request
    $response = wp_remote_post($api_endpoint, array(
        'method' => 'POST',
        'timeout' => 30,
        'headers' => array(
            'Content-Type' => 'application/json',
            'X-API-Key' => $api_key,
            'Accept' => 'application/json',
        ),
        'body' => wp_json_encode($body),
    ));

    // Check for errors
    if (is_wp_error($response)) {
        wp_send_json_error([
            'message' => sprintf(
                esc_html__('API request failed: %s', 'checkoutguard'),
                $response->get_error_message()
            )
        ]);
        return;
    }

    $response_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);
    $data = json_decode($response_body, true);


    // Handle different response codes
    if ($response_code === 200 && isset($data['success']) && $data['success']) {
        // Save to database for recent searches and local caching
        checkoutguard_save_courier_search($phone_number, $data['data']);

        wp_send_json_success([
            'data' => $data['data'],
            'cached' => isset($data['cached']) ? $data['cached'] : false,
            'cache_source' => 'api',
            'message' => esc_html__('Courier check completed successfully.', 'checkoutguard')
        ]);
    } elseif ($response_code === 401) {
        wp_send_json_error([
            'message' => esc_html__('Authentication failed. Please check your API Key.', 'checkoutguard')
        ]);
    } elseif ($response_code === 403) {
        wp_send_json_error([
            'message' => esc_html__('Access forbidden. Invalid API Key.', 'checkoutguard')
        ]);
    } elseif ($response_code === 422) {
        $error_message = isset($data['message']) ? $data['message'] : esc_html__('Validation error.', 'checkoutguard');
        wp_send_json_error([
            'message' => $error_message
        ]);
    } elseif ($response_code === 429) {
        $error_message = isset($data['message']) ? $data['message'] : esc_html__('Rate limit exceeded. Please try again later.', 'checkoutguard');
        wp_send_json_error([
            'message' => $error_message,
            'rate_limit' => isset($data['rate_limit']) ? $data['rate_limit'] : null
        ]);
    } elseif ($response_code === 500 || $response_code === 502 || $response_code === 503) {
        wp_send_json_error([
            'message' => esc_html__('The courier service API is temporarily unavailable. Please try again in a few moments.', 'checkoutguard')
        ]);
    } else {
        $error_message = isset($data['message']) ? $data['message'] : esc_html__('Unknown error occurred.', 'checkoutguard');
        wp_send_json_error([
            'message' => sprintf(
                esc_html__('API Error (Code: %d): %s', 'checkoutguard'),
                $response_code,
                $error_message
            )
        ]);
    }

    wp_die();
}

/**
 * Gets cached courier data from local database.
 * Returns false if cache expired or not found.
 * Cache duration: 6 hours (21600 seconds)
 * 
 * @param string $phone_number The phone number to check.
 * @return array|false Cached data or false if not found/expired.
 */
function checkoutguard_get_cached_courier_data($phone_number)
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'checkoutguard_courier_searches';

    $cached = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table_name} WHERE phone_number = %s ORDER BY searched_at DESC LIMIT 1",
        $phone_number
    ));

    if (!$cached) {
        return false;
    }

    // Check if cache is still valid (6 hours = 21600 seconds)
    $cache_age = time() - strtotime($cached->searched_at);
    $cache_duration = 6 * 3600; // 6 hours in seconds

    if ($cache_age > $cache_duration) {
        // Cache expired - delete from database
        $wpdb->delete(
            $table_name,
            array('id' => $cached->id),
            array('%d')
        );
        return false;
    }

    // Decode and return cached data
    $cached_results = json_decode($cached->results_json, true);
    
    if (!$cached_results) {
        return false;
    }

    // Add cache metadata
    $cached_results['cached_at'] = $cached->searched_at;
    $cached_results['cache_age_minutes'] = round($cache_age / 60);
    
    return $cached_results;
}

/**
 * Saves courier search results to database.
 * 
 * @param string $phone_number The phone number checked.
 * @param array $data The courier check results.
 */
function checkoutguard_save_courier_search($phone_number, $data)
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'checkoutguard_courier_searches';

    // Create table if it doesn't exist
    checkoutguard_create_courier_searches_table();

    $summary = isset($data['summary']) ? $data['summary'] : array();

    $insert_data = array(
        'phone_number' => $phone_number,
        'total_deliveries' => isset($summary['total_deliveries']) ? intval($summary['total_deliveries']) : 0,
        'successful_deliveries' => isset($summary['successful_deliveries']) ? intval($summary['successful_deliveries']) : 0,
        'success_rate' => isset($summary['success_rate']) ? floatval($summary['success_rate']) : 0,
        'risk_level' => isset($summary['risk_level']) ? sanitize_text_field($summary['risk_level']) : 'N/A',
        'searched_by' => get_current_user_id(),
        'searched_at' => current_time('mysql'),
        'results_json' => wp_json_encode($data)
    );

    // Check if phone number already exists
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$table_name} WHERE phone_number = %s",
        $phone_number
    ));

    if ($existing) {
        // Update existing record
        $wpdb->update(
            $table_name,
            $insert_data,
            array('id' => $existing),
            array('%s', '%d', '%d', '%f', '%s', '%d', '%s', '%s'),
            array('%d')
        );
    } else {
        // Insert new record
        $wpdb->insert(
            $table_name,
            $insert_data,
            array('%s', '%d', '%d', '%f', '%s', '%d', '%s', '%s')
        );
    }
}

/**
 * Creates the courier searches table if it doesn't exist.
 */
function checkoutguard_create_courier_searches_table()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'checkoutguard_courier_searches';
    $charset_collate = $wpdb->get_charset_collate();

    // Check if table exists
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") == $table_name) {
        return;
    }

    $sql = "CREATE TABLE {$table_name} (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        phone_number varchar(20) NOT NULL,
        total_deliveries int(11) DEFAULT 0,
        successful_deliveries int(11) DEFAULT 0,
        success_rate decimal(5,2) DEFAULT 0.00,
        risk_level varchar(50) DEFAULT 'N/A',
        searched_by bigint(20) UNSIGNED DEFAULT 0,
        searched_at datetime NOT NULL,
        results_json longtext,
        PRIMARY KEY (id),
        KEY phone_number (phone_number),
        KEY searched_at (searched_at)
    ) {$charset_collate};";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

/**
 * Handles the AJAX request to get recent searches.
 */
function checkoutguard_handle_get_recent_searches_ajax()
{
    // Verify nonce
    check_ajax_referer('checkoutguard_courier_check_nonce', 'nonce');

    // Check permissions
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error([
            'message' => esc_html__('Permission denied.', 'checkoutguard')
        ]);
        return;
    }

    ob_start();
    checkoutguard_render_recent_searches();
    $html = ob_get_clean();

    wp_send_json_success([
        'html' => $html
    ]);

    wp_die();
}

/**
 * Handles the AJAX request to delete a search history entry.
 */
function checkoutguard_handle_delete_courier_search_ajax()
{
    // Verify nonce
    check_ajax_referer('checkoutguard_courier_check_nonce', 'nonce');

    // Check permissions
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error([
            'message' => esc_html__('Permission denied.', 'checkoutguard')
        ]);
        return;
    }

    $search_id = isset($_POST['search_id']) ? intval($_POST['search_id']) : 0;

    if ($search_id <= 0) {
        wp_send_json_error([
            'message' => esc_html__('Invalid search ID.', 'checkoutguard')
        ]);
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'checkoutguard_courier_searches';

    $deleted = $wpdb->delete(
        $table_name,
        array('id' => $search_id),
        array('%d')
    );

    if ($deleted !== false) {
        wp_send_json_success([
            'message' => esc_html__('Search history deleted successfully.', 'checkoutguard')
        ]);
    } else {
        wp_send_json_error([
            'message' => esc_html__('Failed to delete search history.', 'checkoutguard')
        ]);
    }

    wp_die();
}

/**
 * Handles AJAX request to clear all courier search history.
 *
 * @return void
 */
function checkoutguard_handle_clear_all_searches_ajax()
{
    // Verify nonce
    check_ajax_referer('checkoutguard_courier_check_nonce', 'nonce');

    // Check permissions
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error([
            'message' => esc_html__('Permission denied.', 'checkoutguard')
        ]);
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'checkoutguard_courier_searches';

    // Check if table exists
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") != $table_name) {
        wp_send_json_error([
            'message' => esc_html__('No search history found.', 'checkoutguard')
        ]);
        return;
    }

    // Delete all records
    $deleted = $wpdb->query("TRUNCATE TABLE {$table_name}");

    if ($deleted !== false) {
        wp_send_json_success([
            'message' => esc_html__('All search history cleared successfully.', 'checkoutguard')
        ]);
    } else {
        wp_send_json_error([
            'message' => esc_html__('Failed to clear search history.', 'checkoutguard')
        ]);
    }

    wp_die();
}
