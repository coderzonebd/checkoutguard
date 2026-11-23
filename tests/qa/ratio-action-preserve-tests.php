<?php
/**
 * BUG-03: Courier Analytics "When the ratio is too low" = Block is not kept.
 * cg_sanitize_and_sync_settings() (the register_setting sanitize callback)
 * runs on every update_option('cg_plugin_options'), and it rebuilds the
 * array from a fixed list. ratio_action is not on that list, so it is
 * dropped by the Courier Analytics AJAX save itself, by a save of any other
 * section and by the main settings form.
 *
 * Run on a local test site with WooCommerce only (never production):
 *   wp eval-file tests/qa/ratio-action-preserve-tests.php
 * The sanitize callback is registered here because wp eval-file does not
 * fire admin_init. HTTP is answered in this process; nothing leaves the
 * machine. cg_plugin_options is restored at the end.
 */
if (!defined('ABSPATH')) {
    exit;
}
$GLOBALS['QA_OPTS_ORIG'] = get_option('cg_plugin_options', []);
add_filter('pre_http_request', function () {
    return new WP_Error('qa_blocked', 'blocked in test');
}, PHP_INT_MAX);
cg_register_settings();

$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
wp_set_current_user((int) ($admins[0] ?? 1));
add_filter('wp_doing_ajax', '__return_true');
add_filter('wp_die_ajax_handler', function () {
    return function () { throw new RuntimeException('qa_ajax_done'); };
});

$GLOBALS['R'] = [];
function ok($id, $name, $pass, $detail) { $GLOBALS['R'][] = [$id, $name, $pass ? 'PASS' : 'FAIL', $detail]; }

// Base options as a configured store has them (written without the sanitize
// callback, like a value already in the database).
function qa_base($action) {
    $o = is_array($GLOBALS['QA_OPTS_ORIG']) ? $GLOBALS['QA_OPTS_ORIG'] : [];
    $o = array_merge($o, [
        'enable_courier_service' => true, 'enable_fraud_blocker' => true,
        'ratio_blocker_threshold' => 35, 'ratio_blocker_grace_period' => 2,
        'ratio_blocker_message' => 'qa ratio message',
        'fraud_auto_block_attempts' => 7, 'duplicate_blocker_window' => 45,
        'duplicate_blocker_enabled' => true,
    ]);
    if ($action === null) { unset($o['ratio_action']); } else { $o['ratio_action'] = $action; }
    remove_all_filters('sanitize_option_cg_plugin_options');
    update_option('cg_plugin_options', $o);
    cg_register_settings();
}
// The Courier Analytics / Fraud page save: admin-ajax cg_save_page_settings.
function qa_ajax_save($fields) {
    $_POST = $_REQUEST = ['action' => 'cg_save_page_settings', 'nonce' => wp_create_nonce('cg_save_page_settings_nonce'), 'fields' => $fields];
    ob_start();
    try { cg_handle_save_page_settings(); } catch (RuntimeException $e) { }
    $out = (string) ob_get_clean();
    $_POST = $_REQUEST = [];
    $j = json_decode($out, true);
    return !empty($j['success']);
}
// The main settings form (options.php): the posted array has no ratio_* or
// fraud_* page fields.
function qa_form_save() {
    $o = get_option('cg_plugin_options', []);
    $input = [
        'data_retention_policy' => 90, 'enable_courier_service' => '1', 'enable_fraud_blocker' => '1',
        'api_key' => $o['api_key'] ?? '',
    ];
    if (!isset($o['api_key'])) { unset($input['api_key']); }
    update_option('cg_plugin_options', $input);
}
function qa_get($k) { $o = get_option('cg_plugin_options', []); return $o[$k] ?? null; }
function qa_s($v) { return var_export($v, true); }

// R1: Courier Analytics save hold -> block keeps block.
qa_base('hold');
$sent = qa_ajax_save(['ratio_action' => 'block', 'ratio_blocker_threshold' => '35']);
ok('R1', 'Courier Analytics save hold -> block: ratio_action is block', $sent && qa_get('ratio_action') === 'block', 'ajax_ok=' . qa_s($sent) . ' ratio_action=' . qa_s(qa_get('ratio_action')));

// R2: first save block (no ratio_action stored yet).
qa_base(null);
$sent = qa_ajax_save(['ratio_action' => 'block']);
ok('R2', 'first Courier Analytics save with block: ratio_action is block', $sent && qa_get('ratio_action') === 'block', 'ajax_ok=' . qa_s($sent) . ' ratio_action=' . qa_s(qa_get('ratio_action')));

