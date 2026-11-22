<?php
// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}



// For Courier Analytics Page & Single Order Meta Box (returns FULL TABLE)
function cg_handle_check_courier_success()
{
    // ** THE FIX: The redundant increment call has been REMOVED from this function. **
    // The main HQ application will now be the only place that increments the count.

    check_ajax_referer('cg_check_courier_success_nonce', 'cg_courier_nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'Permission Denied.']);
    }

    $phone_number = isset($_POST['phone_number']) ? cg_normalize_phone_number(sanitize_text_field(wp_unslash($_POST['phone_number']))) : '';
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;

    // We still check if the user *can* perform the action based on the last-known data.
    if (!cg_can_perform_action('courier_checks')) {
        wp_send_json_error([
            'message'       => cg_get_limit_reached_message('courier_checks'),
            'limit_reached' => true,
            'action_type'   => 'courier_checks',
            'pricing_url'   => CG_PRICING_URL,
            'whatsapp_url'  => CG_WHATSAPP_URL,
        ], 403);
        return;
    }

    $force_refresh = !empty($_POST['force_refresh']);
    $results = cg_perform_all_api_calls($phone_number, $order_id, $force_refresh);
    $html_output = cg_get_courier_success_html($results, $phone_number);

    // Update recent checks list when checked from the Courier Analytics page
    $recent_entry = null;
    $summary = $results['summary'] ?? null;
    if ($summary && $order_id === 0) {
        cg_update_recent_courier_checks($phone_number, $summary);
        $recent_entry = [
            'phone'        => $phone_number,
            'success_rate' => intval($summary['success_rate'] ?? 0),
            'risk_level'   => $summary['risk_level'] ?? 'N/A',
            'risk_color'   => $summary['risk_color'] ?? '#757575',
            'checked_at'   => current_time('mysql'),
        ];
    }

    wp_send_json_success(['html' => $html_output, 'recent_entry' => $recent_entry]);
}


/**
 * Updates the recent courier checks list stored in wp_options.
 * Keeps max 20 entries, newest first. Moves existing phone to top if re-checked.
 */
function cg_update_recent_courier_checks($phone_number, $summary)
{
    $recent_checks = get_option('cg_recent_courier_checks', []);

    $recent_checks = array_values(array_filter($recent_checks, function ($entry) use ($phone_number) {
        return $entry['phone'] !== $phone_number;
    }));

    array_unshift($recent_checks, [
        'phone'        => $phone_number,
        'success_rate' => intval($summary['success_rate'] ?? 0),
        'risk_level'   => $summary['risk_level'] ?? 'N/A',
        'risk_color'   => $summary['risk_color'] ?? '#757575',
        'checked_at'   => current_time('mysql'),
    ]);

    $recent_checks = array_slice($recent_checks, 0, 20);
    update_option('cg_recent_courier_checks', $recent_checks, false);
}


// For Orders List Column (returns COMPACT BAR)
function cg_handle_order_list_success_check()
{
    // This function correctly did not have the increment call, so no changes are needed here.
    check_ajax_referer('cg_check_order_success_ratio_nonce', 'cg_order_nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'Permission Denied.']);
    }
    $phone_number = isset($_POST['phone_number']) ? cg_normalize_phone_number(sanitize_text_field(wp_unslash($_POST['phone_number']))) : '';
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    $results = cg_perform_all_api_calls($phone_number, $order_id);
    $html_output = cg_get_compact_ratio_html($results['summary'], $phone_number, $order_id);
    wp_send_json_success(['html' => $html_output]);
}




