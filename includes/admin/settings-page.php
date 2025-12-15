<?php
// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register settings
 */
function checkoutguard_register_settings()
{
    // Register settings group
    register_setting('checkoutguard_settings', 'checkoutguard_settings', 'checkoutguard_sanitize_settings');

    // General Settings Section
    add_settings_section(
        'checkoutguard_general_section',
        esc_html__('General Settings', 'checkoutguard'),
        'checkoutguard_general_section_callback',
        'checkoutguard-settings'
    );

    // Feature Settings Section
    add_settings_section(
        'checkoutguard_features_section',
        esc_html__('Feature Management', 'checkoutguard'),
        'checkoutguard_features_section_callback',
        'checkoutguard-settings'
    );

    // Tracking Settings Section
    add_settings_section(
        'checkoutguard_tracking_section',
        esc_html__('Tracking Settings', 'checkoutguard'),
        'checkoutguard_tracking_section_callback',
        'checkoutguard-settings'
    );

    // General Settings Fields
    add_settings_field(
        'enable_incomplete_checkout_tracking',
        esc_html__('Enable Incomplete Checkout Tracking', 'checkoutguard'),
        'checkoutguard_checkbox_field_callback',
        'checkoutguard-settings',
        'checkoutguard_general_section',
        ['field' => 'enable_incomplete_checkout_tracking', 'description' => 'Track abandoned checkouts', 'default' => true]
    );

    add_settings_field(
        'enable_fraud_blocker',
        esc_html__('Enable Fraud Blocker', 'checkoutguard'),
        'checkoutguard_checkbox_field_callback',
        'checkoutguard-settings',
        'checkoutguard_general_section',
        ['field' => 'enable_fraud_blocker', 'description' => 'Block fraudulent orders', 'default' => true]
    );

    add_settings_field(
        'enable_courier_check',
        esc_html__('Enable Courier Check', 'checkoutguard'),
        'checkoutguard_checkbox_field_callback',
        'checkoutguard-settings',
        'checkoutguard_general_section',
        ['field' => 'enable_courier_check', 'description' => 'Check courier success rates', 'default' => true]
    );

    add_settings_field(
        'enable_invoice_shipping',
        esc_html__('Enable Invoice & Shipping', 'checkoutguard'),
        'checkoutguard_checkbox_field_callback',
        'checkoutguard-settings',
        'checkoutguard_general_section',
        ['field' => 'enable_invoice_shipping', 'description' => 'Generate invoices and shipping slips', 'default' => true]
    );

    // Tracking Settings Fields
    add_settings_field(
        'tracking_expiry_days',
        esc_html__('Tracking Data Expiry (Days)', 'checkoutguard'),
        'checkoutguard_number_field_callback',
        'checkoutguard-settings',
        'checkoutguard_tracking_section',
        ['field' => 'tracking_expiry_days', 'description' => 'Delete incomplete checkout data after X days (0 = never)', 'default' => 30]
    );

    add_settings_field(
        'max_recent_searches',
        esc_html__('Max Recent Searches', 'checkoutguard'),
        'checkoutguard_number_field_callback',
        'checkoutguard-settings',
        'checkoutguard_tracking_section',
        ['field' => 'max_recent_searches', 'description' => 'Maximum number of recent courier searches to keep', 'default' => 10]
    );

    // Feature Settings Fields
    add_settings_field(
        'enable_dashboard_widget',
        esc_html__('Enable Dashboard Widget', 'checkoutguard'),
        'checkoutguard_checkbox_field_callback',
        'checkoutguard-settings',
        'checkoutguard_features_section',
        ['field' => 'enable_dashboard_widget', 'description' => 'Show CheckoutGuard widget on WordPress dashboard', 'default' => true]
    );

    add_settings_field(
        'enable_branding_footer',
        esc_html__('Enable Branding Footer', 'checkoutguard'),
        'checkoutguard_checkbox_field_disabled_callback',
        'checkoutguard-settings',
        'checkoutguard_features_section',
        ['field' => 'enable_branding_footer', 'description' => 'Show "Powered by Coder Zone BD" footer (Required in free version)', 'default' => true]
    );
}
add_action('admin_init', 'checkoutguard_register_settings');

