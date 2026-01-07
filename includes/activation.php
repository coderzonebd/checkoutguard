<?php
if (!defined('ABSPATH'))
    exit;

/**
 * Runs on plugin activation.
 */
function checkoutguard_plugin_activate()
{
    checkoutguard_create_database_tables();
}

/**
 * Create the necessary database tables for CheckoutGuard.
 */
function checkoutguard_create_database_tables()
{
    // Ensure WooCommerce is active before trying to create tables
    if (!class_exists('WooCommerce')) {
        return;
    }

    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    // 1. Main Incomplete Checkouts Table
    $table_name_incomplete = $wpdb->prefix . 'checkoutguard_incomplete_checkouts';
    $sql_incomplete = "CREATE TABLE {$table_name_incomplete} (
        id BIGINT(20) NOT NULL AUTO_INCREMENT,
        session_id VARCHAR(100) DEFAULT '' NOT NULL,
        user_id BIGINT(20) UNSIGNED DEFAULT NULL,
        email VARCHAR(100) DEFAULT '',
        first_name VARCHAR(100) DEFAULT '',
        last_name VARCHAR(100) DEFAULT '',
        company VARCHAR(100) DEFAULT '',
        phone VARCHAR(30) DEFAULT '',
        address_1 VARCHAR(255) DEFAULT '',
        address_2 VARCHAR(255) DEFAULT '',
        city VARCHAR(100) DEFAULT '',
        state VARCHAR(100) DEFAULT '',
        postcode VARCHAR(20) DEFAULT '',
        country VARCHAR(50) DEFAULT '',
        ip_address VARCHAR(45) DEFAULT '',
        cart_details LONGTEXT DEFAULT NULL,
        customer_data LONGTEXT DEFAULT NULL,
        cart_value DECIMAL(10,2) DEFAULT 0.00,
        status VARCHAR(20) DEFAULT 'incomplete' NOT NULL,
        recovered_order_id BIGINT(20) UNSIGNED DEFAULT NULL,
        follow_up_date DATE DEFAULT NULL,
        admin_notes TEXT DEFAULT NULL,
        emails_sent INT DEFAULT 0,
        last_email_sent_at DATETIME DEFAULT NULL,
        next_email_scheduled_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY session_id (session_id(10)),
        KEY email (email),
        KEY status (status),
        KEY created_at (created_at),
        KEY next_email_scheduled_at (next_email_scheduled_at)
    ) {$charset_collate};";
    dbDelta($sql_incomplete);

    // 2. Fraud Blocker Table
    $table_blocked_numbers = $wpdb->prefix . 'checkoutguard_blocked_numbers';
    $sql_numbers = "CREATE TABLE {$table_blocked_numbers} (
        id BIGINT(20) NOT NULL AUTO_INCREMENT,
        phone_number VARCHAR(30) NOT NULL,
        added_by BIGINT(20) UNSIGNED DEFAULT NULL,
        reason TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY phone_number (phone_number)
    ) {$charset_collate};";
    dbDelta($sql_numbers);

    // 3. Courier Searches Table
    $table_courier_searches = $wpdb->prefix . 'checkoutguard_courier_searches';
    $sql_courier = "CREATE TABLE {$table_courier_searches} (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        phone_number VARCHAR(20) NOT NULL,
        total_deliveries INT(11) DEFAULT 0,
        successful_deliveries INT(11) DEFAULT 0,
        success_rate DECIMAL(5,2) DEFAULT 0.00,
        risk_level VARCHAR(50) DEFAULT 'N/A',
        searched_by BIGINT(20) UNSIGNED DEFAULT 0,
        searched_at DATETIME NOT NULL,
        results_json LONGTEXT,
        PRIMARY KEY (id),
        KEY phone_number (phone_number),
        KEY searched_at (searched_at)
    ) {$charset_collate};";
    dbDelta($sql_courier);

    update_option('checkoutguard_plugin_version', CHECKOUTGUARD_VERSION);
    update_option('checkoutguard_db_check', 'done');
}

/**
 * CRITICAL FIX: This function MUST be present to fix the crash.
 * Check if tables exist, if not, create them.
 */
function checkoutguard_ensure_tables_exist() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'checkoutguard_incomplete_checkouts';
    
    // If table doesn't exist, run creation
    $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name));
    if ($table_exists != $table_name) {
        checkoutguard_create_database_tables();
    } else {
        // Table exists, check if it has all required columns
        checkoutguard_update_table_structure();
    }
}

/**
 * Update existing table structure if columns are missing
 */
function checkoutguard_update_table_structure() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'checkoutguard_incomplete_checkouts';
    
    // Get all columns in the table
    $columns = $wpdb->get_results("SHOW COLUMNS FROM {$table_name}");
    $column_names = array();
    foreach ($columns as $column) {
        $column_names[] = $column->Field;
    }
    
    // Check and add missing columns
    if (!in_array('company', $column_names)) {
        $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN company VARCHAR(100) DEFAULT '' AFTER last_name");
    }
    
    if (!in_array('customer_data', $column_names)) {
        $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN customer_data LONGTEXT DEFAULT NULL AFTER cart_details");
    }
    
    // Add user_agent column for analytics (Pro feature)
    if (!in_array('user_agent', $column_names)) {
        $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN user_agent TEXT DEFAULT NULL AFTER ip_address");
    }
    
    // Add cart_items column for analytics (Pro feature) - renamed from cart_details
    if (!in_array('cart_items', $column_names)) {
        $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN cart_items LONGTEXT DEFAULT NULL AFTER cart_details");
    }
    
    // Add notes column if missing (for automation priority tags)
    if (!in_array('notes', $column_names)) {
        $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN notes TEXT DEFAULT NULL AFTER admin_notes");
    }
}

/**
 * Handles tasks upon plugin deactivation.
 */
function checkoutguard_plugin_deactivate()
{
    wp_clear_scheduled_hook('checkoutguard_daily_cleanup');
}