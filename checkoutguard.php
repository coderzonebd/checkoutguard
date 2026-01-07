<?php
/**
 * Plugin Name: CheckoutGuard
 * Plugin URI: https://coderzonebd.com/
 * Description: Track incomplete WooCommerce checkouts, block fraud, check courier risk, and generate invoices.
 * Version: 1.1.4
 * Requires at least: 5.6
 * Tested up to: 6.9
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 10.4.3
 * Author: Coder Zone BD
 * Author URI: https://coderzonebd.com/about-us
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: checkoutguard
 * Requires Plugins: woocommerce
 * @package CheckoutGuard
 */

// If this file is called directly, abort.
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

// --- CheckoutGuard Version Control ---
// Define Pro Constant slightly later to allow Pro plugin to load first
add_action( 'plugins_loaded', function() {
    if ( !defined('CHECKOUTGUARD_IS_PRO') ) {
        define('CHECKOUTGUARD_IS_PRO', class_exists('CheckoutGuard_Pro'));
    }
}, 5 );

// Define Core Plugin Constants
define('CHECKOUTGUARD_VERSION', '1.1.4');
define('CHECKOUTGUARD_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CHECKOUTGUARD_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CHECKOUTGUARD_PLUGIN_FILE', __FILE__);
define('CHECKOUTGUARD_INC_DIR', CHECKOUTGUARD_PLUGIN_DIR . 'includes/');
define('CHECKOUTGUARD_ADMIN_DIR', CHECKOUTGUARD_INC_DIR . 'admin/');

/**
 * Main plugin initialization function.
 */
function checkoutguard_init_plugin()
{
    // Load text domain for translations first
    load_plugin_textdomain( 'checkoutguard', false, dirname( plugin_basename( CHECKOUTGUARD_PLUGIN_FILE ) ) . '/languages' );
    
    // Load essential files that contain translation strings
    require_once CHECKOUTGUARD_INC_DIR . 'utils.php';
    require_once CHECKOUTGUARD_ADMIN_DIR . 'admin-pages.php';
    require_once CHECKOUTGUARD_ADMIN_DIR . 'courier-check-page.php';
    require_once CHECKOUTGUARD_ADMIN_DIR . 'invoice-page.php';
    require_once CHECKOUTGUARD_ADMIN_DIR . 'settings-page.php';
    require_once CHECKOUTGUARD_INC_DIR . 'courier-check-ajax.php';
    
    // Check if WooCommerce is active
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'checkoutguard_woocommerce_missing_notice');
        return;
    }

    // CRITICAL FIX: Require activation file here to ensure functions are available
    require_once CHECKOUTGUARD_INC_DIR . 'activation.php';

    // Auto-fix tables if missing (Critical for fresh installs)
    // Wrapped in function_exists to prevent fatal errors if file update failed
    if (function_exists('checkoutguard_ensure_tables_exist')) {
        checkoutguard_ensure_tables_exist();
    }

    // Load all remaining plugin files for the free version
    require_once CHECKOUTGUARD_INC_DIR . 'enqueue.php';
    require_once CHECKOUTGUARD_INC_DIR . 'ajax-handlers.php';
    require_once CHECKOUTGUARD_INC_DIR . 'checkout-integration.php';
    require_once CHECKOUTGUARD_INC_DIR . 'checkout-field-manager.php';
    require_once CHECKOUTGUARD_ADMIN_DIR . 'admin-menus.php';
    require_once CHECKOUTGUARD_ADMIN_DIR . 'dashboard-page.php';
    require_once CHECKOUTGUARD_ADMIN_DIR . 'dashboard-widget.php';
    require_once CHECKOUTGUARD_ADMIN_DIR . 'fraud-protection-page.php';
    require_once CHECKOUTGUARD_ADMIN_DIR . 'field-manager-page.php';
    require_once CHECKOUTGUARD_ADMIN_DIR . 'woocommerce-integration.php';
    require_once CHECKOUTGUARD_ADMIN_DIR . 'diagnostics.php';
    
    // Load Report page (check if file exists in pro plugin)
    $report_page_path = dirname(CHECKOUTGUARD_PLUGIN_DIR) . '/checkoutguard-pro/includes/admin/report-page.php';
    if (file_exists($report_page_path)) {
        require_once $report_page_path;
    }

    // Add all WordPress action hooks
    add_action('wp_enqueue_scripts', 'checkoutguard_enqueue_checkout_assets');
    add_action('admin_enqueue_scripts', 'checkoutguard_enqueue_admin_assets');
    add_action('admin_menu', 'checkoutguard_register_admin_menu');

    // Add all AJAX hooks
    add_action('wp_ajax_checkoutguard_save_checkout_data', 'checkoutguard_handle_save_checkout_data');
    add_action('wp_ajax_nopriv_checkoutguard_save_checkout_data', 'checkoutguard_handle_save_checkout_data');
    
    // Test AJAX endpoint for debugging
    add_action('wp_ajax_checkoutguard_test', 'checkoutguard_test_ajax');
    add_action('wp_ajax_nopriv_checkoutguard_test', 'checkoutguard_test_ajax');
    
    add_action('wp_ajax_checkoutguard_get_checkout_details', 'checkoutguard_get_incomplete_checkout_details_ajax_handler');
    add_action('wp_ajax_checkoutguard_mark_cancelled', 'checkoutguard_mark_cancelled_ajax_handler');
    add_action('wp_ajax_checkoutguard_recover_order', 'checkoutguard_recover_order_ajax_handler');
    add_action('wp_ajax_checkoutguard_mark_hold', 'checkoutguard_mark_hold_ajax_handler');
    add_action('wp_ajax_checkoutguard_reopen_checkout', 'checkoutguard_reopen_checkout_ajax_handler');
    add_action('wp_ajax_checkoutguard_edit_follow_up_date', 'checkoutguard_edit_follow_up_date_ajax_handler');
    add_action('wp_ajax_checkoutguard_add_blocked_item', 'checkoutguard_handle_add_blocked_item_ajax');
    add_action('wp_ajax_checkoutguard_delete_blocked_item', 'checkoutguard_handle_delete_blocked_item_ajax');

    // Courier check AJAX hooks
    add_action('wp_ajax_checkoutguard_courier_check', 'checkoutguard_handle_courier_check_ajax');
    add_action('wp_ajax_checkoutguard_get_recent_searches', 'checkoutguard_handle_get_recent_searches_ajax');
    add_action('wp_ajax_checkoutguard_delete_courier_search', 'checkoutguard_handle_delete_courier_search_ajax');
    add_action('wp_ajax_checkoutguard_clear_all_searches', 'checkoutguard_handle_clear_all_searches_ajax');

    // WooCommerce hooks
    add_action('woocommerce_thankyou', 'checkoutguard_delete_incomplete_checkout_on_order_completion', 10, 1);
    
    // Fraud blocker - multiple hooks to ensure blocking works
    add_action('woocommerce_checkout_process', 'checkoutguard_check_customer_against_blocklists', 5);
    add_action('woocommerce_after_checkout_validation', 'checkoutguard_check_customer_against_blocklists', 5, 2);
    add_filter('woocommerce_checkout_posted_data', 'checkoutguard_validate_blocked_phone_on_checkout', 1);
    
    // Admin notices for diagnostics
    if (is_admin() && function_exists('checkoutguard_diagnostic_admin_notice')) {
        add_action('admin_notices', 'checkoutguard_diagnostic_admin_notice');
    }
    
    // Privacy Policy Content
    add_action( 'admin_init', 'checkoutguard_add_privacy_policy_content' );
}
add_action('init', 'checkoutguard_init_plugin', 10);

