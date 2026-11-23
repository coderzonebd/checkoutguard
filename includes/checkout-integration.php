<?php
// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

function cg_delete_incomplete_checkout_on_order_completion($order_id)
{
    if (!$order_id) {
        return;
    }
    $order = wc_get_order($order_id);
    if (!$order) {
        return;
    }

    // A Store API (block checkout) draft is made when the cart loads, before
    // the customer has typed anything. Cleaning up now would set the guard
    // below and the real order would later skip the cleanup.
    if (method_exists($order, 'get_status') && $order->get_status() === 'checkout-draft') {
        return;
    }

    // A blocked or closed attempt is not a placed order: draft -> cancelled
    // still fires woocommerce_new_order, but the customer's incomplete row
    // must stay, usage must not go down and no order-placed mark is set.
    if ($order->get_meta('_cg_blocked', true) === '1' || $order->get_meta('_cg_attempt_closed', true) === '1') {
        return;
    }

    // Prevent duplicate cleanup execution for the same order
    if ($order->get_meta('_cg_incomplete_cleaned')) {
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'cg_incomplete_checkouts';

    $where_clauses = [];
    $where_params = [];

    // On orders created in wp-admin (manual invoice, Recover button, WC
    // "Add order") the WC session and the device cookie of the request
    // belong to the shop manager, not the customer: do not use them.
    $from_request = !cg_order_is_admin_created($order);

    // 1. Match by WooCommerce session ID if present
    if ($from_request && function_exists('WC') && WC()->session && WC()->session->get_customer_id()) {
        $where_clauses[] = 'session_id = %s';
        $where_params[] = WC()->session->get_customer_id();
    }

    // 2. Match by Device Fingerprint
    $device_fp = $order->get_meta('_cg_device_fingerprint');
    if ($from_request && empty($device_fp) && !empty($_COOKIE['cg_device_fp'])) {
        $device_fp = sanitize_text_field(wp_unslash($_COOKIE['cg_device_fp']));
    }
    if (!empty($device_fp)) {
        $where_clauses[] = 'device_fingerprint = %s';
        $where_params[] = $device_fp;
    }

    // 3. Match by Normalized Phone Number
    $raw_phone = $order->get_billing_phone();
    if (!empty($raw_phone)) {
        $phone = cg_normalize_phone_number($raw_phone);
        if (!empty($phone) && strlen($phone) >= 7) {
            $where_clauses[] = 'phone = %s';
            $where_params[] = $phone;
        }
    }

    // 4. Match by Billing Email
    $email = sanitize_email($order->get_billing_email());
    if (!empty($email) && is_email($email)) {
        $where_clauses[] = 'email = %s';
        $where_params[] = $email;
    }

    // No identifier yet (for example wc_create_order() before the billing
    // fields are set): do not mark the order, so a later call can clean up.
    if (empty($where_clauses)) {
        return;
    }

    // A tracker request sent just before the order (debounced field change,
    // blur on the place-order click) can arrive after this cleanup. Mark the
    // customer for a short time so that request does not add the checkout
    // again as incomplete and count it again.
    cg_mark_order_placed($where_params);

    $order->update_meta_data('_cg_incomplete_cleaned', '1');
    if (method_exists($order, 'save')) {
        $order->save();
    }

    // Recovered rows are the record of a recovery, not an abandoned
    // checkout: keep them.
    $records = $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM {$table_name} WHERE status <> 'recovered' AND (" . implode(' OR ', $where_clauses) . ")",
        ...$where_params
    ));

    if (!empty($records)) {
        $count = count($records);
        $placeholders = implode(',', array_fill(0, $count, '%d'));
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table_name} WHERE id IN ($placeholders)",
            ...$records
        ));

        // HQ is told later from WP-Cron: this runs while the customer places
        // the order, and each HQ call may wait up to 15 s. Only rows this
        // request really removed are given back (a parallel hook may have
        // deleted some of them first).
        if ($deleted) {
            cg_queue_usage_decrement('checkouts', min($count, (int) $deleted), 'order-' . (int) $order->get_id());
        }
    }
}

/**
 * Transient key for one customer key (session id, device fingerprint,
 * normalized phone or email) that just placed an order.
 */
function cg_order_placed_mark_key($value)
{
    return 'cg_ordered_' . md5(strtolower(trim((string) $value)));
}

/**
 * Marks the given customer keys as having just placed an order.
 */
function cg_mark_order_placed(array $values)
{
    foreach (array_unique(array_filter(array_map('strval', $values), 'strlen')) as $value) {
        set_transient(cg_order_placed_mark_key($value), 1, CG_ORDER_PLACED_MARK_TTL);
    }
}

/**
 * True when any of the given customer keys placed an order within
 * CG_ORDER_PLACED_MARK_TTL seconds.
 */
function cg_recently_placed_order(array $values)
{
    foreach (array_filter(array_map('strval', $values), 'strlen') as $value) {
        if (get_transient(cg_order_placed_mark_key($value))) {
            return true;
        }
    }
    return false;
}

/**
 * True when the order was created by a shop manager in wp-admin: the CG
 * manual invoice, the CG Recover button, or WooCommerce "Add order".
 * The request's WC session, device cookie and IP are then the manager's.
 *
 * @param WC_Order $order
 * @return bool
 */
function cg_order_is_admin_created($order)
{
    return in_array($order->get_created_via(), ['admin', 'cg-manual-invoice', 'cg-recover'], true);
}

// ====================================================================
// HELPER FUNCTIONS FOR THE FRAUD PREVENTION CHAIN
// ====================================================================

/**
 * Logs a checkout attempt to the cg_checkout_attempts table.
 */
function cg_log_checkout_attempt($ip, $email, $phone, $device_fp, $address_hash, $result, $reason = '')
{
    global $wpdb;
    $wpdb->insert($wpdb->prefix . 'cg_checkout_attempts', [
        'ip_address'         => $ip,
        'email'              => $email,
        'phone'              => $phone,
        'device_fingerprint' => $device_fp,
        'address_hash'       => $address_hash,
        'attempt_result'     => $result,
        'block_reason'       => $reason,
        'created_at'         => current_time('mysql'),
    ]);
}

/**
 * Checks if a customer should be auto-blocked after repeated failed attempts.
 * If thresholds are exceeded, adds them to the relevant blocklists automatically.
 */
function cg_maybe_auto_block($ip, $email, $phone)
{
    $options = get_option('cg_plugin_options', []);
    if (empty($options['fraud_auto_block_enabled'])) {
        return;
    }

    $max_attempts = (int) ($options['fraud_auto_block_attempts'] ?? 3);
    $window_minutes = (int) ($options['fraud_auto_block_window'] ?? 1440);
    $block_days = (int) ($options['fraud_auto_block_duration'] ?? 30);
    $expires_at = $block_days > 0 ? date('Y-m-d H:i:s', current_time('timestamp') + ($block_days * DAY_IN_SECONDS)) : null;

    global $wpdb;
    $since = date('Y-m-d H:i:s', current_time('timestamp') - ($window_minutes * MINUTE_IN_SECONDS));
    $table = $wpdb->prefix . 'cg_checkout_attempts';

    // Auto-block IP (skip protected IPs like Cloudflare, server, or admin)
    if ($ip && !cg_is_protected_ip($ip)) {
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE ip_address = %s AND attempt_result != 'allowed' AND created_at > %s",
            $ip, $since
        ));
        if ($count >= $max_attempts) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}cg_blocked_ips WHERE ip_address = %s",
                $ip
            ));
            if (!$exists) {
                // Automatic blocks are not counted toward the plan's usage (1.5.0).
                cg_insert_auto_block($wpdb->prefix . 'cg_blocked_ips', [
                    'ip_address'  => $ip,
                    'added_by'    => 0,
                    'auto_blocked' => 1,
                    'reason'      => "Auto-blocked: {$count} failed attempts",
                    'expires_at'  => $expires_at,
                    'created_at'  => current_time('mysql'),
                ], 'fraud_ips');
            }
        }
    }

    // Auto-block Email
    if ($email) {
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE email = %s AND attempt_result != 'allowed' AND created_at > %s",
            $email, $since
        ));
        if ($count >= $max_attempts) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}cg_blocked_emails WHERE email_address = %s",
                $email
            ));
            if (!$exists) {
                // Automatic blocks are not counted toward the plan's usage (1.5.0).
                cg_insert_auto_block($wpdb->prefix . 'cg_blocked_emails', [
                    'email_address' => $email,
                    'added_by'      => 0,
                    'auto_blocked'  => 1,
                    'reason'        => "Auto-blocked: {$count} failed attempts",
                    'expires_at'    => $expires_at,
                    'created_at'    => current_time('mysql'),
                ], 'fraud_emails');
            }
        }
    }

    // Auto-block Phone
    if ($phone) {
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE phone = %s AND attempt_result != 'allowed' AND created_at > %s",
            $phone, $since
        ));
        if ($count >= $max_attempts) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}cg_blocked_numbers WHERE phone_number = %s",
                $phone
            ));
            if (!$exists) {
                // Automatic blocks are not counted toward the plan's usage (1.5.0).
                cg_insert_auto_block($wpdb->prefix . 'cg_blocked_numbers', [
                    'phone_number' => $phone,
                    'added_by'     => 0,
                    'auto_blocked' => 1,
                    'reason'       => "Auto-blocked: {$count} failed attempts",
                    'expires_at'   => $expires_at,
                    'created_at'   => current_time('mysql'),
                ], 'fraud_phones');
            }
        }
    }
}

