<?php
// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Adds the custom widget to the main WordPress dashboard for CheckoutGuard.
 */
function checkoutguard_register_dashboard_widget()
{
    // Only add the widget for users who can manage WooCommerce.
    if (current_user_can('manage_woocommerce')) {
        wp_add_dashboard_widget(
            'checkoutguard_dashboard_widget',
            esc_html__('CheckoutGuard Summary', 'checkoutguard'),
            'checkoutguard_render_dashboard_widget_content'
        );
    }
}
add_action('wp_dashboard_setup', 'checkoutguard_register_dashboard_widget');

/**
 * Renders the content for the free version's dashboard widget.
 */
function checkoutguard_render_dashboard_widget_content()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'checkoutguard_incomplete_checkouts';

    // Query for incomplete checkouts from the last 24 hours.
    $results = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT COUNT(id) as count, SUM(cart_value) as value FROM {$table_name} WHERE status = 'incomplete' AND created_at >= %s",
            date('Y-m-d H:i:s', strtotime('-24 hours'))
        )
    );

    $count = $results->count ?? 0;
    $value = $results->value ?? 0;
    
    $incomplete_url = admin_url('admin.php?page=checkoutguard-incomplete-checkouts');
    ?>
    <div class="checkoutguard-widget-container">
        <p><?php esc_html_e('Showing a summary of incomplete checkouts from the last 24 hours.', 'checkoutguard'); ?></p>
        
        <div class="cg-stat-row">
            <a href="<?php echo esc_url($incomplete_url); ?>" class="cg-stat-box cg-stat-box-link">
                <h3><?php esc_html_e('Incomplete Checkouts', 'checkoutguard'); ?></h3>
                <p id="checkoutguard-stat-incomplete-count"><?php echo esc_html($count); ?></p>
            </a>
            <div class="cg-stat-box">
                <h3><?php esc_html_e('Incomplete Value', 'checkoutguard'); ?></h3>
                <p id="checkoutguard-stat-incomplete-value"><?php echo wp_kses_post(wc_price($value)); ?></p>
            </div>
        </div>

        <hr>
        
        <div class="cg-upgrade-section">
            <div class="cg-upgrade-text">
                <h3><?php esc_html_e('Unlock Full Analytics & Recovery', 'checkoutguard'); ?></h3>
                <p><?php esc_html_e('Upgrade to CheckoutGuard Pro to see detailed charts, filter by date, and recover lost sales with one click.', 'checkoutguard'); ?></p>
            </div>
            <div class="cg-upgrade-actions">
                <a href="https://coderzonebd.com/pricing" target="_blank" class="button button-primary"><?php esc_html_e('Upgrade Now', 'checkoutguard'); ?></a>
            </div>
        </div>
    </div>
    <?php
}