function cg_get_compact_ratio_html($ratio_data, $phone_number, $order_id)
{
    $total_deliveries = (int) ($ratio_data['total_deliveries'] ?? 0);
    $total_success = (int) ($ratio_data['successful_deliveries'] ?? 0);
    $total_cancelled = (int) ($ratio_data['cancelled_deliveries'] ?? ($total_deliveries - $total_success));
    $success_rate = (int) ($ratio_data['success_rate'] ?? 0);
    ob_start(); ?>
    <div class="cg-ratio-display">
        <div class="cg-ratio-stats">
            All: <?php echo esc_html($total_deliveries); ?>
            <span class="cg-ratio-success">Success: <?php echo esc_html($total_success); ?></span>
            <span class="cg-ratio-cancel">Cancel: <?php echo esc_html($total_cancelled); ?></span>
        </div>
        <div class="cg-ratio-bar-bg">
            <div class="cg-ratio-bar-fg" style="width: <?php echo esc_attr($success_rate); ?>%;">
                <?php if ($success_rate > 15) {
                    echo esc_html($success_rate) . '%';
                } ?>
            </div>
        </div>
        <button type="button" class="button-link cg-check-order-ratio" data-phone="<?php echo esc_attr($phone_number); ?>"
            data-order-id="<?php echo esc_attr($order_id); ?>">
            <span class="dashicons dashicons-update"></span>
        </button>
    </div>
    <?php return ob_get_clean();
}

/**
 * The failed-check result: cache_source "error" with a zeroed summary. It is
 * never cached and never blocks or holds an order.
 */
function cg_courier_error_results($error_message)
{
    $error_results = [
        'cache_source' => 'error',
        'summary' => array_merge(cg_get_default_courier_summary(), [
            'risk_level' => $error_message,
            'risk_color' => '#dc3545',
        ]),
    ];

    foreach (array_keys(cg_get_supported_couriers()) as $courier_key) {
        $error_results[$courier_key] = cg_get_default_courier_result($courier_key, true);
    }

    return $error_results;
}

/**
 * HQ answered the courier check with 401/403 and no "code" in the body: it
 * does not accept this key at all. Asking again on every checkout only adds
 * the same wait and the same answer, so the answer is kept for 5 minutes for
 * this key (the whole site, not per phone). 429, 5xx, timeouts and coded
 * 401/403 (site-key answers) are not kept.
 */
function cg_courier_maybe_remember_auth_reject($error)
{
    $data = $error->get_error_data();
    $status = is_array($data) ? (int) ($data['status'] ?? 0) : 0;
    $code = is_array($data) ? (string) ($data['code'] ?? '') : '';
    if (($status !== 401 && $status !== 403) || $code !== '') {
        return;
    }
    set_transient(CG_COURIER_AUTH_REJECT_TRANSIENT, [
        'key' => md5(cg_courier_current_api_key()),
        'message' => mb_substr(sanitize_text_field($error->get_error_message()), 0, 200),
    ], CG_COURIER_AUTH_REJECT_TTL);
}

/**
 * The kept 401/403 message while it applies to the current key, else null.
 * A different key (rotated, registered, claimed, typed in) ends it at once.
 */
function cg_courier_auth_reject_active()
{
    $kept = get_transient(CG_COURIER_AUTH_REJECT_TRANSIENT);
    if (!is_array($kept) || !isset($kept['key'])) {
        return null;
    }
    if (!hash_equals((string) $kept['key'], md5(cg_courier_current_api_key()))) {
        delete_transient(CG_COURIER_AUTH_REJECT_TRANSIENT);
        return null;
    }
    $message = isset($kept['message']) && is_string($kept['message']) ? $kept['message'] : '';
    return $message !== '' ? $message : 'API Error';
}

/**
 * The key cg_call_hq_api() sends.
 */
function cg_courier_current_api_key()
{
    $options = get_option('cg_plugin_options', []);
    return !empty($options['api_key']) ? (string) $options['api_key'] : '';
}

/**
 * True when a courier-check error should start the site-wide wait: a
 * timeout (cURL 28) or an HQ 5xx. Connect/DNS failures (cURL 7, 6),
 * 4xx and 429 never do.
 */
function cg_courier_error_starts_wait($error)
{
    if (!is_wp_error($error)) {
        return false;
    }
    $data = $error->get_error_data();
    $status = is_array($data) ? (int) ($data['status'] ?? 0) : 0;
    if ($status >= 500 && $status <= 599) {
        return true;
    }
    $message = (string) $error->get_error_message();
    return stripos($message, 'cURL error 28') !== false || stripos($message, 'Operation timed out') !== false;
}

/**
 * Calls the secure HQ proxy and returns the REAL, aggregated data.
 * Results are cached in a transient for 6 hours to reduce HQ server requests.
 */
