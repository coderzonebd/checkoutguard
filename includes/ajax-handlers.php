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
        'first_name' => isset($posted_data['billing_first_name']) ? sanitize_text_field($posted_data['billing_first_name']) : '',
        'last_name' => isset($posted_data['billing_last_name']) ? sanitize_text_field($posted_data['billing_last_name']) : '',
        'address_1' => isset($posted_data['billing_address_1']) ? sanitize_text_field($posted_data['billing_address_1']) : '',
        'city' => isset($posted_data['billing_city']) ? sanitize_text_field($posted_data['billing_city']) : '',
        'postcode' => isset($posted_data['billing_postcode']) ? sanitize_text_field($posted_data['billing_postcode']) : '',
        'country' => isset($posted_data['billing_country']) ? sanitize_text_field($posted_data['billing_country']) : '',
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

    // Decode cart items from cart_details field
    $cart_items = json_decode($entry->cart_details, true);
    if (!is_array($cart_items)) {
        $cart_items = array();
    }

    // Format full address
    $address_parts = array_filter(array(
        $entry->address_1,
        $entry->address_2,
        $entry->city,
        $entry->state,
        $entry->postcode,
        $entry->country
    ));
    $full_address = !empty($address_parts) ? implode(', ', $address_parts) : esc_html__('No address provided', 'checkoutguard');

    ob_start();
    ?>
    <div class="cg-entry-details">
        <div class="cg-modal-section">
            <h3><?php esc_html_e('Customer Information', 'checkoutguard'); ?></h3>
            <div class="cg-info-grid">
                <div class="cg-info-item">
                    <span class="cg-info-label"><?php esc_html_e('Name:', 'checkoutguard'); ?></span>
                    <span class="cg-info-value"><?php 
                        $full_name = trim($entry->first_name . ' ' . $entry->last_name);
                        echo esc_html($full_name ?: __('(Not provided)', 'checkoutguard')); 
                    ?></span>
                </div>
            </div>
            <?php if (!CHECKOUTGUARD_IS_PRO): ?>
                <div class="cg-pro-upsell-box">
                    <span class="dashicons dashicons-lock"></span>
                    <p><?php esc_html_e('To see emails, phones, ip please upgrade to pro', 'checkoutguard'); ?></p>
                    <a href="https://coderzonebd.com/pricing" target="_blank" class="button button-primary">
                        <?php esc_html_e('Upgrade to Pro', 'checkoutguard'); ?>
                    </a>
                </div>
            <?php else: ?>
                <div class="cg-info-grid cg-contact-details">
                    <div class="cg-info-item">
                        <span class="cg-info-label"><?php esc_html_e('Email:', 'checkoutguard'); ?></span>
                        <span class="cg-info-value"><?php echo esc_html($entry->email ?: __('(Not provided)', 'checkoutguard')); ?></span>
                    </div>
                    <div class="cg-info-item">
                        <span class="cg-info-label"><?php esc_html_e('Phone:', 'checkoutguard'); ?></span>
                        <span class="cg-info-value"><?php echo esc_html($entry->phone ?: __('(Not provided)', 'checkoutguard')); ?></span>
                    </div>
                    <div class="cg-info-item">
                        <span class="cg-info-label"><?php esc_html_e('IP Address:', 'checkoutguard'); ?></span>
                        <span class="cg-info-value"><?php echo esc_html($entry->ip_address ?: __('(Not captured)', 'checkoutguard')); ?></span>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="cg-modal-section">
            <h3><?php esc_html_e('Cart Details', 'checkoutguard'); ?></h3>
            <div class="cg-cart-summary">
                <p><strong><?php esc_html_e('Total Cart Value:', 'checkoutguard'); ?></strong> <?php echo wc_price($entry->cart_value); ?></p>
                <?php if (!empty($cart_items)): ?>
                    <div class="cg-cart-items-list">
                        <h4><?php esc_html_e('Items in Cart:', 'checkoutguard'); ?></h4>
                        <ul>
                            <?php foreach ($cart_items as $item): ?>
                                <li>
                                    <strong><?php echo esc_html($item['name']); ?></strong>
                                    <span class="cg-item-meta">
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
                    <p class="cg-no-items"><?php esc_html_e('No cart items found.', 'checkoutguard'); ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="cg-modal-section">
            <h3><?php esc_html_e('Checkout Information', 'checkoutguard'); ?></h3>
            <div class="cg-info-grid">
                <div class="cg-info-item">
                    <span class="cg-info-label"><?php esc_html_e('Full Address:', 'checkoutguard'); ?></span>
                    <span class="cg-info-value"><?php echo esc_html($full_address); ?></span>
                </div>
                <div class="cg-info-item">
                    <span class="cg-info-label"><?php esc_html_e('Status:', 'checkoutguard'); ?></span>
                    <span class="cg-info-value"><span class="cg-status-badge cg-status-<?php echo esc_attr($entry->status); ?>"><?php echo esc_html(ucfirst($entry->status)); ?></span></span>
                </div>
                <div class="cg-info-item">
                    <span class="cg-info-label"><?php esc_html_e('First Captured:', 'checkoutguard'); ?></span>
                    <span class="cg-info-value"><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($entry->created_at))); ?></span>
                </div>
                <div class="cg-info-item">
                    <span class="cg-info-label"><?php esc_html_e('Last Updated:', 'checkoutguard'); ?></span>
                    <span class="cg-info-value"><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($entry->updated_at))); ?></span>
                </div>
            </div>
        </div>

        <?php if ($entry->admin_notes): ?>
        <div class="cg-modal-section">
            <h3><?php esc_html_e('Admin Notes', 'checkoutguard'); ?></h3>
            <div class="cg-admin-notes">
                <?php echo wp_kses_post(nl2br($entry->admin_notes)); ?>
            </div>
        </div>
        <?php endif; ?>
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
