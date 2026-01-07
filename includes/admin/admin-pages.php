<?php
// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

// Security Headers removed - they cause 'headers already sent' errors
// WordPress handles security headers appropriately

// Backward compatibility: some legacy Pro builds/hooks referenced this callback.
// Keep a shim here so the admin never fatals, even if Pro is partially loaded.
if (!function_exists('checkoutguard_pro_render_incomplete_checkouts_page')) {
    function checkoutguard_pro_render_incomplete_checkouts_page()
    {
        if (function_exists('doing_action') && doing_action('admin_menu')) {
            return;
        }

        if (function_exists('checkoutguard_render_incomplete_checkouts_page')) {
            checkoutguard_render_incomplete_checkouts_page();
        }
    }
}

/**
 * Renders the newly designed page for Incomplete Checkouts with tabs.
 */
function checkoutguard_render_incomplete_checkouts_page()
{
    static $checkoutguard_incomplete_rendered = false;
    if ($checkoutguard_incomplete_rendered) {
        return;
    }
    $checkoutguard_incomplete_rendered = true;

    $is_pro_active = defined('CHECKOUTGUARD_PRO_VERSION');
    $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'incomplete';

    ?>
    <div class="wrap checkoutguard-incomplete-wrap">
        <h1>
            <span class="dashicons dashicons-cart" style="font-size: 32px; width: 32px; height: 32px;"></span>
            <?php esc_html_e('Checkouts Management', 'checkoutguard'); ?>
        </h1>
        <p>
            <?php esc_html_e('Manage all your checkout statuses in one place.', 'checkoutguard'); ?>
        </p>

        <!-- Tabs Navigation -->
        <h2 class="nav-tab-wrapper">
            <a href="<?php echo esc_url(admin_url('admin.php?page=checkoutguard-incomplete-checkouts&tab=incomplete')); ?>" 
               class="nav-tab <?php echo $active_tab === 'incomplete' ? 'nav-tab-active' : ''; ?>">
                <span class="dashicons dashicons-cart"></span>
                <?php esc_html_e('Incomplete', 'checkoutguard'); ?>
            </a>

            <?php if ($is_pro_active): ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=checkoutguard-incomplete-checkouts&tab=recovered')); ?>" 
                   class="nav-tab <?php echo $active_tab === 'recovered' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-yes-alt"></span>
                    <?php esc_html_e('Recovered', 'checkoutguard'); ?>
                </a>

                <a href="<?php echo esc_url(admin_url('admin.php?page=checkoutguard-incomplete-checkouts&tab=hold')); ?>" 
                   class="nav-tab <?php echo $active_tab === 'hold' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-clock"></span>
                    <?php esc_html_e('On Hold', 'checkoutguard'); ?>
                </a>

                <a href="<?php echo esc_url(admin_url('admin.php?page=checkoutguard-incomplete-checkouts&tab=cancelled')); ?>" 
                   class="nav-tab <?php echo $active_tab === 'cancelled' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-dismiss"></span>
                    <?php esc_html_e('Cancelled', 'checkoutguard'); ?>
                </a>
            <?php else: ?>
                <!-- Locked tabs for free version -->
                <a href="https://coderzonebd.com/pricing" target="_blank" class="nav-tab checkoutguard-locked-tab">
                    <span class="dashicons dashicons-yes-alt"></span>
                    <?php esc_html_e('Recovered', 'checkoutguard'); ?>
                    <span class="checkoutguard-lock-icon">🔒</span>
                </a>

                <a href="https://coderzonebd.com/pricing" target="_blank" class="nav-tab checkoutguard-locked-tab">
                    <span class="dashicons dashicons-clock"></span>
                    <?php esc_html_e('On Hold', 'checkoutguard'); ?>
                    <span class="checkoutguard-lock-icon">🔒</span>
                </a>

                <a href="https://coderzonebd.com/pricing" target="_blank" class="nav-tab checkoutguard-locked-tab">
                    <span class="dashicons dashicons-dismiss"></span>
                    <?php esc_html_e('Cancelled', 'checkoutguard'); ?>
                    <span class="checkoutguard-lock-icon">🔒</span>
                </a>
            <?php endif; ?>
        </h2>

        <!-- Tab Content -->
        <div class="checkoutguard-tab-content">
            <?php
            // Render active tab content
            switch ($active_tab) {
                case 'incomplete':
                    checkoutguard_render_incomplete_tab_content();
                    break;
                
                case 'recovered':
                    if ($is_pro_active && function_exists('checkoutguard_pro_render_recovered_tab_content')) {
                        checkoutguard_pro_render_recovered_tab_content();
                    } else {
                        checkoutguard_render_locked_tab('recovered');
                    }
                    break;
                
                case 'hold':
                    if ($is_pro_active && function_exists('checkoutguard_pro_render_hold_tab_content')) {
                        checkoutguard_pro_render_hold_tab_content();
                    } else {
                        checkoutguard_render_locked_tab('hold');
                    }
                    break;
                
                case 'cancelled':
                    if ($is_pro_active && function_exists('checkoutguard_pro_render_cancelled_tab_content')) {
                        checkoutguard_pro_render_cancelled_tab_content();
                    } else {
                        checkoutguard_render_locked_tab('cancelled');
                    }
                    break;
                
                default:
                    checkoutguard_render_incomplete_tab_content();
            }
            ?>
        </div>
    </div>
    <?php
}

