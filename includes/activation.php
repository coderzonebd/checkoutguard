<?php
if (!defined('ABSPATH'))
    exit;

// Database schema version, kept apart from CG_VERSION: migrations run when
// the stored cg_db_version is older than this, so a schema change does not
// need a plugin version bump, and an older plugin (downgrade) never sees a
// newer schema as something to migrate.
if (!defined('CG_DB_SCHEMA_VERSION')) {
    define('CG_DB_SCHEMA_VERSION', '1.5.0');
}
define('CG_MIGRATION_FAIL_OPTION', 'cg_migration_fail_count');
define('CG_MIGRATION_NOTICE_AFTER', 3);
// Earliest time the admin's "Reconnect now" may call register again after a
// failed registration (at least a minute, longer when HQ sent Retry-After).
define('CG_REGISTER_GATE_OPTION', 'cg_register_next_allowed_at');
define('CG_REGISTER_MIN_GAP', MINUTE_IN_SECONDS);
// Upper bound of the register gate, the same cap as the status wait. A
// stored gate further ahead than this is ignored, and Retry-After is
// clamped to it, so a clock that moved back or a bad header cannot keep
// the site from registering for hours.
define('CG_REGISTER_MAX_WAIT', 10 * MINUTE_IN_SECONDS);
// Double-click lock for the manual Reconnect. Separate from the register
// gate: the gate holds the automatic path, this lock only stops a second
// Reconnect while one is still running. 45 s covers one register (15 s
// timeout) plus the sync after it (15 s); the lock is removed as soon as
// the call ends.
define('CG_REGISTER_RECONNECT_LOCK', 'cg_register_reconnect_lock');
define('CG_REGISTER_RECONNECT_LOCK_TTL', 45);
// Time HQ last answered register with "already registered" / "already taken"
// while this site had no key. Kept until a key is stored.
define('CG_REGISTER_ALREADY_REGISTERED_OPTION', 'cg_register_already_registered_at');

function cg_plugin_activate()
{
    // Buffer output to prevent "unexpected output during activation" errors
    ob_start();
    // Suppress WooCommerce's early translation loading notice during activation
    set_error_handler(function ($errno, $errstr) {
        return $errno === E_USER_NOTICE && str_contains($errstr, '_load_textdomain_just_in_time');
    }, E_USER_NOTICE);

    cg_sitekey_cleanup_142();
    cg_create_database_tables();
    cg_create_blocked_orders_table();
    cg_upgrade_fraud_tables();
    // Only record the schema version once every 1.5.0 column really exists
    // (blocklist usage_counted, checkouts contact_revealed_until); otherwise cg_maybe_run_migrations() retries on a later load.
    if (cg_ensure_150_schema()) {
        update_option('cg_db_version', CG_DB_SCHEMA_VERSION);
        delete_option(CG_MIGRATION_FAIL_OPTION);
    }
    // Set initial status and trigger first sync
    if (!get_option('cg_license_status')) {
        update_option('cg_license_status', 'active');
    }
    // Activation is an explicit admin action: register now even if an earlier
    // attempt left the gate closed.
    cg_register_site_with_hq(true);

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
        action VARCHAR(10) DEFAULT 'block' NOT NULL,
        order_id BIGINT UNSIGNED NULL DEFAULT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

/**
 * Registers the site with HQ and stores the key it returns.
 *
 * After a failed attempt (HQ down, an HTTP error including "already taken",
 * or a 2xx without a key) CG_REGISTER_GATE_OPTION holds the earliest time of
 * the next one; until then this returns without calling HQ, so checkout
 * saves, syncs and page loads never repeat a register HQ just refused or
 * could not answer. Only explicit admin actions pass $force: plugin
 * activation and "Reconnect now" (which has checked and moved the gate itself).
 *
 * @param bool $force Skip the gate check (activation and Reconnect only).
 */
function cg_register_site_with_hq($force = false)
{
    if (!$force && cg_register_gate_left() > 0) {
        return;
    }

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
        cg_update_options_from_hq($options);
        delete_option(CG_REGISTER_GATE_OPTION);
        delete_option(CG_REGISTER_ALREADY_REGISTERED_OPTION);
        return;
    }

    // HQ accepted the registration (2xx, valid site data) but returned no
    // API key — this means the product itself has no key configured on the
    // Headquarters side (a server-side setup issue), not a client-side
    // problem, so nothing this site can retry will fix it on its own.
    if (!is_wp_error($response) && is_array($response) && empty($response['api_key'])) {
        update_option('cg_license_status', 'failed: Headquarters accepted registration but returned no API key. This is a server-side configuration issue — please contact support.');
        // A retry right away gets the same answer; wait like any failure.
        update_option(CG_REGISTER_GATE_OPTION, cg_register_now() + CG_REGISTER_MIN_GAP, false);
        return;
    }

    // Handle WP_Error responses
    if (is_wp_error($response)) {
        // HQ answers 409 {"error":"already_registered"} when the domain already
        // has a key; older HQ builds said "already taken". Both keep the status.
        // The 409 is matched on status + reason field ("error" or "code"), so a
        // "message" sent next to it does not hide it.
        $err_data = $response->get_error_data();
        $is_already_registered = is_array($err_data)
            && (int) ($err_data['status'] ?? 0) === 409
            && (($err_data['code'] ?? '') === 'already_registered' || ($err_data['error'] ?? '') === 'already_registered');
        $is_already_taken = (
            $response->get_error_code() === 'api_error' &&
            (str_contains($response->get_error_message(), 'already taken')
                || $response->get_error_message() === 'already_registered'
                || $is_already_registered)
        );
        if (!$is_already_taken) {
            update_option('cg_license_status', 'failed: ' . $response->get_error_message());
        } else {
            // The status stays as it was, so the plain failed notice does not
            // show; remember the answer for the already-registered notice.
            update_option(CG_REGISTER_ALREADY_REGISTERED_OPTION, cg_register_now(), false);
        }
        // "Already taken" keeps the current status, but it is still a
        // refused register: gate it like every other failure so checkout
        // saves and syncs do not repeat it.
        $data = $response->get_error_data();
        $retry_after = is_array($data) ? (int) ($data['retry_after'] ?? 0) : 0;
        $wait = min(max(CG_REGISTER_MIN_GAP, $retry_after), CG_REGISTER_MAX_WAIT);
        update_option(CG_REGISTER_GATE_OPTION, cg_register_now() + $wait, false);
    }
}

