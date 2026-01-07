<?php
// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renders the Checkout Field Manager page.
 */
function checkoutguard_render_field_manager_page()
{
    global $checkoutguard_field_manager;
    
    if ( ! $checkoutguard_field_manager ) {
        echo '<div class="wrap"><h1>' . esc_html__( 'Field Manager not initialized', 'checkoutguard' ) . '</h1></div>';
        return;
    }
    
    $settings = $checkoutguard_field_manager->get_settings();
    $fields = $checkoutguard_field_manager->get_available_fields();
    $stats = $checkoutguard_field_manager->get_statistics();

    ?>
    <div class="wrap checkoutguard-dashboard-wrap">
        <!-- Modern Page Header -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <div>
                <h1>
                    <span class="dashicons dashicons-forms" style="font-size: 32px; width: 32px; height: 32px;"></span>
                    <?php esc_html_e('Checkout Field Manager', 'checkoutguard'); ?>
                </h1>
                <p>
                    <?php esc_html_e('Simplify your checkout form to reduce cart abandonment', 'checkoutguard'); ?>
                </p>
            </div>
            <div class="checkoutguard-header-actions" style="display: flex; align-items: center;">
                <label class="checkoutguard-toggle-switch">
                    <input type="checkbox" id="field-manager-toggle" <?php checked( $settings['enabled'] ); ?>>
                    <span class="checkoutguard-toggle-slider"></span>
                </label>
                <span style="margin-left: 10px; font-weight: 600;">
                    <?php echo $settings['enabled'] ? esc_html__( 'Enabled', 'checkoutguard' ) : esc_html__( 'Disabled', 'checkoutguard' ); ?>
                </span>
            </div>
        </div>

        <!-- Stats -->
        <div class="checkoutguard-stat-row">
            <div class="checkoutguard-stat-box stat-primary">
                <h3><?php esc_html_e('Active Fields', 'checkoutguard'); ?></h3>
                <p><?php echo esc_html( $stats['active_fields'] ); ?></p>
                <div class="checkoutguard-stat-subtext"><?php echo esc_html( sprintf( __( 'Out of %d total', 'checkoutguard' ), $stats['total_fields'] ) ); ?></div>
            </div>

            <div class="checkoutguard-stat-box stat-cancelled">
                <h3><?php esc_html_e('Hidden Fields', 'checkoutguard'); ?></h3>
                <p><?php echo esc_html( $stats['hidden_fields'] ); ?></p>
                <div class="checkoutguard-stat-subtext"><?php echo esc_html( sprintf( __( '%s%% reduction', 'checkoutguard' ), $stats['reduction_percentage'] ) ); ?></div>
            </div>

            <div class="checkoutguard-stat-box stat-hold">
                <h3><?php esc_html_e('Optional Fields', 'checkoutguard'); ?></h3>
                <p><?php echo esc_html( $stats['optional_fields'] ); ?></p>
                <div class="checkoutguard-stat-subtext"><?php esc_html_e('Made non-required', 'checkoutguard'); ?></div>
            </div>

            <div class="checkoutguard-stat-box stat-recovered">
                <h3><?php esc_html_e('Estimated Impact', 'checkoutguard'); ?></h3>
                <p style="font-size: 24px;">
                    <?php 
                    $impact = max( 5, $stats['hidden_fields'] * 3 + $stats['optional_fields'] * 2 );
                    echo esc_html( '+' . min( 25, $impact ) . '%' ); 
                    ?>
                </p>
                <div class="checkoutguard-stat-subtext"><?php esc_html_e('Conversion boost', 'checkoutguard'); ?></div>
            </div>
        </div>

        <!-- Field Manager Form -->
        <form method="post" action="" id="checkoutguard-field-manager-form">
            <?php wp_nonce_field( 'checkoutguard_save_fields', 'checkoutguard_field_nonce' ); ?>
            <input type="hidden" name="checkoutguard_field_manager_enabled" id="field_manager_enabled" value="<?php echo $settings['enabled'] ? '1' : '0'; ?>">
            
            <div class="checkoutguard-table-responsive-wrapper" style="padding: 20px; margin-top: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid var(--checkoutguard-card-border); padding-bottom: 15px;">
                    <h2 style="margin: 0; font-size: 18px;"><?php esc_html_e('Customize Checkout Fields', 'checkoutguard'); ?></h2>
                    <div style="display: flex; gap: 10px;">
                        <button type="button" class="button button-secondary" id="preview-checkout">
                            <span class="dashicons dashicons-visibility"></span> <?php esc_html_e('Preview Checkout', 'checkoutguard'); ?>
                        </button>
                        <button type="submit" class="button button-primary">
                            <span class="dashicons dashicons-saved"></span> <?php esc_html_e('Save Changes', 'checkoutguard'); ?>
                        </button>
                    </div>
                </div>

                <!-- Pro Enhancement Hook (for drag-drop notice) -->
                <?php do_action('checkoutguard_field_manager_enhancements'); ?>

                <!-- Tabs Navigation -->
                <div class="checkoutguard-tabs-nav">
                    <button type="button" class="checkoutguard-tab-button active" data-tab="billing">
                        <span class="dashicons dashicons-id-alt"></span>
                        <?php esc_html_e('Billing Fields', 'checkoutguard'); ?>
                        <span class="checkoutguard-tab-count"><?php echo count($fields['billing']); ?></span>
                    </button>
                    <button type="button" class="checkoutguard-tab-button" data-tab="shipping">
                        <span class="dashicons dashicons-location"></span>
                        <?php esc_html_e('Shipping Fields', 'checkoutguard'); ?>
                        <span class="checkoutguard-tab-count"><?php echo count($fields['shipping']); ?></span>
                    </button>
                    <button type="button" class="checkoutguard-tab-button" data-tab="order">
                        <span class="dashicons dashicons-clipboard"></span>
                        <?php esc_html_e('Order Fields', 'checkoutguard'); ?>
                        <span class="checkoutguard-tab-count"><?php echo count($fields['order']); ?></span>
                    </button>
                </div>

                <!-- Tab Contents -->
                <?php foreach ( $fields as $section => $section_fields ) : ?>
                <div class="checkoutguard-tab-content <?php echo $section === 'billing' ? 'active' : ''; ?>" data-tab-content="<?php echo esc_attr($section); ?>">
                    <div class="checkoutguard-field-manager-grid">
                        <?php foreach ( $section_fields as $key => $field ) : 
                            $full_key = $key;
                            $is_hidden = in_array( $full_key, $settings['hidden_fields'] );
                            $is_optional = in_array( $full_key, $settings['optional_fields'] );
                            $is_required = isset($field['default_required']) && $field['default_required'];
                            $custom_label = isset($settings['custom_labels'][$full_key]) ? $settings['custom_labels'][$full_key] : '';
                            $is_pro = defined('CHECKOUTGUARD_IS_PRO') && CHECKOUTGUARD_IS_PRO;
                            ?>
                            <div class="checkoutguard-field-item <?php echo $is_hidden ? 'field-hidden' : ''; ?>" data-field="<?php echo esc_attr($full_key); ?>">
                                <div class="checkoutguard-field-header">
                                    <span class="checkoutguard-field-name"><?php echo esc_html( $field['label'] ); ?></span>
                                    <?php if ( $is_required ) : ?>
                                        <span class="checkoutguard-field-badge checkoutguard-badge-required"><?php esc_html_e('Required', 'checkoutguard'); ?></span>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="checkoutguard-field-code"><?php echo esc_html( $full_key ); ?></div>
                                
                                <?php if ( $is_pro ) : ?>
                                    <div class="checkoutguard-field-label-editor">
                                        <label>
                                            <span class="dashicons dashicons-edit" style="color: var(--checkoutguard-primary-color);"></span>
                                            <strong style="color: var(--checkoutguard-primary-color);"><?php esc_html_e('Custom Label (Pro):', 'checkoutguard'); ?></strong>
                                        </label>
                                        <input type="text" 
                                            name="custom_labels[<?php echo esc_attr($full_key); ?>]" 
                                            value="<?php echo esc_attr($custom_label); ?>" 
                                            placeholder="<?php echo esc_attr($field['label']); ?>"
                                            class="checkoutguard-custom-label-input"
                                            <?php disabled( $is_hidden ); ?>>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="checkoutguard-field-actions">
                                    <div class="checkoutguard-field-toggles">
                                        <div class="checkoutguard-toggle-item">
                                            <label class="checkoutguard-toggle-switch-small">
                                                <input type="checkbox" name="hidden_fields[]" value="<?php echo esc_attr( $full_key ); ?>" class="field-hidden-checkbox" <?php checked( $is_hidden ); ?>>
                                                <span class="checkoutguard-toggle-slider-small"></span>
                                            </label>
                                            <span class="checkoutguard-toggle-label"><?php esc_html_e('Hide', 'checkoutguard'); ?></span>
                                        </div>
                                        
                                        <?php if ( $is_required ) : ?>
                                            <div class="checkoutguard-toggle-item">
                                                <label class="checkoutguard-toggle-switch-small">
                                                    <input type="checkbox" name="optional_fields[]" value="<?php echo esc_attr( $full_key ); ?>" class="field-optional-checkbox" <?php checked( $is_optional ); ?> <?php disabled( $is_hidden ); ?>>
                                                    <span class="checkoutguard-toggle-slider-small"></span>
                                                </label>
                                                <span class="checkoutguard-toggle-label"><?php esc_html_e('Optional', 'checkoutguard'); ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <p class="submit" style="text-align: right; margin-top: 20px;">
                <button type="submit" class="button button-primary button-large">
                    <?php esc_html_e('Save Changes', 'checkoutguard'); ?>
                </button>
            </p>
        </form>
    </div>

    <style>
        .checkoutguard-toggle-switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
        }
        .checkoutguard-toggle-switch input {
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
            transition: .4s;
            border-radius: 24px;
        }
        .checkoutguard-toggle-slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        input:checked + .checkoutguard-toggle-slider {
            background-color: var(--checkoutguard-primary-color);
        }
        input:checked + .checkoutguard-toggle-slider:before {
            transform: translateX(26px);
        }
        
        /* Tabs Navigation */
        .checkoutguard-tabs-nav {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid var(--checkoutguard-card-border);
            padding-bottom: 0;
        }
        .checkoutguard-tab-button {
            background: transparent;
            border: none;
            border-bottom: 3px solid transparent;
            padding: 12px 24px;
            font-size: 14px;
            font-weight: 600;
            color: var(--checkoutguard-text-secondary);
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            position: relative;
            bottom: -2px;
        }
        .checkoutguard-tab-button:hover {
            color: var(--checkoutguard-primary-color);
            background: var(--checkoutguard-hover-bg);
        }
        .checkoutguard-tab-button.active {
            color: var(--checkoutguard-primary-color);
            border-bottom-color: var(--checkoutguard-primary-color);
        }
        .checkoutguard-tab-button .dashicons {
            font-size: 18px;
            width: 18px;
            height: 18px;
        }
        .checkoutguard-tab-count {
            background: var(--checkoutguard-primary-light);
            color: var(--checkoutguard-primary-color);
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
        }
        .checkoutguard-tab-button.active .checkoutguard-tab-count {
            background: var(--checkoutguard-primary-color);
            color: white;
        }
        
        /* Tab Content */
        .checkoutguard-tab-content {
            display: none;
            animation: fadeIn 0.3s ease-in;
        }
        .checkoutguard-tab-content.active {
            display: block;
        }
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .checkoutguard-field-manager-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 15px;
        }
        .checkoutguard-field-item {
            background: var(--checkoutguard-card-background);
            border: 1px solid var(--checkoutguard-card-border);
            border-radius: var(--checkoutguard-radius);
            padding: 15px;
            transition: var(--checkoutguard-transition);
        }
        .checkoutguard-field-item:hover {
            border-color: var(--checkoutguard-primary-color);
            box-shadow: var(--checkoutguard-shadow-md);
        }
        .checkoutguard-field-item.field-hidden {
            opacity: 0.6;
            border-color: var(--checkoutguard-danger-color);
            background: var(--checkoutguard-background-color);
        }
        .checkoutguard-field-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }
        .checkoutguard-field-name {
            font-weight: 600;
            font-size: 14px;
            color: var(--checkoutguard-text-primary);
        }
        .checkoutguard-field-badge {
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .checkoutguard-badge-required {
            background: var(--checkoutguard-danger-color);
            color: white;
        }
        .checkoutguard-field-code {
            font-family: monospace;
            font-size: 12px;
            color: var(--checkoutguard-text-secondary);
            background: var(--checkoutguard-background-color);
            padding: 4px 8px;
            border-radius: 3px;
            margin-bottom: 12px;
            border: 1px solid var(--checkoutguard-card-border);
        }
        .checkoutguard-field-actions {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .checkoutguard-field-toggles {
            display: flex;
            align-items: center;
            gap: 20px;
            padding: 10px 0;
        }
        .checkoutguard-toggle-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .checkoutguard-toggle-label {
            font-size: 13px;
            font-weight: 500;
            color: var(--checkoutguard-text-secondary);
        }
        .checkoutguard-toggle-switch-small {
            position: relative;
            display: inline-block;
            width: 42px;
            height: 22px;
        }
        .checkoutguard-toggle-switch-small input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .checkoutguard-toggle-slider-small {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .3s;
            border-radius: 22px;
        }
        .checkoutguard-toggle-slider-small:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .3s;
            border-radius: 50%;
        }
        input:checked + .checkoutguard-toggle-slider-small {
            background-color: var(--checkoutguard-primary-color);
        }
        input:checked + .checkoutguard-toggle-slider-small:before {
            transform: translateX(20px);
        }
        input:disabled + .checkoutguard-toggle-slider-small {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .checkoutguard-checkbox-label {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            font-size: 13px;
            color: var(--checkoutguard-text-secondary);
        }
        .checkoutguard-checkbox-label input[type="checkbox"] {
            margin: 0;
        }
        .checkoutguard-checkbox-label:hover {
            color: var(--checkoutguard-primary-color);
        }
        .checkoutguard-field-label-editor {
            margin: 12px 0;
            padding: 10px;
            background: linear-gradient(135deg, #667eea15 0%, #764ba215 100%);
            border: 1px dashed var(--checkoutguard-primary-color);
            border-radius: 4px;
        }
        .checkoutguard-field-label-editor label {
            display: flex;
            align-items: center;
            gap: 5px;
            margin-bottom: 6px;
            font-size: 12px;
        }
        .checkoutguard-custom-label-input {
            width: 100%;
            padding: 6px 10px;
            border: 1px solid var(--checkoutguard-card-border);
            border-radius: 4px;
            font-size: 13px;
        }
        .checkoutguard-custom-label-input:focus {
            outline: none;
            border-color: var(--checkoutguard-primary-color);
            box-shadow: 0 0 0 2px rgba(102, 126, 234, 0.1);
        }
        .checkoutguard-custom-label-input:disabled {
            background: #f5f5f5;
            cursor: not-allowed;
        }
    </style>

    <script>
    jQuery(document).ready(function($) {
        // Disable optional toggles and label inputs on load for already hidden fields
        $('.checkoutguard-field-item.field-hidden').each(function() {
            $(this).find('.field-optional-checkbox').prop('checked', false).prop('disabled', true);
            $(this).find('.checkoutguard-custom-label-input').prop('disabled', true);
        });

        // Toggle field manager
        $('#field-manager-toggle').on('change', function() {
            var enabled = $(this).is(':checked');
            $('#field_manager_enabled').val(enabled ? '1' : '0');
        });

        // Tab switching
        $('.checkoutguard-tab-button').on('click', function() {
            var tabName = $(this).data('tab');
            
            // Update active states
            $('.checkoutguard-tab-button').removeClass('active');
            $(this).addClass('active');
            
            // Show corresponding content
            $('.checkoutguard-tab-content').removeClass('active');
            $('.checkoutguard-tab-content[data-tab-content="' + tabName + '"]').addClass('active');
        });

        // When hiding a field, uncheck optional and disable label input
        $('.field-hidden-checkbox').on('change', function() {
            var $item = $(this).closest('.checkoutguard-field-item');
            var $optional = $item.find('.field-optional-checkbox');
            var $labelInput = $item.find('.checkoutguard-custom-label-input');
            
            if ($(this).is(':checked')) {
                $item.addClass('field-hidden');
                $optional.prop('checked', false).prop('disabled', true);
                $labelInput.prop('disabled', true);
            } else {
                $item.removeClass('field-hidden');
                $optional.prop('disabled', false);
                $labelInput.prop('disabled', false);
            }
        });

        // Preview checkout
        $('#preview-checkout').on('click', function() {
            window.open('<?php echo esc_url( wc_get_checkout_url() ); ?>', '_blank');
        });
    });
    </script>
    <?php
}
