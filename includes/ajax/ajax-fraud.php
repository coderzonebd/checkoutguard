<?php
// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}


/**
 * AJAX handler for fetching blocked orders with server-side pagination.
 */
function cg_fetch_blocked_orders_ajax_handler()
{
    check_ajax_referer('cg_fetch_blocked_orders_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => __('Permission denied.', 'checkoutguard')]);
        return;
    }
    if (!cg_is_pro_plan()) {
        wp_send_json_error(['message' => __('This feature requires a Pro plan. Please upgrade to access it.', 'checkoutguard')], 403);
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'cg_blocked_orders';

    $page = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
    $per_page = isset($_POST['per_page']) ? max(1, min(100, intval($_POST['per_page']))) : 20;
    $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
    $sort_col = isset($_POST['sort_col']) ? sanitize_key($_POST['sort_col']) : 'blocked_at';
    $sort_dir = (isset($_POST['sort_dir']) && strtoupper($_POST['sort_dir']) === 'ASC') ? 'ASC' : 'DESC';

    // Whitelist sort columns
    $allowed_sort_cols = ['id', 'first_name', 'email_address', 'phone_number', 'ip_address', 'cart_value', 'success_ratio', 'blocked_at'];
    if (!in_array($sort_col, $allowed_sort_cols, true)) {
        $sort_col = 'blocked_at';
    }

    $where_clauses = [];
    if (!empty($search)) {
        $like = '%' . $wpdb->esc_like($search) . '%';
        $where_clauses[] = $wpdb->prepare(
            "(first_name LIKE %s OR last_name LIKE %s OR email_address LIKE %s OR phone_number LIKE %s OR ip_address LIKE %s)",
            $like, $like, $like, $like, $like
        );
    }

    $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';
    $offset = ($page - 1) * $per_page;

    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} {$where_sql}");
    $total_pages = max(1, (int) ceil($total / $per_page));

    if ($page > $total_pages) {
        $page = $total_pages;
        $offset = ($page - 1) * $per_page;
    }

    $results = $wpdb->get_results(
        "SELECT * FROM {$table_name} {$where_sql} ORDER BY {$sort_col} {$sort_dir} LIMIT {$per_page} OFFSET {$offset}"
    );

    $html = cg_get_blocked_orders_table_rows_html($results);

    wp_send_json_success([
        'html'        => $html,
        'count'       => count($results),
        'total'       => $total,
        'page'        => $page,
        'per_page'    => $per_page,
        'total_pages' => $total_pages,
    ]);
    wp_die();
}


/**
 * Handles adding a blocked item via AJAX.

 */
