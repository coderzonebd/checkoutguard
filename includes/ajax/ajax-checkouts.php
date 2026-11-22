<?php
// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}



// ====================================================================
// CHECKOUT TRACKING
// ====================================================================

function cg_handle_save_checkout_data()
{
    check_ajax_referer('cg_save_checkout_data_nonce', 'nonce');

    // 1. Resolve Session ID with robust fallbacks (WooCommerce Session, Device Fingerprint, or Client IP hash)
    $session_id = '';
    if (function_exists('WC') && WC()) {
        if (!WC()->session && function_exists('wc_load_cart')) {
            wc_load_cart();
        }
        if (WC()->session) {
            if (!WC()->session->has_session()) {
                WC()->session->set_customer_session_cookie(true);
            }
            $session_id = WC()->session->get_customer_id();
        }
    }

    $raw_device_fp = !empty($_POST['device_fingerprint']) ? sanitize_text_field(wp_unslash($_POST['device_fingerprint'])) : '';
    if (empty($raw_device_fp) && !empty($_COOKIE['cg_device_fp'])) {
        $raw_device_fp = sanitize_text_field(wp_unslash($_COOKIE['cg_device_fp']));
    }

    if (empty($session_id)) {
        if (!empty($raw_device_fp)) {
            $session_id = 'cg_fp_' . $raw_device_fp;
        } else {
            $session_id = 'cg_ip_' . md5(cg_get_client_ip() . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        }
    }

    // 2. Extract and Normalize Customer Fields (supporting Classic, Gutenberg Block, and Landing Pages)
    $posted_data = array_map('sanitize_text_field', array_filter($_POST, 'is_scalar'));

    $email = !empty($posted_data['billing_email']) ? sanitize_email($posted_data['billing_email']) : '';
    if (empty($email) && !empty($posted_data['email'])) {
        $email = sanitize_email($posted_data['email']);
    }

    $raw_phone = $posted_data['billing_phone'] ?? $posted_data['phone'] ?? $posted_data['mobile'] ?? '';
    $phone = !empty($raw_phone) ? cg_normalize_phone_number($raw_phone) : '';

    $first_name = $posted_data['billing_first_name'] ?? $posted_data['first_name'] ?? $posted_data['name'] ?? $posted_data['customer_name'] ?? '';
    $last_name = $posted_data['billing_last_name'] ?? $posted_data['last_name'] ?? '';

    $address_1 = $posted_data['billing_address_1'] ?? $posted_data['address_1'] ?? $posted_data['address'] ?? $posted_data['customer_address'] ?? '';
    $address_2 = $posted_data['billing_address_2'] ?? $posted_data['address_2'] ?? '';

    $city = $posted_data['billing_city'] ?? $posted_data['city'] ?? '';
    $state = $posted_data['billing_state'] ?? $posted_data['state'] ?? '';
    $postcode = $posted_data['billing_postcode'] ?? $posted_data['postcode'] ?? '';
    $country = $posted_data['billing_country'] ?? $posted_data['country'] ?? '';

    global $wpdb;
    $table_name = $wpdb->prefix . 'cg_incomplete_checkouts';

    // 3. Multi-key Existing Record Search (by session_id, device_fingerprint, or normalized phone)
    $where_clauses = ["session_id = %s"];
    $where_params = [$session_id];

    if (!empty($raw_device_fp)) {
        $where_clauses[] = "device_fingerprint = %s";
        $where_params[] = $raw_device_fp;
    }
    if (!empty($phone) && strlen($phone) >= 7) {
        $where_clauses[] = "phone = %s";
        $where_params[] = $phone;
    }

    $existing_record_id = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$table_name} WHERE (" . implode(' OR ', $where_clauses) . ") ORDER BY id DESC LIMIT 1",
        ...$where_params
    ));

    // Tier limit check for NEW incomplete checkouts
    if (!$existing_record_id && !cg_can_perform_action('checkouts')) {
        wp_send_json_error([
            'message'       => cg_get_limit_reached_message('checkouts'),
            'limit_reached' => true,
            'action_type'   => 'checkouts',
            'pricing_url'   => CG_PRICING_URL,
            'whatsapp_url'  => CG_WHATSAPP_URL,
        ], 403);
        return;
    }

    $data_to_save = [
        'email'              => $email,
        'first_name'         => $first_name,
        'last_name'          => $last_name,
        'phone'              => $phone,
        'address_1'          => $address_1,
        'address_2'          => $address_2,
        'city'               => $city,
        'state'              => $state,
        'postcode'           => $postcode,
        'country'            => $country,
        'ip_address'         => cg_get_client_ip(),
        'session_id'         => $session_id,
        'device_fingerprint' => $raw_device_fp,
        'updated_at'         => current_time('mysql'),
    ];

    $user_id = get_current_user_id();
    if ($user_id) {
        $data_to_save['user_id'] = $user_id;
    }

    // 4. Cart Details & Total Resolution (WooCommerce Cart or Landing Page Direct Product)
    $cart_details_array = [];
    $cart_total_value = 0.00;
    if (function_exists('WC') && WC()->cart && !WC()->cart->is_empty()) {
        foreach (WC()->cart->get_cart() as $cart_item) {
            $_product = $cart_item['data'] ?? null;
            if ($_product && is_a($_product, 'WC_Product')) {
                $variation_id = !empty($cart_item['variation_id']) ? (int) $cart_item['variation_id'] : 0;
                $cart_details_array[] = [
                    'product_id'   => $_product->get_id(),
                    'variation_id' => $variation_id,
                    'name'         => $_product->get_name(),
                    'quantity'     => (int) $cart_item['quantity'],
                    'line_total'   => (float) $cart_item['line_subtotal'],
                ];
                $cart_total_value += (float) $cart_item['line_subtotal'];
            }
        }
    } elseif (!empty($posted_data['product_id']) && function_exists('wc_get_product')) {
        $landing_prod_id = absint($posted_data['product_id']);
        $landing_prod = wc_get_product($landing_prod_id);
        if ($landing_prod) {
            $landing_qty = !empty($posted_data['quantity']) ? max(1, absint($posted_data['quantity'])) : 1;
            $landing_price = (float) $landing_prod->get_price() * $landing_qty;
            $cart_details_array[] = [
                'product_id'   => $landing_prod->get_id(),
                'variation_id' => 0,
                'name'         => $landing_prod->get_name(),
                'quantity'     => $landing_qty,
                'line_total'   => $landing_price,
            ];
            $cart_total_value = $landing_price;
        }
    }

    if (!empty($cart_details_array)) {
        $data_to_save['cart_details'] = wp_json_encode($cart_details_array);
        $data_to_save['cart_value'] = $cart_total_value;
    }

    // 5. Progressive Enhancement: Do not wipe out previously saved fields if user typed them earlier
    if ($existing_record_id) {
        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d", $existing_record_id), ARRAY_A);
        if ($existing) {
            foreach (['email', 'first_name', 'last_name', 'phone', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'cart_details', 'cart_value', 'device_fingerprint'] as $col) {
                if (empty($data_to_save[$col]) && !empty($existing[$col])) {
                    $data_to_save[$col] = $existing[$col];
                }
            }
        }

        $result = $wpdb->update(
            $table_name,
            $data_to_save,
            ['id' => $existing_record_id],
            cg_get_db_formats($data_to_save),
            ['%d']
        );
        if ($result !== false) {
            wp_send_json_success(['message' => 'Checkout data updated.', 'record_id' => $existing_record_id]);
        } else {
            wp_send_json_error(['message' => 'Failed to update checkout data.']);
        }
    } else {
        $data_to_save['created_at'] = current_time('mysql');
        $data_to_save['status'] = 'incomplete';
        if (!isset($data_to_save['cart_details'])) {
            $data_to_save['cart_details'] = wp_json_encode([]);
            $data_to_save['cart_value'] = 0.00;
        }

        // Free contact reveals (1.5.0): decide before the insert, save the
        // record no matter what, then count it. The record is revealed only
        // after HQ confirmed the count; any failure leaves it masked.
        $reveal_decision = function_exists('cg_contact_reveal_decide')
            ? cg_contact_reveal_decide()
            : ['count' => false, 'until' => null];

        $result = $wpdb->insert(
            $table_name,
            $data_to_save,
            cg_get_db_formats($data_to_save)
        );
        if ($result !== false) {
            $new_record_id = (int) $wpdb->insert_id;
            cg_increment_usage_count('checkouts');
            if (function_exists('cg_contact_reveal_commit')) {
                cg_contact_reveal_commit($new_record_id, $reveal_decision);
            }
            $wpdb->insert_id = $new_record_id;
            wp_send_json_success(['message' => 'Checkout data saved.', 'record_id' => $wpdb->insert_id]);
        } else {
            wp_send_json_error(['message' => 'Failed to save new checkout data.']);
        }
    }

    wp_die();
}


