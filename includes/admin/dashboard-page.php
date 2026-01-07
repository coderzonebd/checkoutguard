<?php
// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

// Security Headers removed - they cause 'headers already sent' errors
// WordPress handles security headers appropriately

/**
 * Renders the main dashboard page for CheckoutGuard.
 */
function checkoutguard_render_dashboard_page()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'checkoutguard_incomplete_checkouts';

    // Get stats for the dashboard
    $total_incomplete = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(id) FROM {$table_name} WHERE status = %s",
            'incomplete'
        )
    );
    $total_value = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT SUM(cart_value) FROM {$table_name} WHERE status = %s",
            'incomplete'
        )
    );

    // Get stats for last 24 hours
    $last_24h_incomplete = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(id) FROM {$table_name} WHERE status = 'incomplete' AND created_at >= %s",
            date('Y-m-d H:i:s', strtotime('-1 day'))
        )
    );

    // Get stats for last 7 days
    $last_7d_incomplete = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(id) FROM {$table_name} WHERE status = 'incomplete' AND created_at >= %s",
            date('Y-m-d H:i:s', strtotime('-7 days'))
        )
    );

    // Get recent incomplete checkouts
    $recent_checkouts = $wpdb->get_results(
        "SELECT * FROM {$table_name} WHERE status = 'incomplete' ORDER BY created_at DESC LIMIT 5"
    );

    // PRO Stats Logic
    $stats_pro = [];
    if (defined('CHECKOUTGUARD_IS_PRO') && CHECKOUTGUARD_IS_PRO) {
        $statuses = ['incomplete', 'recovered', 'hold', 'cancelled'];
        foreach ($statuses as $status) {
            $stats_pro[$status] = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT COUNT(id) as count, SUM(cart_value) as value FROM {$table_name} WHERE status = %s",
                    $status
                )
            );
        }
    }

    ?>
    <div class="wrap checkoutguard-dashboard-wrap">
        <h1>
            <span class="dashicons dashicons-dashboard" style="font-size: 32px; width: 32px; height: 32px;"></span>
            <?php esc_html_e('CheckoutGuard Dashboard', 'checkoutguard'); ?>
        </h1>
        <p>
            <?php esc_html_e('Complete overview of your checkout tracking and fraud protection.', 'checkoutguard'); ?>
        </p>

        <!-- Stats Row -->
        <?php if (defined('CHECKOUTGUARD_IS_PRO') && CHECKOUTGUARD_IS_PRO): ?>
            <!-- PRO: Old Style Stats (8 Cards) -->
            <div class="checkoutguard-stat-row">
                <!-- Row 1: Counts -->
                <div class="checkoutguard-stat-box stat-incomplete">
                    <h3><?php esc_html_e('Incomplete Orders', 'checkoutguard'); ?></h3>
                    <p><?php echo esc_html($stats_pro['incomplete']->count ?? 0); ?></p>
                </div>
                <div class="checkoutguard-stat-box stat-recovered">
                    <h3><?php esc_html_e('Recovered Orders', 'checkoutguard'); ?></h3>
                    <p><?php echo esc_html($stats_pro['recovered']->count ?? 0); ?></p>
                </div>
                <div class="checkoutguard-stat-box stat-hold">
                    <h3><?php esc_html_e('Hold Orders', 'checkoutguard'); ?></h3>
                    <p><?php echo esc_html($stats_pro['hold']->count ?? 0); ?></p>
                </div>
                <div class="checkoutguard-stat-box stat-cancelled">
                    <h3><?php esc_html_e('Cancelled Orders', 'checkoutguard'); ?></h3>
                    <p><?php echo esc_html($stats_pro['cancelled']->count ?? 0); ?></p>
                </div>
                
                <!-- Row 2: Values -->
                <div class="checkoutguard-stat-box stat-incomplete">
                    <h3><?php esc_html_e('Incomplete Value', 'checkoutguard'); ?></h3>
                    <p><?php echo wc_price($stats_pro['incomplete']->value ?? 0); ?></p>
                </div>
                <div class="checkoutguard-stat-box stat-recovered">
                    <h3><?php esc_html_e('Recovered Value', 'checkoutguard'); ?></h3>
                    <p><?php echo wc_price($stats_pro['recovered']->value ?? 0); ?></p>
                </div>
                <div class="checkoutguard-stat-box stat-hold">
                    <h3><?php esc_html_e('Hold Value', 'checkoutguard'); ?></h3>
                    <p><?php echo wc_price($stats_pro['hold']->value ?? 0); ?></p>
                </div>
                <div class="checkoutguard-stat-box stat-cancelled">
                    <h3><?php esc_html_e('Cancelled Value', 'checkoutguard'); ?></h3>
                    <p><?php echo wc_price($stats_pro['cancelled']->value ?? 0); ?></p>
                </div>
            </div>
        <?php else: ?>
            <!-- FREE: Standard Stats (4 Cards) -->
            <div class="checkoutguard-stat-row">
                <div class="checkoutguard-stat-box stat-incomplete">
                    <h3><?php esc_html_e('All Incomplete', 'checkoutguard'); ?></h3>
                    <p><?php echo esc_html($total_incomplete ?? 0); ?></p>
                    <div class="checkoutguard-stat-subtext"><?php esc_html_e('Total Carts', 'checkoutguard'); ?></div>
                </div>

                <div class="checkoutguard-stat-box stat-recovered">
                    <h3><?php esc_html_e('Total Value', 'checkoutguard'); ?></h3>
                    <p><?php echo wc_price($total_value ?? 0); ?></p>
                    <div class="checkoutguard-stat-subtext"><?php esc_html_e('At Risk', 'checkoutguard'); ?></div>
                </div>

                <div class="checkoutguard-stat-box stat-hold">
                    <h3><?php esc_html_e('Last 24 Hours', 'checkoutguard'); ?></h3>
                    <p><?php echo esc_html($last_24h_incomplete ?? 0); ?></p>
                    <div class="checkoutguard-stat-subtext"><?php esc_html_e('New Carts', 'checkoutguard'); ?></div>
                </div>

                <div class="checkoutguard-stat-box stat-cancelled">
                    <h3><?php esc_html_e('Last 7 Days', 'checkoutguard'); ?></h3>
                    <p><?php echo esc_html($last_7d_incomplete ?? 0); ?></p>
                    <div class="checkoutguard-stat-subtext"><?php esc_html_e('Weekly Total', 'checkoutguard'); ?></div>
                </div>
            </div>
        <?php endif; ?>

        <div class="checkoutguard-dashboard-layout-container">
            <!-- Left Column: Recent Checkouts -->
            <div class="checkoutguard-dashboard-layout-right">
                <div class="checkoutguard-table-responsive-wrapper">
                    <div style="padding: 20px; border-bottom: 1px solid var(--checkoutguard-card-border); display: flex; justify-content: space-between; align-items: center;">
                        <h2 style="margin: 0; font-size: 18px; display: flex; align-items: center; gap: 10px;">
                            <span class="dashicons dashicons-list-view"></span>
                            <?php esc_html_e('Recent Incomplete Checkouts', 'checkoutguard'); ?>
                        </h2>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=checkoutguard-incomplete-checkouts')); ?>" class="button">
                            <?php esc_html_e('View All', 'checkoutguard'); ?>
                        </a>
                    </div>

                    <?php if (!empty($recent_checkouts)): ?>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Customer', 'checkoutguard'); ?></th>
                                    <th><?php esc_html_e('Cart Value', 'checkoutguard'); ?></th>
                                    <th><?php esc_html_e('Date', 'checkoutguard'); ?></th>
                                    <th><?php esc_html_e('Status', 'checkoutguard'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_checkouts as $checkout):
                                    // Safely decode customer_data with null check
                                    $customer_data = !empty($checkout->customer_data) ? json_decode($checkout->customer_data, true) : [];
                                    $name = !empty($customer_data['billing_first_name']) ? trim(($customer_data['billing_first_name'] ?? '') . ' ' . ($customer_data['billing_last_name'] ?? '')) : esc_html__('Guest', 'checkoutguard');

                                    // Fallback if customer_data is empty (using new structure)
                                    if (empty($name) || $name === 'Guest') {
                                        $name = trim(($checkout->first_name ?? '') . ' ' . ($checkout->last_name ?? '')) ?: esc_html__('Anonymous', 'checkoutguard');
                                    }
                                    $email = $customer_data['billing_email'] ?? ($checkout->email ?? '');
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo esc_html($name); ?></strong><br>
                                            <?php if (!CHECKOUTGUARD_IS_PRO): ?>
                                                <span class="checkoutguard-info-badge badge-warning">
                                                    <span class="dashicons dashicons-lock" style="font-size: 12px; width: 12px; height: 12px;"></span>
                                                    <?php esc_html_e('Hidden', 'checkoutguard'); ?>
                                                </span>
                                            <?php elseif (!empty($email)): ?>
                                                <small><?php echo esc_html($email); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong><?php echo wc_price($checkout->cart_value); ?></strong>
                                        </td>
                                        <td>
                                            <?php echo esc_html(human_time_diff(strtotime($checkout->created_at))) . ' ' . __('ago', 'checkoutguard'); ?>
                                        </td>
                                        <td>
                                            <span class="checkoutguard-status-badge status-incomplete">
                                                <?php esc_html_e('Incomplete', 'checkoutguard'); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="checkoutguard-table-empty-message">
                            <p><?php esc_html_e('No incomplete checkouts yet. New checkouts will appear here automatically.', 'checkoutguard'); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right Column: Fraud Protection & Promo -->
            <div class="checkoutguard-dashboard-layout-left">
                <!-- Fraud Protection Card -->
                <!-- <div class="checkoutguard-blocker-section" style="margin-top: 0;">
                    <h2><?php esc_html_e('Fraud Protection', 'checkoutguard'); ?></h2>
                    
                    <div style="margin-bottom: 20px;">
                        <div style="display: flex; gap: 10px; margin-bottom: 15px;">
                            <span class="dashicons dashicons-yes-alt" style="color: var(--checkoutguard-success-color);"></span>
                            <div>
                                <strong><?php esc_html_e('Phone Number Blocking', 'checkoutguard'); ?></strong>
                                <p style="margin: 5px 0 0; font-size: 13px; color: var(--checkoutguard-text-secondary);"><?php esc_html_e('Block suspicious phone numbers.', 'checkoutguard'); ?></p>
                            </div>
                        </div>

                        <div style="display: flex; gap: 10px; opacity: 0.7;">
                            <span class="dashicons dashicons-lock" style="color: var(--checkoutguard-text-light);"></span>
                            <div>
                                <strong><?php esc_html_e('Advanced Protection', 'checkoutguard'); ?> <span class="checkoutguard-info-badge badge-info">PRO</span></strong>
                                <p style="margin: 5px 0 0; font-size: 13px; color: var(--checkoutguard-text-secondary);"><?php esc_html_e('IP blocking & email filtering.', 'checkoutguard'); ?></p>
                            </div>
                        </div>
                    </div>

                    <div style="border-top: 1px solid var(--checkoutguard-card-border); padding-top: 15px; margin-top: 15px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                            <span style="font-size: 13px; font-weight: 600; color: var(--checkoutguard-text-secondary);"><?php esc_html_e('Blocked Numbers', 'checkoutguard'); ?></span>
                            <strong style="font-size: 18px; color: var(--checkoutguard-primary-color);"><?php 
                                $blocked_table = $wpdb->prefix . 'checkoutguard_blocked_numbers';
                                echo esc_html($wpdb->get_var("SELECT COUNT(*) FROM {$blocked_table}")); 
                            ?></strong>
                        </div>
                        
                        <a href="<?php echo admin_url('admin.php?page=checkoutguard-fraud-blocker'); ?>" class="button button-primary" style="width: 100%; text-align: center; justify-content: center;">
                            <?php esc_html_e('Manage Protection', 'checkoutguard'); ?>
                        </a>
                    </div>
                </div> -->

                <!-- Upgrade Promo -->
                <?php if (!CHECKOUTGUARD_IS_PRO): ?>
                <div class="checkoutguard-upgrade-section" style="margin-top: 20px; flex-direction: column; text-align: center; padding: 20px;">
                    <div class="checkoutguard-upgrade-icon">
                        <span class="dashicons dashicons-superhero-alt"></span>
                    </div>
                    <div class="checkoutguard-upgrade-text">
                        <h3><?php esc_html_e('Go Pro', 'checkoutguard'); ?></h3>
                        <p><?php esc_html_e('Unlock data recovery, WhatsApp integration, and advanced fraud protection.', 'checkoutguard'); ?></p>
                    </div>
                    <div class="checkoutguard-upgrade-actions" style="width: 100%;">
                        <a href="https://coderzonebd.com/pricing" target="_blank" class="button button-primary" style="width: 100%;">
                            <?php esc_html_e('Upgrade Now', 'checkoutguard'); ?>
                        </a>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}