function cg_handle_add_blocked_item_ajax()
{
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_key($_POST['nonce']), 'cg_fraud_blocker_nonce')) {
        wp_send_json_error(['message' => __('Security check failed.', 'checkoutguard')]);
        return;
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Permission denied.', 'checkoutguard')]);
        return;
    }

    $block_type = isset($_POST['block_type']) ? sanitize_key($_POST['block_type']) : '';
    $action_type_map = [
        'ip' => 'fraud_ips',
        'email' => 'fraud_emails',
        'phone' => 'fraud_phones',
        'domain' => 'fraud_domains',
        'address' => 'fraud_addresses',
        'device' => 'fraud_devices',
    ];
    $action_type = isset($action_type_map[$block_type]) ? $action_type_map[$block_type] : 'fraud_' . $block_type . 's';

    // --- PRO PLAN CHECK for premium block types ---
    if (in_array($block_type, ['domain', 'address', 'device'], true) && !cg_is_pro_plan()) {
        wp_send_json_error(['message' => __('This block type requires a Pro plan. Please upgrade to access it.', 'checkoutguard')], 403);
        return;
    }

    // --- LIMIT CHECK ---
    if (!cg_can_perform_action($action_type)) {
        wp_send_json_error([
            'message'       => cg_get_limit_reached_message($action_type),
            'limit_reached' => true,
            'action_type'   => $action_type,
            'pricing_url'   => CG_PRICING_URL,
            'whatsapp_url'  => CG_WHATSAPP_URL,
        ], 403);
        return;
    }

    $value = isset($_POST['value']) ? sanitize_text_field(wp_unslash($_POST['value'])) : '';
    $reason = isset($_POST['reason']) ? sanitize_textarea_field(wp_unslash($_POST['reason'])) : '';

    global $wpdb;
    $table_name = '';
    $column_name = '';

    switch ($block_type) {
        case 'ip':
            $table_name = $wpdb->prefix . 'cg_blocked_ips';
            $column_name = 'ip_address';
            if (!empty($value) && !filter_var($value, FILTER_VALIDATE_IP)) {
                wp_send_json_error(['message' => __('Invalid IP address format.', 'checkoutguard')]);
                return;
            }
            if (cg_is_protected_ip($value)) {
                wp_send_json_error(['message' => __('This IP address belongs to Cloudflare, a server gateway, or an administrator and cannot be blocked.', 'checkoutguard')]);
                return;
            }
            break;
        case 'email':
            $table_name = $wpdb->prefix . 'cg_blocked_emails';
            $column_name = 'email_address';
            if (!empty($value) && !is_email($value)) {
                wp_send_json_error(['message' => __('Invalid email address format.', 'checkoutguard')]);
                return;
            }
            break;
        case 'phone':
            $table_name = $wpdb->prefix . 'cg_blocked_numbers';
            $column_name = 'phone_number';
            break;
        case 'domain':
            $table_name = $wpdb->prefix . 'cg_blocked_domains';
            $column_name = 'domain';
            $value = strtolower(trim($value));
            // Strip @ prefix if user accidentally includes it
            $value = ltrim($value, '@');
            if (empty($value) || !preg_match('/^[a-z0-9]([a-z0-9\-]*\.)+[a-z]{2,}$/', $value)) {
                wp_send_json_error(['message' => __('Invalid domain format. Example: tempmail.com', 'checkoutguard')]);
                return;
            }
            break;
        case 'address':
            $table_name = $wpdb->prefix . 'cg_blocked_addresses';
            $column_name = 'address_hash';
            // For addresses, value comes as JSON with address_1, city, postcode
            $addr_data = json_decode(stripslashes($value), true);
            if (!$addr_data || empty($addr_data['address_1'])) {
                wp_send_json_error(['message' => __('Address line 1 is required.', 'checkoutguard')]);
                return;
            }
            $address_hash = cg_hash_address(
                sanitize_text_field($addr_data['address_1']),
                sanitize_text_field($addr_data['city'] ?? ''),
                sanitize_text_field($addr_data['postcode'] ?? '')
            );
            if (empty($address_hash)) {
                wp_send_json_error(['message' => __('Could not generate address hash.', 'checkoutguard')]);
                return;
            }
            $value = $address_hash;
            // Store readable address text
            $address_text = implode(', ', array_filter([
                sanitize_text_field($addr_data['address_1']),
                sanitize_text_field($addr_data['city'] ?? ''),
                sanitize_text_field($addr_data['postcode'] ?? ''),
            ]));
            break;
        case 'device':
            $table_name = $wpdb->prefix . 'cg_blocked_devices';
            $column_name = 'device_fingerprint';
            $value = sanitize_text_field($value);
            if (empty($value)) {
                wp_send_json_error(['message' => __('Device fingerprint is required.', 'checkoutguard')]);
                return;
            }
            break;
        default:
            wp_send_json_error(['message' => __('Invalid block type.', 'checkoutguard')]);
            return;
    }

    if (empty($value)) {
        wp_send_json_error(['message' => __('Value cannot be empty.', 'checkoutguard')]);
        return;
    }

    // Check if already exists
    $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table_name} WHERE {$column_name} = %s", $value));
    if ($existing) {
        wp_send_json_error(['message' => sprintf(__('This %s is already in the blocklist.', 'checkoutguard'), $block_type)]);
        return;
    }

    $data_to_insert = [
        $column_name => $value,
        'reason' => $reason,
        'added_by' => get_current_user_id(),
        'created_at' => current_time('mysql')
    ];

    // Handle expiry
    $expires_in = isset($_POST['expires_in']) ? intval($_POST['expires_in']) : 0;
    if ($expires_in > 0) {
        $data_to_insert['expires_at'] = gmdate('Y-m-d H:i:s', strtotime("+{$expires_in} days"));
    }

    // Handle address_text for address blocks
    if ($block_type === 'address' && !empty($address_text)) {
        $data_to_insert['address_text'] = $address_text;
    }

    // Handle device_info for device blocks
    if ($block_type === 'device' && !empty($_POST['device_info'])) {
        $data_to_insert['device_info'] = sanitize_textarea_field(wp_unslash($_POST['device_info']));
    }

    $inserted = $wpdb->insert($table_name, $data_to_insert);

    if ($inserted) {
        cg_increment_usage_count($action_type);
        $new_item_id = $wpdb->insert_id;
        $item = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d", $new_item_id));
        $html = cg_get_blocked_list_item_html($item, $column_name, $block_type);
        wp_send_json_success(['html' => $html]);
    } else {
        wp_send_json_error(['message' => __('Database error.', 'checkoutguard')]);
    }
}



