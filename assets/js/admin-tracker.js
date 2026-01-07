jQuery(function ($) {
    'use strict';

    if (typeof checkoutguard_admin_params === 'undefined') {
        return;
    }

    // Initialize dashboard animations and effects
    function initDashboard() {
        // Add hover effects to stat cards
        $('.checkoutguard-stat-box').hover(
            function () {
                $(this).css('transform', 'translateY(-2px)');
            },
            function () {
                $(this).css('transform', 'translateY(0)');
            }
        );

        // Initialize any charts or visualizations here
        // This is a placeholder for future functionality
    }

    // Initialize if we're on the dashboard page
    if ($('.checkoutguard-wrap').length) {
        initDashboard();
    }

    // --- Modal Handling ---
    // Function to open modal with animation
    function openModal() {
        const modal = $('#checkoutguard-details-modal');
        $('body').addClass('checkoutguard-modal-open');
        modal.fadeIn(300);
        modal.attr('aria-hidden', 'false');
        modal.data('lastFocus', document.activeElement);
        setTimeout(() => $('.checkoutguard-modal-close').focus(), 100);
    }

    // Function to close modal with animation
    function closeModal() {
        const modal = $('#checkoutguard-details-modal');
        $('body').removeClass('checkoutguard-modal-open');
        modal.fadeOut(200);
        modal.attr('aria-hidden', 'true');
        const lastFocus = modal.data('lastFocus');
        if (lastFocus) {
            $(lastFocus).focus();
        }
    }

    // Click handler for view details button (Supports both Modern and Old classes)
    // Using document delegation to work with dynamically loaded content
    $(document).on('click', '.checkoutguard-view-details, .act-view-details', function (e) {
        e.preventDefault();
        const entryId = $(this).data('id');
        
        if (!entryId) {
            return;
        }

        const modalBody = $('#checkoutguard-modal-body');
        
        // Show loading state
        modalBody.html('<div class="checkoutguard-loading"><span class="dashicons dashicons-update-alt"></span><p>Loading details...</p></div>');
        openModal();

        // Function to fetch with retry logic
        let retryCount = 0;
        const maxRetries = 2;
        
        function fetchDetails() {
            // Fetch checkout details via AJAX
            $.ajax({
                url: checkoutguard_admin_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'checkoutguard_get_checkout_details',
                    nonce: checkoutguard_admin_params.view_details_nonce,
                    entry_id: entryId
                },
                timeout: 10000, // 10 second timeout
                success: function(response) {
                    if (response.success) {
                        modalBody.html(response.data.html);
                    } else {
                        const errorMsg = response.data && response.data.message ? response.data.message : 'Could not load details.';
                        modalBody.html('<p class="error-message">' + errorMsg + '</p>');
                    }
                },
                error: function(xhr, status, error) {
                    
                    // Retry on network errors
                    if (retryCount < maxRetries && (status === 'timeout' || status === 'error')) {
                        retryCount++;
                        modalBody.html('<div class="checkoutguard-loading"><span class="dashicons dashicons-update-alt"></span><p>Retrying... (' + retryCount + '/' + maxRetries + ')</p></div>');
                        setTimeout(fetchDetails, 1000 * retryCount); // Progressive delay
                    } else {
                        let errorHtml = '<div class="error-message" style="padding: 20px;">';
                        errorHtml += '<p><strong>An error occurred while fetching details.</strong></p>';
                        errorHtml += '<p>Status: ' + status + '</p>';
                        if (xhr.status) {
                            errorHtml += '<p>HTTP Code: ' + xhr.status + '</p>';
                        }
                        errorHtml += '<button class="button" onclick="location.reload()">Reload Page</button>';
                        errorHtml += '</div>';
                        modalBody.html(errorHtml);
                    }
                }
            });
        }
        
        fetchDetails();
    });

    // Close modal when clicking the close button
    $(document).on('click', '.checkoutguard-modal-close, .act-modal-close', closeModal);

    // Close modal when clicking outside the modal content
    $(document).on('click', function(event) {
        const modal = $('#checkoutguard-details-modal, #act-details-modal');
        if ($(event.target).is(modal)) {
            closeModal();
        }
    });

    // Close modal when pressing ESC key
    $(document).on('keydown', function (e) {
        const modal = $('#checkoutguard-details-modal, #act-details-modal');
        if (e.key === 'Escape' && modal.is(':visible')) {
            closeModal();
        }
    });

    // --- Mark as Cancelled (Supports both Modern and Old classes) ---
    $(document).on('click', '.checkoutguard-mark-cancelled, .act-mark-cancelled', function (e) {
        e.preventDefault();
        const $button = $(this);
        const entryId = $button.data('id');

        if (!entryId || !confirm('Are you sure you want to mark this entry as cancelled?')) {
            return;
        }

        $button.prop('disabled', true).text('Cancelling...');

        $.post(checkoutguard_admin_params.ajax_url, {
            action: 'checkoutguard_mark_cancelled',
            nonce: checkoutguard_admin_params.mark_cancelled_nonce,
            entry_id: entryId
        }).done(function (response) {
            if (response.success) {
                $('#checkoutguard-entry-row-' + entryId + ', #act-entry-row-' + entryId).fadeOut(500, function () {
                    $(this).remove();
                });
            } else {
                alert('Error: ' + (response.data.message || 'An unknown error occurred.'));
                $button.prop('disabled', false).text('Cancel');
            }
        }).fail(function () {
            alert('An AJAX error occurred.');
            $button.prop('disabled', false).text('Cancel');
        });
    });

    // --- Recover Order (Supports both Modern and Old classes) ---
    $(document).on('click', '.checkoutguard-recover-order, .act-recover-order', function (e) {
        e.preventDefault();
        const $button = $(this);
        const entryId = $button.data('id');

        if (!entryId || !confirm('Are you sure you want to recover this order? This will create a new WooCommerce order.')) {
            return;
        }

        $button.prop('disabled', true).text('Recovering...');

        $.post(checkoutguard_admin_params.ajax_url, {
            action: 'checkoutguard_recover_order',
            nonce: checkoutguard_admin_params.recover_order_nonce,
            entry_id: entryId
        }).done(function (response) {
            if (response.success) {
                alert(response.data.message);
                // Reload page to show status change
                location.reload();
            } else {
                alert('Error: ' + (response.data.message || 'An unknown error occurred.'));
                $button.prop('disabled', false).text('Recover');
            }
        }).fail(function () {
            alert('An AJAX error occurred.');
            $button.prop('disabled', false).text('Recover');
        });
    });

    // --- Mark as Hold (Supports both Modern and Old classes) ---
    $(document).on('click', '.checkoutguard-mark-hold, .act-mark-hold', function (e) {
        e.preventDefault();
        const $button = $(this);
        const entryId = $button.data('id');

        if (!entryId) return;

        $button.prop('disabled', true).text('Holding...');

        $.post(checkoutguard_admin_params.ajax_url, {
            action: 'checkoutguard_mark_hold',
            nonce: checkoutguard_admin_params.mark_hold_nonce,
            entry_id: entryId
        }).done(function (response) {
            if (response.success) {
                $('#checkoutguard-entry-row-' + entryId + ', #act-entry-row-' + entryId).fadeOut(500, function () {
                    $(this).remove();
                });
            } else {
                alert('Error: ' + (response.data.message || 'An unknown error occurred.'));
                $button.prop('disabled', false).text('Hold');
            }
        }).fail(function () {
            alert('An AJAX error occurred.');
            $button.prop('disabled', false).text('Hold');
        });
    });

    // --- Re-open Checkout (Old Class) ---
    $(document).on('click', '.act-reopen-checkout', function (e) {
        e.preventDefault();
        const $button = $(this);
        const entryId = $button.data('id');

        if (!entryId) return;

        $button.prop('disabled', true);

        $.post(checkoutguard_admin_params.ajax_url, {
            action: 'checkoutguard_reopen_checkout',
            nonce: checkoutguard_admin_params.reopen_checkout_nonce,
            entry_id: entryId
        }).done(function (response) {
            if (response.success) {
                $('#act-entry-row-' + entryId).fadeOut(500, function () {
                    $(this).remove();
                });
            } else {
                alert('Error: ' + (response.data.message || 'An unknown error occurred.'));
                $button.prop('disabled', false);
            }
        }).fail(function () {
            alert('An AJAX error occurred.');
            $button.prop('disabled', false);
        });
    });

    // --- Edit Follow-up Date (Old Class) ---
    $(document).on('click', '.act-edit-follow-up-date', function (e) {
        e.preventDefault();
        const $button = $(this);
        const entryId = $button.data('id');
        const currentDate = $button.data('current-date');
        
        const newDate = prompt('Enter new follow-up date (YYYY-MM-DD):', currentDate);
        
        if (newDate === null || newDate === currentDate) return;
        
        // Simple date validation regex
        if (!/^\d{4}-\d{2}-\d{2}$/.test(newDate)) {
            alert('Invalid date format. Please use YYYY-MM-DD.');
            return;
        }

        $.post(checkoutguard_admin_params.ajax_url, {
            action: 'checkoutguard_edit_follow_up_date',
            nonce: checkoutguard_admin_params.edit_follow_up_nonce,
            entry_id: entryId,
            date: newDate
        }).done(function (response) {
            if (response.success) {
                // Update the date in the table cell
                $('.act-follow-up-date-cell-' + entryId).text(newDate);
                $button.data('current-date', newDate);
            } else {
                alert('Error: ' + (response.data.message || 'An unknown error occurred.'));
            }
        }).fail(function () {
            alert('An AJAX error occurred.');
        });
    });

    // --- Fraud Blocker Page AJAX Handlers ---
    if ($('.checkoutguard-fraud-blocker-wrap').length) {
        // Add item form submission
        $('.checkoutguard-blocker-form').on('submit', function (e) {
            e.preventDefault();
            const $form = $(this);
            const $button = $form.find('button[type="submit"]');
            const $spinner = $form.find('.spinner');
            const blockType = $form.data('block-type');
            const nonce = $form.find('input[name="nonce"]').val();
            const value = $form.find('input[name="value"]').val();
            const reason = $form.find('textarea[name="reason"]').val();

            $button.prop('disabled', true);
            $spinner.addClass('is-active');

            $.post(checkoutguard_admin_params.ajax_url, {
                action: 'checkoutguard_add_blocked_item',
                nonce: nonce,
                block_type: blockType,
                value: value,
                reason: reason
            }).done(function (response) {
                if (response.success) {
                    const list = $('#checkoutguard-blocked-list-' + blockType);
                    list.find('.checkoutguard-no-items, .checkoutguard-no-results').remove();
                    list.prepend(response.data.html);
                    $form[0].reset();
                    $('#checkoutguard-blocker-messages').removeClass('notice-error').addClass('notice-success').html('<p>Item successfully blocked.</p>').slideDown().delay(3000).slideUp();
                } else {
                    $('#checkoutguard-blocker-messages').removeClass('notice-success').addClass('notice-error').html('<p>' + response.data.message + '</p>').slideDown().delay(3000).slideUp();
                }
            }).fail(function () {
                $('#checkoutguard-blocker-messages').removeClass('notice-success').addClass('notice-error').html('<p>An AJAX error occurred.</p>').slideDown().delay(3000).slideUp();
            }).always(function () {
                $button.prop('disabled', false);
                $spinner.removeClass('is-active');
            });
        });

        // Delete item click handler
        $(document).on('click', '.checkoutguard-delete-item-ajax', function (e) {
            e.preventDefault();
            if (!confirm('Are you sure you want to remove this item from the blocklist?')) {
                return;
            }

            const $link = $(this);
            const itemId = $link.data('item-id');
            const blockType = $link.data('block-type');
            const nonce = $link.data('nonce');

            $link.find('.dashicons').removeClass('dashicons-trash').addClass('dashicons-update-alt spin');

            $.post(checkoutguard_admin_params.ajax_url, {
                action: 'checkoutguard_delete_blocked_item',
                nonce: nonce,
                item_id: itemId,
                block_type: blockType
            }).done(function (response) {
                if (response.success) {
                    $('#checkoutguard-blocked-item-' + itemId).fadeOut(500, function () {
                        $(this).remove();
                        const list = $('#checkoutguard-blocked-list-' + blockType);
                        if (list.find('li').length === 0) {
                            list.append('<li class="checkoutguard-no-items checkoutguard-no-results">No items are currently blocked.</li>');
                        }
                    });
                } else {
                    alert('Error: ' + response.data.message);
                    $link.find('.dashicons').removeClass('dashicons-update-alt spin').addClass('dashicons-trash');
                }
            }).fail(function () {
                alert('An AJAX error occurred.');
                $link.find('.dashicons').removeClass('dashicons-update-alt spin').addClass('dashicons-trash');
            });
        });
    }

    // --- Single Order Page: Meta Box Button Click Handlers ---
    $(document).on('click', '.checkoutguard-block-from-order, .checkoutguard-unblock-from-order', function (e) {
        e.preventDefault();
        const $button = $(this);
        const isBlock = $button.hasClass('checkoutguard-block-from-order');
        const action = isBlock ? 'checkoutguard_add_blocked_item' : 'checkoutguard_delete_blocked_item';
        const nonce = $button.data('nonce');
        const value = $button.data('value');
        const orderId = $button.data('order-id');

        $button.prop('disabled', true).find('.dashicons').removeClass('dashicons-shield-alt dashicons-unlock').addClass('dashicons-update-alt spin');

        $.post(checkoutguard_admin_params.ajax_url, {
            action: action,
            nonce: nonce,
            block_type: 'phone',
            value: value,
            reason: `From Order #${orderId}`
        }).done(function (response) {
            if (response.success) {
                const newNonce = isBlock ? checkoutguard_admin_params.delete_blocker_item_nonce : checkoutguard_admin_params.fraud_blocker_nonce;
                const newClass = isBlock ? 'checkoutguard-unblock-from-order' : 'checkoutguard-block-from-order';
                const newIcon = isBlock ? 'dashicons-unlock' : 'dashicons-shield-alt';
                const newText = isBlock ? ' Unblock' : ' Block';
                const newButtonHTML = `<button type="button" class="button ${newClass}" data-order-id="${orderId}" data-block-type="phone" data-value="${value}" data-nonce="${newNonce}"><span class="dashicons ${newIcon}"></span>${newText}</button>`;
                $button.replaceWith(newButtonHTML);
            } else {
                alert('Error: ' + response.data.message);
                $button.prop('disabled', false).find('.dashicons').removeClass('dashicons-update-alt spin').addClass(isBlock ? 'dashicons-shield-alt' : 'dashicons-unlock');
            }
        }).fail(function () {
            alert('An AJAX error occurred.');
            $button.prop('disabled', false).find('.dashicons').removeClass('dashicons-update-alt spin').addClass(isBlock ? 'dashicons-shield-alt' : 'dashicons-unlock');
        });
    });

    // --- Incomplete Checkouts Filtering ---
    // Use document delegation to handle dynamically loaded buttons
    $(document).on('click', '.checkoutguard_incomplete-filter-btn', function() {
        const $btn = $(this);
        const range = $btn.data('range');
        
        if (!range) {
            return;
        }
        
        $('.checkoutguard_incomplete-filter-btn').removeClass('active');
        $btn.addClass('active');
        
        fetchIncompleteEntries(range, null, null);
    });

    $(document).on('click', '.checkoutguard_incomplete_apply_date_filter', function() {
        const startDate = $('#checkoutguard_incomplete_start_date').val();
        const endDate = $('#checkoutguard_incomplete_end_date').val();
        
        if (!startDate || !endDate) {
            alert('Please select both start and end dates.');
            return;
        }
        
        $('.checkoutguard_incomplete-filter-btn').removeClass('active');
        fetchIncompleteEntries('custom', startDate, endDate);
    });

    function fetchIncompleteEntries(range, startDate, endDate) {
        const $loading = $('#checkoutguard-incomplete-loading');
        const $container = $('#checkoutguard-incomplete-table-container');
        
        if (!$container.length) {
            return;
        }
        
        $loading.show();
        $container.css('opacity', '0.5');
        
        $.ajax({
            url: checkoutguard_admin_params.ajax_url,
            type: 'POST',
            data: {
                action: 'checkoutguard_fetch_incomplete_entries',
                nonce: checkoutguard_admin_params.fetch_incomplete_nonce,
                range: range,
                start_date: startDate,
                end_date: endDate
            },
            success: function(response) {
                if (response.success) {
                    $container.html(response.data.html).css('opacity', '1');
                    // Trigger event for other scripts that might need to reinitialize
                    $(document).trigger('checkoutguard_table_refreshed');
                } else {
                    const errorMsg = response.data && response.data.message ? response.data.message : 'Failed to load entries';
                    alert('Error: ' + errorMsg);
                    $container.css('opacity', '1');
                }
            },
            error: function(xhr, status, error) {
                alert('An error occurred while fetching entries. Please try again.');
                $container.css('opacity', '1');
            },
            complete: function() {
                $loading.hide();
            }
        });
    }
}); // End of jQuery document ready