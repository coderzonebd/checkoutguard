<?php
/**
 * Invoice & Shipping Slip Page
 * 
 * Generate and print invoices and shipping slips for WooCommerce orders
 * 
 * @package CheckoutGuard
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renders the Invoice & Shipping Slip page.
 */
function checkoutguard_render_invoice_page()
{
    // Check permissions
    if (!current_user_can('manage_woocommerce')) {
        wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'checkoutguard'));
    }

    // Pagination
    $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $per_page = 50;
    $offset = ($paged - 1) * $per_page;

    // Get all orders (all statuses)
    $orders = wc_get_orders([
        'limit' => $per_page,
        'offset' => $offset,
        'orderby' => 'date',
        'order' => 'DESC',
        'status' => array_keys(wc_get_order_statuses()) // Get all order statuses
    ]);

    // Get total counts
    $total_orders = 0;
    foreach (array_keys(wc_get_order_statuses()) as $status) {
        $total_orders += wc_orders_count(str_replace('wc-', '', $status));
    }
    $processing_count = wc_orders_count('processing');
    $completed_count = wc_orders_count('completed');
    
    // Calculate pagination
    $total_pages = ceil($total_orders / $per_page);

    ?>
    <div class="wrap checkoutguard-dashboard-wrap">
        <!-- Modern Page Header -->
        <h1>
            <span class="dashicons dashicons-media-text" style="font-size: 32px; width: 32px; height: 32px;"></span>
            <?php esc_html_e('Invoice & Shipping Slip', 'checkoutguard'); ?>
        </h1>
        <p>
            <?php esc_html_e('Generate professional invoices and shipping slips for your orders', 'checkoutguard'); ?>
        </p>

        <!-- Modern Stat Cards -->
        <div class="checkoutguard-stat-row">
            <div class="checkoutguard-stat-box stat-primary">
                <h3><?php esc_html_e('Total Orders', 'checkoutguard'); ?></h3>
                <p><?php echo esc_html($total_orders); ?></p>
                <div class="checkoutguard-stat-subtext"><?php esc_html_e('All Orders', 'checkoutguard'); ?></div>
            </div>

            <div class="checkoutguard-stat-box stat-hold">
                <h3><?php esc_html_e('Processing', 'checkoutguard'); ?></h3>
                <p><?php echo esc_html($processing_count); ?></p>
                <div class="checkoutguard-stat-subtext"><?php esc_html_e('Pending Orders', 'checkoutguard'); ?></div>
            </div>

            <div class="checkoutguard-stat-box stat-recovered">
                <h3><?php esc_html_e('Completed', 'checkoutguard'); ?></h3>
                <p><?php echo esc_html($completed_count); ?></p>
                <div class="checkoutguard-stat-subtext"><?php esc_html_e('Fulfilled Orders', 'checkoutguard'); ?></div>
            </div>

            <div class="checkoutguard-stat-box stat-incomplete">
                <h3><?php esc_html_e('Quick Actions', 'checkoutguard'); ?></h3>
                <p><span class="dashicons dashicons-download" style="font-size: 24px; width: 24px; height: 24px;"></span></p>
                <div class="checkoutguard-stat-subtext"><?php esc_html_e('Print & Export', 'checkoutguard'); ?></div>
            </div>
        </div>

        <!-- Orders Table Card -->
        <div class="checkoutguard-table-responsive-wrapper">
            <div style="padding: 20px; border-bottom: 1px solid var(--checkoutguard-card-border); display: flex; justify-content: space-between; align-items: center;">
                <h2 style="margin: 0; font-size: 18px; display: flex; align-items: center; gap: 10px;">
                    <span class="dashicons dashicons-list-view"></span>
                    <?php 
                    printf(
                        esc_html__('All Orders (%s total)', 'checkoutguard'),
                        number_format_i18n($total_orders)
                    );
                    ?>
                </h2>
                <div class="checkoutguard-invoice-actions" style="display: flex; gap: 10px;">
                    <button class="button button-secondary" id="checkoutguard-bulk-invoice">
                        <span class="dashicons dashicons-media-document"></span>
                        <?php esc_html_e('Bulk Invoice', 'checkoutguard'); ?>
                    </button>
                    <button class="button button-secondary" id="checkoutguard-bulk-shipping">
                        <span class="dashicons dashicons-location"></span>
                        <?php esc_html_e('Bulk Shipping Slip', 'checkoutguard'); ?>
                    </button>
                </div>
            </div>
            
            <?php checkoutguard_render_orders_table($orders); ?>
            
            <?php if ($total_pages > 1): ?>
                <div class="checkoutguard-pagination" style="padding: 20px; border-top: 1px solid var(--checkoutguard-card-border); display: flex; justify-content: center;">
                    <?php
                    echo paginate_links([
                        'base' => add_query_arg('paged', '%#%'),
                        'format' => '',
                        'prev_text' => '<span class="dashicons dashicons-arrow-left-alt2"></span> ' . __('Previous', 'checkoutguard'),
                        'next_text' => __('Next', 'checkoutguard') . ' <span class="dashicons dashicons-arrow-right-alt2"></span>',
                        'total' => $total_pages,
                        'current' => $paged,
                        'type' => 'list'
                    ]);
                    ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Print Templates -->
    <div id="checkoutguard-print-area" style="display: none;"></div>
    <?php
}

