<?php
// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}


/**
 * Renders the Invoices admin page: list of all invoiced orders, manual
 * invoice generation (Pro), and bulk ZIP download (Pro).
 */
/**
 * Main render function for the Invoices admin page: tab shell dispatching
 * to the "All Invoices" list and the "Settings" tab.
 */
function cg_render_invoices_page()
{
    $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'list';
    ?>
    <div class="wrap">
        <h1><?php _e('CheckoutGuard Invoices', 'checkoutguard'); ?></h1>

        <nav class="nav-tab-wrapper">
            <a href="?page=cg-invoices&tab=list"
                class="nav-tab <?php echo $active_tab === 'list' ? 'nav-tab-active' : ''; ?>">
                <span class="dashicons dashicons-media-spreadsheet" style="margin-right:4px;"></span>
                <?php _e('All Invoices', 'checkoutguard'); ?>
            </a>
            <a href="?page=cg-invoices&tab=settings"
                class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                <span class="dashicons dashicons-admin-generic" style="margin-right:4px;"></span>
                <?php _e('Settings', 'checkoutguard'); ?>
            </a>
        </nav>

        <div class="cg-settings-content">
            <?php
            if ($active_tab === 'settings') {
                cg_render_invoices_settings_tab();
            } else {
                cg_render_invoices_list_tab();
            }
            ?>
        </div>
    </div>
    <?php
}


/**
 * Renders the "All Invoices" tab: manual generation, bulk download, and
 * the paginated/searchable list of every invoiced order.
 */
function cg_render_invoices_list_tab()
{
    $is_pro = cg_is_pro_plan();
    $initial = cg_fetch_invoiced_orders(1, 20, '');
    $invoices_nonce = wp_create_nonce('cg_invoices_nonce');
    ?>
        <div class="cg-settings-card" style="margin-bottom:16px;">
            <div class="cg-settings-field">
                <label class="cg-settings-field__label"><?php _e('Generate From Existing Order', 'checkoutguard'); ?></label>
                <div class="cg-settings-field__input" style="display:flex; gap:8px; align-items:center;">
                    <input type="number" min="1" id="cg-manual-invoice-order-id" placeholder="<?php esc_attr_e('Order ID', 'checkoutguard'); ?>"
                        class="small-text">
                    <button type="button" id="cg-generate-manual-invoice" class="button button-primary">
                        <span class="dashicons dashicons-media-spreadsheet"></span>
                        <?php _e('Generate Invoice', 'checkoutguard'); ?>
                    </button>
                    <span class="spinner"></span>
                </div>
                <p class="description"><?php _e('For orders that predate the invoice feature, or need re-generating manually.', 'checkoutguard'); ?></p>
            </div>
            <div class="cg-settings-field" style="margin-top:16px;">
                <label class="cg-settings-field__label">
                    <?php _e('Blank Invoice / Label', 'checkoutguard'); ?>
                    <?php if (!$is_pro): ?><span class="cg-pro-badge">PRO</span><?php endif; ?>
                </label>
                <div class="cg-settings-field__input">
                    <button type="button" id="cg-open-manual-invoice-modal" class="button button-primary" <?php disabled(!$is_pro); ?>>
                        <span class="dashicons dashicons-plus-alt2"></span>
                        <?php _e('New Blank Invoice', 'checkoutguard'); ?>
                    </button>
                </div>
                <p class="description"><?php _e('Build a fully custom invoice and shipping label with no WooCommerce order needed — freeform items or picked from your catalog. Never affects stock, the Orders list, or reports.', 'checkoutguard'); ?></p>
            </div>
        </div>

        <?php cg_render_manual_invoice_modal(); ?>

        <div class="cg-bulk-actions" id="cg-invoices-bulk-bar" data-list-id="cg-invoices-table-body" data-nonce="<?php echo esc_attr($invoices_nonce); ?>" style="<?php echo empty($initial['orders']) ? 'display:none;' : ''; ?>">
            <label class="cg-bulk-select-all-label"><input type="checkbox" class="cg-invoice-select-all" id="cg-invoices-select-all"> <?php _e('Select All', 'checkoutguard'); ?></label>
            <button type="button" class="button button-small" id="cg-invoices-bulk-download" disabled <?php disabled(!$is_pro); ?>>
                <span class="dashicons dashicons-download"></span>
                <?php _e('Download Selected (ZIP)', 'checkoutguard'); ?>
                <?php if (!$is_pro): ?><span class="cg-pro-badge">PRO</span><?php endif; ?>
            </button>
            <span class="cg-bulk-count"></span>
            <span class="spinner"></span>
            <input type="search" id="cg-invoices-search" placeholder="<?php esc_attr_e('Search order, name, email...', 'checkoutguard'); ?>" style="margin-left:auto;">
        </div>

        <div id="cg-invoices-loading" class="cg-table-loader" style="display:none;">
            <div class="cg-spinner"></div>
            <p><?php _e('Loading invoices...', 'checkoutguard'); ?></p>
        </div>

        <div id="cg-invoices-table-body-empty-message" class="cg-table-empty-message" <?php if (!empty($initial['orders']))
               echo 'style="display:none;"'; ?>>
            <p><?php _e('No invoices generated yet. Invoices are created automatically when an order reaches Processing or Completed.', 'checkoutguard'); ?></p>
        </div>

        <div class="cg-table-responsive-wrapper">
            <table class="wp-list-table widefat fixed striped" id="cg-invoices-table" <?php if (empty($initial['orders']))
                   echo 'style="display:none;"'; ?>>
                <thead>
                    <tr>
                        <th style="width:24px;"></th>
                        <th><?php _e('Order', 'checkoutguard'); ?></th>
                        <th><?php _e('Invoice #', 'checkoutguard'); ?></th>
                        <th><?php _e('Customer', 'checkoutguard'); ?></th>
                        <th><?php _e('Date', 'checkoutguard'); ?></th>
                        <th><?php _e('Total', 'checkoutguard'); ?></th>
                        <th><?php _e('Actions', 'checkoutguard'); ?></th>
                    </tr>
                </thead>
                <tbody id="cg-invoices-table-body">
                    <?php echo cg_get_invoices_table_rows_html($initial['orders']); ?>
                </tbody>
            </table>
        </div>
        <div id="cg-invoices-pagination" class="cg-pagination-container" data-total-pages="<?php echo esc_attr($initial['total_pages']); ?>" data-page="1"></div>
    <?php
}


