<?php
// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Adds the custom widget to the main WordPress dashboard.
 */
function cg_register_dashboard_widget()
{
    if (current_user_can('manage_woocommerce')) {
        wp_add_dashboard_widget(
            'cg_main_dashboard_widget',
            __('CheckoutGuard Summary', 'checkoutguard'),
            'cg_render_main_dashboard_widget_content'
        );
    }
}
add_action('wp_dashboard_setup', 'cg_register_dashboard_widget');

/**
 * Renders the content for the custom dashboard widget.
 * Now includes lockdown logic based on license status.
 */
function cg_render_main_dashboard_widget_content()
{
    // The #dashboard-widgets-wrap .postbox .inside selector in WordPress has position:relative,
    echo '<div class="cg-widget-container-wrapper">';

    $status = get_option('cg_license_status', 'inactive');
    if ($status === 'inactive' || $status === 'suspended') {

        $whatsapp_link = 'https://chat.whatsapp.com/JBc983oZeeMKVavdI3cgMl';
        $contact_link_start = '<a href="' . esc_url($whatsapp_link) . '" target="_blank">';
        $contact_link_end = '</a>';

        echo '<div class="cg-widget-lockdown-overlay">';
        echo '<p>';

        if ($status === 'inactive') {
            printf(
                /* translators: 1: opening <a> tag, 2: closing </a> tag */
                __('Your site is not active. Please %1$scontact us%2$s to get it activated.', 'checkoutguard'),
                $contact_link_start,
                $contact_link_end
            );
        } elseif ($status === 'suspended') {
            printf(
                /* translators: 1: opening <a> tag, 2: closing </a> tag */
                __('Your license is suspended. Please %1$scontact us%2$s to solve the issue.', 'checkoutguard'),
                $contact_link_start,
                $contact_link_end
            );
        } else {
            _e('There is an issue with your license status.', 'checkoutguard');
        }

        echo '</p>';
        echo '<a href="' . esc_url(admin_url('admin.php?page=cg-settings')) . '" class="button button-primary" style="margin-top: 15px;">' . __('Go to Settings', 'checkoutguard') . '</a>';
        echo '</div>'; // Close the wrapper
        return;
    }

    cg_render_date_filter_controls('dashboard', 'today', 'created_at');

    $incomplete_url = admin_url('admin.php?page=cg-incomplete-checkouts');
    $recovered_url = admin_url('admin.php?page=cg-incomplete-checkouts&tab=recovered');
    $followup_url = admin_url('admin.php?page=cg-incomplete-checkouts&tab=hold');
    $cancelled_url = admin_url('admin.php?page=cg-incomplete-checkouts&tab=cancelled');
    ?>
    <div id="cg-dashboard-loading" style="display:none; text-align:center; padding:20px;">
        <p><?php _e('Loading dashboard data...', 'checkoutguard'); ?></p>
    </div>

    <div class="cg-dashboard-chart-container" style="max-width:250px;"><canvas id="cgOrderStatusChart"></canvas></div>

    <div class="cg-dashboard-counts cg-stat-row">
        <a href="<?php echo esc_url($incomplete_url); ?>" class="cg-stat-box cg-stat-box-link">
            <h3><?php _e('Incomplete', 'checkoutguard'); ?></h3>
            <p id="cg-stat-incomplete-count">...</p>
        </a>
        <a href="<?php echo esc_url($recovered_url); ?>" class="cg-stat-box cg-stat-box-link">
            <h3><?php _e('Recovered', 'checkoutguard'); ?></h3>
            <p id="cg-stat-recovered-count">...</p>
        </a>
        <a href="<?php echo esc_url($followup_url); ?>" class="cg-stat-box cg-stat-box-link">
            <h3><?php _e('On Hold', 'checkoutguard'); ?></h3>
            <p id="cg-stat-hold-count">...</p>
        </a>
        <a href="<?php echo esc_url($cancelled_url); ?>" class="cg-stat-box cg-stat-box-link">
            <h3><?php _e('Cancelled', 'checkoutguard'); ?></h3>
            <p id="cg-stat-cancelled-count">...</p>
        </a>
    </div>

    <?php
    echo '</div>'; // Close the wrapper
}