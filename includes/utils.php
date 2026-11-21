<?php
// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * True if a plan-limit value from the HQ API means "unlimited". The backend
 * sends the plan's raw configured limit as-is (see buildSubscriptionSnapshot
 * in internal/handlers/license.go) — matching its own internal getLimit()
 * ("not set, or < 0, is unlimited"), only a NEGATIVE value (e.g. -1) means
 * unlimited. A limit of exactly 0 is a real, distinct value meaning "zero
 * allowed" — this is how an admin blocks a feature entirely for a plan
 * (set that feature's limit to 0 in the plan editor). Treating 0 the same
 * as unlimited, as this plugin used to, made that impossible.
 *
 * @param mixed $limit Raw limit value from cg_api_cache (already numeric).
 * @return bool
 */
function cg_is_unlimited_limit($limit)
{
    return ((int) $limit) < 0;
}

/**
 * Displays an admin notice if WooCommerce is not active.
 * This is the primary version of this function.
 */
function cg_woocommerce_missing_notice()
{
    ?>
    <div class="notice notice-error is-dismissible">
        <p><?php _e('<strong>CheckoutGuard</strong> requires WooCommerce to be installed and activated to function properly.', 'checkoutguard'); ?>
        </p>
    </div>
    <?php
}

/**
 * Helper function to get an array of formats for $wpdb->insert/update.
 * @param array $data Data array to get formats for.
 * @return array Array of formats.
 */
function cg_get_db_formats($data)
{
    $formats = array();
    // Define default formats for your columns based on their types in the DB
    $column_formats = array(
        'session_id' => '%s',
        'user_id' => '%d',
        'email' => '%s',
        'first_name' => '%s',
        'last_name' => '%s',
        'phone' => '%s',
        'address_1' => '%s',
        'address_2' => '%s',
        'city' => '%s',
        'state' => '%s',
        'postcode' => '%s',
        'country' => '%s',
        'ip_address' => '%s',
        'cart_details' => '%s', // JSON string
        'cart_value' => '%f',   // Float
        'status' => '%s',
        'follow_up_date' => '%s', // Date string YYYY-MM-DD
        'admin_notes' => '%s',
        'created_at' => '%s',   // DATETIME string
        'updated_at' => '%s'    // DATETIME string
    );
    foreach ($data as $key => $value) {
        if (isset($column_formats[$key])) {
            $formats[] = $column_formats[$key];
        } else {
            $formats[] = '%s'; // Default to string if not specified
        }
    }
    return $formats;
}
/**
 * Extracts the root domain from a given URL.
 *
 * @param string $url The URL to parse.
 * @return string The root domain.
 */
function cg_get_root_domain($url)
{
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host) {
        return false;
    }
    return strtolower($host);
}

/**
 * A centralized function for making API calls to the HQ.
 *
 * @param string $endpoint The API endpoint to call (e.g., '/sites/register').
 * @param array $body The data to send in the request body.
 * @param string $method The HTTP method (e.g., 'POST', 'GET').
 * @param bool $require_api_key Whether this API call requires an API key (set to false for registration).
 * @return array|WP_Error The decoded response body or a WP_Error object on failure.
 */