/**
 * Handles the AJAX request to get incomplete checkout details.
 * Returns a modern card-based layout with full courier data.
 */
function cg_get_incomplete_checkout_details_ajax_handler()
{
    check_ajax_referer('cg_view_details_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => __('Permission denied.', 'checkoutguard')));
        return;
    }

    if (!isset($_POST['entry_id']) || !is_numeric($_POST['entry_id'])) {
        wp_send_json_error(array('message' => __('Invalid ID provided.', 'checkoutguard')));
        return;
    }
    $entry_id = intval($_POST['entry_id']);

    global $wpdb;
    $table_name = $wpdb->prefix . 'cg_incomplete_checkouts';
    $entry = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d", $entry_id));

    if (!$entry) {
        wp_send_json_error(array('message' => __('Entry not found.', 'checkoutguard')));
        return;
    }

    $full_name = trim(esc_html($entry->first_name) . ' ' . esc_html($entry->last_name));
    $status_label = ucfirst($entry->status);

    ob_start();
    ?>
    <div class="cg-detail-popup">
        <div class="cg-detail-header">
            <div class="cg-detail-header-info">
                <h2 class="cg-detail-name"><?php echo $full_name ?: __('Unknown Customer', 'checkoutguard'); ?></h2>
                <span class="cg-detail-id">#<?php echo esc_html($entry->id); ?></span>
            </div>
            <span class="cg-detail-status status-<?php echo esc_attr($entry->status); ?>"><?php echo esc_html($status_label); ?></span>
        </div>

        <?php $cg_show_contact = cg_can_show_contact($entry); ?>
        <div class="cg-detail-grid">
            <?php if (!$cg_show_contact): ?>
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
                            <?php if (!$cg_show_contact && !empty($entry->email)): ?>
                                <span class="cg-pro-masked" data-cg-tooltip="<?php esc_attr_e('Upgrade to Pro to see full email', 'checkoutguard'); ?>">
                                    <?php echo esc_html(cg_mask_email($entry->email)); ?>
                                </span>
                            <?php else: ?>
                                <?php echo esc_html($entry->email ?: 'N/A'); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><?php _e('Phone', 'checkoutguard'); ?></td>
                        <td>
                            <?php if (!$cg_show_contact && !empty($entry->phone)): ?>
                                <span class="cg-pro-masked" data-cg-tooltip="<?php esc_attr_e('Upgrade to Pro to see full number', 'checkoutguard'); ?>">
                                    <?php echo esc_html(cg_mask_phone($entry->phone)); ?>
                                </span>
                            <?php else: ?>
                                <?php echo esc_html($entry->phone ?: 'N/A'); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr><td><?php _e('IP Address', 'checkoutguard'); ?></td><td><?php echo esc_html($entry->ip_address ?: 'N/A'); ?></td></tr>
                    <tr><td><?php _e('Captured', 'checkoutguard'); ?></td><td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($entry->created_at))); ?></td></tr>
                    <tr><td><?php _e('Updated', 'checkoutguard'); ?></td><td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($entry->updated_at))); ?></td></tr>
                    <?php if ($entry->status === 'recovered' && !empty($entry->recovered_order_id)): ?>
                        <tr>
                            <td><?php _e('Recovered Order', 'checkoutguard'); ?></td>
                            <td>
                                <?php
                                $order_edit_link = get_edit_post_link($entry->recovered_order_id);
                                if ($order_edit_link) {
                                    echo '<a href="' . esc_url($order_edit_link) . '" target="_blank">#' . esc_html($entry->recovered_order_id) . '</a>';
                                } else {
                                    echo '#' . esc_html($entry->recovered_order_id);
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php if ($entry->status === 'hold' && $entry->follow_up_date): ?>
                        <tr><td><?php _e('Follow-up', 'checkoutguard'); ?></td><td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($entry->follow_up_date))); ?></td></tr>
                    <?php endif; ?>
                </table>
            </div>
            <?php if (!$cg_show_contact): ?>
            </div><!-- .cg-pro-locked-hover -->
            <?php endif; ?>

            <div class="cg-detail-card">
                <h4><?php _e('Billing Address', 'checkoutguard'); ?></h4>
                <div class="cg-detail-address">
                    <?php
                    $address_parts = array_filter([
                        $entry->address_1,
                        $entry->address_2,
                        implode(', ', array_filter([$entry->city, $entry->state])) . ($entry->postcode ? ' ' . $entry->postcode : ''),
                        $entry->country
                    ]);
                    echo nl2br(esc_html(implode("\n", $address_parts)));
                    ?>
                </div>
            </div>
        </div>

        <div class="cg-detail-card cg-detail-card-full">
            <h4>
                <?php _e('Customer Success Rating', 'checkoutguard'); ?>
                <a href="#" class="cg-popup-refresh-ratio" data-id="<?php echo esc_attr($entry->id); ?>" data-type="incomplete_checkout" title="<?php esc_attr_e('Refresh (clear cache)', 'checkoutguard'); ?>">
                    <span class="dashicons dashicons-update"></span>
                </a>
            </h4>
            <?php
            $phone = isset($entry->phone) ? trim($entry->phone) : '';
            $force_refresh = !empty($_POST['force_refresh']);
            if (empty($phone)):
            ?>
                <div class="cg-detail-notice cg-detail-notice-warning"><?php _e('No phone number available for this customer.', 'checkoutguard'); ?></div>
            <?php elseif (!preg_match('/^01[3-9]\d{8}$/', $phone)): ?>
                <div class="cg-detail-notice cg-detail-notice-warning"><?php echo sprintf(__('Phone number %s is not a valid Bangladeshi format.', 'checkoutguard'), '<strong>' . esc_html(!$cg_show_contact ? cg_mask_phone($phone) : $phone) . '</strong>'); ?></div>
            <?php else:
                $ratio_data = cg_perform_all_api_calls($phone, 0, $force_refresh);
                $summary = $ratio_data['summary'] ?? null;
                if ($summary):
                    $success_rate = (int) ($summary['success_rate'] ?? 0);
                    $risk_level = $summary['risk_level'] ?? 'N/A';
                    $risk_color = $summary['risk_color'] ?? '#757575';
                    $total_deliveries = (int) ($summary['total_deliveries'] ?? 0);
                    $total_success = (int) ($summary['successful_deliveries'] ?? 0);
                    $total_cancelled = (int) ($summary['cancelled_deliveries'] ?? 0);
                    $couriers = cg_get_supported_couriers();
            ?>
                <div class="cg-detail-ratio-summary">
                    <?php
                    $popup_cache_source = $ratio_data['cache_source'] ?? 'unknown';
                    $popup_badge_config = cg_get_cache_badge_config($popup_cache_source);
                    ?>
                    <span class="cg-cache-badge cg-cache-<?php echo esc_attr($popup_cache_source); ?>">
                        <span class="dashicons <?php echo esc_attr($popup_badge_config['icon']); ?>"></span>
                        <?php echo esc_html($popup_badge_config['label']); ?>
                    </span>
                    <div class="cg-detail-ratio-score">
                        <span class="cg-detail-ratio-percent" style="color: <?php echo esc_attr($risk_color); ?>;"><?php echo esc_html($success_rate); ?>%</span>
                        <span class="cg-detail-ratio-level" style="color: <?php echo esc_attr($risk_color); ?>;"><?php echo esc_html($risk_level); ?></span>
                    </div>
                    <div class="cg-detail-ratio-counts">
                        <span><?php _e('Total:', 'checkoutguard'); ?> <strong><?php echo esc_html($total_deliveries); ?></strong></span>
                        <span class="cg-text-success"><?php _e('Delivered:', 'checkoutguard'); ?> <strong><?php echo esc_html($total_success); ?></strong></span>
                        <span class="cg-text-danger"><?php _e('Cancelled:', 'checkoutguard'); ?> <strong><?php echo esc_html($total_cancelled); ?></strong></span>
                    </div>
                </div>
                <div class="cg-detail-ratio-bar">
                    <div class="cg-detail-ratio-bar-fill" style="width: <?php echo esc_attr($success_rate); ?>%; background-color: <?php echo esc_attr($risk_color); ?>;"></div>
                </div>
                <table class="cg-detail-courier-table">
                    <thead>
                        <tr>
                            <th><?php _e('Courier', 'checkoutguard'); ?></th>
                            <th><?php _e('Orders', 'checkoutguard'); ?></th>
                            <th><?php _e('Delivered', 'checkoutguard'); ?></th>
                            <th><?php _e('Cancelled', 'checkoutguard'); ?></th>
                            <th><?php _e('Rate', 'checkoutguard'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        foreach ($couriers as $key => $courier):
                            $default_data = cg_get_default_courier_result($key, true);
                            $data = $default_data;
                            if (isset($ratio_data[$key]) && is_array($ratio_data[$key])) {
                                $data = array_merge($default_data, $ratio_data[$key]);
                            }
                            $has_error = !empty($data['error']);
                            $is_estimated = !empty($data['estimated']);
                            $counts_unavailable = !empty($data['counts_unavailable']);
                            $hide_counts = $is_estimated || $counts_unavailable;
                        ?>
                        <tr>
                            <td class="cg-detail-courier-name">
                                <img src="<?php echo esc_url($courier['logo']); ?>" alt="<?php echo esc_attr($courier['name']); ?>">
                                <?php if ($key === 'pathao' && !empty($data['rating_label'])): ?>
                                    <small class="cg-detail-rating-badge"><?php echo esc_html($data['rating_label']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($has_error): ?>
                                    <span class="cg-text-muted">N/A</span>
                                <?php elseif ($is_estimated): ?>
                                    <span class="cg-text-muted">-</span>
                                <?php else: ?>
                                    <?php echo esc_html($data['total']); ?>
                                <?php endif; ?>
                                <?php if ($is_estimated): ?><span class="cg-detail-estimated" title="Estimated">*</span><?php endif; ?>
                            </td>
                            <td><?php echo $has_error ? '<span class="cg-text-muted">N/A</span>' : ($hide_counts ? '<span class="cg-text-muted">-</span>' : esc_html($data['success'])); ?></td>
                            <td><?php echo $has_error ? '<span class="cg-text-muted">Error</span>' : ($hide_counts ? '<span class="cg-text-muted">-</span>' : esc_html($data['cancelled'])); ?></td>
                            <td class="cg-detail-rate-cell"><?php echo $has_error ? '<span class="cg-text-muted">N/A</span>' : ($counts_unavailable ? '<span class="cg-text-muted">-</span>' : esc_html($data['success_rate']) . '%'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if (!empty($summary['note'])): ?>
                    <p class="cg-detail-note">* <?php echo esc_html($summary['note']); ?></p>
                <?php endif; ?>
            <?php else: ?>
                <div class="cg-detail-notice cg-detail-notice-error"><?php _e('Could not retrieve success rating data from the server.', 'checkoutguard'); ?></div>
            <?php endif; ?>
            <?php endif; ?>
        </div>

        <?php
        // Fraud Blocking buttons - check block status for each identifier
        if (!cg_is_pro_plan()): ?>
            <div class="cg-pro-locked-hover">
                <div class="cg-pro-hover-overlay">
                    <span class="dashicons dashicons-lock"></span>
                    <p><?php _e('Upgrade to Pro to unlock Fraud Blocking', 'checkoutguard'); ?></p>
                    <button type="button" class="button button-primary cg-open-upgrade-modal"><?php _e('Upgrade Now', 'checkoutguard'); ?></button>
                </div>
        <?php endif; ?>
        <?php
        // A masked record must not carry the full email or phone anywhere in
        // the HTML (the block buttons used to put them in data-value).
        cg_render_popup_block_buttons(
            $entry->ip_address ?? '',
            $cg_show_contact ? ($entry->email ?? '') : '',
            $cg_show_contact ? ($entry->phone ?? '') : '',
            $entry->device_fingerprint ?? '',
            $entry->address_1 ?? '',
            $entry->city ?? '',
            $entry->postcode ?? '',
            'checkout:' . (int) $entry->id
        );
        ?>
        <?php if (!cg_is_pro_plan()): ?>
            </div>
        <?php endif; ?>

        <div class="cg-detail-card cg-detail-card-full">
            <h4><?php _e('Cart Details', 'checkoutguard'); ?></h4>
            <?php
            $cart_items = json_decode($entry->cart_details, true);
            if (is_array($cart_items) && !empty($cart_items)):
            ?>
            <table class="cg-detail-cart-table">
                <thead>
                    <tr>
                        <th><?php _e('Product', 'checkoutguard'); ?></th>
                        <th><?php _e('Qty', 'checkoutguard'); ?></th>
                        <th><?php _e('Price', 'checkoutguard'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cart_items as $item): ?>
                    <tr>
                        <td><?php echo esc_html($item['name'] ?? __('Unknown Product', 'checkoutguard')); ?></td>
                        <td><?php echo esc_html($item['quantity'] ?? 0); ?></td>
                        <td><?php echo wp_kses_post(wc_price($item['line_total'] ?? 0)); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="2"><strong><?php _e('Cart Value', 'checkoutguard'); ?></strong></td>
                        <td><strong><?php echo wp_kses_post(wc_price($entry->cart_value)); ?></strong></td>
                    </tr>
                </tfoot>
            </table>
            <?php else: ?>
                <p class="cg-detail-empty"><?php _e('No cart items found.', 'checkoutguard'); ?></p>
            <?php endif; ?>
        </div>

        <?php if (!empty($entry->admin_notes)): ?>
        <div class="cg-detail-card cg-detail-card-full">
            <h4><?php _e('Admin Notes', 'checkoutguard'); ?></h4>
            <div class="cg-detail-notes"><?php echo nl2br(esc_html($entry->admin_notes)); ?></div>
        </div>
        <?php endif; ?>

        <?php if ($entry->status !== 'recovered'): ?>
        <?php if (!cg_is_pro_plan()): ?>
        <div class="cg-pro-locked-hover">
            <div class="cg-pro-hover-overlay">
                <span class="dashicons dashicons-lock" style="font-size:28px;width:28px;height:28px;line-height:28px;"></span>
                <p style="margin:0;font-weight:600;color:#1e293b;"><?php _e('Upgrade to Pro to use Action Buttons', 'checkoutguard'); ?></p>
                <button type="button" class="button button-primary cg-open-upgrade-modal"><?php _e('Upgrade Now', 'checkoutguard'); ?></button>
            </div>
        <?php endif; ?>
        <div class="cg-detail-actions">
            <?php if ($entry->status === 'incomplete'): ?>
                <a href="#" class="button button-primary cg-recover-order" data-id="<?php echo esc_attr($entry->id); ?>">
                    <span class="dashicons dashicons-yes"></span> <?php _e('Recover Order', 'checkoutguard'); ?>
                </a>
                <a href="#" class="button cg-mark-hold" data-id="<?php echo esc_attr($entry->id); ?>">
                    <span class="dashicons dashicons-clock"></span> <?php _e('Mark as Hold', 'checkoutguard'); ?>
                </a>
                <a href="#" class="button cg-mark-cancelled" data-id="<?php echo esc_attr($entry->id); ?>">
                    <span class="dashicons dashicons-no-alt"></span> <?php _e('Mark as Cancelled', 'checkoutguard'); ?>
                </a>
            <?php elseif ($entry->status === 'hold'): ?>
                <a href="#" class="button button-primary cg-recover-order" data-id="<?php echo esc_attr($entry->id); ?>">
                    <span class="dashicons dashicons-yes"></span> <?php _e('Recover Order', 'checkoutguard'); ?>
                </a>
                <a href="#" class="button cg-edit-follow-up-date" data-id="<?php echo esc_attr($entry->id); ?>" data-current-date="<?php echo esc_attr($entry->follow_up_date); ?>">
                    <span class="dashicons dashicons-edit"></span> <?php _e('Edit Follow-up Date', 'checkoutguard'); ?>
                </a>
                <a href="#" class="button cg-mark-cancelled" data-id="<?php echo esc_attr($entry->id); ?>">
                    <span class="dashicons dashicons-no-alt"></span> <?php _e('Mark as Cancelled', 'checkoutguard'); ?>
                </a>
            <?php elseif ($entry->status === 'cancelled'): ?>
                <a href="#" class="button cg-reopen-checkout" data-id="<?php echo esc_attr($entry->id); ?>">
                    <span class="dashicons dashicons-undo"></span> <?php _e('Re-open to Incomplete', 'checkoutguard'); ?>
                </a>
            <?php endif; ?>
        </div>
        <?php if (!cg_is_pro_plan()): ?>
        </div><!-- .cg-pro-locked-hover -->
        <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
    $html_output = ob_get_clean();
    wp_send_json_success(array('html' => $html_output));
    wp_die();
}



/**
 * Handles the AJAX request to recover an incomplete checkout as a WooCommerce order.
 */
function cg_recover_order_ajax_handler()
{
    check_ajax_referer('cg_recover_order_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'checkoutguard')));
        return;
    }
    if (!cg_is_pro_plan()) {
        wp_send_json_error(array('message' => __('This feature requires a Pro plan. Please upgrade to access it.', 'checkoutguard')), 403);
        return;
    }

    if (!isset($_POST['entry_id']) || !is_numeric($_POST['entry_id'])) {
        wp_send_json_error(array('message' => __('Invalid ID provided.', 'checkoutguard')));
        return;
    }
    $entry_id = intval($_POST['entry_id']);

    global $wpdb;
    $table_name = $wpdb->prefix . 'cg_incomplete_checkouts';
    $entry = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d", $entry_id));

    if (!$entry) {
        wp_send_json_error(array('message' => __('Incomplete checkout entry not found.', 'checkoutguard')));
        return;
    }

    if (!in_array($entry->status, array('incomplete', 'hold'))) {
        wp_send_json_error(array('message' => __('This entry cannot be recovered. Current status: ', 'checkoutguard') . $entry->status));
        return;
    }

    try {
        $order_data = array(
            'status' => 'wc-processing',
            'customer_id' => $entry->user_id ? $entry->user_id : 0,
        );
        $order = wc_create_order($order_data);

        if (is_wp_error($order)) {
            throw new Exception(__('Error creating WooCommerce order: ', 'checkoutguard') . $order->get_error_message());
        }

        $billing_address = array(
            'first_name' => $entry->first_name,
            'last_name' => $entry->last_name,
            'company' => '',
            'email' => $entry->email,
            'phone' => $entry->phone,
            'address_1' => $entry->address_1,
            'address_2' => $entry->address_2,
            'city' => $entry->city,
            'state' => $entry->state,
            'postcode' => $entry->postcode,
            'country' => $entry->country,
        );
        $order->set_address($billing_address, 'billing');

        $cart_items = json_decode($entry->cart_details, true);
        if (is_array($cart_items) && !empty($cart_items)) {
            foreach ($cart_items as $item_data) {
                $product_id_to_load = !empty($item_data['variation_id']) ? $item_data['variation_id'] : ($item_data['product_id'] ?? 0);
                $product = wc_get_product($product_id_to_load);
                if ($product) {
                    $order->add_product($product, !empty($item_data['quantity']) ? (int) $item_data['quantity'] : 1);
                }
            }
        } else {
            throw new Exception(__('No cart items found in the incomplete checkout entry.', 'checkoutguard'));
        }

        $order->update_meta_data('_cg_recovered_from', $entry_id);
        $order->update_meta_data('_cg_validated', '1');
        $order->calculate_totals();
        $order_id = $order->save();

        $order->add_order_note(
            sprintf(__('Order created from Incomplete Checkout ID %d by %s. Related WC Order ID: #%d', 'checkoutguard'), $entry_id, wp_get_current_user()->display_name, $order_id),
            false,
            true
        );

        $update_data = array(
            'status' => 'recovered',
            'recovered_order_id' => $order_id,
            'updated_at' => current_time('mysql'),
            'admin_notes' => trim($entry->admin_notes . "\n" . sprintf(__('Recovered as WC Order #%d on %s.', 'checkoutguard'), $order_id, current_time('mysql')))
        );

        $wpdb->update(
            $table_name,
            $update_data,
            array('id' => $entry_id),
            cg_get_db_formats($update_data),
            array('%d')
        );

        wp_send_json_success(array(
            'message' => sprintf(__('Order #%s created successfully from incomplete checkout ID %d and set to Processing.', 'checkoutguard'), $order_id, $entry_id),
            'order_id' => $order_id,
            'edit_order_url' => admin_url('post.php?post=' . $order_id . '&action=edit')
        ));

    } catch (Exception $e) {
        wp_send_json_error(array('message' => $e->getMessage()));
    }
    wp_die();
}


/**
 * Handles the AJAX request to mark an incomplete checkout as 'hold'.
 */
function cg_mark_hold_ajax_handler()
{
    check_ajax_referer('cg_mark_hold_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'checkoutguard')));
        return;
    }
    if (!cg_is_pro_plan()) {
        wp_send_json_error(array('message' => __('This feature requires a Pro plan. Please upgrade to access it.', 'checkoutguard')), 403);
        return;
    }

    if (!isset($_POST['entry_id']) || !is_numeric($_POST['entry_id'])) {
        wp_send_json_error(array('message' => __('Invalid ID provided.', 'checkoutguard')));
        return;
    }
    $entry_id = intval($_POST['entry_id']);

    if (!isset($_POST['follow_up_date'])) {
        wp_send_json_error(array('message' => __('Follow-up date not provided.', 'checkoutguard')));
        return;
    }

    $follow_up_date_str = sanitize_text_field(wp_unslash($_POST['follow_up_date']));
    $follow_up_date = null;

    if (!empty($follow_up_date_str)) {
        if (!preg_match("/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])$/", $follow_up_date_str)) {
            wp_send_json_error(array('message' => __('Invalid date format. Please use<y_bin_46>-MM-DD.', 'checkoutguard')));
            return;
        }
        try {
            $date_obj = new DateTime($follow_up_date_str);
            $follow_up_date = $date_obj->format('Y-m-d');
        } catch (Exception $e) {
            wp_send_json_error(array('message' => __('Invalid date provided.', 'checkoutguard')));
            return;
        }
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'cg_incomplete_checkouts';
    $entry_status = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$table_name} WHERE id = %d", $entry_id));

    if (!$entry_status) {
        wp_send_json_error(array('message' => __('Incomplete checkout entry not found.', 'checkoutguard')));
        return;
    }

    if ($entry_status !== 'incomplete') {
        wp_send_json_error(array('message' => __('This entry is not marked as incomplete. Current status: ', 'checkoutguard') . $entry_status));
        return;
    }

    $result = $wpdb->update(
        $table_name,
        array(
            'status' => 'hold',
            'follow_up_date' => $follow_up_date,
            'updated_at' => current_time('mysql')
        ),
        array('id' => $entry_id),
        array('%s', '%s', '%s'),
        array('%d')
    );

    if ($result !== false) {
        wp_send_json_success(array(
            'message' => sprintf(__('Entry ID %d marked as "Hold" with follow-up date: %s.', 'checkoutguard'), $entry_id, ($follow_up_date ? $follow_up_date : 'Not Set'))
        ));
    } else {
        wp_send_json_error(array('message' => __('Failed to mark entry as hold.', 'checkoutguard')));
    }
    wp_die();
}


/**
 * Handles the AJAX request to mark an incomplete checkout as 'cancelled'.
 */
function cg_mark_cancelled_ajax_handler()
{
    check_ajax_referer('cg_mark_cancelled_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'checkoutguard')));
        return;
    }
    if (!cg_is_pro_plan()) {
        wp_send_json_error(array('message' => __('This feature requires a Pro plan. Please upgrade to access it.', 'checkoutguard')), 403);
        return;
    }

    if (!isset($_POST['entry_id']) || !is_numeric($_POST['entry_id'])) {
        wp_send_json_error(array('message' => __('Invalid ID provided.', 'checkoutguard')));
        return;
    }
    $entry_id = intval($_POST['entry_id']);

    global $wpdb;
    $table_name = $wpdb->prefix . 'cg_incomplete_checkouts';
    $entry_status = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$table_name} WHERE id = %d", $entry_id));

    if (!$entry_status) {
        wp_send_json_error(array('message' => __('Incomplete checkout entry not found.', 'checkoutguard')));
        return;
    }

    if (!in_array($entry_status, array('incomplete', 'hold'))) {
        wp_send_json_error(array('message' => __('This entry cannot be cancelled. Current status: ', 'checkoutguard') . $entry_status));
        return;
    }

    $result = $wpdb->update(
        $table_name,
        array(
            'status' => 'cancelled',
            'updated_at' => current_time('mysql')
        ),
        array('id' => $entry_id),
        array('%s', '%s'),
        array('%d')
    );

    if ($result !== false) {
        wp_send_json_success(array(
            'message' => sprintf(__('Entry ID %d marked as "Cancelled".', 'checkoutguard'), $entry_id)
        ));
    } else {
        wp_send_json_error(array('message' => __('Failed to mark entry as cancelled.', 'checkoutguard')));
    }
    wp_die();
}


/**
 * Handles the AJAX request to edit the follow-up date for a 'hold' entry.
 */
function cg_edit_follow_up_date_ajax_handler()
{
    check_ajax_referer('cg_edit_follow_up_date_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'checkoutguard')));
        return;
    }
    if (!cg_is_pro_plan()) {
        wp_send_json_error(array('message' => __('This feature requires a Pro plan. Please upgrade to access it.', 'checkoutguard')), 403);
        return;
    }

    if (!isset($_POST['entry_id']) || !is_numeric($_POST['entry_id'])) {
        wp_send_json_error(array('message' => __('Invalid ID provided.', 'checkoutguard')));
        return;
    }
    $entry_id = intval($_POST['entry_id']);

    if (!isset($_POST['new_follow_up_date'])) {
        wp_send_json_error(array('message' => __('New follow-up date not provided.', 'checkoutguard')));
        return;
    }

    $new_follow_up_date_str = sanitize_text_field(wp_unslash($_POST['new_follow_up_date']));
    $new_follow_up_date_for_db = null;

    if (!empty($new_follow_up_date_str)) {
        if (!preg_match("/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])$/", $new_follow_up_date_str)) {
            wp_send_json_error(array('message' => __('Invalid date format. Please use<y_bin_46>-MM-DD or leave empty to clear.', 'checkoutguard')));
            return;
        }
        try {
            $date_obj = new DateTime($new_follow_up_date_str);
            $new_follow_up_date_for_db = $date_obj->format('Y-m-d');
        } catch (Exception $e) {
            wp_send_json_error(array('message' => __('Invalid date provided after format check.', 'checkoutguard')));
            return;
        }
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'cg_incomplete_checkouts';
    $entry = $wpdb->get_row($wpdb->prepare("SELECT id, status FROM {$table_name} WHERE id = %d", $entry_id));

    if (!$entry) {
        wp_send_json_error(array('message' => __('Entry not found.', 'checkoutguard')));
        return;
    }

    if ($entry->status !== 'hold') {
        wp_send_json_error(array('message' => __('This entry is not marked as "Hold". Cannot edit follow-up date.', 'checkoutguard')));
        return;
    }

    $result = $wpdb->update(
        $table_name,
        array(
            'follow_up_date' => $new_follow_up_date_for_db,
            'updated_at' => current_time('mysql')
        ),
        array('id' => $entry_id),
        array('%s', '%s'),
        array('%d')
    );

    if ($result !== false) {
        wp_send_json_success(array(
            'message' => __('Follow-up date updated successfully.', 'checkoutguard'),
            'new_date_formatted' => $new_follow_up_date_for_db ? date_i18n(get_option('date_format'), strtotime($new_follow_up_date_for_db)) : __('N/A', 'checkoutguard')
        ));
    } else {
        wp_send_json_error(array('message' => __('Failed to update follow-up date.', 'checkoutguard')));
    }
    wp_die();
}


/**
 * Handles the AJAX request to re-open a 'cancelled' checkout back to 'incomplete'.
 */
function cg_reopen_checkout_ajax_handler()
{
    check_ajax_referer('cg_reopen_checkout_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'checkoutguard')));
        return;
    }
    if (!cg_is_pro_plan()) {
        wp_send_json_error(array('message' => __('This feature requires a Pro plan. Please upgrade to access it.', 'checkoutguard')), 403);
        return;
    }

    if (!isset($_POST['entry_id']) || !is_numeric($_POST['entry_id'])) {
        wp_send_json_error(array('message' => __('Invalid ID provided.', 'checkoutguard')));
        return;
    }
    $entry_id = intval($_POST['entry_id']);

    global $wpdb;
    $table_name = $wpdb->prefix . 'cg_incomplete_checkouts';
    $entry = $wpdb->get_row($wpdb->prepare("SELECT id, status FROM {$table_name} WHERE id = %d", $entry_id));

    if (!$entry) {
        wp_send_json_error(array('message' => __('Entry not found.', 'checkoutguard')));
        return;
    }

    if ($entry->status !== 'cancelled') {
        wp_send_json_error(array('message' => __('This entry is not marked as "Cancelled". Cannot re-open.', 'checkoutguard')));
        return;
    }

    $update_data = array(
        'status' => 'incomplete',
        'follow_up_date' => null,
        'recovered_order_id' => null,
        'updated_at' => current_time('mysql')
    );

    $formats = array('%s', null, null, '%s');

    $result = $wpdb->update(
        $table_name,
        $update_data,
        array('id' => $entry_id),
        $formats,
        array('%d')
    );

    if ($result !== false) {
        wp_send_json_success(array(
            'message' => sprintf(__('Entry ID %d has been re-opened to "Incomplete".', 'checkoutguard'), $entry_id)
        ));
    } else {
        wp_send_json_error(array('message' => __('Failed to re-open entry.', 'checkoutguard')));
    }
    wp_die();
}



/**
 * Handles the AJAX request to fetch dashboard data.
 */
function cg_fetch_dashboard_data_ajax_handler()
{
    check_ajax_referer('cg_fetch_dashboard_data_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => __('Permission denied.', 'checkoutguard')));
        return;
    }

    $range = isset($_POST['range']) ? sanitize_text_field(wp_unslash($_POST['range'])) : 'today';
    $start_date_str = isset($_POST['start_date']) ? sanitize_text_field(wp_unslash($_POST['start_date'])) : null;
    $end_date_str = isset($_POST['end_date']) ? sanitize_text_field(wp_unslash($_POST['end_date'])) : null;

    $results = cg_fetch_entries_by_status_and_date_range(null, $range, $start_date_str, $end_date_str, 'created_at', true);

    $stats = array(
        'incomplete' => array('count' => 0, 'value' => 0.00),
        'recovered' => array('count' => 0, 'value' => 0.00),
        'hold' => array('count' => 0, 'value' => 0.00),
        'cancelled' => array('count' => 0, 'value' => 0.00),
    );

    if ($results) {
        foreach ($results as $row) {
            if (isset($stats[$row->status])) {
                $stats[$row->status]['count'] = (int) $row->count;
                $stats[$row->status]['value'] = (float) $row->total_value;
            }
        }
    }

    foreach ($stats as $status_key => $data) {
        $stats[$status_key]['value_formatted'] = wc_price($data['value']);
    }

    // Trend data: daily counts grouped by status for the selected date range
    global $wpdb;
    $table_name = $wpdb->prefix . 'cg_incomplete_checkouts';
    $trend_data = [];

    // Determine actual date bounds for trend query
    $current_time = current_time('timestamp');
    $today_date = date('Y-m-d', $current_time);
    $trend_start = $today_date;
    $trend_end = $today_date;

    if ($range === 'custom' && $start_date_str && $end_date_str) {
        $trend_start = $start_date_str;
        $trend_end = $end_date_str;
    } else {
        switch ($range) {
            case 'yesterday':
                $trend_start = date('Y-m-d', strtotime('-1 day', $current_time));
                $trend_end = $trend_start;
                break;
            case '7days':
                $trend_start = date('Y-m-d', strtotime('-6 days', $current_time));
                break;
            case '30days':
                $trend_start = date('Y-m-d', strtotime('-29 days', $current_time));
                break;
            case 'all':
                $trend_start = null;
                $trend_end = null;
                break;
        }
    }

    if ($trend_start && $trend_end) {
        $trend_results = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(created_at) as date, status, COUNT(*) as count
             FROM {$table_name}
             WHERE DATE(created_at) BETWEEN %s AND %s
             GROUP BY DATE(created_at), status
             ORDER BY date ASC",
            $trend_start,
            $trend_end
        ));
    } else {
        // For "all" range, get last 30 days of trend data
        $trend_results = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(created_at) as date, status, COUNT(*) as count
             FROM {$table_name}
             WHERE DATE(created_at) >= %s
             GROUP BY DATE(created_at), status
             ORDER BY date ASC",
            date('Y-m-d', strtotime('-29 days', $current_time))
        ));
    }

    if ($trend_results) {
        foreach ($trend_results as $row) {
            $trend_data[] = [
                'date'   => $row->date,
                'status' => $row->status,
                'count'  => (int) $row->count,
            ];
        }
    }

    $stats['trend_data'] = $trend_data;

    wp_send_json_success($stats);
    wp_die();
}