/**
 * Render Incomplete Tab Content
 */
function checkoutguard_render_incomplete_tab_content()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'checkoutguard_incomplete_checkouts';

    // Query for stats (last 24 hours)
    $stats = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT COUNT(id) as count, SUM(cart_value) as value FROM {$table_name} WHERE status = 'incomplete' AND created_at >= %s",
            date('Y-m-d H:i:s', strtotime('-1 day'))
        )
    );

    // Get initial results for today
    $initial_results = checkoutguard_fetch_incomplete_by_date_range('today');
    
    ?>
    <!-- Stats Row -->
    <div class="checkoutguard-stat-row">
        <div class="checkoutguard-stat-box stat-incomplete">
            <h3><?php esc_html_e('Last 24 Hours', 'checkoutguard'); ?></h3>
            <p><?php echo esc_html($stats->count ?? 0); ?></p>
            <div class="checkoutguard-stat-subtext"><?php esc_html_e('Incomplete Carts', 'checkoutguard'); ?></div>
        </div>
        <div class="checkoutguard-stat-box stat-recovered">
            <h3><?php esc_html_e('Cart Value (24h)', 'checkoutguard'); ?></h3>
            <p><?php echo wc_price($stats->value ?? 0); ?></p>
            <div class="checkoutguard-stat-subtext"><?php esc_html_e('Potential Revenue', 'checkoutguard'); ?></div>
        </div>
        <div class="checkoutguard-stat-box stat-hold">
            <h3><?php esc_html_e('Total Incomplete', 'checkoutguard'); ?></h3>
            <p><?php echo esc_html(count($initial_results)); ?></p>
            <div class="checkoutguard-stat-subtext"><?php esc_html_e('All Carts', 'checkoutguard'); ?></div>
        </div>
        <?php if (!defined('CHECKOUTGUARD_PRO_VERSION')): ?>
        <div class="checkoutguard-stat-box stat-cancelled">
            <h3><?php esc_html_e('Upgrade Available', 'checkoutguard'); ?></h3>
            <p>PRO</p>
            <div class="checkoutguard-stat-subtext">
                <a href="https://coderzonebd.com/pricing" target="_blank" class="checkoutguard-stat-box-link">
                    <?php esc_html_e('Get More Features', 'checkoutguard'); ?> →
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php checkoutguard_render_date_filter_controls_free('incomplete', 'today'); ?>

    <!-- Data Table Card -->
    <div class="checkoutguard-table-responsive-wrapper">
        <div style="padding: 20px; border-bottom: 1px solid var(--checkoutguard-card-border);">
            <h2 style="margin: 0; font-size: 18px; display: flex; align-items: center; gap: 10px;">
                <span class="dashicons dashicons-list-view"></span>
                <?php esc_html_e('All Incomplete Checkouts', 'checkoutguard'); ?>
            </h2>
        </div>
        <div id="checkoutguard-incomplete-loading" class="checkoutguard-table-loader" style="display:none; text-align:center; padding:20px;">
            <p><?php _e('Loading entries...', 'checkoutguard'); ?></p>
        </div>
        <div id="checkoutguard-incomplete-table-container">
            <?php checkoutguard_render_checkouts_table_new_design($initial_results); ?>
        </div>
    </div>

    <?php checkoutguard_render_details_modal_html(); ?>
    <?php
}

/**
 * Render Locked Tab (for free version)
 */