/**
 * Checks velocity/rate limiting for an IP or phone number.
 * Returns true if rate limit is exceeded.
 */
function cg_check_velocity_limit($ip, $email, $phone)
{
    $options = get_option('cg_plugin_options', []);
    if (empty($options['fraud_velocity_enabled'])) {
        return false;
    }

    $max = (int) ($options['fraud_velocity_max_attempts'] ?? 5);
    $window = (int) ($options['fraud_velocity_window'] ?? 60);

    global $wpdb;
    $since = date('Y-m-d H:i:s', current_time('timestamp') - ($window * MINUTE_IN_SECONDS));
    $table = $wpdb->prefix . 'cg_checkout_attempts';

    // Check by IP
    if ($ip) {
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE ip_address = %s AND created_at > %s",
            $ip, $since
        ));
        if ($count >= $max) {
            return true;
        }
    }

    // Check by phone
    if ($phone) {
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE phone = %s AND created_at > %s",
            $phone, $since
        ));
        if ($count >= $max) {
            return true;
        }
    }

    return false;
}


// ====================================================================
// MAIN FRAUD PREVENTION CHECK (MULTI-LAYERED)
// ====================================================================
// UNIVERSAL VALIDATION ENGINE (CLEAN ARCHITECTURE)
// ====================================================================

/**
 * Universal validation context encapsulating all customer and request parameters.
 */
class CG_Validation_Context
{
    public $phone = '';
    public $email = '';
    public $first_name = '';
    public $last_name = '';
    public $address_1 = '';
    public $city = '';
    public $postcode = '';
    public $address_hash = '';
    public $ip = '';
    public $device_fp = '';
    public $products = [];
    public $total_amount = 0.0;
    public $order_source = 'unknown'; // classic_checkout, store_api, cartflows, landing_page_ajax, direct_order, rest_api
    public $request_type = 'classic'; // ajax, rest, classic, lifecycle
    public $user_id = 0;
    public $payment_method = '';
    public $order = null; // WC_Order if available
    public $extra = [];

    public function __construct(array $data = [])
    {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }

    public function to_array()
    {
        return [
            'phone'          => $this->phone,
            'email'          => $this->email,
            'first_name'     => $this->first_name,
            'last_name'      => $this->last_name,
            'address_1'      => $this->address_1,
            'city'           => $this->city,
            'postcode'       => $this->postcode,
            'address_hash'   => $this->address_hash,
            'ip'             => $this->ip,
            'device_fp'      => $this->device_fp,
            'products'       => $this->products,
            'total_amount'   => $this->total_amount,
            'order_source'   => $this->order_source,
            'request_type'   => $this->request_type,
            'user_id'        => $this->user_id,
            'payment_method' => $this->payment_method,
        ];
    }
}

/**
 * Structured validation result returned by the decision engine.
 */
class CG_Validation_Result
{
    public $is_blocked = false;
    public $block_code = ''; // blocked_duplicate, blocked_ip, blocked_device, blocked_email, blocked_domain, blocked_phone, blocked_address, blocked_velocity, blocked_score, blocked_ratio
    public $block_message = '';
    public $rule_triggered = '';
    public $risk_score = 0;
    public $matched_order_id = null;
    public $details = [];
    /** Ratio hold (paid plans): the checkout goes through, the order is put on hold. */
    public $is_held = false;

    public function is_blocked()
    {
        return $this->is_blocked;
    }

    public function is_held()
    {
        return $this->is_held;
    }

    public static function allow($details = [])
    {
        $res = new self();
        $res->is_blocked = false;
        $res->details = $details;
        return $res;
    }

    /**
     * Not blocked: the checkout continues and the order is put on hold later
     * (see cg_mark_ratio_hold()).
     */
    public static function hold($code, $rule = '', $details = [])
    {
        $res = new self();
        $res->is_blocked = false;
        $res->is_held = true;
        $res->block_code = $code;
        $res->rule_triggered = $rule;
        $res->details = $details;
        return $res;
    }

    public static function block($code, $message, $rule = '', $details = [])
    {
        $res = new self();
        $res->is_blocked = true;
        $res->block_code = $code;
        $res->block_message = $message;
        $res->rule_triggered = $rule;
        $res->details = $details;
        return $res;
    }
}

/**
 * Decoupled transport response adapters for different checkout interfaces.
 */
class CG_Response_Adapter
{
    /**
     * Responds to AJAX calls (landing pages, 1-click forms, custom AJAX).
     */
    public static function ajax(CG_Validation_Result $result)
    {
        if (!$result->is_blocked) {
            return;
        }
        wp_send_json_error([
            'message'    => wp_strip_all_tags($result->block_message),
            'block_code' => $result->block_code,
        ]);
        exit;
    }

    /**
     * Responds to WooCommerce Store API REST requests (Twenty Twenty-Five / Gutenberg Checkout Block).
     */
    public static function store_api(CG_Validation_Result $result)
    {
        if (!$result->is_blocked) {
            return;
        }
        if (class_exists('\Automattic\WooCommerce\StoreApi\Exceptions\RouteException')) {
            throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
                $result->block_code,
                wp_strip_all_tags($result->block_message),
                400
            );
        }
        throw new \Exception(wp_strip_all_tags($result->block_message));
    }

    /**
     * Responds to classic WooCommerce and CartFlows checkouts.
     */
    public static function classic(CG_Validation_Result $result, $errors = null)
    {
        if (!$result->is_blocked) {
            return;
        }
        $msg = wp_strip_all_tags($result->block_message);
        // Classic checkout validates in woocommerce_checkout_process (notice)
        // and again in woocommerce_after_checkout_validation ($errors); the
        // engine returns the cached result, so report each block only once.
        static $reported = [];
        $key = $result->block_code . '|' . $msg;
        if (isset($reported[$key])) {
            return;
        }
        $reported[$key] = true;
        if ($errors instanceof \WP_Error) {
            $errors->add($result->block_code, $msg);
        } else {
            wc_add_notice($msg, 'error');
        }
        if (function_exists('WC') && WC()->session) {
            WC()->session->set('cg_blocked_order_notice', $result->block_message);
        }
    }

    /**
     * Non-destructive lifecycle guard for direct order creation (cancels order, logs audit note, flags meta).
     */
    public static function lifecycle(CG_Validation_Result $result, WC_Order $order)
    {
        if (!$result->is_blocked) {
            return;
        }
        // The note carries the block code and the time only. The block message
        // is kept in _cg_block_reason; it is not copied into the note so the
        // note never carries another customer's order details.
        $order->set_status('cancelled', sprintf(
            __('[CheckoutGuard] Order automatically cancelled by Fraud/Duplicate Blocker. Code: %1$s. Rule: %2$s. Time: %3$s UTC.', 'checkoutguard'),
            $result->block_code,
            $result->rule_triggered ?: $result->block_code,
            gmdate('Y-m-d H:i:s')
        ));
        $order->update_meta_data('_cg_blocked', '1');
        $order->update_meta_data('_cg_block_reason', $result->block_message);
        $order->update_meta_data('_cg_block_code', $result->block_code);
    }
}

/**
 * Resolves multi-signal device fingerprint from header, cookie, explicit param, or IP+UA fallback hash.
 */
function cg_resolve_device_fingerprint($explicit_fp = '')
{
    if (!empty($explicit_fp)) {
        return sanitize_text_field(wp_unslash($explicit_fp));
    }
    // 1. High-precision HTTP Header (passed by tracker.js fetch/XHR/ajax wrappers)
    if (!empty($_SERVER['HTTP_X_CG_DEVICE_FP'])) {
        return sanitize_text_field(wp_unslash($_SERVER['HTTP_X_CG_DEVICE_FP']));
    }
    // 2. Persistent Cookie
    if (!empty($_COOKIE['cg_device_fp'])) {
        return sanitize_text_field(wp_unslash($_COOKIE['cg_device_fp']));
    }
    // 3. Fallback signature using IP + User Agent
    $ip = cg_get_client_ip();
    $ua = sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (!empty($ip) && !empty($ua)) {
        return 'ua_' . substr(md5($ip . '|' . $ua), 0, 16);
    }
    return '';
}

/**
 * Builds a CG_Validation_Context from a WC_Order or standard checkout request.
 */
