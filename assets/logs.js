jQuery(document).ready(function($) {
    // Remove any existing event handlers
    $('#webhookLogsContainer').off('click');

    // Toggle log details
    $('#webhookLogsContainer').on('click', '.log-toggle', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        const $btn = $(this);
        const $details = $btn.closest('.log-card').find('.log-details');
        const isVisible = $details.is(':visible');
        
        console.log('Button clicked:', {
            isVisible,
            buttonText: $btn.text(),
            detailsCount: $details.length,
            detailsDisplay: $details.css('display')
        });

        if (isVisible) {
            $btn.text('Show Details');
            $details.hide().removeClass('expanded');
        } else {
            $btn.text('Hide Details');
            $details.show().addClass('expanded');
        }
    });

    // Toggle collapsible sections
    $('#webhookLogsContainer').on('click', '.collapsible-title', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        const $title = $(this);
        const $content = $title.next('.collapsible-content');
        const $toggleBtn = $title.find('.toggle-btn');
        const isVisible = $content.is(':visible');
        
        console.log('Collapsible clicked:', {
            isVisible,
            toggleText: $toggleBtn.text()
        });

        if (isVisible) {
            $toggleBtn.text('[+]');
            $content.hide().removeClass('expanded');
        } else {
            $toggleBtn.text('[-]');
            $content.show().addClass('expanded');
        }
    });

    // Fetch logs function
    function fetchLogs(page) {
        const $container = $('#webhookLogsContainer');
        const $refreshBtn = $('#refreshLogs');
        const $spinner = $('<span class="spinner is-active" style="float: none; margin-left: 4px;"></span>');
        
        // Only show spinner if it's a manual refresh
        if (page === 1) {
            $refreshBtn.prop('disabled', true).after($spinner);
        }
        
        $.post(ajaxurl, {
            action: 'get_logs',
            security: webhookLogs.nonce,
            page: page || 1
        }).done(function(response) {
            if(response.success) {
                $container.html(response.data.html);
                console.log('Logs HTML updated');
                initCollapsibleLogSections();
            } else {
                console.error('Error loading logs:', response.data);
                $container.html('Error loading logs');
            }
            $refreshBtn.prop('disabled', false);
            $spinner.remove();
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

    // Pre-treat values in HTML code
    let encodedContent = document.createElement('pre');

    // Syntax highlighting for JSON and HTML
    if (typeof content === 'string') {
        console.log('isHTMLString(content):', isHTMLString(content)); // Add this line
        console.log('isJsonString(content):', isJsonString(content)); // Add this line
        if (isJsonString(content)) {
            encodedContent.textContent = JSON.stringify(JSON.parse(content), null, 2);
            encodedContent.innerHTML = syntaxHighlight(encodedContent.textContent);
        } else if (isHTMLString(content)) {
            encodedContent.textContent = content;
            encodedContent.innerHTML = syntaxHighlight(encodedContent.textContent);
        } else {
            encodedContent.textContent = content;
        }
    } else if (typeof content === 'object') {
        encodedContent.textContent = JSON.stringify(content, null, 2);
        encodedContent.innerHTML = syntaxHighlight(encodedContent.textContent);
    } else {
        encodedContent.textContent = String(content);
    }

    panelContent.appendChild(encodedContent);
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

    // Helper function to check if a string is JSON
function isJsonString(str) {
    try {
        JSON.parse(str);
    } catch (e) {
        return false;
    }
    return true;
}

// Helper function to check if a string is HTML
function isHTMLString(str) {
    const doc = new DOMParser().parseFromString(str, 'text/html');
    return doc.body.innerHTML !== '';
}

// Helper function to apply syntax highlighting
function syntaxHighlight(json) {
    json = json.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    json = json.replace(/(https?:\/\/[^\s"]+)/g, '<a href="$1" target="_blank">$1</a>'); // Make URLs clickable
    return json.replace(/("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/g, function (match) {
        let cls = 'number';
        if (/^"/.test(match)) {
            if (/:$/.test(match)) {
                cls = 'key';
            } else {
                cls = 'string';
            }
        } else if (/true|false/.test(match)) {
            cls = 'boolean';
        } else if (/null/.test(match)) {
            cls = 'null';
        }
        return '<span class="'+ cls +'">'+ match +'</span>';
    });
}

    // CSS to handle collapsible behavior
    const style = document.createElement('style');
    style.textContent = `
    .collapsible-panel {
        margin-bottom: 10px;
        border: 1px solid #ccc;
        border-radius: 5px;
    }

    .collapsible-title {
        padding: 10px;
        background-color: #f0f0f0;
        border-bottom: 1px solid #ccc;
        margin: 0;
        display: flex;
        align-items: center; /* Vertically center the content */
        justify-content: space-between; /* Distribute space evenly */
    }

    .collapsible-content {
        padding: 10px;
    }

    .toggle-btn {
        cursor: pointer;
        margin-right: 5px;
    }

    pre {
        white-space: pre-wrap;       /* Since CSS 2.1 */
        white-space: -moz-pre-wrap;  /* Mozilla, since 1999 */
        white-space: -pre-wrap;      /* Opera 4-6 */
        white-space: -o-pre-wrap;    /* Opera 7 */
        word-wrap: break-word;       /* Internet Explorer 5.5+ */
    }

    .key { color: orange; }
    .string { color: green; }
    .number { color: darkkhaki; }
    .boolean { color: plum; }
    .null { color: lightblue; }
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

    function initCollapsibleLogSections() {
        console.log('Initializing collapsible log sections');
        const logEntries = document.querySelectorAll('.log-entry');
        logEntries.forEach(entry => {
            const sections = entry.querySelectorAll('.log-section');
            sections.forEach(section => {
                const title = section.querySelector('strong');
                if (!title) return;
                // Check if arrow already exists
                let arrow = title.querySelector('span.collapsible-arrow');
                if (!arrow) {
                    arrow = document.createElement('span');
                    arrow.classList.add('collapsible-arrow');
                    arrow.textContent = '▶';
                    arrow.style.marginLeft = '5px';
                    title.appendChild(arrow);
                }
                title.style.cursor = 'pointer';
                const content = section.querySelector('pre');
                if (content) {
                    content.style.display = 'none';
                    // Directly add event listener to the title
                    title.addEventListener('click', () => {
                        const isHidden = content.style.display === 'none';
                        content.style.display = isHidden ? 'block' : 'none';
                        arrow.textContent = isHidden ? '▼' : '▶';
                        console.log('Toggled log section:', { expanded: !isHidden });
                    });
                }
            });
        });
    }

});
