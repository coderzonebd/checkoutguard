<?php
// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}


/**
 * Renders the empty table shell for blocked orders (data loaded via AJAX).
 */
function cg_render_blocked_orders_table_shell()
{
    ?>
    <div id="cg-table-body-blocked_orders-empty-message" class="cg-table-empty-message">
        <p><?php _e('No orders have been blocked yet.', 'checkoutguard'); ?></p>
    </div>
    <div class="cg-table-responsive-wrapper">
        <table class="wp-list-table widefat fixed striped cg-table-blocked_orders" style="display:none;">
            <thead>
                <tr>
                    <th class="cg-sortable" data-sort-col="id"><?php _e('ID', 'checkoutguard'); ?></th>
                    <th class="cg-sortable" data-sort-col="first_name"><?php _e('Name', 'checkoutguard'); ?></th>
                    <th class="cg-sortable" data-sort-col="email_address"><?php _e('Email', 'checkoutguard'); ?></th>
                    <th><?php _e('Phone', 'checkoutguard'); ?></th>
                    <th><?php _e('IP Address', 'checkoutguard'); ?></th>
                    <th class="cg-sortable" data-sort-col="cart_value"><?php _e('Cart Value', 'checkoutguard'); ?></th>
                    <th><?php _e('Cart Items', 'checkoutguard'); ?></th>
                    <th class="cg-sortable" data-sort-col="success_ratio"><?php _e('Success Ratio (%)', 'checkoutguard'); ?></th>
                    <th class="cg-sortable" data-sort-col="blocked_at"><?php _e('Blocked At', 'checkoutguard'); ?></th>
                    <th><?php _e('Actions', 'checkoutguard'); ?></th>
                </tr>
            </thead>
            <tbody id="cg-table-body-blocked_orders">
            </tbody>
        </table>
    </div>
    <?php
}


/**
 * Renders the Fraud Blocker management page (Simplified for AJAX).
 */