function cg_build_validation_context_from_order($order, $source = 'direct_order', $request_type = 'lifecycle')
{
    $phone      = $order instanceof WC_Order ? $order->get_billing_phone() : '';
    $email      = $order instanceof WC_Order ? $order->get_billing_email() : '';
    $first_name = $order instanceof WC_Order ? $order->get_billing_first_name() : '';
    $last_name  = $order instanceof WC_Order ? $order->get_billing_last_name() : '';
    $address_1  = $order instanceof WC_Order ? $order->get_billing_address_1() : '';
    $city       = $order instanceof WC_Order ? $order->get_billing_city() : '';
    $postcode   = $order instanceof WC_Order ? $order->get_billing_postcode() : '';
    $device_fp  = $order instanceof WC_Order ? $order->get_meta('_cg_device_fingerprint', true) : '';

    $device_fp = cg_resolve_device_fingerprint($device_fp);
    $ip = cg_get_client_ip();
    if (empty($ip) && $order instanceof WC_Order) {
        $ip = $order->get_customer_ip_address();
    }

    $products = [];
    $total_amount = 0.0;
    if ($order instanceof WC_Order) {
        $total_amount = (float) $order->get_total();
        foreach ($order->get_items() as $item) {
            $pid = $item->get_variation_id() ? (int) $item->get_variation_id() : (int) $item->get_product_id();
            $products[$pid] = ($products[$pid] ?? 0) + (int) $item->get_quantity();
        }
    }

    return new CG_Validation_Context([
        'phone'          => cg_normalize_phone_number($phone),
        'email'          => sanitize_email($email),
        'first_name'     => sanitize_text_field($first_name),
        'last_name'      => sanitize_text_field($last_name),
        'address_1'      => sanitize_text_field($address_1),
        'city'           => sanitize_text_field($city),
        'postcode'       => sanitize_text_field($postcode),
        'address_hash'   => cg_hash_address($address_1, $city, $postcode),
        'ip'             => $ip,
        'device_fp'      => $device_fp,
        'products'       => $products,
        'total_amount'   => $total_amount,
        'order_source'   => $source,
        'request_type'   => $request_type,
        'user_id'        => $order instanceof WC_Order ? (int) $order->get_customer_id() : get_current_user_id(),
        'payment_method' => $order instanceof WC_Order ? $order->get_payment_method() : '',
        'order'          => $order instanceof WC_Order ? $order : null,
    ]);
}

/**
 * Builds a CG_Validation_Context from an incoming custom payload (e.g. landing page AJAX or custom forms).
 */
function cg_build_validation_context_from_payload(array $payload, $source = 'landing_page_ajax', $request_type = 'ajax')
{
    $phone      = $payload['billing_phone'] ?? ($payload['phone'] ?? ($payload['mobile'] ?? ''));
    $email      = $payload['billing_email'] ?? ($payload['email'] ?? '');
    $first_name = $payload['billing_name'] ?? ($payload['billing_first_name'] ?? ($payload['first_name'] ?? ($payload['name'] ?? '')));
    $last_name  = $payload['billing_last_name'] ?? ($payload['last_name'] ?? '');
    $address_1  = $payload['billing_address'] ?? ($payload['billing_address_1'] ?? ($payload['address'] ?? ''));
    $city       = $payload['billing_city'] ?? ($payload['city'] ?? '');
    $postcode   = $payload['billing_postcode'] ?? ($payload['postcode'] ?? '');
    $device_fp  = $payload['device_fingerprint'] ?? ($payload['cg_device_fingerprint'] ?? '');

    $device_fp = cg_resolve_device_fingerprint($device_fp);
    $ip = cg_get_client_ip();

    $products = [];
    $total_amount = floatval($payload['delivery_charge'] ?? 0);

    if (!empty($payload['products']) && is_array($payload['products'])) {
        foreach ($payload['products'] as $p) {
            $pid = !empty($p['variation_id']) ? (int) $p['variation_id'] : (int) ($p['id'] ?? ($p['product_id'] ?? 0));
            $qty = (int) ($p['quantity'] ?? ($p['qty'] ?? 1));
            $price = floatval($p['price'] ?? 0);
            if ($pid > 0) {
                $products[$pid] = ($products[$pid] ?? 0) + $qty;
                $total_amount += ($price * $qty);
            }
        }
    } elseif (!empty($payload['product_id'])) {
        $pid = (int) ($payload['variation_id'] ?? $payload['product_id']);
        $qty = (int) ($payload['quantity'] ?? ($payload['qty'] ?? 1));
        $products[$pid] = $qty;
    }

    return new CG_Validation_Context([
        'phone'          => cg_normalize_phone_number($phone),
        'email'          => sanitize_email($email),
        'first_name'     => sanitize_text_field($first_name),
        'last_name'      => sanitize_text_field($last_name),
        'address_1'      => sanitize_text_field($address_1),
        'city'           => sanitize_text_field($city),
        'postcode'       => sanitize_text_field($postcode),
        'address_hash'   => cg_hash_address($address_1, $city, $postcode),
        'ip'             => $ip,
        'device_fp'      => $device_fp,
        'products'       => $products,
        'total_amount'   => $total_amount,
        'order_source'   => $source,
        'request_type'   => $request_type,
        'user_id'        => get_current_user_id(),
        'payment_method' => sanitize_key($payload['payment_method'] ?? 'cod'),
        'order'          => null,
    ]);
}

/**
 * Backward-compatible helper to extract customer data array.
 */
function cg_extract_checkout_customer_data($order = null, $data = null)
{
    $phone = '';
    $email = '';
    $first_name = '';
    $last_name = '';
    $address_1 = '';
    $city = '';
    $postcode = '';
    $device_fp = '';

    if ($order instanceof WC_Order) {
        $phone      = $order->get_billing_phone();
        $email      = $order->get_billing_email();
        $first_name = $order->get_billing_first_name();
        $last_name  = $order->get_billing_last_name();
        $address_1  = $order->get_billing_address_1();
        $city       = $order->get_billing_city();
        $postcode   = $order->get_billing_postcode();
        $device_fp  = $order->get_meta('_cg_device_fingerprint', true);
    }

    if (is_array($data)) {
        $phone      = $phone ?: ($data['billing_phone'] ?? '');
        $email      = $email ?: ($data['billing_email'] ?? '');
        $first_name = $first_name ?: ($data['billing_first_name'] ?? '');
        $last_name  = $last_name ?: ($data['billing_last_name'] ?? '');
        $address_1  = $address_1 ?: ($data['billing_address_1'] ?? '');
        $city       = $city ?: ($data['billing_city'] ?? '');
        $postcode   = $postcode ?: ($data['billing_postcode'] ?? '');
    }

    if (!empty($_POST)) {
        $phone      = $phone ?: ($_POST['billing_phone'] ?? '');
        $email      = $email ?: ($_POST['billing_email'] ?? '');
        $first_name = $first_name ?: ($_POST['billing_first_name'] ?? '');
        $last_name  = $last_name ?: ($_POST['billing_last_name'] ?? '');
        $address_1  = $address_1 ?: ($_POST['billing_address_1'] ?? '');
        $city       = $city ?: ($_POST['billing_city'] ?? '');
        $postcode   = $postcode ?: ($_POST['billing_postcode'] ?? '');
        $device_fp  = $device_fp ?: ($_POST['cg_device_fingerprint'] ?? '');
    }

    if (empty($phone) || empty($email) || empty($device_fp)) {
        $raw_input = file_get_contents('php://input');
        if (!empty($raw_input)) {
            $json = json_decode($raw_input, true);
            if (is_array($json)) {
                $billing = $json['billing_address'] ?? ($json['billing'] ?? []);
                if (is_array($billing)) {
                    $phone      = $phone ?: ($billing['phone'] ?? '');
                    $email      = $email ?: ($billing['email'] ?? '');
                    $first_name = $first_name ?: ($billing['first_name'] ?? '');
                    $last_name  = $last_name ?: ($billing['last_name'] ?? '');
                    $address_1  = $address_1 ?: ($billing['address_1'] ?? '');
                    $city       = $city ?: ($billing['city'] ?? '');
                    $postcode   = $postcode ?: ($billing['postcode'] ?? '');
                }
                $device_fp = $device_fp ?: ($json['cg_device_fingerprint'] ?? '');
            }
        }
    }

    $device_fp = cg_resolve_device_fingerprint($device_fp);
    $ip = cg_get_client_ip();
    if (empty($ip) && $order instanceof WC_Order) {
        $ip = $order->get_customer_ip_address();
    }

    return [
        'ip'           => $ip,
        'phone'        => cg_normalize_phone_number($phone),
        'email'        => sanitize_email($email),
        'device_fp'    => $device_fp,
        'first_name'   => sanitize_text_field($first_name),
        'last_name'    => sanitize_text_field($last_name),
        'address_hash' => cg_hash_address($address_1, $city, $postcode),
    ];
}

// ====================================================================
// ADVANCED DUPLICATE ORDER LOOKUP
// ====================================================================

/**
 * Searches for recent active orders matching the customer's device fingerprint, IP, phone, or email.
 * Bulletproof: uses WooCommerce wc_get_orders() as primary layer, with a hardened raw SQL fallback.
 */
