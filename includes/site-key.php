<?php

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Site key (SiteKey contract): each site trades the shared product key for its own
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
// At most this many claim-key calls per round, newest nonce first, so a
// shared IP does not run into the claim-key 429 limit (contract §1b).
define('CG_SITEKEY_MAX_CLAIM_TRIES', 3);
define('CG_SITEKEY_PENDING_TTL', HOUR_IN_SECONDS);
define('CG_SITEKEY_DROP_OPTION', 'cg_sitekey_last_drop');
define('CG_SITEKEY_NONCE_PURPOSE', 'sitekey-nonce');
// Signed delivery limits (contract §1c): lifetime, and how far the site
// clock may be behind (iat in the future) or ahead (after exp).
define('CG_SITEKEY_DELIVERY_MAX_LIFETIME', 300);
define('CG_SITEKEY_DELIVERY_SKEW_BEHIND', 300);
define('CG_SITEKEY_DELIVERY_SKEW_AHEAD', 60);

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
 * True after HQ answered 403 site_key_mismatch: the stored own key belongs to
 * another domain. Never claimed again on its own (no loop, contract §5); only
 * the admin's Reconnect starts a new round.
 */
function cg_sitekey_mismatch()
{
    $state = cg_sitekey_state();
    return !empty($state['mismatch_at']);
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
    // SiteKey contract §5: act on the code, never on the status alone or the text.
    $code = (string) $code;
    $legacy_code = ($http_code === 409 && $code === 'claim_required')
        || ($http_code === 403 && in_array($code, ['legacy_expired', 'site_key_required'], true));
    $mismatch = $http_code === 403 && $code === 'site_key_mismatch';
    // Hot path (every HQ answer, e.g. each checkout's courier check): with the
    // shared key and no deadline code there is nothing to do, so no database
    // read. A pending key always means the request carried a czsite_ key.
    if (!$legacy_code && !$mismatch && !cg_sitekey_is_site_key($sent_key)) {
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
        if ($http_code === 401 || $mismatch) {
            cg_sitekey_rollback('rejected');
            return;
        }
    }

    if ($mismatch) {
        // Only an answer about the key we use now may raise the notice: a late
        // answer for an older own key or for the shared key says nothing about
        // the current key. Show the reconnect notice; do not
        // schedule a claim round.
        if (!cg_sitekey_is_site_key($sent_key)
            || !hash_equals(cg_sitekey_current_key(), (string) $sent_key)) {
            return;
        }
        $state = cg_sitekey_state();
        if (empty($state['mismatch_at'])) {
            $state['mismatch_at'] = cg_sitekey_now();
            $state['legacy_blocked_at'] = $state['mismatch_at'];
            $state['legacy_code'] = $code;
            cg_sitekey_save_state($state);
        }
        return;
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
    unset($state['pending_until'], $state['legacy_key'], $state['legacy_blocked_at'], $state['legacy_code'], $state['mismatch_at']);
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
    $wanted = cg_sitekey_needed() || ($reason === 'manual' && !empty($state['mismatch_at']));
    if (!$wanted || !empty($state['pending_until'])) {
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
        // A fresh id per round; HQ echoes it inside the signed delivery.
        $state['request_id'] = cg_sitekey_new_request_id();
        $state['last_reason'] = sanitize_key($reason);
        cg_sitekey_save_state($state);

        $GLOBALS['cg_sitekey_in_flow'] = true;
        try {
            $response = cg_call_hq_api('/sites/request-site-key', ['request_id' => $state['request_id']], 'POST');
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
        $state['request_id'] = '';
        $state['fails'] = $fails + 1;
        cg_sitekey_save_state($state);
        cg_sitekey_set_gate($now + max(cg_sitekey_backoff_seconds($fails), $retry_after));
        return 'failed';
    } finally {
        cg_sitekey_release_lock($token);
    }
}

/**
 * A random round id: 32 base64url characters, 192 bits.
 */
function cg_sitekey_new_request_id()
{
    return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
}

/**
 * The host this site sends as X-Site-Domain, normalized the way HQ
 * normalizes stored domains: lowercase, no trailing dot, IDN as punycode.
 */
function cg_sitekey_site_host()
{
    $host = (string) cg_get_root_domain(get_site_url());
    return cg_sitekey_normalize_host($host);
}

function cg_sitekey_normalize_host($host)
{
    $host = rtrim(strtolower(trim((string) $host)), '.');
    if ($host !== '' && preg_match('/[^\x20-\x7e]/', $host) && function_exists('idn_to_ascii')) {
        $ascii = defined('INTL_IDNA_VARIANT_UTS46')
            ? idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46)
            : idn_to_ascii($host);
        $host = is_string($ascii) ? strtolower($ascii) : '';
    }
    return $host;
}

/**
 * Checks a signed nonce delivery (contract §1c), in the contract's order:
 * signature over the exact payload string, purpose, site, request_id,
 * lifetime and clock skew, nonce format. Pure: no state, no I/O.
 *
 * @param mixed  $payload    The payload JSON string as posted.
 * @param mixed  $signature  Base64 Ed25519 signature as posted.
 * @param string $site_host  Normalized host of this site.
 * @param string $request_id The current round's request_id ('' when none).
 * @param int    $now        Current time.
 * @param string $public_key Base64 Ed25519 public key.
 * @return array ['ok' => true, 'nonce' => string, 'exp' => int] or ['ok' => false, 'reason' => string].
 */
function cg_sitekey_verify_delivery($payload, $signature, $site_host, $request_id, $now, $public_key = CG_HQ_PUBLIC_KEY)
{
    $drop = function ($reason) {
        return ['ok' => false, 'reason' => $reason];
    };
    if (!is_string($payload) || !is_string($signature) || $payload === '' || strlen($payload) > 2048) {
        return $drop('malformed');
    }
    if (!function_exists('sodium_crypto_sign_verify_detached')) {
        return $drop('no_sodium');
    }
    $sig = base64_decode($signature, true);
    $key = base64_decode((string) $public_key, true);
    if (!is_string($sig) || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
        return $drop('malformed');
    }
    if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
        return $drop('bad_signature');
    }
    try {
        $valid = sodium_crypto_sign_verify_detached($sig, $payload, $key);
    } catch (Throwable $e) {
        $valid = false;
    }
    if (!$valid) {
        return $drop('bad_signature');
    }
    $data = json_decode($payload, true);
    if (!is_array($data)) {
        return $drop('malformed');
    }
    if (($data['purpose'] ?? null) !== CG_SITEKEY_NONCE_PURPOSE) {
        return $drop('wrong_purpose');
    }
    $site_url = isset($data['site_url']) && is_string($data['site_url']) ? cg_sitekey_normalize_host($data['site_url']) : '';
    if ($site_url === '' || $site_host === '' || !hash_equals((string) $site_host, $site_url)) {
        return $drop('wrong_site');
    }
    $echo = isset($data['request_id']) && is_string($data['request_id']) ? $data['request_id'] : '';
    if ((string) $request_id === '' || $echo === '' || !hash_equals((string) $request_id, $echo)) {
        return $drop('wrong_request_id');
    }
    if (!isset($data['iat'], $data['exp']) || !is_int($data['iat']) || !is_int($data['exp'])) {
        return $drop('malformed');
    }
    $iat = $data['iat'];
    $exp = $data['exp'];
    $life = $exp - $iat;
    if ($life <= 0 || $life > CG_SITEKEY_DELIVERY_MAX_LIFETIME) {
        return $drop('bad_lifetime');
    }
    if ($iat > $now + CG_SITEKEY_DELIVERY_SKEW_BEHIND) {
        return $drop('not_yet_valid');
    }
    if ($now > $exp + CG_SITEKEY_DELIVERY_SKEW_AHEAD) {
        return $drop('expired');
    }
    $nonce = $data['nonce'] ?? null;
    if (!is_string($nonce) || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $nonce)) {
        return $drop('malformed');
    }
    return ['ok' => true, 'nonce' => $nonce, 'exp' => $exp];
}

