jQuery(document).ready(function($) {
    // Toggle log details
    $('#webhook-logs').on('click', '.log-toggle', function(e) {
        e.preventDefault();
        $(this).closest('.log-card').toggleClass('expanded');
    });

    // Pagination
    $('.log-pagination button').click(function() {
        const page = $(this).data('page');
        $.post(ajaxurl, {
            action: 'get_webhook_logs',
            page: page,
            security: webhookLogs.nonce
        }, function(response) {
            $('#webhook-logs').html(response.html);
            $('.current-page').text(response.page);
        });
    });

    // Reset logs
    $('#resetLogs').click(function() {
        if(confirm('Are you sure you want to delete all logs?')) {
            $.post(ajaxurl, {
                action: 'reset_webhook_logs',
                security: webhookLogs.nonce
            }, function() {
                $('#webhook-logs').empty();
            });
        }
    });

    // Fetch logs function
    function fetchLogs(page) {
        $.post(ajaxurl, {
            action: 'get_logs',
            security: webhookLogs.nonce,
            page: page || 1
        }, function(response) {
            if(response.success) {
                $('#webhookLogsContainer').html(response.data.html);
            } else {
                console.error('Error loading logs:', response.data);
                $('#webhookLogsContainer').html('<div class="notice notice-error">Error loading logs</div>');
            }
        }).fail(function(xhr) {
            console.error('Log request failed:', xhr.responseText);
            $('#webhookLogsContainer').html('<div class="notice notice-error">Request failed: ' + xhr.statusText + '</div>');
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

    // Initial load
    fetchLogs(1);

    // Test toggle button
    $('#testToggle').click(function(e) {
        e.preventDefault();
        const $btn = $(this);
        const isTesting = $btn.hasClass('active');
        
        $.post(ajaxurl, {
            action: isTesting ? 'stop_webhook_test' : 'start_webhook_test',
            security: webhookLogs.nonce // Use localized nonce
        }).done(function() {
            $btn.toggleClass('active')
                .toggleClass('button-primary', isTesting)
                .toggleClass('button-secondary', !isTesting)
                .text(isTesting ? 'Start Listening' : 'Stop Testing');
            $('#testStatus').toggle(!isTesting);
        });
    });

    // Test mode polling
    if($('#testStatus').is(':visible')) {
        let pollInterval = setInterval(() => {
            $.post(ajaxurl, {
                action: 'get_webhook_test',
                security: webhookLogs.nonce
            }, function(response) {
                if(response.success) {
                    if(response.data) {
                        $('#testResults').html('<pre>' + JSON.stringify(response.data, null, 2) + '</pre>');
                    }
                    if(!response.data || !response.data.test_active) {
                        clearInterval(pollInterval);
                        $('#testStatus').hide();
                        $('#testToggle').text('Start Listening').removeClass('button-secondary').addClass('button-primary');
                    }
                }
            });
        }, 3000);
    }

    // Add clipboard initialization debug
    console.log('Clipboard initialized:', typeof ClipboardJS);
    new ClipboardJS('.copy-key, .copy-url', {
        text: function(trigger) {
            console.log('Clipboard target:', trigger);
            return trigger.dataset.clipboardTarget ? 
                document.querySelector(trigger.dataset.clipboardTarget).value :
                trigger.dataset.clipboardText;
        }
    });
});