/**
 * Renders the "New Blank Invoice" builder modal (Pro): customer info,
 * a freeform + product-picker line-item repeater, shipping, payment method,
 * and courier/tracking — everything cg_create_manual_invoice_order() needs.
 * Company/tax header info is intentionally not duplicated here — it's
 * pulled automatically from the existing Invoice Settings on render.
 */
function cg_render_manual_invoice_modal()
{
    $currency_symbol = function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '';
    ?>
    <div id="cg-manual-invoice-modal" class="cg-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="cg-manual-invoice-modal-title">
        <div class="cg-modal-content" style="max-width:820px;">
            <div class="cg-mi-header">
                <div class="cg-mi-header-icon"><span class="dashicons dashicons-media-document"></span></div>
                <div class="cg-mi-header-text">
                    <h3 id="cg-manual-invoice-modal-title"><?php esc_html_e('New Blank Invoice', 'checkoutguard'); ?></h3>
                    <p><?php esc_html_e('Create a fully custom invoice and shipping label — no WooCommerce order needed. Never touches stock, the Orders list, or reports.', 'checkoutguard'); ?></p>
                </div>
                <button type="button" class="cg-modal-close" aria-label="<?php esc_attr_e('Close', 'checkoutguard'); ?>">&times;</button>
            </div>
            <form id="cg-manual-invoice-form">
                <div class="cg-mi-body">
                    <section class="cg-mi-section">
                        <h4 class="cg-mi-section-title"><span class="dashicons dashicons-admin-users"></span> <?php esc_html_e('Customer', 'checkoutguard'); ?></h4>
                        <div class="cg-mi-grid">
                            <div class="cg-form-field" style="grid-column: 1 / -1;">
                                <label for="cg-mi-customer-name"><?php esc_html_e('Name', 'checkoutguard'); ?> <span class="required">*</span></label>
                                <input type="text" id="cg-mi-customer-name" class="regular-text" placeholder="<?php esc_attr_e('e.g. Rahim Uddin', 'checkoutguard'); ?>" required>
                            </div>
                            <div class="cg-form-field">
                                <label for="cg-mi-phone"><?php esc_html_e('Phone', 'checkoutguard'); ?></label>
                                <input type="text" id="cg-mi-phone" class="regular-text" placeholder="01XXXXXXXXX">
                            </div>
                            <div class="cg-form-field">
                                <label for="cg-mi-email"><?php esc_html_e('Email', 'checkoutguard'); ?></label>
                                <input type="email" id="cg-mi-email" class="regular-text" placeholder="name@example.com">
                            </div>
                            <div class="cg-form-field" style="grid-column: 1 / -1;">
                                <label for="cg-mi-address"><?php esc_html_e('Address', 'checkoutguard'); ?></label>
                                <input type="text" id="cg-mi-address" class="regular-text">
                            </div>
                            <div class="cg-form-field">
                                <label for="cg-mi-city"><?php esc_html_e('City', 'checkoutguard'); ?></label>
                                <input type="text" id="cg-mi-city" class="regular-text">
                            </div>
                            <div class="cg-form-field">
                                <label for="cg-mi-state"><?php esc_html_e('State / Division', 'checkoutguard'); ?></label>
                                <input type="text" id="cg-mi-state" class="regular-text">
                            </div>
                            <div class="cg-form-field">
                                <label for="cg-mi-postcode"><?php esc_html_e('Postcode', 'checkoutguard'); ?></label>
                                <input type="text" id="cg-mi-postcode" class="regular-text">
                            </div>
                        </div>
                    </section>

                    <section class="cg-mi-section">
                        <h4 class="cg-mi-section-title"><span class="dashicons dashicons-cart"></span> <?php esc_html_e('Items', 'checkoutguard'); ?></h4>
                        <div class="cg-mi-items">
                            <div class="cg-mi-items-head">
                                <span><?php esc_html_e('Product (optional)', 'checkoutguard'); ?></span>
                                <span><?php esc_html_e('Name', 'checkoutguard'); ?></span>
                                <span><?php esc_html_e('Qty', 'checkoutguard'); ?></span>
                                <span><?php esc_html_e('Price', 'checkoutguard'); ?></span>
                                <span style="text-align:right;"><?php esc_html_e('Amount', 'checkoutguard'); ?></span>
                                <span></span>
                            </div>
                            <div id="cg-mi-items-body"></div>
                        </div>
                        <button type="button" id="cg-mi-add-item" class="button button-small cg-mi-add-item">
                            <span class="dashicons dashicons-plus"></span> <?php esc_html_e('Add Item', 'checkoutguard'); ?>
                        </button>
                    </section>

                    <section class="cg-mi-section cg-mi-split">
                        <div>
                            <h4 class="cg-mi-section-title"><span class="dashicons dashicons-money-alt"></span> <?php esc_html_e('Payment & Shipping', 'checkoutguard'); ?></h4>
                            <div class="cg-form-field">
                                <label for="cg-mi-payment-method"><?php esc_html_e('Payment Method', 'checkoutguard'); ?></label>
                                <select id="cg-mi-payment-method" class="regular-text">
                                    <option value="cod"><?php esc_html_e('Cash on Delivery', 'checkoutguard'); ?></option>
                                    <option value="paid"><?php esc_html_e('Paid', 'checkoutguard'); ?></option>
                                    <option value="bank_transfer"><?php esc_html_e('Bank Transfer', 'checkoutguard'); ?></option>
                                    <option value="other"><?php esc_html_e('Other', 'checkoutguard'); ?></option>
                                </select>
                            </div>
                            <div class="cg-form-field" id="cg-mi-transaction-field" style="display:none;">
                                <label for="cg-mi-transaction-id"><?php esc_html_e('Transaction ID', 'checkoutguard'); ?></label>
                                <input type="text" id="cg-mi-transaction-id" class="regular-text">
                            </div>
                            <div class="cg-form-field">
                                <label for="cg-mi-shipping-total"><?php esc_html_e('Shipping Charge', 'checkoutguard'); ?></label>
                                <input type="number" min="0" step="0.01" id="cg-mi-shipping-total" class="regular-text" value="0">
                            </div>
                        </div>
                        <div>
                            <h4 class="cg-mi-section-title"><span class="dashicons dashicons-car"></span> <?php esc_html_e('Courier (optional)', 'checkoutguard'); ?></h4>
                            <div class="cg-form-field">
                                <label for="cg-mi-courier"><?php esc_html_e('Courier', 'checkoutguard'); ?></label>
                                <select id="cg-mi-courier" class="regular-text">
                                    <option value=""><?php esc_html_e('— None —', 'checkoutguard'); ?></option>
                                    <?php foreach (function_exists('cg_get_supported_couriers') ? cg_get_supported_couriers() : [] as $slug => $courier): ?>
                                        <option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($courier['name'] ?? $slug); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="cg-form-field">
                                <label for="cg-mi-tracking"><?php esc_html_e('Tracking Number', 'checkoutguard'); ?></label>
                                <input type="text" id="cg-mi-tracking" class="regular-text">
                                <p class="description"><?php esc_html_e('Used on the printable shipping label.', 'checkoutguard'); ?></p>
                            </div>
                        </div>
                    </section>
                </div>

                <div class="cg-mi-summary">
                    <div class="cg-mi-summary-row">
                        <span><?php esc_html_e('Subtotal', 'checkoutguard'); ?></span>
                        <span id="cg-mi-subtotal"><?php echo esc_html($currency_symbol); ?>0.00</span>
                    </div>
                    <div class="cg-mi-summary-row">
                        <span><?php esc_html_e('Shipping', 'checkoutguard'); ?></span>
                        <span id="cg-mi-summary-shipping"><?php echo esc_html($currency_symbol); ?>0.00</span>
                    </div>
                    <div class="cg-mi-summary-row cg-mi-summary-total">
                        <span><?php esc_html_e('Total', 'checkoutguard'); ?></span>
                        <span id="cg-mi-total"><?php echo esc_html($currency_symbol); ?>0.00</span>
                    </div>
                </div>

                <div class="cg-mi-footer">
                    <button type="button" class="button cg-mi-cancel"><?php esc_html_e('Cancel', 'checkoutguard'); ?></button>
                    <button type="submit" class="button button-primary button-hero" id="cg-mi-submit">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <?php esc_html_e('Create Invoice', 'checkoutguard'); ?>
                    </button>
                    <span class="spinner"></span>
                </div>
            </form>
        </div>
    </div>
    <?php
}