/**
 * Seconds left on the register gate, or 0 when there is no gate, it has
 * passed, or it lies more than CG_REGISTER_MAX_WAIT ahead (then it is not
 * trusted and ignored).
 *
 * @return int
 */
function cg_register_gate_left()
{
    $left = (int) get_option(CG_REGISTER_GATE_OPTION, 0) - cg_register_now();
    return ($left > 0 && $left <= CG_REGISTER_MAX_WAIT) ? $left : 0;
}

function cg_register_now()
{
    return function_exists('cg_sitekey_now') ? cg_sitekey_now() : time();
}

/**
 * True while the site has no key because registration failed (HQ down,
 * 429, timeout, no key returned) and no SiteKey refusal explains it. The
 * SiteKey notice covers 409 claim_required and the 403 deadline codes.
 */
function cg_register_failed()
{
    $options = get_option('cg_plugin_options', []);
    if (is_array($options) && !empty($options['api_key'])) {
        return false;
    }
    $status = get_option('cg_license_status', '');
    if (!is_string($status) || strpos($status, 'failed') !== 0) {
        return false;
    }
    $state = function_exists('cg_sitekey_state') ? cg_sitekey_state() : [];
    return empty($state['legacy_blocked_at']);
}

/**
 * Admin notice while registration failed and the site has no key: checks
 * are off, so say so on the dashboard and CheckoutGuard pages, in plain
 * words (no raw transport error, never the word "key").
 */
