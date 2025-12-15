<?php
/**
 * Plugin Name: CheckoutGuard
 * Plugin URI: https://coderzonebd.com/
 * Description: Tracks incomplete WooCommerce checkouts to help you understand cart abandonment. Includes a dashboard widget, fraud protection, and courier success rate checking.
 * Version: 1.1.3
 * Requires at least: 5.6
 * Tested up to: 6.9
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 10.4.2
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
// Define if this is the PRO version. For the free version, this is always false.
define('CHECKOUTGUARD_IS_PRO', false);

// Define Core Plugin Constants
define('CHECKOUTGUARD_VERSION', '1.1.2'); // Updated version
define('CHECKOUTGUARD_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CHECKOUTGUARD_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CHECKOUTGUARD_PLUGIN_FILE', __FILE__);
define('CHECKOUTGUARD_INC_DIR', CHECKOUTGUARD_PLUGIN_DIR . 'includes/');
define('CHECKOUTGUARD_ADMIN_DIR', CHECKOUTGUARD_INC_DIR . 'admin/');

// Load essential files
require_once CHECKOUTGUARD_INC_DIR . 'utils.php';
require_once CHECKOUTGUARD_ADMIN_DIR . 'admin-pages.php';
require_once CHECKOUTGUARD_ADMIN_DIR . 'courier-check-page.php';
require_once CHECKOUTGUARD_ADMIN_DIR . 'invoice-page.php';
require_once CHECKOUTGUARD_ADMIN_DIR . 'settings-page.php';
require_once CHECKOUTGUARD_INC_DIR . 'courier-check-ajax.php';

/**
 * Main plugin initialization function.
 */
function checkoutguard_init_plugin()
{
    // Check if WooCommerce is active
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'checkoutguard_woocommerce_missing_notice');
        return;
    }

    // Load all remaining plugin files for the free version
    require_once CHECKOUTGUARD_INC_DIR . 'enqueue.php';
    require_once CHECKOUTGUARD_INC_DIR . 'ajax-handlers.php';
    require_once CHECKOUTGUARD_INC_DIR . 'checkout-integration.php';
    require_once CHECKOUTGUARD_ADMIN_DIR . 'admin-menus.php';
    require_once CHECKOUTGUARD_ADMIN_DIR . 'dashboard-page.php';
    require_once CHECKOUTGUARD_ADMIN_DIR . 'dashboard-widget.php';
    require_once CHECKOUTGUARD_ADMIN_DIR . 'woocommerce-integration.php';

    // Add all WordPress action hooks
    add_action('wp_enqueue_scripts', 'checkoutguard_enqueue_checkout_assets');
    add_action('admin_enqueue_scripts', 'checkoutguard_enqueue_admin_assets');
    add_action('admin_menu', 'checkoutguard_register_admin_menu');

    // Add all AJAX hooks
    add_action('wp_ajax_checkoutguard_save_checkout_data', 'checkoutguard_handle_save_checkout_data');
    add_action('wp_ajax_nopriv_checkoutguard_save_checkout_data', 'checkoutguard_handle_save_checkout_data');
    add_action('wp_ajax_checkoutguard_get_checkout_details', 'checkoutguard_get_incomplete_checkout_details_ajax_handler');
    add_action('wp_ajax_checkoutguard_mark_cancelled', 'checkoutguard_mark_cancelled_ajax_handler');
    add_action('wp_ajax_checkoutguard_add_blocked_item', 'checkoutguard_handle_add_blocked_item_ajax');
    add_action('wp_ajax_checkoutguard_delete_blocked_item', 'checkoutguard_handle_delete_blocked_item_ajax');

    // Courier check AJAX hooks
    add_action('wp_ajax_checkoutguard_courier_check', 'checkoutguard_handle_courier_check_ajax');
    add_action('wp_ajax_checkoutguard_get_recent_searches', 'checkoutguard_handle_get_recent_searches_ajax');
    add_action('wp_ajax_checkoutguard_delete_courier_search', 'checkoutguard_handle_delete_courier_search_ajax');
    add_action('wp_ajax_checkoutguard_clear_all_searches', 'checkoutguard_handle_clear_all_searches_ajax');

    // WooCommerce hooks
    add_action('woocommerce_thankyou', 'checkoutguard_delete_incomplete_checkout_on_order_completion', 10, 1);
    add_action('woocommerce_checkout_process', 'checkoutguard_check_customer_against_blocklists', 20);

    // REMOVED: Daily cleanup cron job is no longer needed in the free version.
}
add_action('plugins_loaded', 'checkoutguard_init_plugin', 20);


/**
 * Initialize the plugin tracker
 *
 * @return void
 */
function appsero_init_tracker_checkoutguard() {

    if ( ! class_exists( 'Appsero\Client' ) ) {
      require_once __DIR__ . '/includes/admin/appsero/src/Client.php';
    }

    $client = new Appsero\Client( 'e20adf30-6ccb-4195-819f-936eab691c43', 'CheckoutGuard', __FILE__ );

    // Active insights
    $client ->insights()
            ->add_plugin_data()
            ->init();

    // Active automatic updater
    $client->updater();


}

appsero_init_tracker_checkoutguard();


// REMOVED: Hook for the daily cleanup

// Activation & Deactivation Hooks
require_once CHECKOUTGUARD_INC_DIR . 'activation.php';
register_activation_hook(CHECKOUTGUARD_PLUGIN_FILE, 'checkoutguard_plugin_activate');
register_deactivation_hook(CHECKOUTGUARD_PLUGIN_FILE, 'checkoutguard_plugin_deactivate');
