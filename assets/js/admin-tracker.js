/* =====================================================================
 * CheckoutGuard — Global UI Modules (loaded before jQuery wrapper)
 * Toast Notifications, Confirmation/Prompt Modals, Pagination Controller,
 * Dark Mode Toggle, User Preferences
 * ===================================================================== */

/* --- 2A. Toast Notification System (window.cgToast) --- */
(function () {
    'use strict';
    var container = null;
    var MAX_TOASTS = 5;
    var ICONS = {
        success: '\u2713',
        error: '\u26A0',
        warning: '\u26A0',
        info: '\uD83D\uDCA1'
    };
    var DURATIONS = { success: 4000, error: 6000, warning: 5000, info: 4000 };

    function getContainer() {
        if (!container) {
            container = document.createElement('div');
            container.className = 'cg-toast-container';
            container.setAttribute('aria-live', 'polite');
            container.setAttribute('aria-atomic', 'false');
            document.body.appendChild(container);
        }
        return container;
    }

    function removeToast(el) {
        el.classList.add('cg-toast--removing');
        setTimeout(function () {
            if (el.parentNode) el.parentNode.removeChild(el);
        }, 300);
    }

    function show(type, message, duration, allowHtml) {
        var c = getContainer();
        duration = duration || DURATIONS[type] || 4000;

        // Enforce max visible
        var toasts = c.querySelectorAll('.cg-toast:not(.cg-toast--removing)');
        while (toasts.length >= MAX_TOASTS) {
            removeToast(toasts[0]);
            toasts = c.querySelectorAll('.cg-toast:not(.cg-toast--removing)');
        }

        var el = document.createElement('div');
        el.className = 'cg-toast cg-toast--' + type;
        el.setAttribute('role', 'status');

        var icon = document.createElement('span');
        icon.className = 'cg-toast__icon';
        icon.textContent = ICONS[type] || '';

        var msg = document.createElement('span');
        msg.className = 'cg-toast__message';
        if (allowHtml) {
            msg.innerHTML = message;
        } else {
            msg.textContent = message;
        }

        var closeBtn = document.createElement('button');
        closeBtn.className = 'cg-toast__close';
        closeBtn.innerHTML = '&times;';
        closeBtn.onclick = function () { removeToast(el); };

        var progress = document.createElement('div');
        progress.className = 'cg-toast__progress';
        progress.style.width = '100%';
        progress.style.transitionDuration = duration + 'ms';

        el.appendChild(icon);
        el.appendChild(msg);
        el.appendChild(closeBtn);
        el.appendChild(progress);
        c.appendChild(el);

        // Start progress bar animation
        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                progress.style.width = '0%';
            });
        });

        var timer = setTimeout(function () { removeToast(el); }, duration);

        // Pause on hover
        el.addEventListener('mouseenter', function () {
            clearTimeout(timer);
            progress.style.transitionDuration = '0ms';
        });
        el.addEventListener('mouseleave', function () {
            var remaining = parseFloat(getComputedStyle(progress).width) / parseFloat(getComputedStyle(progress.parentNode).width) * duration;
            if (remaining < 500) remaining = 500;
            progress.style.transitionDuration = remaining + 'ms';
            progress.style.width = '0%';
            timer = setTimeout(function () { removeToast(el); }, remaining);
        });
    }

    window.cgToast = {
        success: function (msg, dur) { show('success', msg, dur); },
        error: function (msg, dur) { show('error', msg, dur); },
        warning: function (msg, dur) { show('warning', msg, dur); },
        info: function (msg, dur) { show('info', msg, dur); },
        errorHtml: function (msg, dur) { show('error', msg, dur, true); },
        warningHtml: function (msg, dur) { show('warning', msg, dur, true); },
        successHtml: function (msg, dur) { show('success', msg, dur, true); }
    };
})();

/* --- 2B. Confirmation Modal (window.cgConfirm) & Prompt (window.cgPrompt) --- */
(function () {
    'use strict';

    function createOverlay(opts) {
        var overlay = document.createElement('div');
        overlay.className = 'cg-confirm-overlay';

        var dialog = document.createElement('div');
        dialog.className = 'cg-confirm-dialog' + (opts.danger ? ' cg-confirm-dialog--danger' : '');

        var title = document.createElement('h3');
        title.className = 'cg-confirm-dialog__title';
        title.textContent = opts.title || 'Confirm';

        var message = document.createElement('p');
        message.className = 'cg-confirm-dialog__message';
        message.textContent = opts.message || '';

        dialog.appendChild(title);
        dialog.appendChild(message);

        if (opts.input) {
            var input = document.createElement('input');
            input.type = opts.inputType || 'text';
            input.className = 'cg-confirm-dialog__input';
            input.placeholder = opts.placeholder || '';
            input.value = opts.defaultValue || '';
            dialog.appendChild(input);
            dialog._input = input;
        }

        var actions = document.createElement('div');
        actions.className = 'cg-confirm-dialog__actions';

        var cancelBtn = document.createElement('button');
        cancelBtn.className = 'cg-confirm-dialog__btn cg-confirm-dialog__btn--cancel';
        cancelBtn.textContent = opts.cancelText || 'Cancel';

        var confirmBtn = document.createElement('button');
        confirmBtn.className = 'cg-confirm-dialog__btn cg-confirm-dialog__btn--confirm';
        confirmBtn.textContent = opts.confirmText || 'Confirm';

        actions.appendChild(cancelBtn);
        actions.appendChild(confirmBtn);
        dialog.appendChild(actions);
        overlay.appendChild(dialog);

        overlay._cancelBtn = cancelBtn;
        overlay._confirmBtn = confirmBtn;
        overlay._dialog = dialog;

        return overlay;
    }

    function showDialog(opts) {
        return new Promise(function (resolve) {
            var overlay = createOverlay(opts);
            document.body.appendChild(overlay);

            var input = overlay._dialog._input;
            if (input) {
                setTimeout(function () { input.focus(); input.select(); }, 100);
            }

            function close(result) {
                overlay.style.opacity = '0';
                setTimeout(function () {
                    if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
                }, 200);
                resolve(result);
            }

            overlay._cancelBtn.onclick = function () {
                close(opts.input ? null : false);
            };

            overlay._confirmBtn.onclick = function () {
                close(opts.input ? (input ? input.value : '') : true);
            };

            // Close on backdrop click
            overlay.addEventListener('click', function (e) {
                if (e.target === overlay) {
                    close(opts.input ? null : false);
                }
            });

            // Close on Escape, confirm on Enter
            function onKey(e) {
                if (e.key === 'Escape') {
                    document.removeEventListener('keydown', onKey);
                    close(opts.input ? null : false);
                } else if (e.key === 'Enter' && !opts.input) {
                    document.removeEventListener('keydown', onKey);
                    close(true);
                } else if (e.key === 'Enter' && opts.input) {
                    document.removeEventListener('keydown', onKey);
                    close(input ? input.value : '');
                }
            }
            document.addEventListener('keydown', onKey);
        });
    }

    window.cgConfirm = {
        show: function (opts) {
            opts = opts || {};
            opts.input = false;
            return showDialog(opts);
        }
    };

    window.cgPrompt = {
        show: function (opts) {
            opts = opts || {};
            opts.input = true;
            return showDialog(opts);
        }
    };
})();

/* --- 2C. Pagination Controller (window.cgPagination) --- */
(function () {
    'use strict';

    var instances = {};

    function create(id, opts) {
        var state = {
            id: id,
            page: 1,
            perPage: opts.perPage || parseInt(window.cgPrefs && window.cgPrefs.get('per_page'), 10) || 20,
            search: '',
            sortCol: opts.defaultSort || 'updated_at',
            sortDir: opts.defaultDir || 'DESC',
            total: 0,
            totalPages: 0,
            loading: false,
            opts: opts
        };

        instances[id] = state;

        function fetch() {
            if (state.loading) return;
            state.loading = true;

            var $container = jQuery(opts.containerSelector);
            var $tbody = jQuery(opts.tbodySelector);
            var $loading = jQuery(opts.loadingSelector || '#cg-' + id + '-loading');
            var $tableWrap = jQuery(opts.tableContainerSelector || '#cg-' + id + '-table-container');

            $loading.show();
            $tableWrap.hide();

            // Build extra data from filter bar
            var extraData = {};
            if (typeof opts.getFilterData === 'function') {
                extraData = opts.getFilterData();
            }

            var postData = jQuery.extend({
                action: opts.fetchAction,
                nonce: opts.fetchNonce,
                page: state.page,
                per_page: state.perPage,
                search: state.search,
                sort_col: state.sortCol,
                sort_dir: state.sortDir
            }, extraData);

            jQuery.post(
                (typeof cg_admin_params !== 'undefined' ? cg_admin_params.ajax_url : ajaxurl),
                postData
            ).done(function (response) {
                if (response.success) {
                    $tbody.html(response.data.html);
                    state.total = parseInt(response.data.total, 10) || 0;
                    state.totalPages = parseInt(response.data.total_pages, 10) || 0;
                    state.page = parseInt(response.data.page, 10) || 1;

                    var $table = $tableWrap.find('table');
                    var $emptyMsg = $tableWrap.find('.cg-table-empty-message');
                    if (state.total > 0) {
                        $table.show();
                        $emptyMsg.hide();
                    } else {
                        $table.hide();
                        $emptyMsg.show();
                    }

                    renderPagination($container, state);
                } else {
                    $tbody.html('<tr><td colspan="10">Error: ' + ((response.data && response.data.message) || 'Could not load data.') + '</td></tr>');
                }
            }).fail(function () {
                $tbody.html('<tr><td colspan="10">Connection error. Please try again.</td></tr>');
            }).always(function () {
                state.loading = false;
                $loading.hide();
                $tableWrap.show();
            });
        }

        function renderPagination($container, st) {
            if (!$container.length) return;
            var html = '<div class="cg-pagination">';

            // Info
            var start = st.total > 0 ? ((st.page - 1) * st.perPage + 1) : 0;
            var end = Math.min(st.page * st.perPage, st.total);
            html += '<span class="cg-pagination__info">Showing <strong>' + start + '-' + end + '</strong> of <strong>' + st.total + '</strong></span>';

            html += '<div class="cg-pagination__right">';

            // Per-page
            html += '<span class="cg-pagination__per-page">Show ';
            html += '<select class="cg-pp-select" data-pg-id="' + st.id + '">';
            [10, 20, 50, 100].forEach(function (n) {
                html += '<option value="' + n + '"' + (n === st.perPage ? ' selected' : '') + '>' + n + '</option>';
            });
            html += '</select></span>';

            // Page buttons
            html += '<div class="cg-pagination__controls">';
            html += '<button class="cg-pagination__btn cg-pg-prev" data-pg-id="' + st.id + '"' + (st.page <= 1 ? ' disabled' : '') + '>&laquo;</button>';

            var maxButtons = 5;
            var startPage = Math.max(1, st.page - Math.floor(maxButtons / 2));
            var endPage = Math.min(st.totalPages, startPage + maxButtons - 1);
            if (endPage - startPage < maxButtons - 1) {
                startPage = Math.max(1, endPage - maxButtons + 1);
            }

            if (startPage > 1) {
                html += '<button class="cg-pagination__btn cg-pg-goto" data-pg-id="' + st.id + '" data-page="1">1</button>';
                if (startPage > 2) html += '<span class="cg-pagination__ellipsis">&hellip;</span>';
            }

            for (var i = startPage; i <= endPage; i++) {
                html += '<button class="cg-pagination__btn cg-pg-goto' + (i === st.page ? ' cg-pagination__btn--active' : '') + '" data-pg-id="' + st.id + '" data-page="' + i + '">' + i + '</button>';
            }

            if (endPage < st.totalPages) {
                if (endPage < st.totalPages - 1) html += '<span class="cg-pagination__ellipsis">&hellip;</span>';
                html += '<button class="cg-pagination__btn cg-pg-goto" data-pg-id="' + st.id + '" data-page="' + st.totalPages + '">' + st.totalPages + '</button>';
            }

            html += '<button class="cg-pagination__btn cg-pg-next" data-pg-id="' + st.id + '"' + (st.page >= st.totalPages ? ' disabled' : '') + '>&raquo;</button>';
            html += '</div></div></div>';

            $container.html(html);
        }

        return {
            fetch: fetch,
            getState: function () { return state; },
            setSearch: function (q) { state.search = q; state.page = 1; fetch(); },
            setSort: function (col, dir) { state.sortCol = col; state.sortDir = dir; state.page = 1; fetch(); },
            setPage: function (p) { state.page = p; fetch(); },
            reset: function () { state.page = 1; state.search = ''; state.sortCol = opts.defaultSort || 'updated_at'; state.sortDir = opts.defaultDir || 'DESC'; fetch(); }
        };
    }

    // Delegated event handlers — registered once outside create() to prevent duplicate bindings
    jQuery(document).on('click', '.cg-pg-prev', function () {
        var s = instances[jQuery(this).data('pg-id')];
        if (s && s.page > 1) { s.page--; instances[s.id] = s; create(s.id, s.opts).fetch(); }
    });
    jQuery(document).on('click', '.cg-pg-next', function () {
        var s = instances[jQuery(this).data('pg-id')];
        if (s && s.page < s.totalPages) { s.page++; instances[s.id] = s; create(s.id, s.opts).fetch(); }
    });
    jQuery(document).on('click', '.cg-pg-goto', function () {
        var s = instances[jQuery(this).data('pg-id')];
        var p = parseInt(jQuery(this).data('page'), 10);
        if (s && p >= 1 && p <= s.totalPages) { s.page = p; instances[s.id] = s; create(s.id, s.opts).fetch(); }
    });
    jQuery(document).on('change', '.cg-pp-select', function () {
        var s = instances[jQuery(this).data('pg-id')];
        if (s) {
            s.perPage = parseInt(jQuery(this).val(), 10);
            s.page = 1;
            if (window.cgPrefs) window.cgPrefs.set('per_page', s.perPage);
            instances[s.id] = s;
            create(s.id, s.opts).fetch();
        }
    });

    window.cgPagination = {
        create: create,
        get: function (id) { return instances[id] || null; }
    };
})();

