<?php
/*
Plugin Name: CheckoutGuard
Plugin URI: https://wordpress.org/plugins/checkoutguard/
Description: Tracks incomplete WooCommerce checkouts, provides recovery tools, a dashboard, and fraud blocking.
Version: 1.5.0
Requires Plugins: woocommerce
WC requires at least: 7.0
WC tested up to: 11.3.1
Author: Coder Zone BD
Author URI: https://coderzonebd.com/
Text Domain: checkoutguard
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html
*/

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die('No direct access allowed');
}


define('CG_VERSION', '1.5.0');
define('CG_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CG_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CG_PLUGIN_FILE', __FILE__);
define('CG_INC_DIR', CG_PLUGIN_DIR . 'includes/');
define('CG_ADMIN_DIR', CG_INC_DIR . 'admin/');
define('CG_HEADQUARTERS_URL', 'https://li.czbd.app');
define('CG_PRODUCT_SLUG', 'checkoutguard');
// Courier check: a 401/403 without a body code is kept this long (per key).
define('CG_COURIER_AUTH_REJECT_TRANSIENT', 'cg_courier_auth_reject');
define('CG_COURIER_AUTH_REJECT_TTL', 5 * 60);
// Checkout courier check: short timeout (seconds). After a timeout or a
// 5xx, HQ is skipped site-wide for a minute so the next customers are not
// held too (orders fail open).
define('CG_CHECKOUT_COURIER_TIMEOUT', 5);
define('CG_COURIER_TIMEOUT_WAIT_TRANSIENT', 'cg_courier_timeout_wait');
define('CG_COURIER_TIMEOUT_WAIT_TTL', 60);
// Seconds after an order during which a late tracker request for the same
// customer is not saved as a new incomplete checkout.
define('CG_ORDER_PLACED_MARK_TTL', 60);
// Ed25519 public key used to verify the signature on GET /sites/status
// responses (see cg_verify_signed_payload() in includes/utils.php) — public
// keys are meant to ship embedded in the client, there's no API endpoint
// that returns it. Printed on every backend boot ("Public Key (distribute
// with your Client SDKs)"). This is li.czbd.app's production key.
define('CG_HQ_PUBLIC_KEY', 'moCeddoQduMFpYidLIf+yOXH7fZwo+J99JNl0c750DA=');
define('CG_WHATSAPP_URL', 'https://chat.whatsapp.com/JBc983oZeeMKVavdI3cgMl');
define('CG_WHATSAPP_CONTACT1', 'https://wa.me/8801885660190');
define('CG_WHATSAPP_CONTACT2', 'https://wa.me/8801751126322');
define('CG_WHATSAPP_CONTACT3', 'https://wa.me/8801410323121');
define('CG_PRICING_URL', 'https://coderzonebd.com/products/checkoutguard');
// Usage decrements queued on customer paths, sent to HQ from WP-Cron.
define('CG_USAGE_DECREMENT_HOOK', 'cg_send_usage_decrements');
// HQ calls one queued-decrement run sends; the rest goes to a new event.
define('CG_USAGE_DECREMENT_MAX', 20);
// HTTP timeout (seconds) of each queued decrement call.
define('CG_USAGE_DECREMENT_TIMEOUT', 5);

require_once CG_INC_DIR . 'utils.php';
require_once CG_INC_DIR . 'analytics.php';
require_once CG_INC_DIR . 'contact-reveal.php';
require_once CG_INC_DIR . 'site-key.php';

/**
 * Declares compatibility with WooCommerce High-Performance Order Storage.
 * All order reads/writes go through the WC_Order API (see cg_get_order_meta()
 * / cg_update_order_meta() in utils.php), so the plugin is HPOS-safe.
 */
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            CG_PLUGIN_FILE,
            true
        );
    }
});

/**
 * Register custom cron interval for more frequent HQ syncing.
 */
function cg_add_cron_intervals($schedules)
{
    $schedules['cg_six_hours'] = [
        'interval' => 6 * HOUR_IN_SECONDS,
        'display'  => __('Every 6 Hours', 'checkoutguard'),
    ];
    return $schedules;
}
add_filter('cron_schedules', 'cg_add_cron_intervals');

/**
 * Main plugin initialization function.
 */