/**
 * Core function to fetch entries based on status and date range.
 * Supports server-side pagination when $per_page > 0.
 *
 * @param string|null $status          Entry status filter (null = all statuses).
 * @param string      $range           Date range preset or 'custom'/'all'.
 * @param string|null $start_date_str  Custom start date (YYYY-MM-DD).
 * @param string|null $end_date_str    Custom end date (YYYY-MM-DD).
 * @param string      $date_field_to_filter  Which date column to filter on.
 * @param bool        $group_for_dashboard   If true, returns grouped counts by status.
 * @param int         $page            Page number (1-indexed). Only used when $per_page > 0.
 * @param int         $per_page        Items per page. 0 = no limit (backward compatible).
 * @param string      $search          Search term for filtering across name/email/phone/IP.
 * @param string      $sort_col        Column to sort by.
 * @param string      $sort_dir        Sort direction (ASC or DESC).
 * @return array|object[] When $per_page > 0: ['rows' => [], 'total' => N, 'total_pages' => N, 'page' => N].
 *                        When $per_page = 0: flat array of row objects (backward compatible).
 */
function cg_fetch_entries_by_status_and_date_range($status, $range, $start_date_str, $end_date_str, $date_field_to_filter = 'updated_at', $group_for_dashboard = false, $page = 1, $per_page = 0, $search = '', $sort_col = 'updated_at', $sort_dir = 'DESC')
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'cg_incomplete_checkouts';
    $where_clauses = array();

    // Whitelist allowed date fields to prevent SQL injection
    $allowed_date_fields = ['updated_at', 'created_at', 'follow_up_date', 'blocked_at'];
    if (!in_array($date_field_to_filter, $allowed_date_fields, true)) {
        $date_field_to_filter = 'updated_at';
    }

    // Always add status clause if not grouping for the dashboard
    if ($status !== null && !$group_for_dashboard) {
        $where_clauses[] = $wpdb->prepare("status = %s", $status);
    }

    $current_time = current_time('timestamp');
    $today_date = date('Y-m-d', $current_time);

    if ($range === 'custom' && $start_date_str && $end_date_str) {
        if (
            preg_match("/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])$/", $start_date_str) &&
            preg_match("/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])$/", $end_date_str)
        ) {

            $where_clauses[] = $wpdb->prepare("DATE({$date_field_to_filter}) >= %s", $start_date_str);
            $where_clauses[] = $wpdb->prepare("DATE({$date_field_to_filter}) <= %s", $end_date_str);
        } else {
            $range = 'all';
        }
    }

    if ($range !== 'custom' && $range !== 'all') {
        $start_range_date = $today_date;
        $end_range_date = $today_date;

        switch ($range) {
            case 'yesterday':
                $start_range_date = date('Y-m-d', strtotime('-1 day', $current_time));
                $end_range_date = $start_range_date;
                break;
            case '7days':
                $start_range_date = date('Y-m-d', strtotime('-6 days', $current_time));
                break;
            case '30days':
                $start_range_date = date('Y-m-d', strtotime('-29 days', $current_time));
                break;
            case 'next7days':
                $end_range_date = date('Y-m-d', strtotime('+6 days', $current_time));
                break;
            case 'next30days':
                $end_range_date = date('Y-m-d', strtotime('+29 days', $current_time));
                break;
            case 'today':
            default:
                // Dates are already set to today
                break;
        }
        $where_clauses[] = $wpdb->prepare("DATE({$date_field_to_filter}) BETWEEN %s AND %s", $start_range_date, $end_range_date);
    }

    // Search filter
    if (!empty($search) && !$group_for_dashboard) {
        $like = '%' . $wpdb->esc_like($search) . '%';
        $where_clauses[] = $wpdb->prepare(
            "(first_name LIKE %s OR last_name LIKE %s OR email LIKE %s OR phone LIKE %s OR ip_address LIKE %s)",
            $like, $like, $like, $like, $like
        );
    }

    $where_sql_string = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

    if ($group_for_dashboard) {
        // For dashboard, we apply date filtering but group by status
        $query = "SELECT status, COUNT(*) as count, SUM(cart_value) as total_value FROM {$table_name} {$where_sql_string} GROUP BY status";
        return $wpdb->get_results($query);
    }

    // Whitelist allowed sort columns to prevent SQL injection
    $allowed_sort_cols = ['id', 'first_name', 'last_name', 'email', 'phone', 'ip_address', 'cart_value', 'created_at', 'updated_at', 'follow_up_date'];
    if (!in_array($sort_col, $allowed_sort_cols, true)) {
        $sort_col = 'updated_at';
    }
    $sort_dir = strtoupper($sort_dir) === 'ASC' ? 'ASC' : 'DESC';

    // Build ORDER BY
    if ($sort_col === 'updated_at' && $date_field_to_filter === 'follow_up_date' && $status === 'hold') {
        $order_by = "follow_up_date ASC, updated_at DESC";
    } else {
        $order_by = "{$sort_col} {$sort_dir}";
    }

    // Paginated mode
    if ($per_page > 0) {
        $page = max(1, (int) $page);
        $per_page = max(1, min(100, (int) $per_page));
        $offset = ($page - 1) * $per_page;

        // Count total matching rows
        $count_query = "SELECT COUNT(*) FROM {$table_name} {$where_sql_string}";
        $total = (int) $wpdb->get_var($count_query);
        $total_pages = max(1, (int) ceil($total / $per_page));

        // Clamp page
        if ($page > $total_pages) {
            $page = $total_pages;
            $offset = ($page - 1) * $per_page;
        }

        $query = "SELECT * FROM {$table_name} {$where_sql_string} ORDER BY {$order_by} LIMIT {$per_page} OFFSET {$offset}";
        $rows = $wpdb->get_results($query);

        return [
            'rows'        => $rows,
            'total'       => $total,
            'total_pages' => $total_pages,
            'page'        => $page,
        ];
    }

    // Non-paginated mode (backward compatible)
    $query = "SELECT * FROM {$table_name} {$where_sql_string} ORDER BY {$order_by}";
    return $wpdb->get_results($query);
}


