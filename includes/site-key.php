<?php

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Site key (contract v1): each site trades the shared product key for its own
 * czsite_ key. Flow: request-site-key -> HQ posts a nonce to our REST route ->
 * claim-key with that nonce -> the returned key is pending until the first
 * successful call with it, which commits it.
 *
 * Nothing here ever blocks a checkout: while the site has no working key the
 * fraud check is skipped (fail-open) and the admin sees a notice. The key and
 * the nonce never go into logs, notices or responses.
 */

define('CG_SITEKEY_PREFIX', 'czsite_');
define('CG_SITEKEY_STATE_OPTION', 'cg_sitekey_state');
define('CG_SITEKEY_GATE_OPTION', 'cg_sitekey_next_allowed_at');
define('CG_SITEKEY_LOCK_OPTION', 'cg_sitekey_lock');
define('CG_SITEKEY_LOCK_STALE_AFTER', 120);
define('CG_SITEKEY_BUDGET_WINDOW', HOUR_IN_SECONDS);
define('CG_SITEKEY_BUDGET_MAX', 2);
define('CG_SITEKEY_NONCE_WINDOW', 5 * MINUTE_IN_SECONDS);
define('CG_SITEKEY_NONCE_TTL', 5 * MINUTE_IN_SECONDS);
define('CG_SITEKEY_MAX_NONCES', 3);
define('CG_SITEKEY_PENDING_TTL', HOUR_IN_SECONDS);

function cg_sitekey_now()
{
    return (int) apply_filters('cg_sitekey_now', time());
}

/**
 * Reads an option straight from the database (bypasses the object cache, so
 * a value written by a parallel request is seen). Only this option's cache
 * entry is dropped: every site-key option is stored with autoload off, so it
 * is never part of alloptions, and alloptions is left alone (dropping it on
 * every HQ answer would cost object-cache sites a reload each time).
 */
function cg_sitekey_read_raw($name)
{
    global $wpdb;
    $value = $wpdb->get_var($wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
        $name
    ));
    wp_cache_delete($name, 'options');
    return $value;
}

function cg_sitekey_state()
{
    $raw = cg_sitekey_read_raw(CG_SITEKEY_STATE_OPTION);
    $state = $raw === null ? [] : maybe_unserialize($raw);
    return is_array($state) ? $state : [];
}

function cg_sitekey_save_state(array $state)
{
    update_option(CG_SITEKEY_STATE_OPTION, $state, false);
}

function cg_sitekey_gate()
{
    return (int) cg_sitekey_read_raw(CG_SITEKEY_GATE_OPTION);
}

function cg_sitekey_set_gate($ts)
{
    update_option(CG_SITEKEY_GATE_OPTION, (int) $ts, false);
}

/**
 * Wait after the n-th failed attempt (0-based): 31m, 2h, 6h, 24h. The first
 * step is above 30 minutes so two attempts never fall in one 60-minute window
 * by backoff alone; the sliding budget below is the hard limit.
 */
function cg_sitekey_backoff_seconds($step)
{
    $steps = [31 * MINUTE_IN_SECONDS, 2 * HOUR_IN_SECONDS, 6 * HOUR_IN_SECONDS, DAY_IN_SECONDS];
    $step = max(0, min((int) $step, count($steps) - 1));
    return $steps[$step];
}

/**
 * Atomic lock: INSERT IGNORE succeeds for exactly one request (add_option()
 * checks in PHP first and is not atomic). A stale lock is removed by its exact
 * value only, then the race runs again.
 *
 * @return string|false Lock token, or false when another request holds it.
 */
function cg_sitekey_acquire_lock()
{
    global $wpdb;
    $now = cg_sitekey_now();
    $token = $now . '-' . wp_rand(100000, 999999);
    $autoload = function_exists('wp_determine_option_autoload_value') ? 'off' : 'no';
    $insert = function () use ($wpdb, $token, $autoload) {
        $n = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
            CG_SITEKEY_LOCK_OPTION,
            $token,
            $autoload
        ));
        wp_cache_delete(CG_SITEKEY_LOCK_OPTION, 'options');
        wp_cache_delete('notoptions', 'options');
        return $n === 1;
    };
    if ($insert()) {
        return $token;
    }
    $held = cg_sitekey_read_raw(CG_SITEKEY_LOCK_OPTION);
    if ($held === null) {
        return $insert() ? $token : false;
    }
    $held_at = (int) strtok((string) $held, '-');
    if ($held_at > 0 && ($now - $held_at) < CG_SITEKEY_LOCK_STALE_AFTER) {
        return false;
    }
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
        CG_SITEKEY_LOCK_OPTION,
        (string) $held
    ));
    return $insert() ? $token : false;
}

