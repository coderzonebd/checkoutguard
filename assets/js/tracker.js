jQuery(function ($) {
    'use strict';

    // Check if params are loaded
    if (typeof checkoutguard_checkout_params === 'undefined') {
        return;
    }

    // --- ENHANCED SESSION TRACKING LOGIC ---
    let debounceTimeout;
    const debounceDelay = 300; // Reduced to 300ms for faster real-time sync
    let retryCount = 0;
    const maxRetries = 3;
    let lastSavedData = null;
    let saveInProgress = false;

    /**
     * Collects all checkout form data
     */
    function collectCheckoutData() {
        // Try multiple selectors for each field to handle different themes
        const getData = function(selectors) {
            for (let i = 0; i < selectors.length; i++) {
                const $field = $(selectors[i]);
                if ($field.length > 0) {
                    const val = $field.val();
                    if (val && val.length > 0) {
                        return val;
                    }
                }
            }
            return '';
        };
        
        const data = {
            action: 'checkoutguard_save_checkout_data',
            nonce: checkoutguard_checkout_params.save_data_nonce,
            // Try multiple selectors for each field - MORE COMPREHENSIVE
            billing_first_name: getData([
                '#billing_first_name', 
                'input[name="billing_first_name"]', 
                'input[id*="first"][id*="name"]',
                '.woocommerce-billing-fields input[name*="first"]',
                '#billing-first-name'
            ]),
            billing_last_name: getData([
                '#billing_last_name', 
                'input[name="billing_last_name"]',
                'input[id*="last"][id*="name"]',
                '.woocommerce-billing-fields input[name*="last"]',
                '#billing-last-name'
            ]),
            billing_company: getData([
                '#billing_company', 
                'input[name="billing_company"]',
                'input[id*="company"]',
                '#billing-company'
            ]),
            billing_address_1: getData([
                '#billing_address_1', 
                'input[name="billing_address_1"]',
                'input[id*="address"][id*="1"]',
                '.woocommerce-billing-fields input[name*="address"]',
                '#billing-address-1'
            ]),
            billing_address_2: getData([
                '#billing_address_2', 
                'input[name="billing_address_2"]',
                'input[id*="address"][id*="2"]',
                '#billing-address-2'
            ]),
            billing_city: getData([
                '#billing_city', 
                'input[name="billing_city"]',
                'input[id*="city"]',
                '#billing-city'
            ]),
            billing_state: getData([
                '#billing_state', 
                'select[name="billing_state"]', 
                'input[name="billing_state"]',
                'select[id*="state"]',
                '#billing-state'
            ]),
            billing_postcode: getData([
                '#billing_postcode', 
                'input[name="billing_postcode"]',
                'input[id*="postcode"]',
                'input[id*="zip"]',
                '#billing-postcode'
            ]),
            billing_country: getData([
                '#billing_country', 
                'select[name="billing_country"]',
                'select[id*="country"]',
                '#billing-country'
            ]),
            billing_email: getData([
                '#billing_email', 
                'input[name="billing_email"]',
                'input[type="email"]',
                'input[id*="email"]',
                '#billing-email'
            ]),
            billing_phone: getData([
                '#billing_phone', 
                'input[name="billing_phone"]',
                'input[type="tel"]',
                'input[id*="phone"]',
                '#billing-phone'
            ]),
            order_comments: getData([
                '#order_comments', 
                'textarea[name="order_comments"]',
                'textarea[id*="comment"]',
                '#order-comments'
            ]),
            _tracking_timestamp: Date.now()
        };
        
        return data;
    }

    /**
     * Sends checkout data to server with retry logic
     */
    function sendCheckoutData(data, isRetry) {
        isRetry = isRetry || false;

        // Skip if data hasn't changed
        const dataString = JSON.stringify(data);
        if (lastSavedData === dataString && !isRetry) {
            return;
        }

        saveInProgress = true;

        $.ajax({
            url: checkoutguard_checkout_params.ajax_url,
            type: 'POST',
            data: data,
            timeout: 15000, // 15 second timeout
            success: function(response) {
                saveInProgress = false;
                if (response.success) {
                    lastSavedData = dataString;
                    retryCount = 0;
                    // Mark tracking as successful
                    $(document.body).trigger('checkoutguard_tracking_success');
                } else {
                    // Server returned error
                    if (retryCount < maxRetries) {
                        retryCount++;
                        setTimeout(function() {
                            sendCheckoutData(data, true);
                        }, 2000 * retryCount); // Progressive delay
                    }
                }
            },
            error: function(xhr, status, error) {
                saveInProgress = false;
                // Network or server error - retry
                if (retryCount < maxRetries) {
                    retryCount++;
                    setTimeout(function() {
                        sendCheckoutData(data, true);
                    }, 2000 * retryCount);
                }
            }
        });
    }

    /**
     * Main function to collect and send data
     * NOW: Saves ALL data immediately, no validation required
     */
    function collectAndSendData() {
        // Prevent multiple simultaneous saves
        if (saveInProgress) {
            return;
        }
        
        const checkoutData = collectCheckoutData();

        // Check if ANY field has data - save immediately
        let hasAnyData = false;
        for (let key in checkoutData) {
            if (key !== 'action' && key !== 'nonce' && key !== '_tracking_timestamp' && checkoutData[key]) {
                hasAnyData = true;
                break;
            }
        }
        
        if (hasAnyData) {
            sendCheckoutData(checkoutData, false);
        }
    }

    /**
     * Debounced version to prevent excessive AJAX calls
     */
    function debouncedCollectAndSendData() {
        clearTimeout(debounceTimeout);
        debounceTimeout = setTimeout(collectAndSendData, debounceDelay);
    }

    // All billing fields that should trigger tracking
    const fieldSelectors = [
        '#billing_first_name', '#billing_last_name', '#billing_company',
        '#billing_address_1', '#billing_address_2', '#billing_city',
        '#billing_state', '#billing_postcode', '#billing_country',
        '#billing_email', '#billing_phone', '#order_comments',
        // Also try with name attributes
        'input[name="billing_first_name"]', 'input[name="billing_last_name"]',
        'input[name="billing_company"]', 'input[name="billing_address_1"]',
        'input[name="billing_address_2"]', 'input[name="billing_city"]',
        'input[name="billing_postcode"]', 'input[name="billing_email"]',
        'input[name="billing_phone"]', 'select[name="billing_state"]',
        'select[name="billing_country"]', 'textarea[name="order_comments"]'
    ].join(',');
    
    // Bind to all field events - comprehensive tracking
    $(document.body).on('input change blur keyup paste', fieldSelectors, function() {
        debouncedCollectAndSendData();
    });
    
    // Also track select/dropdown changes immediately
    $(document.body).on('change', 'select[name^="billing_"], select[name^="shipping_"]', function() {
        // Save immediately for dropdowns, no debounce
        collectAndSendData();
    });
    
    // WooCommerce specific events
    $(document.body).on('update_checkout updated_checkout', function() {
        setTimeout(collectAndSendData, 500);
    });
    
    // Country/state change events
    $(document.body).on('country_to_state_changed', debouncedCollectAndSendData);

    // Initial capture for auto-filled fields
    setTimeout(function() {
        collectAndSendData();
    }, 1000); // Reduced from 1500ms to 1000ms
    
    // Periodic save every 5 seconds if user is still on page (real-time sync)
    setInterval(function() {
        if (document.hasFocus()) {
            collectAndSendData();
        }
    }, 5000); // Auto-save every 5 seconds

    // Save before page unload (user leaving checkout)
    $(window).on('beforeunload', function() {
        const data = collectCheckoutData();
        if (data.billing_first_name || data.billing_email || data.billing_phone) {
            // Use synchronous AJAX for beforeunload
            $.ajax({
                url: checkoutguard_checkout_params.ajax_url,
                type: 'POST',
                data: data,
                async: false // Important: Must be synchronous for beforeunload
            });
        }
    });
});