/**
 * Handles deleting a blocked item via AJAX by its value.
 */
function cg_handle_delete_blocked_item_ajax()
{
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_key($_POST['nonce']), 'cg_delete_blocked_item_nonce')) {
        wp_send_json_error(['message' => __('Security check failed.', 'checkoutguard')]);
        return;
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Permission denied.', 'checkoutguard')]);
        return;
    }

    global $wpdb;
    $block_type = isset($_POST['block_type']) ? sanitize_key($_POST['block_type']) : '';
    $action_type_map = [
        'ip' => 'fraud_ips',
        'email' => 'fraud_emails',
        'phone' => 'fraud_phones',
        'domain' => 'fraud_domains',
        'address' => 'fraud_addresses',
        'device' => 'fraud_devices',
    ];
    $action_type = isset($action_type_map[$block_type]) ? $action_type_map[$block_type] : 'fraud_' . $block_type . 's';
    $item_id = isset($_POST['item_id']) ? intval($_POST['item_id']) : 0;
    $value_to_delete = isset($_POST['value']) ? sanitize_text_field(wp_unslash($_POST['value'])) : '';

    $table_name = '';
    $column_name = '';
    switch ($block_type) {
        case 'ip':
            $table_name = $wpdb->prefix . 'cg_blocked_ips';
            $column_name = 'ip_address';
            break;
        case 'email':
            $table_name = $wpdb->prefix . 'cg_blocked_emails';
            $column_name = 'email_address';
            break;
        case 'phone':
            $table_name = $wpdb->prefix . 'cg_blocked_numbers';
            $column_name = 'phone_number';
            break;
        case 'domain':
            $table_name = $wpdb->prefix . 'cg_blocked_domains';
            $column_name = 'domain';
            break;
        case 'address':
            $table_name = $wpdb->prefix . 'cg_blocked_addresses';
            $column_name = 'address_hash';
            break;
        case 'device':
            $table_name = $wpdb->prefix . 'cg_blocked_devices';
            $column_name = 'device_fingerprint';
            break;
        default:
            wp_send_json_error(['message' => __('Invalid block type.', 'checkoutguard')]);
            return;
    }

    $deleted = false;
    // Prioritize deleting by ID if it's provided (from the main Fraud Blocker page)
    if ($item_id > 0) {
        $deleted = $wpdb->delete($table_name, ['id' => $item_id], ['%d']);
    }
    // Otherwise, delete by value (from the order meta box "Unblock" button)
    elseif (!empty($value_to_delete)) {
        $deleted = $wpdb->delete($table_name, [$column_name => $value_to_delete], ['%s']);
    } else {
        wp_send_json_error(['message' => __('No item ID or value provided for deletion.', 'checkoutguard')]);
        return;
    }

    if ($deleted !== false) {
        cg_decrement_usage_count($action_type);
        wp_send_json_success(['message' => __('Item removed from blocklist.', 'checkoutguard')]);
    } else {
        wp_send_json_error(['message' => __('Could not remove item from the database.', 'checkoutguard')]);
    }
}


/**
 * Handles deleting a single blocked order log entry via AJAX.
 */
function cg_handle_delete_blocked_log_ajax()
{
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_key($_POST['nonce']), 'cg_delete_blocked_log_nonce')) {
        wp_send_json_error(['message' => __('Security check failed.', 'checkoutguard')]);
        return;
    }

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => __('Permission denied.', 'checkoutguard')]);
        return;
    }

    $log_id = isset($_POST['log_id']) ? intval($_POST['log_id']) : 0;
    if (!$log_id) {
        wp_send_json_error(['message' => __('Invalid Log ID.', 'checkoutguard')]);
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'cg_blocked_orders';
    $deleted = $wpdb->delete($table_name, ['id' => $log_id], ['%d']);

    if ($deleted) {
        wp_send_json_success(['message' => __('Log entry deleted successfully.', 'checkoutguard')]);
    } else {
        wp_send_json_error(['message' => __('Could not delete the log entry.', 'checkoutguard')]);
    }

}