/**
 * Renders the Invoices page's "Settings" tab: numbering format, BD tax
 * fields, and footer text. The advanced fields are Pro-only; Free sites see
 * them disabled with an upgrade prompt, matching the module's server-side
 * enforcement in cg_get_invoice_settings().
 */
function cg_render_invoices_settings_tab()
{
    $settings = cg_get_invoice_settings();
    $is_pro = cg_is_pro_plan();
    ?>
    <form action="options.php" method="post">
        <?php settings_fields('cg_invoice_settings_group'); ?>

        <h2 class="cg-section-title">
            <span class="dashicons dashicons-media-spreadsheet"></span>
            <?php _e('Invoice Numbering', 'checkoutguard'); ?>
        </h2>
        <div class="cg-settings-card">
            <div class="cg-settings-field">
                <label class="cg-settings-field__label" for="cg_inv_digits">
                    <?php _e('Number Digits', 'checkoutguard'); ?>
                </label>
                <div class="cg-settings-field__input">
                    <input type="number" id="cg_inv_digits" name="cg_invoice_settings[number_digits]" min="1" max="10"
                        value="<?php echo esc_attr($settings['number_digits']); ?>" class="small-text">
                    <p class="description"><?php _e('Zero-padded digit count for the sequence number.', 'checkoutguard'); ?></p>
                </div>
            </div>
        </div>

        <h2 class="cg-section-title">
            <span class="dashicons dashicons-media-spreadsheet"></span>
            <?php _e('Advanced Numbering', 'checkoutguard'); ?>
            <?php if (!$is_pro): ?><span class="cg-pro-badge">PRO</span><?php endif; ?>
        </h2>
        <div class="cg-settings-card <?php echo !$is_pro ? 'cg-pro-locked-hover' : ''; ?>">
            <?php if (!$is_pro): ?>
                <div class="cg-pro-hover-overlay">
                    <span class="dashicons dashicons-lock"></span>
                    <p><?php _e('Upgrade to Pro to unlock custom numbering', 'checkoutguard'); ?></p>
                    <button type="button" class="button button-primary cg-open-upgrade-modal"><?php _e('Upgrade Now', 'checkoutguard'); ?></button>
                </div>
            <?php endif; ?>
            <div class="cg-settings-field">
                <label class="cg-settings-field__label" for="cg_inv_prefix">
                    <?php _e('Number Prefix', 'checkoutguard'); ?>
                </label>
                <div class="cg-settings-field__input">
                    <input type="text" id="cg_inv_prefix" name="cg_invoice_settings[number_prefix]"
                        value="<?php echo esc_attr($settings['number_prefix']); ?>" <?php disabled(!$is_pro); ?> class="regular-text">
                    <p class="description"><?php _e('E.g. "INV-" produces invoice numbers like INV-000123.', 'checkoutguard'); ?></p>
                </div>
            </div>
            <div class="cg-settings-field">
                <label class="cg-settings-field__label" for="cg_inv_reset">
                    <?php _e('Reset Sequence', 'checkoutguard'); ?>
                </label>
                <div class="cg-settings-field__input">
                    <select id="cg_inv_reset" name="cg_invoice_settings[number_reset]" <?php disabled(!$is_pro); ?>>
                        <option value="never" <?php selected($settings['number_reset'], 'never'); ?>><?php _e('Never', 'checkoutguard'); ?></option>
                        <option value="yearly" <?php selected($settings['number_reset'], 'yearly'); ?>><?php _e('Yearly', 'checkoutguard'); ?></option>
                        <option value="monthly" <?php selected($settings['number_reset'], 'monthly'); ?>><?php _e('Monthly', 'checkoutguard'); ?></option>
                    </select>
                </div>
            </div>
        </div>

        <h2 class="cg-section-title">
            <span class="dashicons dashicons-portfolio"></span>
            <?php _e('Bangladesh Tax Fields', 'checkoutguard'); ?>
            <?php if (!$is_pro): ?><span class="cg-pro-badge">PRO</span><?php endif; ?>
        </h2>
        <div class="cg-settings-card <?php echo !$is_pro ? 'cg-pro-locked-hover' : ''; ?>">
            <?php if (!$is_pro): ?>
                <div class="cg-pro-hover-overlay">
                    <span class="dashicons dashicons-lock"></span>
                    <p><?php _e('Upgrade to Pro to unlock Tax Fields', 'checkoutguard'); ?></p>
                    <button type="button" class="button button-primary cg-open-upgrade-modal"><?php _e('Upgrade Now', 'checkoutguard'); ?></button>
                </div>
            <?php endif; ?>
            <div class="cg-settings-field">
                <label class="cg-settings-field__label"><?php _e('Show Tax Fields on Invoice', 'checkoutguard'); ?></label>
                <div class="cg-settings-field__input">
                    <label class="cg-toggle">
                        <input type="checkbox" class="cg-toggle__input" name="cg_invoice_settings[show_tax_fields]"
                            value="1" <?php checked(!empty($settings['show_tax_fields'])); ?> <?php disabled(!$is_pro); ?>>
                        <span class="cg-toggle__slider"></span>
                    </label>
                </div>
            </div>
            <div class="cg-settings-field">
                <label class="cg-settings-field__label" for="cg_inv_vat"><?php _e('VAT Registration No.', 'checkoutguard'); ?></label>
                <div class="cg-settings-field__input">
                    <input type="text" id="cg_inv_vat" name="cg_invoice_settings[vat_reg_no]"
                        value="<?php echo esc_attr($settings['vat_reg_no']); ?>" <?php disabled(!$is_pro); ?> class="regular-text">
                </div>
            </div>
            <div class="cg-settings-field">
                <label class="cg-settings-field__label" for="cg_inv_tin"><?php _e('TIN', 'checkoutguard'); ?></label>
                <div class="cg-settings-field__input">
                    <input type="text" id="cg_inv_tin" name="cg_invoice_settings[tin_no]"
                        value="<?php echo esc_attr($settings['tin_no']); ?>" <?php disabled(!$is_pro); ?> class="regular-text">
                </div>
            </div>
            <div class="cg-settings-field">
                <label class="cg-settings-field__label" for="cg_inv_bin"><?php _e('BIN', 'checkoutguard'); ?></label>
                <div class="cg-settings-field__input">
                    <input type="text" id="cg_inv_bin" name="cg_invoice_settings[bin_no]"
                        value="<?php echo esc_attr($settings['bin_no']); ?>" <?php disabled(!$is_pro); ?> class="regular-text">
                </div>
            </div>
        </div>

        <h2 class="cg-section-title">
            <span class="dashicons dashicons-editor-textcolor"></span>
            <?php _e('Invoice Footer', 'checkoutguard'); ?>
            <?php if (!$is_pro): ?><span class="cg-pro-badge">PRO</span><?php endif; ?>
        </h2>
        <div class="cg-settings-card <?php echo !$is_pro ? 'cg-pro-locked-hover' : ''; ?>">
            <?php if (!$is_pro): ?>
                <div class="cg-pro-hover-overlay">
                    <span class="dashicons dashicons-lock"></span>
                    <p><?php _e('Upgrade to Pro to customize the footer', 'checkoutguard'); ?></p>
                    <button type="button" class="button button-primary cg-open-upgrade-modal"><?php _e('Upgrade Now', 'checkoutguard'); ?></button>
                </div>
            <?php endif; ?>
            <div class="cg-settings-field">
                <label class="cg-settings-field__label" for="cg_inv_footer">
                    <?php _e('Footer Text', 'checkoutguard'); ?>
                </label>
                <div class="cg-settings-field__input">
                    <input type="text" id="cg_inv_footer" name="cg_invoice_settings[footer_text]"
                        value="<?php echo esc_attr($settings['footer_text']); ?>" <?php disabled(!$is_pro); ?> class="regular-text">
                    <p class="description">
                        <?php if ($is_pro): ?>
                            <?php _e('Leave empty to remove the footer entirely.', 'checkoutguard'); ?>
                        <?php else: ?>
                            <?php _e('Free plan invoices always show "Generated by Coder Zone BD".', 'checkoutguard'); ?>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        </div>

        <?php if (!$is_pro): ?>
        <div class="cg-upgrade-section">
            <div class="cg-upgrade-icon"><span class="dashicons dashicons-star-filled"></span></div>
            <div class="cg-upgrade-text">
                <h3><?php _e('Unlock Advanced Invoicing', 'checkoutguard'); ?></h3>
                <p><?php _e('Custom numbering, BD tax fields, and footer branding are available on Pro plans.', 'checkoutguard'); ?></p>
            </div>
            <div class="cg-upgrade-actions">
                <button type="button" class="button button-primary cg-open-upgrade-modal"><?php _e('View Our Plans', 'checkoutguard'); ?></button>
            </div>
        </div>
        <?php endif; ?>

        <div class="cg-settings-submit">
            <?php submit_button(__('Save Invoice Settings', 'checkoutguard'), 'primary', 'submit', false); ?>
        </div>
    </form>
    <?php
}