/* --- 2D. Dark Mode Toggle --- */
(function () {
    'use strict';
    var KEY = 'cg_dark_mode';

    function apply(on) {
        if (on) {
            document.body.classList.add('cg-dark-mode');
        } else {
            document.body.classList.remove('cg-dark-mode');
        }
        // Update toggle button icons
        var btns = document.querySelectorAll('.cg-dark-mode-toggle');
        btns.forEach(function (btn) {
            var icon = btn.querySelector('.dashicons');
            if (icon) {
                icon.className = on ? 'dashicons dashicons-admin-appearance' : 'dashicons dashicons-admin-appearance';
            }
            btn.title = on ? 'Switch to Light Mode' : 'Switch to Dark Mode';
        });
    }

    function isOn() {
        try { return localStorage.getItem(KEY) === '1'; } catch (e) { return false; }
    }

    function toggle() {
        var next = !isOn();
        try { localStorage.setItem(KEY, next ? '1' : '0'); } catch (e) {}
        apply(next);
    }

    // Apply on load
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { apply(isOn()); });
    } else {
        apply(isOn());
    }

    // Delegate click handler
    document.addEventListener('click', function (e) {
        if (e.target.closest('.cg-dark-mode-toggle')) {
            e.preventDefault();
            toggle();
        }
    });

    window.cgDarkMode = { toggle: toggle, isOn: isOn, apply: apply };
})();

/* --- 2E. User Preferences (window.cgPrefs) --- */
(function () {
    'use strict';
    var PREFIX = 'cg_pref_';

    window.cgPrefs = {
        get: function (key, fallback) {
            try {
                var val = localStorage.getItem(PREFIX + key);
                return val !== null ? val : (fallback !== undefined ? fallback : null);
            } catch (e) {
                return fallback !== undefined ? fallback : null;
            }
        },
        set: function (key, value) {
            try { localStorage.setItem(PREFIX + key, value); } catch (e) {}
        },
        remove: function (key) {
            try { localStorage.removeItem(PREFIX + key); } catch (e) {}
        },
        getJSON: function (key, fallback) {
            try {
                var raw = localStorage.getItem(PREFIX + key);
                return raw !== null ? JSON.parse(raw) : (fallback !== undefined ? fallback : null);
            } catch (e) {
                return fallback !== undefined ? fallback : null;
            }
        },
        setJSON: function (key, value) {
            try { localStorage.setItem(PREFIX + key, JSON.stringify(value)); } catch (e) {}
        }
    };
})();

/* =====================================================================
 * End of Global UI Modules — jQuery wrapper begins below
 * ===================================================================== */

