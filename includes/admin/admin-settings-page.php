<?php

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fetches the latest configuration from the Headquarters API.
 */
function cg_fetch_hq_configuration()
{
    $response = cg_call_hq_api('/sites/status', [], 'GET');
    if (is_wp_error($response)) {
        return false;
    }
    $verified = cg_verify_signed_payload($response);
    if (is_wp_error($verified)) {
        // Distinct from a network failure — a bad signature means the
        // response was tampered with, not that the server is unreachable.
        update_option('cg_license_status', 'failed: ' . $verified->get_error_message());
        return false;
    }
    return $verified;
}

/**
 * Fetches data from HQ, updates local settings, and caches the result.
 */
function cg_sync_with_hq()
{
    // If no API key is stored, try to register the site first
    $options = get_option('cg_plugin_options', []);
    if (empty($options['api_key'])) {
        if (function_exists('cg_register_site_with_hq')) {
            cg_register_site_with_hq();
        }
        // Re-check — if registration didn't yield a key, we can't sync
        $options = get_option('cg_plugin_options', []);
        if (empty($options['api_key'])) {
            return false;
        }
    }

    $config = cg_fetch_hq_configuration();

    if ($config && is_array($config) && isset($config['status'])) {
        update_option('cg_license_status', $config['status']);
        set_transient('cg_api_cache', $config, DAY_IN_SECONDS);
        set_transient('cg_last_synced', time(), WEEK_IN_SECONDS);

        if (isset($config['settings'])) {
            $hq_settings = (array) $config['settings'];
            $options = get_option('cg_plugin_options', []);
            $options['enable_checkout_tracking'] = !empty($hq_settings['checkout_tracking_enabled']);
            $options['enable_fraud_blocker'] = !empty($hq_settings['fraud_blocker_enabled']);
            $options['enable_courier_service'] = !empty($hq_settings['courier_service_enabled']);
            $options['data_retention_policy'] = $hq_settings['data_retention_days'] ?? 90;
            update_option('cg_plugin_options', $options);
        }
        return true;
    }
    return false;
}

/**
 * AJAX handler for force sync.
 */
function cg_handle_force_sync_ajax()
{
    check_ajax_referer('cg_force_sync_ajax', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Permission denied.', 'checkoutguard')]);
        return;
    }

    if (cg_sync_with_hq()) {
        $cache = get_transient('cg_api_cache');
        wp_send_json_success([
            'message' => __('Successfully synced with Headquarters.', 'checkoutguard'),
            'cache' => $cache,
            'last_synced' => time(),
        ]);
    } else {
        // cg_register_site_with_hq()/cg_fetch_hq_configuration() already wrote
        // a specific "failed: ..." reason into cg_license_status when there is
        // one (bad signature, no API key returned, etc.) — surface that
        // instead of a generic message that reads as a network problem even
        // when the real cause is something else entirely.
        $status = get_option('cg_license_status', '');
        $reason = (is_string($status) && str_starts_with($status, 'failed:'))
            ? trim(substr($status, strlen('failed:')))
            : __('Please check API connectivity.', 'checkoutguard');
        update_option('cg_license_status', 'inactive');
        wp_send_json_error([
            'message' => __('Could not sync with Headquarters: ', 'checkoutguard') . $reason,
        ]);
    }
}

/**
 * Sanitizes settings and sends the settings array to the Headquarters API.
 * This version correctly handles unchecked checkboxes.
 */
