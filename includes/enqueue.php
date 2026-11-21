<?php
// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Enqueues scripts and styles for the FRONT-END checkout page.
 */
function cg_enqueue_checkout_assets()
{
    if (function_exists('cg_is_checkout_context') ? cg_is_checkout_context() : (function_exists('is_checkout') && is_checkout())) {
        wp_enqueue_script(
            'cg-checkout-tracker',
            CG_PLUGIN_URL . 'assets/js/tracker.js',
            array('jquery'),
            CG_VERSION,
            true
        );
        wp_enqueue_style('cg-frontend-styles', CG_PLUGIN_URL . 'assets/css/frontend-styles.css', [], CG_VERSION);


        wp_localize_script(
            'cg-checkout-tracker',
            'cg_checkout_params',
            array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'save_data_nonce' => wp_create_nonce('cg_save_checkout_data_nonce'),
                'live_ratio_check_nonce' => wp_create_nonce('cg_live_ratio_check_nonce'),
                'tracked_fields' => apply_filters('cg_tracked_checkout_fields', array(
                    '#billing_first_name',
                    '#billing_last_name',
                    '#billing_company',
                    '#billing_country',
                    '#billing_address_1',
                    '#billing_address_2',
                    '#billing_city',
                    '#billing_state',
                    '#billing_postcode',
                    '#billing_phone',
                    '#billing_email',
                    '#shipping_first_name',
                    '#shipping_last_name',
                    '#shipping_company',
                    '#shipping_country',
                    '#shipping_address_1',
                    '#shipping_address_2',
                    '#shipping_city',
                    '#shipping_state',
                    '#shipping_postcode',
                    '#order_comments'
                ))
            )
        );
    }
}

/**
 * Ensures a persistent device fingerprint cookie is generated for all front-end visitors.
 * Extremely lightweight inline script (< 400 bytes) with zero performance impact.
 */
function cg_output_frontend_device_sync()
{
    if (is_admin()) {
        return;
    }
    ?>
    <script>
    (function(){try{var m=document.cookie.match(/(?:^|;\s*)cg_device_fp=([^;]+)/);var l=localStorage.getItem('cg_device_id')||'';if(l&&!m){document.cookie='cg_device_fp='+l+';path=/;max-age=31536000;SameSite=Lax';}else if(!m&&!l){var s=[navigator.userAgent,screen.width+'x'+screen.height,navigator.language||'',(new Date()).getTimezoneOffset()].join('|');var h=0;for(var i=0;i<s.length;i++){h=((h<<5)-h)+s.charCodeAt(i);h=h&h;}var fp='d_'+Math.abs(h).toString(36);document.cookie='cg_device_fp='+fp+';path=/;max-age=31536000;SameSite=Lax';try{localStorage.setItem('cg_device_id',fp);}catch(e){}}}catch(e){}})();
    </script>
    <?php
}
add_action('wp_head', 'cg_output_frontend_device_sync', 1);

/**
 * Enqueues scripts and styles for the plugin's ADMIN pages.
 */
