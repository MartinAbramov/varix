jQuery(document).ready(function($) {
    'use strict';
    
    console.log('ELKO Admin JS loaded');
    console.log('AJAX URL:', elko_ajax.ajax_url);
    
    var currentSessionId = elko_ajax.current_session || '';
    var progressInterval = null;
    var isBackgroundJob = false;
    
    // Check for active background jobs on page load
    function checkForActiveJob() {
        $.ajax({
            url: elko_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'elko_get_active_job',
                nonce: elko_ajax.nonce
            },
            success: function(response) {
                if (response.success && response.data && response.data.has_active_job) {
                    console.log('Found active background job:', response.data);
                    
                    // Use the session ID from the active job
                    currentSessionId = response.data.session_id;
                    isBackgroundJob = true;
                    
                    // Show progress bar
                    var progressDiv = $('#sync-progress');
                    progressDiv.show();
                    
                    // Update display with current status
                    updateProgressDisplay({
                        current_item: response.data.current_item || 'Processing...',
                        total: response.data.total,
                        processed: response.data.processed,
                        percentage: response.data.percentage,
                        error_count: response.data.error_count || 0
                    });
                    
                    // Show info message
                    showResult('🔄 Active background job found: ' + response.data.import_type + '. Resuming progress display...', 'success');
                    
                    // Start polling for updates
                    startProgressPolling();
                }
            },
            error: function(xhr, status, error) {
                console.log('Error checking for active job:', error);
            }
        });
    }
    
    // Check for active jobs when page loads
    checkForActiveJob();
    
    // Helper function for background job requests (fire and forget - continues even if page is closed)
    function startBackgroundJob(jobAction, data, button) {
        data = data || {};
        data.action = 'elko_start_background_job';
        data.job_action = jobAction;
        data.nonce = elko_ajax.nonce;
        data.session_id = currentSessionId;
        
        if (button) {
            button.prop('disabled', true);
            var originalText = button.text();
            button.text('⏳ Starting...');
        }
        
        console.log('Starting background job:', jobAction, data);
        
        showProgress('🚀 Starting background job...');
        
        $.ajax({
            url: elko_ajax.ajax_url,
            type: 'POST',
            data: data,
            timeout: 30000, // 30 seconds to start the job
            success: function(response) {
                console.log('Background job started:', response);
                
                if (response.success) {
                    isBackgroundJob = true;
                    showResult(response.data.message || response.data, 'success');
                    showProgress('🔄 Import running in background. Progress updates below...');
                    startProgressPolling();
                    
                    // Re-enable button but keep polling
                    if (button) {
                        button.prop('disabled', false);
                        button.text(originalText);
                    }
                } else {
                    showResult(response.data || 'Failed to start background job', 'error');
                    hideProgress();
                    if (button) {
                        button.prop('disabled', false);
                        button.text(originalText);
                    }
                }
            },
            error: function(xhr, status, error) {
                console.log('Error starting background job:', xhr, status, error);
                showResult('Failed to start background job: ' + error, 'error');
                hideProgress();
                if (button) {
                    button.prop('disabled', false);
                    button.text(originalText);
                }
            }
        });
    }
    
    // Helper function for regular AJAX requests (foreground - requires open tab)
    function makeAjaxRequest(action, data, button, showProgressBar) {
        data = data || {};
        data.action = action;
        data.nonce = elko_ajax.nonce;
        
        if (showProgressBar) {
            data.session_id = currentSessionId;
        }
        
        if (button) {
            button.prop('disabled', true);
            var originalText = button.text();
            button.text('⏳ Processing...');
        }
        
        console.log('Making AJAX request:', action, data);
        
        if (showProgressBar) {
            showProgress('Starting...');
            startProgressPolling();
        }
        
        $.ajax({
            url: elko_ajax.ajax_url,
            type: 'POST',
            data: data,
            timeout: 600000, // 10 minutes
            success: function(response) {
                console.log('AJAX Success for', action, ':', response);
                
                stopProgressPolling();
                hideProgress();
                
                if (response.success) {
                    showResult(response.data, 'success');
                    
                    // Refresh page after scheduler changes
                    if (action === 'elko_toggle_scheduler' || action === 'elko_emergency_stop' || action === 'elko_nuclear_stop') {
                        setTimeout(function() {
                            window.location.reload();
                        }, 2000);
                    }
                } else {
                    showResult(response.data || 'Unknown error occurred', 'error');
                }
            },
            error: function(xhr, status, error) {
                console.log('AJAX Error for', action, ':', xhr, status, error);
                stopProgressPolling();
                hideProgress();
                showResult('AJAX Error: ' + error + ' (Status: ' + status + ')', 'error');
            },
            complete: function() {
                if (button) {
                    button.prop('disabled', false);
                    button.text(originalText);
                }
            }
        });
    }
    
    // Show result message
    function showResult(message, type) {
        var resultDiv = $('#sync-result');
        if (resultDiv.length === 0) {
            resultDiv = $('#emergency-result');
        }
        if (resultDiv.length === 0) {
            resultDiv = $('#debug-result');
        }
        if (resultDiv.length === 0) {
            resultDiv = $('#settings-result');
        }
        
        resultDiv.removeClass('success error')
                 .addClass(type)
                 .css('display', 'block')
                 .html('<strong>' + (type === 'success' ? '✅ ' : '❌ ') + '</strong>' + message);
        
        if (type === 'success') {
            setTimeout(function() {
                resultDiv.fadeOut();
            }, 15000);
        }
    }
    
    // Show progress bar
    function showProgress(message) {
        var progressDiv = $('#sync-progress');
        progressDiv.show();
        progressDiv.find('.progress-current').text(message);
        progressDiv.find('.progress-stats').text('');
        progressDiv.find('.progress-fill').css('width', '0%');
    }
    
    // Hide progress bar
    function hideProgress() {
        var progressDiv = $('#sync-progress');
        progressDiv.find('.progress-fill').css('width', '100%');
        setTimeout(function() {
            progressDiv.hide();
            progressDiv.find('.progress-fill').css('width', '0%');
        }, 500);
    }
    
    // Update progress display
    function updateProgressDisplay(data) {
        var progressDiv = $('#sync-progress');
        
        if (data.current_item) {
            progressDiv.find('.progress-current').text('📦 ' + data.current_item);
        }
        
        var statsText = '';
        if (data.total > 0) {
            statsText = data.processed + ' / ' + data.total + ' (' + data.percentage + '%)';
        }
        if (data.error_count > 0) {
            statsText += ' | Errors: ' + data.error_count;
        }
        if (isBackgroundJob) {
            statsText += ' | 🔄 Running in background';
        }
        progressDiv.find('.progress-stats').text(statsText);
        
        progressDiv.find('.progress-fill').css('width', data.percentage + '%');
    }
    
    // Start progress polling
    function startProgressPolling() {
        if (progressInterval) {
            clearInterval(progressInterval);
        }
        
        progressInterval = setInterval(function() {
            $.ajax({
                url: elko_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'elko_get_progress',
                    nonce: elko_ajax.nonce,
                    session_id: currentSessionId
                },
                success: function(response) {
                    if (response.success && response.data) {
                        updateProgressDisplay(response.data);
                        
                        if (response.data.status === 'completed' || response.data.status === 'stopped' || response.data.status === 'error') {
                            stopProgressPolling();
                            isBackgroundJob = false;
                            
                            // Show completion message
                            if (response.data.status === 'completed') {
                                showResult('✅ Background job completed! Processed ' + response.data.processed + ' items.', 'success');
                            } else if (response.data.status === 'stopped') {
                                showResult('⏹️ Background job was stopped. Processed ' + response.data.processed + ' items.', 'success');
                            } else {
                                showResult('❌ Background job encountered an error.', 'error');
                            }
                            
                            setTimeout(function() {
                                hideProgress();
                            }, 2000);
                        }
                    }
                }
            });
        }, 2000); // Poll every 2 seconds for background jobs (less aggressive)
    }
    
    // Stop progress polling
    function stopProgressPolling() {
        if (progressInterval) {
            clearInterval(progressInterval);
            progressInterval = null;
        }
    }
    
    // Generate new session ID using crypto API if available
    function generateSessionId() {
        var randomPart;
        if (window.crypto && window.crypto.getRandomValues) {
            var array = new Uint32Array(2);
            window.crypto.getRandomValues(array);
            randomPart = array[0].toString(36) + array[1].toString(36);
        } else {
            // Fallback for older browsers
            randomPart = Math.random().toString(36).substr(2, 9);
        }
        currentSessionId = 'elko_' + Date.now() + '_' + randomPart;
        return currentSessionId;
    }
    
    // Get selected categories
    function getSelectedCategories() {
        var selected = $('#import-categories').val();
        return selected || [];
    }
    
    // Stop Import (graceful)
    $('#stop-import').on('click', function(e) {
        e.preventDefault();
        
        if (!confirm('⏹️ Stop current import gracefully? The current item will finish processing.')) {
            return;
        }
        
        makeAjaxRequest('elko_stop_import', { session_id: currentSessionId }, $(this));
    });
    
    // Emergency Stop
    $('#emergency-stop').on('click', function(e) {
        e.preventDefault();
        
        if (!confirm('🚨 FORCE STOP all ELKO imports immediately? This will disable scheduler and clear all tasks.')) {
            return;
        }
        
        makeAjaxRequest('elko_emergency_stop', {}, $(this));
    });
    
    // Toggle Scheduler
    $('#disable-scheduler, #enable-scheduler').on('click', function(e) {
        e.preventDefault();
        
        var buttonId = $(this).attr('id');
        var isDisabling = buttonId === 'disable-scheduler';
        
        var confirmText = isDisabling ? 
            '🛑 Disable automatic scheduling? This will stop all scheduled ELKO imports.' :
            '🔄 Enable automatic scheduling? Scheduled imports will start running.';
            
        if (!confirm(confirmText)) {
            return;
        }
        
        makeAjaxRequest('elko_toggle_scheduler', { force_action: isDisabling ? 'disable' : 'enable' }, $(this));
    });
    
    // Sync Categories - uses background job
    $('#sync-categories').on('click', function(e) {
        e.preventDefault();
        
        if (!confirm('Start category synchronization in BACKGROUND? This will import hierarchical categories from ELKO API.\n\n🔄 You can close this page - the import will continue!')) {
            return;
        }
        
        generateSessionId();
        startBackgroundJob('sync_categories', {}, $(this));
    });
    
    // Sync Products - uses background job
    $('#sync-products').on('click', function(e) {
        e.preventDefault();
        
        var selectedCategories = getSelectedCategories();
        var categoryMsg = selectedCategories.length > 0 
            ? 'Import products from ' + selectedCategories.length + ' selected categories in BACKGROUND?'
            : 'Import products from ALL categories in BACKGROUND? This may take a while.';
        
        categoryMsg += '\n\n🔄 You can close this page - the import will continue!';
        
        if (!confirm(categoryMsg)) {
            return;
        }
        
        generateSessionId();
        startBackgroundJob('sync_products', { categories: selectedCategories }, $(this));
    });
    
    // Import Attributes - uses background job
    $('#import-attributes').on('click', function(e) {
        e.preventDefault();
        
        if (!confirm('Import/update attributes for all ELKO products in BACKGROUND?\n\n🔄 You can close this page - the import will continue!')) {
            return;
        }
        
        generateSessionId();
        startBackgroundJob('import_attributes', {}, $(this));
    });
    
    // Update Prices - uses background job
    $('#update-prices').on('click', function(e) {
        e.preventDefault();
        
        if (!confirm('Update all product prices and stock in BACKGROUND?\n\n🔄 You can close this page - the import will continue!')) {
            return;
        }
        
        generateSessionId();
        startBackgroundJob('update_prices', {}, $(this));
    });
    
    // Fix Images - uses background job with category selection
    $('#fix-images').on('click', function(e) {
        e.preventDefault();
        
        var selectedCategories = getSelectedCategories();
        var categoryMsg = selectedCategories.length > 0 
            ? 'Re-import images for products in ' + selectedCategories.length + ' selected categories in BACKGROUND?'
            : 'Re-import images for ALL products in BACKGROUND? This may take a while.';
        
        categoryMsg += '\n\nThis will delete existing images and download fresh ones from ELKO.\n\n🔄 You can close this page - the import will continue!';
        
        if (!confirm(categoryMsg)) {
            return;
        }
        
        generateSessionId();
        startBackgroundJob('fix_images', { categories: selectedCategories }, $(this));
    });
    
    // Refresh Categories List
    $('#refresh-categories').on('click', function(e) {
        e.preventDefault();
        
        var button = $(this);
        var resultSpan = $('#refresh-categories-result');
        
        button.prop('disabled', true).text('⏳ Loading...');
        resultSpan.text('');
        
        $.ajax({
            url: elko_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'elko_refresh_categories',
                nonce: elko_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    resultSpan.html('<span style="color: green;">' + response.data.message + '</span>');
                    
                    // Update the categories dropdown
                    if (response.data.categories) {
                        var select = $('#import-categories');
                        select.empty();
                        
                        $.each(response.data.categories, function(name, code) {
                            select.append($('<option>', {
                                value: code,
                                text: name
                            }));
                        });
                        
                        // Update the count display
                        select.siblings('.description').first().next('.description').html('<strong>' + response.data.count + '</strong> categories available.');
                    }
                } else {
                    resultSpan.html('<span style="color: red;">' + response.data + '</span>');
                }
            },
            error: function(xhr, status, error) {
                resultSpan.html('<span style="color: red;">Error: ' + error + '</span>');
            },
            complete: function() {
                button.prop('disabled', false).text('🔄 Refresh Categories List from API');
            }
        });
    });
    
    // Clear All Data
    $('#clear-all-data').on('click', function(e) {
        e.preventDefault();
        
        if (!confirm('⚠️ DANGER: This will permanently delete ALL ELKO products and categories! Are you sure?')) {
            return;
        }
        
        if (!confirm('⚠️ FINAL WARNING: This action cannot be undone! All ELKO data will be lost forever!')) {
            return;
        }
        
        makeAjaxRequest('elko_clear_all_data', {}, $(this));
    });
    
    // Test Connection
    $('#test-connection').on('click', function(e) {
        e.preventDefault();
        makeAjaxRequest('elko_test_connection', {}, $(this));
    });
    
    // Debug Endpoints
    $('#debug-endpoints').on('click', function(e) {
        e.preventDefault();
        makeAjaxRequest('elko_debug_endpoints', {}, $(this));
    });
    
    // Save Settings via AJAX
    $('#save-elko-settings').on('click', function(e) {
        e.preventDefault();
        
        var button = $(this);
        button.prop('disabled', true).text('⏳ Saving...');
        
        var formData = {
            action: 'elko_save_settings',
            nonce: elko_ajax.nonce,
            api_url: $('input[name="elko_api_url"]').val(),
            api_key: $('textarea[name="elko_api_key"]').val(),
            tax_percentage: $('input[name="elko_tax_percentage"]').val(),
            markup_percentage: $('input[name="elko_markup_percentage"]').val(),
            price_calculation_method: $('select[name="elko_price_calculation_method"]').val(),
            round_prices: $('input[name="elko_round_prices"]').is(':checked') ? 1 : 0,
            sync_frequency: $('select[name="elko_sync_frequency"]').val()
        };
        
        $.ajax({
            url: elko_ajax.ajax_url,
            type: 'POST',
            data: formData,
            success: function(response) {
                if (response.success) {
                    $('#settings-result')
                        .removeClass('error')
                        .addClass('success')
                        .html('<strong>✅</strong> ' + response.data)
                        .show();
                    
                    setTimeout(function() {
                        $('#settings-result').fadeOut();
                    }, 5000);
                } else {
                    $('#settings-result')
                        .removeClass('success')
                        .addClass('error')
                        .html('<strong>❌</strong> ' + (response.data || 'Failed to save settings'))
                        .show();
                }
            },
            error: function(xhr, status, error) {
                $('#settings-result')
                    .removeClass('success')
                    .addClass('error')
                    .html('<strong>❌</strong> Error: ' + error)
                    .show();
            },
            complete: function() {
                button.prop('disabled', false).text('💾 Save Settings');
            }
        });
    });
    
    // Price preview calculation
    function updatePricePreview() {
        var basePrice = parseFloat($('#price-preview-input').val()) || 100;
        var tax = parseFloat($('input[name="elko_tax_percentage"]').val()) || 0;
        var markup = parseFloat($('input[name="elko_markup_percentage"]').val()) || 0;
        var method = $('select[name="elko_price_calculation_method"]').val();
        var round = $('input[name="elko_round_prices"]').is(':checked');
        
        var finalPrice;
        if (method === 'compound') {
            finalPrice = basePrice * (1 + tax / 100) * (1 + markup / 100);
        } else {
            var taxAmount = basePrice * (tax / 100);
            finalPrice = (basePrice + taxAmount) * (1 + markup / 100);
        }
        
        if (round) {
            finalPrice = Math.round(finalPrice * 100) / 100;
        }
        
        $('#price-preview-result').text('€' + finalPrice.toFixed(2));
        $('#price-preview-breakdown').html(
            'Base: €' + basePrice.toFixed(2) + 
            ' + Tax (' + tax + '%): €' + (basePrice * tax / 100).toFixed(2) + 
            ' + Markup (' + markup + '%): = €' + finalPrice.toFixed(2)
        );
    }
    
    // Bind price preview updates
    $('input[name="elko_tax_percentage"], input[name="elko_markup_percentage"], #price-preview-input').on('input change', updatePricePreview);
    $('select[name="elko_price_calculation_method"]').on('change', updatePricePreview);
    $('input[name="elko_round_prices"]').on('change', updatePricePreview);
    
    // Initial price preview
    if ($('#price-preview-input').length) {
        updatePricePreview();
    }
    
    // Auto-refresh stats every 30 seconds
    setInterval(function() {
        if ($('#stat-products').length > 0) {
            $.ajax({
                url: elko_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'elko_get_stats',
                    nonce: elko_ajax.nonce
                },
                success: function(response) {
                    if (response.success && response.data) {
                        $('#stat-products').text(response.data.products || 0);
                        $('#stat-categories').text(response.data.categories || 0);
                        $('#stat-recent-sync').text(response.data.last_sync || 'Never');
                        $('#stat-errors').text(response.data.recent_errors || 0);
                    }
                }
            });
        }
    }, 30000);
    
    console.log('ELKO Admin JS fully initialized');
});
