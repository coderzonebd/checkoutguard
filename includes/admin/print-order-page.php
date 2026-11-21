<?php
// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * ====================================================================
 * PRINT / LABEL PAGE
 * ====================================================================
 * An interactive, browser-print admin page offering:
 *  - A4 Invoice (Free) — rich print-styled invoice, same data as the
 *    downloadable PDF invoice but with barcode/QR and live zoom preview.
 *  - Courier shipping labels in 2x3in, 3x3in, and A4 sizes (Pro) — with
 *    barcode, QR code, COD box, and courier-brand badge, for handoff to
 *    the courier / warehouse packing station.
 * Uses client-side JsBarcode + QRious (bundled locally) and window.print()
 * with @media print rules, rather than server-side PDF rendering — this
 * gives live zoom/preview and crisp thermal-printer output that a
 * rasterized PDF can't match.
 */

/**
 * Brand-ish accent colors for each supported courier's badge.
 *
 * @param string $courier
 * @return string Hex color.
 */
function cg_get_courier_badge_color($courier)
{
    $colors = [
        'pathao'    => '#ef4444',
        'steadfast' => '#22c55e',
        'redx'      => '#f97316',
        'paperfly'  => '#3b82f6',
        'carrybee'  => '#8b5cf6',
    ];
    return $colors[strtolower((string) $courier)] ?? '#64748b';
}

/**
 * Builds the (nonce-protected) URL to the print/label page for an order.
 *
 * @param int $order_id
 * @return string
 */
function cg_get_print_order_url($order_id)
{
    return add_query_arg([
        'page'     => 'cg-print-order',
        'order_id' => $order_id,
        'nonce'    => wp_create_nonce('cg_print_order_' . $order_id),
    ], admin_url('admin.php'));
}

/**
 * Registers the print page as a real (nonce-protected, capability-checked)
 * admin page, then immediately hides it from the visible submenu — it's
 * only ever reached via a direct "Print" link/button, not site navigation.
 */
function cg_register_print_order_page()
{
    // A null parent registers the page (with its own capability check) as
    // reachable by direct URL without it appearing under any menu item.
    // Using a real parent + remove_submenu_page() looks equivalent but
    // isn't: WordPress resolves the page's hook/capability by searching the
    // $submenu array, so removing the entry there breaks that lookup and
    // the whole page 403s before our own callback ever runs.
    $hook = add_submenu_page(
        null,
        __('Print Order', 'checkoutguard'),
        __('Print Order', 'checkoutguard'),
        'manage_woocommerce',
        'cg-print-order',
        'cg_render_print_order_page'
    );
    add_action('load-' . $hook, 'cg_enqueue_print_order_assets');
}
if (cg_invoices_enabled()) {
    add_action('admin_menu', 'cg_register_print_order_page', 20);
}

/**
 * Enqueues the barcode/QR libraries only on the print page itself.
 */
function cg_enqueue_print_order_assets()
{
    wp_enqueue_script('cg-jsbarcode', CG_PLUGIN_URL . 'assets/js/vendor/jsbarcode.min.js', [], CG_VERSION, true);
    wp_enqueue_script('cg-qrious', CG_PLUGIN_URL . 'assets/js/vendor/qrious.min.js', [], CG_VERSION, true);
}

/**
 * AJAX handler: saves the manually-assigned courier + tracking number for
 * an order (CheckoutGuard doesn't do live courier API booking — this is a
 * lightweight manual assignment so the print label can show the right
 * courier badge and barcode).
 */
function cg_handle_save_order_courier_ajax()
{
    check_ajax_referer('cg_print_order_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => __('Permission denied.', 'checkoutguard')]);
        return;
    }

    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    $order = $order_id ? wc_get_order($order_id) : false;
    if (!$order) {
        wp_send_json_error(['message' => __('Order not found.', 'checkoutguard')]);
        return;
    }

    $courier = isset($_POST['courier']) ? sanitize_key($_POST['courier']) : '';
    if ($courier !== '' && !array_key_exists($courier, cg_get_supported_couriers())) {
        wp_send_json_error(['message' => __('Invalid courier.', 'checkoutguard')]);
        return;
    }
    $tracking_number = isset($_POST['tracking_number']) ? sanitize_text_field(wp_unslash($_POST['tracking_number'])) : '';

    $order->update_meta_data('_cg_courier', $courier);
    $order->update_meta_data('_cg_tracking_number', $tracking_number);
    $order->save();

    wp_send_json_success(['message' => __('Courier info saved.', 'checkoutguard')]);
}
if (cg_invoices_enabled()) {
    add_action('wp_ajax_cg_save_order_courier', 'cg_handle_save_order_courier_ajax');
}

/**
 * Renders the interactive Print/Label admin page for a single order.
 */
