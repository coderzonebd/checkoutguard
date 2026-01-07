<?php
/**
 * CheckoutGuard - Unified Fraud Protection Page
 * 
 * Works for both free and pro versions
 * Shows phone blocking in free, all features in pro
 */

if (!defined('ABSPATH'))
    exit;

function checkoutguard_render_fraud_protection_page()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'checkoutguard'));
    }

    $is_pro_active = defined('CHECKOUTGUARD_PRO_VERSION');
    $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'phone';

    // If pro is not active, force phone tab
    if (!$is_pro_active && $active_tab !== 'phone') {
        $active_tab = 'phone';
    }

    ?>
    <div class="wrap checkoutguard-fraud-protection-wrap">
        <h1 class="cg-page-header-modern">
            <span class="dashicons dashicons-shield" style="font-size: 32px; margin-right: 10px;"></span>
            <?php esc_html_e('Fraud Protection', 'checkoutguard'); ?>
            <?php if ($is_pro_active): ?>
                <span class="cg-pro-badge"><?php esc_html_e('PRO', 'checkoutguard'); ?></span>
            <?php endif; ?>
        </h1>

        <!-- Tab Navigation -->
        <h2 class="nav-tab-wrapper">
            <a href="?page=checkoutguard-fraud-protection&tab=phone" 
               class="nav-tab <?php echo $active_tab === 'phone' ? 'nav-tab-active' : ''; ?>">
                <?php esc_html_e('Phone Blocking', 'checkoutguard'); ?>
            </a>

            <?php if ($is_pro_active): ?>
                <a href="?page=checkoutguard-fraud-protection&tab=dashboard" 
                   class="nav-tab <?php echo $active_tab === 'dashboard' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Dashboard', 'checkoutguard'); ?>
                </a>
                <a href="?page=checkoutguard-fraud-protection&tab=email" 
                   class="nav-tab <?php echo $active_tab === 'email' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Email Blocking', 'checkoutguard'); ?>
                </a>
                <a href="?page=checkoutguard-fraud-protection&tab=ip" 
                   class="nav-tab <?php echo $active_tab === 'ip' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('IP Blocking', 'checkoutguard'); ?>
                </a>
                <a href="?page=checkoutguard-fraud-protection&tab=country" 
                   class="nav-tab <?php echo $active_tab === 'country' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Country Blocking', 'checkoutguard'); ?>
                </a>
                <a href="?page=checkoutguard-fraud-protection&tab=risk" 
                   class="nav-tab <?php echo $active_tab === 'risk' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Risk Analysis', 'checkoutguard'); ?>
                </a>
                <a href="?page=checkoutguard-fraud-protection&tab=velocity" 
                   class="nav-tab <?php echo $active_tab === 'velocity' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Velocity Logs', 'checkoutguard'); ?>
                </a>
            <?php else: ?>
                <!-- Pro Feature Tabs (locked) -->
                <a href="javascript:void(0)" class="nav-tab nav-tab-locked" onclick="alert('<?php echo esc_js(__('This feature is available in CheckoutGuard Pro. Upgrade to unlock advanced fraud protection features!', 'checkoutguard')); ?>')">
                    <?php esc_html_e('Dashboard', 'checkoutguard'); ?> 🔒
                </a>
                <a href="javascript:void(0)" class="nav-tab nav-tab-locked" onclick="alert('<?php echo esc_js(__('This feature is available in CheckoutGuard Pro. Upgrade to unlock email blocking!', 'checkoutguard')); ?>')">
                    <?php esc_html_e('Email Blocking', 'checkoutguard'); ?> 🔒
                </a>
                <a href="javascript:void(0)" class="nav-tab nav-tab-locked" onclick="alert('<?php echo esc_js(__('This feature is available in CheckoutGuard Pro. Upgrade to unlock IP blocking!', 'checkoutguard')); ?>')">
                    <?php esc_html_e('IP Blocking', 'checkoutguard'); ?> 🔒
                </a>
                <a href="javascript:void(0)" class="nav-tab nav-tab-locked" onclick="alert('<?php echo esc_js(__('This feature is available in CheckoutGuard Pro. Upgrade to unlock country blocking!', 'checkoutguard')); ?>')">
                    <?php esc_html_e('Country Blocking', 'checkoutguard'); ?> 🔒
                </a>
                <a href="javascript:void(0)" class="nav-tab nav-tab-locked" onclick="alert('<?php echo esc_js(__('This feature is available in CheckoutGuard Pro. Upgrade to unlock risk analysis!', 'checkoutguard')); ?>')">
                    <?php esc_html_e('Risk Analysis', 'checkoutguard'); ?> 🔒
                </a>
                <a href="javascript:void(0)" class="nav-tab nav-tab-locked" onclick="alert('<?php echo esc_js(__('This feature is available in CheckoutGuard Pro. Upgrade to unlock velocity tracking!', 'checkoutguard')); ?>')">
                    <?php esc_html_e('Velocity Logs', 'checkoutguard'); ?> 🔒
                </a>
            <?php endif; ?>
        </h2>

        <div class="cg-tab-content" style="margin-top: 20px;">
            <?php
            if ($is_pro_active) {
                // Pro version - show all tabs
                switch ($active_tab) {
                    case 'dashboard':
                        if (function_exists('checkoutguard_pro_render_fraud_dashboard_tab')) {
                            checkoutguard_pro_render_fraud_dashboard_tab();
                        }
                        break;
                    case 'phone':
                        checkoutguard_render_phone_blocking_tab();
                        break;
                    case 'email':
                        if (function_exists('checkoutguard_pro_render_email_blocking_tab')) {
                            checkoutguard_pro_render_email_blocking_tab();
                        }
                        break;
                    case 'ip':
                        if (function_exists('checkoutguard_pro_render_ip_blocking_tab')) {
                            checkoutguard_pro_render_ip_blocking_tab();
                        }
                        break;
                    case 'country':
                        if (function_exists('checkoutguard_pro_render_country_blocking_tab')) {
                            checkoutguard_pro_render_country_blocking_tab();
                        }
                        break;
                    case 'risk':
                        if (function_exists('checkoutguard_pro_render_risk_analysis_tab')) {
                            checkoutguard_pro_render_risk_analysis_tab();
                        }
                        break;
                    case 'velocity':
                        if (function_exists('checkoutguard_pro_render_velocity_logs_tab')) {
                            checkoutguard_pro_render_velocity_logs_tab();
                        }
                        break;
                }
            } else {
                // Free version - only phone tab
                checkoutguard_render_phone_blocking_tab();
            }
            ?>
        </div>

        <!-- Branding Footer -->
        <div class="cg-branding-footer">
            <p><?php esc_html_e('Powered by', 'checkoutguard'); ?> <a href="https://coderzonebd.com/" target="_blank">Coder Zone BD</a></p>
        </div>
    </div>

    <style>
        .cg-page-header-modern {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px 30px;
            border-radius: 8px;
            margin: 20px 0;
            display: flex;
            align-items: center;
        }
        .cg-pro-badge {
            background: rgba(255, 255, 255, 0.3);
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 14px;
            margin-left: 15px;
            font-weight: 600;
        }
        .nav-tab-locked {
            opacity: 0.6;
            cursor: help;
        }
        .nav-tab-locked:hover {
            opacity: 0.8;
        }
        .cg-branding-footer {
            margin-top: 30px;
            padding: 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-align: center;
            border-radius: 8px;
        }
        .cg-branding-footer a {
            color: white;
            text-decoration: underline;
            font-weight: 600;
        }
    </style>
    <?php
}