function cg_call_hq_api($endpoint, $body = [], $method = 'POST', $require_api_key = true)
{
    $options = get_option('cg_plugin_options', []);
    $api_key = !empty($options['api_key']) ? $options['api_key'] : '';

    // Only return an error if the API key is required but missing.
    // Initial registration call will set $require_api_key to false.
    if ($require_api_key && empty($api_key)) {
        $error = new WP_Error('api_key_missing', __('API Key is not configured in plugin settings. Please ensure plugin is activated and able to communicate with Headquarters.', 'checkoutguard'));
        cg_log_api_hit($endpoint, $method, false, null, $error->get_error_message());
        return $error;
    }

    $api_url = CG_HEADQUARTERS_URL . '/api/v1' . $endpoint;
    $site_domain = cg_get_root_domain(get_site_url());

    $headers = [
        'Content-Type' => 'application/json; charset=utf-8',
        'Accept' => 'application/json',
        'X-Site-Domain' => $site_domain,
        'X-Product-Slug' => CG_PRODUCT_SLUG,
    ];

    // Add X-Api-Key header if available
    if (!empty($api_key)) {
        $headers['X-Api-Key'] = $api_key;
    }

    // Settings pushes run inside the admin Save request, so fail fast; an
    // unconfirmed push is reported to the user and matched at the next sync.
    if ($endpoint === '/sites/courier-check') {
        $timeout = 30;
    } elseif ($endpoint === '/sites/settings') {
        $timeout = defined('CG_SETTINGS_PUSH_TIMEOUT') ? (int) CG_SETTINGS_PUSH_TIMEOUT : 5;
    } else {
        $timeout = 15;
    }
    // A caller on a customer-facing path (the period sync before a new
    // incomplete checkout is saved) may ask for a shorter timeout.
    if (isset($GLOBALS['cg_hq_timeout_override']) && (int) $GLOBALS['cg_hq_timeout_override'] > 0) {
        $timeout = min($timeout, (int) $GLOBALS['cg_hq_timeout_override']);
    }

    $args = [
        'method' => $method,
        'timeout' => $timeout,
        'headers' => $headers,
    ];

    if (!empty($body)) {
        $args['body'] = json_encode($body);
    }

    $response = wp_remote_request($api_url, $args);

    if (is_wp_error($response)) {
        cg_log_api_hit($endpoint, $method, false, null, $response->get_error_message());
        return $response;
    }

    $response_code = (int) wp_remote_retrieve_response_code($response);

    // HQ may issue a fresh, site-specific API key (e.g. migrating this site off
    // a legacy shared key). Adopt it immediately so future calls use it.
    // HQ sends it on 2xx responses and on 401/403 (the old key was rejected
    // before auth); never adopted from 5xx, timeouts or other errors. Stored
    // through cg_store_rotated_api_key(), which only saves a non-empty key that
    // differs from the current one and never calls HQ again while saving.
    $rotated_key = wp_remote_retrieve_header($response, 'x-new-api-key');
    $rotation_allowed = ($response_code >= 200 && $response_code < 300)
        || $response_code === 401
        || $response_code === 403;
    if (!empty($rotated_key) && $rotation_allowed) {
        $key_adopted = cg_store_rotated_api_key($rotated_key);

        // The request was rejected only because it carried the old key: repeat
        // it exactly once with the new key, so the caller (e.g. "Sync Now")
        // succeeds on the first try. The static flag prevents any second retry.
        static $retrying_with_rotated_key = false;
        if ($key_adopted && ($response_code === 401 || $response_code === 403) && !$retrying_with_rotated_key) {
            cg_log_api_hit($endpoint, $method, false, $response_code, __('API key rotated by Headquarters; retrying once with the new key.', 'checkoutguard'));
            $retrying_with_rotated_key = true;
            try {
                return cg_call_hq_api($endpoint, $body, $method, $require_api_key);
            } finally {
                $retrying_with_rotated_key = false;
            }
        }
    }

    $response_body = json_decode(wp_remote_retrieve_body($response), true);

    if ($response_code >= 300) {
        $error_message = $response_body['message'] ?? $response_body['error'] ?? 'Unknown API Error';
        cg_log_api_hit($endpoint, $method, false, $response_code, $error_message);
        $error_data = ['status' => $response_code];
        if ($response_code === 429) {
            // Raw header; the caller decides how to use it (SiteKey T1).
            $error_data['retry_after'] = wp_remote_retrieve_header($response, 'retry-after');
        }
        return new WP_Error('api_error', $error_message, $error_data);
    }

    // A 2xx response with an endpoint-shaped-but-empty api_key (HQ has no key
    // configured for this product yet) is a real failure the caller needs to
    // see — log it distinctly from a normal success so it's not mistaken for
    // a network problem when troubleshooting via the Recent API Activity panel.
    $is_blank_key_response = $endpoint === '/sites/register'
        && is_array($response_body)
        && array_key_exists('api_key', $response_body)
        && empty($response_body['api_key']);
    if ($is_blank_key_response) {
        cg_log_api_hit($endpoint, $method, false, $response_code, __('Headquarters returned no API key for this product.', 'checkoutguard'));
    } else {
        cg_log_api_hit($endpoint, $method, true, $response_code);
    }

    return $response_body;
}

/**
 * Saves cg_plugin_options for a change that came FROM Headquarters (API key
 * rotation, registration, settings pulled by a sync). Such a write must never
 * be pushed back to HQ: pushing from inside the write is what used to turn a
 * single X-New-Api-Key header into an unbounded request loop (the push ran
 * with the old key still in the database, HQ answered with the rotation
 * header again, and so on).
 *
 * @param array $options Full options array to store.
 */
function cg_update_options_from_hq($options)
{
    $previous = !empty($GLOBALS['cg_suppress_hq_settings_push']);
    $GLOBALS['cg_suppress_hq_settings_push'] = true;
    try {
        update_option('cg_plugin_options', $options);
    } finally {
        $GLOBALS['cg_suppress_hq_settings_push'] = $previous;
    }
}

/**
 * Stores an API key rotated by HQ. Re-entrancy safe: a nested call while a
 * rotation is being saved is ignored, and an unchanged key is not re-saved.
 *
 * @param string $new_key Key from the X-New-Api-Key response header.
 * @return bool True when a new key was stored.
 */
function cg_store_rotated_api_key($new_key)
{
    static $in_progress = false;
    if ($in_progress) {
        return false;
    }

    $new_key = sanitize_text_field($new_key);
    $options = get_option('cg_plugin_options', []);
    if (!is_array($options)) {
        $options = [];
    }
    if ($new_key === '' || (isset($options['api_key']) && $options['api_key'] === $new_key)) {
        return false;
    }

    $in_progress = true;
    try {
        $options['api_key'] = $new_key;
        cg_update_options_from_hq($options);
    } finally {
        $in_progress = false;
    }
    return true;
}

/**
 * Records one HQ API call for on-site diagnostics (see the "Recent API
 * Activity" panel on the Account & Usage tab). Keeps only the most recent 30
 * entries so this never grows unbounded; autoload is off since this is only
 * read on the settings page, not every request.
 */
