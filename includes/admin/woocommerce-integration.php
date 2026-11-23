<?php
if (!defined('ABSPATH')) {
    exit;
}

function cg_add_order_success_ratio_column($columns)
{
    $reordered_columns = array();
    foreach ($columns as $key => $column) {
        $reordered_columns[$key] = $column;
        if ($key === 'order_status') {
            $reordered_columns['order_success_ratio'] = __('Success Ratio', 'checkoutguard');
        }
    }
    return $reordered_columns;
}
add_filter('manage_edit-shop_order_columns', 'cg_add_order_success_ratio_column', 20);
add_filter('manage_woocommerce_page_wc-orders_columns', 'cg_add_order_success_ratio_column', 20);

function cg_render_order_success_ratio_column_content($column, $order_or_order_id)
{
    if ($column === 'order_success_ratio') {
        $order = ($order_or_order_id instanceof WC_Order) ? $order_or_order_id : wc_get_order($order_or_order_id);
        if (!$order) {
            return;
        }
        $order_id = $order->get_id();
        echo '<div class="cg-success-ratio-container" data-order-id="' . esc_attr($order_id) . '">';
        $phone_number = $order->get_billing_phone();
        if (empty($phone_number) && method_exists($order, 'get_shipping_phone')) {
            $phone_number = $order->get_shipping_phone();
        }
        $saved_ratio_data = $order->get_meta('_cg_success_ratio_data', true);
        if (!empty($saved_ratio_data) && is_array($saved_ratio_data) && isset($saved_ratio_data['summary'])) {
            echo cg_get_compact_ratio_html($saved_ratio_data['summary'], $phone_number, $order_id);
        } elseif (!empty($phone_number)) {
            echo '<button type="button" class="button button-small cg-check-order-ratio" data-phone="' . esc_attr($phone_number) . '" data-order-id="' . esc_attr($order_id) . '">' . __('Check', 'checkoutguard') . '</button>';
        } else {
            echo '<em>' . __('No phone', 'checkoutguard') . '</em>';
        }
        echo '</div>';
    }
}
add_action('manage_shop_order_posts_custom_column', 'cg_render_order_success_ratio_column_content', 10, 2);
add_action('manage_woocommerce_page_wc-orders_custom_column', 'cg_render_order_success_ratio_column_content', 10, 2);


function cg_add_success_ratio_meta_box()
{
    add_meta_box('cg_success_ratio_meta_box_id', __('Customer Success Ratio', 'checkoutguard'), 'cg_render_success_ratio_meta_box_content', 'shop_order', 'side', 'high');
    add_meta_box('cg_success_ratio_meta_box_id', __('Customer Success Ratio', 'checkoutguard'), 'cg_render_success_ratio_meta_box_content', 'woocommerce_page_wc-orders', 'side', 'high');
}
add_action('add_meta_boxes', 'cg_add_success_ratio_meta_box');

function cg_render_success_ratio_meta_box_content($post_or_order_object)
{
    if ($post_or_order_object instanceof WP_Post) {
        $order = wc_get_order($post_or_order_object->ID);
    } elseif ($post_or_order_object instanceof WC_Order) {
        $order = $post_or_order_object;
    } else {
        $order = isset($_GET['id']) ? wc_get_order((int) $_GET['id']) : null;
    }
    if (!$order) {
        echo '<p>' . __('Could not load order data.', 'checkoutguard') . '</p>';
        return;
    }

    $phone_number = $order->get_billing_phone();
    if (empty($phone_number) && method_exists($order, 'get_shipping_phone')) {
        $phone_number = $order->get_shipping_phone();
    }
    $order_id = $order->get_id();
    echo '<div id="cg_order_detail_ratio_container">';
    if (empty($phone_number)) {
        echo '<p>' . __('No billing phone number found.', 'checkoutguard') . '</p>';
    } else {
        $saved_results = cg_get_order_meta($order_id, '_cg_success_ratio_data');
        if (!empty($saved_results) && is_array($saved_results) && isset($saved_results['summary'])) {
            echo cg_get_courier_success_html($saved_results, $phone_number);
        } else {
            echo '<p style="margin-top:0;">Check the full delivery history.</p>';
            echo '<button type="button" class="button button-primary cg-meta-box-trigger" data-phone="' . esc_attr($phone_number) . '" data-order-id="' . esc_attr($order_id) . '">' . __('Check Full Ratio', 'checkoutguard') . '</button>';
        }
    }
    echo '</div>';
}
// This first function registers our new meta box with WooCommerce.
function cg_add_fraud_blocker_meta_box()
{
    add_meta_box(
        'cg_fraud_blocker_meta_box',
        __('Fraud Blocker Actions', 'checkoutguard'),
        'cg_render_fraud_blocker_meta_box_content',
        ['shop_order', 'woocommerce_page_wc-orders'], // Show on classic and HPOS order screens
        'side',
        'high'
    );
}
add_action('add_meta_boxes', 'cg_add_fraud_blocker_meta_box');

