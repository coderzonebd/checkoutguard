<?php
// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles saving/updating checkout data from the frontend.
 * ENHANCED: Better session handling, error logging, and data validation
 */
function checkoutguard_handle_save_checkout_data()
{
    // Basic Security
    check_ajax_referer('checkoutguard_save_checkout_data_nonce', 'nonce');

    // Enhanced Session Handling with Multiple Fallbacks
    try {
        // Ensure WooCommerce is loaded
        if (!function_exists('WC') || !WC()) {
            wp_send_json_error(['message' => 'WooCommerce not available.']);
        }

        // Initialize session if not exists
        if (!WC()->session) {
            $session_class = apply_filters('woocommerce_session_handler', 'WC_Session_Handler');
            if (class_exists($session_class)) {
                WC()->session = new $session_class();
                WC()->session->init();
            } else {
                wp_send_json_error(['message' => 'Session handler not available.']);
            }
        }

        // Create session if needed
        if (!WC()->session->has_session()) {
            WC()->session->set_customer_session_cookie(true);
        }

        // Get session ID with fallback
        $session_id = WC()->session->get_customer_id();
        
        // Fallback: Use a temporary identifier based on IP + User Agent
        if (!$session_id) {
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
            $session_id = 'temp_' . md5($ip . $user_agent . time());
        }
        
        if (!$session_id) {
            wp_send_json_error(['message' => 'Unable to generate session ID.']);
        }
    } catch (Exception $e) {
        // Log error and use fallback
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        $session_id = 'fallback_' . md5($ip . $user_agent . time());
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'checkoutguard_incomplete_checkouts';

    // 3. Auto-fix Table (Self-Healing) - Check and create if needed
    $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name));
    if ($table_exists != $table_name) {
        if (function_exists('checkoutguard_create_database_tables')) {
            checkoutguard_create_database_tables();
        } else {
            wp_send_json_error(['message' => 'Database table does not exist and cannot be created.']);
        }
        
        // Verify table was created
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name));
        if ($table_exists != $table_name) {
            wp_send_json_error(['message' => 'Failed to create database table.']);
        }
    }

    $existing_record_id = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$table_name} WHERE session_id = %s",
        $session_id
    ));

    // 4. Data Preparation (ALWAYS capture PII)
    $posted_data = isset($_POST) ? $_POST : array();
    
    // Get IP Address safely - Cloudflare Compatible
    $ip_address = '';
    
    // Explicitly check for Cloudflare header first
    if ( isset($_SERVER['HTTP_CF_CONNECTING_IP']) && !empty($_SERVER['HTTP_CF_CONNECTING_IP']) ) {
        $ip_address = sanitize_text_field($_SERVER['HTTP_CF_CONNECTING_IP']);
    } 
    // Fallback to WooCommerce Geolocation if available
    elseif (class_exists('WC_Geolocation')) {
        $ip_address = WC_Geolocation::get_ip_address();
    } 
    // Fallback to standard server remote address
    else {
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
    }
    
    // If multiple IPs are returned (comma separated), take the first one
    if ( strpos($ip_address, ',') !== false ) {
        $ip_parts = explode(',', $ip_address);
        $ip_address = trim($ip_parts[0]);
    }

    // Enhanced validation - skip if no meaningful data
    $has_data = false;
    if (!empty($posted_data['billing_first_name']) || 
        !empty($posted_data['billing_email']) || 
        !empty($posted_data['billing_phone'])) {
        $has_data = true;
    }
    
    if (!$has_data) {
        wp_send_json_success(['message' => 'No data to save yet.']);
    }

    $data_to_save = [
        'first_name' => isset($posted_data['billing_first_name']) ? sanitize_text_field($posted_data['billing_first_name']) : '',
        'last_name' => isset($posted_data['billing_last_name']) ? sanitize_text_field($posted_data['billing_last_name']) : '',
        'company' => isset($posted_data['billing_company']) ? sanitize_text_field($posted_data['billing_company']) : '',
        'address_1' => isset($posted_data['billing_address_1']) ? sanitize_text_field($posted_data['billing_address_1']) : '',
        'address_2' => isset($posted_data['billing_address_2']) ? sanitize_text_field($posted_data['billing_address_2']) : '',
        'city' => isset($posted_data['billing_city']) ? sanitize_text_field($posted_data['billing_city']) : '',
        'state' => isset($posted_data['billing_state']) ? sanitize_text_field($posted_data['billing_state']) : '',
        'postcode' => isset($posted_data['billing_postcode']) ? sanitize_text_field($posted_data['billing_postcode']) : '',
        'country' => isset($posted_data['billing_country']) ? sanitize_text_field($posted_data['billing_country']) : '',
        // ALWAYS SAVE THESE (Hidden in Admin UI if Free, but saved in DB)
        'email'      => isset($posted_data['billing_email']) ? sanitize_email($posted_data['billing_email']) : '',
        'phone'      => isset($posted_data['billing_phone']) ? sanitize_text_field($posted_data['billing_phone']) : '',
        'ip_address' => sanitize_text_field($ip_address),
        'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '',
        'customer_data' => wp_json_encode($posted_data), // Store full customer data as JSON
        
        'session_id' => $session_id,
        'updated_at' => current_time('mysql'),
    ];

    // MODIFIED: Apply filter to allow Pro version to add Email, Phone, and IP
    $data_to_save = apply_filters('checkoutguard_save_checkout_data_array', $data_to_save, $posted_data);

    $user_id = get_current_user_id();
    if ($user_id) {
        $data_to_save['user_id'] = $user_id;
    }

    // Enhanced cart data collection with error handling
    $cart_details_array = [];
    $cart_value = 0.00;
    
    try {
        if (WC()->cart && !WC()->cart->is_empty()) {
            foreach (WC()->cart->get_cart() as $cart_item) {
                $_product = isset($cart_item['data']) ? $cart_item['data'] : null;
                if ($_product && is_a($_product, 'WC_Product')) {
                    $cart_details_array[] = [
                        'product_id' => $_product->get_id(),
                        'name' => $_product->get_name(),
                        'quantity' => (int) ($cart_item['quantity'] ?? 0),
                        'price' => (float) $_product->get_price(),
                        'line_total' => (float) ($cart_item['line_subtotal'] ?? 0),
                        'variation_id' => isset($cart_item['variation_id']) ? (int) $cart_item['variation_id'] : 0,
                    ];
                }
            }
            
            // Get cart total with tax and shipping consideration
            $cart_value = (float) WC()->cart->get_cart_contents_total();
            
            // Add shipping if calculated
            if (WC()->cart->needs_shipping() && WC()->cart->show_shipping()) {
                $cart_value += (float) WC()->cart->get_shipping_total();
            }
            
            // Add tax if enabled
            if (wc_tax_enabled()) {
                $cart_value += (float) WC()->cart->get_cart_contents_tax();
            }
        }
    } catch (Exception $e) {
        // Log error but continue - cart data is not critical
        $cart_details_array = [];
        $cart_value = 0.00;
    }
    
    $data_to_save['cart_details'] = wp_json_encode($cart_details_array);
    $data_to_save['cart_items'] = wp_json_encode($cart_details_array); // Duplicate for analytics compatibility
    $data_to_save['cart_value'] = $cart_value;

    // Database operations with error handling
    if ($existing_record_id) {
        $result = $wpdb->update(
            $table_name, 
            $data_to_save, 
            ['id' => $existing_record_id],
            null, // format for $data (null = auto-detect)
            ['%d'] // format for $where
        );
        
        if ($result === false) {
            wp_send_json_error([
                'message' => 'Database update failed.',
                'db_error' => $wpdb->last_error
            ]);
        }
        
        // Fire action hook after update
        do_action('checkoutguard_after_save_checkout', $existing_record_id, $data_to_save);
        
        wp_send_json_success([
            'message' => 'Checkout data updated.',
            'record_id' => $existing_record_id
        ]);
        wp_die();
    } else {
        $data_to_save['created_at'] = current_time('mysql');
        $data_to_save['status'] = 'incomplete';
        
        $result = $wpdb->insert($table_name, $data_to_save);
        
        if ($result === false) {
            wp_send_json_error([
                'message' => 'Database insert failed.',
                'db_error' => $wpdb->last_error
            ]);
        }
        
        $new_id = $wpdb->insert_id;
        
        // Fire action hook after insert
        do_action('checkoutguard_after_save_checkout', $new_id, $data_to_save);
        
        wp_send_json_success([
            'message' => 'Checkout data saved.',
            'record_id' => $new_id
        ]);
        wp_die();
    }
}

