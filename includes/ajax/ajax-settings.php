<?php
// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}


/**
 * AJAX handler for saving partial page settings.
 * Merges submitted fields into existing cg_plugin_options without overwriting others.
 */
function cg_handle_save_page_settings()
{
    check_ajax_referer('cg_save_page_settings_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Permission denied.', 'checkoutguard')]);
    }

    $options = get_option('cg_plugin_options', []);
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- individual values sanitized in switch below
    $fields = isset($_POST['fields']) ? wp_unslash($_POST['fields']) : [];

    // Whitelist of allowed fields to update via this handler
    $allowed = [
        'custom_block_message',
        'ratio_blocker_threshold', 'ratio_blocker_grace_period', 'ratio_blocker_message',
        'fraud_auto_block_enabled', 'fraud_auto_block_attempts', 'fraud_auto_block_window', 'fraud_auto_block_duration',
        'fraud_velocity_enabled', 'fraud_velocity_max_attempts', 'fraud_velocity_window',
        'fraud_device_blocking', 'fraud_device_site_block',
        'fraud_risk_scoring_enabled', 'fraud_risk_score_threshold',
        'duplicate_blocker_enabled', 'duplicate_blocker_window', 'duplicate_blocker_mode',
        'duplicate_blocker_match_phone', 'duplicate_blocker_match_device', 'duplicate_blocker_match_ip', 'duplicate_blocker_match_email', 'duplicate_blocker_message',
    ];

    foreach ($fields as $key => $value) {
        if (!in_array($key, $allowed)) {
            continue;
        }
        switch ($key) {
            case 'ratio_blocker_threshold':
            case 'ratio_blocker_grace_period':
            case 'fraud_auto_block_attempts':
            case 'fraud_auto_block_window':
            case 'fraud_auto_block_duration':
            case 'fraud_velocity_max_attempts':
            case 'fraud_velocity_window':
            case 'fraud_risk_score_threshold':
            case 'duplicate_blocker_window':
                $options[$key] = absint($value);
                break;
            case 'fraud_auto_block_enabled':
            case 'fraud_velocity_enabled':
            case 'fraud_device_blocking':
            case 'fraud_device_site_block':
            case 'fraud_risk_scoring_enabled':
            case 'duplicate_blocker_enabled':
            case 'duplicate_blocker_match_phone':
            case 'duplicate_blocker_match_device':
            case 'duplicate_blocker_match_ip':
            case 'duplicate_blocker_match_email':
                $options[$key] = !empty($value) && $value !== '0' && $value !== 'false';
                break;
            case 'duplicate_blocker_mode':
                $options[$key] = in_array($value, ['identical', 'any'], true) ? $value : 'identical';
                break;
            case 'duplicate_blocker_message':
                // Custom duplicate block message requires an active Pro plan
                if (function_exists('cg_is_pro_plan') && cg_is_pro_plan()) {
                    $options[$key] = sanitize_textarea_field($value);
                }
                break;
            default:
                $options[$key] = sanitize_textarea_field($value);
        }
    }

    update_option('cg_plugin_options', $options);
    wp_send_json_success(['message' => __('Settings saved successfully.', 'checkoutguard')]);
}

