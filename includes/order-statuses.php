<?php
// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Returns all managed order statuses from the database.
 * Auto-initializes with WooCommerce defaults on first access.
 *
 * @return array
 */
function cg_get_custom_order_statuses($force_refresh = false)
{
    static $statuses = null;
    if ($force_refresh || $statuses === null) {
        $statuses = get_option('cg_custom_order_statuses', []);
        if (empty($statuses)) {
            $statuses = cg_build_default_statuses_array();
            update_option('cg_custom_order_statuses', $statuses, false);
        }
    }
    return $statuses;
}

/**
 * Builds the default statuses array from WooCommerce defaults.
 *
 * @return array
 */
function cg_build_default_statuses_array()
{
    $defaults = cg_get_default_wc_statuses();
    $result   = [];
    foreach ($defaults as $slug => $data) {
        $result[] = [
            'slug'        => $slug,
            'label'       => $data['label'],
            'color'       => $data['color'],
            'description' => '',
            'icon'        => '',
            'is_default'  => true,
            'show_in_bulk' => false,
            'created_at'  => current_time('mysql'),
        ];
    }
    return $result;
}

/**
 * Resets all order statuses back to WooCommerce defaults.
 * Called by the reset AJAX handler.
 *
 * @return array The new statuses array.
 */
function cg_reset_order_statuses()
{
    $statuses = cg_build_default_statuses_array();
    update_option('cg_custom_order_statuses', $statuses, false);
    // Clear static cache.
    cg_clear_order_statuses_cache();
    return $statuses;
}

/**
 * Clears the static cache for order statuses.
 */
function cg_clear_order_statuses_cache()
{
    cg_get_custom_order_statuses(true);
}

/**
 * Registers non-default custom order statuses with WordPress.
 * Default WooCommerce statuses are already registered by WooCommerce core.
 * Hooked to 'init' at priority 20.
 */
function cg_register_custom_order_statuses()
{
    $statuses = cg_get_custom_order_statuses();
    $defaults = cg_get_default_wc_statuses();

    foreach ($statuses as $status) {
        if (empty($status['slug'])) {
            continue;
        }
        // Only register non-default statuses; WooCommerce handles its own.
        if (isset($defaults[$status['slug']])) {
            continue;
        }
        register_post_status('wc-' . $status['slug'], [
            'label'                     => $status['label'],
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            /* translators: %s: order count */
            'label_count'               => _n_noop(
                $status['label'] . ' <span class="count">(%s)</span>',
                $status['label'] . ' <span class="count">(%s)</span>',
                'checkoutguard'
            ),
        ]);
    }
}

/**
 * Replaces the WooCommerce order status list with our managed statuses.
 * This allows editing default status labels and removing statuses entirely.
 * Filters 'wc_order_statuses'.
 *
 * @param array $statuses Existing WooCommerce statuses.
 * @return array
 */
function cg_add_custom_statuses_to_woocommerce($statuses)
{
    $managed = cg_get_custom_order_statuses();
    if (empty($managed)) {
        return $statuses;
    }

    $new_statuses = [];
    foreach ($managed as $s) {
        if (!empty($s['slug']) && !empty($s['label'])) {
            $new_statuses['wc-' . $s['slug']] = $s['label'];
        }
    }

    return $new_statuses;
}

/**
 * Filters the bulk actions on the WooCommerce orders list page.
 * Appends custom order statuses where show_in_bulk is enabled, while strictly
 * preserving all native WooCommerce bulk actions (processing, on-hold, completed, etc.).
 *
 * @param array $actions Bulk actions array.
 * @return array
 */
