jQuery(document).ready(function($) {
    let logsRefreshInterval = null;
    
    // Toggle log details
    $('#webhookLogsContainer').on('click', '.log-toggle', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        const $btn = $(this);
        const $card = $btn.closest('.log-card');
        const $details = $card.find('.log-details');
        
        $details.slideToggle(200, function() {
            const isVisible = $details.is(':visible');
            $btn.text(isVisible ? 'Hide' : 'Details');
            $card.toggleClass('expanded', isVisible);
        });
    });
    
    // Make sure details are hidden initially
    $('.log-details').hide();

    // Fetch logs function
    function fetchLogs(page) {
        const $container = $('#webhookLogsContainer');
        const $refreshBtn = $('#refreshLogs');
        const $spinner = $('<span class="spinner is-active" style="float: none; margin-left: 4px;"></span>');
        
        // Only show spinner if it's a manual refresh
        if (page === 1) {
            $refreshBtn.prop('disabled', true).after($spinner);
        }
        
        return $.post(ajaxurl, {
            action: 'get_logs',
            security: webhookLogs.nonce,
            page: page || 1
        }).done(function(response) {
            if(response.success) {
                $container.html(response.data.html);
                // No need to rebind events since we're using event delegation
            } else {
                console.error('Error loading logs:', response.data);
                $container.html('<div class="notice notice-error">Error loading logs</div>');
            }
        }).fail(function(xhr) {
            console.error('Log request failed:', xhr.responseText);
            $container.html('<div class="notice notice-error">Request failed: ' + xhr.statusText + '</div>');
        }).always(function() {
            $refreshBtn.prop('disabled', false);
            $spinner.remove();
        });
    }

    // Refresh logs
    $('#refreshLogs').click(function(e) {
        e.preventDefault();
        fetchLogs(1);
    });

    // Clear logs
    $('#clearLogs').click(function(e) {
        e.preventDefault();
        $.post(ajaxurl, {
            action: 'clear_logs',
            security: webhookLogs.nonce
        }).done(function() {
            fetchLogs(1);
        });
    });

    // Initial load and setup auto-refresh
    fetchLogs(1);
    
    // Setup auto-refresh for logs
    function startLogsAutoRefresh() {
        if (logsRefreshInterval) {
            clearInterval(logsRefreshInterval);
        }
        
        // Initial fetch
        fetchLogs(1);
        
        // Setup interval
        logsRefreshInterval = setInterval(function() {
            if (!document.hidden) { // Only refresh if page is visible
                fetchLogs(1);
            }
        }, 3000);
        
        // Handle page visibility changes
        document.addEventListener('visibilitychange', function() {
            if (!document.hidden && logsRefreshInterval === null) {
                startLogsAutoRefresh();
            }
        });
    }
    
    // Start auto-refresh
    startLogsAutoRefresh();
    
    // Stop auto-refresh when leaving the page
    $(window).on('beforeunload', function() {
        if (logsRefreshInterval) {
            clearInterval(logsRefreshInterval);
            logsRefreshInterval = null;
        }
    });

    let pollInterval = null;

    // Test toggle button
    $('#testToggle').click(function(e) {
        e.preventDefault();
        const $btn = $(this);
        const isTesting = $btn.hasClass('active');
        
        $.post(ajaxurl, {
            action: isTesting ? 'stop_webhook_test' : 'start_webhook_test',
            security: webhookLogs.nonce
        }).done(function(response) {
            if (response.success) {
                $btn.toggleClass('active')
                    .toggleClass('button-primary', !response.data.test_active)
                    .toggleClass('button-secondary', response.data.test_active)
                    .text(response.data.test_active ? 'Stop Testing' : 'Start Listening');
                
                $('#testStatus').toggle(response.data.test_active);
                
                if (response.data.test_active) {
                    startPolling();
                } else {
                    if (pollInterval) {
                        clearInterval(pollInterval);
                        pollInterval = null;
                    }
                }
            }
        });
    });

    // Test mode polling function
    function startPolling() {
        if (pollInterval) {
            clearInterval(pollInterval);
        }

        pollInterval = setInterval(() => {
            $.post(ajaxurl, {
                action: 'get_webhook_test',
                security: webhookLogs.nonce
            }, function(response) {
                if (response.success) {
                    if (response.data.results) {
                        $('#testResults').html('<pre>' + JSON.stringify(response.data.results, null, 2) + '</pre>');
                    }
                    if (!response.data.test_active) {
                        clearInterval(pollInterval);
                        pollInterval = null;
                        $('#testStatus').hide();
                        $('#testToggle')
                            .text('Start Listening')
                            .removeClass('button-secondary')
                            .addClass('button-primary')
                            .removeClass('active');
                    }
                }
            });
        }, 3000);
    }

    // Start polling if test mode is active
    if ($('#testStatus').is(':visible')) {
        startPolling();
    }

    // Initialize clipboard
    new ClipboardJS('.copy-key, .copy-url', {
        text: function(trigger) {
            return trigger.dataset.clipboardTarget ? 
                document.querySelector(trigger.dataset.clipboardTarget).value :
                trigger.dataset.clipboardText;
        }
    });
});
