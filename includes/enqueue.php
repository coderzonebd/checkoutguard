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
    // Check if WooCommerce is active first
    if (!class_exists('WooCommerce')) {
        return;
    }
    
    // Multiple checks for checkout page detection
    $is_checkout = false;
    
    // Primary check: Direct page ID comparison (most reliable)
    $checkout_page_id = get_option('woocommerce_checkout_page_id');
    
    if ($checkout_page_id && function_exists('is_page')) {
        $is_checkout = is_page($checkout_page_id);
    }
    
    // Secondary check: Use WooCommerce functions if available
    if (!$is_checkout && function_exists('is_checkout')) {
        $is_checkout = (is_checkout() && !is_order_received_page() && !is_checkout_pay_page());
    }
    
    // Tertiary check: Check query vars
    if (!$is_checkout && isset($GLOBALS['wp'])) {
        global $wp;
        if (isset($wp->query_vars['pagename']) && $checkout_page_id) {
            $checkout_page = get_post($checkout_page_id);
            if ($checkout_page && $wp->query_vars['pagename'] === $checkout_page->post_name) {
                $is_checkout = true;
            }
        }
    }
    
    // Apply filter to allow themes/plugins to override
    $is_checkout = apply_filters('checkoutguard_is_checkout_page', $is_checkout);
    
    if ($is_checkout) {
        // Enqueue jQuery if not already enqueued
        if (!wp_script_is('jquery', 'enqueued')) {
            wp_enqueue_script('jquery');
        }
        
        wp_enqueue_script(
            'checkoutguard-checkout-tracker',
            CHECKOUTGUARD_PLUGIN_URL . 'assets/js/tracker.js',
            ['jquery'],
            CHECKOUTGUARD_VERSION . '-' . time(), // Add timestamp to prevent caching issues
            true
        );

        wp_localize_script(
            'checkoutguard-checkout-tracker',
            'checkoutguard_checkout_params',
            [
                'ajax_url' => admin_url('admin-ajax.php'),
                'save_data_nonce' => wp_create_nonce('checkoutguard_save_checkout_data_nonce'),
                'plugin_version' => CHECKOUTGUARD_VERSION,
            ]
        );
    }
}

/**
 * Add admin bar menu for quick diagnostics
 */
function checkoutguard_admin_bar_menu($wp_admin_bar) {
    if (!current_user_can('manage_options')) {
        return;
    }
    
    if (function_exists('is_checkout') && is_checkout()) {
        $wp_admin_bar->add_node([
            'id' => 'checkoutguard-debug',
            'title' => '🛡️ CheckoutGuard Active',
            'href' => '#',
            'meta' => [
                'title' => 'CheckoutGuard tracking is active on this page',
            ]
        ]);
    }
}
add_action('admin_bar_menu', 'checkoutguard_admin_bar_menu', 100);

/**
 * Enqueues scripts and styles for the plugin's ADMIN pages.
 */
function checkoutguard_enqueue_admin_assets($hook_suffix)
{
    $screen = get_current_screen();
    if (!$screen) {
        return;
    }

    $is_checkoutguard_page = (strpos($screen->id, 'checkoutguard-') !== false || strpos($screen->id, 'checkoutguard_page_') !== false);
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
                'recover_order_nonce' => wp_create_nonce('checkoutguard_recover_order_nonce'),
                'mark_hold_nonce' => wp_create_nonce('checkoutguard_mark_hold_nonce'),
                'reopen_checkout_nonce' => wp_create_nonce('checkoutguard_reopen_checkout_nonce'),
                'edit_follow_up_nonce' => wp_create_nonce('checkoutguard_edit_follow_up_nonce'),
                'fraud_blocker_nonce' => wp_create_nonce('checkoutguard_fraud_blocker_nonce'),
                'delete_blocker_item_nonce' => wp_create_nonce('checkoutguard_delete_blocked_item_nonce'),
                'fetch_incomplete_nonce' => wp_create_nonce('checkoutguard_fetch_incomplete_nonce'),
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

        // Load Chart.js from local bundle to avoid CDN dependency
        wp_register_script(
            'checkoutguard-chartjs',
            CHECKOUTGUARD_PLUGIN_URL . 'assets/js/chart.min.js',
            [],
            '4.4.2',
            true
        );

        wp_enqueue_script(
            'checkoutguard-courier-check-script',
            CHECKOUTGUARD_PLUGIN_URL . 'assets/js/courier-check.js',
            ['jquery', 'checkoutguard-chartjs'],
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