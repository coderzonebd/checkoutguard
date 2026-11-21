<?php
// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}


/**
 * Renders the Order Statuses admin page.
 */
function cg_render_order_statuses_page()
{
    if (!current_user_can('manage_woocommerce')) {
        wp_die(esc_html__('You do not have sufficient permissions.', 'checkoutguard'));
    }

    $custom_statuses = cg_get_custom_order_statuses();
    ?>
    <div class="wrap cg-order-statuses-wrap">
        <div class="cg-page-header">
            <h1><?php esc_html_e('Order Statuses', 'checkoutguard'); ?></h1>
            <button type="button" class="cg-dark-mode-toggle" title="<?php esc_attr_e('Toggle Dark Mode', 'checkoutguard'); ?>">
                <span class="dashicons dashicons-admin-appearance"></span>
            </button>
        </div>
        <p class="cg-page-description"><?php esc_html_e('Overview of all WooCommerce order statuses and manage your custom statuses.', 'checkoutguard'); ?></p>

        <!-- Status Overview Cards -->
        <h2><?php esc_html_e('Status Overview', 'checkoutguard'); ?></h2>
        <div id="cg-order-status-cards" class="cg-status-cards-grid">
            <div class="cg-status-cards-loading">
                <div class="cg-spinner"></div>
                <p><?php esc_html_e('Loading order counts...', 'checkoutguard'); ?></p>
            </div>
        </div>

        <!-- All Statuses Management -->
        <div class="cg-custom-statuses-section">
            <div class="cg-section-header">
                <h2><?php esc_html_e('Manage Statuses', 'checkoutguard'); ?></h2>
                <button type="button" class="button button-primary" id="cg-add-new-status">
                    <span class="dashicons dashicons-plus-alt2"></span>
                    <?php esc_html_e('Add New Status', 'checkoutguard'); ?>
                </button>
            </div>

            <div class="cg-table-responsive-wrapper">
                <table class="wp-list-table widefat fixed striped cg-custom-statuses-table"
                    id="cg-custom-statuses-table">
                    <thead>
                        <tr>
                            <th style="width:60px;"><?php esc_html_e('Color', 'checkoutguard'); ?></th>
                            <th><?php esc_html_e('Label', 'checkoutguard'); ?></th>
                            <th><?php esc_html_e('Slug', 'checkoutguard'); ?></th>
                            <th style="width:90px;"><?php esc_html_e('Type', 'checkoutguard'); ?></th>
                            <th><?php esc_html_e('Description', 'checkoutguard'); ?></th>
                            <th style="width:80px;"><?php esc_html_e('Orders', 'checkoutguard'); ?></th>
                            <th style="width:140px;"><?php esc_html_e('Actions', 'checkoutguard'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="cg-custom-statuses-tbody">
                        <?php foreach ($custom_statuses as $status) :
                            $is_default = !empty($status['is_default']);
                        ?>
                        <tr data-slug="<?php echo esc_attr($status['slug']); ?>">
                            <td>
                                <span class="cg-status-color-dot" style="background-color: <?php echo esc_attr($status['color']); ?>;"></span>
                            </td>
                            <td>
                                <?php if (!empty($status['icon'])) : ?>
                                    <span class="dashicons <?php echo esc_attr($status['icon']); ?>" style="margin-right:4px;"></span>
                                <?php endif; ?>
                                <?php echo esc_html($status['label']); ?>
                            </td>
                            <td><code>wc-<?php echo esc_html($status['slug']); ?></code></td>
                            <td>
                                <?php if ($is_default) : ?>
                                    <span class="cg-status-badge-default"><?php esc_html_e('Default', 'checkoutguard'); ?></span>
                                <?php else : ?>
                                    <span class="cg-status-badge-custom"><?php esc_html_e('Custom', 'checkoutguard'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($status['description'] ?? ''); ?></td>
                            <td class="cg-status-order-count" data-slug="<?php echo esc_attr($status['slug']); ?>">
                                <span class="spinner is-active" style="float:none;"></span>
                            </td>
                            <td>
                                <button type="button" class="button button-small cg-edit-status" data-slug="<?php echo esc_attr($status['slug']); ?>">
                                    <?php esc_html_e('Edit', 'checkoutguard'); ?>
                                </button>
                                <button type="button" class="button button-small button-link-delete cg-delete-status" data-slug="<?php echo esc_attr($status['slug']); ?>">
                                    <?php esc_html_e('Delete', 'checkoutguard'); ?>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php cg_render_status_modal_html(); ?>
    </div>
    <?php
}


/**
 * Renders the add/edit modal HTML for custom order statuses.
 */
function cg_render_status_modal_html()
{
    ?>
    <div id="cg-status-modal" class="cg-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="cg-status-modal-title">
        <div class="cg-modal-content" style="max-width:520px;">
            <div id="cg-status-modal-body">
                <div class="cg-status-modal-header">
                    <h3 id="cg-status-modal-title"><?php esc_html_e('Add New Status', 'checkoutguard'); ?></h3>
                    <button type="button" class="cg-modal-close" aria-label="<?php esc_attr_e('Close', 'checkoutguard'); ?>">&times;</button>
                </div>
                <form id="cg-status-form">
                    <input type="hidden" id="cg-status-edit-slug" value="">
                    <div class="cg-form-field">
                        <label for="cg-status-label"><?php esc_html_e('Status Label', 'checkoutguard'); ?> <span class="required">*</span></label>
                        <input type="text" id="cg-status-label" class="regular-text" required placeholder="<?php esc_attr_e('e.g., Awaiting Pickup', 'checkoutguard'); ?>">
                    </div>
                    <div class="cg-form-field">
                        <label for="cg-status-slug"><?php esc_html_e('Slug', 'checkoutguard'); ?></label>
                        <div class="cg-slug-input-wrap">
                            <code>wc-</code>
                            <input type="text" id="cg-status-slug" class="regular-text" placeholder="<?php esc_attr_e('auto-generated', 'checkoutguard'); ?>" readonly>
                        </div>
                        <p class="description"><?php esc_html_e('Auto-generated from label. Cannot be changed after creation.', 'checkoutguard'); ?></p>
                    </div>
                    <div class="cg-form-field">
                        <label for="cg-status-color"><?php esc_html_e('Color', 'checkoutguard'); ?></label>
                        <div class="cg-color-input-wrap">
                            <input type="color" id="cg-status-color" value="#f97316">
                            <input type="text" id="cg-status-color-hex" class="cg-color-hex-input" value="#f97316" placeholder="#f97316" maxlength="7">
                        </div>
                    </div>
                    <div class="cg-form-field">
                        <label for="cg-status-description"><?php esc_html_e('Description (optional)', 'checkoutguard'); ?></label>
                        <textarea id="cg-status-description" rows="2" class="large-text"></textarea>
                    </div>
                    <div class="cg-form-field">
                        <label><?php esc_html_e('Icon (optional)', 'checkoutguard'); ?></label>
                        <input type="hidden" id="cg-status-icon" value="">
                        <div class="cg-icon-picker" id="cg-icon-picker">
                            <?php
                            $icons = [
                                ''                          => __('None', 'checkoutguard'),
                                'dashicons-cart'            => __('Cart', 'checkoutguard'),
                                'dashicons-car'             => __('Delivery', 'checkoutguard'),
                                'dashicons-clock'           => __('Pending', 'checkoutguard'),
                                'dashicons-yes-alt'         => __('Confirmed', 'checkoutguard'),
                                'dashicons-yes'             => __('Approved', 'checkoutguard'),
                                'dashicons-no-alt'          => __('Cancelled', 'checkoutguard'),
                                'dashicons-dismiss'         => __('Rejected', 'checkoutguard'),
                                'dashicons-warning'         => __('Warning', 'checkoutguard'),
                                'dashicons-flag'            => __('Flagged', 'checkoutguard'),
                                'dashicons-star-filled'     => __('Priority', 'checkoutguard'),
                                'dashicons-money-alt'       => __('Payment', 'checkoutguard'),
                                'dashicons-update'          => __('Processing', 'checkoutguard'),
                                'dashicons-undo'            => __('Refund', 'checkoutguard'),
                                'dashicons-archive'         => __('Archived', 'checkoutguard'),
                                'dashicons-tag'             => __('Tagged', 'checkoutguard'),
                                'dashicons-location'        => __('Shipping', 'checkoutguard'),
                                'dashicons-phone'           => __('Contact', 'checkoutguard'),
                                'dashicons-email-alt'       => __('Email Sent', 'checkoutguard'),
                                'dashicons-visibility'      => __('Review', 'checkoutguard'),
                                'dashicons-lock'            => __('Locked', 'checkoutguard'),
                                'dashicons-unlock'          => __('Unlocked', 'checkoutguard'),
                                'dashicons-hammer'          => __('In Progress', 'checkoutguard'),
                                'dashicons-clipboard'       => __('Picking', 'checkoutguard'),
                                'dashicons-migrate'         => __('Transferred', 'checkoutguard'),
                                'dashicons-megaphone'       => __('Notify', 'checkoutguard'),
                                'dashicons-thumbs-up'       => __('Completed', 'checkoutguard'),
                                'dashicons-thumbs-down'     => __('Failed', 'checkoutguard'),
                                'dashicons-shield'          => __('Verified', 'checkoutguard'),
                                'dashicons-calendar-alt'    => __('Scheduled', 'checkoutguard'),
                                'dashicons-printer'         => __('Print', 'checkoutguard'),
                                'dashicons-building'        => __('Warehouse', 'checkoutguard'),
                            ];
                            foreach ($icons as $icon_class => $icon_label) :
                                $is_none = ($icon_class === '');
                            ?>
                                <div class="cg-icon-picker-item<?php echo $is_none ? ' cg-icon-picker-item--active' : ''; ?>"
                                     data-icon="<?php echo esc_attr($icon_class); ?>"
                                     title="<?php echo esc_attr($icon_label); ?>">
                                    <?php if ($is_none) : ?>
                                        <span class="cg-icon-picker-none">&times;</span>
                                    <?php else : ?>
                                        <span class="dashicons <?php echo esc_attr($icon_class); ?>"></span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="cg-form-field">
                        <label>
                            <input type="checkbox" id="cg-status-show-in-bulk">
                            <?php esc_html_e('Show in Bulk Actions', 'checkoutguard'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('When enabled, this status will appear in the "Change status to" bulk action on the WooCommerce orders page.', 'checkoutguard'); ?></p>
                    </div>
                    <div class="cg-form-actions">
                        <span class="spinner" id="cg-status-spinner"></span>
                        <button type="submit" class="button button-primary" id="cg-status-save-btn">
                            <?php esc_html_e('Save Status', 'checkoutguard'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php
}