function cg_get_recent_matching_orders_for_duplicate_check($customer_data, $window_minutes, $options, $current_order = null)
{
    global $wpdb;

    $customer_phone = $customer_data['phone'] ?? '';
    $customer_email = $customer_data['email'] ?? '';
    $customer_ip    = $customer_data['ip'] ?? '';
    $device_fp      = $customer_data['device_fp'] ?? '';

    $match_phone  = !isset($options['duplicate_blocker_match_phone']) || !empty($options['duplicate_blocker_match_phone']);
    $match_email  = !isset($options['duplicate_blocker_match_email']) || !empty($options['duplicate_blocker_match_email']);
    $match_device = !isset($options['duplicate_blocker_match_device']) || !empty($options['duplicate_blocker_match_device']);
    $match_ip     = !isset($options['duplicate_blocker_match_ip']) || !empty($options['duplicate_blocker_match_ip']);
    // D2 pending founder decision: device, IP, phone and email are OR-matched
    // below, so an IP-only or device-only hit blocks, and 'identical' mode
    // also accepts an order-total-only match. Behaviour is unchanged here.

    $valid_statuses = apply_filters('cg_duplicate_blocker_order_statuses', ['processing', 'pending', 'on-hold', 'completed']);
    $exclude_id = ($current_order instanceof WC_Order) ? (int) $current_order->get_id() : 0;

    $matching_orders = [];
    $matched_order_ids = [];

    // ─── APPROACH 1: WooCommerce Native wc_get_orders (Primary & Bulletproof) ───
    if (function_exists('wc_get_orders')) {
        $recent_wc_orders = wc_get_orders([
            'limit'        => 30,
            'status'       => $valid_statuses,
            'date_created' => '>=' . (time() - ($window_minutes * MINUTE_IN_SECONDS)),
            'exclude'      => $exclude_id > 0 ? [$exclude_id] : [],
            'orderby'      => 'date',
            'order'        => 'DESC',
            'return'       => 'objects',
        ]);

        if (!empty($recent_wc_orders)) {
            $clean_curr_phone = !empty($customer_phone) ? preg_replace('/[^0-9]/', '', $customer_phone) : '';
            $clean_curr_email = !empty($customer_email) ? strtolower(trim($customer_email)) : '';

            foreach ($recent_wc_orders as $candidate) {
                if (!($candidate instanceof WC_Order)) {
                    continue;
                }
                $cand_id = (int) $candidate->get_id();
                if ($cand_id === $exclude_id) {
                    continue;
                }

                $is_match = false;

                // Match 1: Device Fingerprint
                if ($match_device && !empty($device_fp)) {
                    $cand_fp = $candidate->get_meta('_cg_device_fingerprint', true);
                    if (!empty($cand_fp) && $cand_fp === $device_fp) {
                        $is_match = true;
                    }
                }

                // Match 2: Client IP
                if (!$is_match && $match_ip && !empty($customer_ip)) {
                    $cand_ip = $candidate->get_customer_ip_address();
                    if (empty($cand_ip)) {
                        $cand_ip = $candidate->get_meta('_customer_ip_address', true);
                    }
                    if (!empty($cand_ip) && $cand_ip === $customer_ip) {
                        $is_match = true;
                    }
                }

                // Match 3: Phone
                if (!$is_match && $match_phone && !empty($clean_curr_phone)) {
                    $cand_phone = preg_replace('/[^0-9]/', '', $candidate->get_billing_phone() ?: '');
                    if (!empty($cand_phone)) {
                        if ($cand_phone === $clean_curr_phone || 
                            (strlen($clean_curr_phone) >= 7 && str_ends_with($cand_phone, $clean_curr_phone)) || 
                            (strlen($cand_phone) >= 7 && str_ends_with($clean_curr_phone, $cand_phone))) {
                            $is_match = true;
                        }
                    }
                }

                // Match 4: Email
                if (!$is_match && $match_email && !empty($clean_curr_email)) {
                    $cand_email = strtolower(trim($candidate->get_billing_email() ?: ''));
                    if (!empty($cand_email) && $cand_email === $clean_curr_email) {
                        $is_match = true;
                    }
                }

                if ($is_match && !isset($matched_order_ids[$cand_id])) {
                    $matched_order_ids[$cand_id] = true;
                    $matching_orders[] = $candidate;
                }
            }
        }
    }

    // ─── APPROACH 2: Direct SQL Query (Fallback & Redundancy) ───
    if (empty($matching_orders)) {
        $status_list = [];
        foreach ($valid_statuses as $s) {
            $clean = preg_replace('/^wc-/', '', $s);
            $status_list[] = "'" . esc_sql($clean) . "'";
            $status_list[] = "'wc-" . esc_sql($clean) . "'";
        }
        $sql_statuses = implode(',', array_unique($status_list));
        $cutoff_gmt   = gmdate('Y-m-d H:i:s', time() - ($window_minutes * MINUTE_IN_SECONDS));
        $cutoff_local = date('Y-m-d H:i:s', current_time('timestamp') - ($window_minutes * MINUTE_IN_SECONDS));

        $sql_order_ids = [];

        // 1. HPOS table (wp_wc_orders) - Note: only date_created_gmt exists in wp_wc_orders
        $hpos_table = $wpdb->prefix . 'wc_orders';
        $has_hpos = ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $hpos_table)) === $hpos_table);

        if ($has_hpos) {
            if ($match_device && !empty($device_fp)) {
                $meta_table = $wpdb->prefix . 'wc_orders_meta';
                $sql = "SELECT m.order_id FROM {$meta_table} m 
                        INNER JOIN {$hpos_table} o ON m.order_id = o.id
                        WHERE m.meta_key = '_cg_device_fingerprint' 
                          AND m.meta_value = %s 
                          AND o.status IN ({$sql_statuses}) 
                          AND o.date_created_gmt >= %s
                          " . ($exclude_id > 0 ? "AND o.id != {$exclude_id}" : "") . "
                        ORDER BY o.id DESC LIMIT 5";
                $ids = $wpdb->get_col($wpdb->prepare($sql, $device_fp, $cutoff_gmt));
                if (!empty($ids)) {
                    $sql_order_ids = array_merge($sql_order_ids, array_map('intval', $ids));
                }
            }

            if ($match_ip && !empty($customer_ip)) {
                $sql = "SELECT id FROM {$hpos_table} 
                        WHERE ip_address = %s 
                          AND status IN ({$sql_statuses}) 
                          AND date_created_gmt >= %s
                          " . ($exclude_id > 0 ? "AND id != {$exclude_id}" : "") . "
                        ORDER BY id DESC LIMIT 5";
                $ids = $wpdb->get_col($wpdb->prepare($sql, $customer_ip, $cutoff_gmt));
                if (!empty($ids)) {
                    $sql_order_ids = array_merge($sql_order_ids, array_map('intval', $ids));
                }
            }

            if ($match_phone && !empty($customer_phone)) {
                $addr_table = $wpdb->prefix . 'wc_order_addresses';
                $clean_phone = preg_replace('/[^0-9]/', '', $customer_phone);
                $sql = "SELECT a.order_id FROM {$addr_table} a
                        INNER JOIN {$hpos_table} o ON a.order_id = o.id
                        WHERE a.address_type = 'billing' 
                          AND (a.phone = %s OR a.phone LIKE %s)
                          AND o.status IN ({$sql_statuses}) 
                          AND o.date_created_gmt >= %s
                          " . ($exclude_id > 0 ? "AND o.id != {$exclude_id}" : "") . "
                        ORDER BY o.id DESC LIMIT 5";
                $ids = $wpdb->get_col($wpdb->prepare($sql, $customer_phone, '%' . $clean_phone, $cutoff_gmt));
                if (!empty($ids)) {
                    $sql_order_ids = array_merge($sql_order_ids, array_map('intval', $ids));
                }
            }

            if ($match_email && !empty($customer_email) && is_email($customer_email)) {
                $sql = "SELECT id FROM {$hpos_table} 
                        WHERE billing_email = %s 
                          AND status IN ({$sql_statuses}) 
                          AND date_created_gmt >= %s
                          " . ($exclude_id > 0 ? "AND id != {$exclude_id}" : "") . "
                        ORDER BY id DESC LIMIT 5";
                $ids = $wpdb->get_col($wpdb->prepare($sql, $customer_email, $cutoff_gmt));
                if (!empty($ids)) {
                    $sql_order_ids = array_merge($sql_order_ids, array_map('intval', $ids));
                }
            }
        }

        // 2. Classic posts table (wp_posts / wp_postmeta)
        if ($match_device && !empty($device_fp)) {
            $sql = "SELECT p.ID FROM {$wpdb->posts} p
                    INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                    WHERE p.post_type = 'shop_order'
                      AND p.post_status IN ({$sql_statuses})
                      AND (p.post_date >= %s OR p.post_date_gmt >= %s)
                      AND pm.meta_key = '_cg_device_fingerprint'
                      AND pm.meta_value = %s
                      " . ($exclude_id > 0 ? "AND p.ID != {$exclude_id}" : "") . "
                    ORDER BY p.ID DESC LIMIT 5";
            $ids = $wpdb->get_col($wpdb->prepare($sql, $cutoff_local, $cutoff_gmt, $device_fp));
            if (!empty($ids)) {
                $sql_order_ids = array_merge($sql_order_ids, array_map('intval', $ids));
            }
        }

        if ($match_ip && !empty($customer_ip)) {
            $sql = "SELECT p.ID FROM {$wpdb->posts} p
                    INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                    WHERE p.post_type = 'shop_order'
                      AND p.post_status IN ({$sql_statuses})
                      AND (p.post_date >= %s OR p.post_date_gmt >= %s)
                      AND pm.meta_key = '_customer_ip_address'
                      AND pm.meta_value = %s
                      " . ($exclude_id > 0 ? "AND p.ID != {$exclude_id}" : "") . "
                    ORDER BY p.ID DESC LIMIT 5";
            $ids = $wpdb->get_col($wpdb->prepare($sql, $cutoff_local, $cutoff_gmt, $customer_ip));
            if (!empty($ids)) {
                $sql_order_ids = array_merge($sql_order_ids, array_map('intval', $ids));
            }
        }

        if ($match_phone && !empty($customer_phone)) {
            $clean_phone = preg_replace('/[^0-9]/', '', $customer_phone);
            $sql = "SELECT p.ID FROM {$wpdb->posts} p
                    INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                    WHERE p.post_type = 'shop_order'
                      AND p.post_status IN ({$sql_statuses})
                      AND (p.post_date >= %s OR p.post_date_gmt >= %s)
                      AND pm.meta_key = '_billing_phone'
                      AND (pm.meta_value = %s OR pm.meta_value LIKE %s)
                      " . ($exclude_id > 0 ? "AND p.ID != {$exclude_id}" : "") . "
                    ORDER BY p.ID DESC LIMIT 5";
            $ids = $wpdb->get_col($wpdb->prepare($sql, $cutoff_local, $cutoff_gmt, $customer_phone, '%' . $clean_phone));
            if (!empty($ids)) {
                $sql_order_ids = array_merge($sql_order_ids, array_map('intval', $ids));
            }
        }

        if ($match_email && !empty($customer_email) && is_email($customer_email)) {
            $sql = "SELECT p.ID FROM {$wpdb->posts} p
                    INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                    WHERE p.post_type = 'shop_order'
                      AND p.post_status IN ({$sql_statuses})
                      AND (p.post_date >= %s OR p.post_date_gmt >= %s)
                      AND pm.meta_key = '_billing_email'
                      AND pm.meta_value = %s
                      " . ($exclude_id > 0 ? "AND p.ID != {$exclude_id}" : "") . "
                    ORDER BY p.ID DESC LIMIT 5";
            $ids = $wpdb->get_col($wpdb->prepare($sql, $cutoff_local, $cutoff_gmt, $customer_email));
            if (!empty($ids)) {
                $sql_order_ids = array_merge($sql_order_ids, array_map('intval', $ids));
            }
        }

        $sql_order_ids = array_unique(array_filter(array_map('intval', $sql_order_ids)));
        foreach ($sql_order_ids as $oid) {
            $obj = wc_get_order($oid);
            if ($obj) {
                $matching_orders[] = $obj;
            }
        }
    }

    // A blocked Store API attempt can leave a draft/pending order behind; it is
    // not a real earlier order and must not restart the duplicate window.
    $matching_orders = array_values(array_filter($matching_orders, function ($o) {
        return !($o instanceof WC_Order) || $o->get_meta('_cg_blocked', true) !== '1';
    }));

    return $matching_orders;
}