function checkoutguard_render_locked_tab($tab_name)
{
    $tab_titles = [
        'recovered' => __('Recovered Checkouts', 'checkoutguard'),
        'hold' => __('Hold Checkouts', 'checkoutguard'),
        'cancelled' => __('Cancelled Checkouts', 'checkoutguard')
    ];

    $tab_descriptions = [
        'recovered' => __('View and manage checkouts that have been successfully recovered and converted to orders.', 'checkoutguard'),
        'hold' => __('Manage checkouts that are on hold with follow-up dates for customer contact.', 'checkoutguard'),
        'cancelled' => __('View cancelled checkouts and reopen them if needed.', 'checkoutguard')
    ];

    $tab_features = [
        'recovered' => [
            __('Track recovered revenue and conversion rates', 'checkoutguard'),
            __('Link to WooCommerce orders', 'checkoutguard'),
            __('View recovery timeline and history', 'checkoutguard'),
            __('Export recovered data for reporting', 'checkoutguard')
        ],
        'hold' => [
            __('Set follow-up dates for customer contact', 'checkoutguard'),
            __('Add notes and reminders', 'checkoutguard'),
            __('Recover or cancel from hold status', 'checkoutguard'),
            __('Track hold duration and outcomes', 'checkoutguard')
        ],
        'cancelled' => [
            __('View all cancelled checkouts', 'checkoutguard'),
            __('Reopen cancelled checkouts', 'checkoutguard'),
            __('Analyze cancellation reasons', 'checkoutguard'),
            __('Second-chance recovery options', 'checkoutguard')
        ]
    ];

    ?>
    <div class="checkoutguard-locked-content">
        <div class="checkoutguard-locked-overlay">
            <div class="checkoutguard-locked-card">
                <span class="checkoutguard-lock-icon-large">🔒</span>
                <h2><?php echo esc_html($tab_titles[$tab_name] ?? ''); ?></h2>
                <p class="description"><?php echo esc_html($tab_descriptions[$tab_name] ?? ''); ?></p>
                
                <div class="checkoutguard-features-list">
                    <h3><?php esc_html_e('Pro Features Include:', 'checkoutguard'); ?></h3>
                    <ul>
                        <?php foreach ($tab_features[$tab_name] ?? [] as $feature): ?>
                            <li>
                                <span class="dashicons dashicons-yes-alt"></span>
                                <?php echo esc_html($feature); ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <a href="https://coderzonebd.com/pricing" target="_blank" class="button button-primary button-hero">
                    <span class="dashicons dashicons-unlock"></span>
                    <?php esc_html_e('Upgrade to Pro', 'checkoutguard'); ?>
                </a>
                <p class="pricing-note">
                    <?php esc_html_e('Unlock all checkout management features', 'checkoutguard'); ?>
                </p>
            </div>
        </div>
    </div>
    <?php
}


/**
 * Renders the new card-style table for checkouts.
 */