/**
 * Handles the AJAX request to get blocked order log details.
 */
function cg_get_blocked_order_details_ajax_handler()
{
    check_ajax_referer('cg_view_details_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => __('Permission denied.', 'checkoutguard')]);
        return;
    }

    if (!isset($_POST['entry_id']) || !is_numeric($_POST['entry_id'])) {
        wp_send_json_error(['message' => __('Invalid ID provided.', 'checkoutguard')]);
        return;
    }
    $entry_id = intval($_POST['entry_id']);

    global $wpdb;
    $table_name = $wpdb->prefix . 'cg_blocked_orders';
    $entry = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d", $entry_id));

    if (!$entry) {
        wp_send_json_error(['message' => __('Blocked order log not found.', 'checkoutguard')]);
        return;
    }

    $full_name = trim(($entry->first_name ?? '') . ' ' . ($entry->last_name ?? ''));

    ob_start();
    ?>
    <div class="cg-detail-popup">
        <div class="cg-detail-header">
            <div class="cg-detail-header-info">
                <h2 class="cg-detail-name"><?php echo esc_html($full_name ?: __('Unknown Customer', 'checkoutguard')); ?></h2>
                <span class="cg-detail-id">#<?php echo esc_html($entry->id); ?></span>
            </div>
            <span class="cg-detail-status status-blocked"><?php _e('Blocked', 'checkoutguard'); ?></span>
        </div>

        <div class="cg-detail-grid">
            <?php if (!cg_is_pro_plan()): ?>
            <div class="cg-pro-locked-hover">
                <div class="cg-pro-hover-overlay">
                    <span class="dashicons dashicons-lock" style="font-size:28px;width:28px;height:28px;line-height:28px;"></span>
                    <p style="margin:0;font-weight:600;color:#1e293b;"><?php _e('Upgrade to Pro to view Customer Info', 'checkoutguard'); ?></p>
                    <button type="button" class="button button-primary cg-open-upgrade-modal"><?php _e('Upgrade Now', 'checkoutguard'); ?></button>
                </div>
            <?php endif; ?>
            <div class="cg-detail-card">
                <h4><?php _e('Customer Info', 'checkoutguard'); ?></h4>
                <table class="cg-detail-table">
                    <tr>
                        <td><?php _e('Email', 'checkoutguard'); ?></td>
                        <td>
                            <?php if (!cg_is_pro_plan() && !empty($entry->email_address)): ?>
                                <span class="cg-pro-masked" data-cg-tooltip="<?php esc_attr_e('Upgrade to Pro to see full email', 'checkoutguard'); ?>">
                                    <?php echo esc_html(cg_mask_email($entry->email_address)); ?>
                                </span>
                            <?php else: ?>
                                <?php echo esc_html($entry->email_address ?: 'N/A'); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><?php _e('Phone', 'checkoutguard'); ?></td>
                        <td>
                            <?php if (!cg_is_pro_plan() && !empty($entry->phone_number)): ?>
                                <span class="cg-pro-masked" data-cg-tooltip="<?php esc_attr_e('Upgrade to Pro to see full number', 'checkoutguard'); ?>">
                                    <?php echo esc_html(cg_mask_phone($entry->phone_number)); ?>
                                </span>
                            <?php else: ?>
                                <?php echo esc_html($entry->phone_number ?: 'N/A'); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr><td><?php _e('IP Address', 'checkoutguard'); ?></td><td><?php echo esc_html($entry->ip_address ?: 'N/A'); ?></td></tr>
                    <tr><td><?php _e('Blocked At', 'checkoutguard'); ?></td><td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($entry->blocked_at))); ?></td></tr>
                </table>
            </div>
            <?php if (!cg_is_pro_plan()): ?>
            </div><!-- .cg-pro-locked-hover -->
            <?php endif; ?>

            <div class="cg-detail-card">
                <h4><?php _e('Block Reason', 'checkoutguard'); ?></h4>
                <table class="cg-detail-table">
                    <tr>
                        <td><?php _e('Success Ratio', 'checkoutguard'); ?></td>
                        <td><span class="cg-text-danger"><strong><?php echo esc_html($entry->success_ratio); ?>%</strong></span></td>
                    </tr>
                    <tr>
                        <td><?php _e('Threshold', 'checkoutguard'); ?></td>
                        <td><?php echo esc_html($entry->threshold_at_block); ?>%</td>
                    </tr>
                </table>
                <div class="cg-detail-ratio-bar" style="margin-top: 12px;">
                    <div class="cg-detail-ratio-bar-fill" style="width: <?php echo esc_attr($entry->success_ratio); ?>%; background-color: var(--cg-danger-color);"></div>
                </div>
            </div>
        </div>

        <?php
        // Fraud Blocking buttons - check block status for each identifier
        // Look up device fingerprint from the incomplete_checkouts table
        $device_fp = '';
        if (!empty($entry->phone_number)) {
            $device_fp = $wpdb->get_var($wpdb->prepare(
                "SELECT device_fingerprint FROM {$wpdb->prefix}cg_incomplete_checkouts WHERE phone = %s AND device_fingerprint != '' ORDER BY updated_at DESC LIMIT 1",
                $entry->phone_number
            )) ?: '';
        }
        if (!cg_is_pro_plan()): ?>
            <div class="cg-pro-locked-hover">
                <div class="cg-pro-hover-overlay">
                    <span class="dashicons dashicons-lock"></span>
                    <p><?php _e('Upgrade to Pro to unlock Fraud Blocking', 'checkoutguard'); ?></p>
                    <button type="button" class="button button-primary cg-open-upgrade-modal"><?php _e('Upgrade Now', 'checkoutguard'); ?></button>
                </div>
        <?php endif; ?>
        <?php
        cg_render_popup_block_buttons(
            $entry->ip_address ?? '',
            $entry->email_address ?? '',
            $entry->phone_number ?? '',
            $device_fp
        );
        ?>
        <?php if (!cg_is_pro_plan()): ?>
            </div>
        <?php endif; ?>

        <div class="cg-detail-card cg-detail-card-full">
            <h4><?php _e('Cart at Time of Block', 'checkoutguard'); ?></h4>
            <?php
            $cart_items = json_decode($entry->cart_details, true);
            if (is_array($cart_items) && !empty($cart_items)):
            ?>
            <table class="cg-detail-cart-table">
                <thead>
                    <tr>
                        <th><?php _e('Product', 'checkoutguard'); ?></th>
                        <th><?php _e('Qty', 'checkoutguard'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cart_items as $item): ?>
                    <tr>
                        <td><?php echo esc_html($item['name'] ?? __('Unknown Product', 'checkoutguard')); ?></td>
                        <td><?php echo esc_html($item['quantity'] ?? 0); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td><strong><?php _e('Cart Value', 'checkoutguard'); ?></strong></td>
                        <td><strong><?php echo wp_kses_post(wc_price($entry->cart_value)); ?></strong></td>
                    </tr>
                </tfoot>
            </table>
            <?php else: ?>
                <p class="cg-detail-empty"><?php _e('No cart items found.', 'checkoutguard'); ?></p>
            <?php endif; ?>
        </div>
    </div>
    <?php
    $html_output = ob_get_clean();

    wp_send_json_success(['html' => $html_output]);
    wp_die();
}