/**
 * Handles the AJAX request to get incomplete checkout details for the modal.
 */
function checkoutguard_get_incomplete_checkout_details_ajax_handler()
{
    check_ajax_referer('checkoutguard_view_details_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => esc_html__('Permission denied.', 'checkoutguard')]);
        return;
    }

    // Removed rate limiting - it was too aggressive and causing false errors

    $entry_id = isset($_POST['entry_id']) ? absint($_POST['entry_id']) : 0;
    if (!$entry_id) {
        wp_send_json_error(['message' => esc_html__('Invalid ID.', 'checkoutguard')]);
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'checkoutguard_incomplete_checkouts';
    $entry = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d", $entry_id));

    if (!$entry) {
        wp_send_json_error(['message' => esc_html__('Entry not found.', 'checkoutguard')]);
        return;
    }

    // Decode cart items
    $cart_items = json_decode($entry->cart_details, true);
    if (!is_array($cart_items)) {
        $cart_items = array();
    }

    $address_parts = array_filter(array(
        $entry->address_1, $entry->address_2, $entry->city,
        $entry->state, $entry->postcode, $entry->country
    ));
    $full_address = !empty($address_parts) ? implode(', ', $address_parts) : esc_html__('No address provided', 'checkoutguard');

    ob_start();
    ?>
    <div class="checkoutguard-entry-details">
        <div class="checkoutguard-modal-section">
            <h3><?php esc_html_e('Customer Information', 'checkoutguard'); ?></h3>
            <div class="checkoutguard-info-grid">
                <div class="checkoutguard-info-item">
                    <span class="checkoutguard-info-label"><?php esc_html_e('Name:', 'checkoutguard'); ?></span>
                    <span class="checkoutguard-info-value"><?php 
                        $full_name = trim($entry->first_name . ' ' . $entry->last_name);
                        echo esc_html($full_name ?: __('(Not provided)', 'checkoutguard')); 
                    ?></span>
                </div>
            </div>
            
            <div class="checkoutguard-info-grid checkoutguard-contact-details">
                <div class="checkoutguard-info-item">
                    <span class="checkoutguard-info-label"><?php esc_html_e('Email:', 'checkoutguard'); ?></span>
                    <span class="checkoutguard-info-value">
                        <?php if (CHECKOUTGUARD_IS_PRO): ?>
                            <?php echo esc_html($entry->email ?: __('(Not provided)', 'checkoutguard')); ?>
                        <?php else: ?>
                            <a href="https://coderzonebd.com/pricing" target="_blank" class="button button-primary button-small" style="font-size: 11px; padding: 2px 8px; height: auto; line-height: 1.4;">
                                <span class="dashicons dashicons-lock" style="font-size: 12px; width: 12px; height: 12px; margin-top: 1px;"></span>
                                <?php esc_html_e('Upgrade to Pro', 'checkoutguard'); ?>
                            </a>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="checkoutguard-info-item">
                    <span class="checkoutguard-info-label"><?php esc_html_e('Phone:', 'checkoutguard'); ?></span>
                    <span class="checkoutguard-info-value">
                        <?php if (CHECKOUTGUARD_IS_PRO): ?>
                            <?php echo esc_html($entry->phone ?: __('(Not provided)', 'checkoutguard')); ?>
                        <?php else: ?>
                            <a href="https://coderzonebd.com/pricing" target="_blank" class="button button-primary button-small" style="font-size: 11px; padding: 2px 8px; height: auto; line-height: 1.4;">
                                <span class="dashicons dashicons-lock" style="font-size: 12px; width: 12px; height: 12px; margin-top: 1px;"></span>
                                <?php esc_html_e('Upgrade to Pro', 'checkoutguard'); ?>
                            </a>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="checkoutguard-info-item">
                    <span class="checkoutguard-info-label"><?php esc_html_e('IP Address:', 'checkoutguard'); ?></span>
                    <span class="checkoutguard-info-value">
                        <?php if (CHECKOUTGUARD_IS_PRO): ?>
                            <?php echo esc_html($entry->ip_address ?: __('(Not captured)', 'checkoutguard')); ?>
                        <?php else: ?>
                            <a href="https://coderzonebd.com/pricing" target="_blank" class="button button-primary button-small" style="font-size: 11px; padding: 2px 8px; height: auto; line-height: 1.4;">
                                <span class="dashicons dashicons-lock" style="font-size: 12px; width: 12px; height: 12px; margin-top: 1px;"></span>
                                <?php esc_html_e('Upgrade to Pro', 'checkoutguard'); ?>
                            </a>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
        </div>

        <div class="checkoutguard-modal-section">
            <h3><?php esc_html_e('Cart Details', 'checkoutguard'); ?></h3>
            <div class="checkoutguard-cart-summary">
                <p><strong><?php esc_html_e('Total Cart Value:', 'checkoutguard'); ?></strong> <?php echo wc_price($entry->cart_value); ?></p>
                <?php if (!empty($cart_items)): ?>
                    <div class="checkoutguard-cart-items-list">
                        <h4><?php esc_html_e('Items in Cart:', 'checkoutguard'); ?></h4>
                        <ul>
                            <?php foreach ($cart_items as $item): ?>
                                <li>
                                    <strong><?php echo esc_html($item['name']); ?></strong>
                                    <span class="checkoutguard-item-meta">
                                        <?php esc_html_e('Quantity:', 'checkoutguard'); ?> <?php echo esc_html($item['quantity']); ?>
                                        <?php if (isset($item['line_total']) && $item['line_total'] > 0): ?>
                                            | <?php echo wc_price($item['line_total']); ?>
                                        <?php endif; ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php else: ?>
                    <p class="checkoutguard-no-items"><?php esc_html_e('No cart items found.', 'checkoutguard'); ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="checkoutguard-modal-section">
            <h3><?php esc_html_e('Checkout Information', 'checkoutguard'); ?></h3>
            <div class="checkoutguard-info-grid">
                <div class="checkoutguard-info-item">
                    <span class="checkoutguard-info-label"><?php esc_html_e('Full Address:', 'checkoutguard'); ?></span>
                    <span class="checkoutguard-info-value"><?php echo esc_html($full_address); ?></span>
                </div>
                <div class="checkoutguard-info-item">
                    <span class="checkoutguard-info-label"><?php esc_html_e('Status:', 'checkoutguard'); ?></span>
                    <span class="checkoutguard-info-value"><span class="checkoutguard-status-badge checkoutguard-status-<?php echo esc_attr($entry->status); ?>"><?php echo esc_html(ucfirst($entry->status)); ?></span></span>
                </div>
                <div class="checkoutguard-info-item">
                    <span class="checkoutguard-info-label"><?php esc_html_e('First Captured:', 'checkoutguard'); ?></span>
                    <span class="checkoutguard-info-value"><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($entry->created_at))); ?></span>
                </div>
                <div class="checkoutguard-info-item">
                    <span class="checkoutguard-info-label"><?php esc_html_e('Last Updated:', 'checkoutguard'); ?></span>
                    <span class="checkoutguard-info-value"><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($entry->updated_at))); ?></span>
                </div>
            </div>
        </div>

        <?php if ($entry->admin_notes): ?>
        <div class="checkoutguard-modal-section">
            <h3><?php esc_html_e('Admin Notes', 'checkoutguard'); ?></h3>
            <div class="checkoutguard-admin-notes">
                <?php echo wp_kses_post(nl2br($entry->admin_notes)); ?>
            </div>
        </div>
        <?php endif; ?>

        <?php 
        // Hook for Pro version to add action buttons
        do_action('checkoutguard_modal_actions', $entry); 
        ?>
    </div>
    <?php
    $html_output = ob_get_clean();

    wp_send_json_success(['html' => $html_output]);
    wp_die();
}