function checkoutguard_render_checkouts_table_new_design($results)
{
    ?>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th><?php esc_html_e('Customer', 'checkoutguard'); ?></th>
                <th><?php esc_html_e('Address', 'checkoutguard'); ?></th>
                <th><?php esc_html_e('Cart', 'checkoutguard'); ?></th>
                <th><?php esc_html_e('Last Active', 'checkoutguard'); ?></th>
                <th><?php esc_html_e('Actions', 'checkoutguard'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php
            if (empty($results)) {
                echo '<tr><td colspan="5" class="checkoutguard-table-empty-message"><p>' . esc_html__('No incomplete checkouts found.', 'checkoutguard') . '</p></td></tr>';
            } else {
                foreach ($results as $row) {
                    $full_name = trim($row->first_name . ' ' . $row->last_name);
                    $cart_items = json_decode($row->cart_details, true);
                    ?>
                    <tr id="checkoutguard-entry-row-<?php echo esc_attr($row->id); ?>">
                        <td>
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <img src="<?php echo esc_url(get_avatar_url($row->email ?: 'unknown@example.com')); ?>" style="width: 40px; height: 40px; border-radius: 50%;" alt="Avatar">
                                <div>
                                    <a href="#" class="checkoutguard-view-details" style="font-weight: 700; color: var(--checkoutguard-text-primary); text-decoration: none;"
                                        data-id="<?php echo esc_attr($row->id); ?>"><?php echo esc_html($full_name ?: '(No Name)'); ?></a>
                                    <?php if (!CHECKOUTGUARD_IS_PRO): ?>
                                        <div style="font-size: 12px; color: var(--checkoutguard-text-light); margin-top: 4px;">
                                            <span class="dashicons dashicons-lock" style="font-size: 12px; width: 12px; height: 12px;"></span>
                                            <?php esc_html_e('Email hidden in free version', 'checkoutguard'); ?>
                                        </div>
                                    <?php else: ?>
                                        <div style="font-size: 12px; color: var(--checkoutguard-text-secondary); margin-top: 4px;"><?php echo esc_html($row->email ?: '(Not provided)'); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td>
                            <?php
                            $address_parts = array_filter([
                                $row->address_1,
                                $row->city,
                                $row->postcode,
                                $row->country
                            ]);
                            if (!empty($address_parts)) {
                                echo '<div style="display: flex; align-items: flex-start; gap: 6px;">';
                                echo '<span class="dashicons dashicons-location" style="color: var(--checkoutguard-text-light); margin-top: 2px;"></span>';
                                echo '<span>' . esc_html(implode(', ', $address_parts)) . '</span>';
                                echo '</div>';
                            } else {
                                echo '<span style="color: var(--checkoutguard-text-light); font-style: italic;">' . esc_html__('No address', 'checkoutguard') . '</span>';
                            }
                            ?>
                        </td>
                        <td>
                            <div>
                                <strong style="color: var(--checkoutguard-primary-color); font-size: 15px;"><?php echo wc_price($row->cart_value); ?></strong>
                                <?php if (is_array($cart_items) && !empty($cart_items)): ?>
                                    <ul style="margin: 4px 0 0; padding: 0; list-style: none; font-size: 12px; color: var(--checkoutguard-text-secondary);">
                                        <?php foreach (array_slice($cart_items, 0, 2) as $item): ?>
                                            <li>• <?php echo esc_html($item['quantity'] . 'x ' . $item['name']); ?></li>
                                        <?php endforeach; ?>
                                        <?php if (count($cart_items) > 2): ?>
                                            <li style="color: var(--checkoutguard-text-light);">+<?php echo (count($cart_items) - 2); ?> more</li>
                                        <?php endif; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td><?php echo esc_html(human_time_diff(strtotime($row->updated_at))) . ' ago'; ?></td>
                        <td class="checkoutguard-actions-cell">
                            <?php 
                            // WhatsApp button (added by Pro version via hook)
                            do_action('checkoutguard_admin_table_actions', $row); 
                            ?>
                            
                            <button class="button checkoutguard-view-details"
                                data-id="<?php echo esc_attr($row->id); ?>">
                                <span class="dashicons dashicons-visibility"></span>
                                <?php esc_html_e('Details', 'checkoutguard'); ?>
                            </button>
                        </td>
                    </tr>
                    <?php
                }
            }
            ?>
        </tbody>
    </table>
    <?php
}

/**
 * Renders the page for the free version's Fraud Blocker.
 */
function checkoutguard_render_fraud_blocker_page()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'checkoutguard'));
    }

    $ajax_nonce = wp_create_nonce('checkoutguard_fraud_blocker_nonce');
    ?>
    <div class="wrap checkoutguard-fraud-blocker-wrap">
        <h1>
            <span class="dashicons dashicons-shield" style="font-size: 32px; width: 32px; height: 32px;"></span>
            <?php esc_html_e('Fraud Blocker', 'checkoutguard'); ?>
        </h1>
        <p>
            <?php esc_html_e('Block specific phone numbers to prevent unwanted orders. There is no limit to the number of phone numbers you can block.', 'checkoutguard'); ?>
        </p>

        <div id="checkoutguard-blocker-messages" style="display:none;" class="notice is-dismissible"></div>

        <div class="checkoutguard-blocker-sections" style="grid-template-columns: 1fr;">
            <div class="checkoutguard-blocker-section">
                <h2><?php esc_html_e('Phone Number Blocker', 'checkoutguard'); ?></h2>
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
    </div>
    <?php
}


/**
 * Renders the common HTML structure for the details modal.
 */
function checkoutguard_render_details_modal_html()
{
    ?>
    <div id="checkoutguard-details-modal" class="checkoutguard-modal">
        <div class="checkoutguard-modal-content">
            <span class="checkoutguard-modal-close">&times;</span>
            <h2 style="padding: 20px 32px 0; margin: 0; font-size: 24px;"><?php esc_html_e('Checkout Details', 'checkoutguard'); ?></h2>
            <div id="checkoutguard-modal-body">
                <p><?php esc_html_e('Loading details...', 'checkoutguard'); ?></p>
            </div>
        </div>
    </div>
    <?php
}


/**
 * Renders the common table structure for checkouts.
 * Kept for backward compatibility but updated with new classes.
 */