function cg_log_api_hit($endpoint, $method, $success, $status_code = null, $message = '')
{
    $log = get_option('cg_api_log', []);
    if (!is_array($log)) {
        $log = [];
    }
    array_unshift($log, [
        'time' => time(),
        'endpoint' => $endpoint,
        'method' => $method,
        'success' => (bool) $success,
        'status_code' => $status_code,
        'message' => $message,
    ]);
    update_option('cg_api_log', array_slice($log, 0, 30), false);

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log(sprintf(
            '[CheckoutGuard] %s %s -> %s%s%s',
            $method,
            $endpoint,
            $success ? 'OK' : 'FAILED',
            $status_code ? " ({$status_code})" : '',
            $message ? ": {$message}" : ''
        ));
    }
}

/**
 * Verifies an Ed25519-signed payload from the HQ API (currently only
 * GET /sites/status returns one). Returns the decoded, verified payload
 * array on success, or a WP_Error on failure — same convention as
 * cg_call_hq_api() itself, so callers handle both the same way.
 *
 * This is what makes the plan/settings/limits data tamper-evident: a
 * spoofed server response, a MITM'd reply, or a directly-edited local cache
 * all fail this check, because none of them can produce a signature that
 * verifies against our public key without the server's private key.
 *
 * @param array|WP_Error $response The raw decoded response from cg_call_hq_api().
 * @return array|WP_Error The verified payload, or a WP_Error explaining why it wasn't trusted.
 */
function cg_verify_signed_payload($response)
{
    if (!function_exists('sodium_crypto_sign_verify_detached')) {
        return new WP_Error('sodium_missing', __('The libsodium extension is required to verify license signatures.', 'checkoutguard'));
    }
    if (!is_array($response) || empty($response['signature']) || !isset($response['payload_string'])) {
        return new WP_Error('signature_missing', __('Server response was not signed.', 'checkoutguard'));
    }

    $public_key = base64_decode(CG_HQ_PUBLIC_KEY);
    $signature = base64_decode($response['signature']);
    $is_valid = sodium_crypto_sign_verify_detached($signature, $response['payload_string'], $public_key);

    if (!$is_valid) {
        return new WP_Error('signature_invalid', __('Cryptographic signature verification failed — the server response may have been tampered with.', 'checkoutguard'));
    }

    $payload = json_decode($response['payload_string'], true);

    // Generous freshness window — this is a periodic poll (daily cron +
    // manual force-sync), not a nonce challenge-response, so this only
    // guards against a very stale captured response being replayed, not
    // sub-second replay the way a live challenge-response check would.
    if (!isset($payload['iat']) || abs(time() - (int) $payload['iat']) > 6 * HOUR_IN_SECONDS) {
        return new WP_Error('signature_stale', __('License server response is too old to trust — please try syncing again.', 'checkoutguard'));
    }

    return $payload;
}

/**
 * Normalizes a phone number to the Bangladeshi 01XXXXXXXXX format.
 *
 * This function handles various formats, including those with country codes,
 * spaces, hyphens, and other characters.
 *
 * @param string $phone The phone number to normalize.
 * @return string The normalized 11-digit phone number.
 */
function cg_normalize_phone_number($phone)
{
    // Return empty if the input is empty
    if (empty($phone)) {
        return '';
    }

    $cleaned_phone = preg_replace('/\D/', '', $phone);

    // If it starts with '880' and is 13 digits long (e.g., 88017...), strip '88'.
    if (strpos($cleaned_phone, '880') === 0 && strlen($cleaned_phone) === 13) {
        return substr($cleaned_phone, 2);
    }

    // If it starts with '0' and is 11 digits long, it's already in the correct format.
    if (strpos($cleaned_phone, '0') === 0 && strlen($cleaned_phone) === 11) {
        return $cleaned_phone;
    }

    // If it's 10 digits long and doesn't start with '0' (e.g., 17...), prepend '0'.
    if (strlen($cleaned_phone) === 10 && strpos($cleaned_phone, '0') !== 0) {
        return '0' . $cleaned_phone;
    }

    // If none of the above rules match, return the cleaned number as a fallback.
    return $cleaned_phone;
}

/**
 * Normalizes an address for hashing by lowercasing, trimming, and removing extra spaces.
 */
function cg_normalize_address($address_1, $city, $postcode)
{
    $parts = array_filter(array_map('trim', array_map('strtolower', [$address_1, $city, $postcode])));
    $normalized = implode('|', $parts);
    $normalized = preg_replace('/\s+/', ' ', $normalized);
    return $normalized;
}

/**
 * Creates a SHA-256 hash of a normalized address for comparison.
 */
function cg_hash_address($address_1, $city, $postcode)
{
    $normalized = cg_normalize_address($address_1, $city, $postcode);
    if (empty($normalized) || $normalized === '') {
        return '';
    }
    return hash('sha256', $normalized);
}

/**
 * Parses a user agent string into a human-readable device name like "Chrome / Windows 10+".
 *
 * @param string $user_agent The HTTP User-Agent string.
 * @return string A friendly device name, or empty string if UA is empty.
 */
