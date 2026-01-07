<?php
// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

// Security Headers removed - they cause 'headers already sent' errors
// WordPress handles security headers appropriately

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

    // Fraud Blocker Settings Section
    add_settings_section(
        'checkoutguard_fraud_blocker_section',
        esc_html__('Fraud Blocker Settings', 'checkoutguard'),
        'checkoutguard_fraud_blocker_section_callback',
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

    // Fraud Blocker Settings Fields
    add_settings_field(
        'blocked_phone_error_message',
        esc_html__('Blocked Phone Error Message', 'checkoutguard'),
        'checkoutguard_textarea_field_callback',
        'checkoutguard-settings',
        'checkoutguard_fraud_blocker_section',
        ['field' => 'blocked_phone_error_message', 'description' => 'Message shown to customers when their phone number is blocked', 'default' => 'Your order cannot be processed at this time. Please contact support.']
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
    echo '<div class="checkoutguard-settings-section-header">';
    echo '<p style="margin: 0;">' . esc_html__('Configure the main features of CheckoutGuard plugin.', 'checkoutguard') . '</p>';
    echo '</div>';
    echo '<div class="notice notice-warning inline" style="margin: 15px 0; padding: 10px 15px;">';
    echo '<p style="margin: 0;"><span class="dashicons dashicons-info" style="color: #f0b429;"></span> ' . esc_html__('Disabling a feature will hide its menu item from CheckoutGuard navigation.', 'checkoutguard') . '</p>';
    echo '</div>';
}

function checkoutguard_features_section_callback()
{
    echo '<div class="checkoutguard-settings-section-header">';
    echo '<p style="margin: 0;">' . esc_html__('Enable or disable additional features.', 'checkoutguard') . '</p>';
    echo '</div>';
    if (!CHECKOUTGUARD_IS_PRO) {
        echo '<div class="notice notice-info inline" style="margin: 15px 0; padding: 10px 15px;">';
        echo '<p style="margin: 0;"><span class="dashicons dashicons-info" style="color: #00a0d2;"></span> ' . esc_html__('Some features are locked in the free version and require Pro upgrade.', 'checkoutguard') . '</p>';
        echo '</div>';
    }
}

function checkoutguard_tracking_section_callback()
{
    echo '<div class="checkoutguard-settings-section-header">';
    echo '<p style="margin: 0;">' . esc_html__('Configure tracking and data retention settings.', 'checkoutguard') . '</p>';
    echo '</div>';
}

function checkoutguard_fraud_blocker_section_callback()
{
    echo '<div class="checkoutguard-settings-section-header">';
    echo '<p style="margin: 0;">' . esc_html__('Configure fraud blocker settings and error messages.', 'checkoutguard') . '</p>';
    echo '</div>';
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
    
    echo '<div class="checkoutguard-toggle-field">';
    echo '<label class="checkoutguard-toggle-switch">';
    echo '<input type="checkbox" name="checkoutguard_settings[' . esc_attr($field) . ']" value="1" ' . checked($value, 1, false) . ' />';
    echo '<span class="checkoutguard-toggle-slider"></span>';
    echo '</label>';
    echo '<span class="checkoutguard-toggle-label">' . esc_html($description) . '</span>';
    echo '</div>';
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
        echo ' <span class="description" style="color: var(--checkoutguard-primary-color); font-weight: 600;"> [' . esc_html__('Upgrade to Pro to customize', 'checkoutguard') . ']</span>';
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

function checkoutguard_textarea_field_callback($args)
{
    $field = $args['field'];
    $description = isset($args['description']) ? $args['description'] : '';
    $default = isset($args['default']) ? $args['default'] : '';
    
    $options = get_option('checkoutguard_settings');
    $value = isset($options[$field]) ? $options[$field] : $default;
    
    echo '<textarea name="checkoutguard_settings[' . esc_attr($field) . ']" rows="3" class="large-text" style="max-width: 600px;">' . esc_textarea($value) . '</textarea>';
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

    // Text fields
    if (isset($input['blocked_phone_error_message'])) {
        $sanitized['blocked_phone_error_message'] = sanitize_textarea_field($input['blocked_phone_error_message']);
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

    $is_pro_active = defined('CHECKOUTGUARD_PRO_VERSION');

    // Allow pro plugin to handle its own settings save
    do_action('checkoutguard_before_settings_page_render');

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
    <div class="wrap checkoutguard-dashboard-wrap checkoutguard-settings-page">
        <h1>
            <span class="dashicons dashicons-admin-settings" style="font-size: 32px; width: 32px; height: 32px;"></span>
            <?php echo esc_html__('Settings', 'checkoutguard'); ?>
        </h1>
        <p>
            <?php echo esc_html__('Configure CheckoutGuard plugin features and options.', 'checkoutguard'); ?>
        </p>

        <div class="checkoutguard-dashboard-layout-container">
            <div class="checkoutguard-dashboard-layout-right" style="flex: 2;">
                <div class="checkoutguard-settings-content">
                    <!-- Settings Tabs Navigation -->
                    <div class="checkoutguard-settings-tabs">
                        <button type="button" class="checkoutguard-settings-tab active" data-tab="general">
                            <span class="dashicons dashicons-admin-generic"></span>
                            <?php esc_html_e('General', 'checkoutguard'); ?>
                        </button>
                        <button type="button" class="checkoutguard-settings-tab" data-tab="features">
                            <span class="dashicons dashicons-admin-plugins"></span>
                            <?php esc_html_e('Features', 'checkoutguard'); ?>
                        </button>
                        <button type="button" class="checkoutguard-settings-tab" data-tab="tracking">
                            <span class="dashicons dashicons-chart-line"></span>
                            <?php esc_html_e('Tracking', 'checkoutguard'); ?>
                        </button>
                        <button type="button" class="checkoutguard-settings-tab" data-tab="fraud">
                            <span class="dashicons dashicons-shield"></span>
                            <?php esc_html_e('Fraud Blocker', 'checkoutguard'); ?>
                        </button>
                        <?php if ($is_pro_active): ?>
                        <button type="button" class="checkoutguard-settings-tab" data-tab="pro-fraud">
                            <span class="dashicons dashicons-shield-alt"></span>
                            <?php esc_html_e('Pro Fraud Protection', 'checkoutguard'); ?>
                            <span class="cg-pro-badge" style="background: var(--checkoutguard-primary-color); color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">PRO</span>
                        </button>
                        <?php endif; ?>
                    </div>

                    <form method="post" action="options.php" id="checkoutguard-settings-form">
                        <?php settings_fields('checkoutguard_settings'); ?>
                        
                        <!-- Tab Content Panels -->
                        <div class="checkoutguard-settings-tab-content active" data-tab-content="general">
                            <div class="checkoutguard-settings-panel">
                                <?php do_settings_sections_for_tab('checkoutguard-settings', 'checkoutguard_general_section'); ?>
                            </div>
                        </div>

                        <div class="checkoutguard-settings-tab-content" data-tab-content="features">
                            <div class="checkoutguard-settings-panel">
                                <?php do_settings_sections_for_tab('checkoutguard-settings', 'checkoutguard_features_section'); ?>
                            </div>
                        </div>

                        <div class="checkoutguard-settings-tab-content" data-tab-content="tracking">
                            <div class="checkoutguard-settings-panel">
                                <?php do_settings_sections_for_tab('checkoutguard-settings', 'checkoutguard_tracking_section'); ?>
                            </div>
                        </div>

                        <div class="checkoutguard-settings-tab-content" data-tab-content="fraud">
                            <div class="checkoutguard-settings-panel">
                                <?php do_settings_sections_for_tab('checkoutguard-settings', 'checkoutguard_fraud_blocker_section'); ?>
                            </div>
                        </div>

                        <?php if ($is_pro_active): ?>
                        <div class="checkoutguard-settings-tab-content" data-tab-content="pro-fraud">
                            <div class="checkoutguard-settings-panel">
                                <?php do_action('checkoutguard_render_pro_settings_tab'); ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid var(--checkoutguard-card-border); display: flex; gap: 10px;">
                            <?php submit_button(esc_html__('Save Settings', 'checkoutguard'), 'primary', 'submit', false); ?>
                            <button type="button" class="button button-secondary" onclick="return confirm('<?php echo esc_js(__('Are you sure you want to reset all settings to default values?', 'checkoutguard')); ?>');">
                                <?php echo esc_html__('Reset to Defaults', 'checkoutguard'); ?>
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Add Diagnostics Section -->
                <?php if (function_exists('checkoutguard_render_diagnostics_section')) {
                    checkoutguard_render_diagnostics_section();
                } ?>
            </div>

            <div class="checkoutguard-dashboard-layout-left" style="flex: 1;">
                <div class="checkoutguard-blocker-section" style="margin-top: 0;">
                    <h3>
                        <span class="dashicons dashicons-info" style="color: var(--checkoutguard-primary-color);"></span>
                        <?php echo esc_html__('About CheckoutGuard', 'checkoutguard'); ?>
                    </h3>
                    <p style="color: var(--checkoutguard-text-secondary); line-height: 1.6;"><?php echo esc_html__('CheckoutGuard helps you track incomplete checkouts, prevent fraud, check courier reliability, and manage invoices efficiently.', 'checkoutguard'); ?></p>
                    <ul style="list-style: none; padding: 0; margin: 0; border-top: 1px solid var(--checkoutguard-card-border);">
                        <li style="padding: 10px 0; border-bottom: 1px solid var(--checkoutguard-card-border);">
                            <strong><?php echo esc_html__('Version:', 'checkoutguard'); ?></strong> <?php echo esc_html(CHECKOUTGUARD_VERSION); ?>
                        </li>
                        <li style="padding: 10px 0; border-bottom: 1px solid var(--checkoutguard-card-border);">
                            <strong><?php echo esc_html__('Developer:', 'checkoutguard'); ?></strong> Coder Zone BD
                        </li>
                        <li style="padding: 10px 0;">
                            <strong><?php echo esc_html__('Support:', 'checkoutguard'); ?></strong> <a href="mailto:support@coderzonebd.com">support@coderzonebd.com</a>
                        </li>
                        <li style="padding: 10px 0;">
                            <strong><?php echo esc_html__('Donate:', 'checkoutguard'); ?></strong> <a href="https://donate.coderzonebd.com" target="_blank">Donate us</a>
                        </li>
                    </ul>
                </div>

                <?php if (!CHECKOUTGUARD_IS_PRO): ?>
                <div class="checkoutguard-upgrade-section" style="margin-top: 20px; flex-direction: column; text-align: center; padding: 20px;">
                    <div class="checkoutguard-upgrade-icon">
                        <span class="dashicons dashicons-star-filled"></span>
                    </div>
                    <div class="checkoutguard-upgrade-text">
                        <h3><?php echo esc_html__('Upgrade to Pro', 'checkoutguard'); ?></h3>
                        <p><?php echo esc_html__('Get access to advanced features:', 'checkoutguard'); ?></p>
                        <ul style="text-align: left; margin: 15px 0; list-style: none; padding: 0;">
                            <li style="color:black; padding: 5px 0;">✓ <?php echo esc_html__('Advanced Fraud Detection', 'checkoutguard'); ?></li>
                            <li style="color:black; padding: 5px 0;">✓ <?php echo esc_html__('Automated Email Recovery', 'checkoutguard'); ?></li>
                            <li style="color:black; padding: 5px 0;">✓ <?php echo esc_html__('Detailed Analytics', 'checkoutguard'); ?></li>
                            <li style="color:black; padding: 5px 0;">✓ <?php echo esc_html__('Priority Support', 'checkoutguard'); ?></li>
                            <li style="color:black; padding: 5px 0;">✓ <?php echo esc_html__('Custom Branding Options', 'checkoutguard'); ?></li>
                        </ul>
                    </div>
                    <div class="checkoutguard-upgrade-actions" style="width: 100%;">
                        <a href="#" class="button button-primary" style="width: 100%;"><?php echo esc_html__('Upgrade Now', 'checkoutguard'); ?></a>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Branding Footer -->
        <div class="checkoutguard-powered-by">
            <span><?php esc_html_e('Powered by', 'checkoutguard'); ?> <a href="https://coderzonebd.com/" target="_blank" style="font-weight: bold; color: inherit; text-decoration: none;"><?php esc_html_e('Coder Zone BD', 'checkoutguard'); ?></a></span>
        </div>
    </div>

    <style>
    .checkoutguard-settings-tabs {
        display: flex;
        gap: 8px;
        margin-bottom: 0;
        border-bottom: 2px solid var(--checkoutguard-card-border);
        background: var(--checkoutguard-background-color);
        padding: 10px 20px 0;
        border-radius: var(--checkoutguard-radius) var(--checkoutguard-radius) 0 0;
    }

    .checkoutguard-settings-tab {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 12px 20px;
        background: transparent;
        border: none;
        border-bottom: 3px solid transparent;
        color: var(--checkoutguard-text-secondary);
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        position: relative;
        bottom: -2px;
    }

    .checkoutguard-settings-tab:hover {
        color: var(--checkoutguard-primary-color);
        background: rgba(102, 126, 234, 0.05);
    }

    .checkoutguard-settings-tab.active {
        color: var(--checkoutguard-primary-color);
        border-bottom-color: var(--checkoutguard-primary-color);
        background: var(--checkoutguard-card-background);
    }

    .checkoutguard-settings-tab .dashicons {
        font-size: 18px;
        width: 18px;
        height: 18px;
    }

    .checkoutguard-settings-tab-content {
        display: none;
        animation: fadeIn 0.3s ease-in;
    }

    .checkoutguard-settings-tab-content.active {
        display: block;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .checkoutguard-settings-panel {
        padding: 30px;
        background: var(--checkoutguard-card-background);
        border: 1px solid var(--checkoutguard-card-border);
        border-top: none;
        border-radius: 0 0 var(--checkoutguard-radius) var(--checkoutguard-radius);
    }

    .checkoutguard-settings-panel .form-table {
        margin-top: 0;
    }

    .checkoutguard-settings-panel h2 {
        display: none;
    }

    /* Toggle Switch Styles */
    .checkoutguard-toggle-field {
        display: flex;
        align-items: center;
        gap: 15px;
    }

    .checkoutguard-toggle-switch {
        position: relative;
        display: inline-block;
        width: 50px;
        height: 26px;
        flex-shrink: 0;
    }

    .checkoutguard-toggle-switch input[type="checkbox"] {
        opacity: 0;
        width: 0;
        height: 0;
    }

    .checkoutguard-toggle-slider {
        position: absolute;
        cursor: pointer;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: #ccc;
        transition: 0.4s;
        border-radius: 26px;
    }

    .checkoutguard-toggle-slider:before {
        position: absolute;
        content: "";
        height: 20px;
        width: 20px;
        left: 3px;
        bottom: 3px;
        background-color: white;
        transition: 0.4s;
        border-radius: 50%;
        box-shadow: 0 2px 4px rgba(0,0,0,0.2);
    }

    .checkoutguard-toggle-switch input:checked + .checkoutguard-toggle-slider {
        background-color: var(--checkoutguard-primary-color);
    }

    .checkoutguard-toggle-switch input:checked + .checkoutguard-toggle-slider:before {
        transform: translateX(24px);
    }

    .checkoutguard-toggle-switch input:focus + .checkoutguard-toggle-slider {
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.2);
    }

    .checkoutguard-toggle-label {
        color: var(--checkoutguard-text-primary);
        font-size: 14px;
        font-weight: 500;
    }

    .checkoutguard-settings-panel .form-table th {
        font-weight: 600;
        padding: 15px 0;
    }

    .checkoutguard-settings-panel .form-table td {
        padding: 15px 0;
    }
    </style>

    <script>
    jQuery(document).ready(function($) {
        // Tab switching
        $('.checkoutguard-settings-tab').on('click', function() {
            var tabName = $(this).data('tab');
            
            // Update tab buttons
            $('.checkoutguard-settings-tab').removeClass('active');
            $(this).addClass('active');
            
            // Update tab content
            $('.checkoutguard-settings-tab-content').removeClass('active');
            $('.checkoutguard-settings-tab-content[data-tab-content="' + tabName + '"]').addClass('active');
        });
    });
    </script>
    <?php
}

/**
 * Helper function to render settings sections for a specific tab
 */
function do_settings_sections_for_tab($page, $section_id) {
    global $wp_settings_sections, $wp_settings_fields;

    if (!isset($wp_settings_sections[$page])) {
        return;
    }

    foreach ((array) $wp_settings_sections[$page] as $section) {
        if ($section['id'] !== $section_id) {
            continue;
        }

        if ($section['title']) {
            echo "<h2>{$section['title']}</h2>\n";
        }

        if ($section['callback']) {
            call_user_func($section['callback'], $section);
        }

        if (!isset($wp_settings_fields) || !isset($wp_settings_fields[$page]) || !isset($wp_settings_fields[$page][$section['id']])) {
            continue;
        }

        echo '<table class="form-table" role="presentation">';
        do_settings_fields($page, $section['id']);
        echo '</table>';
    }
}