/**
 * Section callbacks
 */
function checkoutguard_general_section_callback()
{
    echo '<p>' . esc_html__('Configure the main features of CheckoutGuard plugin.', 'checkoutguard') . '</p>';
    echo '<p class="description" style="background: #fff3cd; padding: 10px; border-left: 4px solid #ffc107; margin-top: 10px;"><strong>' . esc_html__('Note:', 'checkoutguard') . '</strong> ' . esc_html__('Disabling a feature will hide its menu item from the CheckoutGuard navigation.', 'checkoutguard') . '</p>';
}

function checkoutguard_features_section_callback()
{
    echo '<p>' . esc_html__('Enable or disable additional features.', 'checkoutguard') . '</p>';
    if (!CHECKOUTGUARD_IS_PRO) {
        echo '<p class="description" style="color: #7c3aed; font-weight: 600;">' . esc_html__('Note: Some features are locked in the free version and require Pro upgrade.', 'checkoutguard') . '</p>';
    }
}

function checkoutguard_tracking_section_callback()
{
    echo '<p>' . esc_html__('Configure tracking and data retention settings.', 'checkoutguard') . '</p>';
}

/**
 * Field callbacks
 */
function checkoutguard_checkbox_field_callback($args)
{
    $field = $args['field'];
    $description = isset($args['description']) ? $args['description'] : '';
    $default = isset($args['default']) ? $args['default'] : false;
    
    $options = get_option('checkoutguard_settings');
    $value = isset($options[$field]) ? $options[$field] : $default;
    
    echo '<label>';
    echo '<input type="checkbox" name="checkoutguard_settings[' . esc_attr($field) . ']" value="1" ' . checked($value, 1, false) . ' />';
    echo ' <span class="description">' . esc_html($description) . '</span>';
    echo '</label>';
}

function checkoutguard_checkbox_field_disabled_callback($args)
{
    $field = $args['field'];
    $description = isset($args['description']) ? $args['description'] : '';
    
    echo '<label>';
    echo '<input type="checkbox" checked disabled style="opacity: 0.6; cursor: not-allowed;" />';
    echo '<input type="hidden" name="checkoutguard_settings[' . esc_attr($field) . ']" value="1" />';
    echo ' <span class="description">' . esc_html($description) . '</span>';
    if (!CHECKOUTGUARD_IS_PRO) {
        echo ' <span class="description" style="color: #7c3aed; font-weight: 600;"> [' . esc_html__('Upgrade to Pro to customize', 'checkoutguard') . ']</span>';
    }
    echo '</label>';
}

function checkoutguard_number_field_callback($args)
{
    $field = $args['field'];
    $description = isset($args['description']) ? $args['description'] : '';
    $default = isset($args['default']) ? $args['default'] : 0;
    
    $options = get_option('checkoutguard_settings');
    $value = isset($options[$field]) ? $options[$field] : $default;
    
    echo '<input type="number" name="checkoutguard_settings[' . esc_attr($field) . ']" value="' . esc_attr($value) . '" class="regular-text" min="0" />';
    echo '<p class="description">' . esc_html($description) . '</p>';
}

/**
 * Sanitize settings
 */