/**
 * Renders the content inside our Fraud Blocker meta box.
 * Now shows "Unblock" buttons.
 */
function cg_render_fraud_blocker_meta_box_content($post_or_order_object)
{
    if ($post_or_order_object instanceof WP_Post) {
        $order = wc_get_order($post_or_order_object->ID);
    } else {
        $order = $post_or_order_object;
    }

    if (!$order || !current_user_can('manage_woocommerce')) {
        return;
    }

    global $wpdb;
    $now = current_time('mysql');
    $ip_address = $order->get_customer_ip_address();
    $email = $order->get_billing_email();
    $phone = $order->get_billing_phone();
    $address_1 = $order->get_billing_address_1();
    $city = $order->get_billing_city();
    $postcode = $order->get_billing_postcode();

    // Look up device fingerprint - check order meta first, then incomplete_checkouts table
    $order_id = $order->get_id();
    $device_fp = $order->get_meta('_cg_device_fingerprint', true) ?: '';
    $device_ua = $order->get_meta('_cg_device_user_agent', true) ?: '';

    if (empty($device_fp) && $phone) {
        $normalized_phone = function_exists('cg_normalize_phone_number') ? cg_normalize_phone_number($phone) : $phone;
        $device_fp = $wpdb->get_var($wpdb->prepare(
            "SELECT device_fingerprint FROM {$wpdb->prefix}cg_incomplete_checkouts WHERE phone = %s AND device_fingerprint != '' ORDER BY updated_at DESC LIMIT 1",
            $normalized_phone
        )) ?: '';
    }

    $block_nonce = wp_create_nonce('cg_fraud_blocker_nonce');
    $unblock_nonce = wp_create_nonce('cg_delete_blocked_item_nonce');

    // Build items list
    $items = [];

    if ($ip_address) {
        $is_blocked = (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}cg_blocked_ips WHERE ip_address = %s AND (expires_at IS NULL OR expires_at > %s) LIMIT 1",
            $ip_address, $now
        ));
        $items[] = ['type' => 'ip', 'value' => $ip_address, 'label' => 'IP', 'blocked' => $is_blocked];
    }

    if ($email) {
        $is_blocked = (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}cg_blocked_emails WHERE email_address = %s AND (expires_at IS NULL OR expires_at > %s) LIMIT 1",
            $email, $now
        ));
        $items[] = ['type' => 'email', 'value' => $email, 'label' => 'Email', 'blocked' => $is_blocked, 'ref' => 'order:' . (int) $order_id];
    }

    if ($phone) {
        $check_phone = function_exists('cg_normalize_phone_number') ? cg_normalize_phone_number($phone) : $phone;
        $is_blocked = (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}cg_blocked_numbers WHERE phone_number = %s AND (expires_at IS NULL OR expires_at > %s) LIMIT 1",
            $check_phone, $now
        ));
        $items[] = ['type' => 'phone', 'value' => $check_phone, 'label' => 'Phone', 'blocked' => $is_blocked, 'ref' => 'order:' . (int) $order_id];
    }

    if ($device_fp) {
        $is_blocked = (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}cg_blocked_devices WHERE device_fingerprint = %s AND (expires_at IS NULL OR expires_at > %s) LIMIT 1",
            $device_fp, $now
        ));
        $short_fp = substr($device_fp, 0, 12) . '...';
        $device_name = '';
        if (!empty($device_ua) && function_exists('cg_get_device_name_from_ua')) {
            $device_name = cg_get_device_name_from_ua($device_ua);
        }
        $display = $device_name ? $device_name . ' (' . $short_fp . ')' : $short_fp;
        $items[] = ['type' => 'device', 'value' => $device_fp, 'label' => 'Device', 'display' => $display, 'blocked' => $is_blocked, 'device_info' => $device_ua];
    }

    if ($address_1) {
        $address_hash = function_exists('cg_hash_address') ? cg_hash_address($address_1, $city, $postcode) : '';
        if ($address_hash) {
            $is_blocked = (bool) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}cg_blocked_addresses WHERE address_hash = %s AND (expires_at IS NULL OR expires_at > %s) LIMIT 1",
                $address_hash, $now
            ));
            $address_display = implode(', ', array_filter([$address_1, $city, $postcode]));
            $items[] = ['type' => 'address', 'value' => wp_json_encode(['address_1' => $address_1, 'city' => $city, 'postcode' => $postcode]), 'label' => 'Address', 'display' => $address_display, 'blocked' => $is_blocked];
        }
    }
    $is_pro = cg_is_pro_plan();
    ?>
    <div class="cg-order-blocker">
        <?php foreach ($items as $item):
            $display_val = isset($item['display']) ? $item['display'] : $item['value'];
            // Same masking as the popups; the full value stays on the server.
            if (!$is_pro && $item['type'] === 'phone') {
                $display_val = cg_mask_phone($display_val);
            } elseif (!$is_pro && $item['type'] === 'email') {
                $display_val = cg_mask_email($display_val);
            }
            $short_val = (strlen($display_val) > 25) ? substr($display_val, 0, 22) . '...' : $display_val;
        ?>
        <div class="cg-order-blocker-row">
            <div class="cg-order-blocker-label"><strong><?php echo esc_html($item['label']); ?>:</strong> <?php echo esc_html($short_val); ?></div>
            <div class="cg-order-blocker-action">
                <?php if ($item['blocked']): ?>
                    <button type="button" class="button cg-unblock-from-order" data-block-type="<?php echo esc_attr($item['type']); ?>"<?php echo cg_block_button_target_attr($item); ?> data-nonce="<?php echo esc_attr($unblock_nonce); ?>"<?php if (!empty($item['device_info'])): ?> data-device-info="<?php echo esc_attr($item['device_info']); ?>"<?php endif; ?>><span class="dashicons dashicons-unlock"></span> Unblock</button>
                <?php else: ?>
                    <button type="button" class="button cg-block-from-order" data-block-type="<?php echo esc_attr($item['type']); ?>"<?php echo cg_block_button_target_attr($item); ?> data-nonce="<?php echo esc_attr($block_nonce); ?>"<?php if (!empty($item['device_info'])): ?> data-device-info="<?php echo esc_attr($item['device_info']); ?>"<?php endif; ?>><span class="dashicons dashicons-shield-alt"></span> Block</button>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($items)): ?>
        <div class="cg-order-blocker-row">
            <div class="cg-order-blocker-label"><em><?php _e('No customer identifiers available.', 'checkoutguard'); ?></em></div>
        </div>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Kept as a no-op alias for code that calls it directly. The courier ratio
 * check runs once per checkout in CG_Validation_Engine (CHECK 3) for the
 * classic, Store API and CartFlows flows. This hook used to repeat it on
 * classic checkout (a second courier lookup and its own block decision).
 */
