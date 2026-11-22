<?php
/**
 * Upgrade tests: a site updated from 1.4.2 loses every option, transient and
 * cron event of the removed 1.4.2 site key request module on the first load
 * of the new version (version upgrade routine), not only on deactivate or
 * uninstall.
 *
 * Run on a local test site only (never production):
 *   wp eval-file tests/qa/upgrade-142-cleanup-tests.php
 * It writes and then deletes the 1.4.2 site key options and
 * cg_sitekey_next_allowed_at, and restores cg_plugin_version. No HQ call.
 */
if (!defined('ABSPATH')) {
    exit;
}
$GLOBALS['R'] = ['pass' => 0, 'fail' => 0];
function up_check($id, $cond, $detail = '') {
    $GLOBALS['R'][$cond ? 'pass' : 'fail']++;
    echo ($cond ? 'PASS ' : 'FAIL ') . $id . ($detail !== '' ? ' - ' . $detail : ''), "\n";
}
function up_has_option($name) {
    wp_cache_delete($name, 'options');
    wp_cache_delete('alloptions', 'options');
    wp_cache_delete('notoptions', 'options');
    return get_option($name, '__none__') !== '__none__';
}
function up_seed_142($version) {
    $now = time();
    update_option('cg_sitekey_retry_lock', $now . '|tok', false);
    update_option('cg_sitekey_backoff_step', 3, false);
    update_option('cg_sitekey_recent_calls', [$now - 60, $now - 30], false);
    update_option('cg_sitekey_next_allowed_at', $now + 1800, false);
    set_transient('cg_key_requested', $now, 15 * MINUTE_IN_SECONDS);
    wp_clear_scheduled_hook('cg_sitekey_retry_event');
    wp_schedule_single_event($now + 600, 'cg_sitekey_retry_event');
    if ($version === null) {
        delete_option('cg_plugin_version');
    } else {
        update_option('cg_plugin_version', $version);
    }
    return $now;
}
function up_left_142() {
    $left = [];
    foreach (['cg_sitekey_retry_lock', 'cg_sitekey_backoff_step', 'cg_sitekey_recent_calls'] as $o) {
        if (up_has_option($o)) {
            $left[] = $o;
        }
    }
    wp_cache_delete('_transient_cg_key_requested', 'options');
    if (get_transient('cg_key_requested') !== false) {
        $left[] = 'transient cg_key_requested';
    }
    if (wp_next_scheduled('cg_sitekey_retry_event') !== false) {
        $left[] = 'event cg_sitekey_retry_event';
    }
    return $left;
}

$saved_version = get_option('cg_plugin_version', null);
// The stored version of an updated site. Before the 1.5.0 version bump CG_VERSION
// is still 1.4.2, so another older version stands in for it.
$OLD = CG_VERSION === '1.4.2' ? '1.4.1' : '1.4.2';
if ($OLD !== '1.4.2') {
    echo "NOTE CG_VERSION is still 1.4.2: U2 and U5 use 1.4.1 as the stored version\n";
}

up_check('U0 routine and cleanup exist', function_exists('cg_maybe_run_version_upgrade') && function_exists('cg_sitekey_cleanup_142'));
up_check('U0b 1.4.2 module is gone', !function_exists('cg_sitekey_request_site_key') && !has_action('cg_sitekey_retry_event'));
up_check('U1 routine runs on init before the schema migrations',
    has_action('init', 'cg_maybe_run_version_upgrade') === 4 && has_action('init', 'cg_maybe_run_migrations') === 5,
    'upgrade=' . var_export(has_action('init', 'cg_maybe_run_version_upgrade'), true) . ' migrations=' . var_export(has_action('init', 'cg_maybe_run_migrations'), true));

// U2: 1.4.2 -> current version.
$now = up_seed_142($OLD);
up_check('U2a seeded 1.4.2 state is present', count(up_left_142()) === 5, implode(',', up_left_142()));
cg_maybe_run_version_upgrade();
up_check('U2b upgrade from ' . $OLD . ' removes every 1.4.2 option, transient and event', up_left_142() === [], implode(',', up_left_142()));
up_check('U2c cg_plugin_version is now CG_VERSION', get_option('cg_plugin_version') === CG_VERSION, (string) get_option('cg_plugin_version'));
wp_cache_delete('cg_sitekey_next_allowed_at', 'options');
up_check('U2d shared gate cg_sitekey_next_allowed_at is kept', (int) get_option('cg_sitekey_next_allowed_at') === $now + 1800);

// U3: same version again -> no-op (the routine runs once per version change).
up_seed_142(CG_VERSION);
cg_maybe_run_version_upgrade();
up_check('U3 same version: routine does nothing', count(up_left_142()) === 5, implode(',', up_left_142()));

// U4: no stored version at all.
up_seed_142(null);
cg_maybe_run_version_upgrade();
up_check('U4 missing cg_plugin_version: cleaned and recorded', up_left_142() === [] && get_option('cg_plugin_version') === CG_VERSION, implode(',', up_left_142()));

// U5: through the init hook itself, as on the first page load after the update.
up_seed_142($OLD);
global $wp_filter;
$cb = $wp_filter['init']->callbacks[4]['cg_maybe_run_version_upgrade'] ?? null;
if ($cb) {
    call_user_func($cb['function']);
}
up_check('U5 init priority 4 callback cleans 1.4.2 state', $cb !== null && up_left_142() === [], implode(',', up_left_142()));

// U6: cleanup is safe on a site that never had 1.4.2 state.
delete_option('cg_sitekey_retry_lock'); delete_option('cg_sitekey_backoff_step'); delete_option('cg_sitekey_recent_calls');
delete_transient('cg_key_requested'); wp_clear_scheduled_hook('cg_sitekey_retry_event');
$warn = 0;
set_error_handler(function () use (&$warn) { $warn++; return true; });
cg_sitekey_cleanup_142();
restore_error_handler();
up_check('U6 cleanup with nothing to clean: no warning', $warn === 0 && up_left_142() === []);

// Restore.
delete_option('cg_sitekey_next_allowed_at');
cg_sitekey_cleanup_142();
if ($saved_version === null) {
    delete_option('cg_plugin_version');
} else {
    update_option('cg_plugin_version', $saved_version);
}
$R = $GLOBALS['R'];
echo "== {$R['pass']} pass, {$R['fail']} fail\n";