/**
 * Renders the orders table
 */
function checkoutguard_render_orders_table($orders)
{
    ?>
    <table class="wp-list-table widefat fixed striped table-view-list checkoutguard-table">
        <thead>
            <tr>
                <td id="cb" class="manage-column column-cb check-column">
                    <label class="screen-reader-text" for="checkoutguard-select-all-orders"><?php esc_html_e('Select All', 'checkoutguard'); ?></label>
                    <input id="checkoutguard-select-all-orders" type="checkbox">
                </td>
                <th><?php esc_html_e('Order', 'checkoutguard'); ?></th>
                <th><?php esc_html_e('Customer', 'checkoutguard'); ?></th>
                <th><?php esc_html_e('Date', 'checkoutguard'); ?></th>
                <th><?php esc_html_e('Status', 'checkoutguard'); ?></th>
                <th><?php esc_html_e('Total', 'checkoutguard'); ?></th>
                <th><?php esc_html_e('Actions', 'checkoutguard'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($orders)): ?>
                <tr>
                    <td colspan="7" class="checkoutguard-table-empty-message">
                        <div style="text-align: center; padding: 40px;">
                            <span class="dashicons dashicons-cart" style="font-size: 48px; width: 48px; height: 48px; color: var(--checkoutguard-text-light); margin-bottom: 16px;"></span>
                            <p style="font-size: 16px; color: var(--checkoutguard-text-secondary); margin: 0;"><?php esc_html_e('No orders found', 'checkoutguard'); ?></p>
                        </div>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($orders as $order): ?>
                    <tr data-order-id="<?php echo esc_attr($order->get_id()); ?>">
                        <th scope="row" class="check-column">
                            <input type="checkbox" class="checkoutguard-order-checkbox" name="post[]" value="<?php echo esc_attr($order->get_id()); ?>">
                        </th>
                        <td>
                            <strong>
                                <a href="<?php echo esc_url($order->get_edit_order_url()); ?>" target="_blank" style="color: var(--checkoutguard-primary-color); font-weight: 600;">
                                    #<?php echo esc_html($order->get_order_number()); ?>
                                </a>
                            </strong>
                        </td>
                        <td>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <div style="width: 32px; height: 32px; border-radius: 50%; overflow: hidden; background: var(--checkoutguard-background-color);">
                                    <img src="<?php echo esc_url(get_avatar_url($order->get_billing_email(), ['size' => 32])); ?>" alt="Avatar" style="width: 100%; height: 100%; object-fit: cover;">
                                </div>
                                <div style="display: flex; flex-direction: column;">
                                    <span style="font-weight: 500; color: var(--checkoutguard-text-primary);">
                                        <?php echo esc_html($order->get_formatted_billing_full_name()); ?>
                                    </span>
                                    <span style="font-size: 12px; color: var(--checkoutguard-text-secondary);">
                                        <?php echo esc_html($order->get_billing_email()); ?>
                                    </span>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span style="color: var(--checkoutguard-text-secondary);">
                                <?php echo esc_html($order->get_date_created()->date_i18n(get_option('date_format'))); ?>
                            </span>
                        </td>
                        <td>
                            <?php 
                            $status = $order->get_status();
                            $status_label = wc_get_order_status_name($status);
                            $status_color = 'var(--checkoutguard-text-secondary)';
                            $status_bg = 'var(--checkoutguard-background-color)';
                            
                            switch($status) {
                                case 'completed':
                                    $status_color = 'var(--checkoutguard-success-color)';
                                    $status_bg = 'var(--checkoutguard-success-light)';
                                    break;
                                case 'processing':
                                    $status_color = 'var(--checkoutguard-primary-color)';
                                    $status_bg = 'var(--checkoutguard-primary-light)';
                                    break;
                                case 'on-hold':
                                    $status_color = 'var(--checkoutguard-warning-color)';
                                    $status_bg = 'var(--checkoutguard-warning-light)';
                                    break;
                                case 'cancelled':
                                case 'failed':
                                    $status_color = 'var(--checkoutguard-danger-color)';
                                    $status_bg = 'var(--checkoutguard-danger-light)';
                                    break;
                            }
                            ?>
                            <span style="display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 500; color: <?php echo esc_attr($status_color); ?>; background-color: <?php echo esc_attr($status_bg); ?>;">
                                <?php echo esc_html($status_label); ?>
                            </span>
                        </td>
                        <td>
                            <strong><?php echo wp_kses_post($order->get_formatted_order_total()); ?></strong>
                        </td>
                        <td>
                            <div style="display: flex; gap: 5px;">
                                <button class="button button-small checkoutguard-print-invoice" 
                                        data-order-id="<?php echo esc_attr($order->get_id()); ?>"
                                        title="<?php esc_attr_e('Print Invoice', 'checkoutguard'); ?>">
                                    <span class="dashicons dashicons-media-document" style="margin-top: 3px;"></span>
                                </button>
                                <button class="button button-small checkoutguard-print-shipping" 
                                        data-order-id="<?php echo esc_attr($order->get_id()); ?>"
                                        title="<?php esc_attr_e('Print Shipping Slip', 'checkoutguard'); ?>">
                                    <span class="dashicons dashicons-location" style="margin-top: 3px;"></span>
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    <?php
}

