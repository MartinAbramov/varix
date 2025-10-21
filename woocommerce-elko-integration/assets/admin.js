jQuery(document).ready(function($) {
    'use strict';
    
    console.log('ELKO Admin JS loaded at 2025-10-21 14:19:42');
    console.log('AJAX URL:', elko_ajax.ajax_url);
    console.log('Nonce:', elko_ajax.nonce);
    console.log('User: MartinAbramov');
    
    // Helper function for AJAX requests
    function makeAjaxRequest(action, data, button) {
        data = data || {};
        data.action = action;
        data.nonce = elko_ajax.nonce;
        
        button.prop('disabled', true);
        var originalText = button.text();
        button.text('⏳ Processing...');
        
        console.log('Making AJAX request:', action, data);
        
        $.ajax({
            url: elko_ajax.ajax_url,
            type: 'POST',
            data: data,
            timeout: 300000, // 5 minutes
            success: function(response) {
                console.log('AJAX Success for', action, ':', response);
                
                if (response.success) {
                    showResult(response.data, 'success');
                    
                    // Refresh page after successful scheduler toggle
                    if (action === 'elko_toggle_scheduler' || action === 'elko_emergency_stop') {
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
                console.log('Response text:', xhr.responseText);
                showResult('AJAX Error: ' + error + ' (Status: ' + status + '). Check console for details.', 'error');
            },
            complete: function() {
                button.prop('disabled', false);
                button.text(originalText);
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
        
        resultDiv.removeClass('success error')
                 .addClass(type)
                 .html('<strong>' + (type === 'success' ? '✅ ' : '❌ ') + '</strong>' + message)
                 .show();
        
        // Auto hide after 15 seconds for success messages
        if (type === 'success') {
            setTimeout(function() {
                resultDiv.fadeOut();
            }, 15000);
        }
    }
    
    // Emergency Stop
    $('#emergency-stop').on('click', function(e) {
        e.preventDefault();
        
        if (!confirm('🚨 Are you sure you want to FORCE STOP all ELKO imports immediately? This will disable scheduler and clear all scheduled tasks.')) {
            return;
        }
        
        console.log('Emergency stop clicked at 2025-10-21 14:19:42 by MartinAbramov');
        makeAjaxRequest('elko_emergency_stop', {}, $(this));
    });
    
    // FIXED: Toggle Scheduler with proper button detection
    $('#disable-scheduler, #enable-scheduler').on('click', function(e) {
        e.preventDefault();
        
        var buttonId = $(this).attr('id');
        var isDisabling = buttonId === 'disable-scheduler';
        
        console.log('Scheduler button clicked:', buttonId, 'isDisabling:', isDisabling);
        
        var confirmText = isDisabling ? 
            '🛑 Disable automatic scheduling? This will stop all scheduled ELKO imports and clear all cron jobs.' :
            '🔄 Enable automatic scheduling? This will start scheduled ELKO imports according to configuration.';
            
        if (!confirm(confirmText)) {
            return;
        }
        
        console.log('Toggle scheduler confirmed:', isDisabling ? 'disable' : 'enable', 'at 2025-10-21 14:19:42 by MartinAbramov');
        makeAjaxRequest('elko_toggle_scheduler', { force_action: isDisabling ? 'disable' : 'enable' }, $(this));
    });
    
    // Sync Categories
    $('#sync-categories').on('click', function(e) {
        e.preventDefault();
        
        if (!confirm('Start category synchronization? This will import hierarchical categories from ELKO API.')) {
            return;
        }
        
        console.log('Sync categories clicked at 2025-10-21 14:19:42 by MartinAbramov');
        showProgress('Importing categories with hierarchy...');
        makeAjaxRequest('elko_sync_categories', {}, $(this));
    });
    
    // Sync Products
    $('#sync-products').on('click', function(e) {
        e.preventDefault();
        
        if (!confirm('Start product synchronization? This may take a long time and import many products with images and attributes.')) {
            return;
        }
        
        console.log('Sync products clicked at 2025-10-21 14:19:42 by MartinAbramov');
        showProgress('Importing products with enhanced data (gallery, attributes, descriptions)...');
        makeAjaxRequest('elko_sync_products', {}, $(this));
    });
    
    // Update Prices
    $('#update-prices').on('click', function(e) {
        e.preventDefault();
        
        if (!confirm('Update all product prices from ELKO API?')) {
            return;
        }
        
        console.log('Update prices clicked at 2025-10-21 14:19:42 by MartinAbramov');
        showProgress('Updating product prices...');
        makeAjaxRequest('elko_update_prices', {}, $(this));
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
        
        console.log('Clear all data clicked at 2025-10-21 14:19:42 by MartinAbramov');
        showProgress('Deleting all ELKO data...');
        makeAjaxRequest('elko_clear_all_data', {}, $(this));
    });
    
    // Test Connection
    $('#test-connection').on('click', function(e) {
        e.preventDefault();
        console.log('Test connection clicked at 2025-10-21 14:19:42 by MartinAbramov');
        makeAjaxRequest('elko_test_connection', {}, $(this));
    });
    
    // Debug Endpoints
    $('#debug-endpoints').on('click', function(e) {
        e.preventDefault();
        console.log('Debug endpoints clicked at 2025-10-21 14:19:42 by MartinAbramov');
        makeAjaxRequest('elko_debug_endpoints', {}, $(this));
    });
    
    // NUCLEAR OPTION - Force Kill All ELKO Processes
    if ($('#nuclear-stop').length === 0) {
        $('#emergency-stop').after('<button type="button" id="nuclear-stop" class="button" style="background: #8B0000; color: white; border-color: #8B0000; font-size: 16px; padding: 12px 24px; height: auto; margin-left: 10px;">☢️ NUCLEAR STOP</button>');
    }
    
    $('#nuclear-stop').on('click', function(e) {
        e.preventDefault();
        
        if (!confirm('☢️ NUCLEAR OPTION: This will forcefully kill ALL WordPress cron jobs and ELKO processes. This may affect other plugins. Continue?')) {
            return;
        }
        
        if (!confirm('☢️ FINAL WARNING: This will clear ALL WordPress scheduled events, not just ELKO. Continue only if emergency!')) {
            return;
        }
        
        console.log('NUCLEAR STOP clicked at 2025-10-21 14:19:42 by MartinAbramov');
        makeAjaxRequest('elko_nuclear_stop', {}, $(this));
    });
    
    // Show progress
    function showProgress(message) {
        var progressDiv = $('#sync-progress');
        var progressText = progressDiv.find('.progress-text');
        
        progressText.text(message);
        progressDiv.addClass('active').show();
        
        // Animate progress bar
        var progressFill = progressDiv.find('.progress-fill');
        progressFill.css('width', '0%');
        
        var width = 0;
        var interval = setInterval(function() {
            width += Math.random() * 10;
            if (width > 90) {
                width = 90;
                clearInterval(interval);
            }
            progressFill.css('width', width + '%');
        }, 500);
        
        // Store interval to clear it later
        progressDiv.data('interval', interval);
    }
    
    // Hide progress
    function hideProgress() {
        var progressDiv = $('#sync-progress');
        var interval = progressDiv.data('interval');
        
        if (interval) {
            clearInterval(interval);
        }
        
        var progressFill = progressDiv.find('.progress-fill');
        progressFill.css('width', '100%');
        
        setTimeout(function() {
            progressDiv.removeClass('active').hide();
            progressFill.css('width', '0%');
        }, 1000);
    }
    
    // Override the default AJAX complete handler to hide progress
    $(document).ajaxComplete(function(event, xhr, settings) {
        if (settings.data && settings.data.indexOf('elko_') !== -1) {
            hideProgress();
        }
    });
    
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
    
    console.log('ELKO Admin JS fully initialized by MartinAbramov at 2025-10-21 14:19:42');
});

