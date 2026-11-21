<?php
// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}


/**
 * AJAX handler: Create or update a custom order status.
 */
function cg_handle_save_order_status()
{
    check_ajax_referer('cg_order_statuses_nonce', 'nonce');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => __('Permission denied.', 'checkoutguard')]);
    }

    $label       = sanitize_text_field(wp_unslash($_POST['label'] ?? ''));
    $color       = sanitize_hex_color($_POST['color'] ?? '#f97316');
    $description = sanitize_textarea_field(wp_unslash($_POST['description'] ?? ''));
    $icon         = sanitize_text_field(wp_unslash($_POST['icon'] ?? ''));
    $show_in_bulk = isset($_POST['show_in_bulk']) ? (bool) $_POST['show_in_bulk'] : false;
    $edit_slug    = sanitize_key($_POST['edit_slug'] ?? '');

    if (empty($label)) {
        wp_send_json_error(['message' => __('Status label is required.', 'checkoutguard')]);
    }

    if (empty($color)) {
        $color = '#f97316';
    }

    $statuses = get_option('cg_custom_order_statuses', []);

    if (!empty($edit_slug)) {
        // UPDATE existing status.
        $found = false;
        foreach ($statuses as &$s) {
            if ($s['slug'] === $edit_slug) {
                $s['label']       = $label;
                $s['color']       = $color;
                $s['description'] = $description;
                $s['icon']        = $icon;
                $s['show_in_bulk'] = $show_in_bulk;
                $found = true;
                break;
            }
        }
        unset($s);

        if (!$found) {
            wp_send_json_error(['message' => __('Status not found.', 'checkoutguard')]);
        }

        update_option('cg_custom_order_statuses', $statuses, false);
        cg_clear_order_statuses_cache();

        wp_send_json_success([
            'message' => __('Status updated successfully.', 'checkoutguard'),
            'slug'    => $edit_slug,
            'status'  => [
                'slug'        => $edit_slug,
                'label'       => $label,
                'color'       => $color,
                'description' => $description,
                'icon'        => $icon,
                'show_in_bulk' => $show_in_bulk,
            ],
        ]);
    }

    // CREATE new status.
    $slug = sanitize_title($label);
    $slug = substr($slug, 0, 17); // WC post_status varchar(20) minus 'wc-' prefix.

    if (empty($slug)) {
        wp_send_json_error(['message' => __('Could not generate a valid slug from the label.', 'checkoutguard')]);
    }

    // Block slugs that collide with WooCommerce internal hook names.
    // WC fires do_action('woocommerce_order_status_' . $slug, ...) with 3 args per transition,
    // but 'woocommerce_order_status_changed' is a separate hook expecting 4 args.
    $reserved_slugs = ['changed'];
    if (in_array($slug, $reserved_slugs, true)) {
        wp_send_json_error([
            'message' => sprintf(
                /* translators: %s: the reserved slug */
                __('The slug "%s" is reserved by WooCommerce and cannot be used. Please choose a different label.', 'checkoutguard'),
                $slug
            ),
        ]);
    }

    // Check uniqueness among all managed statuses.
    foreach ($statuses as $s) {
        if ($s['slug'] === $slug) {
            wp_send_json_error(['message' => __('A status with this slug already exists.', 'checkoutguard')]);
        }
    }

    $statuses[] = [
        'slug'        => $slug,
        'label'       => $label,
        'color'       => $color,
        'description' => $description,
        'icon'        => $icon,
        'show_in_bulk' => $show_in_bulk,
        'is_default'  => false,
        'created_at'  => current_time('mysql'),
    ];

    update_option('cg_custom_order_statuses', $statuses, false);
    cg_clear_order_statuses_cache();

    wp_send_json_success([
        'message' => __('Status created successfully.', 'checkoutguard'),
        'slug'    => $slug,
        'status'  => [
            'slug'        => $slug,
            'label'       => $label,
            'color'       => $color,
            'description' => $description,
            'icon'        => $icon,
            'show_in_bulk' => $show_in_bulk,
        ],
    ]);
}


/**
 * AJAX handler: Delete a custom order status with optional order migration.
 */