/**
 * AJAX handler to get invoice HTML
 */
function checkoutguard_get_invoice_html()
{
    check_ajax_referer('checkoutguard_invoice_nonce', 'nonce');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'Permission denied']);
    }

    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : 'invoice';

    if (!$order_id) {
        wp_send_json_error(['message' => 'Invalid order ID']);
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error(['message' => 'Order not found']);
    }

    ob_start();
    
    if ($type === 'shipping') {
        checkoutguard_render_shipping_slip_template($order);
    } else {
        checkoutguard_render_invoice_template($order);
    }
    
    $html = ob_get_clean();

    wp_send_json_success(['html' => $html]);
}
add_action('wp_ajax_checkoutguard_get_invoice', 'checkoutguard_get_invoice_html');

/**
 * Render invoice template
 */
function checkoutguard_render_invoice_template($order)
{
    $store_name = get_bloginfo('name');
    $store_address = get_option('woocommerce_store_address');
    $store_city = get_option('woocommerce_store_city');
    $store_postcode = get_option('woocommerce_store_postcode');
    
    ?>
    <div class="checkoutguard-invoice-document">
        <div class="checkoutguard-invoice-header" style="display: flex; justify-content: space-between; margin-bottom: 40px; border-bottom: 2px solid #eee; padding-bottom: 20px;">
            <div class="checkoutguard-invoice-logo">
                <?php if (has_custom_logo()): ?>
                    <?php the_custom_logo(); ?>
                <?php else: ?>
                    <h1 style="margin: 0; color: #333;"><?php echo esc_html($store_name); ?></h1>
                <?php endif; ?>
            </div>
            <div class="checkoutguard-invoice-title" style="text-align: right;">
                <h2 style="margin: 0 0 5px; color: #333;"><?php esc_html_e('INVOICE', 'checkoutguard'); ?></h2>
                <p style="margin: 0; color: #666;"><?php esc_html_e('Order', 'checkoutguard'); ?> #<?php echo esc_html($order->get_order_number()); ?></p>
            </div>
        </div>

        <div class="checkoutguard-invoice-addresses" style="display: flex; justify-content: space-between; margin-bottom: 40px;">
            <div class="checkoutguard-invoice-from" style="flex: 1;">
                <h3 style="margin: 0 0 10px; font-size: 14px; color: #666; text-transform: uppercase;"><?php esc_html_e('From:', 'checkoutguard'); ?></h3>
                <p style="margin: 0 0 5px;"><strong><?php echo esc_html($store_name); ?></strong></p>
                <?php if ($store_address): ?>
                    <p style="margin: 0 0 5px;"><?php echo esc_html($store_address); ?></p>
                <?php endif; ?>
                <?php if ($store_city || $store_postcode): ?>
                    <p style="margin: 0 0 5px;"><?php echo esc_html($store_city . ' ' . $store_postcode); ?></p>
                <?php endif; ?>
            </div>
            <div class="checkoutguard-invoice-to" style="flex: 1;">
                <h3 style="margin: 0 0 10px; font-size: 14px; color: #666; text-transform: uppercase;"><?php esc_html_e('Bill To:', 'checkoutguard'); ?></h3>
                <p style="margin: 0 0 5px;"><strong><?php echo esc_html($order->get_formatted_billing_full_name()); ?></strong></p>
                <p style="margin: 0 0 5px;"><?php echo esc_html($order->get_billing_address_1()); ?></p>
                <?php if ($order->get_billing_address_2()): ?>
                    <p style="margin: 0 0 5px;"><?php echo esc_html($order->get_billing_address_2()); ?></p>
                <?php endif; ?>
                <p style="margin: 0 0 5px;"><?php echo esc_html($order->get_billing_city() . ', ' . $order->get_billing_postcode()); ?></p>
                <p style="margin: 0 0 5px;"><?php echo esc_html($order->get_billing_phone()); ?></p>
                <p style="margin: 0 0 5px;"><?php echo esc_html($order->get_billing_email()); ?></p>
            </div>
            <div class="checkoutguard-invoice-details" style="flex: 0 0 200px;">
                <table style="width: 100%; border-collapse: collapse;">
                    <tr>
                        <th style="text-align: left; padding: 5px 0; color: #666;"><?php esc_html_e('Date:', 'checkoutguard'); ?></th>
                        <td style="text-align: right; padding: 5px 0;"><?php echo esc_html($order->get_date_created()->date_i18n(get_option('date_format'))); ?></td>
                    </tr>
                    <tr>
                        <th style="text-align: left; padding: 5px 0; color: #666;"><?php esc_html_e('Order #:', 'checkoutguard'); ?></th>
                        <td style="text-align: right; padding: 5px 0;"><?php echo esc_html($order->get_order_number()); ?></td>
                    </tr>
                    <tr>
                        <th style="text-align: left; padding: 5px 0; color: #666;"><?php esc_html_e('Status:', 'checkoutguard'); ?></th>
                        <td style="text-align: right; padding: 5px 0;"><?php echo esc_html(wc_get_order_status_name($order->get_status())); ?></td>
                    </tr>
                </table>
            </div>
        </div>

        <div class="checkoutguard-invoice-items" style="margin-bottom: 40px;">
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f9f9f9; border-bottom: 2px solid #eee;">
                        <th style="text-align: left; padding: 10px;"><?php esc_html_e('Item', 'checkoutguard'); ?></th>
                        <th style="text-align: center; padding: 10px;"><?php esc_html_e('Quantity', 'checkoutguard'); ?></th>
                        <th style="text-align: right; padding: 10px;"><?php esc_html_e('Price', 'checkoutguard'); ?></th>
                        <th style="text-align: right; padding: 10px;"><?php esc_html_e('Total', 'checkoutguard'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($order->get_items() as $item): ?>
                        <tr style="border-bottom: 1px solid #eee;">
                            <td style="padding: 10px;"><?php echo esc_html($item->get_name()); ?></td>
                            <td style="text-align: center; padding: 10px;"><?php echo esc_html($item->get_quantity()); ?></td>
                            <td style="text-align: right; padding: 10px;"><?php echo wp_kses_post(wc_price($item->get_total() / $item->get_quantity())); ?></td>
                            <td style="text-align: right; padding: 10px;"><?php echo wp_kses_post(wc_price($item->get_total())); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3" style="text-align: right; padding: 10px; border-top: 2px solid #eee;"><strong><?php esc_html_e('Subtotal:', 'checkoutguard'); ?></strong></td>
                        <td style="text-align: right; padding: 10px; border-top: 2px solid #eee;"><?php echo wp_kses_post(wc_price($order->get_subtotal())); ?></td>
                    </tr>
                    <?php if ($order->get_total_tax() > 0): ?>
                        <tr>
                            <td colspan="3" style="text-align: right; padding: 10px;"><strong><?php esc_html_e('Tax:', 'checkoutguard'); ?></strong></td>
                            <td style="text-align: right; padding: 10px;"><?php echo wp_kses_post(wc_price($order->get_total_tax())); ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php if ($order->get_shipping_total() > 0): ?>
                        <tr>
                            <td colspan="3" style="text-align: right; padding: 10px;"><strong><?php esc_html_e('Shipping:', 'checkoutguard'); ?></strong></td>
                            <td style="text-align: right; padding: 10px;"><?php echo wp_kses_post(wc_price($order->get_shipping_total())); ?></td>
                        </tr>
                    <?php endif; ?>
                    <tr class="checkoutguard-invoice-total" style="background: #f9f9f9;">
                        <td colspan="3" style="text-align: right; padding: 15px; font-size: 16px;"><strong><?php esc_html_e('Total:', 'checkoutguard'); ?></strong></td>
                        <td style="text-align: right; padding: 15px; font-size: 16px;"><strong><?php echo wp_kses_post($order->get_formatted_order_total()); ?></strong></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="checkoutguard-invoice-footer" style="text-align: center; margin-top: 60px; color: #666;">
            <p><?php esc_html_e('Thank you for your business!', 'checkoutguard'); ?></p>
        </div>
        
        <div class="checkoutguard-invoice-branding" style="text-align: center; margin-top: 20px; font-size: 12px; color: #999;">
            <p><?php esc_html_e('Powered by', 'checkoutguard'); ?> <a href="https://coderzonebd.com/" target="_blank" class="checkoutguard-brand-name" style="font-weight: bold; color: inherit; text-decoration: none;"><?php esc_html_e('Coder Zone BD', 'checkoutguard'); ?></a></p>
        </div>
    </div>
    <?php
}

/**
 * Render shipping slip template
 */
function checkoutguard_render_shipping_slip_template($order)
{
    $store_name = get_bloginfo('name');
    
    ?>
    <div class="checkoutguard-shipping-slip-document">
        <div class="checkoutguard-shipping-header" style="text-align: center; margin-bottom: 40px; border-bottom: 2px solid #eee; padding-bottom: 20px;">
            <h2 style="margin: 0 0 10px; color: #333;"><?php esc_html_e('SHIPPING SLIP', 'checkoutguard'); ?></h2>
            <p style="margin: 0; color: #666;"><?php esc_html_e('Order', 'checkoutguard'); ?> #<?php echo esc_html($order->get_order_number()); ?></p>
            <p style="margin: 0; color: #666;"><?php echo esc_html($order->get_date_created()->date_i18n(get_option('date_format'))); ?></p>
        </div>

        <div class="checkoutguard-shipping-addresses" style="display: flex; justify-content: space-between; margin-bottom: 40px;">
            <div class="checkoutguard-shipping-from" style="flex: 1;">
                <h3 style="margin: 0 0 10px; font-size: 14px; color: #666; text-transform: uppercase;"><?php esc_html_e('FROM:', 'checkoutguard'); ?></h3>
                <p style="margin: 0 0 5px;"><strong><?php echo esc_html($store_name); ?></strong></p>
                <p style="margin: 0 0 5px;"><?php echo esc_html(get_option('woocommerce_store_address')); ?></p>
                <p style="margin: 0 0 5px;"><?php echo esc_html(get_option('woocommerce_store_city') . ', ' . get_option('woocommerce_store_postcode')); ?></p>
            </div>
            <div class="checkoutguard-shipping-to" style="flex: 1;">
                <h3 style="margin: 0 0 10px; font-size: 14px; color: #666; text-transform: uppercase;"><?php esc_html_e('SHIP TO:', 'checkoutguard'); ?></h3>
                <p style="margin: 0 0 5px;"><strong><?php echo esc_html($order->get_formatted_shipping_full_name()); ?></strong></p>
                <p style="margin: 0 0 5px;"><?php echo esc_html($order->get_shipping_address_1()); ?></p>
                <?php if ($order->get_shipping_address_2()): ?>
                    <p style="margin: 0 0 5px;"><?php echo esc_html($order->get_shipping_address_2()); ?></p>
                <?php endif; ?>
                <p style="margin: 0 0 5px;"><?php echo esc_html($order->get_shipping_city() . ', ' . $order->get_shipping_postcode()); ?></p>
                <p style="margin: 0 0 5px;"><?php esc_html_e('Phone:', 'checkoutguard'); ?> <?php echo esc_html($order->get_billing_phone()); ?></p>
            </div>
        </div>

        <div class="checkoutguard-shipping-items" style="margin-bottom: 40px;">
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f9f9f9; border-bottom: 2px solid #eee;">
                        <th style="text-align: left; padding: 10px;"><?php esc_html_e('Item', 'checkoutguard'); ?></th>
                        <th style="text-align: left; padding: 10px;"><?php esc_html_e('SKU', 'checkoutguard'); ?></th>
                        <th style="text-align: center; padding: 10px;"><?php esc_html_e('Quantity', 'checkoutguard'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($order->get_items() as $item): ?>
                        <?php $product = $item->get_product(); ?>
                        <tr style="border-bottom: 1px solid #eee;">
                            <td style="padding: 10px;"><?php echo esc_html($item->get_name()); ?></td>
                            <td style="padding: 10px;"><?php echo $product ? esc_html($product->get_sku()) : '-'; ?></td>
                            <td style="text-align: center; padding: 10px;"><?php echo esc_html($item->get_quantity()); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="checkoutguard-shipping-total" style="text-align: right; margin-bottom: 40px; padding: 10px; background: #f9f9f9;">
            <div class="checkoutguard-total-row">
                <span class="checkoutguard-total-label" style="font-weight: bold; margin-right: 10px;"><?php esc_html_e('Order Total:', 'checkoutguard'); ?></span>
                <span class="checkoutguard-total-amount" style="font-weight: bold;"><?php echo wp_kses_post($order->get_formatted_order_total()); ?></span>
            </div>
        </div>

        <div class="checkoutguard-shipping-notes" style="margin-bottom: 40px;">
            <?php if ($order->get_customer_note()): ?>
                <h4 style="margin: 0 0 10px;"><?php esc_html_e('Customer Notes:', 'checkoutguard'); ?></h4>
                <p style="padding: 15px; background: #f9f9f9; border-left: 4px solid #ccc;"><?php echo esc_html($order->get_customer_note()); ?></p>
            <?php endif; ?>
        </div>

        <div class="checkoutguard-shipping-footer" style="margin-top: 60px;">
            <div class="checkoutguard-shipping-signature" style="display: flex; justify-content: space-between;">
                <div style="text-align: center;">
                    <p style="margin-bottom: 40px;"><?php esc_html_e('Received by:', 'checkoutguard'); ?></p>
                    <div class="checkoutguard-signature-line" style="border-top: 1px solid #000; width: 200px;"></div>
                </div>
                <div style="text-align: center;">
                    <p style="margin-bottom: 40px;"><?php esc_html_e('Date:', 'checkoutguard'); ?></p>
                    <div class="checkoutguard-signature-line" style="border-top: 1px solid #000; width: 200px;"></div>
                </div>
            </div>
        </div>
        
        <div class="checkoutguard-invoice-branding" style="text-align: center; margin-top: 40px; font-size: 12px; color: #999;">
            <p><?php esc_html_e('Powered by', 'checkoutguard'); ?> <a href="https://coderzonebd.com/" target="_blank" class="checkoutguard-brand-name" style="font-weight: bold; color: inherit; text-decoration: none;"><?php esc_html_e('Coder Zone BD', 'checkoutguard'); ?></a></p>
        </div>
    </div>
    <?php
}