/**
 * Core Duplicate Order check returning structured result or null.
 */
function cg_check_duplicate_order_core($customer_data, $order = null)
{
    $options = get_option('cg_plugin_options', []);
    $dup_enabled = cg_duplicate_blocker_switch($options, 'duplicate_blocker_enabled');
    if (!$dup_enabled) {
        return null;
    }

    $customer_phone = $customer_data['phone'] ?? '';
    $customer_email = $customer_data['email'] ?? '';
    $customer_ip    = $customer_data['ip'] ?? '';
    $device_fp      = $customer_data['device_fp'] ?? '';
    $address_hash   = $customer_data['address_hash'] ?? '';

    $match_phone  = !isset($options['duplicate_blocker_match_phone']) || !empty($options['duplicate_blocker_match_phone']);
    $match_email  = !isset($options['duplicate_blocker_match_email']) || !empty($options['duplicate_blocker_match_email']);
    $match_device = !isset($options['duplicate_blocker_match_device']) || !empty($options['duplicate_blocker_match_device']);
    $match_ip     = !isset($options['duplicate_blocker_match_ip']) || !empty($options['duplicate_blocker_match_ip']);

    if (
        (!$match_phone || empty($customer_phone)) &&
        (!$match_email || empty($customer_email)) &&
        (!$match_ip || empty($customer_ip)) &&
        (!$match_device || empty($device_fp))
    ) {
        return null;
    }

    $window_minutes = (int) ($options['duplicate_blocker_window'] ?? 10);
    if ($window_minutes <= 0) {
        $window_minutes = 10;
    }

    $recent_orders = cg_get_recent_matching_orders_for_duplicate_check(
        $customer_data,
        $window_minutes,
        $options,
        $order
    );

    if (empty($recent_orders)) {
        return null;
    }

    $mode = $options['duplicate_blocker_mode'] ?? 'identical';
    $duplicate_order = null;

    if ($mode === 'any') {
        $duplicate_order = $recent_orders[0];
    } else {
        $current_cart_items = [];
        $current_total = 0.00;

        if (!empty($customer_data['products']) && is_array($customer_data['products'])) {
            $current_cart_items = $customer_data['products'];
            ksort($current_cart_items);
            $current_total = floatval($customer_data['total_amount'] ?? 0.0);
        }

        if (empty($current_cart_items) && $order instanceof WC_Order) {
            $current_total = (float) $order->get_total();
            if (count($order->get_items()) > 0) {
                foreach ($order->get_items() as $item) {
                    $pid = $item->get_variation_id() ? (int) $item->get_variation_id() : (int) $item->get_product_id();
                    $current_cart_items[$pid] = ($current_cart_items[$pid] ?? 0) + (int) $item->get_quantity();
                }
                ksort($current_cart_items);
            }
        }
        
        if (empty($current_cart_items) && function_exists('WC') && WC()->cart && !WC()->cart->is_empty()) {
            $current_total = $current_total ?: (float) WC()->cart->get_total('edit');
            foreach (WC()->cart->get_cart() as $cart_item) {
                $pid = !empty($cart_item['variation_id']) ? (int) $cart_item['variation_id'] : (int) $cart_item['product_id'];
                $current_cart_items[$pid] = ($current_cart_items[$pid] ?? 0) + (int) $cart_item['quantity'];
            }
            ksort($current_cart_items);
        }

        foreach ($recent_orders as $prev_order) {
            $order_items = [];
            foreach ($prev_order->get_items() as $item) {
                $item_product_id = $item->get_variation_id() ? (int) $item->get_variation_id() : (int) $item->get_product_id();
                $order_items[$item_product_id] = ($order_items[$item_product_id] ?? 0) + (int) $item->get_quantity();
            }
            ksort($order_items);

            // 1. Exact cart match
            if (!empty($current_cart_items) && $current_cart_items === $order_items) {
                $duplicate_order = $prev_order;
                break;
            }

            // 2. Order total match (e.g. headless or custom AJAX checkout)
            $prev_total = (float) $prev_order->get_total();
            if ($current_total > 0 && abs($current_total - $prev_total) < 0.01 && !empty($order_items)) {
                $duplicate_order = $prev_order;
                break;
            }
        }
    }

    if ($duplicate_order) {
        $order_number = $duplicate_order->get_order_number();

        cg_log_checkout_attempt(
            $customer_ip,
            $customer_email,
            $customer_phone,
            $device_fp,
            $address_hash,
            'blocked_duplicate',
            sprintf('Duplicate of Order #%s (%d min window)', $order_number, $window_minutes)
        );

        $is_pro = function_exists('cg_is_pro_plan') && cg_is_pro_plan();
        // The matched order can belong to someone else (same IP or device), so
        // the default notice never names it.
        $default_msg = __('You have already placed an order recently. Please wait a few moments before ordering again, or contact us if you need to modify your order.', 'checkoutguard');
        $raw_message = ($is_pro && !empty($options['duplicate_blocker_message'])) ? $options['duplicate_blocker_message'] : $default_msg;

        // The order number is never shown, not even in a custom message and not
        // even when email or phone match: typed contact details prove nothing.
        // A legacy {order_id} placeholder (with or without a leading '#') is
        // removed.
        $message = str_replace(
            ['{time_window}', '{phone}'],
            [$window_minutes, $customer_phone ?: ''],
            $raw_message
        );
        $message = preg_replace('/\(\s*#?\s*\{order_id\}\s*\)/', '', $message);
        $message = preg_replace('/#?\s*\{order_id\}/', '', $message);
        $message = preg_replace('/ {2,}/', ' ', preg_replace('/ +([.,;:!?])/', '$1', $message));

        $message = trim((string) $message);

        // A custom message that held only the placeholder must still block.
        return $message !== '' ? $message : $default_msg;
    }

    return null;
}

// ====================================================================
// CORE DECISION & VALIDATION ENGINE
// ====================================================================

class CG_Validation_Engine
{
    private static $evaluated_contexts = [];

