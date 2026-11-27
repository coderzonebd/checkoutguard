<?php
// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}


/**
 * Renders the Checkouts page with tabs for Incomplete, Follow Up, Cancelled, Recovered, and Blocked Orders.
 */
function cg_render_incomplete_checkouts_page()
{
    $license_status = get_option('cg_license_status', 'inactive');
    $is_active = ($license_status !== 'inactive' && $license_status !== 'suspended');

    // Pre-load data for the Incomplete tab only (others lazy-load via AJAX)
    $initial_incomplete = $is_active
        ? cg_fetch_entries_by_status_and_date_range('incomplete', 'today', null, null, 'updated_at')
        : [];
    ?>
    <div class="wrap cg-incomplete-wrap">
        <div class="cg-page-header">
            <h1><?php _e('Checkouts', 'checkoutguard'); ?></h1>
            <button type="button" class="cg-dark-mode-toggle" title="<?php esc_attr_e('Toggle Dark Mode', 'checkoutguard'); ?>">
                <span class="dashicons dashicons-admin-appearance"></span>
            </button>
        </div>

        <nav class="nav-tab-wrapper cg-checkout-tabs" role="tablist" aria-label="<?php esc_attr_e('Checkout tabs', 'checkoutguard'); ?>">
            <a href="#" class="nav-tab nav-tab-active" data-tab="incomplete" role="tab" aria-selected="true" aria-controls="tabpanel-incomplete"><?php _e('Incomplete', 'checkoutguard'); ?></a>
            <a href="#" class="nav-tab<?php echo cg_is_pro_plan() ? '' : ' cg-pro-locked-tab'; ?>" data-tab="hold" role="tab" aria-selected="false" aria-controls="tabpanel-hold">
                <?php _e('Follow Up', 'checkoutguard'); ?>
                <?php if (!cg_is_pro_plan()): ?>
                    <span class="dashicons dashicons-lock cg-pro-tab-lock" data-cg-tooltip="<?php esc_attr_e('Upgrade to Pro to unlock', 'checkoutguard'); ?>"></span>
                <?php endif; ?>
            </a>
            <a href="#" class="nav-tab<?php echo cg_is_pro_plan() ? '' : ' cg-pro-locked-tab'; ?>" data-tab="cancelled" role="tab" aria-selected="false" aria-controls="tabpanel-cancelled">
                <?php _e('Cancelled', 'checkoutguard'); ?>
                <?php if (!cg_is_pro_plan()): ?>
                    <span class="dashicons dashicons-lock cg-pro-tab-lock" data-cg-tooltip="<?php esc_attr_e('Upgrade to Pro to unlock', 'checkoutguard'); ?>"></span>
                <?php endif; ?>
            </a>
            <a href="#" class="nav-tab<?php echo cg_is_pro_plan() ? '' : ' cg-pro-locked-tab'; ?>" data-tab="recovered" role="tab" aria-selected="false" aria-controls="tabpanel-recovered">
                <?php _e('Recovered', 'checkoutguard'); ?>
                <?php if (!cg_is_pro_plan()): ?>
                    <span class="dashicons dashicons-lock cg-pro-tab-lock" data-cg-tooltip="<?php esc_attr_e('Upgrade to Pro to unlock', 'checkoutguard'); ?>"></span>
                <?php endif; ?>
            </a>
            <a href="#" class="nav-tab<?php echo cg_is_pro_plan() ? '' : ' cg-pro-locked-tab'; ?>" data-tab="blocked_orders" role="tab" aria-selected="false" aria-controls="tabpanel-blocked_orders">
                <?php _e('Blocked Orders', 'checkoutguard'); ?>
                <?php if (!cg_is_pro_plan()): ?>
                    <span class="dashicons dashicons-lock cg-pro-tab-lock" data-cg-tooltip="<?php esc_attr_e('Upgrade to Pro to unlock', 'checkoutguard'); ?>"></span>
                <?php endif; ?>
            </a>
        </nav>

        <?php // --- Tab: Incomplete --- ?>
        <div class="cg-tab-pane" data-tab-content="incomplete" id="tabpanel-incomplete" role="tabpanel">
            <p><?php _e('Checkouts that were started but not completed.', 'checkoutguard'); ?></p>
            <?php cg_render_date_filter_controls('incomplete', 'today', 'updated_at', true); ?>
            <div id="cg-incomplete-loading" class="cg-table-loader" style="display:none;">
                <div class="cg-spinner"></div>
                <p><?php _e('Loading entries...', 'checkoutguard'); ?></p>
            </div>
            <div id="cg-incomplete-table-container">
                <?php cg_render_checkouts_table($initial_incomplete, 'incomplete'); ?>
            </div>
            <div id="cg-incomplete-pagination" class="cg-pagination-container"></div>
        </div>

        <?php // --- Tab: Follow Up --- ?>
        <div class="cg-tab-pane<?php echo cg_is_pro_plan() ? '' : ' cg-pro-locked-hover'; ?>" data-tab-content="hold" style="display:none;" id="tabpanel-hold" role="tabpanel">
            <?php if (!cg_is_pro_plan()): ?>
                <div class="cg-pro-hover-overlay">
                    <span class="dashicons dashicons-lock"></span>
                    <p><?php _e('Upgrade to Pro to access Follow Up', 'checkoutguard'); ?></p>
                    <button type="button" class="button button-primary cg-open-upgrade-modal"><?php _e('Upgrade Now', 'checkoutguard'); ?></button>
                </div>
            <?php endif; ?>
            <p><?php _e('Incomplete checkouts marked as "Hold" for future follow-up, ordered by follow-up date.', 'checkoutguard'); ?></p>
            <?php cg_render_date_filter_controls('hold', 'today', 'follow_up_date', true, true); ?>
            <div id="cg-hold-loading" class="cg-table-loader" style="display:none;">
                <div class="cg-spinner"></div>
                <p><?php _e('Loading entries...', 'checkoutguard'); ?></p>
            </div>
            <div id="cg-hold-table-container">
                <?php cg_render_checkouts_table([], 'hold'); ?>
            </div>
            <div id="cg-hold-pagination" class="cg-pagination-container"></div>
        </div>

        <?php // --- Tab: Cancelled --- ?>
        <div class="cg-tab-pane<?php echo cg_is_pro_plan() ? '' : ' cg-pro-locked-hover'; ?>" data-tab-content="cancelled" style="display:none;" id="tabpanel-cancelled" role="tabpanel">
            <?php if (!cg_is_pro_plan()): ?>
                <div class="cg-pro-hover-overlay">
                    <span class="dashicons dashicons-lock"></span>
                    <p><?php _e('Upgrade to Pro to access Cancelled Checkouts', 'checkoutguard'); ?></p>
                    <button type="button" class="button button-primary cg-open-upgrade-modal"><?php _e('Upgrade Now', 'checkoutguard'); ?></button>
                </div>
            <?php endif; ?>
            <p><?php _e('Incomplete checkouts that have been marked as "Cancelled".', 'checkoutguard'); ?></p>
            <?php cg_render_date_filter_controls('cancelled', 'today', 'updated_at', true); ?>
            <div id="cg-cancelled-loading" class="cg-table-loader" style="display:none;">
                <div class="cg-spinner"></div>
                <p><?php _e('Loading entries...', 'checkoutguard'); ?></p>
            </div>
            <div id="cg-cancelled-table-container">
                <?php cg_render_checkouts_table([], 'cancelled'); ?>
            </div>
            <div id="cg-cancelled-pagination" class="cg-pagination-container"></div>
        </div>

        <?php // --- Tab: Recovered --- ?>
        <div class="cg-tab-pane<?php echo cg_is_pro_plan() ? '' : ' cg-pro-locked-hover'; ?>" data-tab-content="recovered" style="display:none;" id="tabpanel-recovered" role="tabpanel">
            <?php if (!cg_is_pro_plan()): ?>
                <div class="cg-pro-hover-overlay">
                    <span class="dashicons dashicons-lock"></span>
                    <p><?php _e('Upgrade to Pro to access Recovered Checkouts', 'checkoutguard'); ?></p>
                    <button type="button" class="button button-primary cg-open-upgrade-modal"><?php _e('Upgrade Now', 'checkoutguard'); ?></button>
                </div>
            <?php endif; ?>
            <p><?php _e('Incomplete checkouts that have been successfully recovered as WooCommerce orders.', 'checkoutguard'); ?></p>
            <?php cg_render_date_filter_controls('recovered', 'today', 'updated_at', true); ?>
            <div id="cg-recovered-loading" class="cg-table-loader" style="display:none;">
                <div class="cg-spinner"></div>
                <p><?php _e('Loading entries...', 'checkoutguard'); ?></p>
            </div>
            <div id="cg-recovered-table-container">
                <?php cg_render_checkouts_table([], 'recovered'); ?>
            </div>
            <div id="cg-recovered-pagination" class="cg-pagination-container"></div>
        </div>

        <?php // --- Tab: Blocked Orders --- ?>
        <div class="cg-tab-pane<?php echo cg_is_pro_plan() ? '' : ' cg-pro-locked-hover'; ?>" data-tab-content="blocked_orders" style="display:none;" id="tabpanel-blocked_orders" role="tabpanel">
            <?php if (!cg_is_pro_plan()): ?>
                <div class="cg-pro-hover-overlay">
                    <span class="dashicons dashicons-lock"></span>
                    <p><?php _e('Upgrade to Pro to access Blocked Orders', 'checkoutguard'); ?></p>
                    <button type="button" class="button button-primary cg-open-upgrade-modal"><?php _e('Upgrade Now', 'checkoutguard'); ?></button>
                </div>
            <?php endif; ?>
            <p><?php _e('Checkout attempts that were automatically blocked due to a low success ratio.', 'checkoutguard'); ?></p>
            <?php cg_render_date_filter_controls('blocked_orders', 'today', 'blocked_at', true); ?>
            <div id="cg-blocked_orders-loading" class="cg-table-loader" style="display:none;">
                <div class="cg-spinner"></div>
                <p><?php _e('Loading entries...', 'checkoutguard'); ?></p>
            </div>
            <div id="cg-blocked_orders-table-container">
                <?php cg_render_blocked_orders_table_shell(); ?>
            </div>
            <div id="cg-blocked_orders-pagination" class="cg-pagination-container"></div>
        </div>

        <?php cg_render_details_modal_html(); ?>
        <?php cg_render_upgrade_modal_html(); ?>
    </div>
    <?php
}



