<?php
// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}



/**
 * Renders the HTML for the lockdown overlay with a dynamic message.
 */
function cg_render_lockdown_overlay($message)
{
    ?>
    <div class="cg-lockdown-overlay">
        <div class="cg-lockdown-message">
            <h2><?php _e('CheckoutGuard', 'checkoutguard'); ?></h2>
            <p><?php echo wp_kses_post($message); ?></p>
        </div>
    </div>
    <?php
}


/**
 * Renders the admin notice for an expired license.
 */
function cg_render_expired_notice()
{
    ?>
    <div class="notice notice-warning is-dismissible">
        <p><strong>CheckoutGuard:</strong> Your license has expired. Your feature limits have been reverted to
            the Free plan. Please renew your license to restore your premium features.</p>
    </div>
    <?php
}


/**
 * Renders the common date filter controls.
 */
function cg_render_date_filter_controls($page_slug_prefix, $default_active_range = 'today', $filter_target_field = 'updated_at', $include_all_button = false, $is_follow_up = false)
{
    $filter_id_prefix = 'cg_' . $page_slug_prefix;
    $is_dashboard = ($page_slug_prefix === 'dashboard');
    ?>
    <div class="cg-dashboard-filters cg-list-page-filters <?php echo esc_attr($filter_id_prefix . '-filters'); ?>"
        data-page-prefix="<?php echo esc_attr($page_slug_prefix); ?>">
        <div class="cg-filter-buttons">
            <?php if ($is_follow_up): ?>
                <button class="button <?php echo esc_attr($filter_id_prefix . '-filter-btn'); ?> active"
                    data-range="today"><?php _e('Due Today', 'checkoutguard'); ?></button>
                <button class="button <?php echo esc_attr($filter_id_prefix . '-filter-btn'); ?>"
                    data-range="next7days"><?php _e('Next 7 Days', 'checkoutguard'); ?></button>
                <button class="button <?php echo esc_attr($filter_id_prefix . '-filter-btn'); ?>"
                    data-range="next30days"><?php _e('Next 30 Days', 'checkoutguard'); ?></button>
            <?php else: ?>
                <button class="button <?php echo esc_attr($filter_id_prefix . '-filter-btn'); ?> active"
                    data-range="today"><?php _e('Today', 'checkoutguard'); ?></button>
                <button class="button <?php echo esc_attr($filter_id_prefix . '-filter-btn'); ?>"
                    data-range="yesterday"><?php _e('Yesterday', 'checkoutguard'); ?></button>
                <button class="button <?php echo esc_attr($filter_id_prefix . '-filter-btn'); ?>"
                    data-range="7days"><?php _e('Last 7 Days', 'checkoutguard'); ?></button>
                <button class="button <?php echo esc_attr($filter_id_prefix . '-filter-btn'); ?>"
                    data-range="30days"><?php _e('Last 30 Days', 'checkoutguard'); ?></button>
            <?php endif; ?>

            <?php if ($include_all_button): ?>
                <button class="button <?php echo esc_attr($filter_id_prefix . '-filter-btn'); ?>"
                    data-range="all"><?php _e('All', 'checkoutguard'); ?></button>
            <?php endif; ?>
        </div>
        <div class="cg-date-range-filter">
            <label
                for="<?php echo esc_attr($filter_id_prefix . '_start_date'); ?>"><?php _e('From:', 'checkoutguard'); ?></label>
            <input type="date" id="<?php echo esc_attr($filter_id_prefix . '_start_date'); ?>"
                class="<?php echo esc_attr($filter_id_prefix . '-date-input'); ?>">
            <label
                for="<?php echo esc_attr($filter_id_prefix . '_end_date'); ?>"><?php _e('To:', 'checkoutguard'); ?></label>
            <input type="date" id="<?php echo esc_attr($filter_id_prefix . '_end_date'); ?>"
                class="<?php echo esc_attr($filter_id_prefix . '-date-input'); ?>">
            <button
                class="button button-primary <?php echo esc_attr($filter_id_prefix . '_apply_date_filter'); ?>"><?php _e('Filter', 'checkoutguard'); ?></button>
        </div>
        <?php if (!$is_dashboard): ?>
            <div class="cg-search-filter">
                <input type="search" id="<?php echo esc_attr($filter_id_prefix . '_search'); ?>"
                    class="cg-table-search-input"
                    data-page-prefix="<?php echo esc_attr($page_slug_prefix); ?>"
                    placeholder="<?php esc_attr_e('Search by name, email, phone, IP...', 'checkoutguard'); ?>">
            </div>
        <?php endif; ?>
    </div>
    <?php
}