jQuery(function ($) {
    'use strict';

    if (typeof cg_admin_params === 'undefined') {
        console.error('CG Admin: cg_admin_params is not defined.');
        return;
    }

    // Show toast on settings save (WordPress redirects with ?settings-updated=true)
    if (new URLSearchParams(window.location.search).get('settings-updated') === 'true') {
        window.cgToast && window.cgToast.success('Settings saved successfully.');
    }

    const modal = $('#cg-details-modal');
    if (modal.length) {
        const modalBody = $('#cg-modal-body');
        const closeModalButton = $('.cg-modal-close');


        $(document).on('click', '.cg-view-details', function (e) {
            e.preventDefault();
            const $button = $(this);
            const entryType = $button.data('type') || 'incomplete_checkout'; // Default to incomplete

            const entryId = entryType === 'blocked_order' ? $button.data('log-id') : $button.data('id');

            if (!entryId) {
                cgToast.error('Could not find the entry ID.');
                return;
            }


            modalBody.html('<p>Loading details...</p>');
            modal.show();


            const ajaxAction = entryType === 'blocked_order'
                ? 'cg_get_blocked_order_details'
                : 'cg_get_incomplete_checkout_details';

            $.post(cg_admin_params.ajax_url, {
                action: ajaxAction,
                nonce: cg_admin_params.view_details_nonce,
                entry_id: entryId
            }).done(function (response) {
                if (response && response.success) {
                    modalBody.html(response.data.html);
                } else {
                    const errorMsg = (response && response.data && response.data.message)
                        ? response.data.message
                        : 'Could not load details. Please try again.';
                    modalBody.html('<p class="error-message">' + errorMsg + '</p>');
                    console.error('CG Modal Error:', response);
                }
            }).fail(function (xhr, status, error) {
                let errorMsg;
                if (xhr.status === 403) {
                    errorMsg = 'Your session has expired. Please <a href="javascript:location.reload()">reload the page</a> and try again.';
                } else if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    errorMsg = xhr.responseJSON.data.message;
                } else {
                    errorMsg = 'An error occurred while fetching details. Please check your connection and try again.';
                }
                modalBody.html('<p class="error-message">' + errorMsg + '</p>');
                console.error('CG AJAX Failed:', {xhr: xhr, status: status, error: error, action: ajaxAction, entryId: entryId});
            });
        });

        // Refresh courier data inside the popup (clears cache)
        $(document).on('click', '.cg-popup-refresh-ratio', function (e) {
            e.preventDefault();
            const $button = $(this);
            const entryId = $button.data('id');
            const entryType = $button.data('type') || 'incomplete_checkout';

            modalBody.html('<p>Loading details...</p>');

            const ajaxAction = entryType === 'blocked_order'
                ? 'cg_get_blocked_order_details'
                : 'cg_get_incomplete_checkout_details';

            $.post(cg_admin_params.ajax_url, {
                action: ajaxAction,
                nonce: cg_admin_params.view_details_nonce,
                entry_id: entryId,
                force_refresh: 1
            }).done(function (response) {
                if (response && response.success) {
                    modalBody.html(response.data.html);
                } else {
                    modalBody.html('<p class="error-message">Could not refresh data.</p>');
                }
            }).fail(function () {
                modalBody.html('<p class="error-message">An error occurred while refreshing.</p>');
            });
        });

        closeModalButton.on('click', () => modal.hide());
        $(window).on('click', (event) => {
            if ($(event.target).is(modal)) {
                modal.hide();
            }
        });

        // Close on Escape key
        $(document).on('keydown.cgModal', function (e) {
            if (e.key === 'Escape' && modal.is(':visible')) {
                modal.hide();
            }
        });

        // Focus trapping inside open modal
        $(document).on('keydown.cgModalTrap', function (e) {
            if (e.key !== 'Tab' || !modal.is(':visible')) return;
            var focusable = modal.find('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])').filter(':visible');
            if (!focusable.length) return;
            var first = focusable.first();
            var last = focusable.last();
            if (e.shiftKey && document.activeElement === first[0]) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last[0]) {
                e.preventDefault();
                first.focus();
            }
        });
    }

    // ── Upgrade to Pro Modal ──
    // The click handler is always bound and resolves the modal at click time
    // (it may be injected later in the footer). If the modal genuinely isn't
    // on the page, fall back to opening the pricing page directly so an
    // upgrade button is never a dead end.
    $(document).on('click', '.cg-open-upgrade-modal', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var $modal = $('#cg-upgrade-modal');
        if ($modal.length) {
            $modal.show();
            return;
        }
        var pricing = (typeof cg_admin_params !== 'undefined' && cg_admin_params.pricing_url)
            ? cg_admin_params.pricing_url : '';
        if (pricing) {
            window.open(pricing, '_blank', 'noopener');
        }
    });

    $(document).on('click', '#cg-upgrade-modal .cg-upgrade-modal-close', function () {
        $('#cg-upgrade-modal').hide();
    });
    $(document).on('click', '#cg-upgrade-modal', function (e) {
        if ($(e.target).is('#cg-upgrade-modal')) {
            $('#cg-upgrade-modal').hide();
        }
    });
    $(document).on('keydown.cgUpgradeModal', function (e) {
        var $modal = $('#cg-upgrade-modal');
        if (e.key === 'Escape' && $modal.length && $modal.is(':visible')) {
            $modal.hide();
        }
    });

    async function handleRowAction(button, confirmation, action, nonce, extraData = {}) {
        const $button = $(button);
        const entryId = $button.data('id');
        const originalButtonHtml = $button.html();

        if (!entryId) return;

        if (confirmation) {
            const ok = await cgConfirm.show({
                title: 'Confirm Action',
                message: confirmation,
                confirmText: 'Yes, proceed',
                danger: true
            });
            if (!ok) return;
        }

        $button.prop('disabled', true).html('<span class="spinner is-active" style="float:left;margin:0 5px;"></span>');

        let data = { action, nonce, entry_id: entryId, ...extraData };

        $.post(cg_admin_params.ajax_url, data)

            .done(function (response) {

                if (response.success) {
                    cgToast.success(response.data.message);
                    // Close the modal if the action was triggered from inside it
                    if ($button.closest('#cg-details-modal').length) {
                        $('#cg-details-modal').hide();
                    }
                    if (action === 'cg_recover_order' && response.data.edit_order_url) {
                        window.location.href = response.data.edit_order_url;
                    } else {

                        $('#cg-entry-row-' + entryId).fadeOut(500, function () {

                            $(this).remove();
                        });
                    }
                } else {
                    cgToast.error(response.data.message || 'An unknown error occurred.');
                    $button.prop('disabled', false).html(originalButtonHtml);
                }

            }).fail(function () {

                cgToast.error('An AJAX error occurred. Please check the browser console.');
                $button.prop('disabled', false).html(originalButtonHtml);
            });
    }


    $(document).on('click', '.cg-recover-order', function (e) {

        e.preventDefault();
        handleRowAction(this, 'Are you sure you want to recover this as a new WooCommerce order?', 'cg_recover_order', cg_admin_params.recover_order_nonce);
    });


    $(document).on('click', '.cg-mark-hold', async function (e) {

        e.preventDefault();
        const button = this;
        const followUpDate = await cgPrompt.show({
            title: 'Set Follow-Up Date',
            message: 'Select a follow-up date, or leave blank:',
            inputType: 'date',
            defaultValue: new Date().toISOString().slice(0, 10)
        });
        if (followUpDate === null) return;
        if (followUpDate !== "" && !/^\d{4}-\d{2}-\d{2}$/.test(followUpDate)) {
            cgToast.warning('Invalid date format. Please use YYYY-MM-DD or leave it blank.');
            return;
        }
        handleRowAction(button, false, 'cg_mark_hold', cg_admin_params.mark_hold_nonce, { follow_up_date: followUpDate });
    });


    $(document).on('click', '.cg-mark-cancelled', function (e) {

        e.preventDefault();
        handleRowAction(this, 'Are you sure you want to mark this entry as cancelled?', 'cg_mark_cancelled', cg_admin_params.mark_cancelled_nonce);
    });


    $(document).on('click', '.cg-reopen-checkout', function (e) {

        e.preventDefault();
        handleRowAction(this, 'Are you sure you want to re-open this entry to "Incomplete"?', 'cg_reopen_checkout', cg_admin_params.reopen_checkout_nonce);
    });


    $(document).on('click', '.cg-edit-follow-up-date', async function (e) {

        e.preventDefault();
        const $button = $(this);
        const entryId = $button.data('id');
        const currentFollowUpDate = $button.data('current-date') || '';

        const newFollowUpDate = await cgPrompt.show({
            title: 'Edit Follow-Up Date',
            message: 'Select a new follow-up date or clear to remove:',
            inputType: 'date',
            defaultValue: currentFollowUpDate
        });
        if (newFollowUpDate === null) return;
        if (newFollowUpDate !== "" && !/^\d{4}-\d{2}-\d{2}$/.test(newFollowUpDate)) {
            cgToast.warning('Invalid date format. Please use YYYY-MM-DD or leave empty to clear.');
            return;
        }

        const originalButtonHtml = $button.html();
        $button.prop('disabled', true).html('...');

        $.post(cg_admin_params.ajax_url, {
            action: 'cg_edit_follow_up_date',
            nonce: cg_admin_params.edit_follow_up_date_nonce,
            entry_id: entryId,
            new_follow_up_date: newFollowUpDate

        }).done(function (response) {

            if (response.success) {
                cgToast.success(response.data.message);
                $('.cg-follow-up-date-cell-' + entryId).text(response.data.new_date_formatted);
                $button.data('current-date', newFollowUpDate);
            } else {
                cgToast.error(response.data.message || 'Could not update date.');
            }

        }).fail(function () {
            cgToast.error('An AJAX error occurred while updating the date.');
        }).always(function () {

            $button.prop('disabled', false).html(originalButtonHtml);
        });
    });


    // =====================================================================
    // Checkout Tab Pagination Controllers
    // =====================================================================

    var checkoutPaginationControllers = {};

    // Maps tab names to AJAX action names and nonces
    var tabConfig = {
        'incomplete': {
            action: 'cg_fetch_incomplete_entries',
            nonce: cg_admin_params.fetch_incomplete_nonce,
            defaultSort: 'updated_at',
            filterField: 'updated_at'
        },
        'recovered': {
            action: 'cg_fetch_recovered_entries',
            nonce: cg_admin_params.fetch_recovered_nonce,
            defaultSort: 'updated_at',
            filterField: 'updated_at'
        },
        'cancelled': {
            action: 'cg_fetch_cancelled_entries',
            nonce: cg_admin_params.fetch_cancelled_nonce,
            defaultSort: 'updated_at',
            filterField: 'updated_at'
        },
        'hold': {
            action: 'cg_fetch_follow_up_entries',
            nonce: cg_admin_params.fetch_follow_up_nonce,
            defaultSort: 'follow_up_date',
            filterField: 'follow_up_date'
        },
        'blocked_orders': {
            action: 'cg_fetch_blocked_orders',
            nonce: cg_admin_params.fetch_blocked_orders_nonce,
            defaultSort: 'blocked_at',
            filterField: 'blocked_at'
        }
    };

    // Helper: get current active filter range + date range for a tab
    function getTabFilterData(tabName) {
        var pane = $('.cg-tab-pane[data-tab-content="' + tabName + '"]');
        var filterGroup = pane.find('.cg-list-page-filters');
        var data = {};

        if (filterGroup.length) {
            var activeBtn = filterGroup.find('.cg-filter-buttons button.active');
            if (activeBtn.length) {
                data.range = activeBtn.data('range');
            } else {
                data.range = 'all';
            }

            var prefix = 'cg_' + tabName;
            var startDate = $('#' + prefix + '_start_date').val();
            var endDate = $('#' + prefix + '_end_date').val();
            if (startDate && endDate) {
                data.range = 'custom';
                data.start_date = startDate;
                data.end_date = endDate;
            }
        } else {
            // Fallback: no filter group found, default to all
            data.range = 'all';
        }

        return data;
    }

    // Initialize or get a pagination controller for a tab
    function getOrCreatePaginationController(tabName) {
        if (checkoutPaginationControllers[tabName]) {
            return checkoutPaginationControllers[tabName];
        }

        var config = tabConfig[tabName];
        if (!config) return null;

        var ctrl = cgPagination.create(tabName, {
            containerSelector: '#cg-' + tabName + '-pagination',
            tbodySelector: '#cg-table-body-' + tabName,
            loadingSelector: '#cg-' + tabName + '-loading',
            tableContainerSelector: '#cg-' + tabName + '-table-container',
            fetchAction: config.action,
            fetchNonce: config.nonce,
            defaultSort: config.defaultSort,
            defaultDir: 'DESC',
            getFilterData: function () {
                return getTabFilterData(tabName);
            }
        });

        checkoutPaginationControllers[tabName] = ctrl;
        return ctrl;
    }

    // Legacy wrapper — still used by filter buttons and date pickers
    function fetchEntriesForTable(pagePrefix, range, startDate, endDate) {
        var ctrl = getOrCreatePaginationController(pagePrefix);
        if (ctrl) {
            // Reset to page 1 when filters change
            var st = ctrl.getState();
            st.page = 1;
            ctrl.fetch();
        }
    }


    $('.cg-list-page-filters .cg-filter-buttons button').on('click', function () {
        const $button = $(this);
        const filterGroup = $button.closest('.cg-list-page-filters');
        const pagePrefix = filterGroup.data('page-prefix');

        if (!pagePrefix || pagePrefix === 'dashboard') { // Do not run on main dashboard page
            return;
        }

        filterGroup.find('.cg-filter-buttons button').removeClass('active');
        $button.addClass('active');
        filterGroup.find('[id$="_start_date"], [id$="_end_date"]').val('');
        fetchEntriesForTable(pagePrefix, $button.data('range'));
    });


    $('.cg-list-page-filters .button-primary').on('click', function () {
        const filterGroup = $(this).closest('.cg-list-page-filters');
        const pagePrefix = filterGroup.data('page-prefix');

        if (pagePrefix === 'dashboard') {
            return;
        }

        const startDate = filterGroup.find(`#cg_${pagePrefix}_start_date`).val();
        const endDate = filterGroup.find(`#cg_${pagePrefix}_end_date`).val();

        if (startDate && endDate) {
            if (new Date(startDate) > new Date(endDate)) {
                cgToast.warning('Start date cannot be after end date.');
                return;
            }
            filterGroup.find('.cg-filter-buttons button').removeClass('active');
            fetchEntriesForTable(pagePrefix, 'custom', startDate, endDate);
        } else {
            cgToast.warning('Please select both a start and end date.');
        }
    });

    // Search input debounce handler
    var searchTimers = {};
    $(document).on('input', '.cg-table-search-input', function () {
        var $input = $(this);
        var pagePrefix = $input.data('page-prefix');
        if (!pagePrefix) return;

        clearTimeout(searchTimers[pagePrefix]);
        searchTimers[pagePrefix] = setTimeout(function () {
            var ctrl = getOrCreatePaginationController(pagePrefix);
            if (ctrl) {
                ctrl.setSearch($input.val().trim());
            }
        }, 400);
    });

    // Sortable header click handler
    $(document).on('click', '.cg-sortable', function () {
        var $th = $(this);
        var sortCol = $th.data('sort-col');
        if (!sortCol) return;

        // Find which tab this table belongs to
        var $table = $th.closest('table');
        var tbodyId = $table.find('tbody').attr('id') || '';
        var tabName = tbodyId.replace('cg-table-body-', '');
        if (!tabName) return;

        var ctrl = getOrCreatePaginationController(tabName);
        if (!ctrl) return;

        var state = ctrl.getState();
        var newDir = (state.sortCol === sortCol && state.sortDir === 'DESC') ? 'ASC' : 'DESC';

        // Update visual indicators
        $table.find('.cg-sortable').removeClass('cg-sortable--asc cg-sortable--desc');
        $th.addClass(newDir === 'ASC' ? 'cg-sortable--asc' : 'cg-sortable--desc');

        ctrl.setSort(sortCol, newDir);
    });


    // === Checkout Tabs (Incomplete / Follow Up / Cancelled / Recovered / Blocked Orders) ===
    if ($('.cg-checkout-tabs').length) {

        // Auto-activate tab from URL param (e.g. ?tab=hold)
        const urlParams = new URLSearchParams(window.location.search);
        const tabParam = urlParams.get('tab');

        $('.cg-checkout-tabs .nav-tab').on('click', function (e) {
            e.preventDefault();
            const $tab = $(this);
            const tabName = $tab.data('tab');

            // Switch active tab
            $('.cg-checkout-tabs .nav-tab').removeClass('nav-tab-active').attr('aria-selected', 'false');
            $tab.addClass('nav-tab-active').attr('aria-selected', 'true');

            // Switch active pane
            $('.cg-tab-pane').hide();
            $(`.cg-tab-pane[data-tab-content="${tabName}"]`).show();

            // Initialize pagination controller on first visit and fetch
            if (!checkoutPaginationControllers[tabName]) {
                var ctrl = getOrCreatePaginationController(tabName);
                if (ctrl) {
                    ctrl.fetch();
                }
            }
        });

        // Trigger tab from URL param on page load
        if (tabParam && $(`.cg-checkout-tabs .nav-tab[data-tab="${tabParam}"]`).length) {
            $(`.cg-checkout-tabs .nav-tab[data-tab="${tabParam}"]`).trigger('click');
        }

        // Initialize the default (incomplete) tab pagination on page load
        var defaultCtrl = getOrCreatePaginationController('incomplete');
        if (defaultCtrl) {
            defaultCtrl.fetch();
        }
    }

    // === Generic Tab Handler for Fraud Blocker & Courier Analytics ===
    $('.cg-fraud-tabs, .cg-courier-tabs').each(function () {
        const $tabNav = $(this);
        const paneClass = $tabNav.hasClass('cg-fraud-tabs') ? '.cg-fraud-pane' : '.cg-courier-pane';

        const urlParams = new URLSearchParams(window.location.search);
        const tabParam = urlParams.get('tab');

        $tabNav.find('.nav-tab').on('click', function (e) {
            e.preventDefault();
            const $tab = $(this);
            const tabName = $tab.data('tab');

            $tabNav.find('.nav-tab').removeClass('nav-tab-active').attr('aria-selected', 'false');
            $tab.addClass('nav-tab-active').attr('aria-selected', 'true');

            $(paneClass).hide();
            $(paneClass + '[data-tab-content="' + tabName + '"]').show();
        });

        if (tabParam && $tabNav.find('.nav-tab[data-tab="' + tabParam + '"]').length) {
            $tabNav.find('.nav-tab[data-tab="' + tabParam + '"]').trigger('click');
        }
    });


    if ($('#cgOrderStatusChart').length) {
        let cgChartInstance = null;
        let cgTrendChartInstance = null;
        var dashboardAutoRefreshTimer = null;
        var currentDashboardRange = 'today';
        var currentDashboardStartDate = null;
        var currentDashboardEndDate = null;

        function renderTrendChart(trendData) {
            var canvas = document.getElementById('cgTrendChart');
            if (!canvas || typeof Chart === 'undefined') return;

            // Build date-keyed map: { '2026-02-21': { incomplete: 3, recovered: 1, ... } }
            var dateMap = {};
            var statuses = ['incomplete', 'recovered', 'hold', 'cancelled'];

            if (trendData && trendData.length) {
                trendData.forEach(function (item) {
                    if (!dateMap[item.date]) {
                        dateMap[item.date] = { incomplete: 0, recovered: 0, hold: 0, cancelled: 0 };
                    }
                    if (dateMap[item.date][item.status] !== undefined) {
                        dateMap[item.date][item.status] = item.count;
                    }
                });
            }

            // Sort dates
            var dates = Object.keys(dateMap).sort();

            // If no data, show a single "No data" point
            if (dates.length === 0) {
                if (cgTrendChartInstance) {
                    cgTrendChartInstance.data.labels = ['No data'];
                    cgTrendChartInstance.data.datasets.forEach(function (ds) { ds.data = [0]; });
                    cgTrendChartInstance.update();
                }
                return;
            }

            // Format date labels
            var labels = dates.map(function (d) {
                var parts = d.split('-');
                return parts[1] + '/' + parts[2];
            });

            var colors = {
                incomplete: { border: 'rgba(153, 102, 255, 1)', bg: 'rgba(153, 102, 255, 0.15)' },
                recovered: { border: 'rgba(75, 192, 192, 1)', bg: 'rgba(75, 192, 192, 0.15)' },
                hold: { border: 'rgba(255, 206, 86, 1)', bg: 'rgba(255, 206, 86, 0.15)' },
                cancelled: { border: 'rgba(255, 99, 132, 1)', bg: 'rgba(255, 99, 132, 0.15)' }
            };

            var datasets = statuses.map(function (status) {
                return {
                    label: status.charAt(0).toUpperCase() + status.slice(1),
                    data: dates.map(function (d) { return dateMap[d][status]; }),
                    borderColor: colors[status].border,
                    backgroundColor: colors[status].bg,
                    borderWidth: 2,
                    fill: true,
                    tension: 0.3,
                    pointRadius: dates.length > 15 ? 2 : 4,
                    pointHoverRadius: 6
                };
            });

            var chartData = { labels: labels, datasets: datasets };
            var chartOptions = {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'top', labels: { usePointStyle: true, padding: 16 } },
                    tooltip: { mode: 'index', intersect: false }
                },
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true, ticks: { stepSize: 1, precision: 0 } }
                }
            };

            if (cgTrendChartInstance) {
                cgTrendChartInstance.data = chartData;
                cgTrendChartInstance.update();
            } else {
                var ctx = canvas.getContext('2d');
                cgTrendChartInstance = new Chart(ctx, {
                    type: 'line',
                    data: chartData,
                    options: chartOptions
                });
            }
        }

        function updateDashboardStats(stats) {
            $('#cg-stat-incomplete-count').text(stats.incomplete.count);
            $('#cg-stat-incomplete-value').html(stats.incomplete.value_formatted);
            $('#cg-stat-recovered-count').text(stats.recovered.count);
            $('#cg-stat-recovered-value').html(stats.recovered.value_formatted);
            $('#cg-stat-hold-count').text(stats.hold.count);
            $('#cg-stat-hold-value').html(stats.hold.value_formatted);
            $('#cg-stat-cancelled-count').text(stats.cancelled.count);
            $('#cg-stat-cancelled-value').html(stats.cancelled.value_formatted);

            const chartData = {
                labels: [`Incomplete (${stats.incomplete.count})`, `Recovered (${stats.recovered.count})`, `Hold (${stats.hold.count})`, `Cancelled (${stats.cancelled.count})`],
                datasets: [{
                    label: 'Checkout Statuses',
                    data: [stats.incomplete.count, stats.recovered.count, stats.hold.count, stats.cancelled.count],
                    backgroundColor: ['rgba(153, 102, 255, 0.7)', 'rgba(75, 192, 192, 0.7)', 'rgba(255, 206, 86, 0.7)', 'rgba(255, 99, 132, 0.7)'],
                    borderColor: ['rgba(153, 102, 255, 1)', 'rgba(75, 192, 192, 1)', 'rgba(255, 206, 86, 1)', 'rgba(255, 99, 132, 1)'],
                    borderWidth: 1
                }]
            };

            if (cgChartInstance) {
                cgChartInstance.data = chartData;
                cgChartInstance.update();
            } else {
                const ctx = document.getElementById('cgOrderStatusChart').getContext('2d');
                cgChartInstance = new Chart(ctx, { type: 'pie', data: chartData, options: { responsive: true } });
            }
        }

        function fetchDashboardData(range, startDate, endDate) {
            startDate = startDate || null;
            endDate = endDate || null;

            // Track current filter state for auto-refresh
            currentDashboardRange = range;
            currentDashboardStartDate = startDate;
            currentDashboardEndDate = endDate;

            $('#cg-dashboard-loading').show();
            $('.cg-stat-box p').text('...');
            $('.cg-dashboard-chart-container, .cg-dashboard-counts, .cg-dashboard-values, .cg-dashboard-trend-container').css('opacity', 0.5);

            $.post(cg_admin_params.ajax_url, {
                action: 'cg_fetch_dashboard_data',
                nonce: cg_admin_params.fetch_dashboard_data_nonce,
                range: range,
                start_date: startDate,
                end_date: endDate

            }).done(function (response) {
                if (response.success) {
                    updateDashboardStats(response.data);
                    renderTrendChart(response.data.trend_data || []);
                } else {
                    cgToast.error('Error loading dashboard data: ' + (response.data.message || 'Unknown error.'));
                }

            }).fail(function () {
                cgToast.error('AJAX error while loading dashboard data.');
            }).always(function () {

                $('#cg-dashboard-loading').hide();
                $('.cg-dashboard-chart-container, .cg-dashboard-counts, .cg-dashboard-values, .cg-dashboard-trend-container').css('opacity', 1);
            });
        }

        const dashboardFilterGroup = $('.cg-dashboard-filters');


        dashboardFilterGroup.find('.cg-filter-buttons button').on('click', function () {

            const $button = $(this);
            dashboardFilterGroup.find('.cg-filter-buttons button').removeClass('active');
            $button.addClass('active');

            dashboardFilterGroup.find('.cg-date-input').val('');

            fetchDashboardData($button.data('range'));
        });


        dashboardFilterGroup.find('.cg_dashboard_apply_date_filter').on('click', function () {

            const startDate = dashboardFilterGroup.find('#cg_dashboard_start_date').val();
            const endDate = dashboardFilterGroup.find('#cg_dashboard_end_date').val();

            if (startDate && endDate) {
                if (new Date(startDate) > new Date(endDate)) {
                    cgToast.warning('Start date cannot be after end date.');
                    return;
                }
                dashboardFilterGroup.find('.cg-filter-buttons button').removeClass('active');
                fetchDashboardData('custom', startDate, endDate);
            } else {
                cgToast.warning('Please select both a start and end date.');
            }
        });

        fetchDashboardData('today');

        // Auto-refresh dashboard every 60 seconds
        dashboardAutoRefreshTimer = setInterval(function () {
            fetchDashboardData(currentDashboardRange, currentDashboardStartDate, currentDashboardEndDate);
        }, 60000);
    }


    let courierCheckRequest = null;

    function getAjaxErrorMessage(xhr, fallbackMessage) {
        if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
            return xhr.responseJSON.data.message;
        }

        if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
            return xhr.responseJSON.message;
        }

        return fallbackMessage || 'An AJAX error occurred.';
    }

    function performCourierCheck(phoneOverride) {
        const $form = $('#cg_courier_check_form');
        const $button = $('#cg_check_ratio_btn');
        const $spinner = $form.find('.spinner');
        const $phoneInput = $('#cg_customer_phone');
        const resultsContainer = $('#cg-courier-results-container');
        const nonce = $('#cg_courier_nonce').val();

        const phoneNumber = phoneOverride || $phoneInput.val().trim();

        if (!phoneNumber) {
            $phoneInput.focus().addClass('error-shake');
            setTimeout(() => $phoneInput.removeClass('error-shake'), 500);
            return;
        }

        if (phoneOverride) {
            $phoneInput.val(phoneOverride);
        }

        if ($button.prop('disabled')) {
            return;
        }

        if (courierCheckRequest && courierCheckRequest.readyState !== 4) {
            courierCheckRequest.abort();
        }

        $spinner.addClass('is-active');
        $button.prop('disabled', true);
        $phoneInput.prop('disabled', true);
        resultsContainer.fadeOut(200);

        // Mark active sidebar item
        $('#cg-recent-checks-list .cg-recent-item').removeClass('active');
        $('#cg-recent-checks-list .cg-recent-item').filter(function () {
            return $(this).attr('data-phone') === phoneNumber;
        }).addClass('active');

        courierCheckRequest = $.post(cg_admin_params.ajax_url, {
            action: 'cg_check_courier_success',
            cg_courier_nonce: nonce,
            phone_number: phoneNumber,
            order_id: 0
        })
            .done(function (response) {
                if (response.success) {
                    resultsContainer.html(response.data.html).fadeIn(200);
                    initSuccessRatioChart();

                    if (response.data.recent_entry) {
                        updateRecentChecksSidebar(response.data.recent_entry);
                    }
                } else {
                    if (response.data && response.data.limit_reached) {
                        cgToast.errorHtml(response.data.message, 10000);
                    } else {
                        cgToast.error(response.data ? response.data.message : 'An unknown error occurred.');
                    }
                    resultsContainer.fadeIn(200);
                }
            })
            .fail(function (xhr, textStatus) {
                if (textStatus === 'abort') {
                    return;
                }

                if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.limit_reached) {
                    cgToast.errorHtml(xhr.responseJSON.data.message, 10000);
                } else {
                    const errorMessage = getAjaxErrorMessage(xhr, 'Could not complete courier check. Please try again.');
                    if (errorMessage.indexOf('<a ') !== -1) {
                        cgToast.errorHtml(errorMessage, 10000);
                    } else {
                        cgToast.error(errorMessage);
                    }
                }
                resultsContainer.fadeIn(200);
            })
            .always(function () {
                courierCheckRequest = null;
                $spinner.removeClass('is-active');
                $button.prop('disabled', false);
                $phoneInput.prop('disabled', false);
            });
    }

    function updateRecentChecksSidebar(entry) {
        const $list = $('#cg-recent-checks-list');
        if (!$list.length) return;

        $list.find('#cg-recent-empty-msg').remove();
        const phone = String(entry.phone);
        $list.find('.cg-recent-item').filter(function () {
            return $(this).attr('data-phone') === phone;
        }).remove();

        // Built with .attr()/.text()/.css() so the phone, rate and colour are escaped.
        const newItem = $('<li class="cg-recent-item active"></li>').attr('data-phone', phone).append(
            $('<div class="cg-recent-item-info"></div>').append(
                $('<span class="cg-recent-phone"></span>').text(phone),
                $('<span class="cg-recent-time">Just now</span>')
            ),
            $('<span class="cg-recent-rate"></span>')
                .css('color', entry.risk_color)
                .text(entry.success_rate + '%')
        );

        $list.find('.cg-recent-item').removeClass('active');
        $list.prepend(newItem);

        const $items = $list.find('.cg-recent-item');
        if ($items.length > 20) {
            $items.slice(20).remove();
        }
    }

    // Sidebar click handler
    $(document).on('click', '.cg-recent-item', function (e) {
        e.preventDefault();
        const phone = $(this).data('phone');
        if (phone) {
            performCourierCheck(String(phone));
        }
    });

    $('#cg_courier_check_form').on('submit', function (e) {
        e.preventDefault();
        performCourierCheck();
    });

    $(document).on('click', '#cg_check_ratio_btn', function (e) {
        e.preventDefault();
        $('#cg_courier_check_form').trigger('submit');
    });

    // Unified Refresh button handler (works on both Courier Analytics page and Order meta box)
    $(document).on('click', '.cg-refresh-courier-data', function (e) {
        e.preventDefault();
        const $button = $(this);
        const phoneNumber = $button.data('phone');
        const orderId = $button.data('order-id') || 0;

        if (!phoneNumber) return;

        if (orderId > 0) {
            // Order meta box context: refresh into the meta box container
            const $container = $('#cg_order_detail_ratio_container');
            const nonce = cg_admin_params.courier_analytics_nonce;

            $container.html('<span class="spinner is-active" style="display:block; margin: 10px auto;"></span>');

            $.post(cg_admin_params.ajax_url, {
                action: 'cg_check_courier_success',
                cg_courier_nonce: nonce,
                phone_number: phoneNumber,
                order_id: orderId,
                force_refresh: 1
            }).done(function (response) {
                if (response.success) {
                    $container.fadeOut(200, function () {
                        $(this).html(response.data.html).fadeIn(200);
                        initSuccessRatioChart();
                    });
                } else {
                    $container.html('<p>' + (response.data && response.data.message ? response.data.message : 'Error loading data.') + '</p>');
                }
            }).fail(function () {
                $container.html('<p>AJAX request failed.</p>');
            });
        } else {
            // Courier Analytics page context: re-run the check with force_refresh
            const resultsContainer = $('#cg-courier-results-container');
            const nonce = $('#cg_courier_nonce').val();

            resultsContainer.fadeOut(200);

            $.post(cg_admin_params.ajax_url, {
                action: 'cg_check_courier_success',
                cg_courier_nonce: nonce,
                phone_number: phoneNumber,
                order_id: 0,
                force_refresh: 1
            }).done(function (response) {
                if (response.success) {
                    resultsContainer.html(response.data.html).fadeIn(200);
                    initSuccessRatioChart();

                    if (response.data.recent_entry) {
                        updateRecentChecksSidebar(response.data.recent_entry);
                    }
                } else {
                    cgToast.error(response.data ? response.data.message : 'An unknown error occurred.');
                    resultsContainer.fadeIn(200);
                }
            }).fail(function (xhr) {
                const errorMessage = getAjaxErrorMessage(xhr, 'Could not refresh courier data. Please try again.');
                if (errorMessage.indexOf('<a ') !== -1) {
                    cgToast.errorHtml(errorMessage, 10000);
                } else {
                    cgToast.error(errorMessage);
                }
                resultsContainer.fadeIn(200);
            });
        }
    });


    $(document).on('click', '.cg-check-order-ratio, .cg-refresh-order-ratio', function (e) {

        e.preventDefault();

        const $button = $(this);
        const $container = $button.closest('.cg-success-ratio-container');
        const phoneNumber = $button.data('phone');
        const nonce = cg_admin_params.order_list_nonce;

        if (!phoneNumber || !nonce) {
            cgToast.warning('Could not perform check. Phone number or security token is missing.');
            return;
        }

        $container.html('<span class="spinner is-active" style="float:left;"></span>');

        $.post(cg_admin_params.ajax_url, {

            action: 'cg_check_order_success_ratio',
            cg_order_nonce: nonce,
            phone_number: phoneNumber,
            order_id: $container.data('order-id')
        })
            .done(function (response) {

                if (response.success) {
                    $container.html(response.data.html);
                } else {
                    $container.html('<em>Error</em>');
                }
            })

            .fail(function () {

                $container.html('<em>Request Failed</em>');
            });
    });


    $(document).on('click', '.cg-check-order-ratio', function (e) {

        e.preventDefault();
        const $button = $(this);
        const $container = $button.closest('.cg-success-ratio-container');
        const phoneNumber = $button.data('phone');
        const orderId = $button.data('order-id');
        const nonce = cg_admin_params.order_list_nonce;

        if (!phoneNumber || !orderId) { cgToast.warning('Missing data for check.'); return; }
        $container.html('<span class="spinner is-active" style="float:left;"></span>');

        $.post(cg_admin_params.ajax_url, {

            action: 'cg_check_order_success_ratio',
            cg_order_nonce: nonce,
            phone_number: phoneNumber,
            order_id: orderId
        })
            .done(function (response) {

                if (response.success) {
                    $container.hide().html(response.data.html).fadeIn(300);
                } else { $container.html('<em>' + (response.data && response.data.message ? response.data.message : 'Error') + '</em>'); }
            })

            .fail(function (jqXHR) { $container.html('<em>' + (jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message ? jqXHR.responseJSON.data.message : 'Request Failed') + '</em>'); });
    });

    $(document).on('click', '.cg-meta-box-trigger', function (e) {

        e.preventDefault();
        const $button = $(this);
        const $container = $('#cg_order_detail_ratio_container');
        const phoneNumber = $button.data('phone');
        const orderId = $button.data('order-id');
        const nonce = cg_admin_params.courier_analytics_nonce;

        if (!phoneNumber || !orderId) { cgToast.warning('Missing data for check.'); return; }
        $container.html('<span class="spinner is-active" style="display:block; margin: 10px auto;"></span>');

        $.post(cg_admin_params.ajax_url, {

            action: 'cg_check_courier_success',
            cg_courier_nonce: nonce,
            phone_number: phoneNumber,
            order_id: orderId,
            force_refresh: 1
        })
            .done(function (response) {
                if (response.success) {
                    $container.fadeOut(200, function () {

                        $(this).html(response.data.html).fadeIn(200);
                        initSuccessRatioChart(); // Call the chart function
                    });
                } else { $container.html('<p>' + (response.data && response.data.message ? response.data.message : 'Error loading data.') + '</p>'); }
            })

            .fail(function (jqXHR) { $container.html('<p>' + (jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message ? jqXHR.responseJSON.data.message : 'AJAX request failed.') + '</p>'); });

    });


    /**
     * NEW: Function to initialize the doughnut chart for the success ratio.
     */
    function initSuccessRatioChart() {
        const canvas = $('#cg-success-ratio-doughnut-chart');
        if (canvas.length === 0 || typeof Chart === 'undefined') {
            return;
        }

        const rate = parseInt(canvas.data('rate'), 10);
        const color = canvas.data('level-color') || '#4CAF50';
        const remaining = 100 - rate;

        const chartData = {
            datasets: [{
                data: [rate, remaining],
                backgroundColor: [color, '#e9ecef'],
                borderWidth: 0,
                cutout: '80%', // Makes the ring thinner
            }]
        };

        const chartOptions = {
            responsive: true,
            maintainAspectRatio: false,
            animation: {
                animateScale: true,
                animateRotate: true
            },
            plugins: {
                tooltip: {
                    enabled: false // Disable tooltips on hover
                }
            },
            events: [] // Disable all events on the chart
        };

        new Chart(canvas, {
            type: 'doughnut',
            data: chartData,
            options: chartOptions,
        });
    }

    if ($('.cg-fraud-blocker-wrap').length) {




        $('.cg-blocker-search').on('keyup input', function () {

            const searchTerm = $(this).val().toLowerCase();
            const listId = $(this).data('list-id');
            const $list = $('#' + listId);
            let itemsFound = 0;


            $list.find('li').each(function () {

                const $item = $(this);
                if ($item.hasClass('cg-no-items')) {
                    return;
                }

                const itemText = $item.find('strong').text().toLowerCase();

                if (itemText.indexOf(searchTerm) > -1) {
                    $item.show();
                    itemsFound++;
                } else {
                    $item.hide();
                }
            });

            $list.find('.cg-no-results').remove(); // Remove old message first
            if (itemsFound === 0 && $list.find('.cg-no-items').length === 0) {
                $list.append('<li class="cg-no-results">No items match your search.</li>');
            }
        });


        $('.cg-blocker-form').on('submit', function (e) {

            e.preventDefault();

            const $form = $(this);
            const $button = $form.find('.button-primary');
            const $spinner = $form.find('.spinner');
            const blockType = $form.data('block-type');
            const list = $('#cg-blocked-list-' + blockType);

            $button.prop('disabled', true);
            $spinner.addClass('is-active');

            const formData = {
                action: 'cg_add_blocked_item',
                nonce: $form.find('input[name="nonce"]').val(),
                block_type: blockType,
                value: blockType === 'address'
                    ? JSON.stringify({
                        address_1: $form.find('input[name="address_1"]').val(),
                        city: $form.find('input[name="city"]').val(),
                        postcode: $form.find('input[name="postcode"]').val()
                    })
                    : $form.find('input[name="value"]').val(),
                reason: $form.find('textarea[name="reason"]').val(),
                expires_in: $form.find('select[name="expires_in"]').val() || '0'
            };

            $.post(cg_admin_params.ajax_url, formData)

                .done(function (response) {

                    if (response.success) {
                        list.find('.cg-no-items').remove();
                        list.prepend(response.data.html);
                        $form[0].reset();
                        cgToast.success('Item added successfully.');
                    } else {
                        if (response.data && response.data.limit_reached) {
                            cgToast.errorHtml(response.data.message, 10000);
                        } else {
                            cgToast.error('Error: ' + (response.data && response.data.message ? response.data.message : 'Unknown error.'));
                        }
                    }
                })

                .fail(function (xhr) {

                    let errorMessage = 'An unexpected error occurred. Please try again.';
                    if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.limit_reached) {
                        cgToast.errorHtml(xhr.responseJSON.data.message, 10000);
                    } else {
                        if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                            errorMessage = xhr.responseJSON.data.message;
                        }
                        cgToast.error('Error: ' + errorMessage);
                    }
                })

                .always(function () {

                    $button.prop('disabled', false);
                    $spinner.removeClass('is-active');
                });
        });


        $(document).on('click', '.cg-delete-item-ajax', async function (e) {

            e.preventDefault();

            const ok = await cgConfirm.show({
                title: 'Delete Blocked Item',
                message: 'Are you sure you want to delete this item?',
                confirmText: 'Delete',
                danger: true
            });
            if (!ok) return;

            const $link = $(this);
            const itemID = $link.data('item-id');
            const blockType = $link.data('block-type');
            const $listItem = $('#cg-blocked-item-' + itemID);
            const list = $('#cg-blocked-list-' + blockType);

            $listItem.css('opacity', '0.5');

            $.post(cg_admin_params.ajax_url, {
                action: 'cg_delete_blocked_item',
                nonce: $link.data('nonce'),
                item_id: itemID,
                block_type: blockType
            })
                .done(function (response) {
                    if (response.success) {
                        $listItem.fadeOut(300, function () {
                            $(this).remove();
                            if (list.children().length === 0) {
                                list.html('<li class="cg-no-items">No items currently blocked.</li>');
                            }
                        });
                    } else {
                        $listItem.css('opacity', '1');
                        cgToast.error(response.data.message || 'Could not delete item.');
                    }
                })

                .fail(function () {
                    $listItem.css('opacity', '1');
                    cgToast.error('An unexpected error occurred. Please try again.');
                });
        });

        // =====================================================================
        // Bulk Select / Delete for Blocked Lists
        // =====================================================================

        // Update the bulk action bar state (count + button enabled/disabled)
        function updateBulkActionBar($bulkBar) {
            var listId = $bulkBar.data('list-id');
            var $list = $('#' + listId);
            var checkedCount = $list.find('.cg-bulk-checkbox:checked').length;
            var totalCount = $list.find('.cg-bulk-checkbox').length;
            var $btn = $bulkBar.find('.cg-bulk-delete-btn');
            var $count = $bulkBar.find('.cg-bulk-count');
            var $selectAll = $bulkBar.find('.cg-bulk-select-all');

            $btn.prop('disabled', checkedCount === 0);
            if (checkedCount > 0) {
                $count.text(checkedCount + ' selected');
            } else {
                $count.text('');
            }
            $selectAll.prop('checked', totalCount > 0 && checkedCount === totalCount);
            $selectAll.prop('indeterminate', checkedCount > 0 && checkedCount < totalCount);
        }

        // "Select All" checkbox
        $(document).on('change', '.cg-bulk-select-all', function () {
            var $bulkBar = $(this).closest('.cg-bulk-actions');
            var listId = $bulkBar.data('list-id');
            var isChecked = $(this).is(':checked');
            $('#' + listId).find('.cg-bulk-checkbox').prop('checked', isChecked);
            updateBulkActionBar($bulkBar);
        });

        // Individual checkbox change
        $(document).on('change', '.cg-bulk-checkbox', function () {
            var $list = $(this).closest('.cg-blocked-list');
            var $bulkBar = $list.prev('.cg-bulk-actions');
            updateBulkActionBar($bulkBar);
        });

        // "Delete Selected" button
        $(document).on('click', '.cg-bulk-delete-btn', async function (e) {
            e.preventDefault();
            var $bulkBar = $(this).closest('.cg-bulk-actions');
            var listId = $bulkBar.data('list-id');
            var blockType = $bulkBar.data('block-type');
            var nonce = $bulkBar.data('nonce');
            var $list = $('#' + listId);
            var $btn = $(this);
            var $spinner = $bulkBar.find('.spinner');

            var selectedIds = [];
            $list.find('.cg-bulk-checkbox:checked').each(function () {
                selectedIds.push($(this).val());
            });

            if (selectedIds.length === 0) return;

            var ok = await cgConfirm.show({
                title: 'Bulk Delete',
                message: 'Are you sure you want to delete ' + selectedIds.length + ' selected item(s)?',
                confirmText: 'Delete All',
                danger: true
            });
            if (!ok) return;

            $btn.prop('disabled', true);
            $spinner.addClass('is-active');

            $.post(cg_admin_params.ajax_url, {
                action: 'cg_bulk_delete_blocked_items',
                nonce: nonce,
                block_type: blockType,
                item_ids: selectedIds
            }).done(function (response) {
                if (response.success) {
                    // Remove deleted items from the list
                    selectedIds.forEach(function (id) {
                        $('#cg-blocked-item-' + id).fadeOut(300, function () {
                            $(this).remove();
                            // Check if list is now empty
                            if ($list.children(':not(.cg-no-items)').length === 0) {
                                $list.html('<li class="cg-no-items">No items currently blocked.</li>');
                                $bulkBar.hide();
                            }
                        });
                    });
                    // Reset select all
                    $bulkBar.find('.cg-bulk-select-all').prop('checked', false).prop('indeterminate', false);
                    $bulkBar.find('.cg-bulk-count').text('');

                    cgToast.success(response.data.message || 'Items deleted successfully.');
                } else {
                    cgToast.error('Error: ' + response.data.message);
                }
            }).fail(function () {
                cgToast.error('An unexpected error occurred. Please try again.');
            }).always(function () {
                $btn.prop('disabled', false);
                $spinner.removeClass('is-active');
                updateBulkActionBar($bulkBar);
            });
        });
    }

    if ($('body').hasClass('post-type-shop_order')) {


        $(document).on('click', '.cg-block-from-order', function (e) {

            e.preventDefault();
            const $button = $(this),
                blockType = $button.data('block-type'),
                value = $button.data('value'),
                ref = $button.data('ref') || '',
                nonce = $button.data('nonce'),
                deviceInfo = $button.data('device-info') || '';
            $button.prop('disabled', true).html('<span class="spinner is-active" style="float:left; margin: 2px 5px 0 0;"></span> Blocking...');

            var postData = {
                action: 'cg_add_blocked_item',
                nonce,
                block_type: blockType,
                reason: `Blocked from Order #${$('#post_ID').val()}`
            };
            // Email/phone buttons carry a reference only (cg_resolve_block_ref).
            if (ref) {
                postData.ref = ref;
            } else {
                postData.value = value;
            }
            if (blockType === 'device' && deviceInfo) {
                postData.device_info = deviceInfo;
            }

            $.post(cg_admin_params.ajax_url, postData).done(function (response) {

                if (response.success) {
                    const unblockNonce = cg_admin_params.delete_blocker_item_nonce;
                    // Built with .attr() so ref/value/device info are escaped.
                    const $newUnblockButton = $('<button type="button" class="button cg-unblock-from-order"></button>')
                        .attr('data-block-type', blockType)
                        .attr(ref ? 'data-ref' : 'data-value', ref ? ref : value)
                        .attr('data-nonce', unblockNonce)
                        .html('<span class="dashicons dashicons-unlock"></span> Unblock');
                    if (deviceInfo) {
                        $newUnblockButton.attr('data-device-info', deviceInfo);
                    }
                    $button.replaceWith($newUnblockButton);
                } else {
                    cgToast.error(response.data.message || 'Could not block item.');
                    $button.prop('disabled', false).html(`<span class="dashicons dashicons-shield-alt"></span> Block`);
                }

            }).fail(function () {

                cgToast.error('An unexpected error occurred.');
                $button.prop('disabled', false).html(`<span class="dashicons dashicons-shield-alt"></span> Block`);
            });
        });


        $(document).on('click', '.cg-unblock-from-order', function (e) {

            e.preventDefault();
            const $button = $(this),
                blockType = $button.data('block-type'),
                value = $button.data('value'),
                ref = $button.data('ref') || '',
                nonce = $button.data('nonce');
            $button.prop('disabled', true).html('<span class="spinner is-active" style="float:left; margin: 2px 5px 0 0;"></span> Unblocking...');

            var postData = {
                action: 'cg_delete_blocked_item',
                nonce,
                block_type: blockType
            };
            if (ref) {
                postData.ref = ref;
            } else {
                postData.value = value;
            }

            $.post(cg_admin_params.ajax_url, postData).done(function (response) {

                if (response.success) {
                    const newNonce = cg_admin_params.fraud_blocker_nonce;
                    const $newBlockButton = $('<button type="button" class="button cg-block-from-order"></button>')
                        .attr('data-block-type', blockType)
                        .attr(ref ? 'data-ref' : 'data-value', ref ? ref : value)
                        .attr('data-nonce', newNonce)
                        .html('<span class="dashicons dashicons-shield-alt"></span> Block');
                    $button.replaceWith($newBlockButton);
                } else {
                    cgToast.error(response.data.message || 'Could not unblock item.');
                    $button.prop('disabled', false).html('<span class="dashicons dashicons-unlock"></span> Unblock');
                }

            }).fail(function () {

                cgToast.error('An unexpected error occurred.');
                $button.prop('disabled', false).html('<span class="dashicons dashicons-unlock"></span> Unblock');
            });
        });
    }


    $(document).on('click', '.cg-delete-blocked-log', async function (e) {

        e.preventDefault();

        const ok = await cgConfirm.show({
            title: 'Delete Log Entry',
            message: 'Are you sure you want to permanently delete this log entry?',
            confirmText: 'Delete',
            danger: true
        });
        if (!ok) return;

        const $button = $(this);
        const logId = $button.data('log-id');
        const nonce = $button.data('nonce');

        const $row = $('#log-row-' + logId); // Correctly targets the row

        $button.prop('disabled', true).html('<span class="dashicons dashicons-trash"></span>');

        $.post(cg_admin_params.ajax_url, {
            action: 'cg_delete_blocked_log',
            nonce: nonce,
            log_id: logId
        })
            .done(function (response) {
                if (response.success) {
                    $row.css('background-color', '#fee2e2').fadeOut(400, function () {

                        $(this).remove();
                    });
                } else {
                    cgToast.error(response.data.message || 'Could not delete log.');

                    $button.prop('disabled', false);
                }
            })
            .fail(function () {
                cgToast.error('An AJAX error occurred.');
                $button.prop('disabled', false);
            });
    });

    // =====================================================================
    // Inline Page Settings Save (Fraud Blocker & Courier Analytics pages)
    // =====================================================================

    // Fraud Blocker page - Save Settings
    $(document).on('click', '#cg-save-fraud-settings', function () {
        var $btn = $(this),
            $spinner = $btn.next('.spinner');

        $btn.prop('disabled', true);
        $spinner.addClass('is-active');

        $.post(cg_admin_params.ajax_url, {
            action: 'cg_save_page_settings',
            nonce: cg_admin_params.save_page_settings_nonce,
            fields: {
                custom_block_message: $('#cg_custom_block_message').val(),
                fraud_auto_block_enabled: $('#cg_fraud_auto_block_enabled').is(':checked') ? '1' : '0',
                fraud_auto_block_attempts: $('#cg_fraud_auto_block_attempts').val(),
                fraud_auto_block_window: $('#cg_fraud_auto_block_window').val(),
                fraud_auto_block_duration: $('#cg_fraud_auto_block_duration').val(),
                fraud_velocity_enabled: $('#cg_fraud_velocity_enabled').is(':checked') ? '1' : '0',
                fraud_velocity_max_attempts: $('#cg_fraud_velocity_max_attempts').val(),
                fraud_velocity_window: $('#cg_fraud_velocity_window').val(),
                fraud_device_blocking: $('#cg_fraud_device_blocking').is(':checked') ? '1' : '0',
                fraud_device_site_block: $('#cg_fraud_device_site_block').is(':checked') ? '1' : '0',
                fraud_risk_scoring_enabled: $('#cg_fraud_risk_scoring_enabled').is(':checked') ? '1' : '0',
                fraud_risk_score_threshold: $('#cg_fraud_risk_score_threshold').val(),
                duplicate_blocker_enabled: $('#cg_duplicate_blocker_enabled').is(':checked') ? '1' : '0',
                duplicate_blocker_window: $('#cg_duplicate_blocker_window').val(),
                duplicate_blocker_mode: $('#cg_duplicate_blocker_mode').val(),
                duplicate_blocker_match_phone: $('#cg_duplicate_blocker_match_phone').is(':checked') ? '1' : '0',
                duplicate_blocker_match_device: $('#cg_duplicate_blocker_match_device').is(':checked') ? '1' : '0',
                duplicate_blocker_match_ip: $('#cg_duplicate_blocker_match_ip').is(':checked') ? '1' : '0',
                duplicate_blocker_match_email: $('#cg_duplicate_blocker_match_email').is(':checked') ? '1' : '0',
                duplicate_blocker_message: $('#cg_duplicate_blocker_message').val()
            }
        }).done(function (response) {
            if (response.success) {
                cgToast.success(response.data.message || 'Settings saved successfully.');
            } else {
                cgToast.error(response.data.message || 'Failed to save settings.');
            }
        }).fail(function () {
            cgToast.error('An AJAX error occurred.');
        }).always(function () {
            $btn.prop('disabled', false);
            $spinner.removeClass('is-active');
        });
    });

    // Courier Analytics page - Save Ratio Settings
    $(document).on('click', '#cg-save-ratio-settings', function () {
        var $btn = $(this),
            $spinner = $btn.next('.spinner');

        $btn.prop('disabled', true);
        $spinner.addClass('is-active');

        var ratioFields = {
            ratio_blocker_threshold: $('#cg_ratio_blocker_threshold').val(),
            ratio_blocker_grace_period: $('#cg_ratio_blocker_grace_period').val(),
            ratio_blocker_message: $('#cg_ratio_blocker_message').val()
        };
        // Shown to paid plans only.
        if ($('#cg_ratio_action').length) {
            ratioFields.ratio_action = $('#cg_ratio_action').val();
        }

        $.post(cg_admin_params.ajax_url, {
            action: 'cg_save_page_settings',
            nonce: cg_admin_params.save_page_settings_nonce,
            fields: ratioFields
        }).done(function (response) {
            if (response.success) {
                cgToast.success(response.data.message || 'Settings saved successfully.');
            } else {
                cgToast.error(response.data.message || 'Failed to save settings.');
            }
        }).fail(function () {
            cgToast.error('An AJAX error occurred.');
        }).always(function () {
            $btn.prop('disabled', false);
            $spinner.removeClass('is-active');
        });
    });

    // =====================================================================
    // Blocklist Import/Export
    // =====================================================================

    // Export blocklist as CSV download
    $(document).on('click', '.cg-export-btn', function (e) {
        e.preventDefault();
        var $button = $(this);
        var blockType = $button.data('block-type');

        $button.prop('disabled', true).text('Exporting...');

        $.post(cg_admin_params.ajax_url, {
            action: 'cg_export_blocklist',
            nonce: cg_admin_params.export_blocklist_nonce,
            block_type: blockType
        }).done(function (response) {
            if (response.success && response.data.csv) {
                var blob = new Blob([response.data.csv], { type: 'text/csv;charset=utf-8;' });
                var url = URL.createObjectURL(blob);
                var link = document.createElement('a');
                link.href = url;
                link.download = 'checkoutguard-blocked-' + blockType + 's.csv';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                URL.revokeObjectURL(url);
            } else {
                cgToast.error(response.data && response.data.message ? response.data.message : 'Export failed.');
            }
        }).fail(function () {
            cgToast.error('An AJAX error occurred during export.');
        }).always(function () {
            $button.prop('disabled', false).text('Export');
        });
    });

    // Open import modal
    $(document).on('click', '.cg-import-btn', function (e) {
        e.preventDefault();
        var blockType = $(this).data('block-type');
        $('#cg-import-block-type').val(blockType);
        $('#cg-import-csv-data').val('');
        $('#cg-import-modal').show();
    });

    // Submit import
    $(document).on('click', '#cg-import-submit', function (e) {
        e.preventDefault();
        var $button = $(this);
        var $spinner = $button.closest('p').find('.spinner');
        var blockType = $('#cg-import-block-type').val();
        var csvData = $('#cg-import-csv-data').val().trim();

        if (!csvData) {
            cgToast.error('Please paste CSV data first.');
            return;
        }

        $button.prop('disabled', true);
        $spinner.addClass('is-active');

        $.post(cg_admin_params.ajax_url, {
            action: 'cg_import_blocklist',
            nonce: cg_admin_params.import_blocklist_nonce,
            block_type: blockType,
            csv_data: csvData
        }).done(function (response) {
            if (response.success) {
                if (response.data.limit_reached > 0) {
                    // Partial import with limit reached — show HTML message with upgrade links
                    cgToast.warningHtml(response.data.message || 'Import partially completed.', 12000);
                } else {
                    cgToast.success(response.data.message || 'Import completed.');
                }
                // Close the modal and reload the page after a short delay to refresh blocklists
                $('#cg-import-modal').hide();
                setTimeout(function () {
                    location.reload();
                }, 1500);
            } else {
                cgToast.error('Error: ' + (response.data && response.data.message ? response.data.message : 'Import failed.'));
            }
        }).fail(function () {
            cgToast.error('An AJAX error occurred during import.');
        }).always(function () {
            $button.prop('disabled', false);
            $spinner.removeClass('is-active');
        });
    });

    // Cancel import modal
    $(document).on('click', '#cg-import-cancel', function (e) {
        e.preventDefault();
        $('#cg-import-modal').hide();
        $('#cg-import-csv-data').val('');
    });

    // Close import modal on backdrop click
    $(document).on('click', '#cg-import-modal', function (e) {
        if ($(e.target).is('#cg-import-modal')) {
            $('#cg-import-modal').hide();
        }
    });

    // =====================================================================
    // Settings Page — AJAX Sync Button
    // =====================================================================

    $(document).on('click', '#cg-refresh-sync', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var $spinner = $btn.find('.spinner');

        $btn.prop('disabled', true);
        $spinner.addClass('is-active');

        $.post(cg_admin_params.ajax_url, {
            action: 'cg_force_sync_ajax',
            nonce: cg_admin_params.force_sync_nonce
        }).done(function (response) {
            if (response.success) {
                cgToast.success(response.data.message || 'Successfully synced with Headquarters.');
                // Update "Last synced" text
                $('#cg-last-synced-text').text('Last synced: just now');
                // Reload after a brief delay so the user sees the success toast
                setTimeout(function () {
                    location.reload();
                }, 800);
            } else {
                cgToast.error(response.data && response.data.message ? response.data.message : 'Sync failed.');
            }
        }).fail(function () {
            cgToast.error('An AJAX error occurred during sync.');
        }).always(function () {
            $btn.prop('disabled', false);
            $spinner.removeClass('is-active');
        });
    });

    // =====================================================================
    // Popup Block/Unblock Buttons (inside detail modals)
    // =====================================================================

    // Block button inside popup
    $(document).on('click', '.cg-popup-block-btn', function (e) {
        e.preventDefault();
        var $button = $(this);
        var blockType = $button.data('block-type');
        var value = $button.data('value');
        var ref = $button.data('ref') || '';
        var nonce = $button.data('nonce');
        var deviceInfo = $button.data('device-info') || '';

        if (!blockType || (!value && !ref)) return;

        $button.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span>');

        var postData = {
            action: 'cg_add_blocked_item',
            nonce: nonce,
            block_type: blockType,
            reason: 'Blocked from checkout details popup'
        };
        // Email/phone buttons carry a reference only (cg_resolve_block_ref).
        if (ref) {
            postData.ref = ref;
        } else {
            postData.value = value;
        }
        if (blockType === 'device' && deviceInfo) {
            postData.device_info = deviceInfo;
        }

        $.post(cg_admin_params.ajax_url, postData).done(function (response) {
            if (response.success) {
                var unblockNonce = cg_admin_params.delete_blocker_item_nonce;
                $button
                    .removeClass('cg-popup-block-btn')
                    .addClass('cg-popup-unblock-btn')
                    .data('nonce', unblockNonce)
                    .prop('disabled', false)
                    .html('<span class="dashicons dashicons-unlock"></span> Unblock');
            } else {
                cgToast.error(response.data && response.data.message ? response.data.message : 'Could not block.');
                $button.prop('disabled', false).html('<span class="dashicons dashicons-shield-alt"></span> Block');
            }
        }).fail(function () {
            cgToast.error('An AJAX error occurred.');
            $button.prop('disabled', false).html('<span class="dashicons dashicons-shield-alt"></span> Block');
        });
    });

    // Unblock button inside popup
    $(document).on('click', '.cg-popup-unblock-btn', function (e) {
        e.preventDefault();
        var $button = $(this);
        var blockType = $button.data('block-type');
        var value = $button.data('value');
        var ref = $button.data('ref') || '';
        var nonce = $button.data('nonce');

        if (!blockType || (!value && !ref)) return;

        $button.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span>');

        var postData = {
            action: 'cg_delete_blocked_item',
            nonce: nonce,
            block_type: blockType
        };
        if (ref) {
            postData.ref = ref;
        } else {
            postData.value = value;
        }

        $.post(cg_admin_params.ajax_url, postData).done(function (response) {
            if (response.success) {
                var blockNonce = cg_admin_params.fraud_blocker_nonce;
                $button
                    .removeClass('cg-popup-unblock-btn')
                    .addClass('cg-popup-block-btn')
                    .data('nonce', blockNonce)
                    .prop('disabled', false)
                    .html('<span class="dashicons dashicons-shield-alt"></span> Block');
            } else {
                cgToast.error(response.data && response.data.message ? response.data.message : 'Could not unblock.');
                $button.prop('disabled', false).html('<span class="dashicons dashicons-unlock"></span> Unblock');
            }
        }).fail(function () {
            cgToast.error('An AJAX error occurred.');
            $button.prop('disabled', false).html('<span class="dashicons dashicons-unlock"></span> Unblock');
        });
    });

    // =========================================================================
    // Order Statuses Page
    // =========================================================================
    if ($('#cg-order-status-cards').length) {

        // Build the WooCommerce orders URL (HPOS-compatible).
        function cgOrdersUrl(slug) {
            // Try HPOS URL first, fallback to legacy.
            var base = cg_admin_params.ajax_url.replace('/admin-ajax.php', '/');
            return base + 'admin.php?page=wc-orders&status=wc-' + encodeURIComponent(slug);
        }

        // Load status cards via AJAX.
        function cgLoadStatusCards() {
            var $container = $('#cg-order-status-cards');
            $container.html(
                '<div class="cg-status-cards-loading">' +
                '<div class="cg-spinner"></div>' +
                '<p>Loading order counts...</p>' +
                '</div>'
            );

            $.post(cg_admin_params.ajax_url, {
                action: 'cg_fetch_order_status_counts',
                nonce: cg_admin_params.order_statuses_nonce
            }).done(function (response) {
                if (response.success && response.data && response.data.statuses) {
                    // Built with .attr()/.text()/.css() so status fields are escaped.
                    var cards = [];
                    response.data.statuses.forEach(function (s) {
                        var $label = $('<span class="cg-status-card-label"></span>');
                        if (s.icon) {
                            $label.append($('<span class="dashicons"></span>').addClass(String(s.icon)), ' ');
                        }
                        $label.append(document.createTextNode(s.label));
                        var $card = $('<div class="cg-status-card"></div>')
                            .css('border-top', '4px solid ' + s.color)
                            .append(
                                $('<div class="cg-status-card-header"></div>').append(
                                    $label,
                                    $('<span></span>')
                                        .addClass(s.is_default ? 'cg-status-badge-default' : 'cg-status-badge-custom')
                                        .text(s.is_default ? 'Default' : 'Custom')
                                ),
                                $('<div class="cg-status-card-count"></div>').text(s.count),
                                $('<a class="cg-status-card-link"></a>')
                                    .attr('href', cgOrdersUrl(s.slug))
                                    .html('View Orders &rarr;')
                            );
                        cards.push($card);
                    });
                    $container.empty().append(cards);

                    // Update order counts in the custom statuses table.
                    response.data.statuses.forEach(function (s) {
                        var $cell = $('.cg-status-order-count').filter(function () {
                            return $(this).attr('data-slug') === String(s.slug);
                        });
                        if ($cell.length) {
                            $cell.empty().append($('<strong></strong>').text(s.count));
                        }
                    });
                } else {
                    $container.html('<p>Error loading status counts.</p>');
                }
            }).fail(function () {
                $container.html('<p>Connection error. Please refresh the page.</p>');
            });
        }

        cgLoadStatusCards();

        // Modal references.
        var $modal = $('#cg-status-modal');
        var $form = $('#cg-status-form');

        // Open modal for Add New Status.
        $('#cg-add-new-status').on('click', function () {
            $form[0].reset();
            $('#cg-status-edit-slug').val('');
            $('#cg-status-slug').val('').prop('readonly', true).removeClass('cg-slug-editing');
            $('#cg-status-modal-title').text('Add New Status');
            $('#cg-status-color').val('#f97316');
            $('#cg-status-color-hex').val('#f97316');
            // Reset icon picker to "None".
            $('#cg-status-icon').val('');
            $('.cg-icon-picker-item').removeClass('cg-icon-picker-item--active');
            $('.cg-icon-picker-item[data-icon=""]').addClass('cg-icon-picker-item--active');
            $('#cg-status-show-in-bulk').prop('checked', false);
            $modal.show();
        });

        // Icon picker click handler.
        $(document).on('click', '.cg-icon-picker-item', function () {
            var $picker = $(this).closest('.cg-icon-picker');
            $picker.find('.cg-icon-picker-item').removeClass('cg-icon-picker-item--active');
            $(this).addClass('cg-icon-picker-item--active');
            $('#cg-status-icon').val($(this).data('icon'));
        });

        // Color picker <-> hex text input two-way sync.
        $('#cg-status-color').on('input', function () {
            $('#cg-status-color-hex').val($(this).val().toUpperCase());
        });
        $('#cg-status-color-hex').on('input', function () {
            var hex = $(this).val().trim();
            if (/^#[0-9A-Fa-f]{6}$/.test(hex)) {
                $('#cg-status-color').val(hex);
            }
        });

        // Auto-generate slug from label (add mode only).
        $('#cg-status-label').on('input', function () {
            if (!$('#cg-status-edit-slug').val()) {
                var slug = $(this).val()
                    .toLowerCase()
                    .replace(/[^a-z0-9\s-]/g, '')
                    .replace(/\s+/g, '-')
                    .replace(/-+/g, '-')
                    .replace(/^-|-$/g, '')
                    .substring(0, 17);
                $('#cg-status-slug').val(slug);
            }
        });

        // Close modal.
        $modal.find('.cg-modal-close').on('click', function () {
            $modal.hide();
        });
        $modal.on('click', function (e) {
            if ($(e.target).hasClass('cg-modal')) {
                $modal.hide();
            }
        });

        // Close on Escape key and focus trapping for status modal
        $(document).on('keydown.cgStatusModal', function (e) {
            if (!$modal.is(':visible')) return;
            if (e.key === 'Escape') {
                $modal.hide();
                return;
            }
            if (e.key === 'Tab') {
                var focusable = $modal.find('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])').filter(':visible');
                if (!focusable.length) return;
                var first = focusable.first();
                var last = focusable.last();
                if (e.shiftKey && document.activeElement === first[0]) {
                    e.preventDefault();
                    last.focus();
                } else if (!e.shiftKey && document.activeElement === last[0]) {
                    e.preventDefault();
                    first.focus();
                }
            }
        });

        // Save (create or update).
        $form.on('submit', function (e) {
            e.preventDefault();
            var $btn = $('#cg-status-save-btn');
            var $spinner = $('#cg-status-spinner');
            $btn.prop('disabled', true);
            $spinner.addClass('is-active');

            $.post(cg_admin_params.ajax_url, {
                action: 'cg_save_order_status',
                nonce: cg_admin_params.order_statuses_nonce,
                label: $('#cg-status-label').val(),
                color: $('#cg-status-color').val(),
                description: $('#cg-status-description').val(),
                icon: $('#cg-status-icon').val(),
                show_in_bulk: $('#cg-status-show-in-bulk').is(':checked') ? 1 : 0,
                edit_slug: $('#cg-status-edit-slug').val()
            }).done(function (response) {
                if (response.success) {
                    cgToast.success(response.data.message);
                    $modal.hide();
                    location.reload();
                } else {
                    cgToast.error(response.data && response.data.message ? response.data.message : 'Error saving status.');
                }
            }).fail(function () {
                cgToast.error('Connection error. Please try again.');
            }).always(function () {
                $btn.prop('disabled', false);
                $spinner.removeClass('is-active');
            });
        });

        // Edit button — fetch data and populate modal.
        $(document).on('click', '.cg-edit-status', function () {
            var slug = $(this).data('slug');
            var $btn = $(this);
            $btn.prop('disabled', true);

            $.post(cg_admin_params.ajax_url, {
                action: 'cg_get_order_status',
                nonce: cg_admin_params.order_statuses_nonce,
                slug: slug
            }).done(function (response) {
                if (response.success && response.data && response.data.status) {
                    var s = response.data.status;
                    $('#cg-status-edit-slug').val(s.slug);
                    $('#cg-status-label').val(s.label);
                    $('#cg-status-slug').val(s.slug).prop('readonly', true).addClass('cg-slug-editing');
                    var colorVal = s.color || '#f97316';
                    $('#cg-status-color').val(colorVal);
                    $('#cg-status-color-hex').val(colorVal.toUpperCase());
                    $('#cg-status-description').val(s.description || '');
                    // Set icon picker selection.
                    var iconVal = s.icon || '';
                    $('#cg-status-icon').val(iconVal);
                    $('.cg-icon-picker-item').removeClass('cg-icon-picker-item--active');
                    $('.cg-icon-picker-item[data-icon="' + iconVal + '"]').addClass('cg-icon-picker-item--active');
                    // Set bulk action checkbox (default false — disabled unless explicitly enabled).
                    $('#cg-status-show-in-bulk').prop('checked', !!s.show_in_bulk);
                    $('#cg-status-modal-title').text('Edit Status');
                    $modal.show();
                } else {
                    cgToast.error(response.data && response.data.message ? response.data.message : 'Could not load status data.');
                }
            }).fail(function () {
                cgToast.error('Connection error.');
            }).always(function () {
                $btn.prop('disabled', false);
            });
        });

        // Delete button — with migration handling.
        $(document).on('click', '.cg-delete-status', function () {
            var slug = $(this).data('slug');
            var $btn = $(this);

            cgConfirm.show({
                title: 'Delete Status',
                message: 'Are you sure you want to delete the status "wc-' + slug + '"? If this is a default status, use "Reset Statuses" in Settings to restore it later.',
                confirmText: 'Delete',
                danger: true
            }).then(function (ok) {
                if (!ok) return;

                $btn.prop('disabled', true).text('Deleting...');
                cgDoDelete(slug, '', $btn);
            });
        });

        // Recursive delete function with migration prompt.
        function cgDoDelete(slug, migrateSlug, $btn) {
            $.post(cg_admin_params.ajax_url, {
                action: 'cg_delete_order_status',
                nonce: cg_admin_params.order_statuses_nonce,
                slug: slug,
                migrate_to: migrateSlug || ''
            }).done(function (response) {
                if (response.success) {
                    cgToast.success(response.data.message);
                    $('tr[data-slug="' + slug + '"]').fadeOut(300, function () {
                        $(this).remove();
                        if ($('#cg-custom-statuses-tbody tr').length === 0) {
                            $('#cg-custom-statuses-table').hide();
                            $('#cg-no-custom-statuses').show();
                        }
                    });
                    cgLoadStatusCards();
                } else if (response.data && response.data.needs_migration) {
                    cgPrompt.show({
                        title: 'Migrate Orders',
                        message: 'This status has ' + response.data.order_count + ' order(s). Enter the slug to migrate them to (e.g. "processing", "on-hold", "completed"):',
                        placeholder: 'processing',
                        confirmText: 'Migrate & Delete'
                    }).then(function (targetSlug) {
                        if (targetSlug) {
                            cgDoDelete(slug, targetSlug, $btn);
                        } else {
                            $btn.prop('disabled', false).text('Delete');
                        }
                    });
                } else {
                    cgToast.error(response.data && response.data.message ? response.data.message : 'Error deleting status.');
                    $btn.prop('disabled', false).text('Delete');
                }
            }).fail(function () {
                cgToast.error('Connection error.');
                $btn.prop('disabled', false).text('Delete');
            });
        }
    }

    // =========================================================================
    // Reset Order Statuses (Settings page)
    // =========================================================================
    $('#cg-reset-order-statuses').on('click', function () {
        var $btn = $(this);
        cgConfirm.show({
            title: 'Reset Order Statuses',
            message: 'This will remove all custom statuses and restore the 7 default WooCommerce statuses. Orders on custom statuses will NOT be migrated. Are you sure?',
            confirmText: 'Reset',
            danger: true
        }).then(function (ok) {
            if (!ok) return;
            $btn.prop('disabled', true).text('Resetting...');
            $.post(cg_admin_params.ajax_url, {
                action: 'cg_reset_order_statuses',
                nonce: cg_admin_params.order_statuses_nonce
            }).done(function (response) {
                if (response.success) {
                    cgToast.success(response.data.message);
                } else {
                    cgToast.error(response.data && response.data.message ? response.data.message : 'Error resetting statuses.');
                }
            }).fail(function () {
                cgToast.error('Connection error. Please try again.');
            }).always(function () {
                $btn.prop('disabled', false).text('Reset Statuses');
            });
        });
    });

    // =====================================================================
    // Invoices Page
    // =====================================================================

    var $invoicesTable = $('#cg-invoices-table');
    if ($invoicesTable.length) {

        function updateInvoicesBulkBar() {
            var $bar = $('#cg-invoices-bulk-bar');
            var checkedCount = $('#cg-invoices-table-body').find('.cg-invoice-checkbox:checked').length;
            var totalCount = $('#cg-invoices-table-body').find('.cg-invoice-checkbox').length;
            $('#cg-invoices-bulk-download').prop('disabled', checkedCount === 0);
            $bar.find('.cg-bulk-count').text(checkedCount > 0 ? checkedCount + ' selected' : '');
            $('#cg-invoices-select-all').prop('checked', totalCount > 0 && checkedCount === totalCount);
            $('#cg-invoices-select-all').prop('indeterminate', checkedCount > 0 && checkedCount < totalCount);
        }

        $(document).on('change', '#cg-invoices-select-all', function () {
            $('#cg-invoices-table-body').find('.cg-invoice-checkbox').prop('checked', $(this).is(':checked'));
            updateInvoicesBulkBar();
        });

        $(document).on('change', '.cg-invoice-checkbox', function () {
            updateInvoicesBulkBar();
        });

        // Manual invoice generation (Pro).
        $(document).on('click', '#cg-generate-manual-invoice', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var $spinner = $btn.next('.spinner');
            var orderId = $('#cg-manual-invoice-order-id').val();
            if (!orderId) {
                cgToast.error('Enter an order ID first.');
                return;
            }
            $btn.prop('disabled', true);
            $spinner.addClass('is-active');
            $.post(cg_admin_params.ajax_url, {
                action: 'cg_generate_manual_invoice',
                nonce: cg_admin_params.invoices_nonce,
                order_id: orderId
            }).done(function (response) {
                if (response.success) {
                    cgToast.success(response.data.message || 'Invoice generated.');
                    $('#cg-manual-invoice-order-id').val('');
                    reloadInvoicesList();
                } else {
                    cgToast.error(response.data && response.data.message ? response.data.message : 'Could not generate invoice.');
                }
            }).fail(function () {
                cgToast.error('An AJAX error occurred.');
            }).always(function () {
                $btn.prop('disabled', false);
                $spinner.removeClass('is-active');
            });
        });

        // =================================================================
        // New Blank Invoice modal (Pro): freeform + product-picker items.
        // =================================================================
        var $miModal = $('#cg-manual-invoice-modal');
        var $miForm = $('#cg-manual-invoice-form');
        var $miItemsBody = $('#cg-mi-items-body');
        var cgMiCurrency = (cg_admin_params.currency_symbol !== undefined) ? cg_admin_params.currency_symbol : '';

        function cgMiFormatMoney(amount) {
            return cgMiCurrency + (Math.round((amount || 0) * 100) / 100).toFixed(2);
        }

        function cgMiRecalcTotals() {
            var subtotal = 0;
            $miItemsBody.find('.cg-mi-item-row').each(function () {
                var $row = $(this);
                var qty = parseInt($row.find('.cg-mi-item-qty').val(), 10) || 0;
                var price = parseFloat($row.find('.cg-mi-item-price').val()) || 0;
                var amount = qty * price;
                $row.find('.cg-mi-item-amount').text(cgMiFormatMoney(amount));
                subtotal += amount;
            });
            var shipping = parseFloat($('#cg-mi-shipping-total').val()) || 0;
            $('#cg-mi-subtotal').text(cgMiFormatMoney(subtotal));
            $('#cg-mi-summary-shipping').text(cgMiFormatMoney(shipping));
            $('#cg-mi-total').text(cgMiFormatMoney(subtotal + shipping));
        }

        function cgMiAddItemRow() {
            var $row = $(
                '<div class="cg-mi-item-row">' +
                    '<select class="cg-mi-item-product wc-product-search" data-placeholder="' + 'Search product…' + '" data-action="woocommerce_json_search_products_and_variations"><option value=""></option></select>' +
                    '<input type="text" class="cg-mi-item-name" placeholder="Item name" required>' +
                    '<input type="number" class="cg-mi-item-qty" min="1" step="1" pattern="[0-9]*" inputmode="numeric" value="1">' +
                    '<input type="number" class="cg-mi-item-price" min="0" step="0.01" value="0">' +
                    '<span class="cg-mi-item-amount">' + cgMiFormatMoney(0) + '</span>' +
                    '<button type="button" class="cg-mi-remove-item" aria-label="Remove"><span class="dashicons dashicons-trash"></span></button>' +
                '</div>'
            );
            $miItemsBody.append($row);
            $(document.body).trigger('wc-enhanced-select-init');
        }

        // Open modal for New Blank Invoice.
        $('#cg-open-manual-invoice-modal').on('click', function () {
            if ($(this).is(':disabled')) return;
            $miForm[0].reset();
            $miItemsBody.empty();
            cgMiAddItemRow();
            $('#cg-mi-transaction-field').hide();
            cgMiRecalcTotals();
            $miModal.show();
        });

        $miModal.find('.cg-modal-close, .cg-mi-cancel').on('click', function () {
            $miModal.hide();
        });
        $miModal.on('click', function (e) {
            if ($(e.target).hasClass('cg-modal')) {
                $miModal.hide();
            }
        });

        $('#cg-mi-add-item').on('click', function () {
            cgMiAddItemRow();
            cgMiRecalcTotals();
        });

        $(document).on('click', '.cg-mi-remove-item', function () {
            if ($miItemsBody.find('.cg-mi-item-row').length > 1) {
                $(this).closest('.cg-mi-item-row').remove();
                cgMiRecalcTotals();
            }
        });

        // Qty must stay a whole number — strip any decimal point/fraction as
        // it's typed, so 1.01 is never reachable (spinner arrows already
        // step by whole numbers via step="1").
        $(document).on('input', '.cg-mi-item-qty', function () {
            var $el = $(this);
            var digitsOnly = $el.val().replace(/[^0-9]/g, '');
            if (digitsOnly !== $el.val()) {
                $el.val(digitsOnly);
            }
        });

        $(document).on('input', '.cg-mi-item-qty, .cg-mi-item-price', function () {
            cgMiRecalcTotals();
        });

        // On blur, normalize to a valid whole number >= 1 (handles an empty
        // or "0" field left behind after typing).
        $(document).on('blur', '.cg-mi-item-qty', function () {
            var $el = $(this);
            var qty = parseInt($el.val(), 10);
            if (!qty || qty < 1) {
                $el.val(1);
                cgMiRecalcTotals();
            }
        });

        $('#cg-mi-shipping-total').on('input', function () {
            cgMiRecalcTotals();
        });

        // Only Bank Transfer / Other need a transaction reference.
        $('#cg-mi-payment-method').on('change', function () {
            var needsTransaction = ($(this).val() === 'bank_transfer' || $(this).val() === 'other');
            $('#cg-mi-transaction-field').toggle(needsTransaction);
        });

        // Autofill name + price when a product is picked from the catalog.
        $(document).on('change', '.cg-mi-item-product', function () {
            var $select = $(this);
            var productId = $select.val();
            if (!productId) return;
            var $row = $select.closest('.cg-mi-item-row');
            $.post(cg_admin_params.ajax_url, {
                action: 'cg_get_product_info',
                nonce: cg_admin_params.invoices_nonce,
                product_id: productId
            }).done(function (response) {
                if (response.success) {
                    $row.find('.cg-mi-item-name').val(response.data.name);
                    $row.find('.cg-mi-item-price').val(response.data.price);
                    cgMiRecalcTotals();
                }
            });
        });

        $miForm.on('submit', function (e) {
            e.preventDefault();
            var $submitBtn = $('#cg-mi-submit');
            var $spinner = $submitBtn.next('.spinner');

            var items = [];
            $miItemsBody.find('.cg-mi-item-row').each(function () {
                var $row = $(this);
                var name = $row.find('.cg-mi-item-name').val();
                var qty = parseInt($row.find('.cg-mi-item-qty').val(), 10);
                if (!name || !qty || qty <= 0) return;
                items.push({
                    product_id: $row.find('.cg-mi-item-product').val() || 0,
                    name: name,
                    qty: qty,
                    price: parseFloat($row.find('.cg-mi-item-price').val()) || 0
                });
            });

            if (!$('#cg-mi-customer-name').val()) {
                cgToast.error('Customer name is required.');
                return;
            }
            if (items.length === 0) {
                cgToast.error('Add at least one line item.');
                return;
            }

            $submitBtn.prop('disabled', true);
            $spinner.addClass('is-active');

            $.post(cg_admin_params.ajax_url, {
                action: 'cg_generate_manual_invoice',
                nonce: cg_admin_params.invoices_nonce,
                customer_name: $('#cg-mi-customer-name').val(),
                phone: $('#cg-mi-phone').val(),
                email: $('#cg-mi-email').val(),
                address_1: $('#cg-mi-address').val(),
                city: $('#cg-mi-city').val(),
                state: $('#cg-mi-state').val(),
                postcode: $('#cg-mi-postcode').val(),
                items: items,
                shipping_total: $('#cg-mi-shipping-total').val() || 0,
                payment_method: $('#cg-mi-payment-method').val(),
                transaction_id: $('#cg-mi-transaction-id').val(),
                courier: $('#cg-mi-courier').val(),
                tracking_number: $('#cg-mi-tracking').val()
            }).done(function (response) {
                if (response.success) {
                    $miModal.hide();
                    var link = response.data.print_url
                        ? ' <a href="' + response.data.print_url + '" target="_blank" rel="noopener">View / Print</a>'
                        : '';
                    cgToast.successHtml((response.data.message || 'Invoice created.') + link);
                    reloadInvoicesList();
                } else {
                    cgToast.error(response.data && response.data.message ? response.data.message : 'Could not create invoice.');
                }
            }).fail(function () {
                cgToast.error('An AJAX error occurred.');
            }).always(function () {
                $submitBtn.prop('disabled', false);
                $spinner.removeClass('is-active');
            });
        });

        // Bulk ZIP download (Pro).
        $(document).on('click', '#cg-invoices-bulk-download', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var $bar = $('#cg-invoices-bulk-bar');
            var $spinner = $bar.find('.spinner');
            var orderIds = [];
            $('#cg-invoices-table-body').find('.cg-invoice-checkbox:checked').each(function () {
                orderIds.push($(this).val());
            });
            if (orderIds.length === 0) return;

            $btn.prop('disabled', true);
            $spinner.addClass('is-active');

            $.post(cg_admin_params.ajax_url, {
                action: 'cg_bulk_download_invoices',
                nonce: cg_admin_params.invoices_nonce,
                order_ids: orderIds
            }).done(function (response) {
                if (response.success && response.data.zip_base64) {
                    var binary = atob(response.data.zip_base64);
                    var bytes = new Uint8Array(binary.length);
                    for (var i = 0; i < binary.length; i++) {
                        bytes[i] = binary.charCodeAt(i);
                    }
                    var blob = new Blob([bytes], { type: 'application/zip' });
                    var url = URL.createObjectURL(blob);
                    var link = document.createElement('a');
                    link.href = url;
                    link.download = response.data.filename || 'invoices.zip';
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                    URL.revokeObjectURL(url);
                    cgToast.success(response.data.count + ' invoice(s) downloaded.');
                } else {
                    cgToast.error(response.data && response.data.message ? response.data.message : 'Bulk download failed.');
                }
            }).fail(function () {
                cgToast.error('An AJAX error occurred during bulk download.');
            }).always(function () {
                $btn.prop('disabled', false);
                $spinner.removeClass('is-active');
            });
        });

        // Search (debounced).
        var invoicesSearchTimer = null;
        var invoicesCurrentPage = 1;

        function reloadInvoicesList(page) {
            page = page || 1;
            invoicesCurrentPage = page;
            var search = $('#cg-invoices-search').val();
            $('#cg-invoices-loading').show();
            $.post(cg_admin_params.ajax_url, {
                action: 'cg_fetch_invoices',
                nonce: cg_admin_params.invoices_nonce,
                page: page,
                search: search
            }).done(function (response) {
                if (response.success) {
                    $('#cg-invoices-table-body').html(response.data.html);
                    var hasRows = response.data.html && response.data.html.trim().length > 0;
                    $('#cg-invoices-table').toggle(hasRows);
                    $('#cg-invoices-table-body-empty-message').toggle(!hasRows);
                    $('#cg-invoices-bulk-bar').toggle(hasRows);
                    updateInvoicesBulkBar();
                } else {
                    cgToast.error(response.data && response.data.message ? response.data.message : 'Could not load invoices.');
                }
            }).fail(function () {
                cgToast.error('An AJAX error occurred while loading invoices.');
            }).always(function () {
                $('#cg-invoices-loading').hide();
            });
        }

        $(document).on('input', '#cg-invoices-search', function () {
            clearTimeout(invoicesSearchTimer);
            invoicesSearchTimer = setTimeout(function () {
                reloadInvoicesList(1);
            }, 500);
        });
    }

});

