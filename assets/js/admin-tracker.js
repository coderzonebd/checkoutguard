jQuery(function ($) {
    'use strict';

    if (typeof checkoutguard_admin_params === 'undefined') {
        console.error('CheckoutGuard Admin: checkoutguard_admin_params is not defined.');
        return;
    }

    // Initialize dashboard animations and effects
    function initDashboard() {
        // Add hover effects to stat cards
        $('.cg-stat-card').hover(
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
    const modal = $('#checkoutguard-details-modal');
    if (modal.length) {
        const modalBody = $('#checkoutguard-modal-body');
        const closeModalButton = $('.cg-modal-close');

        // Function to open modal with animation
        function openModal() {
            $('body').addClass('cg-modal-open');
            modal.fadeIn(300);
            // Trap focus inside modal for accessibility
            modal.attr('aria-hidden', 'false');
            // Save current focus
            modal.data('lastFocus', document.activeElement);
            // Focus on close button
            setTimeout(() => closeModalButton.focus(), 100);
        }

        // Function to close modal with animation
        function closeModal() {
            $('body').removeClass('cg-modal-open');
            modal.fadeOut(200);
            modal.attr('aria-hidden', 'true');
            // Restore focus
            $(modal.data('lastFocus')).focus();
        }

        // Click handler for view details button
        $(document).on('click', '.checkoutguard-view-details', function (e) {
            e.preventDefault();
            const entryId = $(this).data('id');
            if (!entryId) return;

            // Show loading state
            modalBody.html('<div class="cg-loading"><span class="dashicons dashicons-update-alt"></span><p>Loading details...</p></div>');
            openModal();

            // Fetch checkout details via AJAX
            $.post(checkoutguard_admin_params.ajax_url, {
                action: 'checkoutguard_get_checkout_details',
                nonce: checkoutguard_admin_params.view_details_nonce,
                entry_id: entryId
            }).done(function (response) {
                if (response.success) {
                    modalBody.html(response.data.html);
                } else {
                    modalBody.html('<p class="error-message">' + (response.data.message || 'Could not load details.') + '</p>');
                }
            }).fail(function () {
                modalBody.html('<p class="error-message">An error occurred while fetching details.</p>');
            });
        });

        // Close modal when clicking the close button
        closeModalButton.on('click', closeModal);

        // Close modal when clicking outside the modal content
        $(window).on('click', (event) => {
            if ($(event.target).is(modal)) {
                closeModal();
            }
        });

        // Close modal when pressing ESC key
        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' && modal.is(':visible')) {
                closeModal();
            }
        });
    }

    // --- Mark as Cancelled ---
    $(document).on('click', '.checkoutguard-mark-cancelled', function (e) {
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
                $('#checkoutguard-entry-row-' + entryId).fadeOut(500, function () {
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
                    list.find('.checkoutguard-no-items').remove();
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
                            list.append('<li class="checkoutguard-no-items">No items are currently blocked.</li>');
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
});