function checkoutguard_sanitize_settings($input)
{
    $sanitized = array();

    // Checkbox fields
    $checkboxes = [
        'enable_incomplete_checkout_tracking',
        'enable_fraud_blocker',
        'enable_courier_check',
        'enable_invoice_shipping',
        'enable_dashboard_widget'
    ];

    foreach ($checkboxes as $checkbox) {
        $sanitized[$checkbox] = isset($input[$checkbox]) ? 1 : 0;
    }
    
    // Branding footer is always enabled in free version
    $sanitized['enable_branding_footer'] = CHECKOUTGUARD_IS_PRO ? (isset($input['enable_branding_footer']) ? 1 : 0) : 1;

    // Number fields
    if (isset($input['tracking_expiry_days'])) {
        $sanitized['tracking_expiry_days'] = absint($input['tracking_expiry_days']);
    }

    if (isset($input['max_recent_searches'])) {
        $sanitized['max_recent_searches'] = absint($input['max_recent_searches']);
    }

    return $sanitized;
}

/**
 * Get a specific setting value
 */
function checkoutguard_get_setting($key, $default = false)
{
    $options = get_option('checkoutguard_settings');
    
    // If options don't exist yet (first time), return default
    if ($options === false) {
        return $default;
    }
    
    // Return saved value or default
    return isset($options[$key]) ? $options[$key] : $default;
}

/**
 * Check if branding footer should be displayed
 * Always returns true for free version
 */
function checkoutguard_show_branding()
{
    if (!CHECKOUTGUARD_IS_PRO) {
        return true; // Always show branding in free version
    }
    return checkoutguard_get_setting('enable_branding_footer', true);
}

/**
 * Render settings page
 */