// R3: block stored, then another section (duplicate blocker) is saved.
qa_base('block');
$sent = qa_ajax_save(['duplicate_blocker_window' => '50']);
ok('R3', 'block stored, other page section saved: ratio_action still block', $sent && qa_get('ratio_action') === 'block' && qa_get('duplicate_blocker_window') === 50, 'ratio_action=' . qa_s(qa_get('ratio_action')) . ' dup_window=' . qa_s(qa_get('duplicate_blocker_window')));

// R4: block stored, then the main settings form is saved.
qa_base('block');
qa_form_save();
ok('R4', 'block stored, main settings form saved: ratio_action still block', qa_get('ratio_action') === 'block', 'ratio_action=' . qa_s(qa_get('ratio_action')));

// R5: block stored, HQ writes the options (sync / key rotation path).
qa_base('block');
cg_update_options_from_hq(get_option('cg_plugin_options', []));
ok('R5', 'block stored, cg_update_options_from_hq(): ratio_action still block', qa_get('ratio_action') === 'block', 'ratio_action=' . qa_s(qa_get('ratio_action')));

// R6: block -> hold is saved too (the fix must not pin block).
qa_base('block');
$sent = qa_ajax_save(['ratio_action' => 'hold']);
ok('R6', 'Courier Analytics save block -> hold: ratio_action is hold', $sent && qa_get('ratio_action') === 'hold', 'ratio_action=' . qa_s(qa_get('ratio_action')));

// R7: an unknown value is not stored as given.
qa_base('block');
$sent = qa_ajax_save(['ratio_action' => '<b>x</b>']);
ok('R7', 'Courier Analytics save with an unknown value: stored as hold, never the raw value', qa_get('ratio_action') === 'hold', 'ratio_action=' . qa_s(qa_get('ratio_action')));

// R7b: the main settings form path (options.php) posts a raw value; the
// sanitize callback must not store it as given either.
qa_base('block');
update_option('cg_plugin_options', ['data_retention_policy' => 90, 'enable_courier_service' => '1', 'ratio_action' => '<b>x</b>']);
ok('R7b', 'settings form posts an unknown ratio_action: stored as hold, never the raw value', qa_get('ratio_action') === 'hold', 'ratio_action=' . qa_s(qa_get('ratio_action')));

// R8: regression, the other page keys survive the main settings form save.
qa_base('block');
qa_form_save();
$got = [];
foreach (['ratio_blocker_threshold' => 35, 'ratio_blocker_grace_period' => 2, 'ratio_blocker_message' => 'qa ratio message', 'fraud_auto_block_attempts' => 7, 'duplicate_blocker_window' => 45, 'duplicate_blocker_enabled' => true] as $k => $want) {
    if (qa_get($k) != $want) { $got[] = "$k=" . qa_s(qa_get($k)); }
}
ok('R8', 'main settings form save keeps ratio_blocker_*, fraud_auto_block_attempts, duplicate_blocker_*', $got === [], $got === [] ? 'all kept' : 'lost: ' . implode(' ', $got));

// R9: regression, the same keys survive a Courier Analytics save.
qa_base('hold');
qa_ajax_save(['ratio_action' => 'block']);
$got = [];
foreach (['ratio_blocker_threshold' => 35, 'ratio_blocker_grace_period' => 2, 'ratio_blocker_message' => 'qa ratio message', 'fraud_auto_block_attempts' => 7, 'duplicate_blocker_window' => 45, 'duplicate_blocker_enabled' => true] as $k => $want) {
    if (qa_get($k) != $want) { $got[] = "$k=" . qa_s(qa_get($k)); }
}
ok('R9', 'Courier Analytics save keeps the other page keys', $got === [], $got === [] ? 'all kept' : 'lost: ' . implode(' ', $got));

// restore
remove_all_filters('sanitize_option_cg_plugin_options');
update_option('cg_plugin_options', $GLOBALS['QA_OPTS_ORIG']);
$pass = 0;
foreach ($GLOBALS['R'] as $r) {
    echo implode(' | ', $r) . "\n";
    if ($r[2] === 'PASS') { $pass++; }
}
echo 'SUMMARY ' . $pass . '/' . count($GLOBALS['R']) . "\n";