function checkoutguard_render_checkouts_table($results, $current_status = 'incomplete')
{
    $is_empty = empty($results);
    ?>
    <div class="checkoutguard-table-responsive-wrapper">
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col" class="manage-column"><?php esc_html_e('Name', 'checkoutguard'); ?></th>
                    <th scope="col" class="manage-column"><?php esc_html_e('Email', 'checkoutguard'); ?></th>
                    <th scope="col" class="manage-column"><?php esc_html_e('Phone', 'checkoutguard'); ?></th>
                    <th scope="col" class="manage-column"><?php esc_html_e('Cart Value', 'checkoutguard'); ?></th>
                    <th scope="col" class="manage-column"><?php esc_html_e('Cart Items', 'checkoutguard'); ?></th>
                    <th scope="col" class="manage-column"><?php esc_html_e('Last Updated', 'checkoutguard'); ?></th>
                    <th scope="col" class="manage-column" style="width: 120px;">
                        <?php esc_html_e('Actions', 'checkoutguard'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php
                if ($is_empty) {
                    echo '<tr><td colspan="7" class="checkoutguard-table-empty-message"><p>' . esc_html__('No incomplete checkouts found.', 'checkoutguard') . '</p></td></tr>';
                } else {
                    echo wp_kses_post( checkoutguard_get_checkout_table_rows_html($results, $current_status) );
                }
                ?>
            </tbody>
        </table>
    </div>
    <?php
}


/**
 * Helper function to generate HTML for table rows (tbody content).
 */
function checkoutguard_get_checkout_table_rows_html($results, $current_status)
{
    ob_start();
    if (!empty($results)) {
        foreach ($results as $row): ?>
            <tr id="checkoutguard-entry-row-<?php echo esc_attr($row->id); ?>">
                <td>
                    <a href="#" class="checkoutguard-view-details" data-id="<?php echo esc_attr($row->id); ?>">
                        <strong><?php echo esc_html(trim($row->first_name . ' ' . $row->last_name) ?: esc_html__('(No Name)', 'checkoutguard')); ?></strong>
                    </a>
                </td>
                <td><?php echo esc_html($row->email); ?></td>
                <td>
                    <?php echo esc_html($row->phone); ?>
                </td>
                <td><?php echo wc_price($row->cart_value); ?></td>
                <td>
                    <?php
                    $cart_items = json_decode($row->cart_details, true);
                    $item_count = is_array($cart_items) ? count($cart_items) : 0;
                    echo esc_html( $item_count . ' ' . _n('item', 'items', $item_count, 'checkoutguard') );
                    ?>
                </td>
                <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($row->updated_at))); ?>
                </td>
                <td class="checkoutguard-actions-cell">
                    <a href="#" class="button button-small checkoutguard-view-details"
                        data-id="<?php echo esc_attr($row->id); ?>"><?php esc_html_e('View', 'checkoutguard'); ?></a>
                    <a href="#" class="button button-small checkoutguard-mark-cancelled"
                        data-id="<?php echo esc_attr($row->id); ?>"><?php esc_html_e('Cancel', 'checkoutguard'); ?></a>
                </td>
            </tr>
        <?php endforeach;
    }
    return ob_get_clean();
}

/**
 * Helper function to display a list of blocked items.
 */
function checkoutguard_display_blocked_items_list($type)
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'checkoutguard_blocked_numbers';
    $value_column = 'phone_number';

    // No placeholders needed for static ORDER BY clause
    $items = $wpdb->get_results(
        "SELECT * FROM {$table_name} ORDER BY created_at DESC"
    );

    echo '<ul class="checkoutguard-blocked-list" id="checkoutguard-blocked-list-' . esc_attr($type) . '">';
    if (empty($items)) {
        echo '<li class="checkoutguard-no-items checkoutguard-no-results">' . esc_html__('No phone numbers are currently blocked.', 'checkoutguard') . '</li>';
    } else {
        foreach ($items as $item) {
            echo checkoutguard_get_blocked_list_item_html($item, $value_column, $type);
        }
    }
    echo '</ul>';
}

/**
 * Helper function to generate HTML for a single blocked list item.
 */
function checkoutguard_get_blocked_list_item_html($item, $value_column, $type)
{
    ob_start();
    $delete_nonce = wp_create_nonce('checkoutguard_delete_blocked_item_nonce');
    ?>
    <li id="checkoutguard-blocked-item-<?php echo esc_attr($item->id); ?>">
        <div>
            <strong><?php echo esc_html($item->$value_column); ?></strong>
            <?php if (!empty($item->reason)): ?>
                <small><em><?php echo esc_html($item->reason); ?></em></small>
            <?php endif; ?>
        </div>
        <a href="#" class="checkoutguard-delete-item-ajax" title="Delete" data-item-id="<?php echo esc_attr($item->id); ?>"
            data-block-type="<?php echo esc_attr($type); ?>" data-nonce="<?php echo esc_attr($delete_nonce); ?>">
            <span class="dashicons dashicons-trash"></span>
        </a>
    </li>
    <?php
    return ob_get_clean();
}

/**
 * Fetch incomplete checkouts by date range
 */
