jQuery(document).ready(function($) {
    
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
    
    // Initial fetch
    fetchLogs(1);

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

    // Render log function
    function renderLog(log) {
        const logContainer = document.createElement('div');
        logContainer.classList.add('log-entry');

        // Create collapsible panels
        const headersPanel = createCollapsiblePanel('Headers', log.headers, true);
        const paramsPanel = createCollapsiblePanel('Parameters', log.params);
        const responsePanel = createCollapsiblePanel('Response', log.response);

        // Append panels in the desired order
        logContainer.appendChild(headersPanel);
        logContainer.appendChild(paramsPanel);
        logContainer.appendChild(responsePanel);

        return logContainer;
    }

    // Create collapsible panel function
    function createCollapsiblePanel(title, content, collapsed = false) {
        const panel = document.createElement('div');
        panel.classList.add('collapsible-panel');
        
        const panelTitle = document.createElement('h3');
        panelTitle.classList.add('collapsible-title');
        panelTitle.style.cursor = 'pointer';
        
        // Create the toggle button element
        const toggleBtn = document.createElement('span');
        toggleBtn.classList.add('toggle-btn');
        toggleBtn.textContent = collapsed ? '[+]' : '[-]';
        toggleBtn.style.marginRight = '10px';
        
        // Create a separate span for the title text
        const titleSpan = document.createElement('span');
        titleSpan.classList.add('collapsible-text');
        titleSpan.textContent = title;
        
        // Append the toggle button and title span to the panel title
        panelTitle.appendChild(toggleBtn);
        panelTitle.appendChild(titleSpan);
        
        const panelContent = document.createElement('div');
        panelContent.classList.add('collapsible-content');
        panelContent.innerHTML = content.replace(/(https?:\/\/[^\s]+)/g, '<a href="$1" target="_blank">$1</a>');
        panelContent.style.display = collapsed ? 'none' : 'block';
        
        // Single event listener on the panel title to toggle the content display
        panelTitle.addEventListener('click', function() {
            if (panelContent.style.display === 'none') {
                panelContent.style.display = 'block';
                toggleBtn.textContent = '[-]';
            } else {
                panelContent.style.display = 'none';
                toggleBtn.textContent = '[+]';
            }
        });
        
        panel.appendChild(panelTitle);
        panel.appendChild(panelContent);
        
        return panel;
    }

    // CSS to handle collapsible behavior
    const style = document.createElement('style');
    style.textContent = `
    .log-toggle {
        display: inline-block;
        margin-left: 10px;
        cursor: pointer;
    }
    .log-summary {
        cursor: pointer;
    }
    .collapsible-content.collapsed {
        display: none;
    }
    .collapsible-content.expanded {
        display: block;
    }
`;
    document.head.appendChild(style);

    // Ensure the log-summary is clickable
    $('#webhookLogsContainer').on('click', '.log-summary', function(e) {
        e.preventDefault();
        e.stopPropagation();

        const $card = $(this).closest('.log-card');
        const $details = $card.find('.log-details');

        $details.slideToggle(200, function() {
            const isVisible = $details.is(':visible');
            $card.toggleClass('expanded', isVisible);
        });
    });

    document.addEventListener('DOMContentLoaded', function() {
        const logEntries = document.querySelectorAll('.log-entry');
        logEntries.forEach(entry => {
            const sections = entry.querySelectorAll('.log-section');
            sections.forEach(section => {
                const title = section.querySelector('strong');
                const content = section.querySelector('pre');
                const arrow = document.createElement('span');
                arrow.textContent = '▶'; // Right arrow
                arrow.style.marginLeft = '5px';
                title.appendChild(arrow);
                title.style.cursor = 'pointer';
                content.style.display = 'none'; // Initially hide content
                title.addEventListener('click', () => {
                    const isCollapsed = content.style.display === 'none';
                    content.style.display = isCollapsed ? 'block' : 'none';
                    arrow.textContent = isCollapsed ? '▼' : '▶'; // Down arrow when expanded
                });
            });
        });
    });
});
