<?php
/**
 * Courier Check Page
 * 
 * This page allows checking courier success rates for phone numbers
 * using the external API.
 * 
 * @package CheckoutGuard
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

// Security Headers removed - they cause 'headers already sent' errors
// WordPress handles security headers appropriately

/**
 * Renders the Courier Check page.
 */
function checkoutguard_render_courier_check_page()
{
    // Check permissions
    if (!current_user_can('manage_woocommerce')) {
        wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'checkoutguard'));
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'checkoutguard_courier_searches';
    $total_searches = 0;
    
    // Get total searches count safely
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") == $table_name) {
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
        $total_searches = $count ? intval($count) : 0;
    }

    ?>
    <div class="wrap checkoutguard-courier-analytics-wrap">
        <h1>
            <span class="dashicons dashicons-networking" style="font-size: 32px; width: 32px; height: 32px;"></span>
            <?php esc_html_e('Courier Check', 'checkoutguard'); ?>
        </h1>
        <p>
            <?php esc_html_e('Verify customer courier success rates from Pathao, Steadfast, and RedX instantly.', 'checkoutguard'); ?>
        </p>

        <!-- Stats Cards -->
        <div class="checkoutguard-stat-row" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin: 20px 0;">
            <div class="checkoutguard-stat-box stat-incomplete" style="min-width: 0;">
                <h3><?php esc_html_e('Total Checks', 'checkoutguard'); ?></h3>
                <p><?php echo esc_html($total_searches); ?></p>
                <div class="checkoutguard-stat-subtext"><?php esc_html_e('Searches Performed', 'checkoutguard'); ?></div>
            </div>

            <div class="checkoutguard-stat-box stat-recovered" style="min-width: 0;">
                <h3><?php esc_html_e('Cache Duration', 'checkoutguard'); ?></h3>
                <p>6h</p>
                <div class="checkoutguard-stat-subtext"><?php esc_html_e('Auto Refresh', 'checkoutguard'); ?></div>
            </div>

            <div class="checkoutguard-stat-box stat-hold" style="min-width: 0;">
                <h3><?php esc_html_e('API Sources', 'checkoutguard'); ?></h3>
                <p>3</p>
                <div class="checkoutguard-stat-subtext"><?php esc_html_e('Courier Services', 'checkoutguard'); ?></div>
            </div>
        </div>

        <div class="checkoutguard-dashboard-layout-container" style="display: grid; grid-template-columns: minmax(320px, 400px) 1fr; gap: 20px; margin-top: 20px;" style="display: grid; grid-template-columns: minmax(320px, 400px) 1fr; gap: 20px; margin-top: 20px;">
            <!-- Search Card -->
            <div class="checkoutguard-dashboard-layout-left">
                <div class="checkoutguard-blocker-section" style="margin-top: 0;">
                    <h2>
                        <span class="dashicons dashicons-phone" style="margin-right: 10px;"></span>
                        <?php esc_html_e('Check Phone Number', 'checkoutguard'); ?>
                    </h2>

                    <form id="checkoutguard-courier-check-form" class="checkoutguard-courier-check-form" style="box-shadow: none; border: none; padding: 0; margin: 0;">
                        <div class="checkoutguard-form-row">
                            <label for="checkoutguard-phone-number" class="checkoutguard-form-label">
                                <?php esc_html_e('Phone Number', 'checkoutguard'); ?>
                            </label>
                            <div class="checkoutguard-form-input-wrapper">
                                <input 
                                    type="text" 
                                    id="checkoutguard-phone-number" 
                                    name="phone_number" 
                                    class="checkoutguard-phone-input"
                                    placeholder="01700000000"
                                    pattern="01[3-9]\d{8}"
                                    maxlength="11"
                                    required
                                >
                            </div>
                            <p class="checkoutguard-form-hint">
                                <?php esc_html_e('Enter a valid Bangladeshi phone number (e.g., 01700000000)', 'checkoutguard'); ?>
                            </p>
                        </div>

                        <div style="margin-top: 20px;">
                            <button 
                                type="submit" 
                                class="button checkoutguard-check-btn" 
                                id="checkoutguard-check-courier-btn"
                                style="width: 100%; justify-content: center;"
                            >
                                <span class="dashicons dashicons-search"></span>
                                <?php esc_html_e('Check Courier', 'checkoutguard'); ?>
                            </button>
                        </div>

                        <div id="checkoutguard-loading" style="display: none; margin-top: 15px; text-align: center; color: var(--checkoutguard-text-secondary);">
                            <span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span>
                            <span><?php esc_html_e('Checking courier services...', 'checkoutguard'); ?></span>
                        </div>
                    </form>

                    <!-- Recent Searches -->
                    <div id="checkoutguard-recent-searches" style="margin-top: 30px; border-top: 1px solid var(--checkoutguard-card-border); padding-top: 20px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                            <h3 style="margin: 0; font-size: 16px; display: flex; align-items: center; gap: 8px;">
                                <span class="dashicons dashicons-clock"></span>
                                <?php esc_html_e('Recent Searches', 'checkoutguard'); ?>
                            </h3>
                            <button 
                                type="button" 
                                class="button button-link-delete" 
                                id="checkoutguard-clear-all-btn"
                                title="<?php esc_attr_e('Clear all search history', 'checkoutguard'); ?>"
                                style="text-decoration: none; color: var(--checkoutguard-danger-color);"
                            >
                                <?php esc_html_e('Clear All', 'checkoutguard'); ?>
                            </button>
                        </div>
                        <div id="checkoutguard-recent-list">
                            <?php checkoutguard_render_recent_searches(); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Results Card -->
            <div class="checkoutguard-dashboard-layout-right">
                <div class="checkoutguard-table-responsive-wrapper" style="min-height: 400px; display: flex; flex-direction: column;">
                    <div style="padding: 20px; border-bottom: 1px solid var(--checkoutguard-card-border);">
                        <h2 style="margin: 0; font-size: 18px; display: flex; align-items: center; gap: 10px;">
                            <span class="dashicons dashicons-chart-area"></span>
                            <?php esc_html_e('Results', 'checkoutguard'); ?>
                        </h2>
                    </div>

                    <div id="checkoutguard-courier-results" style="flex: 1; padding: 20px;">
                        <div class="checkoutguard-table-empty-message">
                            <span class="dashicons dashicons-search" style="font-size: 48px; width: 48px; height: 48px; margin-bottom: 15px; color: var(--checkoutguard-text-light);"></span>
                            <h3><?php esc_html_e('Ready to Check', 'checkoutguard'); ?></h3>
                            <p><?php esc_html_e('Enter a phone number to view courier success rates from multiple services.', 'checkoutguard'); ?></p>
                            <div style="display: flex; gap: 10px; justify-content: center; margin-top: 20px;">
                                <span class="checkoutguard-info-badge badge-info">Pathao</span>
                                <span class="checkoutguard-info-badge badge-info">Steadfast</span>
                                <span class="checkoutguard-info-badge badge-info">RedX</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <?php
}

