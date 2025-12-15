<?php
// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Enqueues scripts and styles for the FRONT-END checkout page.
 */
function checkoutguard_enqueue_checkout_assets()
{
    if (function_exists('is_checkout') && is_checkout() && !is_order_received_page() && !is_checkout_pay_page()) {
        wp_enqueue_script(
            'checkoutguard-checkout-tracker',
            CHECKOUTGUARD_PLUGIN_URL . 'assets/js/tracker.js',
            ['jquery'],
            CHECKOUTGUARD_VERSION,
            true
        );

        wp_localize_script(
            'checkoutguard-checkout-tracker',
            'checkoutguard_checkout_params',
            [
                'ajax_url' => admin_url('admin-ajax.php'),
                'save_data_nonce' => wp_create_nonce('checkoutguard_save_checkout_data_nonce'),
            ]
        );
    }
}

/**
 * Enqueues scripts and styles for the plugin's ADMIN pages.
 */
function checkoutguard_enqueue_admin_assets($hook_suffix)
{
    $screen = get_current_screen();
    if (!$screen) {
        return;
    }

    $is_checkoutguard_page = (strpos($screen->id, 'checkoutguard-') !== false);
    $is_main_dashboard = ($screen->id === 'dashboard');
    $is_single_order_page = ($screen->id === 'shop_order' || ($screen->id === 'woocommerce_page_wc-orders' && ($_GET['action'] ?? '') === 'edit'));
    $is_courier_check_page = ($screen->id === 'checkoutguard_page_checkoutguard-courier-check');
    $is_settings_page = ($screen->id === 'checkoutguard_page_checkoutguard-settings');

    if ($is_checkoutguard_page || $is_main_dashboard || $is_single_order_page) {
        // Enqueue Dashicons for our custom icons
        wp_enqueue_style('dashicons');
        
        wp_enqueue_style(
            'checkoutguard-admin-styles',
            CHECKOUTGUARD_PLUGIN_URL . 'assets/css/admin-styles.css',
            ['dashicons'],
            CHECKOUTGUARD_VERSION
        );

        wp_enqueue_script(
            'checkoutguard-admin-tracker',
            CHECKOUTGUARD_PLUGIN_URL . 'assets/js/admin-tracker.js',
            ['jquery'],
            CHECKOUTGUARD_VERSION,
            true
        );

        wp_localize_script(
            'checkoutguard-admin-tracker',
            'checkoutguard_admin_params',
            [
                'ajax_url' => admin_url('admin-ajax.php'),
                'view_details_nonce' => wp_create_nonce('checkoutguard_view_details_nonce'),
                'mark_cancelled_nonce' => wp_create_nonce('checkoutguard_mark_cancelled_nonce'),
                'fraud_blocker_nonce' => wp_create_nonce('checkoutguard_fraud_blocker_nonce'),
                'delete_blocker_item_nonce' => wp_create_nonce('checkoutguard_delete_blocked_item_nonce'),
            ]
        );
    }

    // Enqueue courier check assets on the courier check page
    if ($is_courier_check_page) {
        // Enqueue Dashicons
        wp_enqueue_style('dashicons');
        
        // Enqueue admin styles first for base styles
        wp_enqueue_style(
            'checkoutguard-admin-styles',
            CHECKOUTGUARD_PLUGIN_URL . 'assets/css/admin-styles.css',
            ['dashicons'],
            CHECKOUTGUARD_VERSION
        );
        
        // Then enqueue courier-specific styles
        wp_enqueue_style(
            'checkoutguard-courier-check-styles',
            CHECKOUTGUARD_PLUGIN_URL . 'assets/css/courier-check.css',
            ['dashicons', 'checkoutguard-admin-styles'],
            CHECKOUTGUARD_VERSION
        );

        wp_enqueue_script(
            'checkoutguard-courier-check-script',
            CHECKOUTGUARD_PLUGIN_URL . 'assets/js/courier-check.js',
            ['jquery'],
            CHECKOUTGUARD_VERSION,
            true
        );

        wp_localize_script(
            'checkoutguard-courier-check-script',
            'checkoutguardCourier',
            [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('checkoutguard_courier_check_nonce'),
                'noRecentSearches' => esc_html__('No recent searches', 'checkoutguard'),
                'pluginUrl' => CHECKOUTGUARD_PLUGIN_URL,
            ]
        );
    }

    // Enqueue invoice assets on the invoice page
    if ($screen->id === 'checkoutguard_page_checkoutguard-invoice') {
        wp_enqueue_style('dashicons');
        
        // Enqueue admin styles
        wp_enqueue_style(
            'checkoutguard-admin-styles',
            CHECKOUTGUARD_PLUGIN_URL . 'assets/css/admin-styles.css',
            ['dashicons'],
            CHECKOUTGUARD_VERSION
        );

        wp_enqueue_script(
            'checkoutguard-invoice-script',
            CHECKOUTGUARD_PLUGIN_URL . 'assets/js/invoice.js',
            ['jquery'],
            CHECKOUTGUARD_VERSION,
            true
        );

        wp_localize_script(
            'checkoutguard-invoice-script',
            'checkoutguard_invoice_params',
            [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('checkoutguard_invoice_nonce'),
            ]
        );
    }
}