function cg_perform_all_api_calls($phone_number, $order_id, $bypass_cache = false)
{
    // One phone may arrive in different formats (+880..., spaces, dashes);
    // normalize it so all of them share one cache entry and one HQ call.
    $normalized = cg_normalize_phone_number($phone_number);
    if ($normalized !== '') {
        $phone_number = $normalized;
    }
    $cache_key = 'cg_courier_cache_' . md5($phone_number);

    // Classic checkout validates up to three times in one request. Reuse the
    // result of this request, failed checks included (they are not cached in
    // the transient), so a slow HQ is waited for once, not three times.
    if (!$bypass_cache) {
        $memo = cg_courier_request_memo($phone_number);
        if ($memo !== null) {
            $memo['order_id'] = $order_id;
            if ($order_id > 0 && ($memo['cache_source'] ?? '') !== 'error') {
                cg_update_order_meta($order_id, '_cg_success_ratio_data', $memo);
            }
            return $memo;
        }
    }
    $results = cg_perform_all_api_calls_uncached_request($phone_number, $order_id, $bypass_cache, $cache_key);
    if (is_array($results)) {
        cg_courier_request_memo($phone_number, $results);
    }
    return $results;
}

/**
 * Request-scope memo of courier results, keyed by normalized phone.
 * Read: cg_courier_request_memo($phone). Write: pass $value. Lives only for
 * the current PHP request.
 */
function cg_courier_request_memo($phone_number, $value = null, $reset = false)
{
    static $memo = [];
    if ($reset) {
        $memo = [];
        return null;
    }
    if ($value !== null) {
        $memo[$phone_number] = $value;
        return $value;
    }
    return $memo[$phone_number] ?? null;
}

/**
 * Clears the request-scope courier memo (used by tests).
 */
function cg_courier_request_memo_reset()
{
    cg_courier_request_memo('', null, true);
}

/**
 * Transient cache, waits and the HQ call behind cg_perform_all_api_calls().
 */
function cg_perform_all_api_calls_uncached_request($phone_number, $order_id, $bypass_cache, $cache_key)
{

    // Delete local cache if a force refresh was requested
    if ($bypass_cache) {
        delete_transient($cache_key);
        delete_transient(CG_COURIER_AUTH_REJECT_TRANSIENT);
    }

    // Check transient cache
    $cached_results = get_transient($cache_key);

    if ($cached_results !== false) {
        $cached_results = cg_normalize_courier_results_shape($cached_results);
        $cached_results['order_id'] = $order_id;
        $cached_results['cache_source'] = 'plugin_cache';
        if ($order_id > 0) {
            cg_update_order_meta($order_id, '_cg_success_ratio_data', $cached_results);
        }
        return $cached_results;
    }

    // HQ rejected this key a moment ago: skip the call and fail open.
    $auth_reject = cg_courier_auth_reject_active();
    if ($auth_reject !== null) {
        return cg_courier_error_results($auth_reject);
    }

    // HQ timed out or failed a moment ago: skip the call and fail open.
    // Nothing is cached for this phone. A force refresh still calls HQ.
    if (!$bypass_cache && get_transient(CG_COURIER_TIMEOUT_WAIT_TRANSIENT) !== false) {
        return cg_courier_error_results(__('Courier providers are taking too long to respond. Please try again in a few moments.', 'checkoutguard'));
    }

    $response = cg_call_hq_api('/sites/courier-check', ['phone_number' => $phone_number]);

    if (is_wp_error($response)) {
        cg_courier_maybe_remember_auth_reject($response);
        if (cg_courier_error_starts_wait($response)) {
            set_transient(CG_COURIER_TIMEOUT_WAIT_TRANSIENT, 1, CG_COURIER_TIMEOUT_WAIT_TTL);
        }
        $error_message = $response->get_error_message();
        if (empty($error_message)) {
            $error_message = 'API Error';
        } elseif (stripos($error_message, 'cURL error 28') !== false || stripos($error_message, 'Operation timed out') !== false) {
            $error_message = __('Courier providers are taking too long to respond. Please try again in a few moments.', 'checkoutguard');
        }
        // Do NOT cache error results
        return cg_courier_error_results($error_message);
    }

    // The backend nests the actual courier data under a "result" key
    // (alongside the sibling "cache_source" key) rather than returning it
    // flat — unwrap it before normalizing, but keep cache_source from the
    // outer envelope since it lives one level up from "result".
    $cache_source_from_response = is_array($response) ? ($response['cache_source'] ?? null) : null;
    $results = (is_array($response) && isset($response['result'])) ? $response['result'] : $response;

    $results = cg_normalize_courier_results_shape($results);

    // Preserve cache_source from the backend ('server_cache' or 'fresh'), fallback for old server
    if ($cache_source_from_response) {
        $results['cache_source'] = $cache_source_from_response;
    } elseif (!isset($results['cache_source'])) {
        $results['cache_source'] = 'server';
    }

    $results['order_id'] = $order_id;

    // Sync local usage count when HQ incremented (fresh data means HQ counted it)
    if (isset($results['cache_source']) && $results['cache_source'] === 'fresh') {
        $config = get_transient('cg_api_cache');
        if ($config && isset($config['limits']['courier_checks'])) {
            $config['usage']['courier_checks'] = ($config['usage']['courier_checks'] ?? 0) + 1;
            set_transient('cg_api_cache', $config, DAY_IN_SECONDS);
        }
    }

    // A courier-check success ends the wait.
    delete_transient(CG_COURIER_TIMEOUT_WAIT_TRANSIENT);

    // Cache successful results for 6 hours
    set_transient($cache_key, $results, 6 * HOUR_IN_SECONDS);

    if ($order_id > 0) {
        cg_update_order_meta($order_id, '_cg_success_ratio_data', $results);
    }

    return $results;
}


