jQuery(function ($) {
    'use strict';

    if (typeof checkoutguard_checkout_params === 'undefined') {
        return;
    }

    // --- SESSION TRACKING LOGIC ---
    let debounceTimeout;
    const debounceDelay = 800;

    function collectAndSendData() {
        const checkoutData = {
            action: 'checkoutguard_save_checkout_data',
            nonce: checkoutguard_checkout_params.save_data_nonce,
            billing_first_name: $('#billing_first_name').val() || '',
            billing_last_name: $('#billing_last_name').val() || '',
            billing_phone: $('#billing_phone').val() || '',
            billing_email: $('#billing_email').val() || '',
            billing_address_1: $('#billing_address_1').val() || '',
            billing_city: $('#billing_city').val() || '',
            billing_postcode: $('#billing_postcode').val() || '',
            billing_country: $('#billing_country').val() || '',
        };

        if (checkoutData.billing_email || checkoutData.billing_phone || checkoutData.billing_first_name) {
            $.post(checkoutguard_checkout_params.ajax_url, checkoutData);
        }
    }

    function debouncedCollectAndSendData() {
        clearTimeout(debounceTimeout);
        debounceTimeout = setTimeout(collectAndSendData, debounceDelay);
    }

    const fieldSelectors = [
        '#billing_first_name', '#billing_last_name', '#billing_phone', '#billing_email',
        '#billing_address_1', '#billing_city', '#billing_postcode', '#billing_country'
    ].join(',');

    $(document.body).on('input change blur', fieldSelectors, debouncedCollectAndSendData);
    $(document.body).on('update_checkout', () => setTimeout(collectAndSendData, 100));

    setTimeout(collectAndSendData, 1500);
});