/**
 * Initialize Field Manager early to ensure hooks are registered before WooCommerce
 */
function checkoutguard_init_field_manager() {
    // Ensure class is loaded even if init order changes
    if ( ! class_exists('CheckoutGuard_Field_Manager') ) {
        $field_manager_file = CHECKOUTGUARD_INC_DIR . 'checkout-field-manager.php';
        if ( file_exists( $field_manager_file ) ) {
            require_once $field_manager_file;
        }
    }

    // Only initialize if WooCommerce is active and Field Manager class exists
    if ( class_exists('WooCommerce') && class_exists('CheckoutGuard_Field_Manager') ) {
        global $checkoutguard_field_manager;
        $checkoutguard_field_manager = new CheckoutGuard_Field_Manager();
    }
}
// Run after plugin files load but before admin_init so settings save hooks fire reliably
add_action('init', 'checkoutguard_init_field_manager', 20);

/**
 * Register Privacy Policy content
 */
function checkoutguard_add_privacy_policy_content() {
    if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) return;
    $content = '<div class="wp-suggested-text"><h3>CheckoutGuard Data Collection</h3><p>We capture checkout data in real-time.</p></div>';
    wp_add_privacy_policy_content( 'CheckoutGuard', $content );
}

/**
 * Test AJAX endpoint for debugging
 */
function checkoutguard_test_ajax() {
    wp_send_json_success([
        'message' => 'CheckoutGuard AJAX is working!',
        'timestamp' => current_time('mysql'),
        'woocommerce' => class_exists('WooCommerce') ? 'Active' : 'Inactive'
    ]);
}

/**
 * Initialize tracker
 */
function appsero_init_tracker_checkoutguard() {
    if ( ! class_exists( 'Appsero\Client' ) ) {
      require_once __DIR__ . '/includes/admin/appsero/src/Client.php';
    }
    $client = new Appsero\Client( 'e20adf30-6ccb-4195-819f-936eab691c43', 'CheckoutGuard', __FILE__ );
    $client ->insights()->add_plugin_data()->init();
}
appsero_init_tracker_checkoutguard();

// Activation & Deactivation hooks
// Must be registered at the top level, but we conditionally load the activation file
register_activation_hook(CHECKOUTGUARD_PLUGIN_FILE, function() {
    require_once CHECKOUTGUARD_INC_DIR . 'activation.php';
    if (function_exists('checkoutguard_plugin_activate')) {
        checkoutguard_plugin_activate();
    }
});

register_deactivation_hook(CHECKOUTGUARD_PLUGIN_FILE, function() {
    require_once CHECKOUTGUARD_INC_DIR . 'activation.php';
    if (function_exists('checkoutguard_plugin_deactivate')) {
        checkoutguard_plugin_deactivate();
    }
});