function cg_get_device_name_from_ua($user_agent)
{
    if (empty($user_agent)) {
        return '';
    }

    // Detect browser
    $browser = 'Unknown';
    if (preg_match('/Edg\//', $user_agent)) {
        $browser = 'Edge';
    } elseif (preg_match('/OPR\/|Opera/', $user_agent)) {
        $browser = 'Opera';
    } elseif (preg_match('/Chrome\//', $user_agent)) {
        $browser = 'Chrome';
    } elseif (preg_match('/Safari\//', $user_agent) && !preg_match('/Chrome\//', $user_agent)) {
        $browser = 'Safari';
    } elseif (preg_match('/Firefox\//', $user_agent)) {
        $browser = 'Firefox';
    } elseif (preg_match('/MSIE|Trident/', $user_agent)) {
        $browser = 'IE';
    }

    // Detect OS
    $os = 'Unknown';
    if (preg_match('/iPhone/', $user_agent)) {
        $os = 'iPhone';
    } elseif (preg_match('/iPad/', $user_agent)) {
        $os = 'iPad';
    } elseif (preg_match('/Android/', $user_agent)) {
        $os = 'Android';
    } elseif (preg_match('/Mac OS X/', $user_agent)) {
        $os = 'macOS';
    } elseif (preg_match('/Windows NT 10/', $user_agent)) {
        $os = 'Windows 10+';
    } elseif (preg_match('/Windows NT/', $user_agent)) {
        $os = 'Windows';
    } elseif (preg_match('/Linux/', $user_agent)) {
        $os = 'Linux';
    }

    return $browser . ' / ' . $os;
}

/**
 * Calculates a fraud risk score (0-100) based on multiple signals.
 */
function cg_calculate_fraud_risk_score($ip, $email, $phone, $device_fp, $address_hash)
{
    $score = 0;
    $factors = [];

    global $wpdb;
    $attempts_table = $wpdb->prefix . 'cg_checkout_attempts';
    $since_24h = date('Y-m-d H:i:s', current_time('timestamp') - DAY_IN_SECONDS);

    // Factor 1: Courier success ratio (0-40 points)
    if ($phone) {
        $cached = get_transient('cg_courier_cache_' . md5($phone));
        if ($cached && isset($cached['summary']['success_rate'])) {
            $rate = (int) $cached['summary']['success_rate'];
            if ($rate <= 10) {
                $score += 40;
                $factors[] = "Very low courier ratio ({$rate}%)";
            } elseif ($rate <= 20) {
                $score += 30;
                $factors[] = "Low courier ratio ({$rate}%)";
            } elseif ($rate <= 40) {
                $score += 15;
                $factors[] = "Below avg ratio ({$rate}%)";
            }
        }
    }

    // Factor 2: Recent blocked attempts (0-25 points)
    if ($ip || $email || $phone) {
        $where_parts = [];
        $prepare_vals = [];
        if ($ip) {
            $where_parts[] = 'ip_address = %s';
            $prepare_vals[] = $ip;
        }
        if ($email) {
            $where_parts[] = 'email = %s';
            $prepare_vals[] = $email;
        }
        if ($phone) {
            $where_parts[] = 'phone = %s';
            $prepare_vals[] = $phone;
        }
        $where_sql = implode(' OR ', $where_parts);
        $prepare_vals[] = $since_24h;

        $blocked_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$attempts_table} WHERE ({$where_sql}) AND attempt_result != 'allowed' AND created_at > %s",
            ...$prepare_vals
        ));
        if ($blocked_count >= 5) {
            $score += 25;
            $factors[] = "{$blocked_count} blocks in 24h";
        } elseif ($blocked_count >= 3) {
            $score += 15;
            $factors[] = "{$blocked_count} blocks in 24h";
        } elseif ($blocked_count >= 1) {
            $score += 8;
            $factors[] = "{$blocked_count} block(s) in 24h";
        }
    }

    // Factor 3: Velocity/frequency (0-15 points)
    if ($ip) {
        $since_1h = date('Y-m-d H:i:s', current_time('timestamp') - HOUR_IN_SECONDS);
        $total_attempts = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$attempts_table} WHERE ip_address = %s AND created_at > %s",
            $ip, $since_1h
        ));
        if ($total_attempts >= 10) {
            $score += 15;
            $factors[] = "{$total_attempts} attempts in 1h";
        } elseif ($total_attempts >= 5) {
            $score += 8;
            $factors[] = "{$total_attempts} attempts in 1h";
        }
    }

    // Factor 4: Disposable email domain (0-10 points)
    if ($email) {
        $domain = strtolower(substr($email, strrpos($email, '@') + 1));
        $disposable_domains = apply_filters('cg_disposable_email_domains', [
            'tempmail.com', 'guerrillamail.com', 'throwaway.email', 'yopmail.com',
            'mailinator.com', '10minutemail.com', 'guerrillamailblock.com',
            'trashmail.com', 'fakeinbox.com', 'sharklasers.com', 'guerrillamail.info',
            'grr.la', 'dispostable.com', 'maildrop.cc',
        ]);
        if (in_array($domain, $disposable_domains, true)) {
            $score += 10;
            $factors[] = "Disposable email ({$domain})";
        }
    }

    // Factor 5: Device previously associated with blocks (0-10 points)
    if ($device_fp) {
        $device_blocks = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$attempts_table} WHERE device_fingerprint = %s AND attempt_result != 'allowed'",
            $device_fp
        ));
        if ($device_blocks >= 3) {
            $score += 10;
            $factors[] = "Device: {$device_blocks} blocks";
        } elseif ($device_blocks >= 1) {
            $score += 5;
            $factors[] = "Device: {$device_blocks} block(s)";
        }
    }

    return ['score' => min($score, 100), 'factors' => $factors];
}

