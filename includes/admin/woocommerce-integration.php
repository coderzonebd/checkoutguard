<?php
// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Adds the CheckoutGuard meta box to the shop_order edit screen.
 */
function checkoutguard_add_order_fraud_check_metabox()
{
    add_meta_box(
        'checkoutguard_fraud_check',
        __('CheckoutGuard - Fraud Check', 'checkoutguard'),
        'checkoutguard_render_fraud_check_metabox',
        'shop_order',
        'side',
        'high'
    );
    // For HPOS compatibility
    if (class_exists(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)) {
         add_meta_box(
            'checkoutguard_fraud_check',
            __('CheckoutGuard - Fraud Check', 'checkoutguard'),
            'checkoutguard_render_fraud_check_metabox',
            wc_get_page_screen_id('shop-order'),
            'side',
            'high'
        );
    }
}
add_action('add_meta_boxes', 'checkoutguard_add_order_fraud_check_metabox');


/**
 * Renders the content of the meta box.
 */
function checkoutguard_render_fraud_check_metabox($post_or_order_object)
{
    $order = ($post_or_order_object instanceof WC_Order) ? $post_or_order_object : wc_get_order($post_or_order_object->ID);
    if (!$order) {
        echo '<p>' . esc_html__('Order not found.', 'checkoutguard') . '</p>';
        return;
    }

    $billing_phone = $order->get_billing_phone();
    $normalized_phone = checkoutguard_normalize_phone_number($billing_phone);

    echo '<div class="checkoutguard-fraud-check-box">';

    if (empty($normalized_phone)) {
        echo '<p>' . esc_html__('No phone number provided for this order.', 'checkoutguard') . '</p>';
    } else {
        // Check against our local blocklist
        global $wpdb;
        $table_name = $wpdb->prefix . 'checkoutguard_blocked_numbers';
        $blocked_entry = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_name} WHERE phone_number = %s", $normalized_phone));

        if ($blocked_entry) {
             echo '<div class="notice notice-error inline"><p><strong>' . esc_html__('WARNING: Blocked Number', 'checkoutguard') . '</strong></p>';
             echo '<p>' . esc_html__('Reason: ', 'checkoutguard') . esc_html($blocked_entry->reason) . '</p></div>';
        } else {
             // Check for suspicious patterns
             $suspicious = checkoutguard_check_suspicious_phone($normalized_phone);
             if ($suspicious['is_suspicious']) {
                 echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__('CAUTION: Suspicious Pattern', 'checkoutguard') . '</strong></p>';
                 echo '<p>' . esc_html($suspicious['reason']) . '</p></div>';
             } else {
                 echo '<div class="notice notice-success inline"><p>' . esc_html__('Phone number is not in your blocklist.', 'checkoutguard') . '</p></div>';
             }
        }
        
        // Show fraud statistics
        $stats = checkoutguard_get_phone_fraud_stats($normalized_phone);
        if ($stats['total_attempts'] > 0) {
            echo '<div style="margin: 15px 0; padding: 10px; background: #f9f9f9; border-radius: 5px;">';
            echo '<h4 style="margin: 0 0 10px 0;">' . esc_html__('Customer History', 'checkoutguard') . '</h4>';
            echo '<div style="font-size: 12px;">';
            echo '<p style="margin: 5px 0;"><strong>' . esc_html__('Completed Orders:', 'checkoutguard') . '</strong> ' . esc_html($stats['completed_count']) . '</p>';
            echo '<p style="margin: 5px 0;"><strong>' . esc_html__('Incomplete Checkouts:', 'checkoutguard') . '</strong> ' . esc_html($stats['incomplete_count']) . '</p>';
            
            $success_color = $stats['success_rate'] >= 70 ? '#46b450' : ($stats['success_rate'] >= 40 ? '#f0b849' : '#dc3232');
            echo '<p style="margin: 5px 0;"><strong>' . esc_html__('Success Rate:', 'checkoutguard') . '</strong> ';
            echo '<span style="color: ' . esc_attr($success_color) . '; font-weight: bold;">' . esc_html($stats['success_rate']) . '%</span></p>';
            
            if ($stats['total_value'] > 0) {
                echo '<p style="margin: 5px 0;"><strong>' . esc_html__('Total Value:', 'checkoutguard') . '</strong> ' . wc_price($stats['total_value']) . '</p>';
            }
            echo '</div></div>';
        }
        
        // Quick action section - show phone number and block/unblock button
        echo '<div style="margin: 20px 0; padding: 15px; background: #fff; border: 1px solid #ddd; border-radius: 5px;">';
        echo '<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">';
        echo '<div>';
        echo '<strong style="display: block; font-size: 13px; color: #666; margin-bottom: 5px;">' . esc_html__('Phone Number:', 'checkoutguard') . '</strong>';
        echo '<code style="font-size: 16px; font-weight: 600; color: #333; background: #f5f5f5; padding: 4px 8px; border-radius: 3px;">' . esc_html($normalized_phone) . '</code>';
        echo '</div>';
        
        if ($blocked_entry) {
            // Number is blocked - show Unblock button
            echo '<button type="button" class="button button-secondary" id="checkoutguard-quick-unblock-btn" data-phone="'.esc_attr($normalized_phone).'" data-item-id="'.esc_attr($blocked_entry->id).'" style="background: #f0b849; border-color: #f0b849; color: white;">';
            echo '<span class="dashicons dashicons-unlock" style="margin-top: 3px;"></span> ' . esc_html__('Unblock', 'checkoutguard');
            echo '</button>';
        } else {
            // Number is not blocked - show Block button
            echo '<button type="button" class="button button-primary" id="checkoutguard-quick-block-btn" data-phone="'.esc_attr($normalized_phone).'" style="background: #dc3232; border-color: #dc3232;">';
            echo '<span class="dashicons dashicons-shield" style="margin-top: 3px;"></span> ' . esc_html__('Block', 'checkoutguard');
            echo '</button>';
        }
        
        echo '</div>';
        
        // Block reason form (only show when blocking)
        if (!$blocked_entry) {
            echo '<div id="checkoutguard-quick-block-form" style="display:none; margin-top:10px; padding-top: 10px; border-top: 1px solid #ddd;">';
            echo '<label for="checkoutguard-quick-block-reason" style="display: block; margin-bottom: 5px; font-weight: 600; font-size: 12px;">' . esc_html__('Reason for blocking:', 'checkoutguard') . '</label>';
            echo '<textarea id="checkoutguard-quick-block-reason" placeholder="'.esc_attr__('Optional: Enter reason for blocking this number...', 'checkoutguard').'" style="width:100%; margin-bottom:10px; padding: 8px; border: 1px solid #ddd; border-radius: 3px; font-size: 13px;" rows="2"></textarea>';
            echo '<div style="display: flex; gap: 8px;">';
            echo '<button type="button" class="button button-primary" id="checkoutguard-confirm-block-btn" style="background: #dc3232; border-color: #dc3232;">'.esc_html__('Confirm Block', 'checkoutguard').'</button>';
            echo '<button type="button" class="button button-secondary" id="checkoutguard-cancel-block-btn">'.esc_html__('Cancel', 'checkoutguard').'</button>';
            echo '</div>';
            echo '</div>';
        }
        
        echo '</div>';
    }

    // Only show upsell if Pro is NOT active
    if ( ! defined('CHECKOUTGUARD_IS_PRO') || ! CHECKOUTGUARD_IS_PRO ) {
        echo '<hr>';
        echo '<p class="description" style="font-style:italic;">';
        echo esc_html__('Need to block by IP or Email? ', 'checkoutguard');
        echo '<a href="https://coderzonebd.com/checkoutguard" target="_blank">' . esc_html__('Upgrade to Pro', 'checkoutguard') . '</a>';
        echo '</p>';
    }

    echo '</div>';
    
    // Simple inline script for the quick block/unblock
    ?>
    <script>
    jQuery(document).ready(function($){
        // Show block form
        $('#checkoutguard-quick-block-btn').on('click', function(){
            $('#checkoutguard-quick-block-form').slideToggle();
        });
        
        // Cancel block
        $('#checkoutguard-cancel-block-btn').on('click', function(){
            $('#checkoutguard-quick-block-form').slideUp();
            $('#checkoutguard-quick-block-reason').val('');
        });
        
        // Confirm block
        $('#checkoutguard-confirm-block-btn').on('click', function(){
            var phone = $('#checkoutguard-quick-block-btn').data('phone');
            var reason = $('#checkoutguard-quick-block-reason').val();
            var $btn = $(this);
            $btn.prop('disabled', true).text('<?php esc_html_e('Blocking...', 'checkoutguard'); ?>');
            
            $.post(ajaxurl, {
                action: 'checkoutguard_add_blocked_item',
                nonce: '<?php echo wp_create_nonce("checkoutguard_fraud_blocker_nonce"); ?>',
                block_type: 'phone',
                value: phone,
                reason: reason
            }, function(response){
                if(response.success) {
                    location.reload();
                } else {
                    alert(response.data.message || '<?php esc_html_e('Error blocking number', 'checkoutguard'); ?>');
                    $btn.prop('disabled', false).text('<?php esc_html_e('Confirm Block', 'checkoutguard'); ?>');
                }
            });
        });
        
        // Unblock button
        $('#checkoutguard-quick-unblock-btn').on('click', function(){
            if (!confirm('<?php esc_html_e('Are you sure you want to unblock this number?', 'checkoutguard'); ?>')) {
                return;
            }
            
            var phone = $(this).data('phone');
            var itemId = $(this).data('item-id');
            var $btn = $(this);
            $btn.prop('disabled', true).html('<span class="dashicons dashicons-update" style="margin-top: 3px; animation: spin 1s linear infinite;"></span> <?php esc_html_e('Unblocking...', 'checkoutguard'); ?>');
            
            $.post(ajaxurl, {
                action: 'checkoutguard_delete_blocked_item',
                nonce: '<?php echo wp_create_nonce("checkoutguard_delete_blocked_item_nonce"); ?>',
                item_id: itemId,
                value: phone
            }, function(response){
                if(response.success) {
                    location.reload();
                } else {
                    alert(response.data.message || '<?php esc_html_e('Error unblocking number', 'checkoutguard'); ?>');
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-unlock" style="margin-top: 3px;"></span> <?php esc_html_e('Unblock', 'checkoutguard'); ?>');
                }
            });
        });
    });
    </script>
    <style>
    @keyframes spin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }
    </style>
    <?php
}
