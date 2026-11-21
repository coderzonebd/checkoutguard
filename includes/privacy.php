<?php
/**
 * Privacy and GDPR compliance for CheckoutGuard.
 *
 * @package CheckoutGuard
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Add suggested privacy policy content for the site's Privacy Policy page.
 */
function cg_add_privacy_policy_content()
{
    if (!function_exists('wp_add_privacy_policy_content')) {
        return;
    }

    $content = '<h2>' . __('CheckoutGuard', 'checkoutguard') . '</h2>';

    $content .= '<p>' . __('This site uses the CheckoutGuard plugin for WooCommerce to track incomplete checkouts, prevent fraudulent orders, manage fraud controls, generate invoices, and analyze courier delivery success rates. The site owner is responsible for deciding the lawful basis, retention period, and notices that apply to this processing.', 'checkoutguard') . '</p>';

    $content .= '<h3>' . __('What data we collect', 'checkoutguard') . '</h3>';
    $content .= '<p>' . __('When you begin filling in the WooCommerce checkout form, the following data may be captured before you complete your order:', 'checkoutguard') . '</p>';
    $content .= '<ul>';
    $content .= '<li>' . __('Billing name, email address, phone number, and full billing address.', 'checkoutguard') . '</li>';
    $content .= '<li>' . __('Cart contents including product names, quantities, and totals.', 'checkoutguard') . '</li>';
    $content .= '<li>' . __('Your IP address and browser session identifier.', 'checkoutguard') . '</li>';
    $content .= '<li>' . __('Fraud-prevention records, including blocklist values, reasons, and blocked-order details where configured by the site owner.', 'checkoutguard') . '</li>';
    $content .= '</ul>';

    $content .= '<h3>' . __('Why we collect this data', 'checkoutguard') . '</h3>';
    $content .= '<p>' . __('This data is collected for the following purposes:', 'checkoutguard') . '</p>';
    $content .= '<ul>';
    $content .= '<li>' . __('To follow up on incomplete checkouts and help you complete your purchase.', 'checkoutguard') . '</li>';
    $content .= '<li>' . __('To prevent fraudulent orders by checking customer details against blocklists.', 'checkoutguard') . '</li>';
    $content .= '<li>' . __('To analyze courier delivery success rates using phone numbers (when the Courier Analytics feature is enabled).', 'checkoutguard') . '</li>';
    $content .= '<li>' . __('To generate invoices, packing slips, and shipping labels when those features are used.', 'checkoutguard') . '</li>';
    $content .= '</ul>';

    $content .= '<h3>' . __('Where data is stored and shared', 'checkoutguard') . '</h3>';
    $content .= '<p>' . __('Checkout records, invoices, blocklists, and most operational data are stored locally in this WordPress database. CheckoutGuard does not send customer checkout records to its licensing service. When Courier Analytics is used, the customer phone number is sent to the selected courier APIs to retrieve delivery statistics.', 'checkoutguard') . '</p>';
    $content .= '<p>' . __('For license validation, usage limits, settings synchronization, and plugin analytics, CheckoutGuard sends limited site and administrator information to CheckoutGuard Headquarters at li.czbd.app. This may include the site domain, a domain hash, administrator email address, site name, WordPress/WooCommerce/PHP/MySQL versions, active theme, plugin version, selected plugin settings, feature-usage summaries, license status, and timestamps. Plugin analytics is sent from the plugin to this service even when licensing data is otherwise stored locally.', 'checkoutguard') . '</p>';
    $content .= '<p>' . __('If an administrator chooses Allow in the Appsero insights notice, diagnostic and usage information is also sent to Appsero at appsero.com. Appsero may receive the site name and URL, administrator name and email address, WordPress/PHP/MySQL/server details, WordPress version, site language, user count, active and inactive plugin information, and plugin version. Appsero tracking is disabled unless an administrator opts in and can be declined from the notice.', 'checkoutguard') . '</p>';

    $content .= '<h3>' . __('How long we retain this data', 'checkoutguard') . '</h3>';
    $content .= '<p>' . __('Incomplete checkout records are retained according to the data retention policy configured in the plugin settings (default: 90 days). Fraud blocklist entries are retained until manually removed by the store administrator or until their expiry date.', 'checkoutguard') . '</p>';

    $content .= '<h3>' . __('Data removal', 'checkoutguard') . '</h3>';
    $content .= '<p>' . __('You may request access to or removal of your personal data through the WordPress personal data export and erasure tools available under Tools > Export Personal Data and Tools > Erase Personal Data. Upon a valid request, CheckoutGuard removes local incomplete checkout records, blocked-order logs, and email blocklist entries associated with your email address. Data already sent to CheckoutGuard Headquarters, Appsero, or courier providers is subject to those providers\' policies and the site owner\'s instructions.', 'checkoutguard') . '</p>';

    $content .= '<h3>' . __('Controller and contact', 'checkoutguard') . '</h3>';
    $content .= '<p>' . __('The WordPress site owner or operator is the data controller for customer data collected by this plugin. Replace this sentence with your legal business name and contact details. For questions about CheckoutGuard services or data sent to CheckoutGuard Headquarters, contact Coder Zone BD at https://coderzonebd.com/contact. For Appsero processing, see https://appsero.com/privacy-policy/.', 'checkoutguard') . '</p>';

    wp_add_privacy_policy_content(
        'CheckoutGuard',
        wp_kses_post($content)
    );
}

