<?php
if (!defined('ABSPATH'))
    exit;

function cg_plugin_activate()
{
    // Buffer output to prevent "unexpected output during activation" errors
    ob_start();
    // Suppress WooCommerce's early translation loading notice during activation
    set_error_handler(function ($errno, $errstr) {
        return $errno === E_USER_NOTICE && str_contains($errstr, '_load_textdomain_just_in_time');
    }, E_USER_NOTICE);

    cg_create_database_tables();
    cg_create_blocked_orders_table();
    cg_upgrade_fraud_tables();
    update_option('cg_db_version', CG_VERSION);
    // Set initial status and trigger first sync
    if (!get_option('cg_license_status')) {
        update_option('cg_license_status', 'active');
    }
    cg_register_site_with_hq();

    if (function_exists('cg_send_plugin_analytics')) {
        cg_send_plugin_analytics('plugin_activation');
    }

    // Populate cache immediately to close deactivate/reactivate bypass
    if (!function_exists('cg_sync_with_hq')) {
        require_once CG_ADMIN_DIR . 'admin-settings-page.php';
    }
    cg_sync_with_hq();

    // No need to call cg_report_plugin_status(true) here —
    // the SaaS register() endpoint already sets plugin_active = true.

    restore_error_handler();
    ob_end_clean();
}

function cg_create_blocked_orders_table()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'cg_blocked_orders';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        blocked_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        first_name VARCHAR(100) DEFAULT '' NOT NULL,
        last_name VARCHAR(100) DEFAULT '' NOT NULL,
        phone_number VARCHAR(20) NOT NULL,
        email_address VARCHAR(100) DEFAULT '' NOT NULL,
        cart_details longtext NOT NULL,
        cart_value float NOT NULL,
        success_ratio INT NOT NULL,
        threshold_at_block INT NOT NULL,
        ip_address VARCHAR(100) DEFAULT '' NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

function cg_register_site_with_hq()
{
    $root_domain = cg_get_root_domain(get_site_url());
    $admin_email = get_option('admin_email');

    if (!$root_domain) {
        update_option('cg_license_status', 'failed: no domain');
        return;
    }

    // Registration does NOT require an API key
    $response = cg_call_hq_api('/sites/register', [
        'domain' => $root_domain,
        'admin_email' => $admin_email,
    ], 'POST', false);

    // Success — store the API key returned by HQ
    if (!is_wp_error($response) && !empty($response['api_key'])) {
        $options = get_option('cg_plugin_options', []);
        $options['api_key'] = sanitize_text_field($response['api_key']);
        update_option('cg_plugin_options', $options);
        return;
    }

    // HQ accepted the registration (2xx, valid site data) but returned no
    // API key — this means the product itself has no key configured on the
    // Headquarters side (a server-side setup issue), not a client-side
    // problem, so nothing this site can retry will fix it on its own.
    if (!is_wp_error($response) && is_array($response) && empty($response['api_key'])) {
        update_option('cg_license_status', 'failed: Headquarters accepted registration but returned no API key. This is a server-side configuration issue — please contact support.');
        return;
    }

    // Handle WP_Error responses
    if (is_wp_error($response)) {
        $is_already_taken = (
            $response->get_error_code() === 'api_error' &&
            str_contains($response->get_error_message(), 'already taken')
        );
        if (!$is_already_taken) {
            update_option('cg_license_status', 'failed: ' . $response->get_error_message());
        }
    }
}

/**
 * Report plugin activation/deactivation status to SaaS.
 * Fire-and-forget — does not block on failure.
 */
function cg_report_plugin_status($active)
{
    $options = get_option('cg_plugin_options', []);
    if (empty($options['api_key'])) {
        return; // No API key yet, skip
    }

    cg_call_hq_api('/sites/plugin-status', [
        // The backend decodes this into a Go `bool` field — a JSON integer
        // (0/1) fails to unmarshal into it, so this must be a real boolean.
        'active' => (bool) $active,
    ], 'POST', true);
}

/**
 * Create the necessary database tables.
 * Separated from the main activation function for clarity.
 */