function cg_sitekey_release_lock($token)
{
    global $wpdb;
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
        CG_SITEKEY_LOCK_OPTION,
        (string) $token
    ));
    wp_cache_delete(CG_SITEKEY_LOCK_OPTION, 'options');
}

function cg_sitekey_current_key()
{
    $options = get_option('cg_plugin_options', []);
    return (is_array($options) && !empty($options['api_key'])) ? (string) $options['api_key'] : '';
}

function cg_sitekey_is_site_key($key)
{
    return is_string($key) && strpos($key, CG_SITEKEY_PREFIX) === 0;
}

/**
 * True when the site still runs on the shared key (or on a pending site key
 * that has not been committed yet).
 */
function cg_sitekey_needed()
{
    $key = cg_sitekey_current_key();
    if ($key === '') {
        return false; // Registration handles a site without any key.
    }
    $state = cg_sitekey_state();
    return !cg_sitekey_is_site_key($key) || !empty($state['pending_until']);
}

/**
 * Called by cg_call_hq_api() after every HQ answer (never for request-site-key
 * or claim-key themselves). Commits or rolls back a pending key and reacts to
 * the legacy deadline codes. Only schedules work; never calls HQ itself, so a
 * customer-facing request never waits for the key exchange.
 *
 * @param int    $http_code HTTP status, 0 for a transport error.
 * @param string $code      The "code" field of the error body, or ''.
 * @param string $sent_key  The X-Api-Key this request carried.
 */
function cg_sitekey_on_response($http_code, $code, $sent_key)
{
    $legacy_code = ($http_code === 409 && $code === 'claim_required') || ($http_code === 403 && $code === 'legacy_key_expired');
    // Hot path (every HQ answer, e.g. each checkout's courier check): with the
    // shared key and no deadline code there is nothing to do, so no database
    // read. A pending key always means the request carried a czsite_ key.
    if (!$legacy_code && !cg_sitekey_is_site_key($sent_key)) {
        return;
    }

    $state = cg_sitekey_state();

    // Only an answer to the exact pending key may commit or roll it back.
    $is_pending_key = !empty($state['pending_until'])
        && cg_sitekey_is_site_key($sent_key)
        && hash_equals(cg_sitekey_current_key(), (string) $sent_key);
    if ($is_pending_key) {
        if ($http_code >= 200 && $http_code < 300) {
            cg_sitekey_commit();
            return;
        }
        if ($http_code === 401) {
            cg_sitekey_rollback('rejected');
            return;
        }
    }

    if ($legacy_code) {
        $state = cg_sitekey_state();
        $state['legacy_blocked_at'] = cg_sitekey_now();
        $state['legacy_code'] = $code;
        cg_sitekey_save_state($state);
        cg_sitekey_schedule('cg_sitekey_request_event');
    }
}

function cg_sitekey_schedule($hook)
{
    if (!wp_next_scheduled($hook)) {
        wp_schedule_single_event(cg_sitekey_now(), $hook);
    }
}

function cg_sitekey_commit()
{
    $state = cg_sitekey_state();
    unset($state['pending_until'], $state['legacy_key'], $state['legacy_blocked_at'], $state['legacy_code']);
    $state['committed_at'] = cg_sitekey_now();
    $state['fails'] = 0;
    cg_sitekey_save_state($state);
    cg_log_api_hit('/sites/claim-key', 'POST', true, null, __('Site key confirmed.', 'checkoutguard'));
}

/**
 * Puts the shared key back when the pending site key was rejected or was not
 * confirmed within its hour.
 */