/**
 * Renders the common HTML structure for the details modal.
 */
function cg_render_details_modal_html()
{
    ?>
    <div id="cg-details-modal" class="cg-modal" style="display:none;" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e('Details', 'checkoutguard'); ?>">
        <div class="cg-modal-content">
            <button type="button" class="cg-modal-close" aria-label="<?php esc_attr_e('Close', 'checkoutguard'); ?>">&times;</button>
            <div id="cg-modal-body">
                <p><?php _e('Loading details...', 'checkoutguard'); ?></p>
            </div>
        </div>
    </div>
    <?php
}


/**
 * Guarantees the "Upgrade to Pro" modal is present on every screen that can
 * show a .cg-open-upgrade-modal trigger. Pages used to render it themselves,
 * so any page carrying an upgrade button but missing the call (Invoices,
 * Invoice Settings, Print) ended up with dead buttons that did nothing.
 * The static guard in cg_render_upgrade_modal_html() keeps this safe on the
 * pages that still render it inline.
 */
function cg_render_upgrade_modal_in_footer()
{
    $screen = get_current_screen();
    if (!$screen) {
        return;
    }
    $is_cg_page = (strpos($screen->id, 'cg-main-dashboard') !== false || strpos($screen->id, '_page_cg-') !== false);
    $is_order_screen = in_array($screen->id, ['shop_order', 'edit-shop_order', 'woocommerce_page_wc-orders'], true);
    if ($is_cg_page || $is_order_screen || $screen->id === 'dashboard') {
        cg_render_upgrade_modal_html();
    }
}
add_action('admin_footer', 'cg_render_upgrade_modal_in_footer');


/**
 * Renders the "Upgrade to Pro" popup modal.
 * Uses a static guard to prevent double-rendering on a single page.
 */
function cg_render_upgrade_modal_html()
{
    static $rendered = false;
    if ($rendered || cg_is_pro_plan()) {
        return;
    }
    $rendered = true;
    ?>
    <div id="cg-upgrade-modal" class="cg-upgrade-modal-overlay" style="display:none;" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e('Upgrade to Pro', 'checkoutguard'); ?>">
        <div class="cg-upgrade-modal-dialog">
            <button type="button" class="cg-upgrade-modal-close" aria-label="<?php esc_attr_e('Close', 'checkoutguard'); ?>">&times;</button>
            <div class="cg-upgrade-modal-body">
                <span class="dashicons dashicons-lock cg-upgrade-modal-icon"></span>
                <h2><?php _e('Upgrade to Pro', 'checkoutguard'); ?></h2>
                <p class="cg-upgrade-modal-desc"><?php _e('Unlock all premium features including customer info, action buttons, follow-up management, fraud blocking, and more.', 'checkoutguard'); ?></p>
                <div class="cg-upgrade-modal-actions">
                    <a href="<?php echo esc_url(CG_WHATSAPP_URL); ?>" target="_blank" class="cg-upgrade-action-btn cg-upgrade-whatsapp">
                        <span class="dashicons dashicons-groups"></span>
                        <?php _e('Join WhatsApp Community', 'checkoutguard'); ?>
                    </a>
                    <a href="<?php echo esc_url(CG_WHATSAPP_CONTACT3); ?>" target="_blank" rel="noopener" class="cg-upgrade-action-btn cg-upgrade-whatsapp">
                        <span class="dashicons dashicons-phone"></span>
                        <?php _e('WhatsApp: +8801410323121', 'checkoutguard'); ?>
                    </a>
                    <a href="<?php echo esc_url(CG_WHATSAPP_CONTACT2); ?>" target="_blank" rel="noopener" class="cg-upgrade-action-btn cg-upgrade-whatsapp">
                        <span class="dashicons dashicons-phone"></span>
                        <?php _e('WhatsApp: +8801751126322', 'checkoutguard'); ?>
                    </a>
                    <a href="<?php echo esc_url(CG_WHATSAPP_CONTACT1); ?>" target="_blank" rel="noopener" class="cg-upgrade-action-btn cg-upgrade-whatsapp">
                        <span class="dashicons dashicons-phone"></span>
                        <?php _e('WhatsApp: +8801885660190', 'checkoutguard'); ?>
                        <small class="cg-upgrade-btn-note"><?php _e('(WhatsApp only)', 'checkoutguard'); ?></small>
                    </a>
                    <a href="<?php echo esc_url(CG_PRICING_URL); ?>" target="_blank" class="cg-upgrade-action-btn cg-upgrade-pricing">
                        <span class="dashicons dashicons-cart"></span>
                        <?php _e('View Pricing Plans', 'checkoutguard'); ?>
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php
}


