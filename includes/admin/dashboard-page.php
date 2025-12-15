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
        <div class="cg-page-header">
            <h1><?php esc_html_e('CheckoutGuard Dashboard', 'checkoutguard'); ?></h1>
            <p class="page-subtitle">
                <?php esc_html_e('Monitor your store\'s checkout activity and protect against potential fraud.', 'checkoutguard'); ?>
            </p>
        </div>

        <div class="cg-stat-cards-grid">
            <div class="cg-stat-card">
                <p class="stat-title"><span class="dashicons dashicons-cart"></span>
                    <?php esc_html_e('Total Incomplete Carts', 'checkoutguard'); ?></p>
                <p class="stat-value"><?php echo esc_html($total_incomplete ?? 0); ?></p>
            </div>
            <div class="cg-stat-card">
                <p class="stat-title"><span class="dashicons dashicons-money-alt"></span>
                    <?php esc_html_e('Total Value at Risk', 'checkoutguard'); ?></p>
                <p class="stat-value"><?php echo wc_price($total_value ?? 0); ?></p>
            </div>
            <div class="cg-stat-card">
                <p class="stat-title"><span class="dashicons dashicons-clock"></span>
                    <?php esc_html_e('Last 24 Hours', 'checkoutguard'); ?></p>
                <p class="stat-value"><?php echo esc_html($last_24h_incomplete ?? 0); ?></p>
            </div>
            <div class="cg-stat-card">
                <p class="stat-title"><span class="dashicons dashicons-calendar-alt"></span>
                    <?php esc_html_e('Last 7 Days', 'checkoutguard'); ?></p>
                <p class="stat-value"><?php echo esc_html($last_7d_incomplete ?? 0); ?></p>
            </div>
        </div>

        <div class="cg-dashboard-grid">
            <div class="cg-card">
                <div class="cg-card-header">
                    <h2><span class="dashicons dashicons-list-view"></span>
                        <?php esc_html_e('Recent Incomplete Checkouts', 'checkoutguard'); ?></h2>
                    <a href="<?php echo esc_url( admin_url('admin.php?page=checkoutguard-incomplete-checkouts') ); ?>"
                        class="button button-primary"><?php esc_html_e('View All', 'checkoutguard'); ?></a>
                </div>

                <?php if (!empty($recent_checkouts)): ?>
                    <table class="cg-data-table">
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
                                    $name = trim($checkout->first_name . ' ' . $checkout->last_name) ?: esc_html__('Guest', 'checkoutguard');
                                }
                                $email = $customer_data['billing_email'] ?? $checkout->email;
                                ?>
                                <tr>
                                    <td>
                                        <div class="cg-customer-info">
                                            <span class="cg-customer-avatar"><?php echo esc_html( substr($name, 0, 1) ); ?></span>
                                            <div class="cg-customer-details">
                                                <span class="cg-customer-name"><?php echo esc_html($name); ?></span>
                                                <?php if (!empty($email)): ?>
                                                    <span class="cg-customer-email"><?php echo esc_html($email); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?php echo wc_price($checkout->cart_value); ?></td>
                                    <td><?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($checkout->created_at)); ?>
                                    </td>
                                    <td><span
                                            class="cg-status-badge incomplete"><?php esc_html_e('Incomplete', 'checkoutguard'); ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="cg-empty-state">
                        <p><?php esc_html_e('No incomplete checkouts found.', 'checkoutguard'); ?></p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="cg-card">
                <div class="cg-card-header">
                    <h2><span class="dashicons dashicons-shield"></span>
                        <?php esc_html_e('Fraud Protection', 'checkoutguard'); ?></h2>
                    <a href="<?php echo admin_url('admin.php?page=checkoutguard-fraud-blocker'); ?>"
                        class="button button-primary"><?php esc_html_e('Manage', 'checkoutguard'); ?></a>
                </div>
                <div class="cg-card-content">
                    <div class="cg-feature-list">
                        <div class="cg-feature-item">
                            <span class="dashicons dashicons-yes-alt"></span>
                            <div>
                                <h3><?php esc_html_e('Phone Number Blocking', 'checkoutguard'); ?></h3>
                                <p><?php esc_html_e('Block suspicious phone numbers from completing checkout.', 'checkoutguard'); ?>
                                </p>
                            </div>
                        </div>
                        <?php // REMOVED: Locked "Pro" features (IP and Email blocking) ?>
                        <div class="cg-feature-item">
                            <span class="dashicons dashicons-star-filled"></span>
                            <div>
                                <h3><?php esc_html_e('Get More Protection (Pro)', 'checkoutguard'); ?></h3>
                                <p><?php esc_html_e('Upgrade to Pro to block by IP address and email domain.', 'checkoutguard'); ?>
                                </p>
                                <a href="https://coderzonebd.com/pricing" target="_blank"
                                    style="margin-top: 8px;"><?php esc_html_e('Learn More', 'checkoutguard'); ?></a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
}
