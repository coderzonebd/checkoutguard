<?php
// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Free plan: full contact details for the first N new incomplete checkouts
 * of each usage period (1.5.0).
 *
 * The decision is made per record when it is inserted and stored as
 * contact_revealed_until (UTC DATETIME) = the HQ usage_period_end of that
 * moment. A record shows its full email and phone only while
 *   - the plan's contact_reveals limit is unlimited (< 0), or
 *   - now < contact_revealed_until AND usage[contact_reveals] <= limit.
 * Everything else (no cache, no limit key, no period end, unparseable time,
 * failed increment) stays masked. contact_reveals is never decremented: HQ
 * answers 400 to that, and the quota must not come back when a record or an
 * order is deleted.
 * A record saved after the cached period ended, before this request has the
 * new period (sync held by another request, or failed), stays masked and is
 * not counted, so a burst at the period roll cannot use up the new quota.
 */

if (!defined('CG_PERIOD_SYNC_TIMEOUT')) {
    define('CG_PERIOD_SYNC_TIMEOUT', 2);
}
define('CG_REVEAL_ACTION', 'contact_reveals');
define('CG_PERIOD_SYNC_LOCK_OPTION', 'cg_period_sync_lock');
define('CG_PERIOD_SYNCED_FOR_OPTION', 'cg_period_synced_for');
define('CG_PERIOD_SYNC_BACKOFF_TRANSIENT', 'cg_period_sync_backoff');
// The reveal increment runs while a guest's checkout data is being saved,
// so it must not hold that request for the default 15 seconds.
define('CG_REVEAL_INCREMENT_TIMEOUT', 5);
define('CG_PERIOD_SYNC_LOCK_STALE_AFTER', 60);

/**
 * Current UTC unix time. Filterable so tests can move the clock.
 */
function cg_reveal_now()
{
    return (int) apply_filters('cg_reveal_now', time());
}

/**
 * Parses an RFC 3339 date-time that carries an offset ("Z" or +hh:mm/-hh:mm).
 * A value without an offset, an empty value or null is invalid.
 *
 * @return int|null Unix timestamp (UTC), or null when invalid.
 */
function cg_parse_rfc3339_utc($value)
{
    if (!is_string($value) || $value === '') {
        return null;
    }
    $re = '/^(\d{4})-(\d{2})-(\d{2})[Tt](\d{2}):(\d{2}):(\d{2})(?:\.\d{1,9})?(?:([Zz])|([+-])(\d{2}):(\d{2}))$/';
    if (!preg_match($re, $value, $m)) {
        return null;
    }
    $year = (int) $m[1];
    $month = (int) $m[2];
    $day = (int) $m[3];
    $hour = (int) $m[4];
    $minute = (int) $m[5];
    $second = (int) $m[6];
    if (!checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59) {
        return null;
    }
    $offset = 0;
    if (empty($m[7])) {
        $off_h = (int) $m[9];
        $off_m = (int) $m[10];
        if ($off_h > 23 || $off_m > 59) {
            return null;
        }
        $offset = ($off_h * 3600 + $off_m * 60) * ($m[8] === '-' ? -1 : 1);
    }
    return gmmktime($hour, $minute, $second, $month, $day, $year) - $offset;
}

/**
 * The contact_reveals limit from the HQ cache, or null when unknown.
 */
function cg_contact_reveal_limit($cache)
{
    if (!is_array($cache) || !isset($cache['limits']) || !is_array($cache['limits'])
        || !array_key_exists(CG_REVEAL_ACTION, $cache['limits'])
        || !is_numeric($cache['limits'][CG_REVEAL_ACTION])) {
        return null;
    }
    return (int) $cache['limits'][CG_REVEAL_ACTION];
}

function cg_contact_reveal_usage($cache)
{
    $usage = (is_array($cache) && isset($cache['usage']) && is_array($cache['usage']))
        ? ($cache['usage'][CG_REVEAL_ACTION] ?? 0)
        : 0;
    return is_numeric($usage) ? (int) $usage : 0;
}

/**
 * Whether the incomplete checkouts table has contact_revealed_until.
 * Cached per request; pass $reset = true after a migration.
 */
function cg_checkouts_has_reveal_column($reset = false)
{
    static $has = null;
    if ($reset) {
        $has = null;
        return false;
    }
    if ($has === null) {
        global $wpdb;
        $table = $wpdb->prefix . 'cg_incomplete_checkouts';
        $has = !empty($wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'contact_revealed_until'"));
    }
    return $has;
}

/**
 * Should this incomplete checkout row show the full email and phone?
 * Fail-closed: anything unknown means no.
 *
 * @param object|array $row A row of cg_incomplete_checkouts.
 */
function cg_can_show_contact($row)
{
    $cache = get_transient('cg_api_cache');
    $limit = cg_contact_reveal_limit($cache);
    if ($limit === null) {
        return false;
    }
    if ($limit < 0) {
        return true;
    }
    $until = is_array($row) ? ($row['contact_revealed_until'] ?? null) : ($row->contact_revealed_until ?? null);
    if (empty($until) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $until)) {
        return false;
    }
    $until_ts = cg_parse_rfc3339_utc(str_replace(' ', 'T', $until) . 'Z');
    if ($until_ts === null) {
        return false;
    }
    return cg_reveal_now() < $until_ts && cg_contact_reveal_usage($cache) <= $limit;
}

