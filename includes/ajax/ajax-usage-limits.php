<?php
// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}


// ====================================================================
// NEW HELPER FUNCTIONS FOR USAGE & LIMITS
// ====================================================================

/**
 * Checks if a user can perform a limited action based on their plan.
 * Fail-closed: blocks action when cache is missing and sync fails.
 *
 * The backend's usage map is sparse — a feature nobody has used yet is
 * simply absent from it (payload has "usage": {}), not present-with-zero.
 * Only `limits[$action_type]` is checked for presence; usage defaults to 0
 * when absent, same convention every other reader of this cache already
 * uses (see cg_render_usage_row()'s `$cache['usage'][$key] ?? 0`). Requiring
 * BOTH to be isset() — as this used to — meant a site that had genuinely
 * synced but simply never used a feature yet got blocked exactly like a
 * site that had never synced at all, with the same misleading message.
 *
 * @param string $action_type 'checkouts', 'fraud_ips', 'courier_checks', etc.
 * @return bool True if the user is within their limits, false otherwise.
 */
function cg_can_perform_action($action_type)
{
    $config = get_transient('cg_api_cache');

    if (!$config || !is_array($config) || !isset($config['limits'][$action_type])) {
        // Cache miss, or this plan has no known limit for the action type —
        // attempt a real-time sync before giving up.
        if (function_exists('cg_sync_with_hq')) {
            cg_sync_with_hq();
        }
        $config = get_transient('cg_api_cache');

        // Still no data → block action (fail-closed)
        if (!$config || !is_array($config) || !isset($config['limits'][$action_type])) {
            return false;
        }
    }

    $limit = (int) $config['limits'][$action_type];
    $usage = (int) ($config['usage'][$action_type] ?? 0);
    return cg_is_unlimited_limit($limit) || $usage < $limit;
}


function cg_increment_usage_count($action_type)
{
    // This function remains for other actions like checkout tracking. Same
    // sparse-usage-map handling as cg_can_perform_action() above — a
    // feature's first-ever use has no existing key to increment.
    $config = get_transient('cg_api_cache');
    if ($config && isset($config['limits'][$action_type])) {
        $config['usage'][$action_type] = ($config['usage'][$action_type] ?? 0) + 1;
        set_transient('cg_api_cache', $config, DAY_IN_SECONDS);
    }
    cg_call_hq_api('/sites/increment-usage', ['action_type' => $action_type]);
}


/**
 * Lowers the local usage copy (cg_api_cache) by one, for a faster UI
 * response. Does not call HQ.
 *
 * @param string $action_type 'checkouts', 'fraud_blocks', etc.
 * @return bool False for contact_reveals, which is never decremented.
 */
function cg_decrement_usage_local($action_type)
{
    // contact_reveals only ever goes up (HQ answers 400 to a decrement):
    // deleting a record or an order must not give a reveal back.
    if ($action_type === 'contact_reveals') {
        return false;
    }
    $config = get_transient('cg_api_cache');
    if ($config && isset($config['usage'][$action_type]) && $config['usage'][$action_type] > 0) {
        $config['usage'][$action_type]--;
        set_transient('cg_api_cache', $config, DAY_IN_SECONDS);
    }
    return true;
}

/**
 * Decrements the usage count for a specific action via API call to HQ.
 * Waits for HQ (up to the 15 s API timeout): use it only on admin paths.
 * Customer-facing code uses cg_queue_usage_decrement().
 *
 * @param string $action_type 'checkouts', 'fraud_blocks', etc.
 */
function cg_decrement_usage_count($action_type)
{
    if (!cg_decrement_usage_local($action_type)) {
        return;
    }
    cg_call_hq_api('/sites/decrement-usage', ['action_type' => $action_type]);
}

/**
 * Lowers the local usage copy now and sends the HQ decrements later, from
 * WP-Cron, so a customer request (order placed, thank-you page) never waits
 * for HQ. One single event per call; $ref (e.g. the order id) keeps two
 * events with the same count apart, since WP drops a duplicate single event
 * with identical args within 10 minutes.
 *
 * @param string     $action_type 'checkouts', 'fraud_blocks', etc.
 * @param int        $count       Number of decrements.
 * @param string|int $ref         Caller reference, part of the event args.
 * @return bool True when an event was scheduled.
 */
function cg_queue_usage_decrement($action_type, $count, $ref = '')
{
    $count = (int) $count;
    if ($count < 1 || $action_type === 'contact_reveals') {
        return false;
    }
    for ($i = 0; $i < $count; $i++) {
        cg_decrement_usage_local($action_type);
    }
    return wp_schedule_single_event(time(), CG_USAGE_DECREMENT_HOOK, [(string) $action_type, $count, (string) $ref]) === true;
}

/**
 * WP-Cron handler for cg_queue_usage_decrement(): sends $count decrements to
 * HQ, one request each (HQ lowers by one per call), at most
 * CG_USAGE_DECREMENT_MAX per run. The local copy was
 * already lowered when the event was queued. Like the old direct call, a
 * failed request is not retried: a timeout may still have reached HQ, and a
 * second decrement would give usage back twice.
 *
 * @param string     $action_type
 * @param int        $count
 * @param string|int $ref Keeps events apart; passed on to the rest event.
 */
