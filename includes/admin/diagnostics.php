<?php
/**
 * CheckoutGuard Diagnostics and Troubleshooting
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Check system status and display diagnostic information
 */
function checkoutguard_get_system_status() {
    global $wpdb;
    
    $status = [
        'plugin_version' => CHECKOUTGUARD_VERSION,
        'woocommerce_active' => class_exists('WooCommerce'),
        'woocommerce_version' => class_exists('WooCommerce') ? WC()->version : 'Not installed',
        'wordpress_version' => get_bloginfo('version'),
        'php_version' => phpversion(),
        'tables_exist' => [],
        'checkout_page_id' => get_option('woocommerce_checkout_page_id'),
        'tracking_script_url' => CHECKOUTGUARD_PLUGIN_URL . 'assets/js/tracker.js',
    ];
    
    // Check tables
    $tables = [
        'checkoutguard_incomplete_checkouts',
        'checkoutguard_blocked_numbers',
        'checkoutguard_courier_searches'
    ];
    
    foreach ($tables as $table) {
        $table_name = $wpdb->prefix . $table;
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name));
        $status['tables_exist'][$table] = ($exists == $table_name);
        
        if ($exists == $table_name) {
            // Count records
            $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
            $status['table_counts'][$table] = $count;
        }
    }
    
    return $status;
}

/**
 * Display diagnostic admin notice if there are issues
 */
function checkoutguard_diagnostic_admin_notice() {
    $status = checkoutguard_get_system_status();
    
    // Check for missing tables
    $missing_tables = [];
    foreach ($status['tables_exist'] as $table => $exists) {
        if (!$exists) {
            $missing_tables[] = $table;
        }
    }
    
    if (!empty($missing_tables)) {
        ?>
        <div class="notice notice-error">
            <p>
                <strong>CheckoutGuard:</strong> Required database tables are missing: 
                <code><?php echo esc_html(implode(', ', $missing_tables)); ?></code>
            </p>
            <p>
                <a href="<?php echo admin_url('admin.php?page=checkoutguard-settings&action=recreate_tables'); ?>" 
                   class="button button-primary">
                    Recreate Database Tables
                </a>
            </p>
        </div>
        <?php
    }
    
    // Check if WooCommerce checkout page is set
    if (!$status['checkout_page_id']) {
        ?>
        <div class="notice notice-warning">
            <p>
                <strong>CheckoutGuard:</strong> WooCommerce checkout page is not configured. 
                Please set up your checkout page in WooCommerce settings.
            </p>
        </div>
        <?php
    }
}

/**
 * Handle manual table recreation
 */
function checkoutguard_handle_recreate_tables() {
    if (!isset($_GET['action']) || $_GET['action'] !== 'recreate_tables') {
        return;
    }
    
    if (!isset($_GET['page']) || $_GET['page'] !== 'checkoutguard-settings') {
        return;
    }
    
    if (!current_user_can('manage_options')) {
        return;
    }
    
    // Verify nonce if provided
    if (isset($_GET['_wpnonce']) && !wp_verify_nonce($_GET['_wpnonce'], 'checkoutguard_recreate_tables')) {
        return;
    }
    
    // Recreate tables
    if (function_exists('checkoutguard_create_database_tables')) {
        checkoutguard_create_database_tables();
        
        // Add success notice
        add_action('admin_notices', function() {
            ?>
            <div class="notice notice-success is-dismissible">
                <p><strong>CheckoutGuard:</strong> Database tables have been recreated successfully.</p>
            </div>
            <?php
        });
    }
}
add_action('admin_init', 'checkoutguard_handle_recreate_tables');

/**
 * Add diagnostics info to settings page
 */
function checkoutguard_render_diagnostics_section() {
    $status = checkoutguard_get_system_status();
    ?>
    <div class="checkoutguard-diagnostics-section" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; margin-top: 20px;">
        <h2>System Diagnostics</h2>
        
        <table class="widefat striped">
            <tbody>
                <tr>
                    <td><strong>Plugin Version</strong></td>
                    <td><?php echo esc_html($status['plugin_version']); ?></td>
                </tr>
                <tr>
                    <td><strong>WooCommerce</strong></td>
                    <td>
                        <?php if ($status['woocommerce_active']): ?>
                            <span style="color: green;">✓ Active (<?php echo esc_html($status['woocommerce_version']); ?>)</span>
                        <?php else: ?>
                            <span style="color: red;">✗ Not Active</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td><strong>WordPress Version</strong></td>
                    <td><?php echo esc_html($status['wordpress_version']); ?></td>
                </tr>
                <tr>
                    <td><strong>PHP Version</strong></td>
                    <td><?php echo esc_html($status['php_version']); ?></td>
                </tr>
                <tr>
                    <td><strong>Checkout Page ID</strong></td>
                    <td>
                        <?php if ($status['checkout_page_id']): ?>
                            <?php echo esc_html($status['checkout_page_id']); ?> 
                            <a href="<?php echo get_permalink($status['checkout_page_id']); ?>" target="_blank">(View Page)</a>
                        <?php else: ?>
                            <span style="color: red;">Not Set</span>
                        <?php endif; ?>
                    </td>
                </tr>
            </tbody>
        </table>
        
        <h3 style="margin-top: 20px;">Database Tables</h3>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>Table Name</th>
                    <th>Status</th>
                    <th>Records</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($status['tables_exist'] as $table => $exists): ?>
                <tr>
                    <td><code><?php echo esc_html($table); ?></code></td>
                    <td>
                        <?php if ($exists): ?>
                            <span style="color: green;">✓ Exists</span>
                        <?php else: ?>
                            <span style="color: red;">✗ Missing</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php 
                        if ($exists && isset($status['table_counts'][$table])) {
                            echo esc_html($status['table_counts'][$table]);
                        } else {
                            echo '-';
                        }
                        ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <p style="margin-top: 20px;">
            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=checkoutguard-settings&action=recreate_tables'), 'checkoutguard_recreate_tables'); ?>" 
               class="button button-secondary"
               onclick="return confirm('Are you sure you want to recreate the database tables? This will not delete existing data.');">
                Recreate Database Tables
            </a>
        </p>
    </div>
    <?php
}