/**
 * Generic AJAX handler for fetching and returning table rows for a given status.
 * Supports server-side pagination, search, and sorting via POST params.
 */
function cg_generic_fetch_entries_ajax_handler($status, $nonce_action, $date_field_to_filter)
{
    check_ajax_referer($nonce_action, 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => __('Permission denied.', 'checkoutguard')]);
        return;
    }

    $range = isset($_POST['range']) ? sanitize_text_field(wp_unslash($_POST['range'])) : 'all';
    $start_date = isset($_POST['start_date']) ? sanitize_text_field(wp_unslash($_POST['start_date'])) : null;
    $end_date = isset($_POST['end_date']) ? sanitize_text_field(wp_unslash($_POST['end_date'])) : null;

    // Pagination params (optional — when absent, behaves as before)
    $page = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
    $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 0;
    $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
    $sort_col = isset($_POST['sort_col']) ? sanitize_key($_POST['sort_col']) : 'updated_at';
    $sort_dir = isset($_POST['sort_dir']) ? sanitize_key($_POST['sort_dir']) : 'DESC';

    $results = cg_fetch_entries_by_status_and_date_range(
        $status, $range, $start_date, $end_date, $date_field_to_filter, false,
        $page, $per_page, $search, $sort_col, $sort_dir
    );

    // Paginated response
    if ($per_page > 0 && is_array($results) && isset($results['rows'])) {
        $html = cg_get_checkout_table_rows_html($results['rows'], $status);
        wp_send_json_success([
            'html'        => $html,
            'count'       => count($results['rows']),
            'total'       => $results['total'],
            'page'        => $results['page'],
            'per_page'    => $per_page,
            'total_pages' => $results['total_pages'],
        ]);
    } else {
        // Non-paginated (backward compatible)
        $rows = is_array($results) && isset($results['rows']) ? $results['rows'] : $results;
        $html = cg_get_checkout_table_rows_html($rows, $status);
        $count = count($rows);
        wp_send_json_success(['html' => $html, 'count' => $count]);
    }
    wp_die();
}