function cg_sanitize_and_sync_settings($input)
{
    $options = get_option('cg_plugin_options', []);
    $new_options = [];

    // Sanitize and save text/select/textarea fields
    $new_options['data_retention_policy'] = isset($input['data_retention_policy']) ? absint($input['data_retention_policy']) : 90;
    $new_options['exclude_user_roles'] = isset($input['exclude_user_roles']) && is_array($input['exclude_user_roles']) ? array_map('sanitize_text_field', $input['exclude_user_roles']) : [];
    $new_options['custom_block_message'] = isset($input['custom_block_message']) ? sanitize_textarea_field($input['custom_block_message']) : ($options['custom_block_message'] ?? '');
    $new_options['ratio_blocker_threshold'] = isset($input['ratio_blocker_threshold']) ? absint($input['ratio_blocker_threshold']) : ($options['ratio_blocker_threshold'] ?? 20);
    $new_options['ratio_blocker_grace_period'] = isset($input['ratio_blocker_grace_period']) ? absint($input['ratio_blocker_grace_period']) : ($options['ratio_blocker_grace_period'] ?? 5);
    $new_options['ratio_blocker_message'] = isset($input['ratio_blocker_message']) ? sanitize_textarea_field($input['ratio_blocker_message']) : ($options['ratio_blocker_message'] ?? '');

    // Handle checkboxes correctly. They are only in $input if checked.
    $new_options['enable_checkout_tracking'] = !empty($input['enable_checkout_tracking']);
    $new_options['enable_fraud_blocker'] = !empty($input['enable_fraud_blocker']);
    $new_options['enable_courier_service'] = !empty($input['enable_courier_service']);
    $new_options['enable_order_statuses'] = !empty($input['enable_order_statuses']);
    $new_options['enable_invoices'] = !empty($input['enable_invoices']);
    $new_options['delete_on_uninstall'] = !empty($input['delete_on_uninstall']);

    // Preserve the API key from the value being saved, or fall back to the old DB value.
    if (isset($input['api_key'])) {
        $new_options['api_key'] = $input['api_key'];
    } elseif (isset($options['api_key'])) {
        $new_options['api_key'] = $options['api_key'];
    }

    // Preserve fraud blocker inline settings.
    // Check $input first (covers AJAX/sync update_option calls where $input has the new values),
    // then fall back to $options (covers main settings form where these keys aren't submitted).
    $preserve_keys = [
        'custom_block_message', 'fraud_auto_block_enabled', 'fraud_auto_block_attempts',
        'fraud_auto_block_window', 'fraud_auto_block_duration', 'fraud_velocity_enabled',
        'fraud_velocity_max_attempts', 'fraud_velocity_window', 'fraud_device_blocking',
        'fraud_device_site_block', 'fraud_risk_scoring_enabled', 'fraud_risk_score_threshold',
        'duplicate_blocker_enabled', 'duplicate_blocker_window', 'duplicate_blocker_mode',
        'duplicate_blocker_match_phone', 'duplicate_blocker_match_device', 'duplicate_blocker_match_ip', 'duplicate_blocker_match_email', 'duplicate_blocker_message',
    ];
    foreach ($preserve_keys as $key) {
        if (!isset($new_options[$key])) {
            if (isset($input[$key])) {
                $new_options[$key] = $input[$key];
            } elseif (isset($options[$key])) {
                $new_options[$key] = $options[$key];
            }
        }
    }

    // Prepare data for HQ sync
    $settings_to_sync = [
        'checkout_tracking_enabled' => $new_options['enable_checkout_tracking'],
        'fraud_blocker_enabled' => $new_options['enable_fraud_blocker'],
        'courier_service_enabled' => $new_options['enable_courier_service'],
        'data_retention_days' => $new_options['data_retention_policy'],
    ];

    // Send the update to the HQ in the background — the backend expects the
    // settings nested under a "settings" key, not sent flat at the top level.
    cg_call_hq_api('/sites/settings', ['settings' => $settings_to_sync], 'POST');

    return $new_options;
}

/**
 * Register all settings under a SINGLE options group.
 */
function cg_register_settings()
{
    register_setting('cg_settings', 'cg_plugin_options', [
        'sanitize_callback' => 'cg_sanitize_and_sync_settings'
    ]);
}
add_action('admin_init', 'cg_register_settings');

/**
 * Helper: render a single usage progress bar row.
 */