/**
 * Builds the <tr> rows for the Invoices table.
 *
 * @param WC_Order[] $orders
 * @return string
 */
function cg_get_invoices_table_rows_html($orders)
{
    ob_start();
    $is_pro = cg_is_pro_plan();
    foreach ($orders as $order) {
        if (!$order instanceof WC_Order) {
            continue;
        }
        $number = $order->get_meta('_cg_invoice_number', true);
        $name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        $order_edit_link = $order->get_edit_order_url();
        ?>
        <tr id="cg-invoice-row-<?php echo esc_attr($order->get_id()); ?>">
            <td><input type="checkbox" class="cg-invoice-checkbox" value="<?php echo esc_attr($order->get_id()); ?>"></td>
            <td><a href="<?php echo esc_url($order_edit_link); ?>">#<?php echo esc_html($order->get_order_number()); ?></a></td>
            <td><?php echo esc_html($number); ?></td>
            <td><?php echo esc_html($name ?: __('(No Name)', 'checkoutguard')); ?></td>
            <td><?php echo esc_html($order->get_date_created() ? date_i18n(get_option('date_format'), $order->get_date_created()->getTimestamp()) : ''); ?></td>
            <td><?php echo wp_kses_post(wc_price($order->get_total())); ?></td>
            <td class="cg-actions-cell">
                <?php if (function_exists('cg_get_print_order_url')): ?>
                    <a href="<?php echo esc_url(cg_get_print_order_url($order->get_id())); ?>" target="_blank" rel="noopener" class="button button-small" title="<?php esc_attr_e('Print Invoice', 'checkoutguard'); ?>">
                        <span class="dashicons dashicons-media-document"></span>
                    </a>
                <?php else: ?>
                    <a href="<?php echo esc_url(cg_get_invoice_download_url($order)); ?>" target="_blank" rel="noopener" class="button button-small" title="<?php esc_attr_e('Download Invoice', 'checkoutguard'); ?>">
                        <span class="dashicons dashicons-media-spreadsheet"></span>
                    </a>
                <?php endif; ?>
                <?php if (!$is_pro): ?>
                    <span class="cg-pro-badge">PRO labels</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }
    return ob_get_clean();
}

