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
        'status' => 'any' // Get all order statuses
    ]);

    // Get total counts
    $total_orders = wc_orders_count('any');
    $processing_count = wc_orders_count('processing');
    $completed_count = wc_orders_count('completed');
    
    // Calculate pagination
    $total_pages = ceil($total_orders / $per_page);

    ?>
    <div class="wrap checkoutguard-wrap">
        <!-- Modern Page Header -->
        <div class="cg-page-header-modern">
            <div class="cg-header-content">
                <div class="cg-header-icon">
                    <span class="dashicons dashicons-media-text"></span>
                </div>
                <div class="cg-header-text">
                    <h1><?php esc_html_e('Invoice & Shipping Slip', 'checkoutguard'); ?></h1>
                    <p class="cg-header-subtitle">
                        <?php esc_html_e('Generate professional invoices and shipping slips for your orders', 'checkoutguard'); ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- Modern Stat Cards -->
        <div class="cg-stat-cards-modern">
            <div class="cg-stat-card-modern cg-stat-primary">
                <div class="cg-stat-icon">
                    <span class="dashicons dashicons-list-view"></span>
                </div>
                <div class="cg-stat-content">
                    <p class="cg-stat-label"><?php esc_html_e('Total Orders', 'checkoutguard'); ?></p>
                    <p class="cg-stat-number"><?php echo esc_html($total_orders); ?></p>
                    <p class="cg-stat-desc"><?php esc_html_e('All Orders', 'checkoutguard'); ?></p>
                </div>
            </div>

            <div class="cg-stat-card-modern cg-stat-info">
                <div class="cg-stat-icon">
                    <span class="dashicons dashicons-clock"></span>
                </div>
                <div class="cg-stat-content">
                    <p class="cg-stat-label"><?php esc_html_e('Processing', 'checkoutguard'); ?></p>
                    <p class="cg-stat-number"><?php echo esc_html($processing_count); ?></p>
                    <p class="cg-stat-desc"><?php esc_html_e('Pending Orders', 'checkoutguard'); ?></p>
                </div>
            </div>

            <div class="cg-stat-card-modern cg-stat-success">
                <div class="cg-stat-icon">
                    <span class="dashicons dashicons-yes-alt"></span>
                </div>
                <div class="cg-stat-content">
                    <p class="cg-stat-label"><?php esc_html_e('Completed', 'checkoutguard'); ?></p>
                    <p class="cg-stat-number"><?php echo esc_html($completed_count); ?></p>
                    <p class="cg-stat-desc"><?php esc_html_e('Fulfilled Orders', 'checkoutguard'); ?></p>
                </div>
            </div>

            <div class="cg-stat-card-modern cg-stat-warning">
                <div class="cg-stat-icon">
                    <span class="dashicons dashicons-printer"></span>
                </div>
                <div class="cg-stat-content">
                    <p class="cg-stat-label"><?php esc_html_e('Quick Actions', 'checkoutguard'); ?></p>
                    <p class="cg-stat-number"><span class="dashicons dashicons-download"></span></p>
                    <p class="cg-stat-desc"><?php esc_html_e('Print & Export', 'checkoutguard'); ?></p>
                </div>
            </div>
        </div>

        <!-- Orders Table Card -->
        <div class="cg-card">
            <div class="cg-card-header">
                <h2>
                    <span class="dashicons dashicons-list-view"></span>
                    <?php 
                    printf(
                        esc_html__('All Orders (%s total)', 'checkoutguard'),
                        number_format_i18n($total_orders)
                    );
                    ?>
                </h2>
                <div class="cg-invoice-actions">
                    <button class="cg-btn cg-btn-secondary cg-btn-small" id="cg-bulk-invoice">
                        <span class="dashicons dashicons-media-document"></span>
                        <?php esc_html_e('Bulk Invoice', 'checkoutguard'); ?>
                    </button>
                    <button class="cg-btn cg-btn-secondary cg-btn-small" id="cg-bulk-shipping">
                        <span class="dashicons dashicons-location"></span>
                        <?php esc_html_e('Bulk Shipping Slip', 'checkoutguard'); ?>
                    </button>
                </div>
            </div>
            
            <?php checkoutguard_render_orders_table($orders); ?>
            
            <?php if ($total_pages > 1): ?>
                <div class="cg-pagination">
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
    <div id="cg-print-area" style="display: none;"></div>
    <?php
}