/**
 * Handles exporting a blocklist as CSV via AJAX.
 */
function cg_handle_export_blocklist()
{
    check_ajax_referer('cg_fraud_blocker_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Permission denied.', 'checkoutguard')]);
        return;
    }

    global $wpdb;
    $block_type = sanitize_key($_POST['block_type'] ?? '');

    $type_map = [
        'ip'      => ['table' => $wpdb->prefix . 'cg_blocked_ips', 'col' => 'ip_address', 'header' => 'IP Address'],
        'email'   => ['table' => $wpdb->prefix . 'cg_blocked_emails', 'col' => 'email_address', 'header' => 'Email'],
        'phone'   => ['table' => $wpdb->prefix . 'cg_blocked_numbers', 'col' => 'phone_number', 'header' => 'Phone'],
        'domain'  => ['table' => $wpdb->prefix . 'cg_blocked_domains', 'col' => 'domain', 'header' => 'Domain'],
        'address' => ['table' => $wpdb->prefix . 'cg_blocked_addresses', 'col' => 'address_text', 'header' => 'Address'],
        'device'  => ['table' => $wpdb->prefix . 'cg_blocked_devices', 'col' => 'device_fingerprint', 'header' => 'Device Fingerprint'],
    ];

    if (!isset($type_map[$block_type])) {
        wp_send_json_error(['message' => __('Invalid block type.', 'checkoutguard')]);
        return;
    }

    $config = $type_map[$block_type];
    $rows = $wpdb->get_results("SELECT {$config['col']}, reason, expires_at, created_at FROM {$config['table']} ORDER BY created_at DESC", ARRAY_A);

    $csv_lines = [];
    $csv_lines[] = implode(',', [$config['header'], 'Reason', 'Expires At', 'Created At']);

    foreach ($rows as $row) {
        $csv_lines[] = implode(',', [
            '"' . str_replace('"', '""', cg_csv_escape_formula($row[$config['col']] ?? '')) . '"',
            '"' . str_replace('"', '""', cg_csv_escape_formula($row['reason'] ?? '')) . '"',
            '"' . ($row['expires_at'] ?? 'Never') . '"',
            '"' . ($row['created_at'] ?? '') . '"',
        ]);
    }

    wp_send_json_success([
        'csv'      => implode("\n", $csv_lines),
        'filename' => 'cg_blocked_' . $block_type . 's_' . gmdate('Y-m-d') . '.csv',
        'count'    => count($rows),
    ]);
}


