<?php
/**
 * Test helpers for the Reconnect / Sync Now lock (1.5.1): one options row,
 * autoload no, value = Unix expiry. Read and written with raw SQL so the
 * tests see the table, not the options cache. Included by the register-*
 * qa tests; not loaded by the plugin.
 */
if (!defined('ABSPATH')) {
    exit;
}

function qa_rl_name()
{
    return defined('CG_REGISTER_RECONNECT_LOCK') ? CG_REGISTER_RECONNECT_LOCK : 'cg_register_reconnect_lock';
}

function qa_rl_flush()
{
    wp_cache_delete(qa_rl_name(), 'options');
    wp_cache_delete('notoptions', 'options');
    wp_cache_delete('alloptions', 'options');
}

/** Stored expiry (int), or null when there is no lock row. */
function qa_rl_value()
{
    global $wpdb;
    $v = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", qa_rl_name()));
    return $v === null ? null : (int) $v;
}

/** autoload of the lock row, or null when there is none. */
function qa_rl_autoload()
{
    global $wpdb;
    return $wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", qa_rl_name()));
}

function qa_rl_held()
{
    $v = qa_rl_value();
    return $v !== null && $v >= time();
}

/** Hold the lock as another request would: expiry = now + $ttl. */
function qa_rl_set($ttl)
{
    global $wpdb;
    $exp = time() + (int) $ttl;
    $wpdb->query($wpdb->prepare(
        "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %d, 'no') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
        qa_rl_name(),
        $exp
    ));
    qa_rl_flush();
    return $exp;
}

/** Remove the lock row and any pre-1.5.1 transient rows of the same name. */
function qa_rl_clear()
{
    global $wpdb;
    $n = qa_rl_name();
    foreach ([$n, '_transient_' . $n, '_transient_timeout_' . $n] as $o) {
        $wpdb->delete($wpdb->options, ['option_name' => $o]);
    }
    qa_rl_flush();
}