/**
 * Renders a usage warning banner on all CG admin pages when usage is >= 80%.
 * Hooked to 'admin_notices'.
 */
function cg_render_usage_warning_banner()
{
    $screen = get_current_screen();
    if (!$screen) {
        return;
    }
    $is_cg = (strpos($screen->id, 'cg-main-dashboard') !== false)
          || (strpos($screen->id, '_page_cg-') !== false);
    if (!$is_cg) {
        return;
    }

    if (!function_exists('cg_get_usage_warnings')) {
        return;
    }

    $warnings = cg_get_usage_warnings();
    if (empty($warnings)) {
        return;
    }

    $at_limit    = [];
    $approaching = [];

    foreach ($warnings as $w) {
        if ($w['is_at_limit']) {
            $at_limit[] = $w['label'];
        } else {
            $approaching[] = $w['label'] . ' (' . $w['percentage'] . '%)';
        }
    }

    $severity = !empty($at_limit) ? 'danger' : 'warning';
    $icon     = $severity === 'danger' ? 'dashicons-warning' : 'dashicons-info';
    ?>
    <div class="cg-usage-warning-banner cg-usage-warning-banner--<?php echo esc_attr($severity); ?>">
        <div class="cg-usage-warning-banner__icon">
            <span class="dashicons <?php echo esc_attr($icon); ?>"></span>
        </div>
        <div class="cg-usage-warning-banner__content">
            <?php if (!empty($at_limit)) : ?>
                <p><strong><?php _e('Limit reached', 'checkoutguard'); ?></strong> <?php
                    /* translators: %s: comma-separated list of features at limit */
                    printf(__('for: %s.', 'checkoutguard'), esc_html(implode(', ', $at_limit)));
                ?></p>
            <?php endif; ?>
            <?php if (!empty($approaching)) : ?>
                <p><strong><?php _e('Approaching limit', 'checkoutguard'); ?></strong> <?php
                    /* translators: %s: comma-separated list of features approaching limit */
                    printf(__('for: %s.', 'checkoutguard'), esc_html(implode(', ', $approaching)));
                ?></p>
            <?php endif; ?>
            <p><?php _e('Upgrade your plan to increase your limits.', 'checkoutguard'); ?></p>
        </div>
        <div class="cg-usage-warning-banner__actions">
            <a href="<?php echo esc_url(CG_WHATSAPP_URL); ?>" target="_blank" class="button cg-btn-whatsapp">
                <?php _e('WhatsApp Us', 'checkoutguard'); ?>
            </a>
            <button type="button" class="button button-primary cg-open-upgrade-modal">
                <?php _e('View Pricing', 'checkoutguard'); ?>
            </button>
        </div>
    </div>
    <?php
}