/**
 * Handles bulk deletion of blocked items via AJAX.
 */
function cg_handle_bulk_delete_blocked_items_ajax()
{
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_key($_POST['nonce']), 'cg_bulk_delete_blocked_items_nonce')) {
        wp_send_json_error(['message' => __('Security check failed.', 'checkoutguard')]);
        return;
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Permission denied.', 'checkoutguard')]);
        return;
    }

    $block_type = isset($_POST['block_type']) ? sanitize_key($_POST['block_type']) : '';
    $item_ids = isset($_POST['item_ids']) ? array_map('intval', (array) $_POST['item_ids']) : [];

    if (empty($item_ids)) {
        wp_send_json_error(['message' => __('No items selected.', 'checkoutguard')]);
        return;
    }

    global $wpdb;

    $action_type_map = [
        'ip' => ['table' => $wpdb->prefix . 'cg_blocked_ips', 'action' => 'fraud_ips'],
        'email' => ['table' => $wpdb->prefix . 'cg_blocked_emails', 'action' => 'fraud_emails'],
        'phone' => ['table' => $wpdb->prefix . 'cg_blocked_numbers', 'action' => 'fraud_phones'],
        'domain' => ['table' => $wpdb->prefix . 'cg_blocked_domains', 'action' => 'fraud_domains'],
        'address' => ['table' => $wpdb->prefix . 'cg_blocked_addresses', 'action' => 'fraud_addresses'],
        'device' => ['table' => $wpdb->prefix . 'cg_blocked_devices', 'action' => 'fraud_devices'],
    ];

    if (!isset($action_type_map[$block_type])) {
        wp_send_json_error(['message' => __('Invalid block type.', 'checkoutguard')]);
        return;
    }

    $config = $action_type_map[$block_type];
    $table = $config['table'];
    $action_type = $config['action'];
    $deleted_count = 0;

    foreach ($item_ids as $id) {
        $deleted = $wpdb->delete($table, ['id' => $id], ['%d']);
        if ($deleted) {
            cg_decrement_usage_count($action_type);
            $deleted_count++;
        }
    }

    wp_send_json_success([
        'message' => sprintf(__('%d item(s) removed from blocklist.', 'checkoutguard'), $deleted_count),
        'deleted_count' => $deleted_count,
    ]);
}


/**
 * Handles importing a blocklist from CSV via AJAX.
 */