function cg_sitekey_rollback($why)
{
    $state = cg_sitekey_state();
    if (empty($state['pending_until'])) {
        return;
    }
    $options = get_option('cg_plugin_options', []);
    if (!is_array($options)) {
        $options = [];
    }
    if (!empty($state['legacy_key'])) {
        $options['api_key'] = (string) $state['legacy_key'];
        cg_update_options_from_hq($options);
    }
    unset($state['pending_until'], $state['legacy_key']);
    $state['fails'] = (int) ($state['fails'] ?? 0) + 1;
    cg_sitekey_save_state($state);
    cg_sitekey_set_gate(max(cg_sitekey_gate(), cg_sitekey_now() + cg_sitekey_backoff_seconds($state['fails'] - 1)));
    cg_log_api_hit('/sites/claim-key', 'POST', false, null, $why === 'expired'
        ? __('Site key was not confirmed in time; using the previous key.', 'checkoutguard')
        : __('Site key was rejected; using the previous key.', 'checkoutguard'));
}

/**
 * A pending key older than its hour is no longer accepted by HQ.
 */
function cg_sitekey_expire_pending()
{
    $state = cg_sitekey_state();
    if (!empty($state['pending_until']) && cg_sitekey_now() >= (int) $state['pending_until']) {
        cg_sitekey_rollback('expired');
    }
}

/**
 * Prunes the sliding budget and tells whether one more request fits.
 */
function cg_sitekey_budget_ok(array &$state)
{
    $now = cg_sitekey_now();
    $times = isset($state['requests']) && is_array($state['requests']) ? $state['requests'] : [];
    $times = array_values(array_filter($times, function ($t) use ($now) {
        return ($now - (int) $t) < CG_SITEKEY_BUDGET_WINDOW;
    }));
    $state['requests'] = $times;
    return count($times) < CG_SITEKEY_BUDGET_MAX;
}

/**
 * Step 1: ask HQ to send a nonce. At most one caller at a time, never before
 * the gate time, at most 2 per sliding hour.
 *
 * @return string One of: not_needed, gated, budget, locked, accepted, failed.
 */
function cg_sitekey_request($reason = 'auto')
{
    cg_sitekey_expire_pending();
    $state = cg_sitekey_state();
    if (!cg_sitekey_needed() || !empty($state['pending_until'])) {
        return 'not_needed';
    }
    if (cg_sitekey_now() < cg_sitekey_gate()) {
        return 'gated';
    }
    $token = cg_sitekey_acquire_lock();
    if ($token === false) {
        return 'locked';
    }
    try {
        // Re-read under the lock: a parallel request may have just gone out.
        $state = cg_sitekey_state();
        $now = cg_sitekey_now();
        if ($now < cg_sitekey_gate()) {
            return 'gated';
        }
        if (!cg_sitekey_budget_ok($state)) {
            cg_sitekey_save_state($state);
            cg_sitekey_set_gate((int) $state['requests'][0] + CG_SITEKEY_BUDGET_WINDOW);
            return 'budget';
        }

        $fails = (int) ($state['fails'] ?? 0);
        // The gate moves before the call, so a request that dies mid-call
        // still cannot be repeated early.
        cg_sitekey_set_gate($now + cg_sitekey_backoff_seconds($fails));
        $state['requests'][] = $now;
        $state['window_until'] = $now + CG_SITEKEY_NONCE_WINDOW;
        $state['nonces'] = [];
        $state['last_reason'] = sanitize_key($reason);
        cg_sitekey_save_state($state);

        $GLOBALS['cg_sitekey_in_flow'] = true;
        try {
            $response = cg_call_hq_api('/sites/request-site-key', [], 'POST');
        } finally {
            $GLOBALS['cg_sitekey_in_flow'] = false;
        }

        if (!is_wp_error($response)) {
            return 'accepted';
        }
        $data = $response->get_error_data();
        $retry_after = is_array($data) ? (int) ($data['retry_after'] ?? 0) : 0;
        $state = cg_sitekey_state();
        $state['window_until'] = 0;
        $state['fails'] = $fails + 1;
        cg_sitekey_save_state($state);
        cg_sitekey_set_gate($now + max(cg_sitekey_backoff_seconds($fails), $retry_after));
        return 'failed';
    } finally {
        cg_sitekey_release_lock($token);
    }
}