/**
 * Renders the orders table
 */
function checkoutguard_render_orders_table($orders)
{
    ?>
    <div class="cg-invoice-table-wrapper">
        <table class="cg-data-table cg-invoice-table">
            <thead>
                <tr>
                    <th style="width: 40px;">
                        <input type="checkbox" id="cg-select-all-orders">
                    </th>
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
                        <td colspan="7" class="empty-table-message">
                            <div class="cg-empty-state-dashboard">
                                <span class="dashicons dashicons-cart"></span>
                                <p><?php esc_html_e('No orders found', 'checkoutguard'); ?></p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($orders as $order): ?>
                        <tr data-order-id="<?php echo esc_attr($order->get_id()); ?>">
                            <td>
                                <input type="checkbox" class="cg-order-checkbox" value="<?php echo esc_attr($order->get_id()); ?>">
                            </td>
                            <td>
                                <strong>
                                    <a href="<?php echo esc_url($order->get_edit_order_url()); ?>" target="_blank">
                                        #<?php echo esc_html($order->get_order_number()); ?>
                                    </a>
                                </strong>
                            </td>
                            <td>
                                <div class="cg-customer-info">
                                    <div class="cg-customer-avatar-modern">
                                        <img src="<?php echo esc_url(get_avatar_url($order->get_billing_email(), ['size' => 40])); ?>" alt="Avatar">
                                    </div>
                                    <div class="cg-customer-info-modern">
                                        <span class="cg-customer-name-modern">
                                            <?php echo esc_html($order->get_formatted_billing_full_name()); ?>
                                        </span>
                                        <span class="cg-customer-email-modern">
                                            <?php echo esc_html($order->get_billing_email()); ?>
                                        </span>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="cg-date-text">
                                    <?php echo esc_html($order->get_date_created()->date_i18n(get_option('date_format'))); ?>
                                </span>
                            </td>
                            <td>
                                <?php 
                                $status = $order->get_status();
                                $status_class = 'cg-status-' . $status;
                                ?>
                                <span class="cg-status-badge <?php echo esc_attr($status_class); ?>">
                                    <?php echo esc_html(wc_get_order_status_name($status)); ?>
                                </span>
                            </td>
                            <td>
                                <strong><?php echo wp_kses_post($order->get_formatted_order_total()); ?></strong>
                            </td>
                            <td class="cg-actions">
                                <button class="cg-btn cg-btn-small cg-print-invoice" 
                                        data-order-id="<?php echo esc_attr($order->get_id()); ?>"
                                        title="<?php esc_attr_e('Print Invoice', 'checkoutguard'); ?>">
                                    <span class="dashicons dashicons-media-document"></span>
                                    <?php esc_html_e('Invoice', 'checkoutguard'); ?>
                                </button>
                                <button class="cg-btn cg-btn-small cg-btn-secondary cg-print-shipping" 
                                        data-order-id="<?php echo esc_attr($order->get_id()); ?>"
                                        title="<?php esc_attr_e('Print Shipping Slip', 'checkoutguard'); ?>">
                                    <span class="dashicons dashicons-location"></span>
                                    <?php esc_html_e('Shipping', 'checkoutguard'); ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
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
    <div class="cg-invoice-document">
        <div class="cg-invoice-header">
            <div class="cg-invoice-logo">
                <?php if (has_custom_logo()): ?>
                    <?php the_custom_logo(); ?>
                <?php else: ?>
                    <h1><?php echo esc_html($store_name); ?></h1>
                <?php endif; ?>
            </div>
            <div class="cg-invoice-title">
                <h2><?php esc_html_e('INVOICE', 'checkoutguard'); ?></h2>
                <p><?php esc_html_e('Order', 'checkoutguard'); ?> #<?php echo esc_html($order->get_order_number()); ?></p>
            </div>
        </div>

        <div class="cg-invoice-addresses">
            <div class="cg-invoice-from">
                <h3><?php esc_html_e('From:', 'checkoutguard'); ?></h3>
                <p><strong><?php echo esc_html($store_name); ?></strong></p>
                <?php if ($store_address): ?>
                    <p><?php echo esc_html($store_address); ?></p>
                <?php endif; ?>
                <?php if ($store_city || $store_postcode): ?>
                    <p><?php echo esc_html($store_city . ' ' . $store_postcode); ?></p>
                <?php endif; ?>
            </div>
            <div class="cg-invoice-to">
                <h3><?php esc_html_e('Bill To:', 'checkoutguard'); ?></h3>
                <p><strong><?php echo esc_html($order->get_formatted_billing_full_name()); ?></strong></p>
                <p><?php echo esc_html($order->get_billing_address_1()); ?></p>
                <?php if ($order->get_billing_address_2()): ?>
                    <p><?php echo esc_html($order->get_billing_address_2()); ?></p>
                <?php endif; ?>
                <p><?php echo esc_html($order->get_billing_city() . ', ' . $order->get_billing_postcode()); ?></p>
                <p><?php echo esc_html($order->get_billing_phone()); ?></p>
                <p><?php echo esc_html($order->get_billing_email()); ?></p>
            </div>
            <div class="cg-invoice-details">
                <table>
                    <tr>
                        <th><?php esc_html_e('Date:', 'checkoutguard'); ?></th>
                        <td><?php echo esc_html($order->get_date_created()->date_i18n(get_option('date_format'))); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Order #:', 'checkoutguard'); ?></th>
                        <td><?php echo esc_html($order->get_order_number()); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Status:', 'checkoutguard'); ?></th>
                        <td><?php echo esc_html(wc_get_order_status_name($order->get_status())); ?></td>
                    </tr>
                </table>
            </div>
        </div>

        <div class="cg-invoice-items">
            <table>
                <thead>
                    <tr>
                        <th><?php esc_html_e('Item', 'checkoutguard'); ?></th>
                        <th><?php esc_html_e('Quantity', 'checkoutguard'); ?></th>
                        <th><?php esc_html_e('Price', 'checkoutguard'); ?></th>
                        <th><?php esc_html_e('Total', 'checkoutguard'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($order->get_items() as $item): ?>
                        <tr>
                            <td><?php echo esc_html($item->get_name()); ?></td>
                            <td><?php echo esc_html($item->get_quantity()); ?></td>
                            <td><?php echo wp_kses_post(wc_price($item->get_total() / $item->get_quantity())); ?></td>
                            <td><?php echo wp_kses_post(wc_price($item->get_total())); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3"><strong><?php esc_html_e('Subtotal:', 'checkoutguard'); ?></strong></td>
                        <td><?php echo wp_kses_post(wc_price($order->get_subtotal())); ?></td>
                    </tr>
                    <?php if ($order->get_total_tax() > 0): ?>
                        <tr>
                            <td colspan="3"><strong><?php esc_html_e('Tax:', 'checkoutguard'); ?></strong></td>
                            <td><?php echo wp_kses_post(wc_price($order->get_total_tax())); ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php if ($order->get_shipping_total() > 0): ?>
                        <tr>
                            <td colspan="3"><strong><?php esc_html_e('Shipping:', 'checkoutguard'); ?></strong></td>
                            <td><?php echo wp_kses_post(wc_price($order->get_shipping_total())); ?></td>
                        </tr>
                    <?php endif; ?>
                    <tr class="cg-invoice-total">
                        <td colspan="3"><strong><?php esc_html_e('Total:', 'checkoutguard'); ?></strong></td>
                        <td><strong><?php echo wp_kses_post($order->get_formatted_order_total()); ?></strong></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="cg-invoice-footer">
            <p><?php esc_html_e('Thank you for your business!', 'checkoutguard'); ?></p>
        </div>
        
        <div class="cg-invoice-branding">
            <p><?php esc_html_e('Powered by', 'checkoutguard'); ?> <span class="cg-brand-name"><?php esc_html_e('Coder Zone BD', 'checkoutguard'); ?></span></p>
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
    <div class="cg-shipping-slip-document">
        <div class="cg-shipping-header">
            <h2><?php esc_html_e('SHIPPING SLIP', 'checkoutguard'); ?></h2>
            <p><?php esc_html_e('Order', 'checkoutguard'); ?> #<?php echo esc_html($order->get_order_number()); ?></p>
            <p><?php echo esc_html($order->get_date_created()->date_i18n(get_option('date_format'))); ?></p>
        </div>

        <div class="cg-shipping-addresses">
            <div class="cg-shipping-from">
                <h3><?php esc_html_e('FROM:', 'checkoutguard'); ?></h3>
                <p><strong><?php echo esc_html($store_name); ?></strong></p>
                <p><?php echo esc_html(get_option('woocommerce_store_address')); ?></p>
                <p><?php echo esc_html(get_option('woocommerce_store_city') . ', ' . get_option('woocommerce_store_postcode')); ?></p>
            </div>
            <div class="cg-shipping-to">
                <h3><?php esc_html_e('SHIP TO:', 'checkoutguard'); ?></h3>
                <p><strong><?php echo esc_html($order->get_formatted_shipping_full_name()); ?></strong></p>
                <p><?php echo esc_html($order->get_shipping_address_1()); ?></p>
                <?php if ($order->get_shipping_address_2()): ?>
                    <p><?php echo esc_html($order->get_shipping_address_2()); ?></p>
                <?php endif; ?>
                <p><?php echo esc_html($order->get_shipping_city() . ', ' . $order->get_shipping_postcode()); ?></p>
                <p><?php esc_html_e('Phone:', 'checkoutguard'); ?> <?php echo esc_html($order->get_billing_phone()); ?></p>
            </div>
        </div>

        <div class="cg-shipping-items">
            <table>
                <thead>
                    <tr>
                        <th><?php esc_html_e('Item', 'checkoutguard'); ?></th>
                        <th><?php esc_html_e('SKU', 'checkoutguard'); ?></th>
                        <th><?php esc_html_e('Quantity', 'checkoutguard'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($order->get_items() as $item): ?>
                        <?php $product = $item->get_product(); ?>
                        <tr>
                            <td><?php echo esc_html($item->get_name()); ?></td>
                            <td><?php echo $product ? esc_html($product->get_sku()) : '-'; ?></td>
                            <td><?php echo esc_html($item->get_quantity()); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="cg-shipping-total">
            <div class="cg-total-row">
                <span class="cg-total-label"><?php esc_html_e('Order Total:', 'checkoutguard'); ?></span>
                <span class="cg-total-amount"><?php echo wp_kses_post($order->get_formatted_order_total()); ?></span>
            </div>
        </div>

        <div class="cg-shipping-notes">
            <?php if ($order->get_customer_note()): ?>
                <h4><?php esc_html_e('Customer Notes:', 'checkoutguard'); ?></h4>
                <p><?php echo esc_html($order->get_customer_note()); ?></p>
            <?php endif; ?>
        </div>

        <div class="cg-shipping-footer">
            <div class="cg-shipping-signature">
                <p><?php esc_html_e('Received by:', 'checkoutguard'); ?></p>
                <div class="cg-signature-line">_________________________</div>
                <p><?php esc_html_e('Date:', 'checkoutguard'); ?> _____________</p>
            </div>
        </div>
        
        <div class="cg-invoice-branding">
            <p><?php esc_html_e('Powered by', 'checkoutguard'); ?> <span class="cg-brand-name"><?php esc_html_e('Coder Zone BD', 'checkoutguard'); ?></span></p>
        </div>
    </div>
    <?php
}
