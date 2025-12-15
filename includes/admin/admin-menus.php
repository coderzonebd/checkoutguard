<?php
// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Adds the admin menu and submenu pages for the free version of CheckoutGuard.
 */
function checkoutguard_register_admin_menu()
{
    // The main menu page will now be the Dashboard
    add_menu_page(
        esc_html__('CheckoutGuard', 'checkoutguard'),
        esc_html__('CheckoutGuard', 'checkoutguard'),
        'manage_woocommerce',
        'checkoutguard-dashboard', // Main slug
        'checkoutguard_render_dashboard_page', // Main page callback
        'dashicons-shield', // Changed icon to match the plugin purpose
        5
    );
    
    // Sub-menu item for Dashboard (always visible)
    add_submenu_page(
        'checkoutguard-dashboard',
        esc_html__('Dashboard', 'checkoutguard'),
        esc_html__('Dashboard', 'checkoutguard'),
        'manage_woocommerce',
        'checkoutguard-dashboard', // Parent and own slug are the same
        'checkoutguard_render_dashboard_page'
    );

    // Sub-menu item for Incomplete Checkouts (check if enabled)
    if (checkoutguard_get_setting('enable_incomplete_checkout_tracking', true)) {
        add_submenu_page(
            'checkoutguard-dashboard',
            esc_html__('Incomplete Checkouts', 'checkoutguard'),
            esc_html__('Incomplete Checkouts', 'checkoutguard'),
            'manage_woocommerce',
            'checkoutguard-incomplete-checkouts',
            'checkoutguard_render_incomplete_checkouts_page'
        );
    }

    // Sub-menu item for the limited Fraud Blocker (check if enabled)
    if (checkoutguard_get_setting('enable_fraud_blocker', true)) {
        add_submenu_page(
            'checkoutguard-dashboard',
            esc_html__('Fraud Blocker', 'checkoutguard'),
            esc_html__('Fraud Blocker', 'checkoutguard'),
            'manage_options', // Only admins can access this
            'checkoutguard-fraud-blocker',
            'checkoutguard_render_fraud_blocker_page'
        );
    }

    // Sub-menu item for Courier Check (check if enabled)
    if (checkoutguard_get_setting('enable_courier_check', true)) {
        add_submenu_page(
            'checkoutguard-dashboard',
            esc_html__('Courier Check', 'checkoutguard'),
            esc_html__('Courier Check', 'checkoutguard'),
            'manage_woocommerce',
            'checkoutguard-courier-check',
            'checkoutguard_render_courier_check_page'
        );
    }

    // Sub-menu item for Invoice & Shipping Slip (check if enabled)
    if (checkoutguard_get_setting('enable_invoice_shipping', true)) {
        add_submenu_page(
            'checkoutguard-dashboard',
            esc_html__('Invoice & Shipping', 'checkoutguard'),
            esc_html__('Invoice & Shipping', 'checkoutguard'),
            'manage_woocommerce',
            'checkoutguard-invoice',
            'checkoutguard_render_invoice_page'
        );
    }

    // Sub-menu item for Settings (always visible)
    add_submenu_page(
        'checkoutguard-dashboard',
        esc_html__('Settings', 'checkoutguard'),
        esc_html__('Settings', 'checkoutguard'),
        'manage_options',
        'checkoutguard-settings',
        'checkoutguard_render_settings_page'
    );
}