/**
 * Returns label and icon config for a cache source badge.
 */
function cg_get_cache_badge_config($cache_source)
{
    switch ($cache_source) {
        case 'plugin_cache':
            return ['label' => 'Plugin Cache', 'icon' => 'dashicons-database'];
        case 'server_cache':
            return ['label' => 'Server Cache', 'icon' => 'dashicons-cloud'];
        case 'fresh':
            return ['label' => 'Fresh Data', 'icon' => 'dashicons-yes-alt'];
        case 'server':
            return ['label' => 'From Server', 'icon' => 'dashicons-cloud'];
        default:
            return ['label' => 'Unknown', 'icon' => 'dashicons-info'];
    }
}


/**
 * Returns default summary payload.
 */
function cg_get_default_courier_summary()
{
    return [
        'success_rate' => 0,
        'total_deliveries' => 0,
        'successful_deliveries' => 0,
        'cancelled_deliveries' => 0,
        'risk_level' => 'N/A',
        'risk_color' => '#757575',
        'note' => null,
    ];
}


/**
 * Returns per-courier default payload.
 */
function cg_get_default_courier_result($courier_key = '', $error = false)
{
    $defaults = [
        'error' => (bool) $error,
        'total' => 0,
        'success' => 0,
        'cancelled' => 0,
        'success_rate' => 0,
        'estimated' => false,
        'counts_unavailable' => false,
    ];

    if ($courier_key === 'pathao') {
        $defaults['rating'] = null;
        $defaults['rating_label'] = null;
    }

    return $defaults;
}


/**
 * Supported couriers with labels and logo paths.
 */
function cg_get_supported_couriers()
{
    return [
        'pathao' => ['name' => 'Pathao', 'logo' => CG_PLUGIN_URL . 'assets/images/pathao.svg'],
        'steadfast' => ['name' => 'Steadfast', 'logo' => CG_PLUGIN_URL . 'assets/images/steadfast.svg'],
        'redx' => ['name' => 'RedX', 'logo' => CG_PLUGIN_URL . 'assets/images/redx.svg'],
        'paperfly' => ['name' => 'Paperfly', 'logo' => CG_PLUGIN_URL . 'assets/images/paperfly.svg'],
        'carrybee' => ['name' => 'Carrybee', 'logo' => CG_PLUGIN_URL . 'assets/images/carrybee.png'],
    ];
}


/**
 * Ensures all supported couriers exist in the payload.
 */