/**
 * REST: HQ posts the nonce here. Accepted only while a request of ours is
 * open and only in the documented shape. The last 3 are kept (newest wins),
 * so junk posted first cannot push out HQ's real nonce; the claim tries the
 * newest first. The answer is the same 200 in every case, so the route tells
 * an outsider nothing.
 */
function cg_sitekey_receive_nonce(WP_REST_Request $request)
{
    $nonce = $request->get_param('nonce');
    $now = cg_sitekey_now();
    if (is_string($nonce) && preg_match('/^[A-Za-z0-9_-]{43}$/', $nonce)) {
        $state = cg_sitekey_state();
        $open = !empty($state['window_until']) && $now <= (int) $state['window_until'];
        $nonces = isset($state['nonces']) && is_array($state['nonces']) ? $state['nonces'] : [];
        $seen = in_array($nonce, array_column($nonces, 'n'), true);
        if ($open && !$seen) {
            $nonces[] = ['n' => $nonce, 'at' => $now];
            $state['nonces'] = array_slice($nonces, -CG_SITEKEY_MAX_NONCES);
            cg_sitekey_save_state($state);
            cg_sitekey_schedule('cg_sitekey_claim_event');
            if (function_exists('spawn_cron')) {
                spawn_cron(); // Claim within the nonce's 5 minutes, even on a quiet site.
            }
        }
    }
    return new WP_REST_Response(['ok' => true], 200);
}

function cg_sitekey_register_routes()
{
    register_rest_route('checkoutguard/v1', '/key-nonce', [
        'methods' => 'POST',
        'callback' => 'cg_sitekey_receive_nonce',
        // Called by HQ, server to server; protected by the open-window check
        // and by HQ checking the nonce on claim.
        'permission_callback' => '__return_true',
    ]);
}
add_action('rest_api_init', 'cg_sitekey_register_routes');

/**
 * Step 3: trade a received nonce for a pending site key, newest nonce first,
 * then confirm it at once with a signed status sync.
 *
 * @return string One of: none, locked, claimed, committed, invalid, failed.
 */
function cg_sitekey_claim()
{
    $token = cg_sitekey_acquire_lock();
    if ($token === false) {
        return 'locked';
    }
    $result = 'none';
    try {
        $state = cg_sitekey_state();
        $now = cg_sitekey_now();
        $nonces = isset($state['nonces']) && is_array($state['nonces']) ? $state['nonces'] : [];
        $state['nonces'] = [];
        $state['window_until'] = 0;
        cg_sitekey_save_state($state);
        if (!empty($state['pending_until']) || !cg_sitekey_needed()) {
            return 'none';
        }

        $retry_after = 0;
        foreach (array_reverse($nonces) as $entry) {
            if (!is_array($entry) || empty($entry['n']) || ($now - (int) ($entry['at'] ?? 0)) >= CG_SITEKEY_NONCE_TTL) {
                continue;
            }
            $GLOBALS['cg_sitekey_in_flow'] = true;
            try {
                $response = cg_call_hq_api('/sites/claim-key', ['nonce' => (string) $entry['n']], 'POST');
            } finally {
                $GLOBALS['cg_sitekey_in_flow'] = false;
            }
            if (!is_wp_error($response)) {
                $key = is_array($response) && isset($response['api_key']) ? (string) $response['api_key'] : '';
                if (!cg_sitekey_is_site_key($key) || !preg_match('/^[A-Za-z0-9_-]+$/', $key)) {
                    $result = 'failed';
                    break;
                }
                $options = get_option('cg_plugin_options', []);
                if (!is_array($options)) {
                    $options = [];
                }
                $state = cg_sitekey_state();
                $state['legacy_key'] = (string) ($options['api_key'] ?? '');
                $state['pending_until'] = $now + CG_SITEKEY_PENDING_TTL;
                cg_sitekey_save_state($state);
                $options['api_key'] = $key;
                cg_update_options_from_hq($options);
                $result = 'claimed';
                break;
            }
            $data = $response->get_error_data();
            $status = is_array($data) ? (int) ($data['status'] ?? 0) : 0;
            $retry_after = max($retry_after, is_array($data) ? (int) ($data['retry_after'] ?? 0) : 0);
            if ($status === 401) {
                $result = 'invalid';
                continue; // Try the next nonce (one may be a stray post).
            }
            $result = 'failed';
            break;
        }

        // A round where every nonce got 401 does not grow the backoff: the
        // nonces may have been posted by an outsider, and the request gate
        // (set before the request) still limits the next try. Other failures
        // back off as usual.
        if ($result === 'failed') {
            $state = cg_sitekey_state();
            $fails = (int) ($state['fails'] ?? 0);
            $state['fails'] = $fails + 1;
            cg_sitekey_save_state($state);
            cg_sitekey_set_gate(max(cg_sitekey_gate(), $now + max(cg_sitekey_backoff_seconds($fails), $retry_after)));
        }
    } finally {
        cg_sitekey_release_lock($token);
    }

    if ($result === 'claimed' && function_exists('cg_sync_with_hq')) {
        // The first successful call with the new key commits it
        // (cg_sitekey_on_response sees the 2xx).
        cg_sync_with_hq();
        $state = cg_sitekey_state();
        if (empty($state['pending_until']) && cg_sitekey_is_site_key(cg_sitekey_current_key())) {
            $result = 'committed';
        }
    }
    return $result;
}

