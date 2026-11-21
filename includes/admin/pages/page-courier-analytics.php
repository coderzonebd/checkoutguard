<?php
// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}


/**
 * Renders the Courier Analytics page.
 */
function cg_render_courier_analytics_page()
{
    $recent_checks = get_option('cg_recent_courier_checks', []);
    ?>
    <div class="wrap cg-courier-analytics-wrap">
        <div class="cg-page-header">
            <h1><?php _e('Courier Success Ratio Checker', 'checkoutguard'); ?></h1>
            <button type="button" class="cg-dark-mode-toggle" title="<?php esc_attr_e('Toggle Dark Mode', 'checkoutguard'); ?>">
                <span class="dashicons dashicons-admin-appearance"></span>
            </button>
        </div>
        <p><?php _e('Enter a customer phone number to check their delivery success and return ratio across different courier services.', 'checkoutguard'); ?>
        </p>

        <?php if (!cg_is_pro_plan()): ?>
        <div class="cg-pro-locked-hover">
            <div class="cg-pro-hover-overlay">
                <span class="dashicons dashicons-lock"></span>
                <p><?php _e('Upgrade to Pro to unlock Courier Analytics', 'checkoutguard'); ?></p>
                <button type="button" class="button button-primary cg-open-upgrade-modal"><?php _e('Upgrade Now', 'checkoutguard'); ?></button>
            </div>
        <?php endif; ?>

        <nav class="nav-tab-wrapper cg-courier-tabs" role="tablist" aria-label="<?php esc_attr_e('Courier analytics tabs', 'checkoutguard'); ?>">
            <a href="#" class="nav-tab nav-tab-active" data-tab="checker" role="tab" aria-selected="true" aria-controls="tabpanel-checker"><?php _e('Ratio Checker', 'checkoutguard'); ?></a>
            <a href="#" class="nav-tab" data-tab="courier-settings" role="tab" aria-selected="false" aria-controls="tabpanel-courier-settings"><?php _e('Settings', 'checkoutguard'); ?></a>
        </nav>

        <div class="cg-tab-pane cg-courier-pane" data-tab-content="checker" id="tabpanel-checker" role="tabpanel">
        <div class="cg-courier-page-layout">

            <div class="cg-courier-sidebar">
                <div class="cg-courier-sidebar-inner">
                    <h3 class="cg-sidebar-title">
                        <span class="dashicons dashicons-clock"></span>
                        <?php _e('Recent Checks', 'checkoutguard'); ?>
                    </h3>
                    <ul id="cg-recent-checks-list" class="cg-recent-checks-list">
                        <?php if (empty($recent_checks)): ?>
                            <li class="cg-recent-empty" id="cg-recent-empty-msg">
                                <?php _e('No recent checks yet. Check a number to see it here.', 'checkoutguard'); ?>
                            </li>
                        <?php else: ?>
                            <?php foreach ($recent_checks as $check): ?>
                                <li class="cg-recent-item" data-phone="<?php echo esc_attr($check['phone']); ?>">
                                    <div class="cg-recent-item-info">
                                        <span class="cg-recent-phone"><?php echo esc_html($check['phone']); ?></span>
                                        <span class="cg-recent-time"><?php echo esc_html(human_time_diff(strtotime($check['checked_at']), current_time('timestamp'))); ?> <?php _e('ago', 'checkoutguard'); ?></span>
                                    </div>
                                    <span class="cg-recent-rate" style="color: <?php echo esc_attr($check['risk_color']); ?>;">
                                        <?php echo esc_html($check['success_rate']); ?>%
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

            <div class="cg-courier-main">
                <div class="cg-courier-check-form">
                    <form id="cg_courier_check_form">
                        <?php wp_nonce_field('cg_check_courier_success_nonce', 'cg_courier_nonce'); ?>
                        <div class="cg-form-row">
                            <label for="cg_customer_phone" class="cg-form-label">
                                <span class="dashicons dashicons-phone"></span>
                                <?php _e('Customer Phone Number', 'checkoutguard'); ?>
                            </label>
                            <div class="cg-form-input-wrapper">
                                <input type="text"
                                       id="cg_customer_phone"
                                       name="cg_customer_phone"
                                       class="cg-phone-input"
                                       placeholder="<?php esc_attr_e('Enter phone number (e.g., 01XXXXXXXXX)', 'checkoutguard'); ?>"
                                       autocomplete="off"
                                       required />
                                <button type="submit" id="cg_check_ratio_btn" class="button button-primary cg-check-btn">
                                    <span class="dashicons dashicons-search"></span>
                                    <?php _e('Check Ratio', 'checkoutguard'); ?>
                                </button>
                                <span class="spinner"></span>
                            </div>
                            <p class="cg-form-hint"><?php _e('Press Enter to check or click the button', 'checkoutguard'); ?></p>
                        </div>
                    </form>
                </div>

                <div id="cg-courier-results-container" style="margin-top: 24px;">
                </div>
            </div>

        </div>
        </div>

        <?php // Success Ratio Blocker Settings Panel ?>
        <?php
        $cg_options = get_option('cg_plugin_options', []);
        $ratio_threshold = $cg_options['ratio_blocker_threshold'] ?? 20;
        $ratio_grace = $cg_options['ratio_blocker_grace_period'] ?? 5;
        $ratio_message = $cg_options['ratio_blocker_message'] ?? 'Your courier success ratio is {ratio}%, which is too low. Please contact us to complete your order.';
        ?>
        <div class="cg-tab-pane cg-courier-pane" data-tab-content="courier-settings" style="display:none;" id="tabpanel-courier-settings" role="tabpanel">
        <div class="cg-inline-settings-panel cg-inline-settings-panel-tab">
            <h2><?php _e('Success Ratio Blocker Settings', 'checkoutguard'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="cg_ratio_blocker_threshold"><?php _e('Block Threshold (%)', 'checkoutguard'); ?></label></th>
                    <td>
                        <input type="number" id="cg_ratio_blocker_threshold" value="<?php echo esc_attr($ratio_threshold); ?>" class="small-text" min="0" max="100" /> %
                        <p class="description"><?php _e('Block orders if the customer\'s success ratio is at or below this percentage.', 'checkoutguard'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="cg_ratio_blocker_grace_period"><?php _e('New Customer Grace Period', 'checkoutguard'); ?></label></th>
                    <td>
                        <input type="number" id="cg_ratio_blocker_grace_period" value="<?php echo esc_attr($ratio_grace); ?>" class="small-text" min="0" /> <?php _e('orders', 'checkoutguard'); ?>
                        <p class="description"><?php _e('Do not block new customers until they have more than this many total orders.', 'checkoutguard'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="cg_ratio_blocker_message"><?php _e('Blocked Order Notice', 'checkoutguard'); ?></label></th>
                    <td>
                        <textarea id="cg_ratio_blocker_message" rows="3" class="large-text"><?php echo esc_textarea($ratio_message); ?></textarea>
                        <p class="description"><?php _e('This message will be shown in a popup to the customer. Use <code>{ratio}</code> as a placeholder for the customer\'s success ratio.', 'checkoutguard'); ?></p>
                    </td>
                </tr>
            </table>
            <p>
                <button type="button" id="cg-save-ratio-settings" class="button button-primary"><?php _e('Save Settings', 'checkoutguard'); ?></button>
                <span class="spinner"></span>
            </p>
        </div>
        </div>
        <?php if (!cg_is_pro_plan()): ?>
        </div><!-- .cg-pro-locked-hover -->
        <?php endif; ?>
    </div>
    <?php cg_render_upgrade_modal_html(); ?>
    <?php
}