/**
 * Register the personal data exporter for CheckoutGuard.
 *
 * @param array $exporters Registered exporters.
 * @return array
 */
function cg_register_data_exporter($exporters)
{
    $exporters['checkoutguard'] = array(
        'exporter_friendly_name' => __('CheckoutGuard Data', 'checkoutguard'),
        'callback'               => 'cg_personal_data_exporter',
    );
    return $exporters;
}

/**
 * Export personal data associated with an email address.
 *
 * @param string $email_address The email address to export data for.
 * @param int    $page          Page number for batched export.
 * @return array
 */
function cg_personal_data_exporter($email_address, $page = 1)
{
    global $wpdb;

    $export_items = array();
    $table = $wpdb->prefix . 'cg_incomplete_checkouts';

    $checkouts = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$table} WHERE email = %s ORDER BY id ASC LIMIT 100",
        $email_address
    ));

    foreach ($checkouts as $checkout) {
        $data = array(
            array('name' => __('First Name', 'checkoutguard'), 'value' => $checkout->first_name),
            array('name' => __('Last Name', 'checkoutguard'), 'value' => $checkout->last_name),
            array('name' => __('Email', 'checkoutguard'), 'value' => $checkout->email),
            array('name' => __('Phone', 'checkoutguard'), 'value' => $checkout->phone),
            array('name' => __('Address', 'checkoutguard'), 'value' => implode(', ', array_filter(array(
                $checkout->address_1, $checkout->address_2, $checkout->city,
                $checkout->state, $checkout->postcode, $checkout->country,
            )))),
            array('name' => __('IP Address', 'checkoutguard'), 'value' => $checkout->ip_address),
            array('name' => __('Cart Value', 'checkoutguard'), 'value' => $checkout->cart_value),
            array('name' => __('Status', 'checkoutguard'), 'value' => $checkout->status),
            array('name' => __('Created At', 'checkoutguard'), 'value' => $checkout->created_at),
        );

        $export_items[] = array(
            'group_id'          => 'checkoutguard-checkouts',
            'group_label'       => __('CheckoutGuard - Incomplete Checkouts', 'checkoutguard'),
            'group_description' => __('Checkout data captured by CheckoutGuard.', 'checkoutguard'),
            'item_id'           => "cg-checkout-{$checkout->id}",
            'data'              => $data,
        );
    }

    // Check blocked emails table.
    $blocked_email = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}cg_blocked_emails WHERE email_address = %s",
        $email_address
    ));

    if ($blocked_email) {
        $export_items[] = array(
            'group_id'          => 'checkoutguard-blocklist',
            'group_label'       => __('CheckoutGuard - Blocklist Entries', 'checkoutguard'),
            'group_description' => __('Fraud blocklist entries associated with your email.', 'checkoutguard'),
            'item_id'           => "cg-blocked-email-{$blocked_email->id}",
            'data'              => array(
                array('name' => __('Email Address', 'checkoutguard'), 'value' => $blocked_email->email_address),
                array('name' => __('Reason', 'checkoutguard'), 'value' => $blocked_email->reason ?? ''),
                array('name' => __('Blocked On', 'checkoutguard'), 'value' => $blocked_email->created_at),
            ),
        );
    }

    return array(
        'data' => $export_items,
        'done' => true,
    );
}

/**
 * Register the personal data eraser for CheckoutGuard.
 *
 * @param array $erasers Registered erasers.
 * @return array
 */
function cg_register_data_eraser($erasers)
{
    $erasers['checkoutguard'] = array(
        'eraser_friendly_name' => __('CheckoutGuard Data', 'checkoutguard'),
        'callback'             => 'cg_personal_data_eraser',
    );
    return $erasers;
}

/**
 * Erase personal data associated with an email address.
 *
 * @param string $email_address The email address to erase data for.
 * @param int    $page          Page number for batched erasure.
 * @return array
 */
function cg_personal_data_eraser($email_address, $page = 1)
{
    global $wpdb;

    $items_removed = 0;

    // Delete incomplete checkouts.
    $checkouts_deleted = $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->prefix}cg_incomplete_checkouts WHERE email = %s",
        $email_address
    ));
    if ($checkouts_deleted) {
        $items_removed += $checkouts_deleted;
    }

    // Delete blocked email entry.
    $email_deleted = $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->prefix}cg_blocked_emails WHERE email_address = %s",
        $email_address
    ));
    if ($email_deleted) {
        $items_removed += $email_deleted;
    }

    // Delete blocked order logs by email.
    $logs_deleted = $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->prefix}cg_blocked_orders WHERE email_address = %s",
        $email_address
    ));
    if ($logs_deleted) {
        $items_removed += $logs_deleted;
    }

    return array(
        'items_removed'  => $items_removed,
        'items_retained' => false,
        'messages'       => array(),
        'done'           => true,
    );
}