/* --- Usage Warning Toasts on Page Load --- */
(function () {
    'use strict';
    if (typeof cg_admin_params === 'undefined') return;
    var warnings = cg_admin_params.usage_warnings;
    if (!warnings || !warnings.length) return;

    // Show up to 2 toasts for items at their limit (most critical)
    var shown = 0;
    var pricingUrl = cg_admin_params.pricing_url || '';
    var whatsappUrl = cg_admin_params.whatsapp_url || '';

    function buildLimitMsg(label) {
        var msg = 'Plan limit reached for <strong>' + label + '</strong>.';
        msg += ' <a href="#" class="cg-open-upgrade-modal" style="color:#fff;text-decoration:underline;">Upgrade</a>';
        if (whatsappUrl) {
            msg += ' or <a href="' + whatsappUrl + '" target="_blank" style="color:#fff;text-decoration:underline;">WhatsApp us</a>';
        }
        msg += '.';
        return msg;
    }

    // Wait for DOM ready before showing toasts
    document.addEventListener('DOMContentLoaded', function () {
        for (var i = 0; i < warnings.length && shown < 2; i++) {
            if (warnings[i].is_at_limit) {
                window.cgToast.errorHtml(buildLimitMsg(warnings[i].label), 12000);
                shown++;
            }
        }
    });
})();
