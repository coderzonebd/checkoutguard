<?php
// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renders the newly designed page for Incomplete Checkouts.
 */
function checkoutguard_render_incomplete_checkouts_page()
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

    // Query for table rows (all incomplete)
    // REMOVED: 10-item limit and 1-day limit for the query. Now it shows all.
    $results = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE status = %s ORDER BY created_at DESC",
            'incomplete'
        )
    );
    ?>
    <div class="wrap checkoutguard-wrap">
        <div class="cg-page-header">
            <h1><?php esc_html_e('Incomplete Checkouts', 'checkoutguard'); ?></h1>
            <p class="page-subtitle">
                <?php esc_html_e('Review checkouts that were started but not completed.', 'checkoutguard'); ?></p>
        </div>

        <div class="cg-stat-cards-grid">
            <div class="cg-stat-card">
                <p class="stat-title"><?php esc_html_e('Incomplete Carts (Last 24h)', 'checkoutguard'); ?></p>
                <p class="stat-value"><?php echo esc_html($stats->count ?? 0); ?></p>
            </div>
            <div class="cg-stat-card">
                <p class="stat-title"><?php esc_html_e('Value of Carts (Last 24h)', 'checkoutguard'); ?></p>
                <p class="stat-value"><?php echo wc_price($stats->value ?? 0); ?></p>
            </div>
            <div class="cg-stat-card">
                <p class="stat-title"><?php esc_html_e('Total Incomplete Carts', 'checkoutguard'); ?></p>
                <p class="stat-value"><?php echo esc_html(count($results)); ?></p>
            </div>
            <div class="cg-stat-card">
                <p class="stat-title"><?php esc_html_e('Need More Features?', 'checkoutguard'); ?></p>
                <div class="cg-pro-prompt">
                    <a href="https://coderzonebd.com/pricing" target="_blank" class="button button-primary">
                        <?php esc_html_e('Upgrade to Pro', 'checkoutguard'); ?>
                    </a>
                </div>
            </div>
        </div>

        <div class="cg-card">
            <div class="cg-card-header">
                <h2><?php esc_html_e('All Incomplete Checkouts', 'checkoutguard'); ?></h2>
                <div class="cg-search-box">
                    <span class="dashicons dashicons-search"></span>
                    <input type="search" id="cg-table-search" placeholder="Search checkouts...">
                    <small><?php esc_html_e('Search functionality is coming soon.', 'checkoutguard'); ?></small>
                </div>
            </div>
            <?php checkoutguard_render_checkouts_table_new_design($results); ?>
        </div>

        <?php checkoutguard_render_details_modal_html(); ?>
    </div>
    <?php
}


/**
 * Renders the new card-style table for checkouts.
 */