function cg_block_order_by_success_ratio() {}

/**
 * Saves the device fingerprint and user agent as order meta when an order is created.
 * This makes device info available on the single order page for the Fraud Blocker meta box.
 */
function cg_save_device_fingerprint_to_order($order_id)
{
    $order = wc_get_order($order_id);
    if (!$order) {
        return;
    }
    // The device cookie and IP of an admin request are the shop manager's.
    if (cg_order_is_admin_created($order)) {
        return;
    }

    $needs_save = false;

    $device_fp = isset($_POST['cg_device_fingerprint']) ? sanitize_text_field(wp_unslash($_POST['cg_device_fingerprint'])) : '';
    if (empty($device_fp) && isset($_COOKIE['cg_device_fp'])) {
        $device_fp = sanitize_text_field(wp_unslash($_COOKIE['cg_device_fp']));
    }
    if (!empty($device_fp) && !$order->get_meta('_cg_device_fingerprint', true)) {
        $order->update_meta_data('_cg_device_fingerprint', $device_fp);
        $needs_save = true;
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
        if (!empty($user_agent)) {
            $order->update_meta_data('_cg_device_user_agent', $user_agent);
        }
    }

    // Always ensure the genuine client IP is stored on the order
    $client_ip = cg_get_client_ip();
    if (!empty($client_ip) && !$order->get_customer_ip_address()) {
        $order->set_customer_ip_address($client_ip);
        $needs_save = true;
    }

    if ($needs_save) {
        $order->save();
    }
}
add_action('woocommerce_checkout_order_processed', 'cg_save_device_fingerprint_to_order');
add_action('woocommerce_new_order', 'cg_save_device_fingerprint_to_order');