/**
 * Renders the common table structure for different checkout statuses.
 */
function cg_render_checkouts_table($results, $current_status = 'incomplete')
{
    $table_id = 'cg-table-' . $current_status;
    $tbody_id = 'cg-table-body-' . $current_status;
    $empty_message_id = $tbody_id . '-empty-message';
    $is_empty = empty($results);
    ?>
    <div id="<?php echo esc_attr($empty_message_id); ?>" class="cg-table-empty-message" <?php if (!$is_empty)
           echo 'style="display:none;"'; ?>>
        <p><?php printf(__('No checkouts found with status "%s" for the selected criteria.', 'checkoutguard'), esc_html($current_status)); ?>
        </p>
    </div>
    <?php // START of the change ?>
    <div class="cg-table-responsive-wrapper">
        <table class="wp-list-table widefat fixed striped <?php echo esc_attr($table_id); ?>" <?php if ($is_empty)
               echo 'style="display:none;"'; ?>>
            <thead>
                <tr>
                    <th class="cg-sortable" data-sort-col="id"><?php _e('ID', 'checkoutguard'); ?></th>

                    <?php // START of Change: Swapped Name and Email ?>
                    <th class="cg-sortable" data-sort-col="first_name"><?php _e('Name', 'checkoutguard'); ?></th>
                    <th class="cg-sortable" data-sort-col="email"><?php _e('Email', 'checkoutguard'); ?></th>
                    <?php // END of Change ?>

                    <th><?php _e('Phone', 'checkoutguard'); ?></th>
                    <th><?php _e('IP Address', 'checkoutguard'); ?></th>
                    <?php if ($current_status === 'hold'): ?>
                        <th class="cg-sortable" data-sort-col="follow_up_date"><?php _e('Follow-up Date', 'checkoutguard'); ?></th>
                    <?php elseif ($current_status === 'recovered'): ?>
                        <th><?php _e('Recovered Order', 'checkoutguard'); ?></th>
                    <?php else: ?>
                        <th><?php _e('Address', 'checkoutguard'); ?></th>
                    <?php endif; ?>
                    <th class="cg-sortable" data-sort-col="cart_value"><?php _e('Cart Value', 'checkoutguard'); ?></th>
                    <th><?php _e('Cart Items', 'checkoutguard'); ?></th>
                    <th class="cg-sortable" data-sort-col="updated_at"><?php _e('Last Updated', 'checkoutguard'); ?></th>
                    <th><?php _e('Actions', 'checkoutguard'); ?></th>
                </tr>
            </thead>
            <tbody id="<?php echo esc_attr($tbody_id); ?>">
                <?php echo cg_get_checkout_table_rows_html($results, $current_status); ?>
            </tbody>
        </table>
    </div>
<?php // END of the change ?>
<?php
}