function cg_create_database_tables()
{
    // Check if WooCommerce is active without triggering autoloader (prevents early translation loading notice)
    if (!class_exists('WooCommerce', false) && !in_array('woocommerce/woocommerce.php', (array) get_option('active_plugins', []))) {
        return;
    }

    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    $table_name_incomplete = $wpdb->prefix . 'cg_incomplete_checkouts';
    $sql_incomplete = "CREATE TABLE {$table_name_incomplete} (
        id BIGINT(20) NOT NULL AUTO_INCREMENT,
        session_id VARCHAR(100) DEFAULT '' NOT NULL,
        user_id BIGINT(20) UNSIGNED DEFAULT NULL,
        email VARCHAR(100) DEFAULT '',
        first_name VARCHAR(100) DEFAULT '',
        last_name VARCHAR(100) DEFAULT '',
        phone VARCHAR(30) DEFAULT '',
        address_1 VARCHAR(255) DEFAULT '',
        address_2 VARCHAR(255) DEFAULT '',
        city VARCHAR(100) DEFAULT '',
        state VARCHAR(100) DEFAULT '',
        postcode VARCHAR(20) DEFAULT '',
        country VARCHAR(50) DEFAULT '',
        ip_address VARCHAR(45) DEFAULT '',
        cart_details LONGTEXT DEFAULT NULL,
        cart_value DECIMAL(10,2) DEFAULT 0.00,
        status VARCHAR(20) DEFAULT 'incomplete' NOT NULL,
        recovered_order_id BIGINT(20) UNSIGNED DEFAULT NULL,
        follow_up_date DATE DEFAULT NULL,
        admin_notes TEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY session_id (session_id(10)),
        KEY user_id (user_id),
        KEY email (email(50)),
        KEY ip_address (ip_address),
        KEY status (status),
        KEY recovered_order_id (recovered_order_id),
        KEY created_at (created_at),
        KEY follow_up_date (follow_up_date)
    ) {$charset_collate};";
    dbDelta($sql_incomplete);

    $table_blocked_numbers = $wpdb->prefix . 'cg_blocked_numbers';
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

    $table_blocked_emails = $wpdb->prefix . 'cg_blocked_emails';
    $sql_emails = "CREATE TABLE {$table_blocked_emails} (
        id BIGINT(20) NOT NULL AUTO_INCREMENT,
        email_address VARCHAR(100) NOT NULL,
        added_by BIGINT(20) UNSIGNED DEFAULT NULL,
        reason TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY email_address (email_address)
    ) {$charset_collate};";
    dbDelta($sql_emails);

    $table_blocked_ips = $wpdb->prefix . 'cg_blocked_ips';
    $sql_ips = "CREATE TABLE {$table_blocked_ips} (
        id BIGINT(20) NOT NULL AUTO_INCREMENT,
        ip_address VARCHAR(45) NOT NULL,
        added_by BIGINT(20) UNSIGNED DEFAULT NULL,
        reason TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY ip_address (ip_address)
    ) {$charset_collate};";
    dbDelta($sql_ips);

    update_option('cg_plugin_version', CG_VERSION);
}

/**
 * Creates new fraud prevention tables and alters existing blocklist tables.
 * Safe to run multiple times (uses dbDelta and checks before ALTER).
 */
