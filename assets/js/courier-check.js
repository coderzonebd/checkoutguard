/**
 * CheckoutGuard - Courier Check JavaScript
 * 
 * Handles the courier check functionality on the admin page.
 * 
 * @package CheckoutGuard
 */

(function($) {
    'use strict';

    // Wait for DOM to be ready
    $(document).ready(function() {
        // Handle courier check form submission
        $('#cg-courier-check-form').on('submit', function(e) {
            e.preventDefault();
            performCourierCheck();
        });

        // Handle recent search item click
        $(document).on('click', '.cg-recent-item-modern', function() {
            const phoneNumber = $(this).data('phone');
            if (phoneNumber) {
                $('#cg-phone-number').val(phoneNumber);
                performCourierCheck();
            }
        });

        // Format phone number input (remove non-digits)
        $('#cg-phone-number').on('input', function() {
            let value = $(this).val().replace(/\D/g, '');
            $(this).val(value);
        });

        // Hide "Clear All" button if no recent searches
        checkClearAllButtonVisibility();
    });

    /**
     * Checks if "Clear All" button should be visible.
     */
    function checkClearAllButtonVisibility() {
        const itemCount = $('#cg-recent-list .cg-recent-item-modern').length;
        if (itemCount === 0) {
            $('#cg-clear-all-btn').hide();
        } else {
            $('#cg-clear-all-btn').show();
        }
    }

    /**
     * Performs the courier check via AJAX.
     */
    function performCourierCheck() {
        const phoneNumber = $('#cg-phone-number').val().trim();
        const bypassCache = $('#cg-bypass-cache').is(':checked');
        const $button = $('#cg-check-courier-btn');
        const $loading = $('#cg-loading');
        const $resultsContainer = $('#cg-courier-results');

        // Validate phone number
        if (!phoneNumber || !/^01[3-9]\d{8}$/.test(phoneNumber)) {
            showError('Please enter a valid Bangladeshi phone number (e.g., 01700000000)');
            return;
        }

        // Disable form and show loading
        $button.prop('disabled', true);
        $loading.show();
        $resultsContainer.html('<div class="cg-loading-modern"><span class="cg-spinner-modern"></span><span>Fetching courier data...</span></div>');

        // Make AJAX request
        $.ajax({
            url: checkoutguardCourier.ajaxUrl,
            type: 'POST',
            data: {
                action: 'checkoutguard_courier_check',
                nonce: checkoutguardCourier.nonce,
                phone_number: phoneNumber,
                bypass_cache: bypassCache.toString()
            },
            success: function(response) {
                if (response.success) {
                    displayResults(response.data);
                    updateRecentSearches();
                } else {
                    showError(response.data.message || 'An error occurred while checking courier.');
                }
            },
            error: function(xhr, status, error) {
                showError('Network error: Unable to connect to the server. Please try again.');
                console.error('AJAX Error:', status, error);
            },
            complete: function() {
                $button.prop('disabled', false);
                $loading.hide();
            }
        });
    }

    /**
     * Displays the courier check results.
     * 
     * @param {object} data - The response data from the API
     */
    function displayResults(data) {
        const resultsData = data.data || data;
        const cached = data.cached || false;
        const cacheSource = data.cache_source || 'unknown';
        const summary = resultsData.summary || {};
        const pathao = resultsData.pathao || {};
        const steadfast = resultsData.steadfast || {};
        const redx = resultsData.redx || {};

        let html = '';

        // Cache badge with source information
        if (cached) {
            const cacheAge = resultsData.cache_age_minutes || 0;
            let cacheText = 'Cached Results';
            let cacheIcon = 'dashicons-database';
            
            if (cacheSource === 'local') {
                cacheText = 'Local Cache';
                cacheIcon = 'dashicons-saved';
                if (cacheAge > 0) {
                    cacheText += ' (' + cacheAge + ' min ago)';
                }
            } else if (cacheSource === 'api') {
                cacheText = 'Server Cache';
                cacheIcon = 'dashicons-cloud';
            }
            
            html += '<div class="cg-cache-badge cg-cache-' + cacheSource + '">';
            html += '<span class="dashicons ' + cacheIcon + '"></span> ' + cacheText;
            html += '</div>';
        }

        // Summary Card
        html += '<div class="cg-summary-card ' + getRiskClass(summary.risk_level) + '">';
        html += '  <div class="cg-summary-header">';
        html += '    <h3>Overall Assessment</h3>';
        html += '    <div class="cg-risk-badge-large" style="background-color: ' + (summary.risk_color || '#757575') + '">';
        html += '      ' + (summary.risk_level || 'N/A');
        html += '    </div>';
        html += '  </div>';
        html += '  <div class="cg-summary-stats">';
        html += '    <div class="cg-stat-item">';
        html += '      <span class="cg-stat-label">Total Deliveries</span>';
        html += '      <span class="cg-stat-value">' + (summary.total_deliveries || 0) + '</span>';
        html += '    </div>';
        html += '    <div class="cg-stat-item">';
        html += '      <span class="cg-stat-label">Successful</span>';
        html += '      <span class="cg-stat-value cg-success">' + (summary.successful_deliveries || 0) + '</span>';
        html += '    </div>';
        html += '    <div class="cg-stat-item">';
        html += '      <span class="cg-stat-label">Cancelled</span>';
        html += '      <span class="cg-stat-value cg-danger">' + (summary.cancelled_deliveries || 0) + '</span>';
        html += '    </div>';
        html += '    <div class="cg-stat-item">';
        html += '      <span class="cg-stat-label">Success Rate</span>';
        html += '      <span class="cg-stat-value cg-rate">' + (summary.success_rate || 0) + '%</span>';
        html += '    </div>';
        html += '  </div>';
        html += '</div>';

        // Courier Details
        html += '<div class="cg-courier-details-grid">';
        html += buildCourierCard('Pathao', pathao, '#FF5722');
        html += buildCourierCard('Steadfast', steadfast, '#2196F3');
        html += buildCourierCard('RedX', redx, '#E91E63');
        html += '</div>';

        // Branding
        html += '<div class="cg-branding">';
        html += '  Powered by <strong>Coder Zone BD</strong>';
        html += '</div>';

        $('#cg-courier-results').html(html);
    }

    /**
     * Builds HTML for an individual courier card.
     * 
     * @param {string} name - Courier name
     * @param {object} data - Courier data
     * @param {string} color - Brand color
     * @returns {string} HTML string
     */
    function buildCourierCard(name, data, color) {
        const hasError = data.error === true;
        const total = data.total || 0;
        const success = data.success || 0;
        const cancelled = data.cancelled || 0;
        const successRate = data.success_rate || 0;
        
        // Get logo path - convert name to lowercase for filename
        const logoName = name.toLowerCase();
        const logoPath = checkoutguardCourier.pluginUrl + 'assets/img/' + logoName + '.svg';

        let html = '<div class="cg-courier-card">';
        html += '  <div class="cg-courier-header" style="border-left-color: ' + color + '">';
        html += '    <div class="cg-courier-title-wrapper">';
        html += '      <img src="' + logoPath + '" alt="' + name + ' Logo" class="cg-courier-logo" onerror="this.classList.add(\'cg-logo-error\'); this.nextElementSibling.style.display=\'block\';">';
        html += '      <h4 class="cg-courier-name" style="display: none;">' + name + '</h4>';
        html += '    </div>';
        if (hasError) {
            html += '    <span class="cg-error-badge">No Data</span>';
        }
        html += '  </div>';
        html += '  <div class="cg-courier-body">';
        
        if (hasError) {
            html += '    <p class="cg-no-data-text">No delivery history found</p>';
        } else {
            html += '    <div class="cg-courier-stats">';
            html += '      <div class="cg-courier-stat">';
            html += '        <span class="label">Total</span>';
            html += '        <span class="value">' + total + '</span>';
            html += '      </div>';
            html += '      <div class="cg-courier-stat">';
            html += '        <span class="label">Successful</span>';
            html += '        <span class="value cg-success">' + success + '</span>';
            html += '      </div>';
            html += '      <div class="cg-courier-stat">';
            html += '        <span class="label">Cancelled</span>';
            html += '        <span class="value cg-danger">' + cancelled + '</span>';
            html += '      </div>';
            html += '    </div>';
            html += '    <div class="cg-progress-bar">';
            html += '      <div class="cg-progress-fill" style="width: ' + successRate + '%; background-color: ' + color + '"></div>';
            html += '    </div>';
            html += '    <div class="cg-success-rate">' + successRate + '% Success Rate</div>';
        }
        
        html += '  </div>';
        html += '</div>';

        return html;
    }

    /**
     * Gets the risk class for styling.
     * 
     * @param {string} riskLevel - Risk level (Safe, Mid-safe, Risk, High Risk)
     * @returns {string} CSS class name
     */
    function getRiskClass(riskLevel) {
        if (!riskLevel) return '';
        
        const level = riskLevel.toLowerCase();
        if (level === 'safe') return 'risk-safe';
        if (level === 'mid-safe') return 'risk-mid-safe';
        if (level === 'risk') return 'risk-warning';
        if (level === 'high risk') return 'risk-high';
        return '';
    }

    /**
     * Shows an error message.
     * 
     * @param {string} message - Error message to display
     */
    function showError(message) {
        const html = '<div class="cg-error-message">' +
                    '  <span class="dashicons dashicons-warning"></span>' +
                    '  <p>' + message + '</p>' +
                    '</div>';
        $('#cg-courier-results').html(html);
    }

    /**
     * Updates the recent searches list.
     */
    function updateRecentSearches() {
        $.ajax({
            url: checkoutguardCourier.ajaxUrl,
            type: 'POST',
            data: {
                action: 'checkoutguard_get_recent_searches',
                nonce: checkoutguardCourier.nonce
            },
            success: function(response) {
                if (response.success && response.data.html) {
                    $('#cg-recent-list').html(response.data.html);
                    checkClearAllButtonVisibility();
                }
            }
        });
    }

    /**
     * Handles delete button clicks on recent searches.
     */
    $(document).on('click', '.cg-delete-search', function(e) {
        e.stopPropagation(); // Prevent triggering the search item click
        
        const $button = $(this);
        const $item = $button.closest('.cg-recent-item-modern');
        const searchId = $button.data('search-id');
        const phoneNumber = $item.data('phone');

        // Confirm deletion
        if (!confirm('Are you sure you want to delete the search for ' + phoneNumber + '?')) {
            return;
        }

        // Disable button and show loading
        $button.prop('disabled', true);
        $button.find('.dashicons').removeClass('dashicons-trash').addClass('dashicons-update').css('animation', 'spin 1s linear infinite');

        $.ajax({
            url: checkoutguardCourier.ajaxUrl,
            type: 'POST',
            data: {
                action: 'checkoutguard_delete_courier_search',
                nonce: checkoutguardCourier.nonce,
                search_id: searchId
            },
            success: function(response) {
                if (response.success) {
                    // Fade out and remove the item
                    $item.fadeOut(300, function() {
                        $(this).remove();
                        
                        // Check if there are any items left
                        if ($('#cg-recent-list .cg-recent-item-modern').length === 0) {
                            $('#cg-recent-list').html('<div class="cg-no-data-modern"><span class="dashicons dashicons-info"></span><p>' + checkoutguardCourier.noRecentSearches + '</p></div>');
                            $('#cg-clear-all-btn').hide();
                        }
                    });
                } else {
                    alert(response.data.message || 'Failed to delete search.');
                    // Re-enable button and restore icon
                    $button.prop('disabled', false);
                    $button.find('.dashicons').removeClass('dashicons-update').addClass('dashicons-trash').css('animation', '');
                }
            },
            error: function() {
                alert('An error occurred while deleting the search.');
                // Re-enable button and restore icon
                $button.prop('disabled', false);
                $button.find('.dashicons').removeClass('dashicons-update').addClass('dashicons-trash').css('animation', '');
            }
        });
    });

    /**
     * Handles "Clear All" button click.
     */
    $(document).on('click', '#cg-clear-all-btn', function(e) {
        e.preventDefault();
        
        const $button = $(this);
        const itemCount = $('#cg-recent-list .cg-recent-item-modern').length;

        // Check if there are items to delete
        if (itemCount === 0) {
            alert('No search history to clear.');
            return;
        }

        // Confirm deletion
        const confirmMessage = 'Are you sure you want to clear all ' + itemCount + ' search history item' + (itemCount > 1 ? 's' : '') + '?';
        if (!confirm(confirmMessage)) {
            return;
        }

        // Disable button and show loading
        $button.prop('disabled', true);
        const $icon = $button.find('.dashicons');
        const originalIcon = $icon.attr('class');
        $icon.removeClass('dashicons-trash').addClass('dashicons-update');
        $button.addClass('loading');

        $.ajax({
            url: checkoutguardCourier.ajaxUrl,
            type: 'POST',
            data: {
                action: 'checkoutguard_clear_all_searches',
                nonce: checkoutguardCourier.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Fade out all items
                    $('#cg-recent-list .cg-recent-item-modern').fadeOut(300, function() {
                        $(this).remove();
                    });
                    
                    // Update list with empty state
                    setTimeout(function() {
                        $('#cg-recent-list').html('<div class="cg-no-data-modern"><span class="dashicons dashicons-info"></span><p>' + checkoutguardCourier.noRecentSearches + '</p></div>');
                        $button.hide();
                    }, 350);
                    
                } else {
                    alert(response.data.message || 'Failed to clear search history.');
                    // Re-enable button and restore icon
                    $button.prop('disabled', false).removeClass('loading');
                    $icon.attr('class', originalIcon);
                }
            },
            error: function() {
                alert('An error occurred while clearing search history.');
                // Re-enable button and restore icon
                $button.prop('disabled', false).removeClass('loading');
                $icon.attr('class', originalIcon);
            }
        });
    });

})(jQuery);