/**
 * Keeps the last drop reason for the admin diagnostics. Holds no nonce, key
 * or payload and is never sent anywhere. At most one write a minute, whatever
 * the reason, so junk posts (even with changing reasons) cannot turn into a
 * stream of option writes. Within that minute the first reason is kept.
 */
function cg_sitekey_note_drop($reason, $now)
{
    $raw = cg_sitekey_read_raw(CG_SITEKEY_DROP_OPTION);
    $last = $raw === null ? null : maybe_unserialize($raw);
    $at = is_array($last) ? (int) ($last['at'] ?? 0) : 0;
    if ($at > 0 && $now >= $at && $now - $at < MINUTE_IN_SECONDS) {
        return;
    }
    update_option(CG_SITEKEY_DROP_OPTION, ['reason' => sanitize_key($reason), 'at' => (int) $now], false);
}

/**
 * REST: HQ posts the signed nonce here. Accepted only while a request of
 * ours is open and only when every check of cg_sitekey_verify_delivery()
 * passes. The last 3 are kept and the claim tries the newest first. The
 * answer is the same 200 in every case, so the route tells an outsider
 * nothing.
 */
function cg_sitekey_receive_nonce(WP_REST_Request $request)
{
    $now = cg_sitekey_now();
    $state = cg_sitekey_state();
    $open = !empty($state['window_until']) && $now <= (int) $state['window_until'];
    if (!$open) {
        cg_sitekey_note_drop('no_open_request', $now);
        return new WP_REST_Response(['ok' => true], 200);
    }
    $check = cg_sitekey_verify_delivery(
        $request->get_param('payload'),
        $request->get_param('signature'),
        cg_sitekey_site_host(),
        (string) ($state['request_id'] ?? ''),
        $now
    );
    if (empty($check['ok'])) {
        cg_sitekey_note_drop($check['reason'], $now);
        return new WP_REST_Response(['ok' => true], 200);
    }
    $nonces = isset($state['nonces']) && is_array($state['nonces']) ? $state['nonces'] : [];
    if (!in_array($check['nonce'], array_column($nonces, 'n'), true)) {
        $nonces[] = ['n' => $check['nonce'], 'at' => $now, 'exp' => (int) $check['exp']];
        $state['nonces'] = array_slice($nonces, -CG_SITEKEY_MAX_NONCES);
        cg_sitekey_save_state($state);
        cg_sitekey_schedule('cg_sitekey_claim_event');
        if (function_exists('spawn_cron')) {
            spawn_cron(); // Claim within the nonce's 5 minutes, even on a quiet site.
        }
    }
    return new WP_REST_Response(['ok' => true], 200);
}

