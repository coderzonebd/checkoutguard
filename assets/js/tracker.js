
jQuery(function ($) {
    'use strict';

    // Ensure our localized parameters object exists before running any logic.
    if (typeof cg_checkout_params === 'undefined') {
        return;
    }

    // ====================================================================
    // ENHANCED DEVICE FINGERPRINTING (DUAL-TIER STORAGE)
    // ====================================================================

    /**
     * Retrieves stored fingerprint with cross-storage synchronization (Cookie <-> LocalStorage).
     */
    function getStoredFingerprint() {
        var fromLocal = '';
        try {
            fromLocal = localStorage.getItem('cg_device_id') || '';
        } catch (e) { /* ignore */ }

        var fromCookie = '';
        var match = document.cookie.match(/(?:^|;\s*)cg_device_fp=([^;]+)/);
        if (match) {
            fromCookie = match[1];
        }

        if (fromLocal && fromCookie) {
            return fromLocal;
        }
        if (fromLocal && !fromCookie) {
            document.cookie = 'cg_device_fp=' + fromLocal + ';path=/;max-age=' + (365 * 86400) + ';SameSite=Lax';
            return fromLocal;
        }
        if (!fromLocal && fromCookie) {
            try { localStorage.setItem('cg_device_id', fromCookie); } catch (e) {}
            return fromCookie;
        }
        return '';
    }

    /**
     * Generates a resilient device fingerprint from multiple hardware signals.
     */
    function generateDeviceFingerprint() {
        var existing = getStoredFingerprint();
        if (existing && existing.length >= 10) {
            return existing;
        }

        var components = [
            navigator.userAgent || '',
            navigator.language || '',
            screen.width + 'x' + screen.height,
            screen.colorDepth || '',
            (window.devicePixelRatio || 1).toString(),
            (navigator.deviceMemory || '').toString(),
            (navigator.hardwareConcurrency || '').toString(),
            navigator.platform || '',
            new Date().getTimezoneOffset().toString()
        ];

        // Timezone IANA name if available
        try {
            if (Intl && Intl.DateTimeFormat) {
                components.push(Intl.DateTimeFormat().resolvedOptions().timeZone || '');
            }
        } catch (e) { /* ignore */ }

        // WebGL vendor & renderer
        try {
            var canvas = document.createElement('canvas');
            var gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');
            if (gl) {
                var debugInfo = gl.getExtension('WEBGL_debug_renderer_info');
                if (debugInfo) {
                    components.push(gl.getParameter(debugInfo.UNMASKED_VENDOR_WEBGL) || '');
                    components.push(gl.getParameter(debugInfo.UNMASKED_RENDERER_WEBGL) || '');
                }
            }
        } catch (e) { /* ignore */ }

        // Canvas fingerprint
        try {
            var c = document.createElement('canvas');
            c.width = 200;
            c.height = 50;
            var ctx = c.getContext('2d');
            ctx.textBaseline = 'top';
            ctx.font = '14px Arial';
            ctx.fillStyle = '#f60';
            ctx.fillRect(0, 0, 100, 30);
            ctx.fillStyle = '#069';
            ctx.fillText('CG_FP_2026', 2, 15);
            ctx.fillStyle = 'rgba(102, 204, 0, 0.7)';
            ctx.fillText('CG_FP_2026', 4, 17);
            components.push(c.toDataURL());
        } catch (e) { /* ignore */ }

        // Create combined hash
        var str = components.join('|||');
        var hash1 = 0, hash2 = 0;
        for (var i = 0; i < str.length; i++) {
            var char = str.charCodeAt(i);
            hash1 = ((hash1 << 5) - hash1) + char;
            hash1 = hash1 & hash1;
            hash2 = ((hash2 << 7) - hash2) + char;
            hash2 = hash2 & hash2;
        }

        var fp = Math.abs(hash1).toString(36) + Math.abs(hash2).toString(36);

        // Persist to both Cookie and LocalStorage
        document.cookie = 'cg_device_fp=' + fp + ';path=/;max-age=' + (365 * 86400) + ';SameSite=Lax';
        try { localStorage.setItem('cg_device_id', fp); } catch (e) {}

        return fp;
    }

    // Generate and persist the device fingerprint
    var deviceFingerprint = generateDeviceFingerprint();


    // ====================================================================
    // SESSION TRACKING LOGIC (COMBINED)
    // ====================================================================

    let debounceTimeout;
    const debounceDelay = 800;

    function getFieldValue(selectors) {
        for (var i = 0; i < selectors.length; i++) {
            var $el = $(selectors[i]);
            if ($el.length) {
                var val = $el.val();
                if (val && typeof val === 'string' && val.trim().length > 0) {
                    return val.trim();
                }
            }
        }
        return '';
    }

    function collectAndSendData() {
        // Universal Name Detection (Classic WC, Gutenberg Twenty Twenty-Five Block, Landing Pages)
        var firstName = getFieldValue([
            '#billing_first_name', '#billing-first_name', 'input[name="billing_first_name"]',
            '#shipping-first_name', '#shipping_first_name', 'input[name="shipping_first_name"]',
            'input[name="billing_name"]', 'input[name="customer_name"]', 'input[name="name"]',
            '#name', '#customer_name', 'input[autocomplete="given-name"]', 'input[autocomplete="name"]'
        ]);

        var lastName = getFieldValue([
            '#billing_last_name', '#billing-last_name', 'input[name="billing_last_name"]',
            '#shipping-last_name', '#shipping_last_name', 'input[name="shipping_last_name"]',
            'input[name="billing_last_name"]', 'input[autocomplete="family-name"]'
        ]);

        // Universal Phone Detection (Classic WC, Gutenberg Twenty Twenty-Five Block, Landing Pages)
        var phone = getFieldValue([
            '#billing_phone', '#billing-phone', 'input[name="billing_phone"]',
            '#shipping-phone', '#shipping_phone', 'input[name="shipping_phone"]',
            'input[name="phone"]', 'input[name="mobile"]', 'input[name="customer_phone"]',
            '#phone', '#mobile', 'input[type="tel"]', 'input[autocomplete="tel"]',
            'input[name*="phone"]', 'input[name*="mobile"]'
        ]);

        // Universal Email Detection (Classic WC, Gutenberg Twenty Twenty-Five Block, Landing Pages)
        var email = getFieldValue([
            '#billing_email', '#billing-email', 'input[name="billing_email"]',
            '#email', 'input[name="email"]', 'input[name="customer_email"]',
            'input[type="email"]', 'input[autocomplete="email"]', 'input[name*="email"]'
        ]);

        // Universal Address Detection (Classic WC, Gutenberg Twenty Twenty-Five Block, Landing Pages)
        var address1 = getFieldValue([
            '#billing_address_1', '#billing-address_1', 'input[name="billing_address_1"]',
            '#shipping-address_1', '#shipping_address_1', 'input[name="shipping_address_1"]',
            'input[name="billing_address"]', 'input[name="address"]', 'input[name="customer_address"]',
            'textarea[name="address"]', 'textarea[name="billing_address"]', 'textarea[name="customer_address"]',
            '#address', 'input[autocomplete="address-line1"]', 'textarea[name*="address"]'
        ]);

        var city = getFieldValue([
            '#billing_city', '#billing-city', 'input[name="billing_city"]',
            '#shipping-city', '#shipping_city', 'input[name="shipping_city"]',
            'select[name="city"]', 'input[name="city"]'
        ]);

        // Landing Page Product & Quantity Detection
        var productId = getFieldValue([
            'input[name="product_id"]', 'input[name="add-to-cart"]',
            'input[name="radio_product"]:checked', 'input[name="product_choice"]:checked',
            'input[name="selected_product"]:checked', 'select[name="product_id"]'
        ]);
        var productQty = getFieldValue([
            'input[name="quantity"]', 'input[name="product_qty"]', 'select[name="quantity"]'
        ]) || '1';

        var checkoutData = {
            action: 'cg_save_checkout_data',
            nonce: cg_checkout_params.save_data_nonce,
            billing_first_name: firstName,
            billing_last_name: lastName,
            billing_company: getFieldValue(['#billing_company', '#billing-company', 'input[name="billing_company"]']),
            billing_country: getFieldValue(['#billing_country', '#billing-country', 'select[name="billing_country"]']),
            billing_address_1: address1,
            billing_address_2: getFieldValue(['#billing_address_2', '#billing-address_2', 'input[name="billing_address_2"]']),
            billing_city: city,
            billing_state: getFieldValue(['#billing_state', '#billing-state', 'select[name="billing_state"]', 'input[name="billing_state"]']),
            billing_postcode: getFieldValue(['#billing_postcode', '#billing-postcode', 'input[name="billing_postcode"]']),
            billing_phone: phone,
            billing_email: email,
            order_comments: getFieldValue(['#order_comments', 'textarea[name="order_comments"]', 'textarea[name="comments"]', 'textarea[name="note"]']),
            device_fingerprint: deviceFingerprint,
            product_id: productId,
            quantity: productQty
        };

        if (checkoutData.billing_email || checkoutData.billing_phone || checkoutData.billing_first_name || checkoutData.billing_address_1) {
            $.post(cg_checkout_params.ajax_url, checkoutData);
        }
    }

    function debouncedCollectAndSendData() {
        clearTimeout(debounceTimeout);
        debounceTimeout = setTimeout(collectAndSendData, debounceDelay);
    }

    var fieldSelectors = [
        '#billing_first_name', '#billing-first_name', '#shipping-first_name', '#shipping_first_name', 'input[name="billing_first_name"]', 'input[name="shipping_first_name"]', 'input[name="name"]', 'input[name="billing_name"]', 'input[name="customer_name"]', 'input[name*="name"]',
        '#billing_last_name', '#billing-last_name', '#shipping-last_name', '#shipping_last_name', 'input[name="billing_last_name"]', 'input[name="shipping_last_name"]',
        '#billing_address_1', '#billing-address_1', '#shipping-address_1', '#shipping_address_1', 'input[name="billing_address_1"]', 'input[name="shipping_address_1"]', 'input[name="address"]', 'input[name="billing_address"]', 'input[name="customer_address"]', 'textarea[name="address"]', 'textarea[name*="address"]',
        '#billing_city', '#billing-city', '#shipping-city', '#shipping_city', 'input[name="billing_city"]',
        '#billing_phone', '#billing-phone', '#shipping-phone', '#shipping_phone', 'input[name="billing_phone"]', 'input[name="shipping_phone"]', 'input[type="tel"]', 'input[name="phone"]', 'input[name="mobile"]', 'input[name="customer_phone"]', 'input[name*="phone"]', 'input[name*="mobile"]',
        '#billing_email', '#billing-email', '#email', 'input[name="billing_email"]', 'input[type="email"]', 'input[name="email"]', 'input[name="customer_email"]',
        '#order_comments', 'textarea[name="order_comments"]', 'textarea[name="comments"]', 'textarea[name="note"]',
        '.wc-block-components-text-input input', '.wc-block-checkout input', '.co-field', 'input[name="radio_product"]', 'select[name="product_id"]'
    ].join(',');

    $(document.body).on('input change blur', fieldSelectors, debouncedCollectAndSendData);
    $(document.body).on('update_checkout updated_checkout', function () { setTimeout(debouncedCollectAndSendData, 150); });

    setTimeout(collectAndSendData, 1500);

    // Inject device fingerprint as a hidden field for WooCommerce checkout & landing page forms
    var formSelectors = 'form.checkout, form.woocommerce-checkout, form.wcf-embed-checkout-form, .wc-block-checkout, form.co-form, #coCheckout, .co-landing-form, form[id*="co_order"], form[action*="admin-ajax.php"]';

    function ensureDeviceFingerprintInput() {
        if (!deviceFingerprint) return;
        $(formSelectors).each(function () {
            var $form = $(this);
            var $input = $form.find('input[name="cg_device_fingerprint"]');
            if (!$input.length) {
                $form.append('<input type="hidden" name="cg_device_fingerprint" value="' + deviceFingerprint + '">');
            } else if ($input.val() !== deviceFingerprint) {
                $input.val(deviceFingerprint);
            }
        });
    }

    ensureDeviceFingerprintInput();
    $(document.body).on('updated_checkout checkout_place_order submit', ensureDeviceFingerprintInput);
    $(document).on('submit', formSelectors, ensureDeviceFingerprintInput);

    // Attach X-CG-Device-FP header to jQuery AJAX (Classic & CartFlows)
    if ($.ajaxPrefilter) {
        $.ajaxPrefilter(function (options, originalOptions, jqXHR) {
            var fp = getStoredFingerprint() || deviceFingerprint;
            if (fp) {
                jqXHR.setRequestHeader('X-CG-Device-FP', fp);
            }
        });
    }

    // Attach X-CG-Device-FP header to XMLHttpRequest (vanilla AJAX)
    if (window.XMLHttpRequest && XMLHttpRequest.prototype.open && XMLHttpRequest.prototype.send) {
        var _origXHROpen = XMLHttpRequest.prototype.open;
        var _origXHRSend = XMLHttpRequest.prototype.send;
        XMLHttpRequest.prototype.open = function () {
            this._cg_req_url = arguments[1] || '';
            return _origXHROpen.apply(this, arguments);
        };
        XMLHttpRequest.prototype.send = function () {
            var fp = getStoredFingerprint() || deviceFingerprint;
            if (fp && typeof this.setRequestHeader === 'function') {
                var url = this._cg_req_url || '';
                var isSameOrigin = !url || url.indexOf('http') !== 0 || url.indexOf(window.location.hostname) !== -1;
                if (isSameOrigin || url.indexOf('admin-ajax.php') !== -1 || url.indexOf('wc/store') !== -1) {
                    try {
                        this.setRequestHeader('X-CG-Device-FP', fp);
                    } catch (e) { /* ignore */ }
                }
            }
            return _origXHRSend.apply(this, arguments);
        };
    }

    // Attach X-CG-Device-FP header to wp.apiFetch (WooCommerce Gutenberg Checkout Block / Twenty Twenty-Five)
    if (window.wp && window.wp.apiFetch && typeof window.wp.apiFetch.use === 'function') {
        window.wp.apiFetch.use(function (options, next) {
            var fp = getStoredFingerprint() || deviceFingerprint;
            if (fp) {
                options.headers = options.headers || {};
                if (typeof options.headers.set === 'function') {
                    options.headers.set('X-CG-Device-FP', fp);
                } else {
                    options.headers['X-CG-Device-FP'] = fp;
                }
            }
            return next(options);
        });
    }

    // Wrap window.fetch for Store API, admin-ajax, and landing page checkout calls
    if (window.fetch) {
        var _originalFetch = window.fetch;
        window.fetch = function (resource, init) {
            init = init || {};
            var urlStr = (typeof resource === 'string') ? resource : (resource && resource.url ? resource.url : '');
            var isSameOrigin = !urlStr || urlStr.indexOf('http') !== 0 || urlStr.indexOf(window.location.hostname) !== -1;
            if (isSameOrigin || urlStr.indexOf('admin-ajax.php') !== -1 || urlStr.indexOf('wc/store') !== -1 || urlStr.indexOf('wc-ajax') !== -1) {
                var fp = getStoredFingerprint() || deviceFingerprint;
                if (fp) {
                    init.headers = init.headers || {};
                    if (init.headers instanceof Headers) {
                        init.headers.set('X-CG-Device-FP', fp);
                    } else if (typeof init.headers === 'object') {
                        init.headers['X-CG-Device-FP'] = fp;
                    }
                }
            }
            return _originalFetch.call(this, resource, init);
        };
    }

    // ====================================================================
    // DUPLICATE SUBMISSION PREVENTION (Client-Side)
    // ====================================================================
    var isSubmittingCheckout = false;
    $(document.body).on('checkout_place_order', function () {
        if (isSubmittingCheckout) {
            return false;
        }
        isSubmittingCheckout = true;
        setTimeout(function () {
            isSubmittingCheckout = false;
        }, 3000);
        return true;
    });

    $(document).on('click', '.wc-block-components-checkout-place-order-button, #place_order, .wcf-btn-checkout, #coSubBtn, .co-submit-btn, button[name="co_submit"], #coCheckout button[type="submit"]', function (e) {
        if (isSubmittingCheckout) {
            e.preventDefault();
            e.stopPropagation();
            return false;
        }
        isSubmittingCheckout = true;
        setTimeout(function () {
            isSubmittingCheckout = false;
        }, 3000);
    });

    $(document.body).on('checkout_error updated_checkout', function () {
        isSubmittingCheckout = false;
    });


    // ====================================================================
    // LIVE RATIO CHECK LOGIC (UNIVERSAL COMPATIBILITY)
    // ====================================================================

    let ratioDebounce;
    var phoneInputSelectors = [
        '#billing_phone', '#billing-phone', '#shipping-phone', '#shipping_phone',
        'input[name="billing_phone"]', 'input[name="shipping_phone"]',
        'input[name="phone"]', 'input[name="mobile"]', 'input[name="customer_phone"]',
        '#phone', '#mobile', 'input[type="tel"]', '.wc-block-components-text-input input[type="tel"]'
    ].join(',');

    function getOrCreateRatioFeedback($input) {
        var $existing = $input.siblings('.cg-ratio-feedback');
        if ($existing.length) {
            return $existing;
        }

        var $parent = $input.closest('p.form-row, .wc-block-components-text-input, .form-group, .co-field-wrap');
        if ($parent.length && $parent.next('.cg-ratio-feedback').length) {
            return $parent.next('.cg-ratio-feedback');
        }

        var $div = $('<div class="cg-ratio-feedback" style="display:none;" aria-live="polite" aria-atomic="true"></div>');
        if ($parent.length) {
            $parent.after($div);
        } else {
            $input.after($div);
        }
        return $div;
    }

    $(document).on('input blur', phoneInputSelectors, function () {
        var $input = $(this);
        var rawVal = $input.val();
        if (!rawVal || typeof rawVal !== 'string') {
            return;
        }

        var phoneNumber = rawVal.replace(/[^0-9+]/g, '');
        var $feedback = getOrCreateRatioFeedback($input);

        clearTimeout(ratioDebounce);
        if (phoneNumber.length < 10) {
            $feedback.html('').hide();
            return;
        }

        $feedback.html('<i>Checking delivery success ratio...</i>').show();
        ratioDebounce = setTimeout(function () {
            $.post(cg_checkout_params.ajax_url, {
                action: 'cg_live_ratio_check',
                nonce: cg_checkout_params.live_ratio_check_nonce,
                phone_number: phoneNumber
            }).done(function (response) {
                if (response.success && response.data && response.data.rate !== undefined) {
                    var rate = parseInt(response.data.rate, 10);
                    var total = response.data.total !== undefined ? parseInt(response.data.total, 10) : null;
                    if (total === 0) {
                        $feedback.html('<strong>New Customer (No prior delivery history)</strong>').show();
                    } else {
                        var badge = 'Delivery Success Ratio: ' + rate + '%';
                        if (total) {
                            badge += ' (' + total + ' orders)';
                        }
                        $feedback.html('<strong>' + badge + '</strong>').show();
                    }
                } else {
                    $feedback.html('').hide();
                }
            }).fail(function () {
                $feedback.html('').hide();
            });
        }, 600);
    });

});
