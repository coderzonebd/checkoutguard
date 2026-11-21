<?php
if (!defined('ABSPATH')) {
    exit;
}

function cg_send_plugin_analytics($event_type = 'weekly_analytics', $feedback = '')
{
    $root_domain = cg_get_root_domain(get_site_url());
    if (!$root_domain) {
        return;
    }

    $options = get_option('cg_plugin_options', []);
    $payload = [
        'product_slug' => CG_PRODUCT_SLUG,
        'site_hash' => hash('sha256', $root_domain),
        'site_domain' => $root_domain,
        'admin_email' => get_option('admin_email'),
        'site_name' => get_bloginfo('name'),
        'site_language' => get_locale(),
        'timezone' => wp_timezone_string(),
        'wp_version' => get_bloginfo('version'),
        'wp_multisite' => is_multisite(),
        'wp_debug' => defined('WP_DEBUG') && WP_DEBUG,
        'wc_version' => defined('WC_VERSION') ? WC_VERSION : '',
        'wc_active' => class_exists('WooCommerce'),
        'php_version' => PHP_VERSION,
        'mysql_version' => $GLOBALS['wpdb']->db_version(),
        'server_software' => isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE'])) : '',
        'active_theme' => wp_get_theme()->get('Name'),
        'theme_version' => wp_get_theme()->get('Version'),
        'plugin_version' => CG_VERSION,
        'plugin_settings' => [
            'license_status' => get_option('cg_license_status', 'inactive'),
            'api_key_configured' => !empty($options['api_key']),
        ],
        'plugin_analytics' => [],
        'feature_usage' => [],
        'site_status' => $event_type === 'plugin_deactivation' ? 'deactivated' : 'active',
        'event_type' => $event_type,
        'deactivation_feedback' => $feedback,
        'collected_at' => gmdate('c'),
    ];

    $response = wp_remote_post(CG_HEADQUARTERS_URL . '/api/v1/plugin-analytics/', [
        'timeout' => 10,
        'blocking' => true,
        'headers' => [
            'Content-Type' => 'application/json; charset=utf-8',
            'Accept' => 'application/json',
            'User-Agent' => 'CheckoutGuard/' . CG_VERSION,
        ],
        'body' => wp_json_encode($payload),
    ]);

    if (is_wp_error($response)) {
        error_log(sprintf(
            '[CheckoutGuard analytics] %s failed for %s: %s',
            $event_type,
            $root_domain,
            $response->get_error_message()
        ));
        return;
    }

    $status_code = wp_remote_retrieve_response_code($response);
    if ($status_code >= 400) {
        error_log(sprintf(
            '[CheckoutGuard analytics] %s failed for %s with HTTP %d: %s',
            $event_type,
            $root_domain,
            $status_code,
            wp_remote_retrieve_body($response)
        ));
    }
}

add_action('cg_daily_sync_hook', 'cg_send_plugin_analytics', 20);