function cg_upgrade_fraud_tables()
{
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    // New: blocked_domains
    $sql = "CREATE TABLE {$wpdb->prefix}cg_blocked_domains (
        id BIGINT(20) NOT NULL AUTO_INCREMENT,
        domain VARCHAR(255) NOT NULL,
        added_by BIGINT(20) UNSIGNED DEFAULT NULL,
        reason TEXT DEFAULT NULL,
        expires_at DATETIME DEFAULT NULL,
        auto_blocked TINYINT(1) DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY domain (domain)
    ) $charset_collate;";
    dbDelta($sql);

    // New: blocked_addresses
    $sql = "CREATE TABLE {$wpdb->prefix}cg_blocked_addresses (
        id BIGINT(20) NOT NULL AUTO_INCREMENT,
        address_hash VARCHAR(64) NOT NULL,
        address_text TEXT NOT NULL,
        added_by BIGINT(20) UNSIGNED DEFAULT NULL,
        reason TEXT DEFAULT NULL,
        expires_at DATETIME DEFAULT NULL,
        auto_blocked TINYINT(1) DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY address_hash (address_hash)
    ) $charset_collate;";
    dbDelta($sql);

    // New: blocked_devices
    $sql = "CREATE TABLE {$wpdb->prefix}cg_blocked_devices (
        id BIGINT(20) NOT NULL AUTO_INCREMENT,
        device_fingerprint VARCHAR(64) NOT NULL,
        device_info TEXT DEFAULT NULL,
        added_by BIGINT(20) UNSIGNED DEFAULT NULL,
        reason TEXT DEFAULT NULL,
        expires_at DATETIME DEFAULT NULL,
        auto_blocked TINYINT(1) DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY device_fingerprint (device_fingerprint)
    ) $charset_collate;";
    dbDelta($sql);

    // New: checkout_attempts (for velocity + auto-block tracking)
    $sql = "CREATE TABLE {$wpdb->prefix}cg_checkout_attempts (
        id BIGINT(20) NOT NULL AUTO_INCREMENT,
        ip_address VARCHAR(45) DEFAULT '',
        email VARCHAR(100) DEFAULT '',
        phone VARCHAR(30) DEFAULT '',
        device_fingerprint VARCHAR(64) DEFAULT '',
        address_hash VARCHAR(64) DEFAULT '',
        attempt_result VARCHAR(30) NOT NULL DEFAULT 'allowed',
        block_reason TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_ip_time (ip_address, created_at),
        KEY idx_phone_time (phone, created_at),
        KEY idx_email_time (email, created_at),
        KEY idx_device_time (device_fingerprint, created_at)
    ) $charset_collate;";
    dbDelta($sql);

    // Alter existing blocklist tables: add expires_at and auto_blocked columns
    $tables_to_alter = [
        $wpdb->prefix . 'cg_blocked_ips',
        $wpdb->prefix . 'cg_blocked_emails',
        $wpdb->prefix . 'cg_blocked_numbers',
    ];

    foreach ($tables_to_alter as $table) {
        $col = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'expires_at'");
        if (empty($col)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN expires_at DATETIME DEFAULT NULL");
        }
        $col = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'auto_blocked'");
        if (empty($col)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN auto_blocked TINYINT(1) DEFAULT 0");
        }
    }

    // Add device_fingerprint column to incomplete_checkouts table
    $table = $wpdb->prefix . 'cg_incomplete_checkouts';
    $col = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'device_fingerprint'");
    if (empty($col)) {
        $wpdb->query("ALTER TABLE {$table} ADD COLUMN device_fingerprint VARCHAR(64) DEFAULT ''");
    }
}


/**
 * Handles tasks upon plugin deactivation.
 */
function cg_plugin_deactivate()
{
    if (function_exists('cg_send_plugin_analytics')) {
        cg_send_plugin_analytics('plugin_deactivation');
    }

    // Notify SaaS that the plugin is being deactivated (fire-and-forget)
    cg_report_plugin_status(false);

    // Clear scheduled cron jobs
    wp_clear_scheduled_hook('cg_daily_sync_hook');
    wp_clear_scheduled_hook('cg_cleanup_expired_blocks');
}

/**
 * Runs database migrations automatically on plugin update if stored DB version is older
 * than CG_VERSION, or if essential tables/columns are missing.
 */
function cg_maybe_run_migrations()
{
    $installed_ver = get_option('cg_db_version', '1.0.0');
    if (version_compare($installed_ver, CG_VERSION, '<')) {
        cg_create_database_tables();
        cg_create_blocked_orders_table();
        cg_upgrade_fraud_tables();

        // Seed default options if missing
        $options = get_option('cg_plugin_options', []);
        $updated = false;
        $defaults = [
            'duplicate_blocker_enabled'      => 1,
            'duplicate_blocker_window'       => 10,
            'duplicate_blocker_mode'         => 'identical',
            'duplicate_blocker_match_phone'  => 1,
            'duplicate_blocker_match_device' => 1,
            'duplicate_blocker_match_ip'     => 1,
            'duplicate_blocker_match_email'  => 1,
        ];
        foreach ($defaults as $k => $v) {
            if (!isset($options[$k])) {
                $options[$k] = $v;
                $updated = true;
            }
        }
        if ($updated) {
            update_option('cg_plugin_options', $options);
        }

        update_option('cg_db_version', CG_VERSION);
    }
}
add_action('init', 'cg_maybe_run_migrations', 5);