function cg_render_usage_row($label, $used, $limit)
{
    $used = (int) $used;
    $limit = (int) $limit;
    $is_unlimited = cg_is_unlimited_limit($limit);
    // limit === 0 means this feature is fully blocked for the current plan
    // (see cg_is_unlimited_limit()'s docblock) — always 100%, not a ratio to
    // compute (would divide by zero).
    $percentage = $is_unlimited ? 0 : ($limit > 0 ? min(round(($used / $limit) * 100), 100) : 100);

    if ($is_unlimited) {
        $bar_class = 'cg-usage-bar-fill--green';
    } elseif ($percentage >= 80) {
        $bar_class = 'cg-usage-bar-fill--red';
    } elseif ($percentage >= 50) {
        $bar_class = 'cg-usage-bar-fill--yellow';
    } else {
        $bar_class = 'cg-usage-bar-fill--green';
    }
    ?>
    <div class="cg-usage-row">
        <div class="cg-usage-row__header">
            <span class="cg-usage-row__label"><?php echo esc_html($label); ?></span>
            <span class="cg-usage-row__count">
                <?php echo esc_html($used); ?> / <?php echo $is_unlimited ? __('Unlimited', 'checkoutguard') : esc_html($limit); ?>
            </span>
        </div>
        <div class="cg-usage-bar-track">
            <div class="cg-usage-bar-fill <?php echo esc_attr($bar_class); ?>"
                style="width: <?php echo $is_unlimited ? '0' : esc_attr($percentage); ?>%"></div>
        </div>
        <?php if (!$is_unlimited && $percentage >= 80): ?>
            <span class="cg-usage-row__warning"><?php echo esc_html($percentage); ?>% <?php _e('used', 'checkoutguard'); ?></span>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Main render function for the settings page.
 */
function cg_render_settings_page()
{
    $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'account_usage';
    ?>
    <div class="wrap">
        <h1><?php _e('CheckoutGuard Settings', 'checkoutguard'); ?></h1>

        <nav class="nav-tab-wrapper">
            <a href="?page=cg-settings&tab=account_usage"
                class="nav-tab <?php echo $active_tab == 'account_usage' ? 'nav-tab-active' : ''; ?>">
                <span class="dashicons dashicons-chart-bar" style="margin-right:4px;"></span>
                <?php _e('Account & Usage', 'checkoutguard'); ?>
            </a>
            <a href="?page=cg-settings&tab=settings"
                class="nav-tab <?php echo $active_tab == 'settings' ? 'nav-tab-active' : ''; ?>">
                <span class="dashicons dashicons-admin-generic" style="margin-right:4px;"></span>
                <?php _e('Settings', 'checkoutguard'); ?>
            </a>
        </nav>

        <div class="cg-settings-content">
            <?php
            if ($active_tab == 'account_usage') {
                cg_render_account_usage_tab();
            } elseif ($active_tab == 'settings') {
                cg_render_settings_tab();
            }
            ?>
        </div>
    </div>
    <?php
}

/**
 * Render the Account & Usage tab content.
 */
function cg_render_account_usage_tab()
{
    $cache = get_transient('cg_api_cache');
    $last_synced = get_transient('cg_last_synced');
    $displayed_status = get_option('cg_license_status', 'inactive');

    // Account Info Card
    ?>
    <div class="cg-account-card">
        <div class="cg-account-info">
            <div class="cg-account-info__domain">
                <span class="dashicons dashicons-admin-site-alt3 cg-account-info__icon"></span>
                <div>
                    <h2 class="cg-account-info__domain-text"><?php echo esc_html(cg_get_root_domain(get_site_url())); ?></h2>
                    <div class="cg-account-info__meta">
                        <?php if ($cache): ?>
                            <span class="cg-account-info__plan">
                                <span class="dashicons dashicons-tag"></span>
                                <?php echo esc_html(ucwords($cache['plan_slug'] ?? $cache['plan'] ?? __('Unknown', 'checkoutguard'))); ?>
                            </span>
                        <?php endif; ?>
                        <?php
                        $status_class = 'cg-status-badge--inactive';
                        if ($displayed_status === 'active') $status_class = 'cg-status-badge--active';
                        elseif ($displayed_status === 'suspended') $status_class = 'cg-status-badge--warning';
                        ?>
                        <span class="cg-status-badge <?php echo esc_attr($status_class); ?>">
                            <?php echo esc_html(ucwords($displayed_status)); ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
        <div class="cg-account-actions">
            <button type="button" id="cg-refresh-sync" class="button button-secondary">
                <span class="dashicons dashicons-update"></span>
                <?php _e('Sync Now', 'checkoutguard'); ?>
                <span class="spinner"></span>
            </button>
            <?php if ($last_synced): ?>
                <span class="cg-account-actions__synced" id="cg-last-synced-text">
                    <?php
                    printf(
                        __('Last synced: %s ago', 'checkoutguard'),
                        human_time_diff($last_synced)
                    );
                    ?>
                </span>
            <?php else: ?>
                <span class="cg-account-actions__synced" id="cg-last-synced-text">
                    <?php _e('Not synced yet', 'checkoutguard'); ?>
                </span>
            <?php endif; ?>
        </div>
    </div>

    <?php cg_render_api_activity_panel(); ?>

    <?php if (!$cache): ?>
        <div class="cg-empty-state">
            <span class="dashicons dashicons-cloud"></span>
            <h3><?php _e('No data available', 'checkoutguard'); ?></h3>
            <p><?php _e('Click "Sync Now" to fetch your account data from the Headquarters.', 'checkoutguard'); ?></p>
        </div>
    <?php else: ?>
        <!-- Usage Groups -->
        <div class="cg-usage-groups">
            <!-- Checkout Tracking -->
            <div class="cg-usage-group">
                <div class="cg-usage-group__title">
                    <span class="dashicons dashicons-cart"></span>
                    <?php _e('Checkout Tracking', 'checkoutguard'); ?>
                </div>
                <?php cg_render_usage_row(
                    __('Incomplete Checkouts', 'checkoutguard'),
                    $cache['usage']['checkouts'] ?? 0,
                    $cache['limits']['checkouts'] ?? 0
                ); ?>
            </div>

            <!-- Fraud Protection -->
            <div class="cg-usage-group">
                <div class="cg-usage-group__title">
                    <span class="dashicons dashicons-shield"></span>
                    <?php _e('Fraud Protection', 'checkoutguard'); ?>
                </div>
                <?php
                $fraud_items = [
                    'fraud_ips' => __('Blocked IPs', 'checkoutguard'),
                    'fraud_emails' => __('Blocked Emails', 'checkoutguard'),
                    'fraud_phones' => __('Blocked Phones', 'checkoutguard'),
                    'fraud_domains' => __('Blocked Domains', 'checkoutguard'),
                    'fraud_addresses' => __('Blocked Addresses', 'checkoutguard'),
                    'fraud_devices' => __('Blocked Devices', 'checkoutguard'),
                ];
                foreach ($fraud_items as $key => $label) {
                    cg_render_usage_row($label, $cache['usage'][$key] ?? 0, $cache['limits'][$key] ?? 0);
                }
                ?>
            </div>

            <!-- Courier Service -->
            <div class="cg-usage-group">
                <div class="cg-usage-group__title">
                    <span class="dashicons dashicons-car"></span>
                    <?php _e('Courier Service', 'checkoutguard'); ?>
                </div>
                <?php cg_render_usage_row(
                    __('API Checks', 'checkoutguard'),
                    $cache['usage']['courier_checks'] ?? 0,
                    $cache['limits']['courier_checks'] ?? 0
                ); ?>
            </div>
        </div>

        <!-- Upgrade Section -->
        <div class="cg-upgrade-section">
            <div class="cg-upgrade-icon">
                <span class="dashicons dashicons-star-filled"></span>
            </div>
            <div class="cg-upgrade-text">
                <h3><?php _e('Looking for an Upgrade?', 'checkoutguard'); ?></h3>
                <p><?php _e('Find the perfect plan that fits your needs and unlock more features.', 'checkoutguard'); ?></p>
            </div>
            <div class="cg-upgrade-actions">
                <button type="button" class="button button-primary cg-open-upgrade-modal"><?php _e('View Our Plans', 'checkoutguard'); ?></button>
                <a href="https://coderzonebd.com/contact" target="_blank" class="button button-secondary"><?php _e('Get Support', 'checkoutguard'); ?></a>
            </div>
        </div>
    <?php endif;
}

/**
 * Renders a collapsible log of the last 30 Headquarters API calls (endpoint,
 * method, result), populated by cg_log_api_hit() inside cg_call_hq_api().
 * Shown on the Account & Usage tab so a failed sync is diagnosable from the
 * WP admin itself instead of needing server-level log access — this is what
 * actually shows the real reason (e.g. "no API key returned") behind a
 * generic-looking sync failure.
 */
function cg_render_api_activity_panel()
{
    $log = get_option('cg_api_log', []);
    if (!is_array($log) || empty($log)) {
        return;
    }
    ?>
    <details class="cg-api-activity" style="margin: 16px 0;">
        <summary style="cursor:pointer; font-weight:600;">
            <?php _e('Recent API Activity', 'checkoutguard'); ?>
            <?php if (!empty($log[0]) && !$log[0]['success']): ?>
                <span class="cg-status-badge cg-status-badge--warning" style="margin-left:8px;">
                    <?php _e('Last call failed', 'checkoutguard'); ?>
                </span>
            <?php endif; ?>
        </summary>
        <table class="widefat striped" style="margin-top:8px;">
            <thead>
                <tr>
                    <th><?php _e('Time', 'checkoutguard'); ?></th>
                    <th><?php _e('Endpoint', 'checkoutguard'); ?></th>
                    <th><?php _e('Method', 'checkoutguard'); ?></th>
                    <th><?php _e('Result', 'checkoutguard'); ?></th>
                    <th><?php _e('Details', 'checkoutguard'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($log as $entry): ?>
                    <tr>
                        <td><?php echo esc_html(human_time_diff($entry['time']) . ' ago'); ?></td>
                        <td><code><?php echo esc_html($entry['endpoint']); ?></code></td>
                        <td><?php echo esc_html($entry['method']); ?></td>
                        <td>
                            <?php if ($entry['success']): ?>
                                <span class="cg-status-badge cg-status-badge--active">
                                    <?php echo esc_html($entry['status_code'] ? $entry['status_code'] : 'OK'); ?>
                                </span>
                            <?php else: ?>
                                <span class="cg-status-badge cg-status-badge--warning">
                                    <?php echo esc_html($entry['status_code'] ? $entry['status_code'] : __('Failed', 'checkoutguard')); ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($entry['message'] ?? ''); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </details>
    <?php
}

/**
 * Render the Settings tab content.
 */
function cg_render_settings_tab()
{
    $options = get_option('cg_plugin_options', []);
    ?>
    <form action="options.php" method="post">
        <?php settings_fields('cg_settings'); ?>

        <!-- Module Toggles -->
        <h2 class="cg-section-title">
            <span class="dashicons dashicons-plugins-checked"></span>
            <?php _e('Module Toggles', 'checkoutguard'); ?>
        </h2>
        <div class="cg-module-cards">
            <?php
            $modules = [
                [
                    'key' => 'enable_checkout_tracking',
                    'icon' => 'dashicons-cart',
                    'title' => __('Checkout Tracking', 'checkoutguard'),
                    'desc' => __('Track incomplete checkouts and recover lost revenue by following up with customers.', 'checkoutguard'),
                    'color' => 'var(--cg-info-color)',
                ],
                [
                    'key' => 'enable_fraud_blocker',
                    'icon' => 'dashicons-shield',
                    'title' => __('Fraud Blocker', 'checkoutguard'),
                    'desc' => __('Block fraudulent orders by IP, email, phone, device fingerprint, and more.', 'checkoutguard'),
                    'color' => 'var(--cg-danger-color)',
                ],
                [
                    'key' => 'enable_courier_service',
                    'icon' => 'dashicons-car',
                    'title' => __('Courier Service', 'checkoutguard'),
                    'desc' => __('Check courier success ratios before accepting orders to reduce failed deliveries.', 'checkoutguard'),
                    'color' => 'var(--cg-success-color)',
                ],
                [
                    'key' => 'enable_order_statuses',
                    'icon' => 'dashicons-list-view',
                    'title' => __('Order Statuses', 'checkoutguard'),
                    'desc' => __('Create and manage custom WooCommerce order statuses with a visual overview.', 'checkoutguard'),
                    'color' => 'var(--cg-warning-color)',
                ],
                [
                    'key' => 'enable_invoices',
                    'icon' => 'dashicons-media-spreadsheet',
                    'title' => __('Invoices', 'checkoutguard'),
                    'desc' => __('Auto-generate PDF invoices on order completion, with customer download and email attachment.', 'checkoutguard'),
                    'color' => 'var(--cg-info-color)',
                ],
            ];
            foreach ($modules as $module):
                $checked = !empty($options[$module['key']]);
                ?>
                <div class="cg-module-card <?php echo $checked ? 'cg-module-card--active' : ''; ?>">
                    <div class="cg-module-card__icon" style="background: <?php echo esc_attr($module['color']); ?>20; color: <?php echo esc_attr($module['color']); ?>;">
                        <span class="dashicons <?php echo esc_attr($module['icon']); ?>"></span>
                    </div>
                    <div class="cg-module-card__body">
                        <div class="cg-module-card__header">
                            <h3 class="cg-module-card__title"><?php echo esc_html($module['title']); ?></h3>
                            <label class="cg-toggle">
                                <input type="checkbox" class="cg-toggle__input"
                                    name="cg_plugin_options[<?php echo esc_attr($module['key']); ?>]"
                                    value="1" <?php checked($checked); ?>>
                                <span class="cg-toggle__slider"></span>
                            </label>
                        </div>
                        <p class="cg-module-card__desc"><?php echo esc_html($module['desc']); ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- General Settings -->
        <h2 class="cg-section-title">
            <span class="dashicons dashicons-admin-generic"></span>
            <?php _e('General Settings', 'checkoutguard'); ?>
        </h2>
        <div class="cg-settings-card">
            <div class="cg-settings-field">
                <label class="cg-settings-field__label" for="cg_data_retention">
                    <?php _e('Data Retention Policy', 'checkoutguard'); ?>
                </label>
                <div class="cg-settings-field__input">
                    <?php $retention = $options['data_retention_policy'] ?? '90'; ?>
                    <select name="cg_plugin_options[data_retention_policy]" id="cg_data_retention">
                        <option value="30" <?php selected($retention, '30'); ?>>30 <?php _e('Days', 'checkoutguard'); ?></option>
                        <option value="90" <?php selected($retention, '90'); ?>>90 <?php _e('Days', 'checkoutguard'); ?></option>
                        <option value="365" <?php selected($retention, '365'); ?>>1 <?php _e('Year', 'checkoutguard'); ?></option>
                        <option value="0" <?php selected($retention, '0'); ?>><?php _e('Never Delete', 'checkoutguard'); ?></option>
                    </select>
                    <p class="description"><?php _e('Delete incomplete checkout records after this many days.', 'checkoutguard'); ?></p>
                </div>
            </div>

            <div class="cg-settings-field">
                <label class="cg-settings-field__label">
                    <?php _e('Exclude User Roles', 'checkoutguard'); ?>
                </label>
                <div class="cg-settings-field__input">
                    <div class="cg-role-checkboxes">
                        <?php
                        $excluded_roles = $options['exclude_user_roles'] ?? [];
                        $roles = get_editable_roles();
                        foreach ($roles as $role_slug => $role_info):
                            $is_checked = is_array($excluded_roles) && in_array($role_slug, $excluded_roles);
                            ?>
                            <label class="cg-role-checkbox">
                                <input type="checkbox" name="cg_plugin_options[exclude_user_roles][]"
                                    value="<?php echo esc_attr($role_slug); ?>" <?php checked($is_checked); ?>>
                                <?php echo esc_html($role_info['name']); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="description"><?php _e('Do not track checkouts for these user roles.', 'checkoutguard'); ?></p>
                </div>
            </div>
        </div>

        <!-- Advanced / Danger Zone -->
        <h2 class="cg-section-title">
            <span class="dashicons dashicons-warning"></span>
            <?php _e('Advanced', 'checkoutguard'); ?>
        </h2>
        <div class="cg-settings-card cg-settings-card--danger">
            <?php if (!empty($options['enable_order_statuses'])) : ?>
            <div class="cg-settings-field">
                <div class="cg-settings-field__danger-row">
                    <div>
                        <strong><?php _e('Reset Order Statuses', 'checkoutguard'); ?></strong>
                        <p class="description">
                            <span class="dashicons dashicons-warning" style="color: var(--cg-danger-color); font-size: 14px; width: 14px; height: 14px;"></span>
                            <?php _e('This will reset all order statuses back to WooCommerce defaults. Any custom statuses will be removed. Orders on custom statuses will NOT be migrated automatically.', 'checkoutguard'); ?>
                        </p>
                    </div>
                    <button type="button" class="button button-secondary" id="cg-reset-order-statuses">
                        <?php _e('Reset Statuses', 'checkoutguard'); ?>
                    </button>
                </div>
            </div>
            <?php endif; ?>
            <div class="cg-settings-field">
                <div class="cg-settings-field__danger-row">
                    <div>
                        <strong><?php _e('Delete all data on uninstall', 'checkoutguard'); ?></strong>
                        <p class="description">
                            <span class="dashicons dashicons-warning" style="color: var(--cg-danger-color); font-size: 14px; width: 14px; height: 14px;"></span>
                            <?php _e('This will permanently delete all plugin tables and options when you delete the plugin from the Plugins page.', 'checkoutguard'); ?>
                        </p>
                    </div>
                    <label class="cg-toggle cg-toggle--danger">
                        <input type="checkbox" class="cg-toggle__input"
                            name="cg_plugin_options[delete_on_uninstall]"
                            value="1" <?php checked(!empty($options['delete_on_uninstall'])); ?>>
                        <span class="cg-toggle__slider"></span>
                    </label>
                </div>
            </div>
        </div>

        <div class="cg-settings-submit">
            <?php submit_button(__('Save Settings', 'checkoutguard'), 'primary', 'submit', false); ?>
        </div>
    </form>
    <?php
}

/*
 * --- Field Rendering Callbacks (kept for backwards compatibility) ---
 */

function cg_render_data_retention_field()
{
    $options = get_option('cg_plugin_options', []);
    $value = $options['data_retention_policy'] ?? '90';
    ?>
    <select name="cg_plugin_options[data_retention_policy]">
        <option value="30" <?php selected($value, '30'); ?>>30 <?php _e('Days', 'checkoutguard'); ?></option>
        <option value="90" <?php selected($value, '90'); ?>>90 <?php _e('Days', 'checkoutguard'); ?></option>
        <option value="365" <?php selected($value, '365'); ?>>1 <?php _e('Year', 'checkoutguard'); ?></option>
        <option value="0" <?php selected($value, '0'); ?>><?php _e('Never Delete', 'checkoutguard'); ?></option>
    </select>
    <p class="description">
        <?php _e('Delete incomplete checkout records after this many days.', 'checkoutguard'); ?>
    </p>
    <?php
}

function cg_render_exclude_roles_field()
{
    $options = get_option('cg_plugin_options', []);
    $excluded_roles = $options['exclude_user_roles'] ?? [];
    $roles = get_editable_roles();
    foreach ($roles as $role_slug => $role_info) {
        $checked = is_array($excluded_roles) && in_array($role_slug, $excluded_roles) ? 'checked' : '';
        echo "<label><input type='checkbox' name='cg_plugin_options[exclude_user_roles][]' value='{$role_slug}' {$checked}> {$role_info['name']}</label><br>";
    }
    echo '<p class="description">' . __('Do not track checkouts for these user roles.', 'checkoutguard') . '</p>';
}

function cg_render_enable_checkout_tracking_field()
{
    $options = get_option('cg_plugin_options', []);
    $checked = !empty($options['enable_checkout_tracking']) ? 'checked' : '';
    echo "<label><input type='checkbox' name='cg_plugin_options[enable_checkout_tracking]' value='1' {$checked}> " . __('Enable this module.', 'checkoutguard') . '</label>';
    echo '<p class="description">' . __('This allows the plugin to track incomplete checkouts.', 'checkoutguard') . '</p>';
}

function cg_render_enable_fraud_blocker_field()
{
    $options = get_option('cg_plugin_options', []);
    $checked = !empty($options['enable_fraud_blocker']) ? 'checked' : '';
    echo "<label><input type='checkbox' name='cg_plugin_options[enable_fraud_blocker]' value='1' {$checked}> " . __('Enable this module.', 'checkoutguard') . '</label>';
    echo '<p class="description">' . __('This enables the fraud blocker at checkout.', 'checkoutguard') . '</p>';
}

function cg_render_custom_block_message_field()
{
    $options = get_option('cg_plugin_options', []);
    $message = $options['custom_block_message'] ?? __('Your order cannot be processed at this time. Please contact support.', 'checkoutguard');
    echo "<textarea name='cg_plugin_options[custom_block_message]' rows='4' class='large-text'>" . esc_textarea($message) . "</textarea>";
    echo '<p class="description">' . __('The message shown to customers who are blocked by IP, email, or phone.', 'checkoutguard') . '</p>';
}

function cg_render_enable_courier_field()
{
    $options = get_option('cg_plugin_options', []);
    $checked = !empty($options['enable_courier_service']) ? 'checked' : '';
    echo "<label><input type='checkbox' name='cg_plugin_options[enable_courier_service]' value='1' {$checked}> " . __('Enable this module.', 'checkoutguard') . '</label>';
    echo '<p class="description">' . __("This enables the courier success ratio check feature.", 'checkoutguard') . '</p>';
}

function cg_render_delete_on_uninstall_field()
{
    $options = get_option('cg_plugin_options', []);
    $checked = !empty($options['delete_on_uninstall']) ? 'checked' : '';
    echo "<label><input type='checkbox' name='cg_plugin_options[delete_on_uninstall]' value='1' {$checked}> " . __('Delete all plugin data upon uninstallation.', 'checkoutguard') . '</label>';
    echo '<p class="description"><strong>' . __('Warning:', 'checkoutguard') . '</strong> ' . __('This will permanently delete all plugin tables and options when you delete the plugin from the Plugins page.', 'checkoutguard') . '</p>';
}

function cg_render_ratio_threshold_field()
{
    $options = get_option('cg_plugin_options', []);
    $value = $options['ratio_blocker_threshold'] ?? '20';
    echo "<input type='number' name='cg_plugin_options[ratio_blocker_threshold]' value='" . esc_attr($value) . "' class='small-text' min='0' max='100' /> %";
    echo '<p class="description">' . __('Block orders if the customer\'s success ratio is at or below this percentage.', 'checkoutguard') . '</p>';
}

function cg_render_ratio_grace_period_field()
{
    $options = get_option('cg_plugin_options', []);
    $value = $options['ratio_blocker_grace_period'] ?? '5';
    echo "<input type='number' name='cg_plugin_options[ratio_blocker_grace_period]' value='" . esc_attr($value) . "' class='small-text' min='0' /> " . __('orders', 'checkoutguard');
    echo '<p class="description">' . __('Do not block new customers until they have more than this many total orders.', 'checkoutguard') . '</p>';
}

function cg_render_ratio_blocker_message_field()
{
    $options = get_option('cg_plugin_options', []);
    $default_message = 'Your courier success ratio is {ratio}%, which is too low. Please contact us to complete your order.';
    $value = $options['ratio_blocker_message'] ?? $default_message;
    echo "<textarea name='cg_plugin_options[ratio_blocker_message]' rows='3' class='large-text'>" . esc_textarea($value) . "</textarea>";
    echo '<p class="description">' . __('This message will be shown in a popup to the customer. Use <code>{ratio}</code> as a placeholder for the customer\'s success ratio.', 'checkoutguard') . '</p>';
}

/**
 * Inline sync on CG admin page loads.
 * Supplements cron by syncing when cache is missing or stale (>4 hours).
 * Includes a 15-minute cooldown on failed attempts.
 */
function cg_maybe_inline_sync()
{
    if (!is_admin() || !current_user_can('manage_woocommerce')) {
        return;
    }
    $screen = get_current_screen();
    if (!$screen) {
        return;
    }

    $is_cg = (strpos($screen->id, 'cg-main-dashboard') !== false)
          || (strpos($screen->id, '_page_cg-') !== false);
    if (!$is_cg) {
        return;
    }

    // Cooldown after failed sync
    if (get_transient('cg_sync_cooldown')) {
        return;
    }

    $cache_missing = (get_transient('cg_api_cache') === false);
    $last_synced   = get_transient('cg_last_synced');
    $stale         = !$last_synced || ((time() - (int) $last_synced) > 4 * HOUR_IN_SECONDS);

    if ($cache_missing || $stale) {
        $result = cg_sync_with_hq();
        if (!$result) {
            set_transient('cg_sync_cooldown', true, 15 * MINUTE_IN_SECONDS);
        }
    }
}
add_action('current_screen', 'cg_maybe_inline_sync');