function cg_render_fraud_blocker_page()
{
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'checkoutguard'));
    }

    $ajax_nonce = wp_create_nonce('cg_fraud_blocker_nonce');
    ?>
    <div class="wrap cg-fraud-blocker-wrap">
        <div class="cg-page-header">
            <h1><?php _e('Fraud Blocker Management', 'checkoutguard'); ?></h1>
            <button type="button" class="cg-dark-mode-toggle" title="<?php esc_attr_e('Toggle Dark Mode', 'checkoutguard'); ?>">
                <span class="dashicons dashicons-admin-appearance"></span>
            </button>
        </div>
        <p><?php _e('Manage blocked IP addresses, email addresses, phone numbers, domains, addresses, and devices to prevent unwanted orders.', 'checkoutguard'); ?>
        </p>

        <nav class="nav-tab-wrapper cg-fraud-tabs" role="tablist" aria-label="<?php esc_attr_e('Fraud blocker tabs', 'checkoutguard'); ?>">
            <a href="#" class="nav-tab nav-tab-active" data-tab="ip" role="tab" aria-selected="true" aria-controls="tabpanel-ip"><?php _e('IP Addresses', 'checkoutguard'); ?></a>
            <a href="#" class="nav-tab" data-tab="email" role="tab" aria-selected="false" aria-controls="tabpanel-email"><?php _e('Emails', 'checkoutguard'); ?></a>
            <a href="#" class="nav-tab" data-tab="phone" role="tab" aria-selected="false" aria-controls="tabpanel-phone"><?php _e('Phones', 'checkoutguard'); ?></a>
            <a href="#" class="nav-tab<?php echo cg_is_pro_plan() ? '' : ' cg-pro-locked-tab'; ?>" data-tab="domain" role="tab" aria-selected="false" aria-controls="tabpanel-domain">
                <?php _e('Domains', 'checkoutguard'); ?>
                <?php if (!cg_is_pro_plan()): ?>
                    <span class="dashicons dashicons-lock cg-pro-tab-lock" data-cg-tooltip="<?php esc_attr_e('Upgrade to Pro to unlock', 'checkoutguard'); ?>"></span>
                <?php endif; ?>
            </a>
            <a href="#" class="nav-tab<?php echo cg_is_pro_plan() ? '' : ' cg-pro-locked-tab'; ?>" data-tab="address" role="tab" aria-selected="false" aria-controls="tabpanel-address">
                <?php _e('Addresses', 'checkoutguard'); ?>
                <?php if (!cg_is_pro_plan()): ?>
                    <span class="dashicons dashicons-lock cg-pro-tab-lock" data-cg-tooltip="<?php esc_attr_e('Upgrade to Pro to unlock', 'checkoutguard'); ?>"></span>
                <?php endif; ?>
            </a>
            <a href="#" class="nav-tab<?php echo cg_is_pro_plan() ? '' : ' cg-pro-locked-tab'; ?>" data-tab="device" role="tab" aria-selected="false" aria-controls="tabpanel-device">
                <?php _e('Devices', 'checkoutguard'); ?>
                <?php if (!cg_is_pro_plan()): ?>
                    <span class="dashicons dashicons-lock cg-pro-tab-lock" data-cg-tooltip="<?php esc_attr_e('Upgrade to Pro to unlock', 'checkoutguard'); ?>"></span>
                <?php endif; ?>
            </a>
            <a href="#" class="nav-tab" data-tab="fraud-settings" role="tab" aria-selected="false" aria-controls="tabpanel-fraud-settings"><?php _e('Settings', 'checkoutguard'); ?></a>
        </nav>

            <?php // Section 1: IP Blocker ?>
            <div class="cg-tab-pane cg-fraud-pane" data-tab-content="ip" id="tabpanel-ip" role="tabpanel">
            <div class="cg-blocker-section cg-blocker-section-tab">
                <h2><?php _e('IP Address Blocker', 'checkoutguard'); ?></h2>
                <form class="cg-blocker-form" data-block-type="ip">
                    <input type="hidden" name="nonce" value="<?php echo esc_attr($ajax_nonce); ?>">
                    <p><label
                            for="cg_block_ip"><?php _e('IP Address to Block:', 'checkoutguard'); ?></label><br><input
                            type="text" name="value" class="regular-text" placeholder="e.g., 192.168.1.100"></p>
                    <p><label
                            for="cg_block_ip_reason"><?php _e('Reason (Optional):', 'checkoutguard'); ?></label><br><textarea
                            name="reason" rows="2" class="large-text"></textarea></p>
                    <p><label><?php _e('Expires After:', 'checkoutguard'); ?></label><br>
                        <select name="expires_in">
                            <option value="0"><?php _e('Never (Permanent)', 'checkoutguard'); ?></option>
                            <option value="7"><?php _e('7 Days', 'checkoutguard'); ?></option>
                            <option value="30"><?php _e('30 Days', 'checkoutguard'); ?></option>
                            <option value="90"><?php _e('90 Days', 'checkoutguard'); ?></option>
                        </select>
                    </p>
                    <p><button type="submit"
                            class="button button-primary"><?php _e('Block IP Address', 'checkoutguard'); ?></button><span
                            class="spinner"></span></p>
                </form>
                <h3><?php _e('Blocked IP Addresses', 'checkoutguard'); ?>
                    <span class="cg-import-export-btns">
                        <button type="button" class="button button-small cg-export-btn" data-block-type="ip"><?php _e('Export', 'checkoutguard'); ?></button>
                        <button type="button" class="button button-small cg-import-btn" data-block-type="ip"><?php _e('Import', 'checkoutguard'); ?></button>
                    </span>
                </h3>
                <p class="cg-search-wrapper">
                    <input type="search" class="cg-blocker-search" data-list-id="cg-blocked-list-ip"
                        placeholder="<?php esc_attr_e('Search IPs...', 'checkoutguard'); ?>">
                </p>
                <?php cg_display_blocked_items_list('ip'); ?>
            </div>
            </div>

            <?php // Section 2: Email Blocker ?>
            <div class="cg-tab-pane cg-fraud-pane" data-tab-content="email" style="display:none;" id="tabpanel-email" role="tabpanel">
            <div class="cg-blocker-section cg-blocker-section-tab">
                <h2><?php _e('Email Address Blocker', 'checkoutguard'); ?></h2>
                <form class="cg-blocker-form" data-block-type="email">
                    <input type="hidden" name="nonce" value="<?php echo esc_attr($ajax_nonce); ?>">
                    <p><label
                            for="cg_block_email"><?php _e('Email Address to Block:', 'checkoutguard'); ?></label><br><input
                            type="email" name="value" class="regular-text" placeholder="e.g., spammer@example.com"></p>
                    <p><label
                            for="cg_block_email_reason"><?php _e('Reason (Optional):', 'checkoutguard'); ?></label><br><textarea
                            name="reason" rows="2" class="large-text"></textarea></p>
                    <p><label><?php _e('Expires After:', 'checkoutguard'); ?></label><br>
                        <select name="expires_in">
                            <option value="0"><?php _e('Never (Permanent)', 'checkoutguard'); ?></option>
                            <option value="7"><?php _e('7 Days', 'checkoutguard'); ?></option>
                            <option value="30"><?php _e('30 Days', 'checkoutguard'); ?></option>
                            <option value="90"><?php _e('90 Days', 'checkoutguard'); ?></option>
                        </select>
                    </p>
                    <p><button type="submit"
                            class="button button-primary"><?php _e('Block Email Address', 'checkoutguard'); ?></button><span
                            class="spinner"></span></p>
                </form>
                <h3><?php _e('Blocked Email Addresses', 'checkoutguard'); ?>
                    <span class="cg-import-export-btns">
                        <button type="button" class="button button-small cg-export-btn" data-block-type="email"><?php _e('Export', 'checkoutguard'); ?></button>
                        <button type="button" class="button button-small cg-import-btn" data-block-type="email"><?php _e('Import', 'checkoutguard'); ?></button>
                    </span>
                </h3>
                <p class="cg-search-wrapper">
                    <input type="search" class="cg-blocker-search" data-list-id="cg-blocked-list-email"
                        placeholder="<?php esc_attr_e('Search Emails...', 'checkoutguard'); ?>">
                </p>
                <?php cg_display_blocked_items_list('email'); ?>
            </div>
            </div>

            <?php // Section 3: Phone Blocker ?>
            <div class="cg-tab-pane cg-fraud-pane" data-tab-content="phone" style="display:none;" id="tabpanel-phone" role="tabpanel">
            <div class="cg-blocker-section cg-blocker-section-tab">
                <h2><?php _e('Phone Number Blocker', 'checkoutguard'); ?></h2>
                <form class="cg-blocker-form" data-block-type="phone">
                    <input type="hidden" name="nonce" value="<?php echo esc_attr($ajax_nonce); ?>">
                    <p><label
                            for="cg_block_phone"><?php _e('Phone Number to Block:', 'checkoutguard'); ?></label><br><input
                            type="text" name="value" class="regular-text" placeholder="e.g., +15551234567"></p>
                    <p><label
                            for="cg_block_phone_reason"><?php _e('Reason (Optional):', 'checkoutguard'); ?></label><br><textarea
                            name="reason" rows="2" class="large-text"></textarea></p>
                    <p><label><?php _e('Expires After:', 'checkoutguard'); ?></label><br>
                        <select name="expires_in">
                            <option value="0"><?php _e('Never (Permanent)', 'checkoutguard'); ?></option>
                            <option value="7"><?php _e('7 Days', 'checkoutguard'); ?></option>
                            <option value="30"><?php _e('30 Days', 'checkoutguard'); ?></option>
                            <option value="90"><?php _e('90 Days', 'checkoutguard'); ?></option>
                        </select>
                    </p>
                    <p><button type="submit"
                            class="button button-primary"><?php _e('Block Phone Number', 'checkoutguard'); ?></button><span
                            class="spinner"></span></p>
                </form>
                <h3><?php _e('Blocked Phone Numbers', 'checkoutguard'); ?>
                    <span class="cg-import-export-btns">
                        <button type="button" class="button button-small cg-export-btn" data-block-type="phone"><?php _e('Export', 'checkoutguard'); ?></button>
                        <button type="button" class="button button-small cg-import-btn" data-block-type="phone"><?php _e('Import', 'checkoutguard'); ?></button>
                    </span>
                </h3>
                <p class="cg-search-wrapper">
                    <input type="search" class="cg-blocker-search" data-list-id="cg-blocked-list-phone"
                        placeholder="<?php esc_attr_e('Search Phones...', 'checkoutguard'); ?>">
                </p>
                <?php cg_display_blocked_items_list('phone'); ?>
            </div>
            </div>

            <?php // Section 4: Email Domain Blocker ?>
            <div class="cg-tab-pane cg-fraud-pane<?php echo cg_is_pro_plan() ? '' : ' cg-pro-locked-hover'; ?>" data-tab-content="domain" style="display:none;" id="tabpanel-domain" role="tabpanel">
            <?php if (!cg_is_pro_plan()): ?>
                <div class="cg-pro-hover-overlay">
                    <span class="dashicons dashicons-lock"></span>
                    <p><?php _e('Upgrade to Pro to unlock Domain Blocking', 'checkoutguard'); ?></p>
                    <button type="button" class="button button-primary cg-open-upgrade-modal"><?php _e('Upgrade Now', 'checkoutguard'); ?></button>
                </div>
            <?php endif; ?>
            <div class="cg-blocker-section cg-blocker-section-tab">
                <h2><?php _e('Email Domain Blocker', 'checkoutguard'); ?></h2>
                <form class="cg-blocker-form" data-block-type="domain">
                    <input type="hidden" name="nonce" value="<?php echo esc_attr($ajax_nonce); ?>">
                    <p><label><?php _e('Domain to Block:', 'checkoutguard'); ?></label><br><input
                            type="text" name="value" class="regular-text" placeholder="e.g., tempmail.com"></p>
                    <p class="description"><?php _e('Block all emails from this domain. Do not include the @ symbol.', 'checkoutguard'); ?></p>
                    <p><label><?php _e('Reason (Optional):', 'checkoutguard'); ?></label><br><textarea
                            name="reason" rows="2" class="large-text"></textarea></p>
                    <p><label><?php _e('Expires After:', 'checkoutguard'); ?></label><br>
                        <select name="expires_in">
                            <option value="0"><?php _e('Never (Permanent)', 'checkoutguard'); ?></option>
                            <option value="7"><?php _e('7 Days', 'checkoutguard'); ?></option>
                            <option value="30"><?php _e('30 Days', 'checkoutguard'); ?></option>
                            <option value="90"><?php _e('90 Days', 'checkoutguard'); ?></option>
                        </select>
                    </p>
                    <p><button type="submit"
                            class="button button-primary"><?php _e('Block Domain', 'checkoutguard'); ?></button><span
                            class="spinner"></span></p>
                </form>
                <h3><?php _e('Blocked Domains', 'checkoutguard'); ?>
                    <span class="cg-import-export-btns">
                        <button type="button" class="button button-small cg-export-btn" data-block-type="domain"><?php _e('Export', 'checkoutguard'); ?></button>
                        <button type="button" class="button button-small cg-import-btn" data-block-type="domain"><?php _e('Import', 'checkoutguard'); ?></button>
                    </span>
                </h3>
                <p class="cg-search-wrapper">
                    <input type="search" class="cg-blocker-search" data-list-id="cg-blocked-list-domain"
                        placeholder="<?php esc_attr_e('Search Domains...', 'checkoutguard'); ?>">
                </p>
                <?php cg_display_blocked_items_list('domain'); ?>
            </div>
            </div>

            <?php // Section 5: Address Blocker ?>
            <div class="cg-tab-pane cg-fraud-pane<?php echo cg_is_pro_plan() ? '' : ' cg-pro-locked-hover'; ?>" data-tab-content="address" style="display:none;" id="tabpanel-address" role="tabpanel">
            <?php if (!cg_is_pro_plan()): ?>
                <div class="cg-pro-hover-overlay">
                    <span class="dashicons dashicons-lock"></span>
                    <p><?php _e('Upgrade to Pro to unlock Address Blocking', 'checkoutguard'); ?></p>
                    <button type="button" class="button button-primary cg-open-upgrade-modal"><?php _e('Upgrade Now', 'checkoutguard'); ?></button>
                </div>
            <?php endif; ?>
            <div class="cg-blocker-section cg-blocker-section-tab">
                <h2><?php _e('Address Blocker', 'checkoutguard'); ?></h2>
                <form class="cg-blocker-form cg-address-form" data-block-type="address">
                    <input type="hidden" name="nonce" value="<?php echo esc_attr($ajax_nonce); ?>">
                    <p><label><?php _e('Address Line 1:', 'checkoutguard'); ?></label><br><input
                            type="text" name="address_1" class="regular-text" placeholder="e.g., 123 Main Street"></p>
                    <p><label><?php _e('City:', 'checkoutguard'); ?></label><br><input
                            type="text" name="city" class="regular-text" placeholder="e.g., Dhaka"></p>
                    <p><label><?php _e('Postcode:', 'checkoutguard'); ?></label><br><input
                            type="text" name="postcode" class="regular-text" placeholder="e.g., 1205"></p>
                    <p class="description"><?php _e('The address will be normalized and hashed for matching. Small formatting differences will be ignored.', 'checkoutguard'); ?></p>
                    <p><label><?php _e('Reason (Optional):', 'checkoutguard'); ?></label><br><textarea
                            name="reason" rows="2" class="large-text"></textarea></p>
                    <p><label><?php _e('Expires After:', 'checkoutguard'); ?></label><br>
                        <select name="expires_in">
                            <option value="0"><?php _e('Never (Permanent)', 'checkoutguard'); ?></option>
                            <option value="7"><?php _e('7 Days', 'checkoutguard'); ?></option>
                            <option value="30"><?php _e('30 Days', 'checkoutguard'); ?></option>
                            <option value="90"><?php _e('90 Days', 'checkoutguard'); ?></option>
                        </select>
                    </p>
                    <p><button type="submit"
                            class="button button-primary"><?php _e('Block Address', 'checkoutguard'); ?></button><span
                            class="spinner"></span></p>
                </form>
                <h3><?php _e('Blocked Addresses', 'checkoutguard'); ?>
                    <span class="cg-import-export-btns">
                        <button type="button" class="button button-small cg-export-btn" data-block-type="address"><?php _e('Export', 'checkoutguard'); ?></button>
                    </span>
                </h3>
                <p class="cg-search-wrapper">
                    <input type="search" class="cg-blocker-search" data-list-id="cg-blocked-list-address"
                        placeholder="<?php esc_attr_e('Search Addresses...', 'checkoutguard'); ?>">
                </p>
                <?php cg_display_blocked_items_list('address'); ?>
            </div>
            </div>

            <?php // Section 6: Device Blocker ?>
            <div class="cg-tab-pane cg-fraud-pane<?php echo cg_is_pro_plan() ? '' : ' cg-pro-locked-hover'; ?>" data-tab-content="device" style="display:none;" id="tabpanel-device" role="tabpanel">
            <?php if (!cg_is_pro_plan()): ?>
                <div class="cg-pro-hover-overlay">
                    <span class="dashicons dashicons-lock"></span>
                    <p><?php _e('Upgrade to Pro to unlock Device Blocking', 'checkoutguard'); ?></p>
                    <button type="button" class="button button-primary cg-open-upgrade-modal"><?php _e('Upgrade Now', 'checkoutguard'); ?></button>
                </div>
            <?php endif; ?>
            <div class="cg-blocker-section cg-blocker-section-tab">
                <h2><?php _e('Device Blocker', 'checkoutguard'); ?></h2>
                <form class="cg-blocker-form" data-block-type="device">
                    <input type="hidden" name="nonce" value="<?php echo esc_attr($ajax_nonce); ?>">
                    <p><label><?php _e('Device Fingerprint:', 'checkoutguard'); ?></label><br><input
                            type="text" name="value" class="regular-text" placeholder="<?php esc_attr_e('Device fingerprint hash', 'checkoutguard'); ?>"></p>
                    <p class="description"><?php _e('Device fingerprints are automatically captured at checkout. You can find them in order details. Blocked devices cannot place orders, and optionally cannot access the site at all.', 'checkoutguard'); ?></p>
                    <p><label><?php _e('Reason (Optional):', 'checkoutguard'); ?></label><br><textarea
                            name="reason" rows="2" class="large-text"></textarea></p>
                    <p><label><?php _e('Expires After:', 'checkoutguard'); ?></label><br>
                        <select name="expires_in">
                            <option value="0"><?php _e('Never (Permanent)', 'checkoutguard'); ?></option>
                            <option value="7"><?php _e('7 Days', 'checkoutguard'); ?></option>
                            <option value="30"><?php _e('30 Days', 'checkoutguard'); ?></option>
                            <option value="90"><?php _e('90 Days', 'checkoutguard'); ?></option>
                        </select>
                    </p>
                    <p><button type="submit"
                            class="button button-primary"><?php _e('Block Device', 'checkoutguard'); ?></button><span
                            class="spinner"></span></p>
                </form>
                <h3><?php _e('Blocked Devices', 'checkoutguard'); ?>
                    <span class="cg-import-export-btns">
                        <button type="button" class="button button-small cg-export-btn" data-block-type="device"><?php _e('Export', 'checkoutguard'); ?></button>
                    </span>
                </h3>
                <p class="cg-search-wrapper">
                    <input type="search" class="cg-blocker-search" data-list-id="cg-blocked-list-device"
                        placeholder="<?php esc_attr_e('Search Devices...', 'checkoutguard'); ?>">
                </p>
                <?php cg_display_blocked_items_list('device'); ?>
            </div>
            </div>

        <?php // Import Modal (shared) ?>
        <div id="cg-import-modal" style="display:none;" class="cg-modal-backdrop">
            <div class="cg-modal">
                <h3><?php _e('Import Blocklist', 'checkoutguard'); ?></h3>
                <p class="description"><?php _e('Paste CSV data below. First column is the value, second column (optional) is the reason. One entry per line.', 'checkoutguard'); ?></p>
                <textarea id="cg-import-csv-data" rows="10" class="large-text" placeholder="<?php esc_attr_e("192.168.1.1,Spammer\n10.0.0.1,Fraudulent orders", 'checkoutguard'); ?>"></textarea>
                <input type="hidden" id="cg-import-block-type" value="">
                <p>
                    <button type="button" id="cg-import-submit" class="button button-primary"><?php _e('Import', 'checkoutguard'); ?></button>
                    <button type="button" id="cg-import-cancel" class="button"><?php _e('Cancel', 'checkoutguard'); ?></button>
                    <span class="spinner"></span>
                </p>
            </div>
        </div>

        <?php // Fraud Blocker Settings Panel (Enhanced) ?>
        <?php
        $cg_options = get_option('cg_plugin_options', []);
        $block_message = $cg_options['custom_block_message'] ?? __('Your order cannot be processed at this time. Please contact support.', 'checkoutguard');
        ?>
        <div class="cg-tab-pane cg-fraud-pane" data-tab-content="fraud-settings" style="display:none;" id="tabpanel-fraud-settings" role="tabpanel">
        <div class="cg-inline-settings-panel cg-inline-settings-panel-tab">
            <h2><?php _e('Fraud Blocker Settings', 'checkoutguard'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="cg_custom_block_message"><?php _e('Custom Block Message', 'checkoutguard'); ?></label></th>
                    <td>
                        <textarea id="cg_custom_block_message" rows="4" class="large-text"><?php echo esc_textarea($block_message); ?></textarea>
                        <p class="description"><?php _e('The message shown to customers who are blocked by IP, email, phone, domain, address, or device.', 'checkoutguard'); ?></p>
                    </td>
                </tr>
            </table>

            <h3><?php _e('Duplicate Order Blocker', 'checkoutguard'); ?></h3>
            <p class="description"><?php _e('Prevent customers from submitting identical or duplicate orders repeatedly within a short time window.', 'checkoutguard'); ?></p>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="cg_duplicate_blocker_enabled"><?php _e('Enable Duplicate Blocker', 'checkoutguard'); ?></label></th>
                    <td>
                        <label><input type="checkbox" id="cg_duplicate_blocker_enabled" value="1" <?php checked(cg_duplicate_blocker_switch($cg_options, 'duplicate_blocker_enabled')); ?>>
                        <?php _e('Block rapid duplicate checkout submissions.', 'checkoutguard'); ?></label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="cg_duplicate_blocker_window"><?php _e('Time Window', 'checkoutguard'); ?></label></th>
                    <td>
                        <select id="cg_duplicate_blocker_window">
                            <?php
                            $dup_window = (int) ($cg_options['duplicate_blocker_window'] ?? 10);
                            $windows = [5 => '5 Minutes', 10 => '10 Minutes', 15 => '15 Minutes', 30 => '30 Minutes', 60 => '1 Hour'];
                            foreach ($windows as $mins => $label) {
                                echo '<option value="' . esc_attr($mins) . '"' . selected($dup_window, $mins, false) . '>' . esc_html($label) . '</option>';
                            }
                            ?>
                        </select>
                        <p class="description"><?php _e('Check for recent orders placed within this time window.', 'checkoutguard'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="cg_duplicate_blocker_mode"><?php _e('Detection Mode', 'checkoutguard'); ?></label></th>
                    <td>
                        <select id="cg_duplicate_blocker_mode">
                            <?php $dup_mode = $cg_options['duplicate_blocker_mode'] ?? 'identical'; ?>
                            <option value="identical" <?php selected($dup_mode, 'identical'); ?>><?php _e('Smart (Block identical cart products & quantities only)', 'checkoutguard'); ?></option>
                            <option value="any" <?php selected($dup_mode, 'any'); ?>><?php _e('Strict (Block any order from the same customer)', 'checkoutguard'); ?></option>
                        </select>
                        <p class="description"><?php _e('Smart mode first matches the cart items; if they do not match, it matches by order total.', 'checkoutguard'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label><?php _e('Matching Criteria', 'checkoutguard'); ?></label></th>
                    <td>
                        <fieldset>
                            <label style="margin-right: 15px;">
                                <input type="checkbox" id="cg_duplicate_blocker_match_phone" value="1" <?php checked(!isset($cg_options['duplicate_blocker_match_phone']) || !empty($cg_options['duplicate_blocker_match_phone'])); ?>>
                                <?php _e('Phone Number (Normalized)', 'checkoutguard'); ?>
                            </label>
                            <label style="margin-right: 15px;">
                                <input type="checkbox" id="cg_duplicate_blocker_match_device" value="1" <?php checked(!isset($cg_options['duplicate_blocker_match_device']) || !empty($cg_options['duplicate_blocker_match_device'])); ?>>
                                <?php _e('Device Fingerprint (Browser/Hardware)', 'checkoutguard'); ?>
                            </label>
                            <label style="margin-right: 15px;">
                                <input type="checkbox" id="cg_duplicate_blocker_match_ip" value="1" <?php checked(!isset($cg_options['duplicate_blocker_match_ip']) || !empty($cg_options['duplicate_blocker_match_ip'])); ?>>
                                <?php _e('IP Address', 'checkoutguard'); ?>
                            </label>
                            <label>
                                <input type="checkbox" id="cg_duplicate_blocker_match_email" value="1" <?php checked(!isset($cg_options['duplicate_blocker_match_email']) || !empty($cg_options['duplicate_blocker_match_email'])); ?>>
                                <?php _e('Email Address', 'checkoutguard'); ?>
                            </label>
                        </fieldset>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="cg_duplicate_blocker_message">
                            <?php _e('Custom Notice Message', 'checkoutguard'); ?>
                            <?php if (!cg_is_pro_plan()): ?>
                                <span class="cg-pro-badge" data-cg-tooltip="<?php esc_attr_e('Upgrade to Pro to customize notice message', 'checkoutguard'); ?>" style="cursor:help;">PRO</span>
                            <?php endif; ?>
                        </label>
                    </th>
                    <td>
                        <?php
                        $default_dup_msg = __('You have already placed an order recently. Please wait a few moments before ordering again, or contact us if you need to modify your order.', 'checkoutguard');
                        $saved_dup_msg = $cg_options['duplicate_blocker_message'] ?? '';
                        $is_pro = cg_is_pro_plan();
                        ?>
                        <div class="<?php echo !$is_pro ? 'cg-pro-locked-hover' : ''; ?>" style="position:relative; max-width:650px; min-height:125px; border-radius:8px;">
                            <?php if (!$is_pro): ?>
                                <div class="cg-pro-hover-overlay">
                                    <span class="dashicons dashicons-lock"></span>
                                    <p><?php _e('Upgrade to Pro to customize notice message', 'checkoutguard'); ?></p>
                                    <button type="button" class="button button-primary cg-open-upgrade-modal"><?php _e('Upgrade Now', 'checkoutguard'); ?></button>
                                </div>
                            <?php endif; ?>
                            <textarea id="cg_duplicate_blocker_message" rows="3" class="large-text" <?php echo !$is_pro ? 'disabled' : ''; ?> placeholder="<?php echo esc_attr($default_dup_msg); ?>"><?php echo esc_textarea($saved_dup_msg); ?></textarea>
                            <p class="description">
                                <?php if (!$is_pro): ?>
                                    <strong><?php _e('Custom message requires a Pro plan.', 'checkoutguard'); ?></strong> <?php _e('Free plan uses the default notice above. Available placeholders: {time_window}, {phone}. The order number is never shown to the customer.', 'checkoutguard'); ?>
                                <?php else: ?>
                                    <?php _e('Leave blank to use default. Available placeholders: {time_window}, {phone}. The order number is never shown to the customer.', 'checkoutguard'); ?>
                                <?php endif; ?>
                            </p>
                        </div>
                    </td>
                </tr>
            </table>

            <?php if (!cg_is_pro_plan()): ?>
            <div class="cg-pro-locked-hover">
                <div class="cg-pro-hover-overlay">
                    <span class="dashicons dashicons-lock"></span>
                    <p><?php _e('Upgrade to Pro to unlock advanced settings', 'checkoutguard'); ?></p>
                    <button type="button" class="button button-primary cg-open-upgrade-modal"><?php _e('Upgrade Now', 'checkoutguard'); ?></button>
                </div>
            <?php endif; ?>

            <h3><?php _e('Auto-Block Repeat Offenders', 'checkoutguard'); ?></h3>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label><?php _e('Enable Auto-Block', 'checkoutguard'); ?></label></th>
                    <td>
                        <label><input type="checkbox" id="cg_fraud_auto_block_enabled" value="1" <?php checked(!empty($cg_options['fraud_auto_block_enabled'])); ?>>
                        <?php _e('Automatically block IP/email/phone after repeated failed attempts.', 'checkoutguard'); ?></label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label><?php _e('Block After', 'checkoutguard'); ?></label></th>
                    <td>
                        <input type="number" id="cg_fraud_auto_block_attempts" class="small-text" min="2" max="50" value="<?php echo esc_attr($cg_options['fraud_auto_block_attempts'] ?? 3); ?>"> <?php _e('failed attempts', 'checkoutguard'); ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label><?php _e('Within Time Window', 'checkoutguard'); ?></label></th>
                    <td>
                        <select id="cg_fraud_auto_block_window">
                            <?php
                            $window_val = $cg_options['fraud_auto_block_window'] ?? 1440;
                            $windows = [60 => '1 Hour', 360 => '6 Hours', 1440 => '24 Hours', 10080 => '7 Days'];
                            foreach ($windows as $mins => $label) {
                                echo '<option value="' . esc_attr($mins) . '"' . selected($window_val, $mins, false) . '>' . esc_html($label) . '</option>';
                            }
                            ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label><?php _e('Auto-Block Duration', 'checkoutguard'); ?></label></th>
                    <td>
                        <select id="cg_fraud_auto_block_duration">
                            <?php
                            $dur_val = $cg_options['fraud_auto_block_duration'] ?? 30;
                            $durations = [7 => '7 Days', 30 => '30 Days', 90 => '90 Days', 0 => 'Permanent'];
                            foreach ($durations as $days => $label) {
                                echo '<option value="' . esc_attr($days) . '"' . selected($dur_val, $days, false) . '>' . esc_html($label) . '</option>';
                            }
                            ?>
                        </select>
                    </td>
                </tr>
            </table>

            <h3><?php _e('Rate Limiting', 'checkoutguard'); ?></h3>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label><?php _e('Enable Rate Limiting', 'checkoutguard'); ?></label></th>
                    <td>
                        <label><input type="checkbox" id="cg_fraud_velocity_enabled" value="1" <?php checked(!empty($cg_options['fraud_velocity_enabled'])); ?>>
                        <?php _e('Limit the number of checkout attempts per time window.', 'checkoutguard'); ?></label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label><?php _e('Max Attempts', 'checkoutguard'); ?></label></th>
                    <td>
                        <input type="number" id="cg_fraud_velocity_max_attempts" class="small-text" min="2" max="100" value="<?php echo esc_attr($cg_options['fraud_velocity_max_attempts'] ?? 5); ?>"> <?php _e('attempts per window', 'checkoutguard'); ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label><?php _e('Time Window', 'checkoutguard'); ?></label></th>
                    <td>
                        <select id="cg_fraud_velocity_window">
                            <?php
                            $vel_window = $cg_options['fraud_velocity_window'] ?? 60;
                            $vel_windows = [15 => '15 Minutes', 30 => '30 Minutes', 60 => '1 Hour', 360 => '6 Hours'];
                            foreach ($vel_windows as $mins => $label) {
                                echo '<option value="' . esc_attr($mins) . '"' . selected($vel_window, $mins, false) . '>' . esc_html($label) . '</option>';
                            }
                            ?>
                        </select>
                    </td>
                </tr>
            </table>

            <h3><?php _e('Device Blocking', 'checkoutguard'); ?></h3>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label><?php _e('Enable Device Fingerprinting', 'checkoutguard'); ?></label></th>
                    <td>
                        <label><input type="checkbox" id="cg_fraud_device_blocking" value="1" <?php checked(!empty($cg_options['fraud_device_blocking'])); ?>>
                        <?php _e('Capture browser fingerprints and allow device-level blocking.', 'checkoutguard'); ?></label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label><?php _e('Site-Wide Device Block', 'checkoutguard'); ?></label></th>
                    <td>
                        <label><input type="checkbox" id="cg_fraud_device_site_block" value="1" <?php checked(!empty($cg_options['fraud_device_site_block'])); ?>>
                        <?php _e('Completely block site access for blocked devices (not just checkout).', 'checkoutguard'); ?></label>
                        <p class="description"><?php _e('When enabled, blocked devices will see a 403 Access Denied page on every page of the site.', 'checkoutguard'); ?></p>
                    </td>
                </tr>
            </table>

            <h3><?php _e('Risk Scoring (Advanced)', 'checkoutguard'); ?></h3>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label><?php _e('Enable Risk Scoring', 'checkoutguard'); ?></label></th>
                    <td>
                        <label><input type="checkbox" id="cg_fraud_risk_scoring_enabled" value="1" <?php checked(!empty($cg_options['fraud_risk_scoring_enabled'])); ?>>
                        <?php _e('Calculate a fraud risk score (0-100) based on multiple signals.', 'checkoutguard'); ?></label>
                        <p class="description"><?php _e('Combines courier ratio, recent blocks, velocity, email domain reputation, and device history into a single score.', 'checkoutguard'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label><?php _e('Block Threshold', 'checkoutguard'); ?></label></th>
                    <td>
                        <input type="number" id="cg_fraud_risk_score_threshold" class="small-text" min="10" max="100" value="<?php echo esc_attr($cg_options['fraud_risk_score_threshold'] ?? 70); ?>"> / 100
                        <p class="description"><?php _e('Orders with a risk score at or above this threshold will be blocked.', 'checkoutguard'); ?></p>
                    </td>
                </tr>
            </table>

            <?php if (!cg_is_pro_plan()): ?>
            </div><!-- .cg-pro-locked-hover -->
            <?php endif; ?>

            <p>
                <button type="button" id="cg-save-fraud-settings" class="button button-primary"><?php _e('Save Settings', 'checkoutguard'); ?></button>
                <span class="spinner"></span>
            </p>
        </div>
        </div>
    </div>
    <?php cg_render_upgrade_modal_html(); ?>
    <?php
}


/**
 * Helper function to display a list of blocked items.
 * This function now fetches its own data.
 */
function cg_display_blocked_items_list($type)
{
    global $wpdb;
    $table_name = '';
    $value_column = '';

    switch ($type) {
        case 'ip':
            $table_name = $wpdb->prefix . 'cg_blocked_ips';
            $value_column = 'ip_address';
            break;
        case 'email':
            $table_name = $wpdb->prefix . 'cg_blocked_emails';
            $value_column = 'email_address';
            break;
        case 'phone':
            $table_name = $wpdb->prefix . 'cg_blocked_numbers';
            $value_column = 'phone_number';
            break;
        case 'domain':
            $table_name = $wpdb->prefix . 'cg_blocked_domains';
            $value_column = 'domain';
            break;
        case 'address':
            $table_name = $wpdb->prefix . 'cg_blocked_addresses';
            $value_column = 'address_text';
            break;
        case 'device':
            $table_name = $wpdb->prefix . 'cg_blocked_devices';
            $value_column = 'device_fingerprint';
            break;
        default:
            return;
    }

    $items = $wpdb->get_results("SELECT * FROM {$table_name} ORDER BY created_at DESC");

    $bulk_nonce = wp_create_nonce('cg_bulk_delete_blocked_items_nonce');
    echo '<div class="cg-bulk-actions" data-block-type="' . esc_attr($type) . '" data-list-id="cg-blocked-list-' . esc_attr($type) . '" data-nonce="' . esc_attr($bulk_nonce) . '"' . (empty($items) ? ' style="display:none;"' : '') . '>';
    echo '<label class="cg-bulk-select-all-label"><input type="checkbox" class="cg-bulk-select-all"> ' . __('Select All', 'checkoutguard') . '</label>';
    echo '<button type="button" class="button button-small cg-bulk-delete-btn" disabled><span class="dashicons dashicons-trash"></span> ' . __('Delete Selected', 'checkoutguard') . '</button>';
    echo '<span class="cg-bulk-count"></span>';
    echo '<span class="spinner"></span>';
    echo '</div>';

    echo '<ul class="cg-blocked-list" id="cg-blocked-list-' . esc_attr($type) . '">';
    if (empty($items)) {
        echo '<li class="cg-no-items">' . __('No items currently blocked.', 'checkoutguard') . '</li>';
    } else {
        foreach ($items as $item) {
            echo cg_get_blocked_list_item_html($item, $value_column, $type);
        }
    }
    echo '</ul>';
}


/**
 * Helper function to generate HTML for a single blocked list item.
 */
function cg_get_blocked_list_item_html($item, $value_column, $type)
{
    ob_start();
    $delete_nonce = wp_create_nonce('cg_delete_blocked_item_nonce');

    // For device type, show a friendly name + hash together
    $display_value = $item->$value_column;
    if ($type === 'device') {
        $device_name = '';
        if (!empty($item->device_info) && function_exists('cg_get_device_name_from_ua')) {
            $device_name = cg_get_device_name_from_ua($item->device_info);
        }
        $short_fp = substr($item->device_fingerprint, 0, 12) . '...';
        $display_value = $device_name ? $device_name . ' (' . $short_fp . ')' : $short_fp;
    }
    ?>
    <li id="cg-blocked-item-<?php echo esc_attr($item->id); ?>">
        <label class="cg-bulk-checkbox-label"><input type="checkbox" class="cg-bulk-checkbox" value="<?php echo esc_attr($item->id); ?>"></label>
        <div>
            <strong><?php echo esc_html($display_value); ?></strong>
            <?php if (!empty($item->auto_blocked)): ?>
                <span class="cg-badge cg-badge-auto"><?php _e('Auto', 'checkoutguard'); ?></span>
            <?php endif; ?>
            <?php if (!empty($item->expires_at)): ?>
                <span class="cg-badge cg-badge-expires" title="<?php echo esc_attr(sprintf(__('Expires: %s', 'checkoutguard'), $item->expires_at)); ?>">
                    <?php echo esc_html(human_time_diff(strtotime($item->expires_at))); ?>
                </span>
            <?php endif; ?>
            <?php if (!empty($item->reason)): ?>
                <small><em><?php echo esc_html($item->reason); ?></em></small>
            <?php endif; ?>
        </div>
        <a href="#" class="cg-delete-item-ajax" title="Delete" data-item-id="<?php echo esc_attr($item->id); ?>"
            data-block-type="<?php echo esc_attr($type); ?>" data-nonce="<?php echo esc_attr($delete_nonce); ?>">
            <span class="dashicons dashicons-trash"></span>
        </a>
    </li>
    <?php
    return ob_get_clean();
}

/**
 * Helper function to generate HTML for blocked order table rows.
 * This version includes safety checks and correct data attributes.
 */
/**
 * Renders inline block/unblock buttons for a popup detail view.
 * Checks whether each identifier (IP, email, phone, device) is currently blocked.
 * Email and phone buttons carry only $ref (e.g. "checkout:12"), never the
 * value; without a $ref they are not shown.
 */
function cg_render_popup_block_buttons($ip, $email, $phone, $device_fp = '', $address_1 = '', $city = '', $postcode = '', $ref = '')
{
    global $wpdb;
    $now = current_time('mysql');
    $block_nonce = wp_create_nonce('cg_fraud_blocker_nonce');
    $unblock_nonce = wp_create_nonce('cg_delete_blocked_item_nonce');

    // Build list of items to check
    $items = [];

    if (!empty($ip)) {
        $is_blocked = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}cg_blocked_ips WHERE ip_address = %s AND (expires_at IS NULL OR expires_at > %s) LIMIT 1",
            $ip, $now
        ));
        $is_protected = function_exists('cg_is_protected_ip') && cg_is_protected_ip($ip);
        $ip_label = __('IP', 'checkoutguard');
        if (function_exists('cg_is_cloudflare_ip') && cg_is_cloudflare_ip($ip)) {
            $ip_label .= ' (Cloudflare)';
        }
        $items[] = [
            'type'      => 'ip',
            'value'     => $ip,
            'label'     => $ip_label,
            'icon'      => 'dashicons-admin-site-alt3',
            'blocked'   => (bool) $is_blocked,
            'protected' => $is_protected,
        ];
    }

    if ($ref === '') {
        $email = '';
        $phone = '';
    }

    if (!empty($email) && is_email($email)) {
        $is_blocked = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}cg_blocked_emails WHERE email_address = %s AND (expires_at IS NULL OR expires_at > %s) LIMIT 1",
            $email, $now
        ));
        $items[] = ['type' => 'email', 'value' => $email, 'label' => __('Email', 'checkoutguard'), 'icon' => 'dashicons-email', 'blocked' => (bool) $is_blocked, 'ref' => $ref];
    }

    if (!empty($phone)) {
        $normalized_phone = function_exists('cg_normalize_phone_number') ? cg_normalize_phone_number($phone) : $phone;
        $is_blocked = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}cg_blocked_numbers WHERE phone_number = %s AND (expires_at IS NULL OR expires_at > %s) LIMIT 1",
            $normalized_phone, $now
        ));
        $items[] = ['type' => 'phone', 'value' => $normalized_phone, 'label' => __('Phone', 'checkoutguard'), 'icon' => 'dashicons-phone', 'blocked' => (bool) $is_blocked, 'ref' => $ref];
    }

    if (!empty($device_fp)) {
        $is_blocked = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}cg_blocked_devices WHERE device_fingerprint = %s AND (expires_at IS NULL OR expires_at > %s) LIMIT 1",
            $device_fp, $now
        ));
        // Look up device_info from blocked_devices or incomplete_checkouts for display
        $device_ua = $wpdb->get_var($wpdb->prepare(
            "SELECT device_info FROM {$wpdb->prefix}cg_blocked_devices WHERE device_fingerprint = %s LIMIT 1",
            $device_fp
        )) ?: '';
        $device_name = '';
        if (!empty($device_ua) && function_exists('cg_get_device_name_from_ua')) {
            $device_name = cg_get_device_name_from_ua($device_ua);
        }
        $short_fp = substr($device_fp, 0, 12) . '...';
        $display = $device_name ? $device_name . ' (' . $short_fp . ')' : $short_fp;
        $items[] = ['type' => 'device', 'value' => $device_fp, 'label' => __('Device', 'checkoutguard'), 'icon' => 'dashicons-laptop', 'blocked' => (bool) $is_blocked, 'display' => $display, 'device_info' => $device_ua];
    }

    if (!empty($address_1)) {
        $address_hash = cg_hash_address($address_1, $city, $postcode);
        if (!empty($address_hash)) {
            $is_blocked = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}cg_blocked_addresses WHERE address_hash = %s AND (expires_at IS NULL OR expires_at > %s) LIMIT 1",
                $address_hash, $now
            ));
            $address_text = implode(', ', array_filter([$address_1, $city, $postcode]));
            $items[] = ['type' => 'address', 'value' => json_encode(['address_1' => $address_1, 'city' => $city, 'postcode' => $postcode]), 'label' => __('Address', 'checkoutguard'), 'icon' => 'dashicons-location', 'blocked' => (bool) $is_blocked, 'display' => $address_text];
        }
    }

    if (empty($items)) {
        return;
    }
    $is_pro = cg_is_pro_plan();
    ?>
    <div class="cg-detail-card cg-detail-card-full">
        <h4><span class="dashicons dashicons-shield-alt"></span> <?php _e('Fraud Blocking', 'checkoutguard'); ?></h4>
        <div class="cg-popup-block-buttons">
            <?php foreach ($items as $item):
                $display_val = isset($item['display']) ? $item['display'] : $item['value'];
                // Mask phone and email display values for free users
                if (!$is_pro) {
                    if ($item['type'] === 'phone') {
                        $display_val = cg_mask_phone($display_val);
                    } elseif ($item['type'] === 'email') {
                        $display_val = cg_mask_email($display_val);
                    }
                }
                $short_val = (strlen($display_val) > 30) ? substr($display_val, 0, 27) . '...' : $display_val;
            ?>
                <div class="cg-popup-block-row" data-block-type="<?php echo esc_attr($item['type']); ?>">
                    <div class="cg-popup-block-info">
                        <span class="dashicons <?php echo esc_attr($item['icon']); ?>"></span>
                        <span class="cg-popup-block-label"><?php echo esc_html($item['label']); ?>:</span>
                        <span class="cg-popup-block-value" title="<?php echo esc_attr($display_val); ?>"><?php echo esc_html($short_val); ?></span>
                    </div>
                    <?php if (!empty($item['protected'])): ?>
                        <span class="cg-badge" style="background:#e0f2fe;color:#0369a1;padding:3px 8px;border-radius:4px;font-size:11px;font-weight:600;"><?php _e('Protected Gateway', 'checkoutguard'); ?></span>
                    <?php elseif ($item['blocked']): ?>
                        <button type="button" class="button button-small cg-popup-unblock-btn" data-block-type="<?php echo esc_attr($item['type']); ?>"<?php echo cg_block_button_target_attr($item); ?> data-nonce="<?php echo esc_attr($unblock_nonce); ?>"<?php if (!empty($item['device_info'])): ?> data-device-info="<?php echo esc_attr($item['device_info']); ?>"<?php endif; ?>>
                            <span class="dashicons dashicons-unlock"></span> <?php _e('Unblock', 'checkoutguard'); ?>
                        </button>
                    <?php else: ?>
                        <button type="button" class="button button-small cg-popup-block-btn" data-block-type="<?php echo esc_attr($item['type']); ?>"<?php echo cg_block_button_target_attr($item); ?> data-nonce="<?php echo esc_attr($block_nonce); ?>"<?php if (!empty($item['device_info'])): ?> data-device-info="<?php echo esc_attr($item['device_info']); ?>"<?php endif; ?>>
                            <span class="dashicons dashicons-shield-alt"></span> <?php _e('Block', 'checkoutguard'); ?>
                        </button>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}