/**
 * Phone Blocking Tab (Available in both free and pro)
 */
function checkoutguard_render_phone_blocking_tab()
{
    $ajax_nonce = wp_create_nonce('checkoutguard_fraud_blocker_nonce');
    $is_pro_active = defined('CHECKOUTGUARD_PRO_VERSION');
    ?>
    
    <div class="checkoutguard-blocker-section">
        <?php if (!$is_pro_active): ?>
            <div class="notice notice-info" style="margin-top: 0;">
                <p>
                    <strong><?php esc_html_e('Upgrade to Pro for Advanced Fraud Protection!', 'checkoutguard'); ?></strong><br>
                    <?php esc_html_e('Block emails, IP addresses, countries, detect VPNs, analyze risk scores, and more!', 'checkoutguard'); ?>
                </p>
            </div>
        <?php endif; ?>

        <div id="checkoutguard-blocker-messages" style="display:none;" class="notice is-dismissible"></div>

        <div class="checkoutguard-fraud-card">
            <h2><?php esc_html_e('Phone Number Blocker', 'checkoutguard'); ?></h2>
            <p><?php esc_html_e('Block specific phone numbers to prevent unwanted orders. There is no limit to the number of phone numbers you can block.', 'checkoutguard'); ?></p>
            
            <form class="checkoutguard-blocker-form" data-block-type="phone">
                <input type="hidden" name="nonce" value="<?php echo esc_attr($ajax_nonce); ?>">
                <p>
                    <label for="checkoutguard_block_phone"><?php esc_html_e('Phone Number to Block:', 'checkoutguard'); ?></label>
                    <input type="text" name="value" class="regular-text" placeholder="e.g., 01712345678" style="width: 100%; max-width: 400px;">
                </p>
                <p>
                    <label for="checkoutguard_block_phone_reason"><?php esc_html_e('Reason (Optional):', 'checkoutguard'); ?></label>
                    <textarea name="reason" rows="2" class="large-text" style="width: 100%; max-width: 400px;"></textarea>
                </p>
                <p>
                    <button type="submit" class="button button-primary">
                        <?php esc_html_e('Block Phone Number', 'checkoutguard'); ?>
                    </button>
                    <span class="spinner"></span>
                </p>
            </form>
            
            <div class="checkoutguard-search-wrapper" style="margin-top: 30px; max-width: 400px;">
                <input type="text" id="checkoutguard-blocker-search" class="checkoutguard-blocker-search" placeholder="<?php esc_attr_e('Search blocked numbers...', 'checkoutguard'); ?>">
            </div>

            <h3 style="margin-top:20px;"><?php esc_html_e('Blocked Phone Numbers', 'checkoutguard'); ?></h3>
            <?php checkoutguard_display_blocked_items_list('phone'); ?>
        </div>
    </div>

    <style>
        .checkoutguard-fraud-card {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
    </style>
    <?php
}
