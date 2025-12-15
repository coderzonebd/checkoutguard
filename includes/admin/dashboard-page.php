<?php
// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renders the main dashboard page for CheckoutGuard.
 */
function checkoutguard_render_dashboard_page()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'checkoutguard_incomplete_checkouts';

    // Get stats for the dashboard
    $total_incomplete = $wpdb->get_var("SELECT COUNT(id) FROM {$table_name} WHERE status = 'incomplete'");
    $total_value = $wpdb->get_var("SELECT SUM(cart_value) FROM {$table_name} WHERE status = 'incomplete'");

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
        $wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE status = 'incomplete' ORDER BY created_at DESC LIMIT 5"
        )
    );

    ?>
    <div class="wrap checkoutguard-wrap">
        <!-- Modern Dashboard Header -->
        <div class="cg-page-header-modern">
            <div class="cg-header-content">
                <div class="cg-header-icon">
                    <span class="dashicons dashicons-dashboard"></span>
                </div>
                <div class="cg-header-text">
                    <h1><?php esc_html_e('CheckoutGuard Dashboard', 'checkoutguard'); ?></h1>
                    <p class="cg-header-subtitle">
                        <?php esc_html_e('Complete overview of your checkout tracking and fraud protection', 'checkoutguard'); ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- Modern Dashboard Stats -->
        <div class="cg-stat-cards-modern">
            <div class="cg-stat-card-modern cg-stat-primary">
                <div class="cg-stat-icon">
                    <span class="dashicons dashicons-cart"></span>
                </div>
                <div class="cg-stat-content">
                    <p class="cg-stat-label"><?php esc_html_e('All Incomplete', 'checkoutguard'); ?></p>
                    <p class="cg-stat-number"><?php echo esc_html($total_incomplete ?? 0); ?></p>
                    <p class="cg-stat-desc"><?php esc_html_e('Total Carts', 'checkoutguard'); ?></p>
                </div>
            </div>

            <div class="cg-stat-card-modern cg-stat-success">
                <div class="cg-stat-icon">
                    <span class="dashicons dashicons-money-alt"></span>
                </div>
                <div class="cg-stat-content">
                    <p class="cg-stat-label"><?php esc_html_e('Total Value', 'checkoutguard'); ?></p>
                    <p class="cg-stat-number"><?php echo wc_price($total_value ?? 0); ?></p>
                    <p class="cg-stat-desc"><?php esc_html_e('At Risk', 'checkoutguard'); ?></p>
                </div>
            </div>

            <div class="cg-stat-card-modern cg-stat-info">
                <div class="cg-stat-icon">
                    <span class="dashicons dashicons-clock"></span>
                </div>
                <div class="cg-stat-content">
                    <p class="cg-stat-label"><?php esc_html_e('Last 24 Hours', 'checkoutguard'); ?></p>
                    <p class="cg-stat-number"><?php echo esc_html($last_24h_incomplete ?? 0); ?></p>
                    <p class="cg-stat-desc"><?php esc_html_e('New Carts', 'checkoutguard'); ?></p>
                </div>
            </div>

            <div class="cg-stat-card-modern cg-stat-warning">
                <div class="cg-stat-icon">
                    <span class="dashicons dashicons-calendar-alt"></span>
                </div>
                <div class="cg-stat-content">
                    <p class="cg-stat-label"><?php esc_html_e('Last 7 Days', 'checkoutguard'); ?></p>
                    <p class="cg-stat-number"><?php echo esc_html($last_7d_incomplete ?? 0); ?></p>
                    <p class="cg-stat-desc"><?php esc_html_e('Weekly Total', 'checkoutguard'); ?></p>
                </div>
            </div>
        </div>

        <!-- Dashboard Grid -->
        <div class="cg-dashboard-grid-modern">
            <!-- Recent Checkouts Card -->
            <div class="cg-dashboard-card-modern">
                <div class="cg-dashboard-card-header">
                    <div class="cg-card-title-group">
                        <span class="dashicons dashicons-list-view"></span>
                        <h2><?php esc_html_e('Recent Incomplete Checkouts', 'checkoutguard'); ?></h2>
                    </div>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=checkoutguard-incomplete-checkouts')); ?>"
                        class="cg-btn cg-btn-primary">
                        <?php esc_html_e('View All', 'checkoutguard'); ?>
                        <span class="dashicons dashicons-arrow-right-alt"></span>
                    </a>
                </div>

                <?php if (!empty($recent_checkouts)): ?>
                    <div class="cg-dashboard-table-wrapper">
                        <table class="cg-dashboard-table">
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
                                    $customer_data = json_decode($checkout->customer_data, true);
                                    $name = !empty($customer_data['billing_first_name']) ? $customer_data['billing_first_name'] . ' ' . $customer_data['billing_last_name'] : esc_html__('Guest', 'checkoutguard');

                                    // Fallback if customer_data is empty (using new structure)
                                    if (empty($name) || $name === 'Guest') {
                                        $name = trim($checkout->first_name . ' ' . $checkout->last_name) ?: esc_html__('Anonymous', 'checkoutguard');
                                    }
                                    $email = $customer_data['billing_email'] ?? $checkout->email;
                                    ?>
                                    <tr class="cg-dashboard-row">
                                        <td>
                                            <div class="cg-dashboard-customer">
                                                <div class="cg-customer-avatar-modern">
                                                    <img src="<?php echo esc_url(get_avatar_url($email ?: 'unknown@example.com', ['size' => 40])); ?>" alt="Avatar">
                                                </div>
                                                <div class="cg-customer-info-modern">
                                                    <span class="cg-customer-name-modern"><?php echo esc_html($name); ?></span>
                                                    <?php if (!CHECKOUTGUARD_IS_PRO): ?>
                                                        <span class="cg-customer-email-modern cg-pro-badge-small">
                                                            <span class="dashicons dashicons-lock"></span>
                                                            <?php esc_html_e('Hidden', 'checkoutguard'); ?>
                                                        </span>
                                                    <?php elseif (!empty($email)): ?>
                                                        <span class="cg-customer-email-modern"><?php echo esc_html($email); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="cg-cart-value-badge">
                                                <span class="dashicons dashicons-cart"></span>
                                                <?php echo wc_price($checkout->cart_value); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="cg-date-text">
                                                <?php echo esc_html(human_time_diff(strtotime($checkout->created_at))) . ' ' . __('ago', 'checkoutguard'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="cg-status-badge cg-status-incomplete">
                                                <?php esc_html_e('Incomplete', 'checkoutguard'); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="cg-empty-state-dashboard">
                        <span class="dashicons dashicons-cart"></span>
                        <p><?php esc_html_e('No incomplete checkouts yet', 'checkoutguard'); ?></p>
                        <small><?php esc_html_e('New checkouts will appear here automatically', 'checkoutguard'); ?></small>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Fraud Protection Card -->
            <div class="cg-dashboard-card-modern cg-protection-card">
                <div class="cg-dashboard-card-header">
                    <div class="cg-card-title-group">
                        <span class="dashicons dashicons-shield"></span>
                        <h2><?php esc_html_e('Fraud Protection', 'checkoutguard'); ?></h2>
                    </div>
                    <a href="<?php echo admin_url('admin.php?page=checkoutguard-fraud-blocker'); ?>"
                        class="cg-btn cg-btn-secondary">
                        <?php esc_html_e('Manage', 'checkoutguard'); ?>
                        <span class="dashicons dashicons-arrow-right-alt"></span>
                    </a>
                </div>
                <div class="cg-protection-content">
                    <div class="cg-protection-feature">
                        <div class="cg-feature-icon cg-icon-active">
                            <span class="dashicons dashicons-yes-alt"></span>
                        </div>
                        <div class="cg-feature-details">
                            <h3><?php esc_html_e('Phone Number Blocking', 'checkoutguard'); ?></h3>
                            <p><?php esc_html_e('Block suspicious phone numbers from completing checkout', 'checkoutguard'); ?></p>
                        </div>
                    </div>

                    <div class="cg-protection-feature cg-feature-locked">
                        <div class="cg-feature-icon cg-icon-pro">
                            <span class="dashicons dashicons-lock"></span>
                        </div>
                        <div class="cg-feature-details">
                            <h3><?php esc_html_e('Advanced Protection', 'checkoutguard'); ?>
                                <span class="cg-pro-tag"><?php esc_html_e('PRO', 'checkoutguard'); ?></span>
                            </h3>
                            <p><?php esc_html_e('Unlock IP blocking, email domain filtering, and advanced fraud detection', 'checkoutguard'); ?></p>
                            <a href="https://coderzonebd.com/pricing" target="_blank" class="cg-upgrade-link-inline">
                                <?php esc_html_e('Upgrade to Pro', 'checkoutguard'); ?>
                                <span class="dashicons dashicons-arrow-right-alt"></span>
                            </a>
                        </div>
                    </div>

                    <div class="cg-protection-stats">
                        <div class="cg-protection-stat-item">
                            <span class="cg-stat-icon-small">
                                <span class="dashicons dashicons-shield-alt"></span>
                            </span>
                            <div>
                                <strong><?php echo esc_html($wpdb->get_var("SELECT COUNT(*) FROM " . $wpdb->prefix . "checkoutguard_blocked_numbers")); ?></strong>
                                <span><?php esc_html_e('Blocked Numbers', 'checkoutguard'); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
}
