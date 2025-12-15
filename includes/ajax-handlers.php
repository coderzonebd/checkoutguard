<?php
// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles saving/updating checkout data from the frontend.
 * REMOVED: 10-record limit.
 */
function checkoutguard_handle_save_checkout_data()
{
    check_ajax_referer('checkoutguard_save_checkout_data_nonce', 'nonce');

    if (!WC()->session || !WC()->session->has_session()) {
        WC()->session->set_customer_session_cookie(true);
    }
    $session_id = WC()->session->get_customer_id();

    if (!$session_id) {
        wp_send_json_error(['message' => 'WooCommerce session not available.']);
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'checkoutguard_incomplete_checkouts';
    $existing_record_id = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$table_name} WHERE session_id = %s",
        $session_id
    ));

    // Sanitize all POST data
    $posted_data = isset($_POST) ? $_POST : array();
    $data_to_save = [
        'email' => isset($posted_data['billing_email']) ? sanitize_email($posted_data['billing_email']) : '',
        'first_name' => isset($posted_data['billing_first_name']) ? sanitize_text_field($posted_data['billing_first_name']) : '',
        'last_name' => isset($posted_data['billing_last_name']) ? sanitize_text_field($posted_data['billing_last_name']) : '',
        'phone' => isset($posted_data['billing_phone']) ? checkoutguard_normalize_phone_number(sanitize_text_field($posted_data['billing_phone'])) : '',
        'address_1' => isset($posted_data['billing_address_1']) ? sanitize_text_field($posted_data['billing_address_1']) : '',
        'city' => isset($posted_data['billing_city']) ? sanitize_text_field($posted_data['billing_city']) : '',
        'postcode' => isset($posted_data['billing_postcode']) ? sanitize_text_field($posted_data['billing_postcode']) : '',
        'country' => isset($posted_data['billing_country']) ? sanitize_text_field($posted_data['billing_country']) : '',
        'ip_address' => WC_Geolocation::get_ip_address(),
        'session_id' => $session_id,
        'updated_at' => current_time('mysql'),
    ];

    $user_id = get_current_user_id();
    if ($user_id) {
        $data_to_save['user_id'] = $user_id;
    }

    $cart_details_array = [];
    if (WC()->cart && !WC()->cart->is_empty()) {
        foreach (WC()->cart->get_cart() as $cart_item) {
            $_product = $cart_item['data'];
            if ($_product && is_a($_product, 'WC_Product')) {
                $cart_details_array[] = [
                    'product_id' => $_product->get_id(),
                    'name' => $_product->get_name(),
                    'quantity' => (int) $cart_item['quantity'],
                    'line_total' => (float) $cart_item['line_subtotal'],
                ];
            }
        }
    }
    $data_to_save['cart_details'] = wp_json_encode($cart_details_array);
    $data_to_save['cart_value'] = WC()->cart ? (float) WC()->cart->get_cart_contents_total() : 0.00;

    if ($existing_record_id) {
        $wpdb->update($table_name, $data_to_save, ['id' => $existing_record_id]);
        wp_send_json_success(['message' => 'Checkout data updated.']);
    } else {
        // REMOVED: 10-record limit enforcement.
        $data_to_save['created_at'] = current_time('mysql');
        $data_to_save['status'] = 'incomplete';
        $wpdb->insert($table_name, $data_to_save);
        wp_send_json_success(['message' => 'Checkout data saved.']);
    }
    wp_die();
}

/**
 * Handles the AJAX request to get incomplete checkout details for the modal.
 * REMOVED: Phone number is no longer hidden.
 */
