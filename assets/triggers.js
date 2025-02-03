jQuery(document).ready(function($) {
    // Toggle custom headers section
    $('.toggle-headers').on('click', function() {
        $(this).siblings('.custom-headers').slideToggle();
    });

    // Handle trigger checkbox changes
    $('input[name^="webhook_trigger_"]').on('change', function() {
        var triggerName = $(this).attr('name');
        var isChecked = $(this).prop('checked');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'toggle_trigger',
                trigger: triggerName,
                enabled: isChecked ? 'on' : 'off',
                nonce: webhook_settings.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Show success message
                    alert('Trigger setting saved successfully!');
                } else {
                    // Show error message and revert checkbox
                    alert('Failed to save trigger setting.');
                    $(this).prop('checked', !isChecked);
                }
            },
            error: function() {
                alert('Failed to save trigger setting.');
                $(this).prop('checked', !isChecked);
            }
        });
    });

    // Handle URL input changes
    $('input[name$="_url"]').on('change', function() {
        var triggerName = $(this).attr('name').replace('_url', '');
        var url = $(this).val();
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'save_trigger_url',
                trigger: triggerName,
                url: url,
                nonce: webhook_settings.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Show success message
                    alert('URL saved successfully!');
                } else {
                    alert('Failed to save URL.');
                }
            },
            error: function() {
                alert('Failed to save URL.');
            }
        });
    });

    // Handle headers textarea changes
    $('textarea[name$="_headers"]').on('change', function() {
        var triggerName = $(this).attr('name').replace('_headers', '');
        var headers = $(this).val();
        
        // Validate JSON format
        try {
            JSON.parse(headers);
        } catch (e) {
            alert('Invalid JSON format for headers. Please check your input.');
            return;
        }
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'save_trigger_headers',
                trigger: triggerName,
                headers: headers,
                nonce: webhook_settings.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert('Headers saved successfully!');
                } else {
                    alert('Failed to save headers.');
                }
            },
            error: function() {
                alert('Failed to save headers.');
            }
        });
    });
});
