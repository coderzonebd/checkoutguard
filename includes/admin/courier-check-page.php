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

    ?>
    <div class="wrap checkoutguard-wrap">
        <div class="cg-page-header">
            <h1>
                <span class="dashicons dashicons-networking"></span>
                <?php esc_html_e('Courier Check', 'checkoutguard'); ?>
            </h1>
            <p class="page-subtitle">
                <?php esc_html_e('Check customer courier success rates from Pathao, Steadfast, and RedX.', 'checkoutguard'); ?>
            </p>
        </div>

        <div class="cg-courier-grid">
            <!-- Left Column: Search Form -->
            <div class="cg-card cg-courier-search-card">
                <div class="cg-card-header">
                    <h2><?php esc_html_e('Check Phone Number', 'checkoutguard'); ?></h2>
                </div>

                <form id="cg-courier-check-form" class="cg-courier-form">
                    <div class="cg-form-group">
                        <label for="cg-phone-number"><?php esc_html_e('Phone Number', 'checkoutguard'); ?></label>
                        <input 
                            type="text" 
                            id="cg-phone-number" 
                            name="phone_number" 
                            placeholder="01700000000"
                            pattern="01[3-9]\d{8}"
                            maxlength="11"
                            required
                        >
                        <small class="cg-form-help">
                            <?php esc_html_e('Enter a valid Bangladeshi phone number (e.g., 01700000000)', 'checkoutguard'); ?>
                        </small>
                    </div>

                    <div class="cg-form-group">
                        <label>
                            <input 
                                type="checkbox" 
                                id="cg-bypass-cache" 
                                name="bypass_cache"
                            >
                            <?php esc_html_e('Bypass cache (fetch fresh data)', 'checkoutguard'); ?>
                        </label>
                        <small class="cg-form-help">
                            <?php esc_html_e('Results are cached for 6 hours by default', 'checkoutguard'); ?>
                        </small>
                    </div>

                    <button 
                        type="submit" 
                        class="button button-primary button-large" 
                        id="cg-check-courier-btn"
                    >
                        <span class="dashicons dashicons-search"></span>
                        <?php esc_html_e('Check Courier', 'checkoutguard'); ?>
                    </button>

                    <div class="cg-loading-spinner" id="cg-loading" style="display: none;">
                        <span class="spinner is-active"></span>
                        <span><?php esc_html_e('Checking courier services...', 'checkoutguard'); ?></span>
                    </div>
                </form>

                <!-- Recent Searches -->
                <div class="cg-recent-searches" id="cg-recent-searches">
                    <div class="cg-recent-searches-header">
                        <h3><?php esc_html_e('Recent Searches', 'checkoutguard'); ?></h3>
                        <button 
                            type="button" 
                            class="cg-clear-all-searches button button-small" 
                            id="cg-clear-all-btn"
                            title="<?php esc_attr_e('Clear all search history', 'checkoutguard'); ?>"
                        >
                            <span class="dashicons dashicons-trash"></span>
                            <?php esc_html_e('Clear All', 'checkoutguard'); ?>
                        </button>
                    </div>
                    <div class="cg-recent-searches-list" id="cg-recent-list">
                        <?php checkoutguard_render_recent_searches(); ?>
                    </div>
                </div>
            </div>

            <!-- Right Column: Results -->
            <div class="cg-card cg-courier-results-card">
                <div class="cg-card-header">
                    <h2><?php esc_html_e('Results', 'checkoutguard'); ?></h2>
                </div>

                <div id="cg-courier-results" class="cg-courier-results">
                    <div class="cg-no-results">
                        <span class="dashicons dashicons-search"></span>
                        <p><?php esc_html_e('Enter a phone number to check courier success rates', 'checkoutguard'); ?></p>
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
        echo '<p class="cg-no-data">' . esc_html__('No recent searches', 'checkoutguard') . '</p>';
        return;
    }

    $recent = $wpdb->get_results(
        "SELECT * FROM {$table_name} ORDER BY searched_at DESC LIMIT 5"
    );

    if (empty($recent)) {
        echo '<p class="cg-no-data">' . esc_html__('No recent searches', 'checkoutguard') . '</p>';
        return;
    }

    foreach ($recent as $search) {
        $risk_class = 'risk-' . strtolower(str_replace(' ', '-', $search->risk_level));
        ?>
        <div class="cg-recent-item" data-phone="<?php echo esc_attr($search->phone_number); ?>" data-search-id="<?php echo esc_attr($search->id); ?>">
            <div class="cg-recent-content">
                <div class="cg-recent-phone"><?php echo esc_html($search->phone_number); ?></div>
                <div class="cg-recent-meta">
                    <span class="cg-risk-badge <?php echo esc_attr($risk_class); ?>">
                        <?php echo esc_html($search->risk_level); ?>
                    </span>
                    <span class="cg-recent-time">
                        <?php echo esc_html(human_time_diff(strtotime($search->searched_at), current_time('timestamp'))); ?> 
                        <?php esc_html_e('ago', 'checkoutguard'); ?>
                    </span>
                </div>
            </div>
            <button 
                type="button" 
                class="cg-delete-search" 
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