function cg_sitekey_register_routes()
{
    register_rest_route('checkoutguard/v1', '/key-nonce', [
        'methods' => 'POST',
        'callback' => 'cg_sitekey_receive_nonce',
        // Called by HQ, server to server; protected by the open-window check,
        // the Ed25519 signature and request_id echo, and HQ checking the
        // nonce on claim.
        'permission_callback' => '__return_true',
    ]);
}
add_action('rest_api_init', 'cg_sitekey_register_routes');

/**
 * Step 3: trade a received nonce for a pending site key, newest nonce first,
 * then confirm it at once with a signed status sync.
 * At most CG_SITEKEY_MAX_CLAIM_TRIES calls per round. A round where every
 * try gets 401 does not grow the backoff.
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
        $state['request_id'] = ''; // The round ends here; a new one uses a new id.
        cg_sitekey_save_state($state);
        if (!empty($state['pending_until']) || (!cg_sitekey_needed() && empty($state['mismatch_at']))) {
            return 'none';
        }

        $retry_after = 0;
        $tries = 0;
        foreach (array_reverse($nonces) as $entry) {
            if (!is_array($entry) || empty($entry['n']) || ($now - (int) ($entry['at'] ?? 0)) >= CG_SITEKEY_NONCE_TTL
                || (isset($entry['exp']) && $now > (int) $entry['exp'] + CG_SITEKEY_DELIVERY_SKEW_AHEAD)) {
                continue;
            }
            if ($tries >= CG_SITEKEY_MAX_CLAIM_TRIES) {
                break; // The older nonces are dropped with the rest of the round.
            }
            $tries++;
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
    $mismatch = cg_sitekey_mismatch();
    if (empty($state['legacy_blocked_at']) || (!$mismatch && cg_sitekey_is_site_key(cg_sitekey_current_key()) && empty($state['pending_until']))) {
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
    echo ($mismatch
        ? esc_html__('The key stored here belongs to another domain (the site address changed or the key was copied). Orders still go through, but without the fraud check. Reconnect to get a key for this domain.', 'checkoutguard')
        : esc_html__('Headquarters needs this site to use its own key. Orders still go through, but without the fraud check.', 'checkoutguard')) . ' ';
    $plan_note = cg_sitekey_plan_note();
    if ($plan_note !== '') {
        echo esc_html($plan_note) . ' ';
    }
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

/**
 * One sentence for the SiteKey notice on how long the paid plan is kept while
 * HQ refuses the key (SiteKey contract §7). Empty when the plan rule does not
 * depend on the refusal.
 *
 * @return string
 */
function cg_sitekey_plan_note()
{
    if (!function_exists('cg_plan_key_grace_until')) {
        return '';
    }
    $last = get_option(CG_LAST_KNOWN_PLAN_OPTION, null);
    if (!is_array($last) || !isset($last['synced_at']) || !is_int($last['synced_at'])) {
        return '';
    }
    $failure = cg_plan_key_failure($last['synced_at']);
    if ($failure === 'expired') {
        return __('Paid features are off until this site is reconnected.', 'checkoutguard');
    }
    if ($failure !== 'key') {
        return '';
    }
    $grace = cg_plan_key_grace_until();
    if ($grace === null) {
        return __('Paid features stay on for up to 72 hours after the last successful sync.', 'checkoutguard');
    }
    if ($grace <= cg_sitekey_now()) {
        return __('Paid features are off until this site is reconnected.', 'checkoutguard');
    }
    return sprintf(
        /* translators: %s: date until which paid features stay on */
        __('Paid features stay on until %s.', 'checkoutguard'),
        wp_date(get_option('date_format'), $grace)
    );
}

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
