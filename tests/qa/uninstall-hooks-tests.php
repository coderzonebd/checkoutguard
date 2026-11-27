<?php
/**
 * Uninstall must always clear the plugin's WP-Cron events (U1-U4), while the
 * data and options are only removed when "delete_on_uninstall" is on.
 * Before 1.5.1 every wp_clear_scheduled_hook() sat inside the opt-in block,
 * so a default uninstall left cg_daily_sync_hook, cg_send_usage_decrements
 * and the rest scheduled (D12 only looked for the string). And
 * wp_clear_scheduled_hook() with no args only removes events that have no
 * args, so queued decrements (they carry args) stayed even with the opt-in.
 *
 * Run on a local test site only (never production):
 *   wp eval-file tests/qa/uninstall-hooks-tests.php
 * DROP TABLE is rewritten to a no-op SELECT and recorded; options and the
 * cron array are put back after each case.
 */
if (!defined('ABSPATH')) {
    exit;
}
global $wpdb;

$GLOBALS['QA_RES'] = [];
function qa_ok($id, $name, $cond, $info = '')
{
    $GLOBALS['QA_RES'][] = (bool) $cond;
    echo ($cond ? 'PASS' : 'FAIL') . " {$id} {$name}" . ($cond ? '' : " | {$info}") . "\n";
}

$hooks = [
    'cg_daily_sync_hook'        => [],
    'cg_cleanup_expired_blocks' => [],
    'cg_sitekey_request_event'  => [],
    'cg_sitekey_claim_event'    => [],
    'cg_sitekey_retry_event'    => [],
    // Queued decrements carry args; the clear must remove every args set.
    'cg_send_usage_decrements'  => [['checkouts', 2, 'order-1'], ['checkouts', 1, 'order-2']],
];

function qa_hook_events($hook)
{
    $n = 0;
    foreach ((array) _get_cron_array() as $ts => $list) {
        if (isset($list[$hook])) {
            $n += count($list[$hook]);
        }
    }
    return $n;
}

function qa_cg_options()
{
    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT option_name, option_value, autoload FROM {$wpdb->options}
         WHERE option_name LIKE 'cg\\_%' OR option_name LIKE '\\_transient\\_cg\\_%' OR option_name LIKE '\\_transient\\_timeout\\_cg\\_%'
         ORDER BY option_name",
        ARRAY_A
    );
    $out = [];
    foreach ($rows as $r) {
        $out[$r['option_name']] = [$r['option_value'], $r['autoload']];
    }
    return $out;
}

function qa_restore_options($snap)
{
    global $wpdb;
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE 'cg\\_%' OR option_name LIKE '\\_transient\\_cg\\_%' OR option_name LIKE '\\_transient\\_timeout\\_cg\\_%'"
    );
    foreach ($snap as $name => $v) {
        $wpdb->insert($wpdb->options, ['option_name' => $name, 'option_value' => $v[0], 'autoload' => $v[1]]);
    }
    wp_cache_flush();
}

function qa_run_uninstall($delete_on_uninstall, $hooks, $id)
{
    global $wpdb;
    $orig_opts = qa_cg_options();
    $orig_cron = _get_cron_array();

    $o = get_option('cg_plugin_options', []);
    $o = is_array($o) ? $o : [];
    $o['delete_on_uninstall'] = $delete_on_uninstall ? 1 : 0;
    $o['qa_uninstall_marker'] = 'keep-me';
    update_option('cg_plugin_options', $o);
    update_option('cg_license_status', 'qa-active');
    set_transient('cg_api_cache', ['limits' => ['checkouts' => 50], 'usage' => []], HOUR_IN_SECONDS);

    foreach ($hooks as $hook => $argsets) {
        // Drop events left by other runs, whatever their args.
        wp_unschedule_hook($hook);
        if (empty($argsets)) {
            wp_schedule_single_event(time() + 3600, $hook);
        } else {
            foreach ($argsets as $i => $args) {
                wp_schedule_single_event(time() + 3600 + $i, $hook, $args);
            }
        }
    }
    $scheduled = 0;
    foreach (array_keys($hooks) as $hook) {
        $scheduled += qa_hook_events($hook);
    }
    $before = qa_cg_options();

    $drops = [];
    $f = function ($q) use (&$drops) {
        if (stripos(ltrim($q), 'DROP TABLE') === 0) {
            $drops[] = $q;
            return 'SELECT 1';
        }
        return $q;
    };
    add_filter('query', $f);
    if (!defined('WP_UNINSTALL_PLUGIN')) {
        define('WP_UNINSTALL_PLUGIN', 'checkoutguard/checkoutguard.php');
    }
    include CG_PLUGIN_DIR . 'uninstall.php';
    remove_filter('query', $f);
    wp_cache_flush();

    $left = [];
    foreach (array_keys($hooks) as $hook) {
        $n = qa_hook_events($hook);
        if ($n > 0) {
            $left[$hook] = $n;
        }
    }
    $after = qa_cg_options();

    qa_ok($id . 'a', 'setup scheduled 7 events', $scheduled === 7, "scheduled={$scheduled}");
    qa_ok($id . 'b', 'no CG cron event left after uninstall', empty($left), wp_json_encode($left));
    foreach (array_keys($hooks) as $hook) {
        // wp_next_scheduled() without args, as the plugin itself checks it.
        $argsets = $hooks[$hook];
        $next = empty($argsets) ? wp_next_scheduled($hook) : wp_next_scheduled($hook, $argsets[0]);
        qa_ok($id . 'n', "wp_next_scheduled({$hook}) === false", $next === false, var_export($next, true));
    }
    if ($delete_on_uninstall) {
        qa_ok($id . 'c', 'option on: 9 tables dropped', count($drops) === 9, 'drops=' . count($drops));
        qa_ok($id . 'd', 'option on: cg_plugin_options removed', !isset($after['cg_plugin_options']), 'present');
    } else {
        qa_ok($id . 'c', 'option off: no table dropped', count($drops) === 0, 'drops=' . count($drops));
        qa_ok($id . 'd', 'option off: every cg_ option and transient unchanged', $after === $before,
            'missing=' . wp_json_encode(array_keys(array_diff_key($before, $after))));
        $po = get_option('cg_plugin_options', []);
        qa_ok($id . 'e', 'option off: plugin options kept', is_array($po) && ($po['qa_uninstall_marker'] ?? '') === 'keep-me', wp_json_encode($po));
    }

    _set_cron_array($orig_cron);
    qa_restore_options($orig_opts);
}

qa_run_uninstall(false, $hooks, 'U1');
qa_run_uninstall(true, $hooks, 'U2');

$fail = count(array_filter($GLOBALS['QA_RES'], function ($r) { return !$r; }));
echo 'SUMMARY ' . (count($GLOBALS['QA_RES']) - $fail) . '/' . count($GLOBALS['QA_RES']) . ' pass' . ($fail ? " FAIL {$fail}" : '') . "\n";