/**
 * Helper function to generate HTML for table rows (tbody content).
 */
function cg_get_checkout_table_rows_html($results, $current_status)
{
    ob_start();
    if (!empty($results)) {
        foreach ($results as $row): ?>
            <?php $cg_show_contact = cg_can_show_contact($row); ?>
            <tr id="cg-entry-row-<?php echo esc_attr($row->id); ?>">
                <?php // START of Change: Reordered cells and made Name clickable ?>
                <td><?php echo esc_html($row->id); ?></td>
                <td>
                    <?php
                    $full_name = trim($row->first_name . ' ' . $row->last_name);
                    if (empty($full_name)) {
                        $full_name = __('(No Name Provided)', 'checkoutguard');
                    }
                    ?>
                    <a href="#" class="cg-view-details" data-id="<?php echo esc_attr($row->id); ?>"
                        title="<?php printf(esc_attr__('View details for %s', 'checkoutguard'), esc_attr($full_name)); ?>">
                        <strong><?php echo esc_html($full_name); ?></strong>
                    </a>
                </td>
                <td>
                    <?php if (!$cg_show_contact && !empty($row->email)): ?>
                        <span class="cg-pro-masked" data-cg-tooltip="<?php esc_attr_e('Upgrade to Pro to see full email', 'checkoutguard'); ?>"><?php echo esc_html(cg_mask_email($row->email)); ?></span>
                    <?php else: ?>
                        <?php echo esc_html($row->email); ?>
                    <?php endif; ?>
                </td>

                <td>
                    <?php if (!$cg_show_contact && !empty($row->phone)): ?>
                        <span class="cg-pro-masked" data-cg-tooltip="<?php esc_attr_e('Upgrade to Pro to see full number', 'checkoutguard'); ?>"><?php echo esc_html(cg_mask_phone($row->phone)); ?></span>
                    <?php else: ?>
                        <?php echo esc_html($row->phone); ?>
                    <?php endif; ?>
                </td>
                <td><?php echo esc_html($row->ip_address); ?></td>
                <?php if ($current_status === 'hold'): ?>
                    <td class="cg-follow-up-date-cell-<?php echo esc_attr($row->id); ?>">
                        <?php echo esc_html($row->follow_up_date ? date_i18n(get_option('date_format'), strtotime($row->follow_up_date)) : __('N/A', 'checkoutguard')); ?>
                    </td>
                <?php elseif ($current_status === 'recovered'): ?>
                    <td>
                        <?php
                        if (!empty($row->recovered_order_id)) {
                            $order_edit_link = get_edit_post_link($row->recovered_order_id);
                            if ($order_edit_link) {
                                printf('<a href="%s" target="_blank">#%s</a>', esc_url($order_edit_link), esc_html($row->recovered_order_id));
                            } else {
                                echo '#' . esc_html($row->recovered_order_id);
                            }
                        } else {
                            _e('N/A', 'checkoutguard');
                        }
                        ?>
                    </td>
                <?php else: ?>
                    <td>
                        <?php
                        $address_parts = array_filter([
                            $row->address_1,
                            $row->city,
                            $row->postcode,
                        ]);
                        echo esc_html(implode(', ', $address_parts));
                        ?>
                    </td>
                <?php endif; ?>
                <td><?php echo wp_kses_post(wc_price($row->cart_value)); ?></td>
                <td>
                    <?php
                    $cart_items = json_decode($row->cart_details, true);
                    $item_count = is_array($cart_items) ? count($cart_items) : 0;
                    echo esc_html($item_count) . ' ' . _n('item', 'items', $item_count, 'checkoutguard');
                    ?>
                </td>
                <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($row->updated_at))); ?>
                </td>
                <td class="cg-actions-cell">
                    <?php
                    printf('<a href="#" class="button button-small cg-view-details" data-id="%d" title="%s"><span class="dashicons dashicons-visibility"></span></a> ', esc_attr($row->id), __('View Details', 'checkoutguard'));

                    if ($current_status === 'incomplete') {
                        printf('<a href="#" class="button button-small button-primary cg-recover-order" data-id="%d" title="%s"><span class="dashicons dashicons-yes"></span></a> ', esc_attr($row->id), esc_attr(__('Recover Order', 'checkoutguard') . ' - ' . __('May reduce stock and send the customer the order email.', 'checkoutguard')));
                        printf('<a href="#" class="button button-small cg-mark-hold" data-id="%d" title="%s"><span class="dashicons dashicons-clock"></span></a> ', esc_attr($row->id), __('Mark as Hold', 'checkoutguard'));
                        printf('<a href="#" class="button button-small cg-mark-cancelled" data-id="%d" title="%s"><span class="dashicons dashicons-no-alt"></span></a>', esc_attr($row->id), __('Mark as Cancelled', 'checkoutguard'));
                    } elseif ($current_status === 'hold') {
                        printf('<a href="#" class="button button-small button-primary cg-recover-order" data-id="%d" title="%s"><span class="dashicons dashicons-yes"></span></a> ', esc_attr($row->id), esc_attr(__('Recover Order', 'checkoutguard') . ' - ' . __('May reduce stock and send the customer the order email.', 'checkoutguard')));
                        printf('<a href="#" class="button button-small cg-edit-follow-up-date" data-id="%d" data-current-date="%s" title="%s"><span class="dashicons dashicons-edit"></span></a> ', esc_attr($row->id), esc_attr($row->follow_up_date), __('Edit Follow-up Date', 'checkoutguard'));
                        printf('<a href="#" class="button button-small cg-mark-cancelled" data-id="%d" title="%s"><span class="dashicons dashicons-no-alt"></span></a>', esc_attr($row->id), __('Mark as Cancelled', 'checkoutguard'));
                    } elseif ($current_status === 'cancelled') {
                        printf('<a href="#" class="button button-small cg-reopen-checkout" data-id="%d" title="%s"><span class="dashicons dashicons-undo"></span></a>', esc_attr($row->id), __('Re-open to Incomplete', 'checkoutguard'));
                    }
                    ?>
                </td>
            </tr>
        <?php endforeach;
    }
    return ob_get_clean();
}