function cg_handle_delete_order_status()
{
    check_ajax_referer('cg_order_statuses_nonce', 'nonce');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => __('Permission denied.', 'checkoutguard')]);
    }

    $slug       = sanitize_key($_POST['slug'] ?? '');
    $migrate_to = sanitize_key($_POST['migrate_to'] ?? '');

    if (empty($slug)) {
        wp_send_json_error(['message' => __('No status slug provided.', 'checkoutguard')]);
    }

    $statuses    = get_option('cg_custom_order_statuses', []);
    $found_index = null;

    foreach ($statuses as $index => $s) {
        if ($s['slug'] === $slug) {
            $found_index = $index;
            break;
        }
    }

    if ($found_index === null) {
        wp_send_json_error(['message' => __('Status not found.', 'checkoutguard')]);
    }

    // Check if any orders have this status.
    $order_count = (int) wc_orders_count($slug);

    if ($order_count > 0 && empty($migrate_to)) {
        wp_send_json_error([
            'message'         => sprintf(
                /* translators: %d: number of orders */
                __('This status has %d order(s). Please select a status to migrate them to.', 'checkoutguard'),
                $order_count
            ),
            'needs_migration' => true,
            'order_count'     => $order_count,
        ]);
    }

    // Migrate orders if needed.
    if ($order_count > 0 && !empty($migrate_to)) {
        $all_wc_statuses = wc_get_order_statuses();
        if (!isset($all_wc_statuses['wc-' . $migrate_to])) {
            wp_send_json_error(['message' => __('Invalid migration target status.', 'checkoutguard')]);
        }

        $orders = wc_get_orders([
            'status' => $slug,
            'limit'  => -1,
            'return' => 'ids',
        ]);

        foreach ($orders as $order_id) {
            $order = wc_get_order($order_id);
            if ($order) {
                $order->set_status($migrate_to);
                $order->add_order_note(sprintf(
                    /* translators: 1: old status label, 2: new status label */
                    __('Status automatically changed from "%1$s" to "%2$s" because the custom status was deleted.', 'checkoutguard'),
                    $statuses[$found_index]['label'],
                    $all_wc_statuses['wc-' . $migrate_to]
                ));
                $order->save();
            }
        }
    }

    // Remove the status from the array.
    array_splice($statuses, $found_index, 1);
    update_option('cg_custom_order_statuses', $statuses, false);
    cg_clear_order_statuses_cache();

    wp_send_json_success([
        'message'  => sprintf(
            /* translators: %s: status slug */
            __('Status "%s" deleted successfully.', 'checkoutguard'),
            $slug
        ),
        'migrated' => $order_count,
    ]);
}


/**
 * AJAX handler: Fetch order counts for all WooCommerce statuses.
 */
function cg_handle_fetch_order_status_counts()
{
    check_ajax_referer('cg_order_statuses_nonce', 'nonce');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => __('Permission denied.', 'checkoutguard')]);
    }

    $managed_statuses = cg_get_custom_order_statuses();

    $result = [];
    foreach ($managed_statuses as $s) {
        if (empty($s['slug']) || empty($s['label'])) {
            continue;
        }
        $count = (int) wc_orders_count($s['slug']);
        $result[] = [
            'slug'       => $s['slug'],
            'label'      => $s['label'],
            'count'      => $count,
            'color'      => $s['color'] ?? '#6b7280',
            'icon'       => $s['icon'] ?? '',
            'is_default' => !empty($s['is_default']),
        ];
    }

    wp_send_json_success(['statuses' => $result]);
}


/**
 * AJAX handler: Get a single custom order status for the edit form.
 */
function cg_handle_get_order_status()
{
    check_ajax_referer('cg_order_statuses_nonce', 'nonce');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => __('Permission denied.', 'checkoutguard')]);
    }

    $slug = sanitize_key($_POST['slug'] ?? '');
    if (empty($slug)) {
        wp_send_json_error(['message' => __('No slug provided.', 'checkoutguard')]);
    }

    $statuses = get_option('cg_custom_order_statuses', []);
    foreach ($statuses as $s) {
        if ($s['slug'] === $slug) {
            wp_send_json_success(['status' => $s]);
        }
    }

    wp_send_json_error(['message' => __('Status not found.', 'checkoutguard')]);
}


/**
 * AJAX handler: Reset all order statuses back to WooCommerce defaults.
 */
function cg_handle_reset_order_statuses()
{
    check_ajax_referer('cg_order_statuses_nonce', 'nonce');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => __('Permission denied.', 'checkoutguard')]);
    }

    $statuses = cg_reset_order_statuses();

    wp_send_json_success([
        'message'  => __('Order statuses have been reset to WooCommerce defaults.', 'checkoutguard'),
        'statuses' => $statuses,
    ]);
}