function cg_handle_import_blocklist()
{
    check_ajax_referer('cg_fraud_blocker_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Permission denied.', 'checkoutguard')]);
        return;
    }

    $block_type = sanitize_key($_POST['block_type'] ?? '');
    $csv_data = isset($_POST['csv_data']) ? wp_unslash($_POST['csv_data']) : '';

    // Prevent DoS via oversized CSV payloads (1 MB limit)
    if (strlen($csv_data) > 1048576) {
        wp_send_json_error(['message' => __('CSV data is too large. Maximum size is 1 MB.', 'checkoutguard')]);
        return;
    }

    if (empty($csv_data)) {
        wp_send_json_error(['message' => __('No CSV data provided.', 'checkoutguard')]);
        return;
    }

    global $wpdb;

    $type_map = [
        'ip'     => ['table' => $wpdb->prefix . 'cg_blocked_ips', 'col' => 'ip_address', 'action' => 'fraud_ips'],
        'email'  => ['table' => $wpdb->prefix . 'cg_blocked_emails', 'col' => 'email_address', 'action' => 'fraud_emails'],
        'phone'  => ['table' => $wpdb->prefix . 'cg_blocked_numbers', 'col' => 'phone_number', 'action' => 'fraud_phones'],
        'domain' => ['table' => $wpdb->prefix . 'cg_blocked_domains', 'col' => 'domain', 'action' => 'fraud_domains'],
    ];

    if (!isset($type_map[$block_type])) {
        wp_send_json_error(['message' => __('Import is only supported for IP, Email, Phone, and Domain blocklists.', 'checkoutguard')]);
        return;
    }

    $config = $type_map[$block_type];
    $action_type = $config['action'];
    $lines = preg_split('/\r\n|\r|\n/', trim($csv_data));
    $imported = 0;
    $skipped = 0;
    $limit_reached = 0;
    $current_user_id = get_current_user_id();

    foreach ($lines as $index => $line) {
        $line = trim($line);
        if (empty($line)) {
            continue;
        }

        // Skip header row
        if ($index === 0 && (stripos($line, 'ip') !== false || stripos($line, 'email') !== false || stripos($line, 'phone') !== false || stripos($line, 'domain') !== false)) {
            continue;
        }

        // Check plan limit before each insert
        if (!cg_can_perform_action($action_type)) {
            // Count remaining unprocessed lines (excluding empty/header)
            $limit_reached = count($lines) - $index;
            break;
        }

        // Parse CSV line — first column is the value, second is optional reason
        $parts = str_getcsv($line);
        $value = sanitize_text_field(trim($parts[0] ?? ''));
        $reason = sanitize_textarea_field(trim($parts[1] ?? 'Imported via CSV'));

        if (empty($value)) {
            $skipped++;
            continue;
        }

        // Validate based on type
        if ($block_type === 'ip' && !filter_var($value, FILTER_VALIDATE_IP)) {
            $skipped++;
            continue;
        }
        if ($block_type === 'email' && !is_email($value)) {
            $skipped++;
            continue;
        }
        if ($block_type === 'domain') {
            $value = strtolower(ltrim($value, '@'));
        }

        // Check if already exists
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$config['table']} WHERE {$config['col']} = %s",
            $value
        ));
        if ($exists) {
            $skipped++;
            continue;
        }

        $inserted = $wpdb->insert($config['table'], [
            $config['col'] => $value,
            'reason'       => $reason,
            'added_by'     => $current_user_id,
            'created_at'   => current_time('mysql'),
        ]);
        if ($inserted) {
            cg_increment_usage_count($action_type);
            $imported++;
        }
    }

    $message = sprintf(__('Import complete: %d added, %d skipped (duplicates or invalid).', 'checkoutguard'), $imported, $skipped);
    if ($limit_reached > 0) {
        $message .= ' ' . sprintf(
            /* translators: 1: count of remaining items, 2: pricing link open tag, 3: close tag, 4: WhatsApp link open tag, 5: close tag */
            __('Plan limit reached — %1$d remaining items were not imported. %2$sUpgrade your plan%3$s or %4$scontact us on WhatsApp%5$s.', 'checkoutguard'),
            $limit_reached,
            '<a href="#" class="cg-open-upgrade-modal">',
            '</a>',
            '<a href="' . esc_url(CG_WHATSAPP_URL) . '" target="_blank">',
            '</a>'
        );
    }

    wp_send_json_success([
        'message'       => $message,
        'imported'      => $imported,
        'skipped'       => $skipped,
        'limit_reached' => $limit_reached,
    ]);
}

