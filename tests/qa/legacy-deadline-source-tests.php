<?php
/**
 * Where the legacy hard end may come from (SiteKey contract §7, Tanvir 22 Nov):
 * only legacy_key_deadline inside a verified signed GET /sites/status payload.
 * Never from an unsigned or tampered status, never from an error body, no
 * date in the plugin. Runs the real path cg_sync_with_hq() ->
 * cg_call_hq_api() -> cg_verify_signed_payload() -> cg_remember_last_known_plan().
 *
 * Run on a local machine only (never production), plain PHP CLI, no WordPress:
 *   php tests/qa/legacy-deadline-source-tests.php [plugin_dir]
 * WordPress functions and HTTP are stubbed in memory; nothing leaves the
 * machine. The signing keypair is made fresh by this test (libsodium); no
 * real HQ key is used. Control: QA_CTL=1 turns signature verification off in
 * a temp copy of utils.php, and C3a/C3d must then fail.
 */
if (PHP_SAPI !== 'cli') {
    exit;
}
if (!function_exists('sodium_crypto_sign_keypair')) {
    fwrite(STDERR, "libsodium missing\n");
    exit(2);
}
$KP = sodium_crypto_sign_keypair();
$SK = sodium_crypto_sign_secretkey($KP);
define('ABSPATH', '/tmp/');
define('MINUTE_IN_SECONDS', 60); define('HOUR_IN_SECONDS', 3600); define('DAY_IN_SECONDS', 86400); define('WEEK_IN_SECONDS', 604800);
define('CG_HQ_PUBLIC_KEY', base64_encode(sodium_crypto_sign_publickey($KP)));
define('CG_HEADQUARTERS_URL', 'http://hq.invalid');
define('CG_PRODUCT_SLUG', 'checkoutguard');
$GLOBALS['__opt'] = []; $GLOBALS['__tr'] = []; $GLOBALS['__resp'] = null; $GLOBALS['__hits'] = [];
function get_option($k, $d = false) { return array_key_exists($k, $GLOBALS['__opt']) ? $GLOBALS['__opt'][$k] : $d; }
function update_option($k, $v, $a = null) { $GLOBALS['__opt'][$k] = $v; return true; }
function delete_option($k) { unset($GLOBALS['__opt'][$k]); return true; }
function get_transient($k) { return $GLOBALS['__tr'][$k] ?? false; }
function set_transient($k, $v, $t = 0) { $GLOBALS['__tr'][$k] = $v; return true; }
function delete_transient($k) { unset($GLOBALS['__tr'][$k]); return true; }
function add_action(...$a) {} function add_filter(...$a) {}
function __($s, $d = null) { return $s; } function esc_html($s) { return $s; } function esc_html__($s, $d = null) { return $s; }
function wp_date($f, $ts) { return gmdate($f, $ts); }
function apply_filters($h, $v) { return $v; }
function wp_cache_delete(...$a) {}
function wp_next_scheduled(...$a) { return false; } function wp_schedule_single_event(...$a) { return true; } function wp_clear_scheduled_hook(...$a) { return 0; }
function maybe_unserialize($v) { if (!is_string($v)) return $v; $u = @unserialize($v); return ($u !== false || $v === 'b:0;') ? $u : $v; }
function sanitize_key($k) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $k)); }
function sanitize_text_field($s) { return trim((string) $s); }
function current_time($t, $gmt = 0) { return $t === 'mysql' ? gmdate('Y-m-d H:i:s') : time(); }
function get_site_url() { return 'https://shop.example.test'; }
function wp_rand($a = 0, $b = 0) { return random_int($a, $b); }
function is_wp_error($v) { return $v instanceof WP_Error; }
class WP_Error { public $c; public $m; function __construct($c = '', $m = '') { $this->c = $c; $this->m = $m; } function get_error_message() { return $this->m; } function get_error_code() { return $this->c; } }
function wp_remote_request($url, $args) {
    $GLOBALS['__hits'][] = parse_url($url, PHP_URL_PATH);
    $r = $GLOBALS['__resp'];
    if (!$r || strpos($url, '/sites/status') === false) return new WP_Error('qa_blocked', 'blocked in test');
    return ['code' => $r[0], 'body' => $r[1], 'headers' => []];
}
function wp_remote_retrieve_response_code($r) { return $r['code']; }
function wp_remote_retrieve_body($r) { return $r['body']; }
function wp_remote_retrieve_header($r, $h) { return ''; }
class StubWpdb { public $options = 'wp_options'; public $prefix = 'wp_';
  function prepare($q, ...$a) { return $a[0] ?? $q; }
  function get_var($name) { if (!array_key_exists($name, $GLOBALS['__opt'])) return null; $v = $GLOBALS['__opt'][$name]; return (is_array($v) || is_object($v)) ? serialize($v) : (string) $v; }
  function query($q) { return 0; } function insert(...$a) { return 1; } }
$GLOBALS['wpdb'] = new StubWpdb();