/**
 * Cleans up expired blocks from all blocklist tables and old checkout attempts.
 */
function cg_cleanup_expired_blocks_handler()
{
    global $wpdb;
    $now = current_time('mysql');

    // Map each table to its corresponding usage action type
    $tables = [
        $wpdb->prefix . 'cg_blocked_ips'       => 'fraud_ips',
        $wpdb->prefix . 'cg_blocked_emails'     => 'fraud_emails',
        $wpdb->prefix . 'cg_blocked_numbers'    => 'fraud_phones',
        $wpdb->prefix . 'cg_blocked_domains'    => 'fraud_domains',
        $wpdb->prefix . 'cg_blocked_addresses'  => 'fraud_addresses',
        $wpdb->prefix . 'cg_blocked_devices'    => 'fraud_devices',
    ];

    foreach ($tables as $table => $action_type) {
        // Only rows that were counted toward usage give a decrement back;
        // automatic blocks (usage_counted = 0) were never counted. The
        // decrements follow the rows this DELETE really removed (no separate
        // COUNT), so a row deleted by someone else in between is never
        // decremented twice. Without the column (migration not done yet)
        // behave like 1.4.2: every expired row is counted.
        $has_column = cg_blocklist_has_usage_counted($table);
        $counted_sql = $has_column ? ' AND COALESCE(usage_counted, 1) = 1' : '';
        $counted_deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE expires_at IS NOT NULL AND expires_at < %s{$counted_sql}",
            $now
        ));
        for ($i = 0; $i < (int) $counted_deleted; $i++) {
            cg_decrement_usage_count($action_type);
        }
        if ($has_column) {
            // Expired automatic blocks: removed without any decrement.
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table} WHERE expires_at IS NOT NULL AND expires_at < %s AND usage_counted = 0",
                $now
            ));
        }
    }

    // Clean old checkout_attempts older than 7 days
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->prefix}cg_checkout_attempts WHERE created_at < %s",
        date('Y-m-d H:i:s', current_time('timestamp') - (7 * DAY_IN_SECONDS))
    ));
}

/**
 * Plan slugs that always unlock the Pro features. Matched exactly after
 * strtolower(trim()); anything else (a typo, "pro-yearly", a slug HQ adds
 * later) is not Pro until it is added here.
 */
define('CG_PRO_PLAN_SLUGS', ['pro', 'custom']);

/**
 * Plans that keep the Pro features while the signed /sites/status payload
 * carries a future `grandfathered_until` (sites that were on these plans
 * before the 1.5.0 plan change).
 */
define('CG_GRANDFATHERED_PLAN_SLUGS', ['basic', 'standard']);

/**
 * The cached plan slug, lowercased and trimmed, or null when unknown.
 *
 * @param mixed $cache The cg_api_cache value (verified /sites/status payload).
 * @return string|null
 */
function cg_plan_slug($cache)
{
    if (!is_array($cache)) {
        return null;
    }
    // The backend's /sites/status response key is "plan_slug" (falls back to
    // the older "plan" key in case a future response ever sends both).
    $plan_value = $cache['plan_slug'] ?? $cache['plan'] ?? null;
    if (!is_string($plan_value)) {
        return null;
    }
    $plan = strtolower(trim($plan_value));
    return $plan === '' ? null : $plan;
}

/**
 * True when a Basic/Standard site is still inside its grandfather window.
 * The field must be present, non-null, a valid RFC3339 time with a zone, and
 * in the future. A missing key (older HQ) or null means no grandfather.
 *
 * @param mixed $cache The cg_api_cache value.
 * @return bool
 */
function cg_is_grandfathered($cache)
{
    if (!in_array(cg_plan_slug($cache), CG_GRANDFATHERED_PLAN_SLUGS, true)) {
        return false;
    }
    $until_raw = $cache['grandfathered_until'] ?? null;
    if (!is_string($until_raw) || !function_exists('cg_parse_rfc3339_utc')) {
        return false;
    }
    $until = cg_parse_rfc3339_utc($until_raw);
    if ($until === null || $until === false) {
        return false;
    }
    $now = function_exists('cg_reveal_now') ? cg_reveal_now() : time();
    return $now < (int) $until;
}

/**
 * Checks if the current site may use the Pro features.
 * Pro and Custom always; Basic and Standard only while grandfathered.
 * Fail-closed: false if the cache is missing or the plan is unknown.
 *
 * @return bool
 */