function cg_init_plugin()
{
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'cg_woocommerce_missing_notice');
        return;
    }

    require_once CG_ADMIN_DIR . 'admin-pages.php';
    require_once CG_ADMIN_DIR . 'admin-settings-page.php';

    $status = get_option('cg_license_status', 'inactive');
    $current_page = $_GET['page'] ?? '';
    $is_cg_page = strpos($current_page, 'cg-') === 0;

    $should_lockdown = ($is_cg_page && $current_page !== 'cg-settings' && ($status === 'inactive' || $status === 'suspended'));

    if ($should_lockdown) {
        require_once CG_INC_DIR . 'enqueue.php';
        require_once CG_ADMIN_DIR . 'admin-menus.php';
        add_action('admin_enqueue_scripts', 'cg_enqueue_admin_assets');
        add_action('admin_menu', 'cg_register_admin_menu');


        $whatsapp_link = 'https://chat.whatsapp.com/JBc983oZeeMKVavdI3cgMl';
        $contact_us_html = sprintf(
            /* translators: 1: opening <a> tag, 2: closing </a> tag */
            __('%1$scontact us%2$s', 'checkoutguard'),
            '<a href="' . esc_url($whatsapp_link) . '" target="_blank">',
            '</a>'
        );

        $messages = [
            'inactive' => sprintf(
                /* translators: %s: clickable "contact us" text */
                __('Your site is not active. Please %s to get it activated.', 'checkoutguard'),
                $contact_us_html
            ),
            'suspended' => sprintf(
                /* translators: %s: clickable "contact us" text */
                __('Your license is suspended. Please %s to solve the issue.', 'checkoutguard'),
                $contact_us_html
            ),
        ];
        $lockdown_message = $messages[$status] ?? __('There is an issue with your license status.', 'checkoutguard');

        add_action('admin_footer', function () use ($lockdown_message) {
            cg_render_lockdown_overlay($lockdown_message);
        });
        return;
    }


    require_once CG_INC_DIR . 'enqueue.php';
    require_once CG_INC_DIR . 'ajax-handlers.php';
    require_once CG_INC_DIR . 'checkout-integration.php';
    require_once CG_INC_DIR . 'ratio-hold.php';
    require_once CG_INC_DIR . 'invoice.php';
    require_once CG_ADMIN_DIR . 'admin-menus.php';
    require_once CG_ADMIN_DIR . 'print-order-page.php';
    require_once CG_ADMIN_DIR . 'dashboard-widget.php';
    require_once CG_ADMIN_DIR . 'woocommerce-integration.php';

    add_action('wp_enqueue_scripts', 'cg_enqueue_checkout_assets');
    add_action('admin_enqueue_scripts', 'cg_enqueue_admin_assets');
    add_action('admin_menu', 'cg_register_admin_menu');
    add_action('admin_notices', 'cg_render_usage_warning_banner');

    add_action('wp_ajax_cg_save_checkout_data', 'cg_handle_save_checkout_data');
    add_action('wp_ajax_nopriv_cg_save_checkout_data', 'cg_handle_save_checkout_data');
    add_action('wp_ajax_cg_get_checkout_details', 'cg_get_incomplete_checkout_details_ajax_handler');
    add_action('wp_ajax_cg_get_incomplete_checkout_details', 'cg_get_incomplete_checkout_details_ajax_handler');
    add_action('wp_ajax_cg_recover_order', 'cg_recover_order_ajax_handler');
    add_action('wp_ajax_cg_mark_hold', 'cg_mark_hold_ajax_handler');
    add_action('wp_ajax_cg_mark_cancelled', 'cg_mark_cancelled_ajax_handler');
    add_action('wp_ajax_cg_fetch_dashboard_data', 'cg_fetch_dashboard_data_ajax_handler');
    add_action('wp_ajax_cg_edit_follow_up_date', 'cg_edit_follow_up_date_ajax_handler');
    add_action('wp_ajax_cg_reopen_checkout', 'cg_reopen_checkout_ajax_handler');
    add_action('wp_ajax_cg_fetch_follow_up_entries', 'cg_fetch_follow_up_entries_ajax_handler');
    add_action('wp_ajax_cg_fetch_incomplete_entries', 'cg_fetch_incomplete_entries_ajax_handler');
    add_action('wp_ajax_cg_fetch_recovered_entries', 'cg_fetch_recovered_entries_ajax_handler');
    add_action('wp_ajax_cg_fetch_cancelled_entries', 'cg_fetch_cancelled_entries_ajax_handler');
    add_action('wp_ajax_cg_check_courier_success', 'cg_handle_check_courier_success');
    add_action('wp_ajax_cg_check_order_success_ratio', 'cg_handle_order_list_success_check');
    add_action('wp_ajax_cg_add_blocked_item', 'cg_handle_add_blocked_item_ajax');
    add_action('wp_ajax_cg_delete_blocked_item', 'cg_handle_delete_blocked_item_ajax');
    add_action('wp_ajax_cg_delete_blocked_log', 'cg_handle_delete_blocked_log_ajax');

    add_action('wp_ajax_cg_get_blocked_order_details', 'cg_get_blocked_order_details_ajax_handler');


    add_action('wp_ajax_cg_save_page_settings', 'cg_handle_save_page_settings');

    // No nopriv handler: only shop staff may look up courier history.
    add_action('wp_ajax_cg_live_ratio_check', 'cg_live_ratio_check_handler');

    // Block checkout: the draft becomes the real order here (no
    // woocommerce_checkout_order_processed on this path).
    add_action('woocommerce_store_api_checkout_order_processed', 'cg_delete_incomplete_checkout_on_order_completion', 10, 1);
    add_action('woocommerce_thankyou', 'cg_delete_incomplete_checkout_on_order_completion', 10, 1);
    add_action('woocommerce_checkout_order_processed', 'cg_delete_incomplete_checkout_on_order_completion', 10, 1);
    add_action('woocommerce_new_order', 'cg_delete_incomplete_checkout_on_order_completion', 10, 1);
    add_action(CG_USAGE_DECREMENT_HOOK, 'cg_send_queued_usage_decrements', 10, 3);
    add_action('woocommerce_checkout_process', 'cg_check_customer_against_blocklists', 20);

    // Site-wide device blocking (must run on every page load for blocked devices)
    add_action('template_redirect', 'cg_check_device_site_block', 1);

    // Bulk import/export
    add_action('wp_ajax_cg_export_blocklist', 'cg_handle_export_blocklist');
    add_action('wp_ajax_cg_import_blocklist', 'cg_handle_import_blocklist');
    add_action('wp_ajax_cg_bulk_delete_blocked_items', 'cg_handle_bulk_delete_blocked_items_ajax');

    add_action('wp_ajax_cg_force_sync_ajax', 'cg_handle_force_sync_ajax');

    add_action('wp_ajax_cg_fetch_blocked_orders', 'cg_fetch_blocked_orders_ajax_handler');

    // Custom order statuses AJAX handlers.
    $options = get_option('cg_plugin_options', []);
    if (!empty($options['enable_order_statuses'])) {
        add_action('wp_ajax_cg_save_order_status', 'cg_handle_save_order_status');
        add_action('wp_ajax_cg_delete_order_status', 'cg_handle_delete_order_status');
        add_action('wp_ajax_cg_fetch_order_status_counts', 'cg_handle_fetch_order_status_counts');
        add_action('wp_ajax_cg_get_order_status', 'cg_handle_get_order_status');
        add_action('wp_ajax_cg_reset_order_statuses', 'cg_handle_reset_order_statuses');
        // Filter bulk actions to respect per-status toggle.
        add_filter('bulk_actions-edit-shop_order', 'cg_filter_order_bulk_actions', 25);
        add_filter('bulk_actions-woocommerce_page_wc-orders', 'cg_filter_order_bulk_actions', 25);
        add_filter('handle_bulk_actions-edit-shop_order', 'cg_handle_custom_order_status_bulk_action', 10, 3);
        add_filter('handle_bulk_actions-woocommerce_page_wc-orders', 'cg_handle_custom_order_status_bulk_action', 10, 3);
    }

    if ($status === 'expired') {
        add_action('admin_notices', 'cg_render_expired_notice');
    }

    // Sync cron: upgrade from daily to every 6 hours, migrate if needed.
    $next = wp_next_scheduled('cg_daily_sync_hook');
    if ($next) {
        if (wp_get_schedule('cg_daily_sync_hook') !== 'cg_six_hours') {
            wp_unschedule_event($next, 'cg_daily_sync_hook');
            wp_schedule_event(time() + rand(0, 1800), 'cg_six_hours', 'cg_daily_sync_hook');
        }
    } else {
        wp_schedule_event(time() + rand(0, 1800), 'cg_six_hours', 'cg_daily_sync_hook');
    }
    if (!wp_next_scheduled('cg_cleanup_expired_blocks')) {
        wp_schedule_event(time(), 'hourly', 'cg_cleanup_expired_blocks');
    }
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