function cg_render_register_failed_notice()
{
    // One notice at a time: the already-registered notice wins.
    if (!current_user_can('manage_options') || !cg_register_failed() || cg_register_already_registered()) {
        return;
    }
    $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    $on_dashboard = $screen && $screen->id === 'dashboard';
    if (!$on_dashboard && strpos($page, 'cg-') !== 0) {
        return;
    }
    $gate = (int) get_option(CG_REGISTER_GATE_OPTION, 0);
    $running = get_transient(CG_REGISTER_RECONNECT_LOCK) !== false;
    echo '<div class="notice notice-error"><p><strong>' . esc_html__('CheckoutGuard is not protecting checkouts right now.', 'checkoutguard') . '</strong> ';
    echo esc_html__('This site could not connect to CheckoutGuard yet. Orders still go through, but without the fraud check.', 'checkoutguard') . ' ';
    if ($running) {
        echo esc_html__('Reconnect is running. Reload this page in a few seconds.', 'checkoutguard');
    } elseif (cg_register_gate_left() > 0) {
        echo esc_html(sprintf(
            /* translators: %s: time after which the admin may try again */
            __('You can try again after %s.', 'checkoutguard'),
            wp_date(get_option('date_format') . ' ' . get_option('time_format'), $gate)
        )) . ' ';
        echo esc_html__('If it keeps failing, contact CheckoutGuard support.', 'checkoutguard');
    } else {
        $url = wp_nonce_url(admin_url('admin-post.php?action=cg_register_reconnect'), 'cg_register_reconnect');
        echo '<a class="button button-secondary" href="' . esc_url($url) . '">' . esc_html__('Reconnect now', 'checkoutguard') . '</a> ';
        echo esc_html__('Click "Reconnect now". If it keeps failing, contact CheckoutGuard support.', 'checkoutguard');
    }
    echo '</p></div>';
}
add_action('admin_notices', 'cg_render_register_failed_notice');

/**
 * True while HQ said this domain is already registered and the site still
 * has no key of its own (the status is left as it was, so
 * cg_register_failed() does not see it).
 */
function cg_register_already_registered()
{
    $options = get_option('cg_plugin_options', []);
    if (is_array($options) && !empty($options['api_key'])) {
        return false;
    }
    return (int) get_option(CG_REGISTER_ALREADY_REGISTERED_OPTION, 0) > 0;
}

/**
 * Admin notice for the already-registered case: checks are off, say so in
 * plain words (never the word "key"), with the same gated Reconnect.
 */
function cg_render_register_already_registered_notice()
{
    if (!current_user_can('manage_options') || !cg_register_already_registered()) {
        return;
    }
    $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    $on_dashboard = $screen && $screen->id === 'dashboard';
    if (!$on_dashboard && strpos($page, 'cg-') !== 0) {
        return;
    }
    $gate = (int) get_option(CG_REGISTER_GATE_OPTION, 0);
    echo '<div class="notice notice-error"><p><strong>' . esc_html__('CheckoutGuard is not protecting checkouts right now.', 'checkoutguard') . '</strong> ';
    echo esc_html__('This site is already registered with CheckoutGuard, but this copy of the plugin is not connected to it. Orders still go through, but without the fraud check.', 'checkoutguard') . ' ';
    if ($gate > cg_register_now()) {
        echo esc_html(sprintf(
            /* translators: %s: time after which the admin may try again */
            __('You can try again after %s.', 'checkoutguard'),
            wp_date(get_option('date_format') . ' ' . get_option('time_format'), $gate)
        )) . ' ';
    } else {
        $url = wp_nonce_url(admin_url('admin-post.php?action=cg_register_reconnect'), 'cg_register_reconnect');
        echo '<a class="button button-secondary" href="' . esc_url($url) . '">' . esc_html__('Reconnect now', 'checkoutguard') . '</a> ';
    }
    echo esc_html__('If it keeps failing, contact CheckoutGuard support.', 'checkoutguard');
    echo '</p></div>';
}
add_action('admin_notices', 'cg_render_register_already_registered_notice');

/**
 * A key saved any other way (settings, HQ sync) ends the already-registered
 * state.
 *
 * @param mixed $old_value Previous cg_plugin_options value.
 * @param mixed $value     New cg_plugin_options value.
 */
function cg_register_already_registered_clear($old_value, $value)
{
    if (is_array($value) && !empty($value['api_key'])) {
        delete_option(CG_REGISTER_ALREADY_REGISTERED_OPTION);
    }
}
add_action('update_option_cg_plugin_options', 'cg_register_already_registered_clear', 10, 2);
add_action('add_option_cg_plugin_options', function ($option, $value) {
    cg_register_already_registered_clear(null, $value);
}, 10, 2);

/**
 * One manual registration attempt. An admin click is not held by the gate:
 * it always sends one register and moves the gate first, so the automatic
 * path (checkout saves, cron) stays gated after it.
 *
 * A short lock (CG_REGISTER_RECONNECT_LOCK, CG_REGISTER_RECONNECT_LOCK_TTL
 * seconds) is set before the call and removed when it ends, success or not.
 * While it is set a second click sends nothing.
 *
 * @return string 'not_needed', 'running' or 'tried'.
 */
