<?php
// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * ====================================================================
 * ADMIN PAGES LOADER
 * ====================================================================
 * Modular sub-files grouped by page and component.
 */
$cg_pages_dir = plugin_dir_path(__FILE__) . 'pages/';

require_once $cg_pages_dir . 'common-components.php';
require_once $cg_pages_dir . 'page-dashboard.php';
require_once $cg_pages_dir . 'page-checkouts.php';
require_once $cg_pages_dir . 'page-fraud-blocker.php';
require_once $cg_pages_dir . 'page-courier-analytics.php';
require_once $cg_pages_dir . 'page-order-statuses.php';
require_once $cg_pages_dir . 'page-invoices.php';