    /**
     * Evaluates a CG_Validation_Context through all security rules.
     * Guaranteed idempotent per request.
     *
     * @param CG_Validation_Context $context
     * @return CG_Validation_Result
     */
    public static function validate(CG_Validation_Context $context)
    {
        $cache_key = md5(
            $context->ip . '|' .
            $context->phone . '|' .
            $context->email . '|' .
            $context->device_fp . '|' .
            ($context->order instanceof WC_Order ? $context->order->get_id() : 0)
        );

        if (isset(self::$evaluated_contexts[$cache_key])) {
            return self::$evaluated_contexts[$cache_key];
        }

        // Extensibility hook: before validation runs
        do_action('checkoutguard_before_validation', $context);

        $options = get_option('cg_plugin_options', []);

        // ─── 0. User Role Exclusion ───
        if ($context->user_id) {
            $user = get_userdata($context->user_id);
            if ($user && !empty($options['exclude_user_roles'])) {
                foreach ((array) $options['exclude_user_roles'] as $role) {
                    if (in_array($role, $user->roles, true)) {
                        $result = CG_Validation_Result::allow(['reason' => 'excluded_role', 'role' => $role]);
                        return self::finalize($result, $context, $cache_key);
                    }
                }
            }
        }

        $customer_data = $context->to_array();

        // ─── CHECK 1: DUPLICATE ORDER BLOCKER ───
        $dup_error = cg_check_duplicate_order_core($customer_data, $context->order);
        if ($dup_error) {
            $result = CG_Validation_Result::block('blocked_duplicate', $dup_error, 'duplicate_order_window');
            return self::finalize($result, $context, $cache_key);
        }

        // ─── CHECK 2: MULTI-LAYER FRAUD BLOCKER ───
        $fraud_enabled = !isset($options['enable_fraud_blocker']) || !empty($options['enable_fraud_blocker']);
        if ($fraud_enabled) {
            global $wpdb;
            $now = current_time('mysql');

            $block_message = !empty($options['custom_block_message'])
                ? esc_html($options['custom_block_message'])
                : __('Your order cannot be processed at this time. Please contact support if you believe this is an error.', 'checkoutguard');

            // Velocity / Rate Limiting
            if (cg_check_velocity_limit($context->ip, $context->email, $context->phone)) {
                cg_log_checkout_attempt($context->ip, $context->email, $context->phone, $context->device_fp, $context->address_hash, 'blocked_velocity', 'Rate limit exceeded');
                cg_maybe_auto_block($context->ip, $context->email, $context->phone);
                $msg = __('Too many checkout attempts. Please try again later.', 'checkoutguard');
                $result = CG_Validation_Result::block('blocked_velocity', $msg, 'velocity_rate_limit');
                return self::finalize($result, $context, $cache_key);
            }

            // IP Blocklist
            if (!empty($context->ip)) {
                $is_ip_blocked = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}cg_blocked_ips WHERE ip_address = %s AND (expires_at IS NULL OR expires_at > %s) LIMIT 1",
                    $context->ip, $now
                ));
                if ($is_ip_blocked) {
                    cg_log_checkout_attempt($context->ip, $context->email, $context->phone, $context->device_fp, $context->address_hash, 'blocked_ip', "IP: {$context->ip}");
                    cg_maybe_auto_block($context->ip, $context->email, $context->phone);
                    $result = CG_Validation_Result::block('blocked_ip', $block_message . ' (Ref: IP)', 'ip_blocklist');
                    return self::finalize($result, $context, $cache_key);
                }
            }

            // Email & Domain Blocklist
            if (!empty($context->email) && is_email($context->email)) {
                $is_email_blocked = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}cg_blocked_emails WHERE email_address = %s AND (expires_at IS NULL OR expires_at > %s) LIMIT 1",
                    $context->email, $now
                ));
                if ($is_email_blocked) {
                    cg_log_checkout_attempt($context->ip, $context->email, $context->phone, $context->device_fp, $context->address_hash, 'blocked_email', "Email: {$context->email}");
                    cg_maybe_auto_block($context->ip, $context->email, $context->phone);
                    $result = CG_Validation_Result::block('blocked_email', $block_message . ' (Ref: Email)', 'email_blocklist');
                    return self::finalize($result, $context, $cache_key);
                }

                $email_domain = strtolower(substr($context->email, strrpos($context->email, '@') + 1));
                if ($email_domain) {
                    $is_domain_blocked = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM {$wpdb->prefix}cg_blocked_domains WHERE domain = %s AND (expires_at IS NULL OR expires_at > %s) LIMIT 1",
                        $email_domain, $now
                    ));
                    if ($is_domain_blocked) {
                        cg_log_checkout_attempt($context->ip, $context->email, $context->phone, $context->device_fp, $context->address_hash, 'blocked_domain', "Domain: {$email_domain}");
                        cg_maybe_auto_block($context->ip, $context->email, $context->phone);
                        $result = CG_Validation_Result::block('blocked_domain', $block_message . ' (Ref: Domain)', 'domain_blocklist');
                        return self::finalize($result, $context, $cache_key);
                    }
                }
            }

            // Phone Blocklist
            if (!empty($context->phone)) {
                $is_phone_blocked = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}cg_blocked_numbers WHERE phone_number = %s AND (expires_at IS NULL OR expires_at > %s) LIMIT 1",
                    $context->phone, $now
                ));
                if ($is_phone_blocked) {
                    cg_log_checkout_attempt($context->ip, $context->email, $context->phone, $context->device_fp, $context->address_hash, 'blocked_phone', "Phone: {$context->phone}");
                    cg_maybe_auto_block($context->ip, $context->email, $context->phone);
                    $result = CG_Validation_Result::block('blocked_phone', $block_message . ' (Ref: Phone)', 'phone_blocklist');
                    return self::finalize($result, $context, $cache_key);
                }
            }

            // Device Fingerprint Blocklist
            if (!empty($options['fraud_device_blocking']) && !empty($context->device_fp)) {
                $is_device_blocked = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}cg_blocked_devices WHERE device_fingerprint = %s AND (expires_at IS NULL OR expires_at > %s) LIMIT 1",
                    $context->device_fp, $now
                ));
                if ($is_device_blocked) {
                    cg_log_checkout_attempt($context->ip, $context->email, $context->phone, $context->device_fp, $context->address_hash, 'blocked_device', "Device: {$context->device_fp}");
                    cg_maybe_auto_block($context->ip, $context->email, $context->phone);
                    $result = CG_Validation_Result::block('blocked_device', $block_message . ' (Ref: Device)', 'device_blocklist');
                    return self::finalize($result, $context, $cache_key);
                }
            }

            // Address Hash Blocklist
            if (!empty($context->address_hash)) {
                $is_address_blocked = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}cg_blocked_addresses WHERE address_hash = %s AND (expires_at IS NULL OR expires_at > %s) LIMIT 1",
                    $context->address_hash, $now
                ));
                if ($is_address_blocked) {
                    cg_log_checkout_attempt($context->ip, $context->email, $context->phone, $context->device_fp, $context->address_hash, 'blocked_address', "Address: {$context->address_hash}");
                    cg_maybe_auto_block($context->ip, $context->email, $context->phone);
                    $result = CG_Validation_Result::block('blocked_address', $block_message . ' (Ref: Address)', 'address_blocklist');
                    return self::finalize($result, $context, $cache_key);
                }
            }

            // Fraud Risk Score
            if (!empty($options['fraud_risk_scoring_enabled'])) {
                $risk = cg_calculate_fraud_risk_score($context->ip, $context->email, $context->phone, $context->device_fp, $context->address_hash);
                $threshold = (int) ($options['fraud_risk_score_threshold'] ?? 70);
                if ($risk['score'] >= $threshold) {
                    $reason = "Score: {$risk['score']}/100 - " . implode(', ', $risk['factors']);
                    cg_log_checkout_attempt($context->ip, $context->email, $context->phone, $context->device_fp, $context->address_hash, 'blocked_score', $reason);
                    cg_maybe_auto_block($context->ip, $context->email, $context->phone);
                    $result = CG_Validation_Result::block('blocked_score', $block_message, 'fraud_risk_score', ['risk_score' => $risk['score']]);
                    return self::finalize($result, $context, $cache_key);
                }
            }
        }

        // ─── CHECK 3: COURIER SUCCESS RATIO BLOCKER ───
        $courier_enabled = !empty($options['enable_courier_service']);
        if ($courier_enabled && !empty($context->phone)) {
            $threshold = isset($options['ratio_blocker_threshold']) ? (int) $options['ratio_blocker_threshold'] : 20;
            $grace_period = isset($options['ratio_blocker_grace_period']) ? (int) $options['ratio_blocker_grace_period'] : 5;

            // Short HQ timeout for this call only; the admin check keeps 30 s.
            $prev_timeout = $GLOBALS['cg_hq_timeout_override'] ?? null;
            $GLOBALS['cg_hq_timeout_override'] = (int) CG_CHECKOUT_COURIER_TIMEOUT;
            try {
                $ratio_data = cg_perform_all_api_calls($context->phone, 0);
            } finally {
                if ($prev_timeout === null) {
                    unset($GLOBALS['cg_hq_timeout_override']);
                } else {
                    $GLOBALS['cg_hq_timeout_override'] = $prev_timeout;
                }
            }
            // A failed courier check (no key, HQ down, 429, timeout) comes back
            // as cache_source "error" with a zeroed summary. That 0% is not
            // the customer's ratio, so it never blocks or holds an order,
            // whatever the grace period (orders always go through). A
            // customer with no deliveries at all has no ratio either.
            $ratio_failed = is_array($ratio_data) && ($ratio_data['cache_source'] ?? '') === 'error';
            if (!$ratio_failed && !is_wp_error($ratio_data) && is_array($ratio_data) && isset($ratio_data['summary'])) {
                $total_orders = (int) ($ratio_data['summary']['total_deliveries'] ?? 0);
                $success_rate = (int) ($ratio_data['summary']['success_rate'] ?? 100);

                if ($total_orders > 0 && $total_orders >= $grace_period && $success_rate <= $threshold) {
                    // Paid plans with ratio_action "hold" (the default for paid):
                    // let the checkout through, the order goes on-hold later.
                    // Logged as allowed, so a hold never feeds the fraud score.
                    if (cg_ratio_action() === 'hold') {
                        cg_log_checkout_attempt($context->ip, $context->email, $context->phone, $context->device_fp, $context->address_hash, 'allowed', "Source: {$context->order_source}; ratio hold {$success_rate}% <= {$threshold}%");
                        $result = CG_Validation_Result::hold('held_ratio', 'courier_success_ratio', [
                            'success_rate' => $success_rate,
                            'threshold'    => $threshold,
                            'source'       => $context->order_source,
                        ]);
                        return self::finalize($result, $context, $cache_key);
                    }

                    global $wpdb;
                    $table_name = $wpdb->prefix . 'cg_blocked_orders';

                    $cart_details_array = [];
                    $cart_value = $context->total_amount;
                    if ($context->order instanceof WC_Order) {
                        foreach ($context->order->get_items() as $item) {
                            $cart_details_array[] = ['name' => $item->get_name(), 'quantity' => (int) $item->get_quantity()];
                        }
                        $cart_value = (float) $context->order->get_total();
                    } elseif (function_exists('WC') && WC()->cart && !WC()->cart->is_empty()) {
                        foreach (WC()->cart->get_cart() as $cart_item) {
                            if (!empty($cart_item['data'])) {
                                $cart_details_array[] = ['name' => $cart_item['data']->get_name(), 'quantity' => (int) $cart_item['quantity']];
                            }
                        }
                        $cart_value = (float) WC()->cart->get_total('edit');
                    }

                    $wpdb->insert($table_name, [
                        'blocked_at'         => current_time('mysql'),
                        'first_name'         => $context->first_name,
                        'last_name'          => $context->last_name,
                        'phone_number'       => $context->phone,
                        'email_address'      => $context->email,
                        'cart_details'       => wp_json_encode($cart_details_array),
                        'cart_value'         => $cart_value,
                        'success_ratio'      => $success_rate,
                        'threshold_at_block' => $threshold,
                        'ip_address'         => $context->ip
                    ]);

                    $default_ratio_msg = 'Your courier success ratio is {ratio}%, which is too low. Please contact us to complete your order.';
                    // An empty saved message (the settings form stores '' when the
                    // field is not on the submitted tab) must fall back to the default.
                    $custom_ratio_msg = !empty($options['ratio_blocker_message']) ? $options['ratio_blocker_message'] : $default_ratio_msg;
                    $final_ratio_msg = str_replace('{ratio}', $success_rate, $custom_ratio_msg);

                    $result = CG_Validation_Result::block('blocked_ratio', $final_ratio_msg, 'courier_success_ratio', [
                        'success_rate' => $success_rate,
                        'threshold'    => $threshold
                    ]);
                    return self::finalize($result, $context, $cache_key);
                }
            }
        }

        // ─── ALL CHECKS PASSED: Allowed ───
        cg_log_checkout_attempt($context->ip, $context->email, $context->phone, $context->device_fp, $context->address_hash, 'allowed', "Source: {$context->order_source}");
        $result = CG_Validation_Result::allow(['source' => $context->order_source]);

        return self::finalize($result, $context, $cache_key);
    }

    private static function finalize(CG_Validation_Result $result, CG_Validation_Context $context, $cache_key)
    {
        $result = apply_filters('checkoutguard_validation_result', $result, $context);
        do_action('checkoutguard_after_validation', $context, $result);
        self::$evaluated_contexts[$cache_key] = $result;
        return $result;
    }
}

