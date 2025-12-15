<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Adds the Fraud Blocker meta box to the single order edit page.
 * The Courier Analytics meta box is removed for the free version.
 */
function checkoutguard_add_fraud_blocker_meta_box()
{
    add_meta_box(
        'checkoutguard_fraud_blocker_meta_box',
        esc_html__('CheckoutGuard Blocker', 'checkoutguard'),
        'checkoutguard_render_fraud_blocker_meta_box_content',
        ['shop_order', 'woocommerce_page_wc-orders'], // Show on classic and HPOS order screens
        'side',
        'high'
    );
}
add_action('add_meta_boxes', 'checkoutguard_add_fraud_blocker_meta_box');


/**
 * Renders the content inside our simplified Fraud Blocker meta box.
 * Only shows the phone number block action.
 */
function checkoutguard_render_fraud_blocker_meta_box_content($post_or_order_object)
{
    if ($post_or_order_object instanceof WP_Post) {
        $order = wc_get_order($post_or_order_object->ID);
    } else {
        // Handle HPOS screen
        $order = $post_or_order_object;
    }

    if (!$order || !current_user_can('manage_woocommerce')) {
        return;
    }

    global $wpdb;
    $phone = $order->get_billing_phone();
    $order_id = $order->get_id();

    // Create nonces for our AJAX actions
    $block_nonce = wp_create_nonce('checkoutguard_fraud_blocker_nonce');
    $unblock_nonce = wp_create_nonce('checkoutguard_delete_blocked_item_nonce');
    ?>
    <div class="cg-order-blocker">

        <?php // --- Phone Number Row --- ?>
        <div class="cg-order-blocker-row">
            <div class="cg-order-blocker-label"><strong><?php esc_html_e('Phone:', 'checkoutguard'); ?></strong>
                <?php echo esc_html($phone ?: 'N/A'); ?></div>
            <div class="cg-order-blocker-action">
                <?php
                $is_blocked = $phone ? $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}checkoutguard_blocked_numbers WHERE phone_number = %s", $phone)) : false;
                if ($is_blocked) {
                    echo '<button type="button" class="button checkoutguard-unblock-from-order" data-order-id="' . esc_attr($order_id) . '" data-block-type="phone" data-value="' . esc_attr($phone) . '" data-nonce="' . esc_attr($unblock_nonce) . '"><span class="dashicons dashicons-unlock"></span> ' . esc_html__('Unblock', 'checkoutguard') . '</button>';
                } elseif ($phone) {
                    echo '<button type="button" class="button checkoutguard-block-from-order" data-order-id="' . esc_attr($order_id) . '" data-block-type="phone" data-value="' . esc_attr($phone) . '" data-nonce="' . esc_attr($block_nonce) . '"><span class="dashicons dashicons-shield-alt"></span> ' . esc_html__('Block', 'checkoutguard') . '</button>';
                }
                ?>
            </div>
        </div>

        <?php // REMOVED: Upsell for IP and Email blocking ?>
        <div class="cg-order-blocker-row">
            <small><?php esc_html_e('Need to block by IP or Email?', 'checkoutguard'); ?> <a
                    href="https://coderzonebd.com/pricing"
                    target="_blank"><?php esc_html_e('Upgrade to Pro', 'checkoutguard'); ?></a></small>
        </div>
    </div>
    <?php
}