function checkoutguard_fetch_incomplete_by_date_range($range, $start_date = null, $end_date = null) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'checkoutguard_incomplete_checkouts';
    $where_clauses = array("status = 'incomplete'");
    
    $current_time = current_time('timestamp');
    $today_date = date('Y-m-d', $current_time);
    
    if ($range === 'custom' && $start_date && $end_date) {
        if (
            preg_match("/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])$/", $start_date) &&
            preg_match("/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])$/", $end_date)
        ) {
            $where_clauses[] = $wpdb->prepare("DATE(created_at) >= %s", $start_date);
            $where_clauses[] = $wpdb->prepare("DATE(created_at) <= %s", $end_date);
        }
    } elseif ($range !== 'all') {
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
            case 'today':
            default:
                break;
        }
        $where_clauses[] = $wpdb->prepare("DATE(created_at) BETWEEN %s AND %s", $start_range_date, $end_range_date);
    }
    
    $where_sql = implode(' AND ', $where_clauses);
    $query = "SELECT * FROM {$table_name} WHERE {$where_sql} ORDER BY created_at DESC";
    
    return $wpdb->get_results($query);
}

/**
 * Date filter controls for free version
 */
function checkoutguard_render_date_filter_controls_free($page_slug_prefix, $default_active_range = 'today') {
    $filter_id_prefix = 'checkoutguard_' . $page_slug_prefix;
    ?>
    <div class="checkoutguard-dashboard-filters checkoutguard-list-page-filters <?php echo esc_attr($filter_id_prefix . '-filters'); ?>">
        <div class="checkoutguard-filter-buttons">
            <button class="button <?php echo esc_attr($filter_id_prefix . '-filter-btn'); ?> active" data-range="today"><?php _e('Today', 'checkoutguard'); ?></button>
            <button class="button <?php echo esc_attr($filter_id_prefix . '-filter-btn'); ?>" data-range="yesterday"><?php _e('Yesterday', 'checkoutguard'); ?></button>
            <button class="button <?php echo esc_attr($filter_id_prefix . '-filter-btn'); ?>" data-range="7days"><?php _e('Last 7 Days', 'checkoutguard'); ?></button>
            <button class="button <?php echo esc_attr($filter_id_prefix . '-filter-btn'); ?>" data-range="30days"><?php _e('Last 30 Days', 'checkoutguard'); ?></button>
            <button class="button <?php echo esc_attr($filter_id_prefix . '-filter-btn'); ?>" data-range="all"><?php _e('All', 'checkoutguard'); ?></button>
        </div>
        <div class="checkoutguard-date-range-filter">
            <label for="<?php echo esc_attr($filter_id_prefix . '_start_date'); ?>"><?php _e('From:', 'checkoutguard'); ?></label>
            <input type="date" id="<?php echo esc_attr($filter_id_prefix . '_start_date'); ?>" class="checkoutguard-date-input">
            <label for="<?php echo esc_attr($filter_id_prefix . '_end_date'); ?>"><?php _e('To:', 'checkoutguard'); ?></label>
            <input type="date" id="<?php echo esc_attr($filter_id_prefix . '_end_date'); ?>" class="checkoutguard-date-input">
            <button class="button button-primary <?php echo esc_attr($filter_id_prefix . '_apply_date_filter'); ?>"><?php _e('Filter', 'checkoutguard'); ?></button>
        </div>
    </div>
    <?php
}

// AJAX Handler for incomplete checkouts filtering
add_action('wp_ajax_checkoutguard_fetch_incomplete_entries', 'checkoutguard_ajax_fetch_incomplete_entries');

function checkoutguard_ajax_fetch_incomplete_entries() {
    check_ajax_referer('checkoutguard_fetch_incomplete_nonce', 'nonce');
    
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => __('Permission denied.', 'checkoutguard')], 403);
    }
    
    $range = isset($_POST['range']) ? sanitize_text_field(wp_unslash($_POST['range'])) : 'today';
    $start_date = isset($_POST['start_date']) ? sanitize_text_field(wp_unslash($_POST['start_date'])) : null;
    $end_date = isset($_POST['end_date']) ? sanitize_text_field(wp_unslash($_POST['end_date'])) : null;
    
    $results = checkoutguard_fetch_incomplete_by_date_range($range, $start_date, $end_date);
    
    ob_start();
    checkoutguard_render_checkouts_table_new_design($results);
    $html = ob_get_clean();
    
    wp_send_json_success(['html' => $html, 'count' => count($results)]);
    wp_die();
}

/**
 * Renders the Your Enquiry page
 */
