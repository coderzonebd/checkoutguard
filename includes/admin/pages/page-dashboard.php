<?php
// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}



/**
 * Renders the main Dashboard Overview page.
 */
function cg_render_dashboard_overview_page()
{
    $incomplete_url = admin_url('admin.php?page=cg-incomplete-checkouts');
    $recovered_url = admin_url('admin.php?page=cg-incomplete-checkouts&tab=recovered');
    $followup_url = admin_url('admin.php?page=cg-incomplete-checkouts&tab=hold');
    $cancelled_url = admin_url('admin.php?page=cg-incomplete-checkouts&tab=cancelled');
    ?>
    <div class="wrap cg-dashboard-wrap">
        <div class="cg-page-header">
            <h1><?php _e('CheckoutGuard Dashboard', 'checkoutguard'); ?></h1>
            <button type="button" class="cg-dark-mode-toggle" title="<?php esc_attr_e('Toggle Dark Mode', 'checkoutguard'); ?>">
                <span class="dashicons dashicons-admin-appearance"></span>
            </button>
        </div>
        <?php cg_render_date_filter_controls('dashboard', 'today', 'created_at'); ?>

        <div id="cg-dashboard-loading" style="display:none; text-align:center; padding:20px;">
            <p><?php _e('Loading dashboard data...', 'checkoutguard'); ?></p>
        </div>

        <?php // This is the new two-column layout container ?>
        <div class="cg-dashboard-layout-container">

            <?php // Left Column: Chart ?>
            <div class="cg-dashboard-layout-left">
                <div class="cg-dashboard-chart-container"><canvas id="cgOrderStatusChart"></canvas></div>
            </div>

            <?php // Right Column: Stat Boxes ?>
            <div class="cg-dashboard-layout-right">
                <div class="cg-dashboard-counts cg-stat-row">
                    <a href="<?php echo esc_url($incomplete_url); ?>" class="cg-stat-box cg-stat-box-link">
                        <h3><?php _e('Incomplete Orders', 'checkoutguard'); ?></h3>
                        <p id="cg-stat-incomplete-count">0</p>
                    </a>
                    <a href="<?php echo esc_url($recovered_url); ?>" class="cg-stat-box cg-stat-box-link">
                        <h3><?php _e('Recovered Orders', 'checkoutguard'); ?></h3>
                        <p id="cg-stat-recovered-count">0</p>
                    </a>
                    <a href="<?php echo esc_url($followup_url); ?>" class="cg-stat-box cg-stat-box-link">
                        <h3><?php _e('Hold Orders', 'checkoutguard'); ?></h3>
                        <p id="cg-stat-hold-count">0</p>
                    </a>
                    <a href="<?php echo esc_url($cancelled_url); ?>" class="cg-stat-box cg-stat-box-link">
                        <h3><?php _e('Cancelled Orders', 'checkoutguard'); ?></h3>
                        <p id="cg-stat-cancelled-count">0</p>
                    </a>
                </div>
                <div class="cg-dashboard-values cg-stat-row">
                    <div class="cg-stat-box">
                        <h3><?php _e('Incomplete Value', 'checkoutguard'); ?></h3>
                        <p id="cg-stat-incomplete-value"><?php echo wp_kses_post(wc_price(0)); ?></p>
                    </div>
                    <div class="cg-stat-box">
                        <h3><?php _e('Recovered Value', 'checkoutguard'); ?></h3>
                        <p id="cg-stat-recovered-value"><?php echo wp_kses_post(wc_price(0)); ?></p>
                    </div>
                    <div class="cg-stat-box">
                        <h3><?php _e('Hold Value', 'checkoutguard'); ?></h3>
                        <p id="cg-stat-hold-value"><?php echo wp_kses_post(wc_price(0)); ?></p>
                    </div>
                    <div class="cg-stat-box">
                        <h3><?php _e('Cancelled Value', 'checkoutguard'); ?></h3>
                        <p id="cg-stat-cancelled-value"><?php echo wp_kses_post(wc_price(0)); ?></p>
                    </div>
                </div>
            </div>

        </div>

        <div class="cg-dashboard-trend-container">
            <h2><?php _e('Checkout Trends', 'checkoutguard'); ?></h2>
            <canvas id="cgTrendChart"></canvas>
        </div>
    </div>
    <?php
}