// init, not plugins_loaded: cg_init_plugin()'s lockdown branch calls __()
// directly (not deferred into a later-firing hook callback), and WordPress
// 6.7+ logs a "_load_textdomain_just_in_time" notice for any translation
// call before init — every hook this function registers (admin_menu,
// admin_enqueue_scripts, wp_ajax_*, template_redirect, etc.) fires well
// after init anyway, so nothing here actually needs plugins_loaded timing.
add_action('init', 'cg_init_plugin', 20);

// Custom order statuses (must be outside cg_init_plugin so statuses register globally).
require_once CG_INC_DIR . 'order-statuses.php';
add_action('init', 'cg_register_custom_order_statuses', 20);
add_filter('wc_order_statuses', 'cg_add_custom_statuses_to_woocommerce', 20);

// Privacy policy and GDPR compliance.
require_once CG_INC_DIR . 'privacy.php';
add_action('admin_init', 'cg_add_privacy_policy_content');
add_filter('wp_privacy_personal_data_exporters', 'cg_register_data_exporter');
add_filter('wp_privacy_personal_data_erasers', 'cg_register_data_eraser');

add_action('cg_daily_sync_hook', 'cg_sync_with_hq');
add_action('cg_cleanup_expired_blocks', 'cg_cleanup_expired_blocks_handler');

require_once CG_INC_DIR . 'activation.php';
register_activation_hook(CG_PLUGIN_FILE, 'cg_plugin_activate');
register_deactivation_hook(CG_PLUGIN_FILE, 'cg_plugin_deactivate');


