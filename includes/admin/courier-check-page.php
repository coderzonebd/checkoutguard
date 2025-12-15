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
    <div class="wrap checkoutguard-wrap">
        <!-- Modern Page Header -->
        <div class="cg-page-header-modern">
            <div class="cg-header-content">
                <div class="cg-header-icon">
                    <span class="dashicons dashicons-networking"></span>
                </div>
                <div class="cg-header-text">
                    <h1><?php esc_html_e('Courier Check', 'checkoutguard'); ?></h1>
                    <p class="cg-header-subtitle">
                        <?php esc_html_e('Verify customer courier success rates from Pathao, Steadfast, and RedX instantly', 'checkoutguard'); ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="cg-courier-stats-modern">
            <div class="cg-stat-card-modern cg-stat-primary">
                <div class="cg-stat-icon">
                    <span class="dashicons dashicons-search"></span>
                </div>
                <div class="cg-stat-content">
                    <p class="cg-stat-label"><?php esc_html_e('Total Checks', 'checkoutguard'); ?></p>
                    <p class="cg-stat-number"><?php echo esc_html($total_searches); ?></p>
                    <p class="cg-stat-desc"><?php esc_html_e('Searches Performed', 'checkoutguard'); ?></p>
                </div>
            </div>

            <div class="cg-stat-card-modern cg-stat-success">
                <div class="cg-stat-icon">
                    <span class="dashicons dashicons-update"></span>
                </div>
                <div class="cg-stat-content">
                    <p class="cg-stat-label"><?php esc_html_e('Cache Duration', 'checkoutguard'); ?></p>
                    <p class="cg-stat-number">6h</p>
                    <p class="cg-stat-desc"><?php esc_html_e('Auto Refresh', 'checkoutguard'); ?></p>
                </div>
            </div>

            <div class="cg-stat-card-modern cg-stat-info">
                <div class="cg-stat-icon">
                    <span class="dashicons dashicons-businessman"></span>
                </div>
                <div class="cg-stat-content">
                    <p class="cg-stat-label"><?php esc_html_e('API Sources', 'checkoutguard'); ?></p>
                    <p class="cg-stat-number">3</p>
                    <p class="cg-stat-desc"><?php esc_html_e('Courier Services', 'checkoutguard'); ?></p>
                </div>
            </div>
        </div>

        <div class="cg-courier-grid-modern">
            <!-- Search Card -->
            <div class="cg-courier-card-modern">
                <div class="cg-courier-card-header">
                    <div class="cg-card-title-group">
                        <span class="dashicons dashicons-phone"></span>
                        <h2><?php esc_html_e('Check Phone Number', 'checkoutguard'); ?></h2>
                    </div>
                </div>

                <div class="cg-courier-card-body">
                    <form id="cg-courier-check-form" class="cg-courier-form-modern">
                        <div class="cg-form-group-modern">
                            <label for="cg-phone-number">
                                <span class="dashicons dashicons-phone"></span>
                                <?php esc_html_e('Phone Number', 'checkoutguard'); ?>
                            </label>
                            <input 
                                type="text" 
                                id="cg-phone-number" 
                                name="phone_number" 
                                class="cg-input-modern cg-input-large"
                                placeholder="01700000000"
                                pattern="01[3-9]\d{8}"
                                maxlength="11"
                                required
                            >
                            <small class="cg-form-help-modern">
                                <span class="dashicons dashicons-info"></span>
                                <?php esc_html_e('Enter a valid Bangladeshi phone number (e.g., 01700000000)', 'checkoutguard'); ?>
                            </small>
                        </div>

                        <button 
                            type="submit" 
                            class="cg-btn cg-btn-primary cg-btn-large cg-btn-block" 
                            id="cg-check-courier-btn"
                        >
                            <span class="dashicons dashicons-search"></span>
                            <?php esc_html_e('Check Courier', 'checkoutguard'); ?>
                        </button>

                        <div class="cg-loading-modern" id="cg-loading" style="display: none;">
                            <span class="cg-spinner-modern"></span>
                            <span><?php esc_html_e('Checking courier services...', 'checkoutguard'); ?></span>
                        </div>
                    </form>

                    <!-- Recent Searches -->
                    <div class="cg-recent-section-modern" id="cg-recent-searches">
                        <div class="cg-recent-header-modern">
                            <h3>
                                <span class="dashicons dashicons-clock"></span>
                                <?php esc_html_e('Recent Searches', 'checkoutguard'); ?>
                            </h3>
                            <button 
                                type="button" 
                                class="cg-btn cg-btn-danger cg-btn-small" 
                                id="cg-clear-all-btn"
                                title="<?php esc_attr_e('Clear all search history', 'checkoutguard'); ?>"
                            >
                                <span class="dashicons dashicons-trash"></span>
                                <?php esc_html_e('Clear All', 'checkoutguard'); ?>
                            </button>
                        </div>
                        <div class="cg-recent-list-modern" id="cg-recent-list">
                            <?php checkoutguard_render_recent_searches(); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Results Card -->
            <div class="cg-courier-card-modern cg-results-card">
                <div class="cg-courier-card-header">
                    <div class="cg-card-title-group">
                        <span class="dashicons dashicons-chart-area"></span>
                        <h2><?php esc_html_e('Results', 'checkoutguard'); ?></h2>
                    </div>
                </div>

                <div id="cg-courier-results" class="cg-courier-results-modern">
                    <div class="cg-no-results-modern">
                        <div class="cg-no-results-icon">
                            <span class="dashicons dashicons-search"></span>
                        </div>
                        <h3><?php esc_html_e('Ready to Check', 'checkoutguard'); ?></h3>
                        <p><?php esc_html_e('Enter a phone number to view courier success rates from multiple services', 'checkoutguard'); ?></p>
                        <div class="cg-courier-badges">
                            <span class="cg-courier-badge">Pathao</span>
                            <span class="cg-courier-badge">Steadfast</span>
                            <span class="cg-courier-badge">RedX</span>
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
        echo '<div class="cg-no-data-modern">';
        echo '<span class="dashicons dashicons-info"></span>';
        echo '<p>' . esc_html__('No recent searches', 'checkoutguard') . '</p>';
        echo '</div>';
        return;
    }

    $recent = $wpdb->get_results(
        "SELECT * FROM {$table_name} ORDER BY searched_at DESC LIMIT 5"
    );

    if (empty($recent)) {
        echo '<div class="cg-no-data-modern">';
        echo '<span class="dashicons dashicons-info"></span>';
        echo '<p>' . esc_html__('No recent searches', 'checkoutguard') . '</p>';
        echo '</div>';
        return;
    }

    foreach ($recent as $search) {
        $risk_class = 'risk-' . strtolower(str_replace(' ', '-', $search->risk_level));
        ?>
        <div class="cg-recent-item-modern" data-phone="<?php echo esc_attr($search->phone_number); ?>" data-search-id="<?php echo esc_attr($search->id); ?>">
            <div class="cg-recent-icon-modern">
                <span class="dashicons dashicons-phone"></span>
            </div>
            <div class="cg-recent-content-modern">
                <div class="cg-recent-phone-modern"><?php echo esc_html($search->phone_number); ?></div>
                <div class="cg-recent-meta-modern">
                    <span class="cg-risk-badge-modern <?php echo esc_attr($risk_class); ?>">
                        <?php echo esc_html($search->risk_level); ?>
                    </span>
                    <span class="cg-recent-time-modern">
                        <span class="dashicons dashicons-clock"></span>
                        <?php echo esc_html(human_time_diff(strtotime($search->searched_at), current_time('timestamp'))); ?> 
                        <?php esc_html_e('ago', 'checkoutguard'); ?>
                    </span>
                </div>
            </div>
            <button 
                type="button" 
                class="cg-delete-search-modern cg-delete-search" 
                data-search-id="<?php echo esc_attr($search->id); ?>"
                title="<?php esc_attr_e('Delete this search', 'checkoutguard'); ?>"
                aria-label="<?php esc_attr_e('Delete this search', 'checkoutguard'); ?>"
            >
                <span class="dashicons dashicons-trash"></span>
            </button>
        </div>
        <?php
    }
}