/**
 * Reads an option straight from the database (no object cache), so a
 * value written by a concurrent request is seen.
 */
function cg_reveal_read_option_raw($name)
{
    global $wpdb;
    $value = $wpdb->get_var($wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
        $name
    ));
    wp_cache_delete($name, 'options');
    return $value;
}

/**
 * Atomic lock: INSERT IGNORE succeeds for exactly one request. add_option()
 * is not atomic (it checks in PHP, then upserts), so it is not used here.
 *
 * @return string|false The lock token, or false when another request holds it.
 */
function cg_period_sync_acquire_lock($now)
{
    global $wpdb;
    $token = $now . '-' . wp_rand(100000, 999999);
    $autoload = function_exists('wp_determine_option_autoload_value') ? 'off' : 'no';
    $insert = function () use ($wpdb, $token, $autoload) {
        $n = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
            CG_PERIOD_SYNC_LOCK_OPTION,
            $token,
            $autoload
        ));
        wp_cache_delete(CG_PERIOD_SYNC_LOCK_OPTION, 'options');
        wp_cache_delete('notoptions', 'options');
        return $n === 1;
    };
    if ($insert()) {
        return $token;
    }
    $held = cg_reveal_read_option_raw(CG_PERIOD_SYNC_LOCK_OPTION);
    if ($held === null) {
        return $insert() ? $token : false;
    }
    $held_at = (int) strtok((string) $held, '-');
    if ($held_at > 0 && ($now - $held_at) < CG_PERIOD_SYNC_LOCK_STALE_AFTER) {
        return false;
    }
    // Stale (a request died while syncing): remove exactly that value, then race again.
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
        CG_PERIOD_SYNC_LOCK_OPTION,
        (string) $held
    ));
    return $insert() ? $token : false;
}

function cg_period_sync_release_lock($token)
{
    global $wpdb;
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
        CG_PERIOD_SYNC_LOCK_OPTION,
        (string) $token
    ));
    wp_cache_delete(CG_PERIOD_SYNC_LOCK_OPTION, 'options');
}

/**
 * Wait after the n-th failed period sync (0-based): 60s, 2m, 5m, 10m, 15m.
 */
function cg_period_sync_backoff_seconds($step)
{
    $steps = [60, 120, 300, 600, 900];
    $step = max(0, min((int) $step, count($steps) - 1));
    return $steps[$step];
}

/**
 * One HQ sync per finished period, run only when the local cache says the
 * quota is used up and the period it belongs to has ended. Never waits for
 * another request's sync: without the lock it returns false at once and the
 * caller decides with the cache it has.
 *
 * @param string $period_end_raw usage_period_end exactly as cached.
 * @return bool True when this call synced successfully.
 */
function cg_contact_reveal_period_sync($period_end_raw, $now)
{
    if ((string) cg_reveal_read_option_raw(CG_PERIOD_SYNCED_FOR_OPTION) === (string) $period_end_raw) {
        return false;
    }
    $backoff = get_transient(CG_PERIOD_SYNC_BACKOFF_TRANSIENT);
    if (is_array($backoff) && isset($backoff['next_try_at']) && $now < (int) $backoff['next_try_at']) {
        return false;
    }
    $token = cg_period_sync_acquire_lock($now);
    if ($token === false) {
        return false;
    }
    $ok = false;
    try {
        // Another request may have finished the same sync while we raced.
        if ((string) cg_reveal_read_option_raw(CG_PERIOD_SYNCED_FOR_OPTION) === (string) $period_end_raw) {
            return false;
        }
        // Read the backoff again under the lock, so the next step is based on
        // the latest failure and a retry that is not due yet is skipped.
        $backoff = get_transient(CG_PERIOD_SYNC_BACKOFF_TRANSIENT);
        if (is_array($backoff) && isset($backoff['next_try_at']) && $now < (int) $backoff['next_try_at']) {
            return false;
        }
        $previous = $GLOBALS['cg_hq_timeout_override'] ?? null;
        $GLOBALS['cg_hq_timeout_override'] = max(1, (int) CG_PERIOD_SYNC_TIMEOUT);
        try {
            $ok = function_exists('cg_sync_with_hq') && cg_sync_with_hq() === true;
        } catch (\Throwable $e) {
            $ok = false;
        } finally {
            if ($previous === null) {
                unset($GLOBALS['cg_hq_timeout_override']);
            } else {
                $GLOBALS['cg_hq_timeout_override'] = $previous;
            }
        }
        if ($ok) {
            update_option(CG_PERIOD_SYNCED_FOR_OPTION, (string) $period_end_raw, false);
            delete_transient(CG_PERIOD_SYNC_BACKOFF_TRANSIENT);
        } else {
            $step = (is_array($backoff) && isset($backoff['step'])) ? (int) $backoff['step'] + 1 : 0;
            $step = min($step, 4);
            $wait = cg_period_sync_backoff_seconds($step);
            set_transient(CG_PERIOD_SYNC_BACKOFF_TRANSIENT, ['step' => $step, 'next_try_at' => $now + $wait], $wait + 60);
        }
    } finally {
        cg_period_sync_release_lock($token);
    }
    return $ok;
}