function cg_render_print_order_page()
{
    if (!current_user_can('manage_woocommerce')) {
        wp_die(__('You do not have permission to access this page.', 'checkoutguard'));
    }

    $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
    if (!$order_id || !isset($_GET['nonce']) || !wp_verify_nonce(sanitize_key($_GET['nonce']), 'cg_print_order_' . $order_id)) {
        wp_die(__('Invalid or expired link.', 'checkoutguard'));
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        wp_die(__('Order not found.', 'checkoutguard'));
    }

    $is_pro = cg_is_pro_plan();
    $settings = cg_get_invoice_settings();
    $invoice_number = cg_get_or_assign_invoice_number($order) ?: ('#' . $order->get_order_number());

    $logo_url = $settings['company_logo_id'] ? wp_get_attachment_image_url($settings['company_logo_id'], 'medium') : '';
    $company_name = $settings['company_name'] ?: get_bloginfo('name');

    $courier = $order->get_meta('_cg_courier', true);
    $tracking_number = $order->get_meta('_cg_tracking_number', true);
    $courier_label = $courier ? (cg_get_supported_couriers()[$courier]['name'] ?? ucfirst($courier)) : '';
    $courier_color = cg_get_courier_badge_color($courier);

    $city = $order->get_shipping_city() ?: $order->get_billing_city();
    $state = $order->get_shipping_state() ?: $order->get_billing_state();
    $is_dhaka = (stripos($city, 'dhaka') !== false) || (stripos($state, 'dhaka') !== false);
    $sort_type = $is_dhaka ? 'ISD' : 'OSD';

    $barcode_value = $tracking_number ?: $order->get_order_number();
    $barcode_text = $tracking_number ? ($courier_label ?: 'Tracking') . ': ' . $tracking_number : (string) $order->get_order_number();

    $customer_name = trim($order->get_formatted_shipping_full_name()) ?: trim($order->get_formatted_billing_full_name());
    $customer_phone = $order->get_shipping_phone() ?: $order->get_billing_phone();
    $address_1 = $order->get_shipping_address_1() ?: $order->get_billing_address_1();

    // Rich text payload for the label QR code.
    $qr_label_lines = [
        'Order ID: ' . $order->get_order_number(),
        'Name: ' . $customer_name,
        'Phone: ' . $customer_phone,
        'Address: ' . trim($address_1 . ', ' . $city . ', ' . $state),
        'Amount to Collect: ' . number_format((float) $order->get_total()) . ' ' . get_woocommerce_currency(),
        'Created: ' . ($order->get_date_created() ? $order->get_date_created()->date('d/m/Y h:i:s A') : ''),
    ];
    if ($tracking_number) {
        $qr_label_lines[] = '';
        $qr_label_lines[] = ($courier_label ?: 'Courier') . ' ID: ' . $tracking_number;
    }
    $qr_label_text = implode("\n", $qr_label_lines);

    // Rich text payload for the invoice QR code.
    $qr_invoice_lines = [
        'Order ID: ' . $order->get_order_number(),
        'Date: ' . ($order->get_date_created() ? $order->get_date_created()->date('d/m/Y h:i:s A') : ''),
        'Customer: ' . $customer_name,
        'Phone: ' . $customer_phone,
        'Address: ' . trim($address_1 . ', ' . $city . ', ' . $state),
        '',
        'ITEMS:',
    ];
    $i = 1;
    foreach ($order->get_items() as $item) {
        $qr_invoice_lines[] = $i . '. ' . $item->get_name() . ' x ' . $item->get_quantity() . ' = ' . number_format((float) $item->get_total()) . ' ' . get_woocommerce_currency();
        $i++;
    }
    $qr_invoice_lines[] = '';
    $qr_invoice_lines[] = 'Subtotal: ' . number_format((float) $order->get_subtotal()) . ' ' . get_woocommerce_currency();
    $qr_invoice_lines[] = 'Shipping: ' . number_format((float) $order->get_shipping_total()) . ' ' . get_woocommerce_currency();
    $qr_invoice_lines[] = 'Total: ' . number_format((float) $order->get_total()) . ' ' . get_woocommerce_currency();
    $qr_invoice_lines[] = 'Payment: ' . $order->get_payment_method_title();
    if ($tracking_number) {
        $qr_invoice_lines[] = ($courier_label ?: 'Courier') . ' ID: ' . $tracking_number;
    }
    $qr_invoice_text = implode("\n", $qr_invoice_lines);

    ?>
    <div class="wrap cg-print-order-wrap">
        <?php cg_render_print_order_styles(); ?>

        <div class="cg-page-header no-print">
            <h1><?php printf(esc_html__('Print — Order #%s', 'checkoutguard'), esc_html($order->get_order_number())); ?></h1>
        </div>

        <div class="container-fluid py-3">
            <div class="cg-print-controls-bar">
                <div class="cg-print-controls-left">
                    <a href="<?php echo esc_url($order->get_edit_order_url()); ?>" class="button">&larr; <?php esc_html_e('Back to Order', 'checkoutguard'); ?></a>
                    <div class="cg-btn-group">
                        <button type="button" class="button button-primary" id="cg-btn-toggle-invoice" onclick="cgToggleMode('invoice')"><?php esc_html_e('Invoice', 'checkoutguard'); ?></button>
                        <button type="button" class="button" id="cg-btn-toggle-label" onclick="cgToggleMode('label')"><?php esc_html_e('Print Label', 'checkoutguard'); ?><?php if (!$is_pro): ?> <span class="cg-pro-badge">PRO</span><?php endif; ?></button>
                    </div>
                </div>
                <div class="cg-print-controls-right">
                    <div class="cg-btn-group cg-label-size-selector" style="display:none;">
                        <button type="button" class="button button-primary" id="cg-btn-size-2x3" onclick="cgSelectSize('2x3')">2 x 3 in</button>
                        <button type="button" class="button" id="cg-btn-size-3x3" onclick="cgSelectSize('3x3')">3 x 3 in</button>
                        <button type="button" class="button" id="cg-btn-size-a4" onclick="cgSelectSize('a4')">A4</button>
                    </div>
                    <button type="button" class="button button-primary" id="cg-btn-trigger-print" onclick="window.print()"><?php esc_html_e('Print Invoice', 'checkoutguard'); ?></button>
                </div>
            </div>

            <div class="cg-courier-assign-bar">
                <label><?php esc_html_e('Courier:', 'checkoutguard'); ?>
                    <select id="cg-courier-select">
                        <option value=""><?php esc_html_e('— Not assigned —', 'checkoutguard'); ?></option>
                        <?php foreach (cg_get_supported_couriers() as $key => $c): ?>
                            <option value="<?php echo esc_attr($key); ?>" <?php selected($courier, $key); ?>><?php echo esc_html($c['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label><?php esc_html_e('Tracking / Consignment ID:', 'checkoutguard'); ?>
                    <input type="text" id="cg-tracking-input" value="<?php echo esc_attr($tracking_number); ?>" placeholder="<?php esc_attr_e('Optional', 'checkoutguard'); ?>">
                </label>
                <button type="button" class="button button-small" id="cg-save-courier-btn"><?php esc_html_e('Save', 'checkoutguard'); ?></button>
                <span class="spinner"></span>
            </div>

            <div class="cg-print-preview-area" id="cg-previewArea">
                <div class="cg-zoom-controls no-print">
                    <button type="button" class="button button-small" onclick="cgZoom(0.1)">+</button>
                    <button type="button" class="button button-small" onclick="cgZoom(-0.1)">-</button>
                    <button type="button" class="button button-small" onclick="cgResetZoom()">100%</button>
                </div>

                <div class="cg-zoom-wrapper">
                    <div id="cg-label-mode-container" class="cg-mode-container" style="display:none;">
                        <?php cg_render_print_labels($order, $is_pro, $invoice_number, $company_name, $logo_url, $settings, $courier, $courier_label, $courier_color, $sort_type, $customer_name, $customer_phone, $address_1, $city, $state, $barcode_text); ?>
                    </div>
                    <div id="cg-invoice-mode-container" class="cg-mode-container">
                        <?php cg_render_print_invoice($order, $invoice_number, $company_name, $logo_url, $settings, $courier, $courier_label, $courier_color); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        var cgOrderNumber = <?php echo wp_json_encode($order->get_order_number()); ?>;
        var cgBarcodeValue = <?php echo wp_json_encode($barcode_value); ?>;
        var cgBarcodeText = <?php echo wp_json_encode($barcode_text); ?>;
        var cgQrLabelText = <?php echo wp_json_encode($qr_label_text); ?>;
        var cgQrInvoiceText = <?php echo wp_json_encode($qr_invoice_text); ?>;
        var cgIsPro = <?php echo $is_pro ? 'true' : 'false'; ?>;
        var cgAjaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        var cgCourierNonce = <?php echo wp_json_encode(wp_create_nonce('cg_print_order_nonce')); ?>;
        var cgOrderId = <?php echo (int) $order_id; ?>;
        var cgSiteTimezone = <?php echo wp_json_encode(wp_timezone_string()); ?>;
    </script>
    <?php
    cg_render_print_order_script();
}

/**
 * Renders the three courier shipping label sizes (2x3in, 3x3in, A4).
 * Pro-gated: on Free, no real customer/order data is rendered at all — only
 * a locked placeholder — since this content is reachable via window.print()
 * regardless of any on-screen hover overlay, so the gate has to be at
 * render time, not just a CSS hover reveal.
 */
function cg_render_print_labels($order, $is_pro, $invoice_number, $company_name, $logo_url, $settings, $courier, $courier_label, $courier_color, $sort_type, $customer_name, $customer_phone, $address_1, $city, $state, $barcode_text)
{
    if (!$is_pro) {
        ?>
        <div class="cg-label-a4 cg-print-locked-card" id="cg-label-a4" style="min-height:400px;">
            <span class="dashicons dashicons-lock"></span>
            <p style="font-weight:600; font-size:14px;"><?php esc_html_e('Courier shipping labels require a Pro plan', 'checkoutguard'); ?></p>
            <button type="button" class="button button-primary cg-open-upgrade-modal"><?php esc_html_e('Upgrade Now', 'checkoutguard'); ?></button>
        </div>
        <?php
        return;
    }

    $sizes = ['2x3', '3x3', 'a4'];
    foreach ($sizes as $size) {
        $is_a4 = ($size === 'a4');
        $class = $is_a4 ? 'cg-label-a4' : 'cg-label-' . $size;
        ?>
        <div class="cg-label-size-<?php echo esc_attr($size); ?>" id="cg-label-container-<?php echo esc_attr($size); ?>" style="display:none;">
            <div class="<?php echo esc_attr($class); ?>">
                <?php if (!$is_a4): ?>
                    <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #ccc; padding-bottom:4px; margin-bottom:4px;">
                        <?php if ($logo_url): ?>
                            <img src="<?php echo esc_url($logo_url); ?>" style="max-height:24px; max-width:40%; object-fit:contain;" alt="">
                            <div style="text-align:right; max-width:55%; line-height:1.1;">
                                <div style="font-weight:700; font-size:6.5pt;"><?php echo esc_html($company_name); ?></div>
                                <div style="color:#64748b; font-size:5pt;"><?php echo esc_html($settings['company_phone']); ?></div>
                            </div>
                        <?php else: ?>
                            <div style="text-align:center; width:100%;">
                                <div style="font-weight:700; font-size:7pt; text-transform:uppercase;"><?php echo esc_html($company_name); ?></div>
                                <div style="color:#64748b; font-size:5.5pt;"><?php echo esc_html($settings['company_phone']); ?></div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div style="text-align:center; margin-bottom:4px;"><svg id="cg-barcode-<?php echo esc_attr($size); ?>"></svg></div>

                    <div style="display:flex; border-bottom:1px solid #ccc; padding-bottom:4px; margin-bottom:6px;">
                        <div style="width:35%; text-align:center; display:flex; align-items:center; justify-content:center;"><canvas id="cg-qr-label-<?php echo esc_attr($size); ?>"></canvas></div>
                        <div style="width:65%; padding-left:8px; font-size:6.5pt; line-height:1.3;">
                            <div><span style="color:#64748b;"><?php esc_html_e('ORDER:', 'checkoutguard'); ?></span> <strong><?php echo esc_html($order->get_order_number()); ?></strong></div>
                            <div><span style="color:#64748b;"><?php esc_html_e('PAYMENT:', 'checkoutguard'); ?></span> <?php echo $order->get_payment_method() === 'cod' ? esc_html__('COD', 'checkoutguard') : esc_html__('Prepaid', 'checkoutguard'); ?></div>
                            <?php if ($courier): ?>
                                <div class="cg-courier-badge" style="margin-top:3px; color:<?php echo esc_attr($courier_color); ?>; border:1.2px solid <?php echo esc_attr($courier_color); ?>; font-size:6pt;">
                                    <?php echo esc_html($courier_label); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div style="text-align:left; flex-grow:1; font-size:5.5pt; line-height:1.25; margin-bottom:6px;">
                        <div><strong><?php esc_html_e('NAME:', 'checkoutguard'); ?></strong> <?php echo esc_html($customer_name); ?></div>
                        <div><strong><?php esc_html_e('PHONE:', 'checkoutguard'); ?></strong> <?php echo esc_html($customer_phone); ?></div>
                        <div><strong><?php esc_html_e('ADDRESS:', 'checkoutguard'); ?></strong> <?php echo esc_html($address_1 . ', ' . $city); ?></div>
                    </div>

                    <?php if ($order->get_payment_method() === 'cod'): ?>
                    <div class="cg-cod-box" style="margin-bottom:4px;">
                        <span style="display:block; font-size:5pt; font-weight:normal;"><?php esc_html_e('CASH ON DELIVERY', 'checkoutguard'); ?></span>
                        <span style="display:block; font-size:9pt; font-weight:800;"><?php echo wp_kses_post(wc_price($order->get_total())); ?></span>
                    </div>
                    <?php endif; ?>

                    <div style="display:flex; justify-content:space-between; border-top:1px solid #ccc; padding-top:2px; font-size:4.8pt; color:#64748b;">
                        <span class="cg-printed-time-short"><?php echo esc_html(current_time('d/m/y h:i:sa')); ?></span>
                        <span><?php esc_html_e('Generated by Coder Zone BD', 'checkoutguard'); ?></span>
                    </div>
                <?php else: ?>
                    <div style="display:flex; justify-content:space-between; border-bottom:2px solid #000; padding-bottom:12px; margin-bottom:12px;">
                        <div>
                            <h3 style="margin:0 0 4px;"><?php esc_html_e('Shipped From:', 'checkoutguard'); ?><br><?php echo esc_html($company_name); ?></h3>
                            <div style="font-size:9pt; color:#64748b;"><?php echo esc_html($settings['company_address']); ?></div>
                            <div style="font-size:9pt; color:#64748b;"><?php echo esc_html($settings['company_phone']); ?></div>
                        </div>
                        <div>
                            <?php if ($logo_url): ?><img src="<?php echo esc_url($logo_url); ?>" style="max-height:45px;" alt=""><?php endif; ?>
                        </div>
                    </div>

                    <div style="display:flex; justify-content:space-between; border-bottom:2px solid #000; padding-bottom:12px; margin-bottom:12px;">
                        <div style="width:65%;">
                            <h3 style="margin:0 0 4px;"><?php esc_html_e('Shipped To:', 'checkoutguard'); ?><br><?php echo esc_html($customer_name); ?></h3>
                            <div style="font-weight:600; font-size:10pt;"><?php esc_html_e('Phone:', 'checkoutguard'); ?> <?php echo esc_html($customer_phone); ?></div>
                            <div style="font-size:9.5pt; margin-top:6px;"><strong><?php esc_html_e('Address:', 'checkoutguard'); ?></strong> <?php echo esc_html($address_1 . ', ' . $city . ', ' . $state); ?></div>
                        </div>
                        <div style="width:30%; text-align:right;"><canvas id="cg-qr-label-a4"></canvas></div>
                    </div>

                    <div style="display:flex; gap:16px;">
                        <div style="width:50%; border-right:1px solid #000; padding-right:12px;">
                            <?php if ($courier): ?>
                                <div class="cg-courier-badge" style="width:100%; text-align:center; font-size:10pt; padding:6px; margin-bottom:8px; color:<?php echo esc_attr($courier_color); ?>; border:2px solid <?php echo esc_attr($courier_color); ?>;">
                                    <?php echo esc_html($courier_label); ?>
                                </div>
                            <?php endif; ?>
                            <div style="font-size:9pt; margin-bottom:8px;"><?php esc_html_e('Order ID:', 'checkoutguard'); ?> <strong><?php echo esc_html($order->get_order_number()); ?></strong></div>
                            <div style="padding:10px; border:1px solid #000; background:#f8fafc;">
                                <div style="font-size:8.5pt; color:#64748b;"><?php esc_html_e('Collectable Amount:', 'checkoutguard'); ?></div>
                                <div style="font-size:20pt; font-weight:800;"><?php echo wp_kses_post(wc_price($order->get_total())); ?></div>
                            </div>
                        </div>
                        <div style="width:50%;">
                            <div style="text-align:center; margin-bottom:8px;"><svg id="cg-barcode-a4"></svg></div>
                            <div style="font-size:8.5pt; line-height:1.6;">
                                <div><?php esc_html_e('Order Date:', 'checkoutguard'); ?> <strong><?php echo esc_html($order->get_date_created() ? $order->get_date_created()->date('Y-m-d h:i:s A') : ''); ?></strong></div>
                                <div><?php esc_html_e('Target Hub:', 'checkoutguard'); ?> <strong><?php echo esc_html($city ?: 'N/A'); ?></strong></div>
                                <div><?php esc_html_e('Sort:', 'checkoutguard'); ?> <strong><?php echo esc_html($sort_type); ?></strong></div>
                            </div>
                        </div>
                    </div>

                    <div style="display:flex; justify-content:space-between; border-top:1px solid #000; margin-top:12px; padding-top:8px; font-size:8pt; color:#64748b;">
                        <span><?php esc_html_e('Printed:', 'checkoutguard'); ?> <span class="cg-printed-time"><?php echo esc_html(current_time('Y-m-d h:i:s A')); ?></span></span>
                        <span><?php esc_html_e('Generated by Coder Zone BD', 'checkoutguard'); ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}

/**
 * Print-page CSS: on-screen preview chrome, thermal/A4 label sizing, and
 * @media print rules that hide wp-admin's own chrome and force correct
 * paper size for the currently active mode.
 */
function cg_render_print_order_styles()
{
    ?>
    <style>
        .cg-print-order-wrap .cg-print-controls-bar { background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px 20px; margin-bottom:16px; display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:12px; }
        .cg-print-order-wrap .cg-print-controls-left, .cg-print-order-wrap .cg-print-controls-right { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
        .cg-print-order-wrap .cg-btn-group .button { border-radius:0; }
        .cg-print-order-wrap .cg-btn-group .button:first-child { border-radius:4px 0 0 4px; }
        .cg-print-order-wrap .cg-btn-group .button:last-child { border-radius:0 4px 4px 0; }
        .cg-courier-assign-bar { background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:10px 20px; margin-bottom:16px; display:flex; align-items:center; gap:16px; flex-wrap:wrap; }
        .cg-courier-assign-bar label { display:flex; align-items:center; gap:6px; font-weight:600; font-size:13px; }
        .cg-print-preview-area { background:#f1f5f9; border-radius:8px; min-height:60vh; position:relative; padding:30px 0; }
        .cg-zoom-controls { position:absolute; top:10px; right:10px; display:flex; gap:4px; z-index:10; }
        .cg-zoom-wrapper { display:flex; justify-content:center; overflow:auto; }
        .cg-mode-container { transform-origin: top center; transition: transform .1s ease; }

        /* Solid full-sheet border: box-sizing:border-box keeps it inside the
           label's exact physical size, and it's the same on screen as in
           print so the preview is WYSIWYG. */
        .cg-label-2x3, .cg-label-3x3 { font-family:sans-serif; color:#000; background:#fff; box-sizing:border-box; border:1px solid #000; display:flex; flex-direction:column; justify-content:space-between; }
        .cg-label-2x3 { width:2in; height:3in; padding:0.08in; font-size:6.5pt; line-height:1.1; }
        .cg-label-3x3 { width:3in; height:3in; padding:8px; font-size:7.8pt; line-height:1.15; }
        .cg-label-a4 { width:210mm; min-height:297mm; padding:20mm; box-sizing:border-box; font-family:sans-serif; color:#000; background:#fff; border:2px solid #000; margin:0 auto; }
        .cg-invoice-a4 { width:210mm; min-height:297mm; padding:20mm; box-sizing:border-box; font-family:sans-serif; color:#000; background:#fff; border:1px solid #cbd5e1; display:flex; flex-direction:column; justify-content:space-between; margin:0 auto; }

        .cg-cod-box { border:1.5px solid #000; padding:3px; text-align:center; background:#f8fafc; font-weight:bold; border-radius:4px; }
        .cg-courier-badge { border-radius:4px; padding:2.5px 6px; font-weight:700; text-align:center; display:inline-block; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
        .cg-invoice-table th, .cg-invoice-table td { text-align:center; vertical-align:middle; padding:10px 8px; }
        .cg-invoice-footer-bar { color:#fff; padding:10px 20px; font-size:8.5pt; display:flex; justify-content:space-between; align-items:center; margin:0 -20mm -20mm -20mm; }

        .cg-print-locked-card { display:flex; flex-direction:column; align-items:center; justify-content:center; gap:10px; text-align:center; background:#f8fafc; }
        .cg-print-locked-card .dashicons { font-size:40px; width:40px; height:40px; color:#94a3b8; }

        @media print {
            /* margin: 0 — every printable sheet below is sized to exactly
               match its @page size and supplies its own whitespace via
               padding. A non-zero @page margin would shrink the printable
               area below the sheet's declared size, so the browser would
               clip it or auto-scale it down. */
            @page { size: A4 portrait; margin: 0; }

            #adminmenumain, #wpadminbar, #wpfooter, .cg-print-controls-bar, .cg-courier-assign-bar, .cg-zoom-controls, .no-print { display:none !important; }

            /* Flatten every wp-admin + preview ancestor so the printable
               sheet lands flush at the page's top-left corner. Without this
               the preview's flex centering pushes a 2in label ~3in to the
               right — entirely off a 2in-wide page (which prints blank) —
               and leftover ancestor padding pushes A4 content down far
               enough to spill onto a second page. */
            html, body, #wpwrap, #wpcontent, #wpbody, #wpbody-content,
            .cg-print-order-wrap, .cg-print-order-wrap .container-fluid,
            .cg-print-preview-area, .cg-zoom-wrapper {
                display:block !important; position:static !important; float:none !important;
                width:auto !important; min-width:0 !important; max-width:none !important;
                height:auto !important; min-height:0 !important; max-height:none !important;
                margin:0 !important; padding:0 !important; border:0 !important;
                background:#fff !important; overflow:visible !important; box-shadow:none !important;
            }

            /* Mode/size containers: reset the box but never `display` —
               which one is visible is driven by inline display + the
               mode rules at the end of this block. */
            .cg-mode-container,
            #cg-label-container-2x3, #cg-label-container-3x3, #cg-label-container-a4 {
                position:static !important; width:auto !important; max-width:none !important;
                margin:0 !important; padding:0 !important; border:0 !important;
                overflow:visible !important; transform:none !important;
            }

            /* The sheets themselves, flush to the page corner. Widths/heights
               sit ~1mm inside the nominal A4 page box: Chrome rounds an A4
               page to 209.9mm x 296.9mm, so a sheet declared at exactly
               210x297 overflows by a fraction of a millimetre and spills
               onto a blank second page. */
            .cg-invoice-a4, .cg-label-a4, .cg-label-2x3, .cg-label-3x3 {
                margin:0 !important; box-shadow:none !important;
                -webkit-print-color-adjust:exact; print-color-adjust:exact;
            }
            /* A label is always exactly one fixed sheet — clip any spill. */
            .cg-label-a4, .cg-label-2x3, .cg-label-3x3 { overflow:hidden !important; break-inside:avoid; }
            .cg-label-a4 { width:209mm !important; height:296mm !important; min-height:0 !important; }
            /* The invoice can legitimately run long (many line items), so it
               keeps a one-page minimum but is free to flow onto further
               pages rather than silently clipping items. */
            .cg-invoice-a4 {
                width:209mm !important; height:auto !important;
                min-height:296mm !important; overflow:visible !important;
            }
            /* Only the invoice drops its border in print (it's just a
               preview guide); all three label sizes keep their full-sheet
               border as part of the printed design. */
            .cg-invoice-a4 { border:none !important; }

            body.cg-print-mode-invoice #cg-label-mode-container,
            body.cg-print-mode-label #cg-invoice-mode-container { display:none !important; }
        }
    </style>
    <!-- Rewritten by JS whenever the mode/size changes, so the printed page
         size (@page) always matches the currently selected label size —
         a static @page rule can't be scoped to a body class. -->
    <style id="cg-page-size-style"></style>
    <?php
}

/**
 * Renders the A4 invoice (Free) — a print-styled version of the same data
 * as the downloadable PDF invoice, with barcode/QR and live preview.
 */
function cg_render_print_invoice($order, $invoice_number, $company_name, $logo_url, $settings, $courier, $courier_label, $courier_color)
{
    $footer_color = $settings['footer_bg_color'] ?: '#1e293b';
    ?>
    <div class="cg-invoice-a4">
        <div>
            <div style="display:flex; justify-content:space-between; align-items:flex-start; padding-bottom:20px; margin-bottom:25px; border-bottom:2px solid #000;">
                <div>
                    <?php if ($logo_url): ?>
                        <img src="<?php echo esc_url($logo_url); ?>" style="max-height:60px; object-fit:contain;" alt="">
                    <?php else: ?>
                        <h2 style="font-weight:700; text-transform:uppercase; margin:0; font-size:22pt; letter-spacing:1px;"><?php echo esc_html($company_name); ?></h2>
                    <?php endif; ?>
                </div>
                <div style="text-align:right; font-size:10pt; line-height:1.5; color:#333;">
                    <div style="font-weight:700; font-size:16pt; color:#000; margin-bottom:4px;"><?php echo esc_html($company_name); ?></div>
                    <?php if ($settings['company_address']): ?><div><?php echo wp_kses_post(nl2br(esc_html($settings['company_address']))); ?></div><?php endif; ?>
                    <?php if ($settings['company_phone']): ?><div><?php echo esc_html($settings['company_phone']); ?></div><?php endif; ?>
                    <?php if ($settings['company_email']): ?><div><?php echo esc_html($settings['company_email']); ?></div><?php endif; ?>
                </div>
            </div>

            <div style="display:flex; justify-content:space-between; margin-bottom:30px;">
                <div style="max-width:55%;">
                    <div style="font-size:10pt; color:#555; margin-bottom:8px;"><?php esc_html_e('Invoice to:', 'checkoutguard'); ?></div>
                    <div style="font-weight:700; font-size:14pt; color:#000; margin-bottom:5px;"><?php echo esc_html($order->get_formatted_billing_full_name()); ?></div>
                    <?php
                    // get_formatted_billing_address() leads with the customer
                    // name, which is already shown above in its own style —
                    // drop it here so the name isn't printed twice.
                    $cg_billing = $order->get_address('billing');
                    unset($cg_billing['first_name'], $cg_billing['last_name']);
                    $cg_address = WC()->countries->get_formatted_address($cg_billing);
                    ?>
                    <div style="font-size:10pt; line-height:1.5; color:#333;"><?php echo wp_kses_post($cg_address); ?></div>
                    <div style="font-size:10pt; color:#333; margin-top:4px;"><strong><?php esc_html_e('Phone:', 'checkoutguard'); ?></strong> <?php echo esc_html($order->get_billing_phone()); ?></div>
                </div>
                <div style="text-align:right;">
                    <div style="font-size:36pt; font-weight:300; letter-spacing:2px; color:#000; margin-bottom:12px;"><?php esc_html_e('INVOICE', 'checkoutguard'); ?></div>
                    <div style="font-size:11pt; line-height:1.6; color:#333;">
                        <div><?php esc_html_e('Invoice No#', 'checkoutguard'); ?> <strong style="font-size:12pt; color:#000; margin-left:8px;"><?php echo esc_html($invoice_number); ?></strong></div>
                        <div><?php esc_html_e('Order No#', 'checkoutguard'); ?> <strong style="color:#000; margin-left:8px;"><?php echo esc_html($order->get_order_number()); ?></strong></div>
                        <div><?php esc_html_e('Date', 'checkoutguard'); ?> <strong style="color:#000; margin-left:8px;"><?php echo esc_html($order->get_date_created() ? $order->get_date_created()->date('d / m / Y  h:i:s A') : ''); ?></strong></div>
                    </div>
                </div>
            </div>

            <table class="cg-invoice-table" style="width:100%; border-collapse:collapse; margin-bottom:20px;">
                <thead>
                    <tr style="border-top:2px solid #000; border-bottom:2px solid #000;">
                        <th style="width:48%; text-transform:uppercase; font-size:10pt;"><?php esc_html_e('Item', 'checkoutguard'); ?></th>
                        <th style="width:16%; text-transform:uppercase; font-size:10pt;"><?php esc_html_e('Quantity', 'checkoutguard'); ?></th>
                        <th style="width:18%; text-transform:uppercase; font-size:10pt;"><?php esc_html_e('Unit Price', 'checkoutguard'); ?></th>
                        <th style="width:18%; text-transform:uppercase; font-size:10pt;"><?php esc_html_e('Total', 'checkoutguard'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($order->get_items() as $item): ?>
                        <tr style="border-bottom:1px solid #e2e8f0;">
                            <td style="font-size:10pt;"><?php echo esc_html($item->get_name()); ?></td>
                            <td style="font-size:10pt;"><?php echo esc_html($item->get_quantity()); ?></td>
                            <td style="font-size:10pt;"><?php echo wp_kses_post(wc_price($order->get_item_subtotal($item, false, true))); ?></td>
                            <td style="font-size:10pt; font-weight:600;"><?php echo wp_kses_post(wc_price($item->get_total())); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div style="display:flex; justify-content:space-between; align-items:flex-start; padding-top:8px;">
                <div style="max-width:50%;">
                    <div style="font-weight:700; text-transform:uppercase; font-size:10.5pt; margin-bottom:10px;"><?php esc_html_e('Payment Method', 'checkoutguard'); ?></div>
                    <?php if ($order->get_payment_method() === 'cod'): ?>
                        <div style="display:inline-block; padding:8px 18px; border:2px solid #000; border-radius:8px; font-weight:700; font-size:12pt; font-style:italic;">
                            <?php esc_html_e('Cash on Delivery', 'checkoutguard'); ?>
                        </div>
                    <?php else: ?>
                        <div style="font-size:10pt; line-height:1.6;">
                            <div style="font-weight:700; font-size:11pt; margin-bottom:4px;"><?php echo esc_html($order->get_payment_method_title()); ?></div>
                            <?php if ($order->get_transaction_id()): ?>
                                <div><?php esc_html_e('Transaction ID:', 'checkoutguard'); ?> <strong><?php echo esc_html($order->get_transaction_id()); ?></strong></div>
                            <?php endif; ?>
                            <div><?php esc_html_e('Paid on:', 'checkoutguard'); ?> <strong><?php echo esc_html($order->get_date_paid() ? $order->get_date_paid()->date('Y-m-d h:i:s A') : '—'); ?></strong></div>
                        </div>
                    <?php endif; ?>
                </div>
                <div style="min-width:240px;">
                    <table style="width:100%; font-size:10.5pt;">
                        <tr>
                            <td style="text-align:right; padding:5px 12px 5px 0; color:#555; font-weight:600;"><?php esc_html_e('Subtotal', 'checkoutguard'); ?></td>
                            <td style="text-align:right; font-weight:600;"><?php echo wp_kses_post(wc_price($order->get_subtotal())); ?></td>
                        </tr>
                        <?php if ((float) $order->get_total_tax() > 0): ?>
                        <tr>
                            <td style="text-align:right; padding:5px 12px 5px 0; color:#555; font-weight:600;"><?php esc_html_e('Tax', 'checkoutguard'); ?></td>
                            <td style="text-align:right; font-weight:600;"><?php echo wp_kses_post(wc_price($order->get_total_tax())); ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td style="text-align:right; padding:5px 12px 5px 0; color:#555; font-weight:600;"><?php esc_html_e('Shipping', 'checkoutguard'); ?></td>
                            <td style="text-align:right; font-weight:600;"><?php echo wp_kses_post(wc_price($order->get_shipping_total())); ?></td>
                        </tr>
                        <tr style="border-top:1.5px solid #ccc;">
                            <td style="text-align:right; padding:12px 12px 5px 0; font-size:14pt; font-weight:800;"><?php esc_html_e('Total', 'checkoutguard'); ?></td>
                            <td style="text-align:right; font-size:15pt; font-weight:800;"><?php echo wp_kses_post(wc_price($order->get_total())); ?></td>
                        </tr>
                    </table>
                </div>
            </div>

            <div style="display:flex; flex-direction:column; align-items:center; margin-top:50px; margin-bottom:20px; text-align:center;">
                <canvas id="cg-qr-invoice"></canvas>
                <?php if ($courier): ?>
                    <div class="cg-courier-badge" style="font-size:13pt; margin-top:12px; padding:7px 22px; color:<?php echo esc_attr($courier_color); ?>; border:2.5px solid <?php echo esc_attr($courier_color); ?>;">
                        <?php echo esc_html($courier_label); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div style="margin-top:auto; padding-top:40px;">
            <div style="display:flex; justify-content:space-between; align-items:flex-end; margin-bottom:30px;">
                <div style="font-size:12pt; font-style:italic; font-weight:600;"><?php esc_html_e('Thank you for your business!', 'checkoutguard'); ?></div>
                <div style="text-align:center; width:200px;">
                    <div style="border-bottom:2px solid #000; margin-bottom:6px;"></div>
                    <div style="font-size:9.5pt; font-weight:700; text-transform:uppercase;"><?php esc_html_e('Authorized Signed', 'checkoutguard'); ?></div>
                </div>
            </div>
            <div class="cg-invoice-footer-bar" style="background-color:<?php echo esc_attr($footer_color); ?>;">
                <div><?php esc_html_e('Printed:', 'checkoutguard'); ?> <span class="cg-printed-time"><?php echo esc_html(current_time('Y-m-d h:i:s A')); ?></span></div>
                <div><?php echo esc_html($settings['footer_text']); ?></div>
            </div>
        </div>
    </div>
    <?php
}

/**
 * The print page's client-side controller: mode/size switching, zoom,
 * localStorage preferences, barcode/QR generation, and the courier-save
 * AJAX call. Mirrors the reference system's UX but scoped to the plugin's
 * own libraries and element IDs.
 */
function cg_render_print_order_script()
{
    ?>
    <script>
    (function () {
        'use strict';
        var currentMode = localStorage.getItem('cg_print_pref_mode') || 'invoice';
        var currentSize = localStorage.getItem('cg_print_pref_size') || '2x3';
        var currentScale = parseFloat(localStorage.getItem('cg_print_pref_scale') || '1.0');

        function generateCodes() {
            try {
                if (document.getElementById('cg-barcode-2x3')) {
                    JsBarcode('#cg-barcode-2x3', cgBarcodeValue, { format: 'CODE128', width: 1.1, height: 20, displayValue: true, text: cgBarcodeText, fontSize: 7, margin: 0 });
                    JsBarcode('#cg-barcode-3x3', cgBarcodeValue, { format: 'CODE128', width: 1.4, height: 25, displayValue: true, text: cgBarcodeText, fontSize: 8, margin: 0 });
                    JsBarcode('#cg-barcode-a4', cgBarcodeValue, { format: 'CODE128', width: 1.8, height: 45, displayValue: true, text: cgBarcodeText, fontSize: 10, margin: 2 });
                }
            } catch (e) { /* barcode target may not exist in current mode; ignore */ }

            function qrImage(canvasId, text, size) {
                var canvas = document.getElementById(canvasId);
                if (!canvas) return;
                try {
                    var encoded = unescape(encodeURIComponent(text));
                    var qr = new QRious({ element: canvas, value: encoded, size: size * 2, level: 'L' });
                    var img = document.createElement('img');
                    img.src = canvas.toDataURL('image/png');
                    img.style.width = size + 'px';
                    img.style.height = size + 'px';
                    canvas.parentNode.replaceChild(img, canvas);
                } catch (e) { /* ignore */ }
            }

            qrImage('cg-qr-label-2x3', cgQrLabelText, 55);
            qrImage('cg-qr-label-3x3', cgQrLabelText, 75);
            qrImage('cg-qr-label-a4', cgQrLabelText, 110);
            qrImage('cg-qr-invoice', cgQrInvoiceText, 170);
        }

        window.cgToggleMode = function (mode) {
            currentMode = mode;
            localStorage.setItem('cg_print_pref_mode', mode);

            var btnInvoice = document.getElementById('cg-btn-toggle-invoice');
            var btnLabel = document.getElementById('cg-btn-toggle-label');
            var invoiceWrap = document.getElementById('cg-invoice-mode-container');
            var labelWrap = document.getElementById('cg-label-mode-container');
            var sizeSelector = document.querySelector('.cg-label-size-selector');
            var btnPrint = document.getElementById('cg-btn-trigger-print');

            document.body.classList.remove('cg-print-mode-invoice', 'cg-print-mode-label');

            if (mode === 'label') {
                btnLabel.classList.add('button-primary');
                btnInvoice.classList.remove('button-primary');
                labelWrap.style.display = '';
                invoiceWrap.style.display = 'none';
                if (sizeSelector) sizeSelector.style.display = cgIsPro ? '' : 'none';
                btnPrint.textContent = 'Print Label';
                document.body.classList.add('cg-print-mode-label');
                if (cgIsPro) window.cgSelectSize(currentSize);
            } else {
                btnInvoice.classList.add('button-primary');
                btnLabel.classList.remove('button-primary');
                invoiceWrap.style.display = '';
                labelWrap.style.display = 'none';
                if (sizeSelector) sizeSelector.style.display = 'none';
                btnPrint.textContent = 'Print Invoice';
                document.body.classList.add('cg-print-mode-invoice');
            }
            applyZoom();
            updatePageSize();
        };

        window.cgSelectSize = function (size) {
            currentSize = size;
            localStorage.setItem('cg_print_pref_size', size);
            document.body.classList.remove('cg-label-size-2x3', 'cg-label-size-3x3', 'cg-label-size-a4');
            document.body.classList.add('cg-label-size-' + size);

            ['2x3', '3x3', 'a4'].forEach(function (s) {
                var el = document.getElementById('cg-label-container-' + s);
                if (el) el.style.display = (s === size) ? '' : 'none';
                var btn = document.getElementById('cg-btn-size-' + s);
                if (btn) btn.classList.toggle('button-primary', s === size);
            });
            applyZoom();
            updatePageSize();
        };

        window.cgZoom = function (delta) {
            currentScale = Math.min(Math.max(currentScale + delta, 0.5), 2.5);
            localStorage.setItem('cg_print_pref_scale', currentScale);
            applyZoom();
        };
        window.cgResetZoom = function () {
            currentScale = 1.0;
            localStorage.setItem('cg_print_pref_scale', currentScale);
            applyZoom();
        };
        function applyZoom() {
            var container = currentMode === 'label' ? document.getElementById('cg-label-mode-container') : document.getElementById('cg-invoice-mode-container');
            if (container) container.style.transform = 'scale(' + currentScale + ')';
        }

        function updatePageSize() {
            var styleEl = document.getElementById('cg-page-size-style');
            if (!styleEl) return;
            // margin is always 0 here — the content div for every size
            // (.cg-invoice-a4 / .cg-label-a4 / .cg-label-2x3 / .cg-label-3x3)
            // already matches its @page size exactly and provides its own
            // whitespace via padding, so the page box and the div are
            // always the same physical size, with nothing left for the
            // browser to clip or auto-scale.
            var size = 'A4 portrait';
            if (currentMode === 'label' && cgIsPro) {
                if (currentSize === '2x3') { size = '2in 3in'; }
                else if (currentSize === '3x3') { size = '3in 3in'; }
            }
            styleEl.textContent = '@media print { @page { size: ' + size + '; margin: 0; } }';
        }

        // Refreshes the "Printed:" stamps at print time so they show the
        // moment of printing rather than the moment the page was opened.
        // Always to the second, and built from parts so the output matches
        // the server-rendered format exactly (Y-m-d h:i:s A).
        function updatePrintedTime() {
            var opts = {
                year: 'numeric', month: '2-digit', day: '2-digit',
                hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true
            };
            // wp_timezone_string() may return a raw UTC offset ("+06:00")
            // when the site uses a manual offset instead of a named zone;
            // Intl only accepts IANA names, so fall back to browser-local.
            var parts = {};
            try {
                opts.timeZone = cgSiteTimezone;
                new Intl.DateTimeFormat('en-US', opts).formatToParts(new Date())
                    .forEach(function (p) { parts[p.type] = p.value; });
            } catch (e) {
                parts = {};
                delete opts.timeZone;
                new Intl.DateTimeFormat('en-US', opts).formatToParts(new Date())
                    .forEach(function (p) { parts[p.type] = p.value; });
            }

            var hms = parts.hour + ':' + parts.minute + ':' + parts.second + ' ' + (parts.dayPeriod || '');
            var full = parts.year + '-' + parts.month + '-' + parts.day + ' ' + hms;
            var short = parts.day + '/' + parts.month + '/' + String(parts.year).slice(-2) + ' ' +
                        parts.hour + ':' + parts.minute + ':' + parts.second + (parts.dayPeriod || '').toLowerCase();

            document.querySelectorAll('.cg-printed-time').forEach(function (el) { el.textContent = full.trim(); });
            document.querySelectorAll('.cg-printed-time-short').forEach(function (el) { el.textContent = short.trim(); });
        }

        document.addEventListener('DOMContentLoaded', function () {
            generateCodes();
            updatePrintedTime();
            window.cgToggleMode(currentMode === 'label' ? 'label' : 'invoice');
            applyZoom();

            var saveBtn = document.getElementById('cg-save-courier-btn');
            if (saveBtn) {
                saveBtn.addEventListener('click', function () {
                    var spinner = saveBtn.nextElementSibling;
                    saveBtn.disabled = true;
                    if (spinner) spinner.classList.add('is-active');
                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', cgAjaxUrl, true);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    xhr.onload = function () {
                        saveBtn.disabled = false;
                        if (spinner) spinner.classList.remove('is-active');
                        if (xhr.status === 200) {
                            location.reload();
                        }
                    };
                    var params = 'action=cg_save_order_courier&nonce=' + encodeURIComponent(cgCourierNonce) +
                        '&order_id=' + encodeURIComponent(cgOrderId) +
                        '&courier=' + encodeURIComponent(document.getElementById('cg-courier-select').value) +
                        '&tracking_number=' + encodeURIComponent(document.getElementById('cg-tracking-input').value);
                    xhr.send(params);
                });
            }
        });
        window.addEventListener('beforeprint', updatePrintedTime);
    })();
    </script>
    <?php
}