function cg_normalize_courier_results_shape($results)
{
    if (!is_array($results)) {
        $results = [];
    }

    $summary = [];
    if (isset($results['summary']) && is_array($results['summary'])) {
        $summary = $results['summary'];
    }
    $results['summary'] = array_merge(cg_get_default_courier_summary(), $summary);

    foreach (array_keys(cg_get_supported_couriers()) as $courier_key) {
        $courier_result = [];
        if (isset($results[$courier_key]) && is_array($results[$courier_key])) {
            $courier_result = $results[$courier_key];
        }
        $results[$courier_key] = array_merge(cg_get_default_courier_result($courier_key), $courier_result);
    }

    return $results;
}


/**
 * Helper function to generate the HTML for the responsive courier success display.
 */
function cg_get_courier_success_html($results, $phone_number)
{
    $results = cg_normalize_courier_results_shape($results);
    $summary = $results['summary'];
    $couriers = cg_get_supported_couriers();

    ob_start();
    ?>
    <div class="cg-redesign-container">
        <div class="cg-redesign-left">
            <div class="cg-ratio-circle-wrap">
                <canvas id="cg-success-ratio-doughnut-chart" width="160" height="160"
                    data-rate="<?php echo esc_attr($summary['success_rate']); ?>"
                    data-level-color="<?php echo esc_attr($summary['risk_color']); ?>"></canvas>
                <div class="cg-ratio-circle-text">
                    <div class="cg-ratio-circle-percent"><?php echo esc_html($summary['success_rate']); ?>%</div>
                    <div class="cg-ratio-circle-label" style="color: <?php echo esc_attr($summary['risk_color']); ?>;">
                        <?php echo esc_html($summary['risk_level']); ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="cg-redesign-right">
            <div class="cg-redesign-header">
                <h3>Results for: <?php echo esc_html($phone_number); ?></h3>
                <?php
                $cache_source = $results['cache_source'] ?? 'unknown';
                $badge_config = cg_get_cache_badge_config($cache_source);
                ?>
                <div class="cg-header-actions">
                    <span class="cg-cache-badge cg-cache-<?php echo esc_attr($cache_source); ?>">
                        <span class="dashicons <?php echo esc_attr($badge_config['icon']); ?>"></span>
                        <?php echo esc_html($badge_config['label']); ?>
                    </span>
                    <button type="button" class="button button-secondary cg-refresh-courier-data"
                        data-phone="<?php echo esc_attr($phone_number); ?>"
                        data-order-id="<?php echo esc_attr($results['order_id'] ?? 0); ?>">
                        <span class="dashicons dashicons-update"></span> Refresh
                    </button>
                </div>
            </div>

            <div class="cg-summary-boxes">
                <div class="cg-summary-box"><span
                        class="cg-summary-value"><?php echo esc_html($summary['total_deliveries']); ?></span><span
                        class="cg-summary-label">Total Orders</span></div>
                <div class="cg-summary-box"><span class="cg-summary-value"
                        style="color: #28a745;"><?php echo esc_html($summary['successful_deliveries']); ?></span><span
                        class="cg-summary-label">Total Delivered</span></div>
                <div class="cg-summary-box"><span class="cg-summary-value"
                        style="color: #dc3545;"><?php echo esc_html($summary['cancelled_deliveries']); ?></span><span
                        class="cg-summary-label">Total Canceled</span></div>
            </div>

            <div class="cg-redesigned-table-wrapper">
                <table class="cg-redesigned-table">
                    <thead>
                        <tr>
                            <th class="cg-col-courier">Courier</th>
                            <th class="cg-col-data">Orders</th>
                            <th class="cg-col-data">Delivered</th>
                            <th class="cg-col-data">Canceled</th>
                            <th class="cg-col-rate">Success Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($couriers as $key => $courier):
                            $data = $results[$key];
                            $has_error = !empty($data['error']);
                            $is_estimated = !empty($data['estimated']);
                            $counts_unavailable = !empty($data['counts_unavailable']);
                            $hide_counts = $is_estimated || $counts_unavailable;
                        ?>
                        <tr>
                            <td class="cg-col-courier">
                                <img src="<?php echo esc_url($courier['logo']); ?>" alt="<?php echo esc_attr($courier['name']); ?>">
                                <?php if ($key === 'pathao' && !empty($data['rating_label'])): ?>
                                    <span class="cg-rating-badge"><?php echo esc_html($data['rating_label']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="cg-col-data">
                                <?php if ($has_error): ?>
                                    N/A
                                <?php elseif ($is_estimated): ?>
                                    <span style="color: #999;">-</span>
                                <?php else: ?>
                                    <?php echo esc_html($data['total']); ?>
                                <?php endif; ?>
                            </td>
                            <td class="cg-col-data">
                                <?php if ($has_error): ?>
                                    N/A
                                <?php elseif ($hide_counts): ?>
                                    <span style="color: #999;">-</span>
                                <?php else: ?>
                                    <?php echo esc_html($data['success']); ?>
                                <?php endif; ?>
                            </td>
                            <td class="cg-col-data">
                                <?php if ($has_error): ?>
                                    <em>Failed</em>
                                <?php elseif ($hide_counts): ?>
                                    <span style="color: #999;">-</span>
                                <?php else: ?>
                                    <?php echo esc_html($data['cancelled']); ?>
                                <?php endif; ?>
                            </td>
                            <td class="cg-col-rate">
                                <?php if ($has_error): ?>
                                    N/A
                                <?php elseif ($counts_unavailable): ?>
                                    <span style="color: #999;">-</span>
                                <?php else: ?>
                                    <?php echo esc_html($data['success_rate']); ?>%
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php $pathao_data = $results['pathao']; ?>
            <?php if (!empty($pathao_data['estimated']) && empty($pathao_data['error'])): ?>
                <p class="cg-estimation-note">Pathao does not provide delivery counts. The success rate is estimated from the customer rating.</p>
            <?php endif; ?>

            <div class="cg-powered-by">
                <span class="powered-by-text">
                    Powered by <a href="https://coderzonebd.com" target="_blank">Coder Zone BD</a>
                </span>
                <span class="community-link">
                    Join Our <a href="https://chat.whatsapp.com/JBc983oZeeMKVavdI3cgMl" target="_blank">WhatsApp
                        Community</a>
                </span>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}


/**
 * Whether the checkout-page live ratio lookup is allowed for this request:
 * only for shop staff (manage_woocommerce), and only while the courier
 * service is enabled. Customers - guests or logged in - must not be able to
 * look up another phone number's delivery history.
 *
 * @return bool
 */
function cg_is_live_ratio_check_enabled()
{
    if (!current_user_can('manage_woocommerce')) {
        return false;
    }
    $options = get_option('cg_plugin_options', []);
    return !empty($options['enable_courier_service']);
}

/**
 * Handles the live AJAX request from the checkout page to get the success ratio.
 */
function cg_live_ratio_check_handler()
{
    // Shop staff only, and never when Courier Analytics is turned off.
    if (!cg_is_live_ratio_check_enabled()) {
        wp_send_json_error(['message' => __('Not available.', 'checkoutguard')], 403);
        return;
    }

    check_ajax_referer('cg_live_ratio_check_nonce', 'nonce');

    // Rate limit: max 20 requests per IP per 10 minutes to prevent phone enumeration.
    $ip         = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
    $rate_key   = 'cg_lrc_' . md5($ip);
    $rate_count = (int) get_transient($rate_key);
    if ($rate_count >= 20) {
        wp_send_json_error(['message' => 'Too many requests. Please wait before trying again.'], 429);
        return;
    }
    set_transient($rate_key, $rate_count + 1, 10 * MINUTE_IN_SECONDS);

    if (empty($_POST['phone_number'])) {
        wp_send_json_error();
        return;
    }

    $phone_number = cg_normalize_phone_number(sanitize_text_field(wp_unslash($_POST['phone_number'])));

    // IMPORTANT: This call should NOT increment usage. We let the proxy handle that.
    $ratio_data = cg_perform_all_api_calls($phone_number, 0);

    if (is_wp_error($ratio_data) || !isset($ratio_data['summary']['success_rate']) || (($ratio_data['cache_source'] ?? '') === 'error')) {
        wp_send_json_error();
        return;
    }

    $total = (int) ($ratio_data['summary']['total_deliveries'] ?? 0);
    $rate  = (int) ($ratio_data['summary']['success_rate'] ?? 100);

    // Only return the rate and total to keep the response small and fast
    wp_send_json_success([
        'rate'  => $rate,
        'total' => $total,
    ]);
    wp_die();
}