function cg_is_pro_plan()
{
    $cache = get_transient('cg_api_cache');
    $plan = cg_plan_slug($cache);
    if ($plan === null) {
        return false;
    }
    if (in_array($plan, CG_PRO_PLAN_SLUGS, true)) {
        return true;
    }
    return cg_is_grandfathered($cache);
}

/**
 * Masks a phone number, showing only the first 3 digits.
 * Example: "01812345678" → "018********"
 *
 * @param string $phone The phone number to mask.
 * @return string The masked phone number.
 */
function cg_mask_phone($phone)
{
    if (strlen($phone) <= 3) {
        return $phone;
    }
    return substr($phone, 0, 3) . str_repeat('*', strlen($phone) - 3);
}

/**
 * Masks an email address, showing only the first 2 characters and domain.
 * Example: "customer@gmail.com" → "cu***@gmail.com"
 *
 * @param string $email The email address to mask.
 * @return string The masked email address.
 */
function cg_mask_email($email)
{
    if (empty($email) || strpos($email, '@') === false) {
        return $email;
    }
    $parts = explode('@', $email, 2);
    $local = $parts[0];
    $domain = $parts[1];
    if (strlen($local) <= 2) {
        return $local . '***@' . $domain;
    }
    return substr($local, 0, 2) . '***@' . $domain;
}

/**
 * Neutralizes CSV formula injection by prefixing values that start with a
 * formula-triggering character (=, +, -, @, tab, CR) with a single quote.
 * Spreadsheet apps (Excel, Sheets, LibreOffice) treat such cells as literal
 * text instead of executing them as formulas.
 *
 * @param string $value The raw cell value.
 * @return string The value, safe to place in a CSV cell.
 */
function cg_csv_escape_formula($value)
{
    $value = (string) $value;
    if ($value !== '' && strpbrk($value[0], "=+-@\t\r") !== false) {
        return "'" . $value;
    }
    return $value;
}

/**
 * Reads order meta in an HPOS-safe way.
 *
 * With High-Performance Order Storage enabled, order meta lives in the
 * WooCommerce order tables rather than in postmeta, so get_post_meta() on an
 * order ID silently returns nothing. Always go through the order object.
 *
 * @param int    $order_id
 * @param string $key
 * @param bool   $single
 * @return mixed Empty string when the order can't be loaded.
 */
function cg_get_order_meta($order_id, $key, $single = true)
{
    if (!function_exists('wc_get_order')) {
        return '';
    }
    $order = wc_get_order($order_id);
    return $order ? $order->get_meta($key, $single) : '';
}

/**
 * Writes order meta in an HPOS-safe way. Counterpart to cg_get_order_meta().
 *
 * @param int    $order_id
 * @param string $key
 * @param mixed  $value
 * @return bool True when the write was persisted.
 */
function cg_update_order_meta($order_id, $key, $value)
{
    if (!function_exists('wc_get_order')) {
        return false;
    }
    $order = wc_get_order($order_id);
    if (!$order) {
        return false;
    }
    $order->update_meta_data($key, $value);
    $order->save();
    return true;
}

// ====================================================================
// ADVANCED IP RESOLUTION & CLOUDFLARE PROTECTION
// ====================================================================

/**
 * Checks if an IPv4 address is within a CIDR subnet (e.g. 172.64.0.0/13).
 */
function cg_ipv4_in_cidr($ip, $cidr)
{
    if (strpos($cidr, '/') === false) {
        return $ip === $cidr;
    }
    list($subnet, $mask) = explode('/', $cidr);
    $ip_long = ip2long($ip);
    $subnet_long = ip2long($subnet);
    if ($ip_long === false || $subnet_long === false) {
        return false;
    }
    $mask_int = ~((1 << (32 - (int) $mask)) - 1);
    return ($ip_long & $mask_int) === ($subnet_long & $mask_int);
}

/**
 * Checks if an IP address belongs to Cloudflare's published CDN IP ranges.
 */
function cg_is_cloudflare_ip($ip)
{
    if (empty($ip) || !is_string($ip)) {
        return false;
    }

    // Official Cloudflare IPv4 CIDR blocks
    $cf_ipv4 = [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
    ];

    foreach ($cf_ipv4 as $cidr) {
        if (cg_ipv4_in_cidr($ip, $cidr)) {
            return true;
        }
    }

    // Cloudflare IPv6 prefix checks
    $cf_ipv6_prefixes = [
        '2400:cb00:',
        '2606:4700:',
        '2803:f800:',
        '2405:b500:',
        '2405:8100:',
        '2a06:98c0:',
        '2c0f:f248:',
    ];
    $ip_lower = strtolower($ip);
    foreach ($cf_ipv6_prefixes as $prefix) {
        if (strncmp($ip_lower, $prefix, strlen($prefix)) === 0) {
            return true;
        }
    }

    return false;
}

/**
 * Checks if an IP address is a protected system IP that should NEVER be blocked.
 * Prevents blocking localhost, internal network, server IP, Cloudflare proxy IP, or current admin IP.
 */