/**
 * Decides, before a new incomplete checkout is inserted, whether it gets
 * the full contact details and whether it counts toward contact_reveals.
 *
 * @return array{count: bool, until: string|null} until is a UTC
 *               'Y-m-d H:i:s' to store after a successful increment.
 */
function cg_contact_reveal_decide()
{
    $decision = ['count' => false, 'until' => null];
    try {
        $now = cg_reveal_now();
        $cache = get_transient('cg_api_cache');
        $limit = cg_contact_reveal_limit($cache);
        if ($limit === null || $limit < 0) {
            // Unknown: nothing to count against. Unlimited: shown anyway.
            return $decision;
        }
        $decision['count'] = true;

        $end_raw = $cache['usage_period_end'] ?? null;
        $end = cg_parse_rfc3339_utc($end_raw);
        if ($end === null) {
            return $decision;
        }
        if (cg_contact_reveal_usage($cache) >= $limit && $now >= $end) {
            if (cg_contact_reveal_period_sync($end_raw, $now)) {
                $cache = get_transient('cg_api_cache');
                $limit = cg_contact_reveal_limit($cache);
                if ($limit === null || $limit < 0) {
                    $decision['count'] = false;
                    return $decision;
                }
                $end = cg_parse_rfc3339_utc($cache['usage_period_end'] ?? null);
                if ($end === null) {
                    return $decision;
                }
            }
        }
        if ($now >= $end) {
            // The cached period is over and this request did not get the new
            // one (another request holds the sync, or it failed). The record
            // stays hidden, so it must not use up the next period's quota.
            $decision['count'] = false;
            return $decision;
        }
        if (cg_contact_reveal_usage($cache) < $limit) {
            $decision['until'] = gmdate('Y-m-d H:i:s', $end);
        }
    } catch (\Throwable $e) {
        $decision['until'] = null;
    }
    return $decision;
}

/**
 * Counts one new record toward contact_reveals (local cache first, then
 * HQ) and stores contact_revealed_until only when HQ confirmed the count.
 * Never throws and never blocks the checkout.
 *
 * @param int   $record_id Inserted row id.
 * @param array $decision  From cg_contact_reveal_decide().
 * @return bool True when the record was revealed.
 */
function cg_contact_reveal_commit($record_id, array $decision)
{
    if (empty($decision['count'])) {
        return false;
    }
    try {
        $config = get_transient('cg_api_cache');
        if (is_array($config) && isset($config['limits'][CG_REVEAL_ACTION])) {
            $config['usage'][CG_REVEAL_ACTION] = cg_contact_reveal_usage($config) + 1;
            set_transient('cg_api_cache', $config, DAY_IN_SECONDS);
        }
        $previous = $GLOBALS['cg_hq_timeout_override'] ?? null;
        $GLOBALS['cg_hq_timeout_override'] = max(1, (int) CG_REVEAL_INCREMENT_TIMEOUT);
        try {
            $response = cg_call_hq_api('/sites/increment-usage', ['action_type' => CG_REVEAL_ACTION]);
        } finally {
            if ($previous === null) {
                unset($GLOBALS['cg_hq_timeout_override']);
            } else {
                $GLOBALS['cg_hq_timeout_override'] = $previous;
            }
        }
        if (is_wp_error($response) || !is_array($response) || ($response['status'] ?? '') !== 'success') {
            return false;
        }
        if (isset($response['usage']) && is_numeric($response['usage'])) {
            $config = get_transient('cg_api_cache');
            if (is_array($config)) {
                $config['usage'][CG_REVEAL_ACTION] = (int) $response['usage'];
                set_transient('cg_api_cache', $config, DAY_IN_SECONDS);
            }
        }
        if (empty($decision['until']) || !$record_id || !cg_checkouts_has_reveal_column()) {
            return false;
        }
        global $wpdb;
        $updated = $wpdb->update(
            $wpdb->prefix . 'cg_incomplete_checkouts',
            ['contact_revealed_until' => $decision['until']],
            ['id' => (int) $record_id],
            ['%s'],
            ['%d']
        );
        return $updated === 1;
    } catch (\Throwable $e) {
        return false;
    }
}