function checkoutguard_render_report_page()
{
    // Handle form submission
    if (isset($_POST['submit_report']) && check_admin_referer('checkoutguard_submit_report')) {
        $message = isset($_POST['message']) ? sanitize_textarea_field(wp_unslash($_POST['message'])) : '';
        $report_type = isset($_POST['report_type']) ? sanitize_text_field(wp_unslash($_POST['report_type'])) : 'general';
        $additional_info = isset($_POST['additional_info']) ? sanitize_textarea_field(wp_unslash($_POST['additional_info'])) : '';
        
        if (empty($message)) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Please enter a message before submitting.', 'checkoutguard') . '</p></div>';
        } else {
            $result = checkoutguard_submit_plugin_report($message, $report_type, $additional_info);
            
            if ($result['success']) {
                echo '<div class="notice notice-success is-dismissible">';
                echo '<p><strong>' . esc_html__('Report submitted successfully!', 'checkoutguard') . '</strong></p>';
                if (isset($result['report_id'])) {
                    echo '<p>' . sprintf(esc_html__('Report ID: %d', 'checkoutguard'), $result['report_id']) . '</p>';
                }
                echo '</div>';
            } else {
                echo '<div class="notice notice-error is-dismissible">';
                echo '<p><strong>' . esc_html__('Failed to submit report:', 'checkoutguard') . '</strong></p>';
                echo '<p>' . esc_html($result['message']) . '</p>';
                echo '</div>';
            }
        }
    }
    
    ?>
    <div class="wrap checkoutguard-report-wrap">
        <h1>
            <span class="dashicons dashicons-megaphone" style="font-size: 32px; width: 32px; height: 32px;"></span>
            <?php esc_html_e('Your Enquiry', 'checkoutguard'); ?>
        </h1>
        <p class="description">
            <?php esc_html_e('Have an issue, feedback, or feature request? Let us know! We\'ll review your report and get back to you.', 'checkoutguard'); ?>
        </p>

        <div class="checkoutguard-report-card" style="background: #fff; padding: 20px; margin-top: 20px; border: 1px solid #ddd; border-radius: 4px; max-width: 800px;">
            <form method="post" action="">
                <?php wp_nonce_field('checkoutguard_submit_report'); ?>
                
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="report_type"><?php esc_html_e('Report Type', 'checkoutguard'); ?> <span class="required" style="color: red;">*</span></label>
                            </th>
                            <td>
                                <select name="report_type" id="report_type" class="regular-text" required>
                                    <option value="feature_request"><?php esc_html_e('Feature Request', 'checkoutguard'); ?></option>
                                    <option value="bug"><?php esc_html_e('Bug Report', 'checkoutguard'); ?></option>
                                    <option value="suggestion"><?php esc_html_e('Suggestion', 'checkoutguard'); ?></option>
                                    <option value="question"><?php esc_html_e('Question', 'checkoutguard'); ?></option>
                                    <option value="feedback"><?php esc_html_e('General Feedback', 'checkoutguard'); ?></option>
                                    <option value="other"><?php esc_html_e('Other', 'checkoutguard'); ?></option>
                                </select>
                                <p class="description">
                                    <?php esc_html_e('Select the type of report you\'re submitting.', 'checkoutguard'); ?>
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="message"><?php esc_html_e('Your Message', 'checkoutguard'); ?> <span class="required" style="color: red;">*</span></label>
                            </th>
                            <td>
                                <textarea name="message" id="message" rows="8" class="large-text" required 
                                    placeholder="<?php esc_attr_e('Describe your issue, feedback, or feature request...', 'checkoutguard'); ?>"></textarea>
                                <p class="description">
                                    <?php esc_html_e('Please provide as much detail as possible to help us understand and address your report.', 'checkoutguard'); ?>
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="additional_info"><?php esc_html_e('Additional Information', 'checkoutguard'); ?></label>
                            </th>
                            <td>
                                <textarea name="additional_info" id="additional_info" rows="4" class="large-text" 
                                    placeholder="<?php esc_attr_e('Any additional context, error messages, or steps to reproduce...', 'checkoutguard'); ?>"></textarea>
                                <p class="description">
                                    <?php esc_html_e('Optional: Include error codes, theme name, or other relevant details.', 'checkoutguard'); ?>
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>
                
                <div style="background: #f0f0f1; padding: 15px; border-radius: 4px; margin: 20px 0;">
                    <h3 style="margin-top: 0;"><?php esc_html_e('System Information (Automatically Included)', 'checkoutguard'); ?></h3>
                    <ul style="list-style: disc; padding-left: 20px;">
                        <li><?php echo sprintf(esc_html__('Site URL: %s', 'checkoutguard'), '<code>' . esc_html(get_site_url()) . '</code>'); ?></li>
                        <li><?php echo sprintf(esc_html__('Plugin Version: %s', 'checkoutguard'), '<code>' . esc_html(CHECKOUTGUARD_VERSION) . '</code>'); ?></li>
                        <li><?php echo sprintf(esc_html__('WordPress Version: %s', 'checkoutguard'), '<code>' . esc_html(get_bloginfo('version')) . '</code>'); ?></li>
                        <li><?php echo sprintf(esc_html__('PHP Version: %s', 'checkoutguard'), '<code>' . esc_html(phpversion()) . '</code>'); ?></li>
                        <li><?php echo sprintf(esc_html__('WooCommerce Version: %s', 'checkoutguard'), '<code>' . esc_html(defined('WC_VERSION') ? WC_VERSION : 'Not Available') . '</code>'); ?></li>
                        <li><?php echo sprintf(esc_html__('Active Theme: %s', 'checkoutguard'), '<code>' . esc_html(wp_get_theme()->get('Name')) . '</code>'); ?></li>
                        <li><?php echo sprintf(esc_html__('Total Users: %d', 'checkoutguard'), count_users()['total_users']); ?></li>
                    </ul>
                    <p class="description" style="margin-top: 10px;">
                        <?php esc_html_e('This information helps us diagnose issues more effectively. No sensitive data is collected.', 'checkoutguard'); ?>
                    </p>
                </div>
                
                <p class="submit">
                    <input type="submit" name="submit_report" id="submit_report" class="button button-primary button-hero" value="<?php esc_attr_e('Submit Report', 'checkoutguard'); ?>">
                    <span class="spinner" style="float: none; margin: 8px 10px;"></span>
                </p>
            </form>
        </div>

        <div style="margin-top: 30px; padding: 15px; background: #e7f3ff; border-left: 4px solid #0073aa; max-width: 800px;">
            <h3 style="margin-top: 0;"><?php esc_html_e('Need Immediate Help?', 'checkoutguard'); ?></h3>
            <p><?php esc_html_e('For urgent issues or general inquiries, you can also:', 'checkoutguard'); ?></p>
            <ul style="list-style: disc; padding-left: 20px;">
                <li><?php echo sprintf(esc_html__('Visit our website: %s', 'checkoutguard'), '<a href="https://coderzonebd.com" target="_blank">coderzonebd.com</a>'); ?></li>
                <li><?php echo sprintf(esc_html__('Contact support: %s', 'checkoutguard'), '<a href="https://coderzonebd.com/contact" target="_blank">Support Portal</a>'); ?></li>
            </ul>
        </div>
    </div>
    
    <style>
        .checkoutguard-report-wrap .required {
            color: #d63638;
        }
        .checkoutguard-report-card {
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .checkoutguard-report-wrap .button-hero {
            height: auto;
            line-height: 1.5;
            padding: 12px 24px;
            font-size: 14px;
        }
    </style>
    <?php
}