function checkoutguard_get_incomplete_checkout_details_ajax_handler()
{
    check_ajax_referer('checkoutguard_view_details_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => esc_html__('Permission denied.', 'checkoutguard')]);
        return;
    }

    // Validate and sanitize entry_id
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

    // Decode cart data
    $cart_items = array();

    // Try to get cart data from cart_data field
    if (!empty($entry->cart_data)) {
        $decoded_items = json_decode($entry->cart_data, true);
        if (is_array($decoded_items)) {
            $cart_items = $decoded_items;
        }
    }

    // If cart_data is empty or invalid, try to extract from description
    if (empty($cart_items) && !empty($entry->description)) {
        $lines = explode("\n", $entry->description);
        foreach ($lines as $line) {
            // Match patterns like "1x Product Name" or just "Product Name"
            if (preg_match('/^(\d+)x\s+(.+)$/', $line, $matches)) {
                $cart_items[] = array(
                    'name' => trim($matches[2]),
                    'quantity' => intval($matches[1]),
                    'line_total' => 0
                );
            } elseif (!empty(trim($line))) {
                // Add any non-empty line as a product
                $cart_items[] = array(
                    'name' => trim($line),
                    'quantity' => 1,
                    'line_total' => 0
                );
            }
        }
    }

    // Fallback: If still no items, extract from the table data
    if (empty($cart_items)) {
        // Get products from the incomplete checkouts table
        $products_table = $wpdb->prefix . 'checkoutguard_incomplete_checkout_products';
        $products = $wpdb->get_results($wpdb->prepare(
            "SELECT product_name, quantity, price FROM {$products_table} WHERE checkout_id = %d",
            $entry_id
        ));

        if (!empty($products)) {
            foreach ($products as $product) {
                $cart_items[] = array(
                    'name' => $product->product_name,
                    'quantity' => $product->quantity,
                    'line_total' => $product->price
                );
            }
        }
    }

    ob_start();
    ?>
    <div class="cg-entry-details">
        <div class="cg-modal-section">
            <h3><?php esc_html_e('Customer Information', 'checkoutguard'); ?></h3>
            <div class="cg-info-grid">
                <div class="cg-info-item">
                    <span class="cg-info-label"><?php esc_html_e('Name:', 'checkoutguard'); ?></span>
                    <span
                        class="cg-info-value"><?php echo esc_html(trim($entry->first_name . ' ' . $entry->last_name)); ?></span>
                </div>
                <div class="cg-info-item">
                    <span class="cg-info-label"><?php esc_html_e('Email:', 'checkoutguard'); ?></span>
                    <span class="cg-info-value"><?php echo esc_html($entry->email); ?></span>
                </div>
                <div class="cg-info-item">
                    <span class="cg-info-label"><?php esc_html_e('Phone:', 'checkoutguard'); ?></span>
                    <span class="cg-info-value">
                        <?php echo esc_html($entry->phone); // REMOVED: Pro check, now always shows phone ?>
                    </span>
                </div>
            </div>
        </div>

        <div class="cg-modal-section">
            <h3><?php esc_html_e('Cart Details', 'checkoutguard'); ?></h3>
            <p><strong><?php esc_html_e('Cart Value:', 'checkoutguard'); ?></strong>
                <?php echo wc_price($entry->cart_value); ?></p>
            <?php
            $cart_items = json_decode($entry->cart_details, true);
            if (!empty($cart_items)) {
                echo '<ul>';
                foreach ($cart_items as $item) {
                    echo '<li>' . esc_html($item['name']) . ' (Qty: ' . esc_html($item['quantity']) . ')</li>';
                }
                echo '</ul>';
            }
            ?>
        </div>

        <div class="cg-modal-section">
            <h3><?php esc_html_e('Checkout Information', 'checkoutguard'); ?></h3>
            <div class="cg-info-grid">
                <div class="cg-info-item">
                    <span class="cg-info-label"><?php esc_html_e('Address:', 'checkoutguard'); ?></span>
                    <span class="cg-info-value"><?php echo esc_html($entry->address_1); ?></span>
                </div>
                <div class="cg-info-item">
                    <span class="cg-info-label"><?php esc_html_e('Cart Value:', 'checkoutguard'); ?></span>
                    <span class="cg-info-value"><?php echo wc_price($entry->cart_value); ?></span>
                </div>
                <div class="cg-info-item">
                    <span class="cg-info-label"><?php esc_html_e('Captured on:', 'checkoutguard'); ?></span>
                    <span
                        class="cg-info-value"><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($entry->created_at))); ?></span>
                </div>
            </div>
        </div>
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
 * REMOVED: 5-item limit.
 */
function checkoutguard_handle_add_blocked_item_ajax()
{
    check_ajax_referer('checkoutguard_fraud_blocker_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => esc_html__('Permission denied.', 'checkoutguard')]);
        return;
    }

    $block_type = isset($_POST['block_type']) ? sanitize_key($_POST['block_type']) : '';
    if ($block_type !== 'phone') {
        wp_send_json_error(['message' => esc_html__('Invalid block type.', 'checkoutguard')]);
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'checkoutguard_blocked_numbers';

    // REMOVED: 5-item limit enforcement.

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
}
