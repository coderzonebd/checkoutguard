<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package CheckoutGuard
 */

// If uninstall not called from WordPress, exit.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$options = get_option('cg_plugin_options', array());

if (!empty($options['delete_on_uninstall'])) {
    global $wpdb;

    // Drop all custom tables.
    $tables = array(
        $wpdb->prefix . 'cg_incomplete_checkouts',
        $wpdb->prefix . 'cg_blocked_numbers',
        $wpdb->prefix . 'cg_blocked_emails',
        $wpdb->prefix . 'cg_blocked_ips',
        $wpdb->prefix . 'cg_blocked_orders',
        $wpdb->prefix . 'cg_blocked_domains',
        $wpdb->prefix . 'cg_blocked_addresses',
        $wpdb->prefix . 'cg_blocked_devices',
        $wpdb->prefix . 'cg_checkout_attempts',
    );

    foreach ($tables as $table) {
        $wpdb->query("DROP TABLE IF EXISTS {$table}"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    }

    // Delete all plugin options and transients.
    delete_option('cg_plugin_options');
    delete_option('cg_license_status');
    delete_option('cg_plugin_version');
    delete_option('cg_recent_courier_checks');
    delete_option('cg_custom_order_statuses');
    delete_option('cg_invoice_settings');
    delete_option('cg_api_log');
    delete_option('cg_sitekey_next_allowed_at');
    delete_option('cg_register_next_allowed_at');
    delete_option('cg_register_already_registered_at');
    delete_option('cg_sitekey_backoff_step');
    delete_option('cg_sitekey_recent_calls');
    delete_option('cg_sitekey_retry_lock');
    delete_transient('cg_key_requested');
    delete_transient('cg_register_reconnect_lock');

    // Invoice numbering sequences and free-tier usage counters are stored
    // under dynamically-named options (cg_invoice_seq_all, cg_invoice_seq_2026,
    // cg_invoice_seq_202601, cg_invoice_usage_2026-01, ...), so they have to be
    // matched by prefix rather than deleted by name.
    $dynamic_option_prefixes = array('cg_invoice_seq_', 'cg_invoice_usage_');
    foreach ($dynamic_option_prefixes as $prefix) {
        $names = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like($prefix) . '%'
            )
        );
        foreach ($names as $name) {
            delete_option($name);
        }
    }

    delete_option('cg_period_synced_for');
    delete_option('cg_migration_fail_count');
    delete_option('cg_db_version');
    delete_transient('cg_migration_retry_pause');
    delete_option('cg_period_sync_lock');
    delete_transient('cg_period_sync_backoff');
    delete_transient('cg_api_cache');
    delete_transient('cg_last_synced');
    delete_transient('cg_courier_auth_reject');
    delete_option('cg_last_known_plan');
    delete_option('cg_sitekey_state');
    delete_option('cg_sitekey_next_allowed_at');
    delete_option('cg_register_next_allowed_at');
    delete_option('cg_register_already_registered_at');
    delete_option('cg_sitekey_lock');
    delete_option('cg_sitekey_last_drop');
}

// Cron events are removed on every uninstall, not only with the opt-in
// above: the plugin code is gone, so nothing may still be scheduled for it.
// wp_unschedule_hook() removes every event of the hook whatever its args;
// wp_clear_scheduled_hook() with no args would leave the queued usage
// decrements, which carry args.
$cg_cron_hooks = array(
    'cg_daily_sync_hook',
    'cg_cleanup_expired_blocks',
    'cg_sitekey_request_event',
    'cg_sitekey_claim_event',
    'cg_sitekey_retry_event',
    // Queued HQ usage decrements (CG_USAGE_DECREMENT_HOOK).
    'cg_send_usage_decrements',
);
foreach ($cg_cron_hooks as $cg_cron_hook) {
    wp_unschedule_hook($cg_cron_hook);
}

// The Reconnect / Sync Now lock is removed on every uninstall, not only with
// the opt-in above: it is a short-lived lock, not merchant data.
delete_option('cg_register_reconnect_lock');
delete_transient('cg_register_reconnect_lock');
