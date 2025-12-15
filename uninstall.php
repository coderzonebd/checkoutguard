<?php
// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Remove custom tables created by CheckoutGuard.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}checkoutguard_incomplete_checkouts" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}checkoutguard_blocked_numbers" );

// Remove plugin options.
delete_option( 'checkoutguard_plugin_version' );
delete_option( 'checkoutguard_settings' );