$dir = $argv[1] ?? dirname(__DIR__, 2);
$utils = $dir . '/includes/utils.php';
if (getenv('QA_CTL') === '1') {
    $src = file_get_contents($utils);
    $mut = str_replace('if (!$is_valid) {', 'if (false) {', $src, $n);
    $utils = sys_get_temp_dir() . '/cg-ctl-utils-' . getmypid() . '.php';
    file_put_contents($utils, $mut);
    echo "CONTROL: signature check off ($n)\n";
    if ($n !== 1) {
        fwrite(STDERR, "CONTROL: expected exactly 1 signature check, found $n\n");
        @unlink($utils);
        exit(2);
    }
}
require $utils;
require $dir . '/includes/site-key.php';
require $dir . '/includes/admin/admin-settings-page.php';
$pass = 0; $fail = 0;
function ok($name, $cond, $info = '') { global $pass, $fail; if ($cond) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name  $info\n"; } }

// Placeholder key for the stubbed options only; never a real key.
$K = CG_SITEKEY_PREFIX . 'test_placeholder_a';
$D = DAY_IN_SECONDS;
$T10 = time() + 10 * $D; $T10 -= $T10 % 60;
$iso = function ($ts) { return gmdate('Y-m-d\TH:i:s\Z', $ts); };
$payload = function ($extra = []) { return array_merge(['status' => 'active', 'plan' => 'pro', 'plan_slug' => 'pro', 'iat' => time()], $extra); };
$signed = function ($p) use ($SK) { $ps = json_encode($p); return ['payload_string' => $ps, 'signature' => base64_encode(sodium_crypto_sign_detached($ps, $SK))]; };
$reset = function () use ($K) { $GLOBALS['__opt'] = ['cg_plugin_options' => ['api_key' => $K]]; $GLOBALS['__tr'] = []; };
$sync = function ($code, $body) { $GLOBALS['__resp'] = [$code, is_string($body) ? $body : json_encode($body)]; return cg_sync_with_hq(); };
$dl = function () { $l = get_option('cg_last_known_plan', null); return is_array($l) && array_key_exists('legacy_key_deadline', $l) ? $l['legacy_key_deadline'] : 'no-row'; };

// C1 signed status with the field: stored; far date clamped to sync + 60 days.
$reset(); $r = $sync(200, $signed($payload(['legacy_key_deadline' => $iso($T10)])));
ok('C1 signed status with legacy_key_deadline: stored', $r !== false && $dl() === $T10, var_export($dl(), true));
$reset(); $sync(200, $signed($payload(['legacy_key_deadline' => '2099-12-31T00:00:00Z']))); $l = get_option('cg_last_known_plan');
ok('C1b signed, deadline beyond 60 days: clamped to sync + 60 days', $dl() === $l['synced_at'] + 60 * $D, var_export($dl(), true));
// C2 signed status without the field: no hard end, 72-hour rule only.
$reset(); $r = $sync(200, $signed($payload()));
ok('C2 signed status without the field: no hard end (null)', $r !== false && $dl() === null, var_export($dl(), true));
cg_sitekey_on_response(403, 'site_key_required', $K);
ok('C2b no field + site_key_required: no grace date, 72-hour sentence', cg_plan_key_grace_until() === null && strpos(cg_sitekey_plan_note(), '72 hours') !== false, cg_sitekey_plan_note());
// C2c a deadline from an earlier signed status is cleared by a signed status without the field.
$reset(); $sync(200, $signed($payload(['legacy_key_deadline' => $iso($T10)]))); $before = $dl();
$r = $sync(200, $signed($payload()));
ok('C2c signed with deadline, then signed without the field: null stored', $before === $T10 && $r !== false && $dl() === null, var_export($before, true) . ' -> ' . var_export($dl(), true));
// C3 unsigned or tampered status: the value is never taken.
$reset(); $sync(200, $signed($payload()));
$t = $signed($payload()); $t['payload_string'] = json_encode($payload(['legacy_key_deadline' => $iso($T10)]));
$r = $sync(200, $t);
ok('C3a tampered payload_string with a deadline: rejected, stored value unchanged', $r === false && $dl() === null && strpos((string) get_option('cg_license_status'), 'failed') === 0, var_export($dl(), true));
$reset(); $r = $sync(200, $payload(['legacy_key_deadline' => $iso($T10)]));
ok('C3b unsigned status with a deadline: rejected, nothing stored', $r === false && $dl() === 'no-row', var_export($dl(), true));
$reset(); $b = $signed($payload()); $b['legacy_key_deadline'] = $iso($T10); $r = $sync(200, $b);
ok('C3c deadline next to (not inside) a valid signed payload: ignored', $r !== false && $dl() === null, var_export($dl(), true));
$reset(); $b = $signed($payload(['legacy_key_deadline' => $iso($T10)])); $b['signature'] = base64_encode(str_repeat("\0", 64)); $r = $sync(200, $b);
ok('C3d deadline in a payload with a wrong signature: rejected', $r === false && $dl() === 'no-row', var_export($dl(), true));
// C4 error bodies carrying a deadline: never used.
foreach (['C4a' => [403, 'site_key_required'], 'C4b' => [403, 'legacy_expired'], 'C4c' => [409, 'claim_required'], 'C4d' => [401, '']] as $id => [$code, $c]) {
    $reset(); $sync(200, $signed($payload()));
    $body = ['error' => 'x', 'legacy_key_deadline' => $iso($T10)]; if ($c !== '') $body['code'] = $c;
    $r = $sync($code, $body);
    ok("$id error body $code " . ($c ?: 'without code') . ' with a deadline: not used', $r === false && $dl() === null && cg_plan_key_grace_until() === null && strpos(cg_sitekey_plan_note(), gmdate('Y-m-d', $T10)) === false, var_export($dl(), true) . ' / ' . cg_sitekey_plan_note());
}
ok('Z only /sites/status was answered, nothing left the machine', array_unique($GLOBALS['__hits']) === ['/api/v1/sites/status'], json_encode(array_count_values($GLOBALS['__hits'])));
if (getenv('QA_CTL') === '1') { @unlink($utils); }
echo "RESULT $pass/" . ($pass + $fail) . "\n";
exit($fail ? 1 : 0);