function cg_filter_order_bulk_actions($actions)
{
    if (!is_array($actions)) {
        $actions = [];
    }

    $statuses = cg_get_custom_order_statuses();

    // Re-add or append bulk actions for statuses where show_in_bulk is enabled.
    $mark_actions = [];
    foreach ($statuses as $s) {
        if (empty($s['slug']) || empty($s['show_in_bulk'])) {
            continue;
        }
        $key = 'mark_' . $s['slug'];
        // Do not overwrite native WC bulk actions if already present with localized string
        if (!isset($actions[$key])) {
            $mark_actions[$key] = sprintf(
                /* translators: %s: order status label */
                __('Change status to %s', 'checkoutguard'),
                $s['label']
            );
        }
    }

    if (empty($mark_actions)) {
        return $actions;
    }

    // Insert mark_* actions before 'trash' if it exists, otherwise append.
    if (isset($actions['trash'])) {
        $trash = $actions['trash'];
        unset($actions['trash']);
        $actions = array_merge($actions, $mark_actions, ['trash' => $trash]);
    } else {
        $actions = array_merge($actions, $mark_actions);
    }

    return $actions;
}

/**
 * Handles custom order status changes from the WooCommerce bulk actions dropdown.
 * Supports both classic posts table (edit-shop_order) and HPOS (woocommerce_page_wc-orders).
 */
function cg_handle_custom_order_status_bulk_action($redirect_to, $action, $order_ids)
{
    if (strpos($action, 'mark_') !== 0 || empty($order_ids) || !is_array($order_ids)) {
        return $redirect_to;
    }

    $status_slug = substr($action, 5); // remove 'mark_'
    $valid_statuses = cg_get_order_status_slugs();
    if (!in_array($status_slug, $valid_statuses, true)) {
        return $redirect_to;
    }

    $changed = 0;
    foreach ($order_ids as $order_id) {
        $order = wc_get_order($order_id);
        if ($order && $order->get_status() !== $status_slug) {
            $order->update_status($status_slug, __('Order status changed via bulk action.', 'checkoutguard'));
            $changed++;
        }
    }

    $redirect_to = add_query_arg([
        'cg_bulk_status_changed' => $changed,
        'cg_bulk_status_slug'    => rawurlencode($status_slug),
    ], $redirect_to);

    return $redirect_to;
}

/**
 * Renders an admin notice when custom order statuses are updated via bulk action.
 */
function cg_custom_order_status_bulk_notice()
{
    if (isset($_GET['cg_bulk_status_changed']) && is_numeric($_GET['cg_bulk_status_changed'])) {
        $count = (int) $_GET['cg_bulk_status_changed'];
        $slug = sanitize_text_field(wp_unslash($_GET['cg_bulk_status_slug'] ?? ''));
        $statuses = cg_get_custom_order_statuses();
        $label = $slug;
        foreach ($statuses as $s) {
            if ($s['slug'] === $slug) {
                $label = $s['label'];
                break;
            }
        }
        ?>
        <div class="notice notice-success is-dismissible">
            <p>
                <?php
                echo esc_html(sprintf(
                    /* translators: 1: number of orders, 2: status label */
                    _n('%1$d order status changed to "%2$s".', '%1$d order statuses changed to "%2$s".', $count, 'checkoutguard'),
                    $count,
                    $label
                ));
                ?>
            </p>
        </div>
        <?php
    }
}
add_action('admin_notices', 'cg_custom_order_status_bulk_notice');

/**
 * Returns the original WooCommerce default statuses with their colors.
 * Used as a reference for reset and identifying defaults.
 *
 * @return array
 */
function cg_get_default_wc_statuses()
{
    return [
        'pending'    => ['label' => __('Pending payment', 'checkoutguard'), 'color' => '#f59e0b'],
        'processing' => ['label' => __('Processing', 'checkoutguard'),      'color' => '#3b82f6'],
        'on-hold'    => ['label' => __('On hold', 'checkoutguard'),         'color' => '#f97316'],
        'completed'  => ['label' => __('Completed', 'checkoutguard'),       'color' => '#10b981'],
        'cancelled'  => ['label' => __('Cancelled', 'checkoutguard'),       'color' => '#ef4444'],
        'refunded'   => ['label' => __('Refunded', 'checkoutguard'),        'color' => '#6b7280'],
        'failed'     => ['label' => __('Failed', 'checkoutguard'),          'color' => '#dc2626'],
    ];
}