function cg_register_reconnect_attempt()
{
    if (!cg_register_failed() && !cg_register_already_registered()) {
        return 'not_needed';
    }
    if (get_transient(CG_REGISTER_RECONNECT_LOCK) !== false) {
        return 'running';
    }
    set_transient(CG_REGISTER_RECONNECT_LOCK, 1, CG_REGISTER_RECONNECT_LOCK_TTL);
    try {
        $now = cg_register_now();
        update_option(CG_REGISTER_GATE_OPTION, $now + CG_REGISTER_MIN_GAP, false);
        if (!function_exists('cg_sync_with_hq')) {
            require_once CG_ADMIN_DIR . 'admin-settings-page.php';
        }
        // The one register this attempt allows. If it fails it sets the gate
        // again, so the register inside the sync below stays gated.
        cg_register_site_with_hq(true);
        cg_sync_with_hq();
    } finally {
        delete_transient(CG_REGISTER_RECONNECT_LOCK);
    }
    return 'tried';
}

function cg_handle_register_reconnect()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Permission denied.', 'checkoutguard'), 403);
    }
    check_admin_referer('cg_register_reconnect');
    cg_register_reconnect_attempt();
    wp_safe_redirect(wp_get_referer() ?: admin_url());
    exit;
}
add_action('admin_post_cg_register_reconnect', 'cg_handle_register_reconnect');

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
        usage_counted TINYINT(1) DEFAULT 1,
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
        usage_counted TINYINT(1) DEFAULT 1,
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
        usage_counted TINYINT(1) DEFAULT 1,
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
        usage_counted TINYINT(1) DEFAULT 1,
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
        usage_counted TINYINT(1) DEFAULT 1,
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
        usage_counted TINYINT(1) DEFAULT 1,
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
 * Makes sure every blocklist table has the usage_counted column (1.5.0).
 *
 * usage_counted = 1: the row counts toward the plan's fraud_* usage (manual
 * blocks, and every row that existed before 1.5.0). usage_counted = 0: an
 * automatic block, which is never counted, so deleting it never decrements.
 *
 * $wpdb->query() does not throw when ALTER fails, so the column is checked
 * again afterwards. Returns true only when all six tables have it; callers
 * must not bump cg_db_version otherwise.
 */
function cg_ensure_usage_counted_columns()
{
    global $wpdb;
    $all_present = true;
    foreach (cg_blocklist_usage_tables() as $table => $action_type) {
        $col = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'usage_counted'");
        if (empty($col)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN usage_counted TINYINT(1) DEFAULT 1");
            $col = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'usage_counted'");
        }
        if (empty($col)) {
            $all_present = false;
        }
    }
    cg_blocklist_has_usage_counted(null, true);
    return $all_present;
}

/**
 * Makes sure cg_incomplete_checkouts has contact_revealed_until (1.5.0):
 * the UTC end of the usage period in which a Free record was given the full
 * email and phone. NULL (all older rows included) means masked on Free.
 * Checked again after ALTER, like usage_counted.
 */
function cg_ensure_contact_reveal_column()
{
    global $wpdb;
    $table = $wpdb->prefix . 'cg_incomplete_checkouts';
    $col = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'contact_revealed_until'");
    if (empty($col)) {
        $wpdb->query("ALTER TABLE {$table} ADD COLUMN contact_revealed_until DATETIME NULL DEFAULT NULL");
        $col = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'contact_revealed_until'");
    }
    if (function_exists('cg_checkouts_has_reveal_column')) {
        cg_checkouts_has_reveal_column(true);
    }
    return !empty($col);
}

/**
 * 1.5.0 ratio hold: cg_blocked_orders.action ('block' | 'hold') and the
 * held order's id. Existing rows are blocks.
 */
function cg_ensure_blocked_orders_hold_columns()
{
    global $wpdb;
    $table = $wpdb->prefix . 'cg_blocked_orders';
    $ok = true;
    $defs = [
        'action'   => "VARCHAR(10) NOT NULL DEFAULT 'block'",
        'order_id' => 'BIGINT UNSIGNED NULL DEFAULT NULL',
    ];
    foreach ($defs as $name => $def) {
        $col = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $name));
        if (empty($col)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN {$name} {$def}");
            $col = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $name));
        }
        $ok = $ok && !empty($col);
    }
    return $ok;
}

