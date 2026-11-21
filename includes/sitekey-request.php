<?php
/**
 * SiteKey: calling POST /sites/request-site-key without ever looping (1.4.3).
 *
 * HQ limits request-site-key per client IP and answers 429 with a
 * Retry-After header when the limit is hit. The plugin then:
 *  - honours Retry-After (delta-seconds only, capped at 24 h);
 *  - otherwise waits a backoff with jitter: at least 15 min, at most 1 h;
 *  - retries at most once per request (one scheduled single event); anything
 *    further waits for the next scheduled sync;
 *  - shows no admin notice for a 429.
 *
 * Every trigger (first request, the one retry, cron, admin visit) goes
 * through cg_sitekey_request_site_key(), which respects one gate
 * (option cg_sitekey_next_allowed_at, UTC timestamp) and a budget of at most
 * CG_SITEKEY_MAX_CALLS_PER_HOUR calls in any sliding 60-minute window. The
 * decision to call is taken under an atomic lock (add_option on a unique
 * option_name), so two PHP processes never call together.
 *
 * All times are UTC Unix timestamps (time()), never current_time().
 *
 * @package CheckoutGuard
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CG_SITEKEY_GATE_OPTION', 'cg_sitekey_next_allowed_at');
define('CG_SITEKEY_STEP_OPTION', 'cg_sitekey_backoff_step');
define('CG_SITEKEY_CALLS_OPTION', 'cg_sitekey_recent_calls');
define('CG_SITEKEY_LOCK_OPTION', 'cg_sitekey_retry_lock');
define('CG_SITEKEY_RETRY_HOOK', 'cg_sitekey_retry_event');
define('CG_SITEKEY_MAX_CALLS_PER_HOUR', 2);
define('CG_SITEKEY_BACKOFF_MIN', 15 * MINUTE_IN_SECONDS);
define('CG_SITEKEY_BACKOFF_MAX', HOUR_IN_SECONDS);
define('CG_SITEKEY_RETRY_AFTER_CAP', DAY_IN_SECONDS);
define('CG_SITEKEY_LOCK_STALE_AFTER', 15 * MINUTE_IN_SECONDS);
// After a call that was not a 429 (success, 5xx, timeout), the next call
// waits at least this long, so admin visits stay at most one per hour.
define('CG_SITEKEY_MIN_SPACING', HOUR_IN_SECONDS);

/**
 * Current UTC time. Tests replace it through the cg_sitekey_now filter.
 */
function cg_sitekey_now()
{
    return (int) apply_filters('cg_sitekey_now', time());
}

/**
 * Reads an option straight from the database, bypassing the object cache,
 * so a value written by another PHP process is seen. Returns null when the
 * option does not exist.
 */
function cg_sitekey_read_option_raw($name)
{
    global $wpdb;
    $value = $wpdb->get_var($wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
        $name
    ));
    wp_cache_delete($name, 'options');
    return $value === null ? null : maybe_unserialize($value);
}

/**
 * Writes a non-autoloaded option.
 */
function cg_sitekey_write_option($name, $value)
{
    if (!add_option($name, $value, '', 'no')) {
        update_option($name, $value, false);
    }
}

/**
 * Parses a Retry-After header. Only delta-seconds are accepted; an HTTP
 * date, a negative or non-numeric value, or an empty header gives null (the
 * caller then uses its backoff). Values above 24 h are capped at 24 h.
 *
 * @param mixed $header Raw header value (string, or array if repeated).
 * @return int|null Seconds, or null when unusable.
 */
function cg_sitekey_parse_retry_after($header)
{
    if (is_array($header)) {
        $header = reset($header);
    }
    if (!is_string($header) && !is_int($header)) {
        return null;
    }
    $header = trim((string) $header);
    if ($header === '' || !ctype_digit($header)) {
        return null;
    }
    // Long digit strings: avoid int overflow, they are over the cap anyway.
    if (strlen($header) > 9) {
        return CG_SITEKEY_RETRY_AFTER_CAP;
    }
    return min((int) $header, CG_SITEKEY_RETRY_AFTER_CAP);
}

/**
 * Backoff for the given step (0 = first 429): 15 min, 30 min, then 1 h,
 * plus up to 25% jitter, always inside [15 min, 1 h].
 */
function cg_sitekey_backoff_seconds($step)
{
    $step = max(0, (int) $step);
    $base = CG_SITEKEY_BACKOFF_MIN * (2 ** min($step, 4));
    $jitter = (int) floor($base * (wp_rand(0, 250) / 1000));
    $wait = $base + $jitter;
    return (int) max(CG_SITEKEY_BACKOFF_MIN, min(CG_SITEKEY_BACKOFF_MAX, $wait));
}

/**
 * Takes the lock. The option value is the time the lock was taken. A lock
 * older than CG_SITEKEY_LOCK_STALE_AFTER (the holder died) is deleted, but
 * only if it still holds the same value, and add_option is tried again, so
 * two processes that both saw the stale lock cannot both win.
 *
 * @return string|false The lock value (to release it), or false if busy.
 */