/**
 * Handles the AJAX request to mark an incomplete checkout as 'cancelled'.
 */
function checkoutguard_mark_cancelled_ajax_handler()
{
    check_ajax_referer('checkoutguard_mark_cancelled_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => esc_html__('Permission denied.', 'checkoutguard')]);
        return;
    }
    // Rate limiting
    $user_id = get_current_user_id();
    $rate_key = 'cg_mark_cancelled_' . $user_id;
    if (get_transient($rate_key)) {
        wp_send_json_error(['message' => esc_html__('Too many requests. Please wait.', 'checkoutguard')], 429);
        return;
    }
    set_transient($rate_key, true, 1); // 1 second cooldown
    $entry_id = isset($_POST['entry_id']) ? intval($_POST['entry_id']) : 0;
    if (!$entry_id) {
        wp_send_json_error(['message' => esc_html__('Invalid ID.', 'checkoutguard')]);
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'checkoutguard_incomplete_checkouts';
    $result = $wpdb->update(
        $table_name,
        ['status' => 'cancelled', 'updated_at' => current_time('mysql')],
        ['id' => $entry_id, 'status' => 'incomplete'],
        ['%s', '%s'],
        ['%d', '%s']
    );

    if ($result !== false) {
        wp_send_json_success(['message' => esc_html__('Entry marked as "Cancelled".', 'checkoutguard')]);
    } else {
        wp_send_json_error(['message' => esc_html__('Could not update entry.', 'checkoutguard')]);
    }
    wp_die();
}

/**
 * Handles adding a blocked phone number via AJAX.
 */
function checkoutguard_handle_add_blocked_item_ajax()
{
    check_ajax_referer('checkoutguard_fraud_blocker_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => esc_html__('Permission denied.', 'checkoutguard')]);
        return;
    }

    // Accept legacy parameter name `type` for backward compatibility
    $block_type = isset($_POST['block_type']) ? sanitize_key($_POST['block_type']) : '';
    if (empty($block_type) && isset($_POST['type'])) {
        $block_type = sanitize_key($_POST['type']);
    }
    if ($block_type !== 'phone') {
        wp_send_json_error(['message' => esc_html__('Invalid block type.', 'checkoutguard')]);
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'checkoutguard_blocked_numbers';

    $value = isset($_POST['value']) ? checkoutguard_normalize_phone_number(sanitize_text_field(wp_unslash($_POST['value']))) : '';
    $reason = isset($_POST['reason']) ? sanitize_textarea_field(wp_unslash($_POST['reason'])) : '';

    if (empty($value)) {
        wp_send_json_error(['message' => esc_html__('Phone number cannot be empty.', 'checkoutguard')]);
        return;
    }

    $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table_name} WHERE phone_number = %s", $value));
    if ($existing) {
        wp_send_json_error(['message' => esc_html__('This phone number is already blocked.', 'checkoutguard')]);
        return;
    }

    $inserted = $wpdb->insert($table_name, [
        'phone_number' => $value,
        'reason' => $reason,
        'added_by' => get_current_user_id()
    ]);

    if ($inserted) {
        $new_item_id = $wpdb->insert_id;
        $item = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d", $new_item_id));
        $html = checkoutguard_get_blocked_list_item_html($item, 'phone_number', 'phone');
        wp_send_json_success(['html' => $html]);
    } else {
        wp_send_json_error(['message' => esc_html__('Database error.', 'checkoutguard')]);
    }
}

/**
 * Handles deleting a blocked item via AJAX.
 */
function checkoutguard_handle_delete_blocked_item_ajax()
{
    check_ajax_referer('checkoutguard_delete_blocked_item_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => esc_html__('Permission denied.', 'checkoutguard')]);
        return;
    }

    $item_id = isset($_POST['item_id']) ? intval($_POST['item_id']) : 0;
    $value = isset($_POST['value']) ? checkoutguard_normalize_phone_number(sanitize_text_field(wp_unslash($_POST['value']))) : '';

    if ($item_id <= 0 && empty($value)) {
        wp_send_json_error(['message' => esc_html__('No item specified for deletion.', 'checkoutguard')]);
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'checkoutguard_blocked_numbers';

    if ($item_id > 0) {
        $deleted = $wpdb->delete($table_name, ['id' => $item_id], ['%d']);
    } else {
        $deleted = $wpdb->delete($table_name, ['phone_number' => $value], ['%s']);
    }

    if ($deleted !== false) {
        wp_send_json_success(['message' => esc_html__('Item removed from blocklist.', 'checkoutguard')]);
    } else {
        wp_send_json_error(['message' => esc_html__('Could not remove item.', 'checkoutguard')]);
    }
    wp_die();
}

/**
 * Handles recovering an order (creating a WC Order from checkout data).
 */
function checkoutguard_recover_order_ajax_handler()
{
    check_ajax_referer('checkoutguard_recover_order_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => esc_html__('Permission denied.', 'checkoutguard')]);
        return;
    }

    $entry_id = isset($_POST['entry_id']) ? absint($_POST['entry_id']) : 0;
    if (!$entry_id) {
        wp_send_json_error(['message' => esc_html__('Invalid ID.', 'checkoutguard')]);
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'checkoutguard_incomplete_checkouts';
    $entry = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d", $entry_id));

    if (!$entry) {
        wp_send_json_error(['message' => esc_html__('Entry not found.', 'checkoutguard')]);
        return;
    }

    // Logic to create WooCommerce Order
    try {
        $order = wc_create_order();
        
        // Add products
        $cart_items = json_decode($entry->cart_details, true);
        if (is_array($cart_items)) {
            foreach ($cart_items as $item) {
                if (isset($item['product_id'])) {
                    $order->add_product(wc_get_product($item['product_id']), $item['quantity']);
                }
            }
        }

        // Set address
        $address = [
            'first_name' => $entry->first_name,
            'last_name'  => $entry->last_name,
            'email'      => $entry->email,
            'phone'      => $entry->phone,
            'address_1'  => $entry->address_1,
            'address_2'  => $entry->address_2,
            'city'       => $entry->city,
            'state'      => $entry->state,
            'postcode'   => $entry->postcode,
            'country'    => $entry->country,
        ];
        $order->set_address($address, 'billing');
        $order->set_address($address, 'shipping');

        $order->calculate_totals();
        $order->update_status('pending', 'Recovered from CheckoutGuard');
        $order->save();

        // Update entry status
        $wpdb->update(
            $table_name,
            [
                'status' => 'recovered',
                'recovered_order_id' => $order->get_id()
            ],
            ['id' => $entry_id],
            ['%s', '%d'],
            ['%d']
        );

        wp_send_json_success(['message' => esc_html__('Order recovered successfully.', 'checkoutguard')]);
        wp_die();

    } catch (Exception $e) {
        wp_send_json_error(['message' => $e->getMessage()]);
        wp_die();
    }
}

/**
 * Handles marking a checkout as hold.
 */
function checkoutguard_mark_hold_ajax_handler()
{
    check_ajax_referer('checkoutguard_mark_hold_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => esc_html__('Permission denied.', 'checkoutguard')]);
        return;
    }

    $entry_id = isset($_POST['entry_id']) ? absint($_POST['entry_id']) : 0;
    if (!$entry_id) {
        wp_send_json_error(['message' => esc_html__('Invalid ID.', 'checkoutguard')]);
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'checkoutguard_incomplete_checkouts';
    
    $updated = $wpdb->update(
        $table_name,
        ['status' => 'hold'],
        ['id' => $entry_id],
        ['%s'],
        ['%d']
    );

    if ($updated !== false) {
        wp_send_json_success(['message' => esc_html__('Marked as hold.', 'checkoutguard')]);
    } else {
        wp_send_json_error(['message' => esc_html__('Database error.', 'checkoutguard')]);
    }
    wp_die();
}

/**
 * Handles re-opening a checkout (setting status back to incomplete).
 */
function checkoutguard_reopen_checkout_ajax_handler()
{
    check_ajax_referer('checkoutguard_reopen_checkout_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => esc_html__('Permission denied.', 'checkoutguard')]);
        return;
    }

    $entry_id = isset($_POST['entry_id']) ? absint($_POST['entry_id']) : 0;
    if (!$entry_id) {
        wp_send_json_error(['message' => esc_html__('Invalid ID.', 'checkoutguard')]);
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'checkoutguard_incomplete_checkouts';
    
    $updated = $wpdb->update(
        $table_name,
        ['status' => 'incomplete'],
        ['id' => $entry_id],
        ['%s'],
        ['%d']
    );

    if ($updated !== false) {
        wp_send_json_success(['message' => esc_html__('Re-opened successfully.', 'checkoutguard')]);
    } else {
        wp_send_json_error(['message' => esc_html__('Database error.', 'checkoutguard')]);
    }
    wp_die();
}

/**
 * Handles editing the follow-up date.
 */
function checkoutguard_edit_follow_up_date_ajax_handler()
{
    check_ajax_referer('checkoutguard_edit_follow_up_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => esc_html__('Permission denied.', 'checkoutguard')]);
        return;
    }

    $entry_id = isset($_POST['entry_id']) ? absint($_POST['entry_id']) : 0;
    $date = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '';

    if (!$entry_id || empty($date)) {
        wp_send_json_error(['message' => esc_html__('Invalid parameters.', 'checkoutguard')]);
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'checkoutguard_incomplete_checkouts';
    
    $updated = $wpdb->update(
        $table_name,
        ['follow_up_date' => $date],
        ['id' => $entry_id],
        ['%s'],
        ['%d']
    );

    if ($updated !== false) {
        wp_send_json_success(['message' => esc_html__('Follow-up date updated.', 'checkoutguard')]);
    } else {
        wp_send_json_error(['message' => esc_html__('Database error.', 'checkoutguard')]);
    }
    wp_die();
}