/**
 * Renders recent searches list.
 */
function checkoutguard_render_recent_searches()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'checkoutguard_courier_searches';

    // Check if table exists
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") != $table_name) {
        echo '<p style="color: var(--checkoutguard-text-light); font-style: italic;">' . esc_html__('No recent searches', 'checkoutguard') . '</p>';
        return;
    }

    $recent = $wpdb->get_results(
        "SELECT * FROM {$table_name} ORDER BY searched_at DESC LIMIT 5"
    );

    if (empty($recent)) {
        echo '<p style="color: var(--checkoutguard-text-light); font-style: italic;">' . esc_html__('No recent searches', 'checkoutguard') . '</p>';
        return;
    }

    foreach ($recent as $search) {
        $risk_class = 'badge-' . strtolower(str_replace(' ', '-', $search->risk_level));
        if ($search->risk_level == 'Safe') $risk_class = 'badge-success';
        if ($search->risk_level == 'High Risk') $risk_class = 'badge-danger';
        if ($search->risk_level == 'Moderate Risk') $risk_class = 'badge-warning';
        ?>
        <div class="checkoutguard-recent-item-modern" style="display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid var(--checkoutguard-card-border);" data-phone="<?php echo esc_attr($search->phone_number); ?>" data-search-id="<?php echo esc_attr($search->id); ?>">
            <div style="display: flex; align-items: center; gap: 10px;">
                <span class="dashicons dashicons-phone" style="color: var(--checkoutguard-text-light);"></span>
                <div>
                    <div style="font-weight: 600; color: var(--checkoutguard-text-primary); cursor: pointer;" onclick="document.getElementById('checkoutguard-phone-number').value='<?php echo esc_js($search->phone_number); ?>'; document.getElementById('checkoutguard-check-courier-btn').click();">
                        <?php echo esc_html($search->phone_number); ?>
                    </div>
                    <div style="font-size: 11px; color: var(--checkoutguard-text-secondary); margin-top: 2px;">
                        <?php echo esc_html(human_time_diff(strtotime($search->searched_at), current_time('timestamp'))); ?> 
                        <?php esc_html_e('ago', 'checkoutguard'); ?>
                    </div>
                </div>
            </div>
            <div style="display: flex; align-items: center; gap: 10px;">
                <span class="checkoutguard-info-badge <?php echo esc_attr($risk_class); ?>" style="font-size: 10px;">
                    <?php echo esc_html($search->risk_level); ?>
                </span>
                <button 
                    type="button" 
                    class="checkoutguard-delete-search" 
                    data-search-id="<?php echo esc_attr($search->id); ?>"
                    title="<?php esc_attr_e('Delete this search', 'checkoutguard'); ?>"
                    style="background: none; border: none; color: var(--checkoutguard-text-light); cursor: pointer; padding: 0;"
                >
                    <span class="dashicons dashicons-trash"></span>
                </button>
            </div>
        </div>
        <?php
    }
}
