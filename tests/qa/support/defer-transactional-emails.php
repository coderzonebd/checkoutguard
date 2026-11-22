<?php
/**
 * QA helper: turn on deferred WooCommerce transactional emails for one wp-cli run.
 *
 * Load with --require so the filter exists before WooCommerce init:
 *   wp --require=tests/qa/support/defer-transactional-emails.php eval-file tests/qa/duplicate-blocker-tests.php
 * Local test site only.
 */

if (!class_exists('WP_CLI')) {
    return;
}

define('QA_F4D_DEFER_REQUESTED', true);
WP_CLI::add_wp_hook('woocommerce_defer_transactional_emails', '__return_true');
