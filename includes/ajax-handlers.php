<?php
// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * ====================================================================
 * AJAX HANDLERS LOADER
 * ====================================================================
 * Modular sub-files grouped by feature domain.
 */
$cg_ajax_dir = plugin_dir_path(__FILE__) . 'ajax/';

require_once $cg_ajax_dir . 'ajax-usage-limits.php';
require_once $cg_ajax_dir . 'ajax-checkouts.php';
require_once $cg_ajax_dir . 'ajax-courier.php';
require_once $cg_ajax_dir . 'ajax-fraud.php';
require_once $cg_ajax_dir . 'ajax-order-statuses.php';
require_once $cg_ajax_dir . 'ajax-settings.php';