/**
 * Backward-compatible validation function. Returns block message string or null.
 */
function cg_run_checkout_security_validation($customer_data, $order = null, $source = 'unknown')
{
    if ($order instanceof WC_Order) {
        $context = cg_build_validation_context_from_order($order, $source, 'classic');
    } elseif ($customer_data instanceof CG_Validation_Context) {
        $context = $customer_data;
    } else {
        $context = new CG_Validation_Context([
            'phone'        => $customer_data['phone'] ?? '',
            'email'        => $customer_data['email'] ?? '',
            'first_name'   => $customer_data['first_name'] ?? '',
            'last_name'    => $customer_data['last_name'] ?? '',
            'address_1'    => $customer_data['address_1'] ?? '',
            'city'         => $customer_data['city'] ?? '',
            'postcode'     => $customer_data['postcode'] ?? '',
            'address_hash' => $customer_data['address_hash'] ?? '',
            'ip'           => $customer_data['ip'] ?? cg_get_client_ip(),
            'device_fp'    => $customer_data['device_fp'] ?? cg_resolve_device_fingerprint(),
            'order_source' => $source,
            'order'        => $order,
        ]);
    }

    $result = CG_Validation_Engine::validate($context);
    return $result->is_blocked() ? $result->block_message : null;
}

// ====================================================================
// UNIVERSAL CHECKOUT HOOKS & ADAPTER INTEGRATIONS
// ====================================================================

/**
 * 1. Payload-Based Early AJAX Interceptor (Landing Pages / 1-Click Checkout / Custom AJAX)
 * Hooks at init (priority 0) and inspects order payload signatures to intercept and block
 * before any custom order creation handler can run.
 */
function cg_universal_ajax_order_interceptor()
{
    if (!wp_doing_ajax()) {
        return;
    }

    $action = isset($_REQUEST['action']) ? sanitize_key($_REQUEST['action']) : '';
    // Skip CheckoutGuard's internal AJAX actions
    if (strpos($action, 'cg_') === 0) {
        return;
    }

    $has_order_payload = false;
    $parsed_payload = [];

    // Signature 1: JSON order_data parameter (e.g. co_create_order from landing page plugins)
    if (!empty($_POST['order_data'])) {
        $decoded = json_decode(stripslashes($_POST['order_data']), true);
        if (is_array($decoded) && (!empty($decoded['products']) || !empty($decoded['billing_phone']) || !empty($decoded['billing_name']))) {
            $has_order_payload = true;
            $parsed_payload = $decoded;
        }
    }

    // Signature 2: Direct POST fields with checkout signatures
    if (!$has_order_payload) {
        $has_phone   = !empty($_POST['billing_phone']) || !empty($_POST['phone']) || !empty($_POST['mobile']);
        $has_address = !empty($_POST['billing_address_1']) || !empty($_POST['billing_address']) || !empty($_POST['address']);
        $has_product = !empty($_POST['product_id']) || !empty($_POST['products']) || !empty($_POST['cart_items']) || !empty($_POST['delivery_charge']);

        if ($has_phone && ($has_address || $has_product)) {
            $has_order_payload = true;
            $parsed_payload = $_POST;
        }
    }

    // Signature 3: Raw JSON payload in php://input
    if (!$has_order_payload) {
        $raw = file_get_contents('php://input');
        if (!empty($raw) && (strpos($raw, 'billing') !== false || strpos($raw, 'product') !== false)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && (!empty($decoded['billing_phone']) || !empty($decoded['billing_address']) || !empty($decoded['products']))) {
                $has_order_payload = true;
                $parsed_payload = $decoded;
            }
        }
    }

    if (!$has_order_payload) {
        return;
    }

    $context = cg_build_validation_context_from_payload($parsed_payload, !empty($action) ? "ajax_{$action}" : 'landing_page_ajax', 'ajax');
    $result = CG_Validation_Engine::validate($context);

    if ($result->is_blocked()) {
        CG_Response_Adapter::ajax($result);
    }
}
add_action('init', 'cg_universal_ajax_order_interceptor', 0);

/**
 * True while serving the Store API checkout route (/wc/store/v1/checkout or
 * a later version), where cg_store_api_checkout_guard() does the validation.
 */
function cg_is_store_api_checkout_request()
{
    if (!defined('REST_REQUEST') || !REST_REQUEST || empty($GLOBALS['wp']) || !is_object($GLOBALS['wp'])) {
        return false;
    }
    $route = isset($GLOBALS['wp']->query_vars['rest_route']) ? (string) $GLOBALS['wp']->query_vars['rest_route'] : '';
    return (bool) preg_match('#^/wc/store(/v\d+)?/checkout/?$#', untrailingslashit($route) . '/');
}

/**
 * 2. Safe Core Order Lifecycle Guard (woocommerce_before_order_object_save)
 * Ultimate fail-safe for any direct wc_create_order() calls. Non-destructive: cancels order
 * and logs audit note instead of throwing raw database exceptions.
 */