function cg_sitekey_acquire_lock($now)
{
    global $wpdb;
    // "<time taken>-<random>": the time decides staleness, the random part
    // makes the guarded release/delete match only this holder's row.
    $token = (int) $now . '-' . wp_rand(100000, 999999);
    if (add_option(CG_SITEKEY_LOCK_OPTION, $token, '', 'no')) {
        return $token;
    }
    $held = cg_sitekey_read_option_raw(CG_SITEKEY_LOCK_OPTION);
    if ($held === null) {
        // Released between our add_option and read; try once more.
        return add_option(CG_SITEKEY_LOCK_OPTION, $token, '', 'no') ? $token : false;
    }
    if ((int) $held > $now - CG_SITEKEY_LOCK_STALE_AFTER) {
        return false;
    }
    $deleted = $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
        CG_SITEKEY_LOCK_OPTION,
        (string) $held
    ));
    wp_cache_delete(CG_SITEKEY_LOCK_OPTION, 'options');
    wp_cache_delete('notoptions', 'options');
    if (!$deleted) {
        return false;
    }
    return add_option(CG_SITEKEY_LOCK_OPTION, $token, '', 'no') ? $token : false;
}

/**
 * Releases the lock if it is still ours.
 */
function cg_sitekey_release_lock($token)
{
    global $wpdb;
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
        CG_SITEKEY_LOCK_OPTION,
        (string) $token
    ));
    wp_cache_delete(CG_SITEKEY_LOCK_OPTION, 'options');
    wp_cache_delete('notoptions', 'options');
}

/**
 * Calls made in the last hour, oldest first.
 */
function cg_sitekey_recent_calls($now)
{
    $calls = cg_sitekey_read_option_raw(CG_SITEKEY_CALLS_OPTION);
    $calls = is_array($calls) ? array_map('intval', $calls) : [];
    $calls = array_values(array_filter($calls, function ($t) use ($now) {
        return $t > $now - HOUR_IN_SECONDS && $t <= $now;
    }));
    sort($calls);
    return $calls;
}

/**
 * Moves the gate forward (never backward).
 */
function cg_sitekey_push_gate($until)
{
    $current = (int) cg_sitekey_read_option_raw(CG_SITEKEY_GATE_OPTION);
    if ((int) $until > $current) {
        cg_sitekey_write_option(CG_SITEKEY_GATE_OPTION, (int) $until);
    }
}

/**
 * Asks HQ to send a claim nonce to this site (request-site-key), within the
 * T1 limits. Safe to call from any trigger: it decides itself whether a call
 * is allowed now.
 *
 * @param string $trigger 'first', 'retry', 'cron' or 'admin'.
 * @return string One of: 'ok', 'rate_limited', 'error', 'gated', 'budget',
 *                'locked'.
 */
function cg_sitekey_request_site_key($trigger = 'cron')
{
    $now = cg_sitekey_now();

    // Cheap check before the lock.
    if ($now < (int) cg_sitekey_read_option_raw(CG_SITEKEY_GATE_OPTION)) {
        return 'gated';
    }

    $lock = cg_sitekey_acquire_lock($now);
    if ($lock === false) {
        return 'locked';
    }

    try {
        // Re-check under the lock: another process may have just called.
        if ($now < (int) cg_sitekey_read_option_raw(CG_SITEKEY_GATE_OPTION)) {
            return 'gated';
        }
        $calls = cg_sitekey_recent_calls($now);
        if (count($calls) >= CG_SITEKEY_MAX_CALLS_PER_HOUR) {
            cg_sitekey_push_gate($calls[0] + HOUR_IN_SECONDS);
            return 'budget';
        }

        // Record the call before making it, so a crash mid-call still counts.
        $calls[] = $now;
        cg_sitekey_write_option(CG_SITEKEY_CALLS_OPTION, $calls);
        // N2 gate: the plugin only accepts a nonce it asked for.
        set_transient('cg_key_requested', $now, 15 * MINUTE_IN_SECONDS);

        $result = cg_call_hq_api('/sites/request-site-key', [], 'POST', false);

        if (!is_wp_error($result)) {
            delete_option(CG_SITEKEY_STEP_OPTION);
            wp_clear_scheduled_hook(CG_SITEKEY_RETRY_HOOK);
            cg_sitekey_write_option(CG_SITEKEY_GATE_OPTION, $now + CG_SITEKEY_MIN_SPACING);
            return 'ok';
        }

        $data = $result->get_error_data();
        $status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 0;

        if ($status === 429) {
            $step = (int) cg_sitekey_read_option_raw(CG_SITEKEY_STEP_OPTION);
            $wait = cg_sitekey_backoff_seconds($step);
            $retry_after = cg_sitekey_parse_retry_after(is_array($data) ? ($data['retry_after'] ?? null) : null);
            if ($retry_after !== null) {
                $wait = max($wait, $retry_after);
            }
            cg_sitekey_write_option(CG_SITEKEY_STEP_OPTION, $step + 1);
            cg_sitekey_write_option(CG_SITEKEY_GATE_OPTION, $now + $wait);

            // At most one retry per request: only a non-retry call schedules
            // one, and only if none is pending. No admin notice for a 429.
            if ($trigger !== 'retry' && !wp_next_scheduled(CG_SITEKEY_RETRY_HOOK)) {
                wp_schedule_single_event($now + $wait, CG_SITEKEY_RETRY_HOOK);
            }
            return 'rate_limited';
        }

        // 5xx, timeout or another error: no retry, normal spacing.
        cg_sitekey_push_gate($now + CG_SITEKEY_MIN_SPACING);
        return 'error';
    } finally {
        cg_sitekey_release_lock($lock);
    }
}

/**
 * The one scheduled retry after a 429.
 */
function cg_sitekey_run_retry()
{
    cg_sitekey_request_site_key('retry');
}
add_action(CG_SITEKEY_RETRY_HOOK, 'cg_sitekey_run_retry');