function cg_send_queued_usage_decrements($action_type, $count, $ref = '')
{
    $count = (int) $count;
    if ($count < 1 || $action_type === 'contact_reveals') {
        return;
    }
    $now = min($count, CG_USAGE_DECREMENT_MAX);
    for ($i = 0; $i < $now; $i++) {
        cg_call_hq_api('/sites/decrement-usage', ['action_type' => (string) $action_type]);
    }
    // More than one run's share: the rest goes in a new event a minute
    // later, so one cron request stays short.
    if ($count > $now) {
        wp_schedule_single_event(time() + MINUTE_IN_SECONDS, CG_USAGE_DECREMENT_HOOK, [(string) $action_type, $count - $now, (string) $ref]);
    }
}


/**
 * Returns an array of action types at >=80% usage for warning display.
 *
 * @return array
 */
function cg_get_usage_warnings()
{
    $config = get_transient('cg_api_cache');
    if (!$config || !is_array($config) || !isset($config['limits'], $config['usage'])) {
        return [];
    }
    $labels = [
        'checkouts'       => __('Incomplete Checkouts', 'checkoutguard'),
        'fraud_ips'       => __('Blocked IPs', 'checkoutguard'),
        'fraud_emails'    => __('Blocked Emails', 'checkoutguard'),
        'fraud_phones'    => __('Blocked Phones', 'checkoutguard'),
        'fraud_domains'   => __('Blocked Domains', 'checkoutguard'),
        'fraud_addresses' => __('Blocked Addresses', 'checkoutguard'),
        'fraud_devices'   => __('Blocked Devices', 'checkoutguard'),
        'courier_checks'  => __('Courier API Checks', 'checkoutguard'),
        'contact_reveals' => __('Full Contact Details', 'checkoutguard'),
    ];
    $warnings = [];
    foreach ($config['limits'] as $type => $limit) {
        $limit = (int) $limit;
        if (cg_is_unlimited_limit($limit)) {
            continue; // unlimited
        }
        $usage = (int) ($config['usage'][$type] ?? 0);
        // limit === 0 means the feature is fully blocked for this plan (see
        // cg_is_unlimited_limit()'s docblock) — that's always "100% used"
        // by definition, not a usage/limit ratio to compute (which would
        // divide by zero).
        $pct = ($limit === 0) ? 100 : round(($usage / $limit) * 100);
        if ($pct >= 80) {
            $warnings[] = [
                'action_type' => $type,
                'label'       => $labels[$type] ?? $type,
                'usage'       => $usage,
                'limit'       => $limit,
                'percentage'  => min($pct, 100),
                'is_at_limit' => ($usage >= $limit),
            ];
        }
    }
    return $warnings;
}


/**
 * Returns an HTML message explaining why cg_can_perform_action() blocked an
 * action. Two genuinely different situations both fail-closed the same way
 * in cg_can_perform_action(), but they are NOT the same thing to the site
 * owner and must not share the same "you're at your limit, upgrade" wording:
 *
 *  - The site has never successfully synced with Headquarters (cg_api_cache
 *    is empty — e.g. registration never got a working API key, or the last
 *    sync's signature failed to verify). Nothing has actually been used;
 *    telling the owner to "upgrade" here is simply wrong.
 *  - The site HAS synced, and usage is genuinely at or over the plan limit.
 *
 * @param string $action_type The action type that was blocked.
 * @return string
 */
function cg_get_limit_reached_message($action_type)
{
    $config = get_transient('cg_api_cache');
    // Usage is a sparse map (see cg_can_perform_action()) — only the limit
    // needs to be present to say this site has genuinely synced.
    $has_synced = $config && is_array($config) && isset($config['limits'][$action_type]);

    if (!$has_synced) {
        return sprintf(
            /* translators: 1: settings page link open tag, 2: close tag */
            __('CheckoutGuard hasn\'t synced with Headquarters yet, so this feature is paused until it does — this is not a plan limit. Go to %1$sSettings%2$s and click "Sync Now".', 'checkoutguard'),
            '<a href="' . esc_url(admin_url('admin.php?page=cg-settings')) . '">',
            '</a>'
        );
    }

    $labels = [
        'checkouts'       => __('Incomplete Checkouts', 'checkoutguard'),
        'fraud_ips'       => __('Blocked IPs', 'checkoutguard'),
        'fraud_emails'    => __('Blocked Emails', 'checkoutguard'),
        'fraud_phones'    => __('Blocked Phones', 'checkoutguard'),
        'fraud_domains'   => __('Blocked Domains', 'checkoutguard'),
        'fraud_addresses' => __('Blocked Addresses', 'checkoutguard'),
        'fraud_devices'   => __('Blocked Devices', 'checkoutguard'),
        'courier_checks'  => __('Courier API Checks', 'checkoutguard'),
        'contact_reveals' => __('Full Contact Details', 'checkoutguard'),
    ];
    $label = $labels[$action_type] ?? $action_type;
    return sprintf(
        /* translators: 1: feature label, 2: pricing link open tag, 3: close tag, 4: WhatsApp link open tag, 5: close tag */
        __('You have reached your plan limit for %1$s. %2$sUpgrade your plan%3$s or %4$scontact us on WhatsApp%5$s.', 'checkoutguard'),
        esc_html($label),
        '<a href="#" class="cg-open-upgrade-modal">',
        '</a>',
        '<a href="' . esc_url(CG_WHATSAPP_URL) . '" target="_blank">',
        '</a>'
    );
}

