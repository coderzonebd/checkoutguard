<?php
// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Adds the main admin menu and submenu pages for the plugin.
 */
function cg_register_admin_menu()
{
    add_menu_page(
        __('CheckoutGuard', 'checkoutguard'),
        __('CheckoutGuard', 'checkoutguard'),
        'manage_woocommerce',
        'cg-main-dashboard',
        'cg_render_dashboard_overview_page',
        'dashicons-shield',
        3
    );

    add_submenu_page(
        'cg-main-dashboard',
        __('Dashboard Overview', 'checkoutguard'),
        __('Dashboard', 'checkoutguard'),
        'manage_woocommerce',
        'cg-main-dashboard',
        'cg_render_dashboard_overview_page'
    );

    add_submenu_page(
        'cg-main-dashboard',
        __('Checkouts', 'checkoutguard'),
        __('Checkouts', 'checkoutguard'),
        'manage_woocommerce',
        'cg-incomplete-checkouts',
        'cg_render_incomplete_checkouts_page'
    );

    add_submenu_page(
        'cg-main-dashboard',
        __('Fraud Blocker', 'checkoutguard'),
        __('Fraud Blocker', 'checkoutguard'),
        'manage_options',
        'cg-fraud-blocker',
        'cg_render_fraud_blocker_page'
    );
    add_submenu_page(
        'cg-main-dashboard',
        __('Courier Analytics', 'checkoutguard'),
        __('Courier Analytics', 'checkoutguard'),
        'manage_woocommerce',
        'cg-courier-analytics',
        'cg_render_courier_analytics_page'
    );
    $options = get_option('cg_plugin_options', []);

    if (!empty($options['enable_order_statuses'])) {
        add_submenu_page(
            'cg-main-dashboard',
            __('Order Statuses', 'checkoutguard'),
            __('Order Statuses', 'checkoutguard'),
            'manage_woocommerce',
            'cg-order-statuses',
            'cg_render_order_statuses_page'
        );
    }
    if (!empty($options['enable_invoices'])) {
        add_submenu_page(
            'cg-main-dashboard',
            __('Invoices', 'checkoutguard'),
            __('Invoices', 'checkoutguard'),
            'manage_woocommerce',
            'cg-invoices',
            'cg_render_invoices_page'
        );
    }
    add_submenu_page(
        'cg-main-dashboard',
        __('Settings', 'checkoutguard'),
        __('Settings', 'checkoutguard'),
        'manage_options', // Only admins can change settings
        'cg-settings',
        'cg_render_settings_page' // This function will be in our new file
    );
}