function checkoutguard_render_settings_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    // Handle form submission message
    if (isset($_GET['settings-updated'])) {
        add_settings_error(
            'checkoutguard_messages',
            'checkoutguard_message',
            esc_html__('Settings saved successfully.', 'checkoutguard'),
            'success'
        );
    }

    settings_errors('checkoutguard_messages');
    ?>
    <div class="wrap cg-settings-page">
        <div class="cg-page-header-modern">
            <div class="cg-header-content">
                <div class="cg-header-icon">
                    <span class="dashicons dashicons-admin-settings"></span>
                </div>
                <div class="cg-header-text">
                    <h1><?php echo esc_html__('Settings', 'checkoutguard'); ?></h1>
                    <p class="cg-header-description"><?php echo esc_html__('Configure CheckoutGuard plugin features and options', 'checkoutguard'); ?></p>
                </div>
            </div>
        </div>

        <div class="cg-content-wrapper">
            <form method="post" action="options.php" class="cg-settings-form">
                <?php
                settings_fields('checkoutguard_settings');
                do_settings_sections('checkoutguard-settings');
                ?>

                <div class="cg-settings-actions">
                    <?php submit_button(esc_html__('Save Settings', 'checkoutguard'), 'primary', 'submit', false); ?>
                    <button type="button" class="button button-secondary cg-reset-settings" onclick="return confirm('<?php echo esc_js(__('Are you sure you want to reset all settings to default values?', 'checkoutguard')); ?>');">
                        <?php echo esc_html__('Reset to Defaults', 'checkoutguard'); ?>
                    </button>
                </div>
            </form>

            <div class="cg-settings-info">
                <div class="cg-info-box">
                    <h3><span class="dashicons dashicons-info"></span> <?php echo esc_html__('About CheckoutGuard', 'checkoutguard'); ?></h3>
                    <p><?php echo esc_html__('CheckoutGuard helps you track incomplete checkouts, prevent fraud, check courier reliability, and manage invoices efficiently.', 'checkoutguard'); ?></p>
                    <ul class="cg-info-list">
                        <li><strong><?php echo esc_html__('Version:', 'checkoutguard'); ?></strong> <?php echo esc_html(CHECKOUTGUARD_VERSION); ?></li>
                        <li><strong><?php echo esc_html__('Developer:', 'checkoutguard'); ?></strong> Coder Zone BD</li>
                        <li><strong><?php echo esc_html__('Support:', 'checkoutguard'); ?></strong> <a href="mailto:support@coderzonebd.com">support@coderzonebd.com</a></li>
                    </ul>
                </div>

                <div class="cg-info-box cg-upgrade-box">
                    <h3><span class="dashicons dashicons-star-filled"></span> <?php echo esc_html__('Upgrade to Pro', 'checkoutguard'); ?></h3>
                    <p><?php echo esc_html__('Get access to advanced features:', 'checkoutguard'); ?></p>
                    <ul class="cg-pro-features">
                        <li>✓ <?php echo esc_html__('Advanced Fraud Detection', 'checkoutguard'); ?></li>
                        <li>✓ <?php echo esc_html__('Automated Email Recovery', 'checkoutguard'); ?></li>
                        <li>✓ <?php echo esc_html__('Detailed Analytics', 'checkoutguard'); ?></li>
                        <li>✓ <?php echo esc_html__('Priority Support', 'checkoutguard'); ?></li>
                        <li>✓ <?php echo esc_html__('Custom Branding Options', 'checkoutguard'); ?></li>
                    </ul>
                    <a href="#" class="button button-primary cg-upgrade-btn"><?php echo esc_html__('Upgrade Now', 'checkoutguard'); ?></a>
                </div>
            </div>
        </div>

        <!-- Branding Footer -->
        <div class="cg-branding-footer">
            <p><?php esc_html_e('Powered by', 'checkoutguard'); ?> <span class="cg-brand-name"><?php esc_html_e('Coder Zone BD', 'checkoutguard'); ?></span></p>
        </div>
    </div>

    <style>
        .cg-settings-page .cg-content-wrapper {
            display: grid;
            grid-template-columns: 1fr 350px;
            gap: 30px;
            margin-top: 30px;
        }

        @media (max-width: 1280px) {
            .cg-settings-page .cg-content-wrapper {
                grid-template-columns: 1fr;
            }
        }

        .cg-settings-form {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            padding: 30px;
        }

        .cg-settings-form h2 {
            font-size: 18px;
            margin-top: 0;
            padding-bottom: 15px;
            border-bottom: 2px solid #4f46e5;
            color: #1e293b;
        }

        .cg-settings-form table {
            margin-top: 20px;
        }

        .cg-settings-form .form-table th {
            padding: 20px 10px 20px 0;
            font-weight: 600;
            color: #334155;
        }

        .cg-settings-form .form-table td {
            padding: 20px 10px;
        }

        .cg-settings-actions {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            gap: 10px;
        }

        .cg-reset-settings:hover {
            background: #ef4444;
            color: #fff;
            border-color: #dc2626;
        }

        .cg-settings-info {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .cg-info-box {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            padding: 25px;
        }

        .cg-info-box h3 {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 16px;
            margin: 0 0 15px 0;
            color: #1e293b;
        }

        .cg-info-box h3 .dashicons {
            color: #4f46e5;
            font-size: 20px;
            width: 20px;
            height: 20px;
        }

        .cg-info-box p {
            color: #64748b;
            line-height: 1.6;
            margin-bottom: 15px;
        }

        .cg-info-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .cg-info-list li {
            padding: 8px 0;
            color: #475569;
            border-bottom: 1px solid #f1f5f9;
        }

        .cg-info-list li:last-child {
            border-bottom: none;
        }

        .cg-upgrade-box {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
        }

        .cg-upgrade-box h3,
        .cg-upgrade-box p {
            color: #fff;
        }

        .cg-upgrade-box h3 .dashicons {
            color: #fbbf24;
        }

        .cg-pro-features {
            list-style: none;
            padding: 0;
            margin: 15px 0;
        }

        .cg-pro-features li {
            padding: 8px 0;
            color: rgba(255, 255, 255, 0.95);
            font-size: 14px;
        }

        .cg-upgrade-btn {
            display: inline-block;
            margin-top: 10px;
            background: #fff !important;
            color: #764ba2 !important;
            border: none !important;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .cg-upgrade-btn:hover {
            background: #f9fafb !important;
            transform: translateY(-1px);
            box-shadow: 0 6px 8px rgba(0,0,0,0.15);
        }
    </style>
    <?php
}