/**
 * All 1.5.0 columns. Every check always runs; true only when all exist.
 */
function cg_ensure_150_schema()
{
    $usage_counted = cg_ensure_usage_counted_columns();
    $reveal = cg_ensure_contact_reveal_column();
    $hold = cg_ensure_blocked_orders_hold_columns();
    return $usage_counted && $reveal && $hold;
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
    wp_clear_scheduled_hook('cg_sitekey_retry_event');
    wp_clear_scheduled_hook('cg_sitekey_request_event');
    wp_clear_scheduled_hook('cg_sitekey_claim_event');
}

/**
 * Removes what the 1.4.2 site key request module left behind. That module
 * (includes/sitekey-request.php) was replaced by includes/site-key.php in
 * 1.5.0. The gate option cg_sitekey_next_allowed_at is kept on purpose:
 * site-key.php uses the same option, so a Retry-After wait that 1.4.2 stored
 * is still honoured. Safe to run more than once.
 */
function cg_sitekey_cleanup_142()
{
    wp_clear_scheduled_hook('cg_sitekey_retry_event');
    delete_option('cg_sitekey_retry_lock');
    delete_option('cg_sitekey_backoff_step');
    delete_option('cg_sitekey_recent_calls');
    delete_transient('cg_key_requested');
}

/**
 * Version upgrade routine. Runs once whenever the stored cg_plugin_version
 * differs from CG_VERSION (a plugin update replaces the files without calling
 * the activation hook), before the schema migrations on the same hook.
 */
function cg_maybe_run_version_upgrade()
{
    if (get_option('cg_plugin_version') === CG_VERSION) {
        return;
    }
    cg_sitekey_cleanup_142();
    update_option('cg_plugin_version', CG_VERSION);
}
add_action('init', 'cg_maybe_run_version_upgrade', 4);

/**
 * Runs database migrations automatically when the stored cg_db_version is
 * older than CG_DB_SCHEMA_VERSION.
 */
function cg_maybe_run_migrations()
{
    $installed_ver = get_option('cg_db_version', '1.0.0');
    if (version_compare($installed_ver, CG_DB_SCHEMA_VERSION, '<') && !get_transient('cg_migration_retry_pause')) {
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

        // Bump the version only when the 1.5.0 columns are really there. On
        // failure the next page load (after a short pause, so a host that
        // refuses ALTER is not hit with dbDelta on every request) tries again.
        // The pause is a transient: without a persistent object cache it is
        // stored in wp_options, so it works on every host. Consecutive
        // failures are counted in an option for the settings-page notice.
        if (cg_ensure_150_schema()) {
            update_option('cg_db_version', CG_DB_SCHEMA_VERSION);
            delete_transient('cg_migration_retry_pause');
            delete_option(CG_MIGRATION_FAIL_OPTION);
        } else {
            set_transient('cg_migration_retry_pause', 1, (int) apply_filters('cg_migration_retry_pause_seconds', 60));
            update_option(CG_MIGRATION_FAIL_OPTION, (int) get_option(CG_MIGRATION_FAIL_OPTION, 0) + 1, false);
        }
    }
}
add_action('init', 'cg_maybe_run_migrations', 5);

/**
 * After CG_MIGRATION_NOTICE_AFTER failed migrations in a row (usually a host
 * that does not allow ALTER TABLE), tell the admin, on the CheckoutGuard
 * settings page only.
 */
function cg_render_migration_failed_notice()
{
    if (!current_user_can('manage_options')) {
        return;
    }
    if (!isset($_GET['page']) || $_GET['page'] !== 'cg-settings') {
        return;
    }
    $fails = (int) get_option(CG_MIGRATION_FAIL_OPTION, 0);
    if ($fails < CG_MIGRATION_NOTICE_AFTER) {
        return;
    }
    echo '<div class="notice notice-error cg-migration-failed-notice"><p>'
        . esc_html(sprintf(
            /* translators: %d: number of failed attempts */
            __('CheckoutGuard could not update its database tables (%d attempts). Your hosting may not allow ALTER TABLE for the WordPress database user. Please ask your host to allow it, or contact CheckoutGuard support. The plugin keeps working with the old table layout and retries automatically.', 'checkoutguard'),
            $fails
        ))
        . '</p></div>';
}
add_action('admin_notices', 'cg_render_migration_failed_notice');