function cg_universal_order_save_guard($order, $data_store)
{
    // Safety check 1: Never intercept admin users manually managing orders in wp-admin or performing administrative actions
    if (is_admin() && (current_user_can('manage_woocommerce') || !wp_doing_ajax())) {
        return;
    }

    // Safety check 2: Only intercept active target statuses
    $status = $order->get_status();
    if (!in_array($status, ['processing', 'pending', 'on-hold', 'completed'], true)) {
        return;
    }

    // Safety check 2b: a Store API checkout is validated once by
    // cg_store_api_checkout_guard(); validating the draft here as well ran
    // every check (and the courier ratio check, with its blocked_orders row)
    // twice per block checkout.
    if (cg_is_store_api_checkout_request()) {
        return;
    }

    // Safety check 3: Avoid re-validating if already evaluated, blocked, manual invoice, or admin recovered
    if (
        $order->get_meta('_cg_validated', true) === '1' ||
        $order->get_meta('_cg_blocked', true) === '1' ||
        $order->get_meta('_cg_manual_invoice', true) ||
        $order->get_meta('_cg_recovered_from', true)
    ) {
        return;
    }

    $source = wp_doing_ajax() ? 'ajax_lifecycle' : (defined('REST_REQUEST') ? 'rest_lifecycle' : 'direct_lifecycle');
    $context = cg_build_validation_context_from_order($order, $source, 'lifecycle');

    // Ensure device fingerprint and client IP are preserved on the order for admin review
    if (!empty($context->device_fp) && !$order->get_meta('_cg_device_fingerprint', true)) {
        $order->update_meta_data('_cg_device_fingerprint', $context->device_fp);
    }
    if (!empty($context->ip) && !$order->get_customer_ip_address()) {
        $order->set_customer_ip_address($context->ip);
    }

    $result = CG_Validation_Engine::validate($context);

    if ($result->is_blocked()) {
        CG_Response_Adapter::lifecycle($result, $order);
    } else {
        $order->update_meta_data('_cg_validated', '1');
        if (cg_mark_ratio_hold($order, $result) && in_array($status, ['processing', 'completed'], true)) {
            // Created directly as paid/processing (no checkout payment step).
            // The note and the blocked_orders row come from the status hook after the save.
            $order->set_status('on-hold');
        }
    }
}
add_action('woocommerce_before_order_object_save', 'cg_universal_order_save_guard', 10, 2);

/**
 * Close a Store API checkout attempt that CheckoutGuard blocked.
 *
 * Without this the draft stays pending and shows up as a live order for the
 * same customer. The cancel goes through CG_Response_Adapter::lifecycle(), so
 * the order gets the same status, audit note (block code and time) and
 * _cg_blocked / _cg_block_code meta as any other order CheckoutGuard blocks.
 * Cancelled is outside the duplicate lookup statuses and the save guard skips
 * it. Only the attempt itself is touched, and only while it is still a draft
 * or pending; no other order is looked up or changed.
 *
 * WooCommerce sends the admin "Order cancelled" email on pending -> cancelled;
 * a blocked attempt was never a real order, so the order is marked and
 * cg_mute_closed_attempt_cancelled_email() keeps that email off. The customer
 * cancelled email only fires from processing or on-hold, so it never fires
 * here. Stock and coupon usage are not changed: the block throws before
 * WooCommerce holds coupons or reserves stock for this request, and
 * WooCommerce only gives back stock or coupon usage that an order recorded.
 *
 * @param WC_Order             $order  The blocked attempt.
 * @param CG_Validation_Result $result The block result.
 */
function cg_close_blocked_store_api_attempt($order, $result)
{
    if (!($order instanceof WC_Order) || !$order->has_status(['checkout-draft', 'pending'])) {
        return;
    }
    CG_Response_Adapter::lifecycle($result, $order);
    $order->update_meta_data('_cg_attempt_closed', '1');
    $order->save();
}

/**
 * Keep the admin "Order cancelled" email off for a closed Store API attempt.
 *
 * The decision comes from the order meta, not from request timing, so it also
 * holds when WooCommerce defers transactional emails to shutdown or to a later
 * Action Scheduler run. Orders cancelled by any other path are not affected.
 *
 * @param bool          $enabled     Whether the email is enabled.
 * @param WC_Order|null $email_order The order the email is about, if any.
 * @return bool
 */
function cg_mute_closed_attempt_cancelled_email($enabled, $email_order = null)
{
    if ($email_order instanceof WC_Order && $email_order->get_meta('_cg_attempt_closed', true) === '1') {
        return false;
    }
    return $enabled;
}
add_filter('woocommerce_email_enabled_cancelled_order', 'cg_mute_closed_attempt_cancelled_email', 10, 2);

/**
 * 3. WooCommerce Store API Checkout Guard (Gutenberg Checkout Block / Twenty Twenty-Five Theme / FSE)
 */
function cg_store_api_checkout_guard($order, $request)
{
    $context = cg_build_validation_context_from_order($order, 'store_api', 'rest');

    if (!empty($context->device_fp)) {
        $order->update_meta_data('_cg_device_fingerprint', $context->device_fp);
    }
    if (!empty($context->ip)) {
        $order->set_customer_ip_address($context->ip);
    }

    $result = CG_Validation_Engine::validate($context);
    if ($result->is_blocked()) {
        // Mark the attempt so the duplicate lookup never counts it as an earlier
        // order, whatever its status (WooCommerce keeps the draft after the
        // exception below). A draft or pending attempt is also cancelled.
        $order->update_meta_data('_cg_blocked', '1');
        $order->update_meta_data('_cg_block_code', $result->block_code);
        cg_close_blocked_store_api_attempt($order, $result);
        CG_Response_Adapter::store_api($result);
    } else {
        $order->update_meta_data('_cg_validated', '1');
        cg_mark_ratio_hold($order, $result);
    }
}
add_action('woocommerce_store_api_checkout_update_order_from_request', 'cg_store_api_checkout_guard', 10, 2);

/**
 * 4. Universal Order Creation Guard (CartFlows / Quick Checkout / Classic order creation)
 */
function cg_order_creation_guard($order, $data)
{
    $context = cg_build_validation_context_from_order($order, 'cartflows', 'classic');

    if (!empty($context->device_fp)) {
        $order->update_meta_data('_cg_device_fingerprint', $context->device_fp);
    }
    if (!empty($context->ip)) {
        $order->set_customer_ip_address($context->ip);
    }

    $result = CG_Validation_Engine::validate($context);
    if ($result->is_blocked()) {
        throw new \Exception(wp_strip_all_tags($result->block_message));
    } else {
        $order->update_meta_data('_cg_validated', '1');
        cg_mark_ratio_hold($order, $result);
    }
}
add_action('woocommerce_checkout_create_order', 'cg_order_creation_guard', 10, 2);

/**
 * 5. Form Validation Guard (Classic & CartFlows form validation)
 */
function cg_after_checkout_validation_guard($data, $errors)
{
    if ($errors->get_error_messages()) {
        return;
    }

    $customer_data = cg_extract_checkout_customer_data(null, $data);
    $context = new CG_Validation_Context(array_merge($customer_data, [
        'order_source' => 'classic_checkout',
        'request_type' => 'classic',
    ]));

    $result = CG_Validation_Engine::validate($context);
    if ($result->is_blocked()) {
        CG_Response_Adapter::classic($result, $errors);
    }
}
add_action('woocommerce_after_checkout_validation', 'cg_after_checkout_validation_guard', 10, 2);

/**
 * 6. Classic Checkout Process Guard
 */
function cg_checkout_process_guard()
{
    $customer_data = cg_extract_checkout_customer_data();
    $context = new CG_Validation_Context(array_merge($customer_data, [
        'order_source' => 'classic_checkout',
        'request_type' => 'classic',
    ]));

    $result = CG_Validation_Engine::validate($context);
    if ($result->is_blocked()) {
        CG_Response_Adapter::classic($result);
    }
}
add_action('woocommerce_checkout_process', 'cg_checkout_process_guard', 18);

// Backward compatibility alias functions
function cg_check_customer_against_blocklists() {}
function cg_check_duplicate_order() {}
function cg_check_device_site_block()
{
    if (is_admin()) {
        return;
    }
    $options = get_option('cg_plugin_options', []);
    if (empty($options['fraud_device_site_block'])) {
        return;
    }
    $device_fp = isset($_COOKIE['cg_device_fp']) ? sanitize_text_field(wp_unslash($_COOKIE['cg_device_fp'])) : '';
    if (empty($device_fp)) {
        return;
    }
    global $wpdb;
    $now = current_time('mysql');
    $is_blocked = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}cg_blocked_devices WHERE device_fingerprint = %s AND (expires_at IS NULL OR expires_at > %s) LIMIT 1",
        $device_fp, $now
    ));
    if ($is_blocked) {
        $custom_message = !empty($options['custom_block_message'])
            ? wp_kses_post($options['custom_block_message'])
            : __('Access denied. Your device has been blocked from this store. Contact support if you believe this is an error.', 'checkoutguard');

        wp_die(
            '<div style="text-align:center;padding:60px 20px;font-family:-apple-system,BlinkMacSystemFont,sans-serif;">'
            . '<h1 style="color:#c62828;font-size:28px;margin-bottom:16px;">' . __('Access Denied', 'checkoutguard') . '</h1>'
            . '<p style="font-size:16px;color:#555;max-width:500px;margin:0 auto;">' . $custom_message . '</p>'
            . '</div>',
            __('Access Denied', 'checkoutguard'),
            ['response' => 403]
        );
    }
}
add_action('template_redirect', 'cg_check_device_site_block', 1);