/**
 * Submit a report to the license server
 */
function checkoutguard_submit_plugin_report($message, $report_type = 'general', $additional_info = '')
{
    $api_url = 'https://coderzonebd.com/api/plugin-reports/submit';
    
    // Get all active plugins
    $all_plugins = get_plugins();
    $active_plugins = get_option('active_plugins', array());
    $plugin_names = array();
    
    foreach ($active_plugins as $plugin_path) {
        if (isset($all_plugins[$plugin_path])) {
            $plugin_names[] = $all_plugins[$plugin_path]['Name'];
        }
    }
    
    // Prepare additional data
    $additional_data = array(
        'report_type' => $report_type,
        'theme' => wp_get_theme()->get('Name'),
        'theme_version' => wp_get_theme()->get('Version'),
        'wc_version' => defined('WC_VERSION') ? WC_VERSION : 'N/A',
        'multisite' => is_multisite() ? 'Yes' : 'No',
    );
    
    if (!empty($additional_info)) {
        $additional_data['user_provided_info'] = $additional_info;
    }
    
    // Prepare the data
    $data = array(
        'site_url' => get_site_url(),
        'message' => $message,
        'total_users' => count_users()['total_users'],
        'plugin_names' => $plugin_names,
        'plugin_version' => CHECKOUTGUARD_VERSION,
        'php_version' => phpversion(),
        'wp_version' => get_bloginfo('version'),
        'server_info' => isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE'])) : 'Unknown',
        'reporter_email' => get_option('admin_email'),
        'reporter_name' => get_bloginfo('name'),
        'additional_data' => $additional_data,
    );
    
    // Send the request
    $response = wp_remote_post($api_url, array(
        'headers' => array(
            'Content-Type' => 'application/json',
        ),
        'body' => wp_json_encode($data),
        'timeout' => 30,
        'sslverify' => true,
    ));
    
    // Handle errors
    if (is_wp_error($response)) {
        return array(
            'success' => false,
            'message' => $response->get_error_message()
        );
    }
    
    $response_code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);
    
    if ($response_code === 201 || $response_code === 200) {
        return array(
            'success' => true,
            'message' => isset($body['message']) ? $body['message'] : 'Report submitted successfully',
            'report_id' => isset($body['report_id']) ? $body['report_id'] : null
        );
    } else {
        return array(
            'success' => false,
            'message' => isset($body['message']) ? $body['message'] : 'Failed to submit report. HTTP Code: ' . $response_code
        );
    }
}
