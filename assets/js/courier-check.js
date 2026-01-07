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
        $('#checkoutguard-courier-check-form').on('submit', function(e) {
            e.preventDefault();
            performCourierCheck();
        });

        // Handle recent search item click
        $(document).on('click', '.checkoutguard-recent-item-modern', function() {
            const phoneNumber = $(this).data('phone');
            if (phoneNumber) {
                $('#checkoutguard-phone-number').val(phoneNumber);
                performCourierCheck();
            }
        });

        // Format phone number input (remove non-digits)
        $('#checkoutguard-phone-number').on('input', function() {
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
        const itemCount = $('#checkoutguard-recent-list .checkoutguard-recent-item-modern').length;
        if (itemCount === 0) {
            $('#checkoutguard-clear-all-btn').hide();
        } else {
            $('#checkoutguard-clear-all-btn').show();
        }
    }

    /**
     * Performs the courier check via AJAX.
     */
    function performCourierCheck() {
        const phoneNumber = $('#checkoutguard-phone-number').val().trim();
        const bypassCache = $('#checkoutguard-bypass-cache').is(':checked');
        const $button = $('#checkoutguard-check-courier-btn');
        const $loading = $('#checkoutguard-loading');
        const $resultsContainer = $('#checkoutguard-courier-results');

        // Validate phone number
        if (!phoneNumber || !/^01[3-9]\d{8}$/.test(phoneNumber)) {
            showError('Please enter a valid Bangladeshi phone number (e.g., 01700000000)');
            return;
        }

        // Disable form and show loading
        $button.prop('disabled', true);
        $loading.show();
        $resultsContainer.html('<div class="checkoutguard-loading-modern"><span class="checkoutguard-spinner-modern"></span><span>Fetching courier data...</span></div>');

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
                // Disabled for production: console.error('AJAX Error:', status, error);
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
            
            html += '<div class="checkoutguard-cache-badge checkoutguard-cache-' + cacheSource + '">';
            html += '<span class="dashicons ' + cacheIcon + '"></span> ' + cacheText;
            html += '</div>';
        }

        const riskClass = getRiskClass(summary.risk_level);
        const riskColor = summary.risk_color || '#757575';
        const successRate = summary.success_rate || 0;
        const totalDeliveries = summary.total_deliveries || 0;
        const successfulDeliveries = summary.successful_deliveries || 0;
        const cancelledDeliveries = summary.cancelled_deliveries || 0;
        const riskLevel = summary.risk_level || 'N/A';
        
        // Main Grid Layout - Improved sizing
        html += '<div style="display: grid; grid-template-columns: 250px 1fr; gap: 24px; margin-bottom: 24px;">';
        
        // Chart Section - Larger chart
        html += '  <div style="display: flex; flex-direction: column; align-items: center; justify-content: center;">';
        html += '    <div style="position: relative; width: 220px; height: 220px;">';
        html += '      <canvas id="courier-success-chart"></canvas>';
        html += '      <div style="position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center;">';
        html += '        <div style="font-size: 52px; font-weight: 800; line-height: 1; background: linear-gradient(to bottom right, #EEC343, #F97316); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;">' + Math.round(successRate) + '%</div>';
        html += '        <div style="margin-top: 6px; padding: 6px 18px; border-radius: 9999px; font-size: 13px; font-weight: 700; border: 1px solid;" class="' + riskClass + '">' + riskLevel + '</div>';
        html += '      </div>';
        html += '    </div>';
        html += '  </div>';
        
        // Stats Cards Section - Better sizing
        html += '  <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px;">';
        
        // Total Orders Card
        html += '    <div style="background: linear-gradient(to bottom right, #f9fafb, #f3f4f6); border: 1px solid #e5e7eb; border-radius: 12px; padding: 20px; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.08); transition: all 0.2s;" onmouseover="this.style.transform=\'translateY(-3px)\';this.style.boxShadow=\'0 4px 12px rgba(0,0,0,0.12)\'" onmouseout="this.style.transform=\'translateY(0)\';this.style.boxShadow=\'0 2px 4px rgba(0,0,0,0.08)\'">';
        html += '      <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">';
        html += '        <div style="padding: 10px; background: #6b7280; border-radius: 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">';
        html += '          <span class="dashicons dashicons-archive" style="color: white; font-size: 20px; width: 20px; height: 20px;"></span>';
        html += '        </div>';
        html += '      </div>';
        html += '      <div style="font-size: 32px; font-weight: 800; line-height: 1; color: #1f2937; margin-bottom: 6px;">' + totalDeliveries + '</div>';
        html += '      <div style="font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em;">Total Orders</div>';
        html += '    </div>';
        
        // Delivered Card
        html += '    <div style="background: linear-gradient(to bottom right, #d1fae5, #a7f3d0); border: 1px solid #6ee7b7; border-radius: 12px; padding: 20px; box-shadow: 0 2px 4px rgba(16, 185, 129, 0.15); transition: all 0.2s;" onmouseover="this.style.transform=\'translateY(-3px)\';this.style.boxShadow=\'0 4px 12px rgba(16,185,129,0.25)\'" onmouseout="this.style.transform=\'translateY(0)\';this.style.boxShadow=\'0 2px 4px rgba(16,185,129,0.15)\'">';
        html += '      <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">';
        html += '        <div style="padding: 10px; background: linear-gradient(to bottom right, #10b981, #059669); border-radius: 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">';
        html += '          <span class="dashicons dashicons-yes-alt" style="color: white; font-size: 20px; width: 20px; height: 20px;"></span>';
        html += '        </div>';
        html += '      </div>';
        html += '      <div style="font-size: 32px; font-weight: 800; line-height: 1; color: #047857; margin-bottom: 6px;">' + successfulDeliveries + '</div>';
        html += '      <div style="font-size: 11px; font-weight: 600; color: #065f46; text-transform: uppercase; letter-spacing: 0.05em;">Delivered</div>';
        html += '    </div>';
        
        // Cancelled Card
        html += '    <div style="background: linear-gradient(to bottom right, #fee2e2, #fecaca); border: 1px solid #fca5a5; border-radius: 12px; padding: 20px; box-shadow: 0 2px 4px rgba(239, 68, 68, 0.15); transition: all 0.2s;" onmouseover="this.style.transform=\'translateY(-3px)\';this.style.boxShadow=\'0 4px 12px rgba(239,68,68,0.25)\'" onmouseout="this.style.transform=\'translateY(0)\';this.style.boxShadow=\'0 2px 4px rgba(239,68,68,0.15)\'">';
        html += '      <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">';
        html += '        <div style="padding: 10px; background: linear-gradient(to bottom right, #ef4444, #dc2626); border-radius: 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">';
        html += '          <span class="dashicons dashicons-dismiss" style="color: white; font-size: 20px; width: 20px; height: 20px;"></span>';
        html += '        </div>';
        html += '      </div>';
        html += '      <div style="font-size: 32px; font-weight: 800; line-height: 1; color: #b91c1c; margin-bottom: 6px;">' + cancelledDeliveries + '</div>';
        html += '      <div style="font-size: 11px; font-weight: 600; color: #991b1b; text-transform: uppercase; letter-spacing: 0.05em;">Cancelled</div>';
        html += '    </div>';
        
        html += '  </div>';
        html += '</div>';

        // Courier Details Section Header
        html += '<div style="background: linear-gradient(to right, #fef3c7, #fed7aa); padding: 14px 20px; border-radius: 12px 12px 0 0; border-bottom: 2px solid #fbbf24; margin-bottom: 0;">';
        html += '  <h4 style="margin: 0; font-size: 15px; font-weight: 700; color: #1f2937; display: flex; align-items: center;">';
        html += '    <span class="dashicons dashicons-chart-bar" style="color: #d97706; margin-right: 8px; font-size: 18px; width: 18px; height: 18px;"></span>';
        html += '    Courier Breakdown';
        html += '  </h4>';
        html += '</div>';

        // Courier List
        html += '<div class="checkoutguard-courier-list" style="border: 2px solid #e5e7eb; border-top: none; border-radius: 0 0 12px 12px; padding: 14px;">';
        html += buildCourierRow('Pathao', pathao, '#FF5722');
        
        // Build Pathao note with rating label
        let pathaoNote = 'Pathao data is estimated based on customer rating.';
        if (pathao && pathao.rating_label) {
            pathaoNote += ' Pathao customer rating: <strong>' + pathao.rating_label + '</strong>';
        }
        html += '<div style="padding: 8px 12px; font-size: 11px; color: #666; font-style: italic; background: #f9f9f9; border-left: 3px solid #FF5722; margin: 0 0 10px 0;">' + pathaoNote + '</div>';
        
        html += buildCourierRow('Steadfast', steadfast, '#2196F3');
        html += buildCourierRow('RedX', redx, '#E91E63');
        html += '</div>';

        // Branding
        html += '<div class="checkoutguard-branding" style="text-align: center; padding: 12px; font-size: 12px; color: #6b7280;">';
        html += '  Powered by <a href="https://coderzonebd.com/" target="_blank" style="font-weight: bold; color: #1f2937; text-decoration: none;">Coder Zone BD</a>';
        html += '</div>';

        $('#checkoutguard-courier-results').html(html);
        
        // Render Chart
        renderSuccessChart(successRate, riskColor);
    }

    /**
     * Renders the success rate doughnut chart
     */
    function renderSuccessChart(successRate, riskColor) {
        const canvas = document.getElementById('courier-success-chart');
        if (!canvas) return;
        
        // Destroy existing chart if any
        if (window.courierChart) {
            window.courierChart.destroy();
        }
        
        const ctx = canvas.getContext('2d');
        const gradient = ctx.createLinearGradient(0, 0, 0, 400);
        
        // Determine colors based on success rate
        let color1, color2;
        if (successRate >= 90) {
            color1 = '#10b981';
            color2 = '#059669';
        } else if (successRate >= 75) {
            color1 = '#EEC343';
            color2 = '#F97316';
        } else {
            color1 = '#ef4444';
            color2 = '#dc2626';
        }
        
        gradient.addColorStop(0, color1);
        gradient.addColorStop(1, color2);
        
        window.courierChart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                datasets: [{
                    data: [successRate, Math.max(0, 100 - successRate)],
                    backgroundColor: [gradient, '#f3f4f6'],
                    borderWidth: 0,
                    cutout: '75%'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    tooltip: { enabled: false },
                    legend: { display: false }
                },
                animation: {
                    animateRotate: true,
                    animateScale: true,
                    duration: 1500,
                    easing: 'easeInOutQuart'
                }
            }
        });
    }

    /**
     * Builds HTML for an individual courier row (Old Style).
     * 
     * @param {string} name - Courier name
     * @param {object} data - Courier data
     * @param {string} color - Brand color
     * @returns {string} HTML string
     */
    function buildCourierRow(name, data, color) {
        const hasError = data.error === true;
        const total = data.total || 0;
        const success = data.success || 0;
        const cancelled = data.cancelled || 0;
        const successRate = data.success_rate || 0;
        
        // Get logo path - convert name to lowercase for filename
        const logoName = name.toLowerCase();
        const logoPath = checkoutguardCourier.pluginUrl + 'assets/img/' + logoName + '.svg';

        let html = '<div class="checkoutguard-courier-row">';
        
        // Logo Column
        html += '  <div class="checkoutguard-courier-logo-col">';
        html += '    <div class="checkoutguard-courier-logo-wrapper">';
        html += '      <img src="' + logoPath + '" alt="' + name + '" onerror="this.style.display=\'none\'; this.nextElementSibling.style.display=\'block\';">';
        html += '      <span class="checkoutguard-courier-fallback" style="display:none; background:' + color + '">' + name.charAt(0) + '</span>';
        html += '    </div>';
        html += '    <div class="checkoutguard-courier-name">' + name + '</div>';
        html += '  </div>';
        
        // Stats Column
        html += '  <div class="checkoutguard-courier-stats-col">';
        if (hasError) {
            html += '    <div class="checkoutguard-courier-error">No data available</div>';
        } else {
            html += '    <div class="checkoutguard-row-stats">';
            html += '      <div class="checkoutguard-row-stat">';
            html += '        <span class="stat-label">Total</span>';
            html += '        <span class="stat-value">' + total + '</span>';
            html += '      </div>';
            html += '      <div class="checkoutguard-row-stat">';
            html += '        <span class="stat-label">Success</span>';
            html += '        <span class="stat-value text-success">' + success + '</span>';
            html += '      </div>';
            html += '      <div class="checkoutguard-row-stat">';
            html += '        <span class="stat-label">Cancelled</span>';
            html += '        <span class="stat-value text-danger">' + cancelled + '</span>';
            html += '      </div>';
            html += '    </div>';
            
            // Progress Bar
            html += '    <div class="checkoutguard-row-progress">';
            html += '      <div class="checkoutguard-row-progress-bar" style="width: ' + successRate + '%; background-color: ' + color + '"></div>';
            html += '    </div>';
        }
        html += '  </div>';
        
        // Rate Column
        html += '  <div class="checkoutguard-courier-rate-col">';
        if (!hasError) {
            html += '    <div class="checkoutguard-row-rate" style="color: ' + color + '">';
            html += '      ' + successRate + '%';
            html += '      <small>Success</small>';
            html += '    </div>';
        } else {
             html += '    <span class="dashicons dashicons-warning" style="color: #ccc"></span>';
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
        const html = '<div class="checkoutguard-error-message">' +
                    '  <span class="dashicons dashicons-warning"></span>' +
                    '  <p>' + message + '</p>' +
                    '</div>';
        $('#checkoutguard-courier-results').html(html);
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
                    $('#checkoutguard-recent-list').html(response.data.html);
                    checkClearAllButtonVisibility();
                }
            }
        });
    }

    /**
     * Handles delete button clicks on recent searches.
     */
    $(document).on('click', '.checkoutguard-delete-search', function(e) {
        e.stopPropagation(); // Prevent triggering the search item click
        
        const $button = $(this);
        const $item = $button.closest('.checkoutguard-recent-item-modern');
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
                        if ($('#checkoutguard-recent-list .checkoutguard-recent-item-modern').length === 0) {
                            $('#checkoutguard-recent-list').html('<div class="checkoutguard-no-data-modern"><span class="dashicons dashicons-info"></span><p>' + checkoutguardCourier.noRecentSearches + '</p></div>');
                            $('#checkoutguard-clear-all-btn').hide();
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
    $(document).on('click', '#checkoutguard-clear-all-btn', function(e) {
        e.preventDefault();
        
        const $button = $(this);
        const itemCount = $('#checkoutguard-recent-list .checkoutguard-recent-item-modern').length;

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
                    $('#checkoutguard-recent-list .checkoutguard-recent-item-modern').fadeOut(300, function() {
                        $(this).remove();
                    });
                    
                    // Update list with empty state
                    setTimeout(function() {
                        $('#checkoutguard-recent-list').html('<div class="checkoutguard-no-data-modern"><span class="dashicons dashicons-info"></span><p>' + checkoutguardCourier.noRecentSearches + '</p></div>');
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