function cg_sitekey_request_event()
{
    cg_sitekey_request('deadline');
}
add_action('cg_sitekey_request_event', 'cg_sitekey_request_event');
add_action('cg_sitekey_claim_event', 'cg_sitekey_claim');

/**
 * Six-hourly sync: drop an unconfirmed key after its hour and, while the site
 * is still on the shared key, ask for its own one (within the gate/budget).
 */
function cg_sitekey_maintenance()
{
    cg_sitekey_expire_pending();
    if (apply_filters('cg_sitekey_proactive', true) && cg_sitekey_needed()) {
        cg_sitekey_request('scheduled');
    }
}
add_action('cg_daily_sync_hook', 'cg_sitekey_maintenance', 30);

/**
 * Admin notice while HQ refuses the shared key and no site key works yet.
 * Shown to admins on the dashboard and CheckoutGuard pages. No key or nonce.
 */
function cg_render_sitekey_notice()
{
    if (!current_user_can('manage_options')) {
        return;
    }
    $state = cg_sitekey_state();
    if (empty($state['legacy_blocked_at']) || (cg_sitekey_is_site_key(cg_sitekey_current_key()) && empty($state['pending_until']))) {
        return;
    }
    $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    $on_dashboard = $screen && $screen->id === 'dashboard';
    if (!$on_dashboard && strpos($page, 'cg-') !== 0) {
        return;
    }
    $gate = cg_sitekey_gate();
    $url = wp_nonce_url(admin_url('admin-post.php?action=cg_sitekey_reconnect'), 'cg_sitekey_reconnect');
    echo '<div class="notice notice-error"><p><strong>' . esc_html__('CheckoutGuard is not protecting checkouts right now.', 'checkoutguard') . '</strong> ';
    echo esc_html__('Headquarters needs this site to use its own key. Orders still go through, but without the fraud check.', 'checkoutguard') . ' ';
    if ($gate > cg_sitekey_now()) {
        echo esc_html(sprintf(
            /* translators: %s: time of the next automatic attempt */
            __('Next automatic attempt: %s.', 'checkoutguard'),
            wp_date(get_option('date_format') . ' ' . get_option('time_format'), $gate)
        ));
    } else {
        echo '<a class="button button-secondary" href="' . esc_url($url) . '">' . esc_html__('Reconnect now', 'checkoutguard') . '</a>';
    }
    echo '</p></div>';
}
add_action('admin_notices', 'cg_render_sitekey_notice');

function cg_handle_sitekey_reconnect()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Permission denied.', 'checkoutguard'), 403);
    }
    check_admin_referer('cg_sitekey_reconnect');
    cg_sitekey_request('manual');
    wp_safe_redirect(wp_get_referer() ?: admin_url());
    exit;
}
add_action('admin_post_cg_sitekey_reconnect', 'cg_handle_sitekey_reconnect');