// Hook the generic handler to specific actions
function cg_fetch_incomplete_entries_ajax_handler()
{
    cg_generic_fetch_entries_ajax_handler('incomplete', 'cg_fetch_incomplete_nonce', 'updated_at');
}

function cg_fetch_recovered_entries_ajax_handler()
{
    if (!cg_is_pro_plan()) {
        check_ajax_referer('cg_fetch_recovered_nonce', 'nonce');
        wp_send_json_error(array('message' => __('This feature requires a Pro plan.', 'checkoutguard')), 403);
        return;
    }
    cg_generic_fetch_entries_ajax_handler('recovered', 'cg_fetch_recovered_nonce', 'updated_at');
}

function cg_fetch_cancelled_entries_ajax_handler()
{
    if (!cg_is_pro_plan()) {
        check_ajax_referer('cg_fetch_cancelled_nonce', 'nonce');
        wp_send_json_error(array('message' => __('This feature requires a Pro plan.', 'checkoutguard')), 403);
        return;
    }
    cg_generic_fetch_entries_ajax_handler('cancelled', 'cg_fetch_cancelled_nonce', 'updated_at');
}

function cg_fetch_follow_up_entries_ajax_handler()
{
    if (!cg_is_pro_plan()) {
        check_ajax_referer('cg_fetch_follow_up_nonce', 'nonce');
        wp_send_json_error(array('message' => __('This feature requires a Pro plan.', 'checkoutguard')), 403);
        return;
    }
    cg_generic_fetch_entries_ajax_handler('hold', 'cg_fetch_follow_up_nonce', 'follow_up_date');
}