function cg_enqueue_admin_assets($hook_suffix)
{
    global $post; // Make the global $post object available

    $screen = get_current_screen();
    if (!$screen) {
        return;
    }

    $is_cg_page = (strpos($screen->id, 'cg-main-dashboard') !== false || strpos($screen->id, '_page_cg-') !== false);
    $is_main_dashboard = ($screen->id === 'dashboard');
    $is_shop_order_list_page = ($screen->id === 'edit-shop_order' || ($screen->id === 'woocommerce_page_wc-orders' && ($_GET['action'] ?? '') !== 'edit'));
    $is_single_order_page = ($screen->id === 'shop_order' || ($screen->id === 'woocommerce_page_wc-orders' && ($_GET['action'] ?? '') === 'edit'));
    $is_courier_analytics_page = ($screen->id === 'checkoutguard_page_cg-courier-analytics');

    if ($is_cg_page || $is_main_dashboard || $is_shop_order_list_page || $is_single_order_page) {

        wp_enqueue_style(
            'cg-admin-styles',
            CG_PLUGIN_URL . 'assets/css/admin-styles.css',
            array(),
            CG_VERSION
        );

        $dependencies = array('jquery');

        if ($screen->id === 'toplevel_page_cg-main-dashboard' || $screen->id === 'dashboard' || $is_single_order_page || $is_courier_analytics_page) {
            wp_enqueue_script('chart-js', CG_PLUGIN_URL . 'assets/js/chart.min.js', array(), '3.9.1', true);
            $dependencies[] = 'chart-js';
        }

        // Invoices page: WooCommerce's own product-search select2 for the
        // "New Blank Invoice" line-item picker (already registered + localized
        // by WooCommerce core; just needs enqueueing on this non-WC screen).
        if (strpos($screen->id, 'cg-invoices') !== false) {
            wp_enqueue_style('woocommerce_admin_styles');
            wp_enqueue_script('wc-enhanced-select');
            $dependencies[] = 'wc-enhanced-select';
        }

        wp_enqueue_script('cg-admin-tracker', CG_PLUGIN_URL . 'assets/js/admin-tracker.js', $dependencies, CG_VERSION, true);

        $params_array = array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'fraud_blocker_nonce' => wp_create_nonce('cg_fraud_blocker_nonce'),
            'delete_blocker_item_nonce' => wp_create_nonce('cg_delete_blocked_item_nonce'),
            'order_list_nonce' => wp_create_nonce('cg_check_order_success_ratio_nonce'),
            'courier_analytics_nonce' => wp_create_nonce('cg_check_courier_success_nonce'),
            'view_details_nonce' => wp_create_nonce('cg_view_details_nonce'),
            'recover_order_nonce' => wp_create_nonce('cg_recover_order_nonce'),
            'mark_hold_nonce' => wp_create_nonce('cg_mark_hold_nonce'),
            'mark_cancelled_nonce' => wp_create_nonce('cg_mark_cancelled_nonce'),
            'fetch_dashboard_data_nonce' => wp_create_nonce('cg_fetch_dashboard_data_nonce'),
            'edit_follow_up_date_nonce' => wp_create_nonce('cg_edit_follow_up_date_nonce'),
            'reopen_checkout_nonce' => wp_create_nonce('cg_reopen_checkout_nonce'),
            'fetch_incomplete_nonce' => wp_create_nonce('cg_fetch_incomplete_nonce'),
            'fetch_recovered_nonce' => wp_create_nonce('cg_fetch_recovered_nonce'),
            'fetch_cancelled_nonce' => wp_create_nonce('cg_fetch_cancelled_nonce'),
            'fetch_follow_up_nonce' => wp_create_nonce('cg_fetch_follow_up_nonce'),
            'live_ratio_check_nonce' => wp_create_nonce('cg_live_ratio_check_nonce'),
            'save_page_settings_nonce' => wp_create_nonce('cg_save_page_settings_nonce'),
            'export_blocklist_nonce' => wp_create_nonce('cg_fraud_blocker_nonce'),
            'import_blocklist_nonce' => wp_create_nonce('cg_fraud_blocker_nonce'),
            'force_sync_nonce' => wp_create_nonce('cg_force_sync_ajax'),
            'fetch_blocked_orders_nonce' => wp_create_nonce('cg_fetch_blocked_orders_nonce'),
            'bulk_delete_blocked_items_nonce' => wp_create_nonce('cg_bulk_delete_blocked_items_nonce'),
            'order_statuses_nonce' => wp_create_nonce('cg_order_statuses_nonce'),
            'invoices_nonce' => wp_create_nonce('cg_invoices_nonce'),
            'usage_warnings' => function_exists('cg_get_usage_warnings') ? cg_get_usage_warnings() : [],
            'whatsapp_url'   => defined('CG_WHATSAPP_URL') ? CG_WHATSAPP_URL : '',
            'pricing_url'    => defined('CG_PRICING_URL') ? CG_PRICING_URL : '',
            'is_pro'         => function_exists('cg_is_pro_plan') ? cg_is_pro_plan() : false,
            'currency_symbol' => function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '',

        );

        if ($is_single_order_page) {
            global $wpdb;

            $order_id = 0;
            if (isset($post->ID)) {
                $order_id = $post->ID;
            } elseif (isset($_GET['post'])) {
                $order_id = intval($_GET['post']);
            } elseif (isset($_GET['id'])) { // For HPOS compatibility
                $order_id = intval($_GET['id']);
            }

            $is_blocked = false;
            if ($order_id > 0) {
                // Must go through the order object: on HPOS stores the customer
                // IP lives in the WooCommerce order tables, not in postmeta, so
                // get_post_meta() would always come back empty here.
                $cg_order = wc_get_order($order_id);
                $ip_address = $cg_order ? $cg_order->get_customer_ip_address() : '';
                if ($ip_address) {
                    $result = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM {$wpdb->prefix}cg_blocked_ips WHERE ip_address = %s",
                        $ip_address
                    ));
                    if ($result) {
                        $is_blocked = true;
                    }
                }
            }
            $params_array['is_ip_blocked'] = $is_blocked;
        }

        wp_localize_script(
            'cg-admin-tracker',
            'cg_admin_params',
            $params_array
        );
    }
}