function checkoutguard_render_checkouts_table_new_design($results)
{
    ?>
    <table class="cg-data-table">
        <thead>
            <tr>
                <th><?php esc_html_e('Customer', 'checkoutguard'); ?></th>
                <th><?php esc_html_e('Contact', 'checkoutguard'); // REMOVED: (Pro) tag ?></th>
                <th><?php esc_html_e('Cart', 'checkoutguard'); ?></th>
                <th><?php esc_html_e('Last Active', 'checkoutguard'); ?></th>
                <th><?php esc_html_e('Actions', 'checkoutguard'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php
            if (empty($results)) {
                echo '<tr><td colspan="5" style="text-align: center; padding: 40px;">' . esc_html__('No incomplete checkouts found.', 'checkoutguard') . '</td></tr>';
            } else {
                foreach ($results as $row) {
                    $full_name = trim($row->first_name . ' ' . $row->last_name);
                    $cart_items = json_decode($row->cart_details, true);
                    ?>
                    <tr class="checkoutguard-table-row" id="checkoutguard-entry-row-<?php echo esc_attr($row->id); ?>">
                        <td>
                            <div class="cg-customer-info">
                                <img src="<?php echo esc_url(get_avatar_url($row->email)); ?>" class="avatar" alt="Avatar">
                                <div>
                                    <a href="#" class="name checkoutguard-view-details"
                                        data-id="<?php echo esc_attr($row->id); ?>"><?php echo esc_html($full_name ?: '(No Name)'); ?></a>
                                    <div class="email"><?php echo esc_html($row->email); ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <?php // REMOVED: Pro upsell link. Now showing phone. ?>
                            <div class="cg-contact-info">
                                <?php if ($row->phone): ?>
                                    <span class="dashicons dashicons-phone"></span>
                                    <?php echo esc_html($row->phone); ?>
                                <?php else: ?>
                                    <span class="cg-text-secondary"><?php esc_html_e('No phone', 'checkoutguard'); ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <div class="cg-cart-items">
                                <strong><?php echo wc_price($row->cart_value); ?></strong>
                                <?php if (is_array($cart_items) && !empty($cart_items)): ?>
                                    <ul>
                                        <?php foreach (array_slice($cart_items, 0, 2) as $item): ?>
                                            <li><?php echo esc_html($item['quantity'] . 'x ' . $item['name']); ?></li>
                                        <?php endforeach; ?>
                                        <?php if (count($cart_items) > 2): ?>
                                            <li>...<?php echo (count($cart_items) - 2); ?> more</li>
                                        <?php endif; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td><?php echo esc_html(human_time_diff(strtotime($row->updated_at))) . ' ago'; ?></td>
                        <td class="cg-actions">
                            <button class="button button-secondary checkoutguard-view-details"
                                data-id="<?php echo esc_attr($row->id); ?>"><?php esc_html_e('Details', 'checkoutguard'); ?></button>
                            <button class="button button-link-delete checkoutguard-mark-cancelled"
                                data-id="<?php echo esc_attr($row->id); ?>"><?php esc_html_e('Cancel', 'checkoutguard'); ?></button>
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
 * REMOVED: IP and Email upsell sections.
 */
function checkoutguard_render_fraud_blocker_page()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'checkoutguard'));
    }

    $ajax_nonce = wp_create_nonce('checkoutguard_fraud_blocker_nonce');
    ?>
    <div class="wrap checkoutguard-fraud-blocker-wrap">
        <h1><?php esc_html_e('Fraud Blocker', 'checkoutguard'); ?></h1>
        <p><?php esc_html_e('Block specific phone numbers to prevent unwanted orders. There is no limit to the number of phone numbers you can block.', 'checkoutguard'); ?>
        </p>

        <div id="checkoutguard-blocker-messages" style="display:none;" class="notice is-dismissible"></div>

        <div class="cg-blocker-sections" style="grid-template-columns: 1fr;"> <?php // Simplified to one column ?>
            <div class="cg-blocker-section">
                <h2><?php esc_html_e('Phone Number Blocker', 'checkoutguard'); ?></h2>
                <form class="checkoutguard-blocker-form" data-block-type="phone">
                    <input type="hidden" name="nonce" value="<?php echo esc_attr($ajax_nonce); ?>">
                    <p><label
                            for="checkoutguard_block_phone"><?php esc_html_e('Phone Number to Block:', 'checkoutguard'); ?></label><br><input
                            type="text" name="value" class="regular-text" placeholder="e.g., 01712345678"></p>
                    <p><label
                            for="checkoutguard_block_phone_reason"><?php esc_html_e('Reason (Optional):', 'checkoutguard'); ?></label><br><textarea
                            name="reason" rows="2" class="large-text"></textarea></p>
                    <p><button type="submit"
                            class="button button-primary"><?php esc_html_e('Block Phone Number', 'checkoutguard'); ?></button><span
                            class="spinner"></span></p>
                </form>
                <h3 style="margin-top:20px;"><?php esc_html_e('Blocked Phone Numbers', 'checkoutguard'); ?></h3>
                <?php checkoutguard_display_blocked_items_list('phone'); ?>
            </div>

            <?php // REMOVED: Pro upsell section for IP/Email blocking ?>
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
    <div id="checkoutguard-details-modal" class="cg-modal" style="display:none;">
        <div class="cg-modal-content">
            <span class="cg-modal-close">&times;</span>
            <h2><?php esc_html_e('Checkout Details', 'checkoutguard'); ?></h2>
            <div id="checkoutguard-modal-body">
                <p><?php esc_html_e('Loading details...', 'checkoutguard'); ?></p>
            </div>
        </div>
    </div>
    <?php
}


/**
 * Renders the common table structure for checkouts.
 * REMOVED: This function is no longer used, replaced by checkoutguard_render_checkouts_table_new_design
 */
function checkoutguard_render_checkouts_table($results, $current_status = 'incomplete')
{
    // This function appears to be an older design. We will keep the new one.
    // To be safe, we'll update this one too.
    $is_empty = empty($results);
    ?>
    <div class="cg-table-responsive-wrapper">
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col" class="manage-column"><?php esc_html_e('Name', 'checkoutguard'); ?></th>
                    <th scope="col" class="manage-column"><?php esc_html_e('Email', 'checkoutguard'); ?></th>
                    <th scope="col" class="manage-column">
                        <?php esc_html_e('Phone', 'checkoutguard'); // REMOVED: (Pro) tag ?></th>
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
                    echo '<tr><td colspan="7">' . esc_html__('No incomplete checkouts found.', 'checkoutguard') . '</td></tr>';
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
                    <?php echo esc_html($row->phone); // REMOVED: Pro check, now always shows phone ?>
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
                <td class="cg-actions-cell">
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

    $items = $wpdb->get_results("SELECT * FROM {$table_name} ORDER BY created_at DESC");

    echo '<ul class="cg-blocked-list" id="checkoutguard-blocked-list-' . esc_attr($type) . '">';
    if (empty($items)) {
        echo '<li class="checkoutguard-no-items">' . esc_html__('No phone numbers are currently blocked.', 'checkoutguard') . '</li>';
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