function cg_get_blocked_orders_table_rows_html($results)
{
    ob_start();
    if (!empty($results)) {
        foreach ($results as $row) {
            $cart_items = json_decode($row->cart_details, true);
            $item_count = is_array($cart_items) ? count($cart_items) : 0;
            $full_name = trim(($row->first_name ?? '') . ' ' . ($row->last_name ?? ''));
            ?>
            <tr id="log-row-<?php echo esc_attr($row->id); ?>">
                <td><?php echo esc_html($row->id); ?></td>
                <td>
                    <a href="#" class="cg-view-details" data-type="blocked_order" data-log-id="<?php echo esc_attr($row->id); ?>">
                        <strong><?php echo esc_html($full_name ?: '(No Name)'); ?></strong>
                    </a>
                </td>
                <td>
                    <?php if (!cg_is_pro_plan() && !empty($row->email_address)): ?>
                        <span class="cg-pro-masked" data-cg-tooltip="<?php esc_attr_e('Upgrade to Pro to see full email', 'checkoutguard'); ?>"><?php echo esc_html(cg_mask_email($row->email_address)); ?></span>
                    <?php else: ?>
                        <?php echo esc_html($row->email_address); ?>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (!cg_is_pro_plan() && !empty($row->phone_number)): ?>
                        <span class="cg-pro-masked" data-cg-tooltip="<?php esc_attr_e('Upgrade to Pro to see full number', 'checkoutguard'); ?>"><?php echo esc_html(cg_mask_phone($row->phone_number)); ?></span>
                    <?php else: ?>
                        <?php echo esc_html($row->phone_number); ?>
                    <?php endif; ?>
                </td>
                <td><?php echo esc_html($row->ip_address); ?></td>
                <td><?php echo wp_kses_post(wc_price($row->cart_value)); ?></td>
                <td><?php echo esc_html($item_count) . ' ' . _n('item', 'items', $item_count, 'checkoutguard'); ?></td>
                <td>
                    <span class="cg-blocked-ratio"><?php echo esc_html($row->success_ratio); ?>%</span>
                    <small>(Threshold: <?php echo esc_html($row->threshold_at_block); ?>%)</small>
                    <?php if (isset($row->action) && $row->action === 'hold'): ?>
                        <br><span class="cg-badge cg-badge-held"><?php esc_html_e('Held', 'checkoutguard'); ?></span>
                        <?php if (!empty($row->order_id)): ?>
                            <?php $cg_held_order = wc_get_order((int) $row->order_id); ?>
                            <?php if ($cg_held_order): ?>
                                <a href="<?php echo esc_url($cg_held_order->get_edit_order_url()); ?>">#<?php echo esc_html($cg_held_order->get_order_number()); ?></a>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
                <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($row->blocked_at))); ?>
                </td>
                <td class="cg-actions-cell">
                    <?php
                    printf('<a href="#" class="button button-small cg-view-details" data-type="blocked_order" data-log-id="%d" title="%s"><span class="dashicons dashicons-visibility"></span></a> ', esc_attr($row->id), __('View Details', 'checkoutguard'));
                    printf('<button class="button button-small button-danger cg-delete-blocked-log" data-log-id="%d" data-nonce="%s"><span class="dashicons dashicons-trash"></span></button>', esc_attr($row->id), wp_create_nonce('cg_delete_blocked_log_nonce'));
                    ?>
                </td>
            </tr>
            <?php
        }
    }
    return ob_get_clean();
}