function cg_is_protected_ip($ip)
{
    if (empty($ip)) {
        return true;
    }

    // Localhost & loopback
    if ($ip === '127.0.0.1' || $ip === '::1' || $ip === 'localhost') {
        return true;
    }

    // Private subnets (RFC 1918)
    $private_cidrs = [
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
    ];
    foreach ($private_cidrs as $cidr) {
        if (cg_ipv4_in_cidr($ip, $cidr)) {
            return true;
        }
    }

    // Server's own IP
    if (!empty($_SERVER['SERVER_ADDR']) && $ip === $_SERVER['SERVER_ADDR']) {
        return true;
    }

    // Cloudflare Proxy IP
    if (cg_is_cloudflare_ip($ip)) {
        return true;
    }

    // If current logged-in user is an admin or store manager, protect their IP
    if (is_user_logged_in() && (current_user_can('manage_options') || current_user_can('manage_woocommerce'))) {
        $current_ip = cg_get_client_ip();
        if ($ip === $current_ip) {
            return true;
        }
    }

    return false;
}

/**
 * Retrieves the genuine visitor IP address with full Cloudflare and reverse proxy support.
 *
 * Checks in order of trust:
 * 1. HTTP_CF_CONNECTING_IP (Cloudflare)
 * 2. HTTP_TRUE_CLIENT_IP (Cloudflare Enterprise / Akamai)
 * 3. HTTP_X_REAL_IP (Nginx reverse proxy)
 * 4. HTTP_X_FORWARDED_FOR (Validates chain and selects first public IP)
 * 5. WC_Geolocation::get_ip_address() (WooCommerce fallback)
 * 6. $_SERVER['REMOTE_ADDR']
 *
 * @return string Validated IP address or '127.0.0.1'
 */
function cg_get_client_ip()
{
    $candidates = [];

    // 1. Cloudflare header
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $candidates[] = trim($_SERVER['HTTP_CF_CONNECTING_IP']);
    }

    // 2. Akamai / Cloudflare Enterprise
    if (!empty($_SERVER['HTTP_TRUE_CLIENT_IP'])) {
        $candidates[] = trim($_SERVER['HTTP_TRUE_CLIENT_IP']);
    }

    // 3. Nginx / Caddy X-Real-IP
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $candidates[] = trim($_SERVER['HTTP_X_REAL_IP']);
    }

    // 4. X-Forwarded-For (can be comma-separated: client, proxy1, proxy2)
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        foreach ($parts as $part) {
            $part = trim($part);
            if (!empty($part)) {
                $candidates[] = $part;
            }
        }
    }

    // 5. WooCommerce Geolocation helper
    if (class_exists('WC_Geolocation') && method_exists('WC_Geolocation', 'get_ip_address')) {
        $wc_ip = WC_Geolocation::get_ip_address();
        if (!empty($wc_ip)) {
            $candidates[] = trim($wc_ip);
        }
    }

    // 6. Direct REMOTE_ADDR
    if (!empty($_SERVER['REMOTE_ADDR'])) {
        $candidates[] = trim($_SERVER['REMOTE_ADDR']);
    }

    // First pass: find first valid PUBLIC non-Cloudflare IP
    foreach ($candidates as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            if (!cg_is_cloudflare_ip($ip)) {
                return $ip;
            }
        }
    }

    // Second pass: any valid public IP (including Cloudflare if no other header present)
    foreach ($candidates as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $ip;
        }
    }

    // Third pass: any valid IP (e.g. local test environments)
    foreach ($candidates as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }

    return '127.0.0.1';
}

/**
 * Checks if the current page request is a checkout context across classic WooCommerce,
 * modern Gutenberg Block checkout (Twenty Twenty-Five / FSE), and CartFlows checkout steps.
 *
 * @return bool
 */
function cg_is_checkout_context()
{
    if (is_admin()) {
        return false;
    }

    if (function_exists('is_order_received_page') && is_order_received_page()) {
        return false;
    }

    if (function_exists('is_checkout_pay_page') && is_checkout_pay_page()) {
        return false;
    }

    // Classic WooCommerce checkout
    if (function_exists('is_checkout') && is_checkout()) {
        return true;
    }

    // Single product pages (frequently embed direct "Order Now" / COD forms)
    if (function_exists('is_product') && is_product()) {
        return true;
    }

    // CartFlows detection
    if (function_exists('_is_wcf_checkout_type') && _is_wcf_checkout_type()) {
        return true;
    }
    if (function_exists('wcf') && method_exists(wcf(), 'is_checkout') && wcf()->is_checkout()) {
        return true;
    }

    // Gutenberg Block / CartFlows post type or block detection / Custom Landing Pages
    global $post;
    if ($post instanceof WP_Post) {
        if ($post->post_type === 'cartflows_step' || strpos($post->post_type, 'landing') !== false || strpos($post->post_type, 'checkout') !== false) {
            return true;
        }
        if (function_exists('has_block')) {
            if (has_block('woocommerce/checkout', $post) || has_block('cartflows/checkout', $post)) {
                return true;
            }
        }
        if (!empty($post->post_content)) {
            if (
                has_shortcode($post->post_content, 'woocommerce_checkout') ||
                has_shortcode($post->post_content, 'cartflows_checkout') ||
                strpos($post->post_content, 'co_order_') !== false ||
                preg_match('/\[(?:co_order_|cartflows_|wc_checkout|quick_order|fast_checkout|easy_checkout)/i', $post->post_content)
            ) {
                return true;
            }
        }
        // Elementor landing page data check for checkout / order widgets
        $elementor_data = get_post_meta($post->ID, '_elementor_data', true);
        if (is_string($elementor_data) && (strpos($elementor_data, 'checkout') !== false || strpos($elementor_data, 'order') !== false || strpos($elementor_data, 'billing_') !== false)) {
            return true;
        }
    }

    // URL path fallback for custom checkout endpoints, landing pages, and Twenty Twenty-Five FSE templates
    if (isset($_SERVER['REQUEST_URI'])) {
        $uri = strtolower(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '');
        if (
            strpos($uri, 'checkout') !== false ||
            strpos($uri, 'cartflow') !== false ||
            strpos($uri, 'order-now') !== false ||
            strpos($uri, 'quick-order') !== false ||
            strpos($uri, 'order') !== false ||
            strpos($uri, 'landing') !== false
        ) {
            return true;
        }
    }

    return (bool) apply_filters('cg_is_checkout_context', false);
}

