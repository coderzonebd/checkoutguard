<?php
/**
 * CheckoutGuard Uninstall
 *
 * Uninstalling CheckoutGuard deletes all plugin data including:
 * - Custom database tables
 * - Plugin settings and options
 * - Transients
 * - User metadata
 * - Scheduled cron events
 *
 * @package CheckoutGuard
 * @since 1.1.2.1
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Check if we should delete data on uninstall
// Developers can override this with a filter
if ( ! apply_filters( 'checkoutguard_delete_data_on_uninstall', true ) ) {
    return;
}

global $wpdb;

/**
 * Remove custom database tables created by CheckoutGuard
 */
$tables = array(
    $wpdb->prefix . 'checkoutguard_incomplete_checkouts',
    $wpdb->prefix . 'checkoutguard_blocked_numbers',
    $wpdb->prefix . 'checkoutguard_courier_checks',
    $wpdb->prefix . 'checkoutguard_fraud_logs',
);

foreach ( $tables as $table ) {
    $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

/**
 * Remove all plugin options
 */
$options = array(
    'checkoutguard_plugin_version',
    'checkoutguard_settings',
    'checkoutguard_db_version',
    'checkoutguard_activation_date',
    'checkoutguard_last_cleanup',
    'checkoutguard_enable_dashboard',
    'checkoutguard_enable_courier_check',
    'checkoutguard_enable_invoice',
    'checkoutguard_show_branding',
    'checkoutguard_invoice_company_name',
    'checkoutguard_invoice_company_address',
    'checkoutguard_invoice_company_phone',
    'checkoutguard_invoice_company_email',
    'checkoutguard_invoice_footer_text',
);

foreach ( $options as $option ) {
    delete_option( $option );
}

/**
 * Remove all transients
 */
$wpdb->query(
    "DELETE FROM {$wpdb->options} 
    WHERE option_name LIKE '_transient_checkoutguard_%' 
    OR option_name LIKE '_transient_timeout_checkoutguard_%'"
);

/**
 * Remove user metadata
 */
$wpdb->query(
    "DELETE FROM {$wpdb->usermeta} 
    WHERE meta_key LIKE 'checkoutguard_%'"
);

/**
 * Clear scheduled cron events
 */
$cron_hooks = array(
    'checkoutguard_cleanup_old_data',
    'checkoutguard_daily_stats_update',
    'checkoutguard_weekly_report',
);

foreach ( $cron_hooks as $hook ) {
    $timestamp = wp_next_scheduled( $hook );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, $hook );
    }
}

// Clear all schedules for this plugin
wp_clear_scheduled_hook( 'checkoutguard_cleanup_old_data' );
wp_clear_scheduled_hook( 'checkoutguard_daily_stats_update' );
wp_clear_scheduled_hook( 'checkoutguard_weekly_report' );

/**
 * Remove custom capabilities (if any were added)
 */
$capabilities = array(
    'manage_checkoutguard',
    'view_checkoutguard_reports',
);

$roles = array( 'administrator', 'shop_manager' );
foreach ( $roles as $role_name ) {
    $role = get_role( $role_name );
    if ( $role ) {
        foreach ( $capabilities as $cap ) {
            $role->remove_cap( $cap );
        }
    }
}

/**
 * Flush rewrite rules
 */
flush_rewrite_rules();

/**
 * Clear any cached data
 */
wp_cache_flush();

// Log uninstallation for debugging purposes (only if WP_DEBUG is enabled)
if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
    error_log( 'CheckoutGuard: Plugin data successfully removed during uninstallation.' );
}