/**
 * Blocklist tables and the usage key each one is counted under.
 */
function cg_blocklist_usage_tables()
{
    global $wpdb;
    return [
        $wpdb->prefix . 'cg_blocked_ips'       => 'fraud_ips',
        $wpdb->prefix . 'cg_blocked_emails'    => 'fraud_emails',
        $wpdb->prefix . 'cg_blocked_numbers'   => 'fraud_phones',
        $wpdb->prefix . 'cg_blocked_domains'   => 'fraud_domains',
        $wpdb->prefix . 'cg_blocked_addresses' => 'fraud_addresses',
        $wpdb->prefix . 'cg_blocked_devices'   => 'fraud_devices',
    ];
}

/**
 * Runtime guard: does this blocklist table have the usage_counted column?
 * Cached per request. When it is missing (migration failed or has not run)
 * every caller falls back to the 1.4.2 behaviour instead of writing or
 * filtering on a column that does not exist.
 *
 * @param string|null $table Full table name.
 * @param bool        $reset Clear the per-request cache (after a migration).
 */
function cg_blocklist_has_usage_counted($table, $reset = false)
{
    static $cache = [];
    if ($reset) {
        $cache = [];
        return false;
    }
    if (!isset($cache[$table])) {
        global $wpdb;
        $cache[$table] = !empty($wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'usage_counted'"));
    }
    return $cache[$table];
}

/**
 * Inserts an automatic block. Automatic blocks do not count toward the
 * plan's fraud_* usage (1.5.0), so no increment is sent and the row is
 * stored with usage_counted = 0. Without the column it behaves like 1.4.2
 * (counted and incremented), so nothing breaks before the migration.
 *
 * @return int|false Result of $wpdb->insert().
 */
function cg_insert_auto_block($table, array $data, $action_type)
{
    global $wpdb;
    $has_column = cg_blocklist_has_usage_counted($table);
    if ($has_column) {
        $data['usage_counted'] = 0;
    }
    $inserted = $wpdb->insert($table, $data);
    if ($inserted && !$has_column) {
        cg_increment_usage_count($action_type);
    }
    return $inserted;
}

/**
 * Deletes one blocklist row and gives usage back only if that row was
 * counted.
 *
 * @return int|false Rows deleted: 1 on success, 0 when nothing matched (no
 *                   decrement is sent), false on a database error.
 *
 * @param string $table       Full table name.
 * @param string $column      Column to match on ('id' or the value column).
 * @param mixed  $value       Value to match.
 * @param string $action_type Usage key (fraud_*).
 */
function cg_delete_blocklist_row($table, $column, $value, $action_type)
{
    global $wpdb;
    $has_column = cg_blocklist_has_usage_counted($table);
    $format = $column === 'id' ? '%d' : '%s';
    $select = $has_column ? 'id, usage_counted' : 'id';
    $wpdb->last_error = '';
    $row = $wpdb->get_row($wpdb->prepare("SELECT {$select} FROM {$table} WHERE {$column} = {$format} LIMIT 1", $value));
    if (!$row) {
        return $wpdb->last_error !== '' ? false : 0;
    }
    $deleted = $wpdb->delete($table, ['id' => (int) $row->id], ['%d']);
    if ($deleted === false) {
        return false;
    }
    $counted = !$has_column || $row->usage_counted === null || (int) $row->usage_counted === 1;
    if ($deleted > 0 && $counted) {
        cg_decrement_usage_count($action_type);
    }
    return (int) $deleted;
}

/**
 * Number of active automatic blocks that are not counted toward usage.
 * Shown next to the usage row as "+N auto-blocked (not counted)".
 */
function cg_count_uncounted_auto_blocks($table)
{
    global $wpdb;
    if (!cg_blocklist_has_usage_counted($table)) {
        return 0;
    }
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE usage_counted = 0 AND (expires_at IS NULL OR expires_at >= %s)",
        current_time('mysql')
    ));
}
