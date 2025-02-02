<?php
/**
 * Plugin Name: Simple Webhook Handler
 * Description: Custom API-Rest webhook endpoint for media upload and post creation
 * Version: 1.8.15
 * Author: Felipe Matos
 */

class Webhook_Handler {
    public function __construct() {
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        add_action('rest_api_init', [$this, 'register_routes']);
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp_ajax_start_webhook_test', [$this, 'start_test_mode']);
        add_action('wp_ajax_stop_webhook_test', [$this, 'stop_test_mode']);
        add_action('wp_ajax_get_webhook_test', [$this, 'get_test_results']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_get_logs', [$this, 'ajax_get_logs']);
        add_action('wp_ajax_clear_logs', [$this, 'clear_logs']);
        add_action('wp_ajax_refresh_auth_key', [$this, 'refresh_auth_key']);
        
        // Add plugin action links
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'plugin_settings_link']);
    }

    public function plugin_settings_link($links) {
        $settings_link = '<a href="options-general.php?page=webhook-settings">' . __('Settings') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    public function create_log_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'webhook_logs';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            time datetime NOT NULL,
            endpoint varchar(100) NOT NULL,
            method varchar(10) NOT NULL,
            headers text NOT NULL,
            params text NOT NULL,
            files text NOT NULL,
            response text NOT NULL,
            status_code smallint(3) NOT NULL,
            ip varchar(45) NOT NULL,
            PRIMARY KEY  (id),
            KEY endpoint (endpoint),
            KEY status_code (status_code)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public function register_routes() {
        register_rest_route('webhook/v1', '/(?P<action>[a-zA-Z0-9-_]+)', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_request'],
            'permission_callback' => [$this, 'verify_request'],
            'args' => [
                'action' => [
                    'required' => true,
                    'validate_callback' => function($param) {
                        return in_array($param, ['upload', 'create-post']);
                    }
                ]
            ]
        ]);
    }

    public function add_settings_page() {
        add_options_page(
            'Webhook Settings',
            'Webhook',
            'manage_options',
            'webhook-settings',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings() {
        // Auto-generate key if empty
        if(empty(get_option('webhook_auth_key'))) {
            update_option('webhook_auth_key', wp_generate_password(32, false));
        }
        register_setting('webhook_settings', 'webhook_auth_key');
        register_setting('webhook_settings', 'webhook_endpoint');

        add_settings_section(
            'webhook_main',
            'Authentication Settings',
            null,
            'webhook-settings'
        );

        add_settings_section(
            'webhook_security',
            'Security Settings',
            null,
            'webhook-settings'
        );

        add_settings_field(
            'webhook_auth_key',
            'API Auth Key',
            [$this, 'auth_key_field'],
            'webhook-settings',
            'webhook_main'
        );

        add_settings_field(
            'webhook_endpoints',
            'Endpoint URLs',
            [$this, 'endpoint_url_fields'],
            'webhook-settings',
            'webhook_main'
        );

        add_settings_field(
            'webhook_rate_limit',
            'Rate Limit',
            [$this, 'rate_limit_field'],
            'webhook-settings',
            'webhook_security'
        );
    }

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h2>Webhook Settings</h2>
            
            <!-- Test Mode Section -->
            <div class="card">
                <h3>Test Webhook</h3>
                <button id="testToggle" class="button button-primary">
                    <span class="text">Start Listening</span>
                    <span class="spinner"></span>
                </button>
                <div id="testStatus" style="display:none;margin-top:15px;">
                    <div class="notice notice-info">
                        <p>Waiting for requests... <span class="dashicons dashicons-update spin"></span></p>
                    </div>
                    <div id="testResults"></div>
                </div>
            </div>

            <!-- Docs Section -->
            <div class="card" style="margin-top:20px;">
                <h3>API Documentation</h3>
                <?php
                $site_url = site_url();
                $auth_key = get_option('webhook_auth_key');
                
                // Get docs content with real values
                $docs = file_get_contents(__DIR__.'/readme.md');
                $docs = str_replace(
                    ['https://yoursite.com', 'YOUR_KEY'],
                    [$site_url, $auth_key],
                    $docs
                );

                echo "<div class='endpoint-doc'>
                    <pre><code>".esc_html($docs)."</code></pre>
                </div>";
                ?>
            </div>

            <!-- Log viewer section -->
            <div class="card">
                <h3>Request Logs</h3>
                <div class="log-controls">
                    <button class="button" id="refreshLogs">Refresh</button>
                    <button class="button button-danger" id="clearLogs">Clear All Logs</button>
                </div>
                <div id="webhookLogsContainer"></div>
            </div>

            <!-- Existing Settings Form -->
            <form action="options.php" method="post">
                <?php
                settings_fields('webhook_settings');
                do_settings_sections('webhook-settings');
                ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">API Auth Key</th>
                        <td>
                            <input type="text" id="webhook_auth_key" name="webhook_auth_key" value="<?php echo esc_attr(get_option('webhook_auth_key')); ?>" readonly />
                            <button type="button" id="refresh-auth-key" class="button"><span class="dashicons dashicons-update"></span></button>
                            <button type="button" id="copy-auth-key" class="button"><span class="dashicons dashicons-admin-page"></span></button>
                        </td>
                    </tr>
                </table>
            </form>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            const toggle = $('#testToggle');
            const status = $('#testStatus');
            let isTesting = false;

            toggle.click(function(e) {
                e.preventDefault();
                isTesting = !isTesting;
                
                $.post(ajaxurl, {
                    action: isTesting ? 'start_webhook_test' : 'stop_webhook_test',
                    security: '<?php echo wp_create_nonce('webhook_test'); ?>'
                }, function(response) {
                    status.toggle(isTesting);
                    toggle.find('.text').text(isTesting ? 'Stop Testing' : 'Start Listening');
                    toggle.toggleClass('button-primary button-secondary');
                });

                if(isTesting) checkForResults();
            });

            function checkForResults() {
                if(!isTesting) return;
                $.get(ajaxurl + '?action=get_webhook_test', function(data) {
                    $('#testResults').html('<pre>' + JSON.stringify(data, null, 2) + '</pre>');
                    setTimeout(checkForResults, 2000);
                });
            }

            // Log viewer
            const logContainer = $('#webhookLogsContainer');
            const refreshButton = $('#refreshLogs');
            const clearButton = $('#clearLogs');

            refreshButton.click(function(e) {
                e.preventDefault();
                $.post(ajaxurl, {
                    action: 'get_logs',
                    security: '<?php echo wp_create_nonce('webhook_logs'); ?>',
                    page: 1
                }, function(response) {
                    if(response.success) {
                        logContainer.html(response.data.html);
                    } else {
                        console.error('Error loading logs:', response.data);
                        logContainer.html('<div class="notice notice-error">Error loading logs</div>');
                    }
                }).fail(function(xhr) {
                    console.error('Log request failed:', xhr.responseText);
                    logContainer.html('<div class="notice notice-error">Request failed: ' + xhr.statusText + '</div>');
                });
            });

            clearButton.click(function(e) {
                e.preventDefault();
                $.post(ajaxurl, {
                    action: 'clear_logs',
                    security: '<?php echo wp_create_nonce('webhook_logs'); ?>'
                }, function(response) {
                    if(response.success) {
                        logContainer.html('');
                    } else {
                        console.error('Error clearing logs:', response.data);
                    }
                });
            });

            // Trigger initial load
            refreshButton.trigger('click');

            // Refresh auth key
            $('#refresh-auth-key').click(function(e) {
                e.preventDefault();
                $.post(ajaxurl, {
                    action: 'refresh_auth_key',
                    security: '<?php echo wp_create_nonce('webhook_auth_key'); ?>'
                }, function(response) {
                    if(response.success) {
                        $('#webhook_auth_key').val(response.data);
                    } else {
                        console.error('Error refreshing auth key:', response.data);
                    }
                });
            });

            // Copy auth key
            $('#copy-auth-key').click(function(e) {
                e.preventDefault();
                const authKey = document.getElementById("webhook_auth_key");
                authKey.select();
                document.execCommand("copy");
                alert("Auth Key copied to clipboard");
            });
        });
        </script>
        <?php
    }

    public function enqueue_assets() {
        wp_enqueue_script(
            'clipboard', 
            'https://cdnjs.cloudflare.com/ajax/libs/clipboard.js/2.0.11/clipboard.min.js',
            [],
            '2.0.11'
        );
        
        wp_add_inline_script('clipboard', "document.addEventListener('DOMContentLoaded', function() { new ClipboardJS('.copy-key, .copy-url', { text: function(trigger) { return trigger.dataset.clipboardTarget ? document.querySelector(trigger.dataset.clipboardTarget).value : trigger.dataset.clipboardText; }}); });");
        
        wp_enqueue_style(
            'webhook-test-mode',
            plugins_url('assets/test-mode.css', __FILE__)
        );
        
        wp_enqueue_style(
            'webhook-logs',
            plugins_url('assets/logs.css', __FILE__)
        );
        wp_enqueue_script(
            'webhook-settings',
            plugins_url('assets/logs.js', __FILE__),
            ['jquery', 'clipboard'],
            '1.0',
            true
        );
        
        wp_localize_script('webhook-settings', 'webhookLogs', [
            'nonce' => wp_create_nonce('webhook_logs')
        ]);
    }

    public function start_test_mode() {
        set_transient('webhook_test_mode', true, 3600);
        delete_transient('webhook_test_data'); // Clear previous test data
        wp_send_json_success([
            'message' => 'Test mode activated',
            'test_active' => true
        ]);
    }

    public function stop_test_mode() {
        delete_transient('webhook_test_mode');
        delete_transient('webhook_test_data');
        wp_send_json_success([
            'message' => 'Test mode deactivated',
            'test_active' => false
        ]);
    }

    public function get_test_results() {
        if (!get_transient('webhook_test_mode')) {
            wp_send_json_success(['test_active' => false]);
            return;
        }

        $data = get_transient('webhook_test_data');
        if(!$data) {
            wp_send_json_success([
                'test_active' => true,
                'message' => 'Waiting for requests...'
            ]);
            return;
        }

        wp_send_json_success([
            'test_active' => true,
            'results' => $data
        ]);
    }

    public function clear_logs() {
        check_ajax_referer('webhook_logs', 'security');
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'webhook_logs';
        
        $wpdb->query("TRUNCATE TABLE {$table_name}");
        wp_send_json_success();
    }

    public function refresh_auth_key() {
        check_ajax_referer('webhook_auth_key', 'security');
        
        $new_key = wp_generate_password(32, false);
        update_option('webhook_auth_key', $new_key);
        wp_send_json_success(['data' => $new_key]);
    }

    public function auth_key_field() {
        $key = esc_attr(get_option('webhook_auth_key'));
        echo "<div class='auth-key-wrapper' style='display:flex;gap:10px;align-items:center;'>
            <input type='text' id='webhook_auth_key' value='{$key}' class='regular-text' readonly>
            <button type='button' id='refresh-auth-key' class='button'><span class='dashicons dashicons-update'></span></button>
            <button type='button' id='copy-auth-key' class='button'><span class='dashicons dashicons-admin-page'></span></button>
        </div>";
        echo "<p class='description'>Authentication key required in X-Auth-Key header</p>";
    }

    public function endpoint_url_fields() {
        $base_url = rest_url('webhook/v1/');
        $endpoints = [
            'auth' => 'Authentication',
            'upload' => 'Media Upload',
            'create-post' => 'Create Post'
        ];

        echo "<table class='form-table'><tbody>";
        foreach($endpoints as $path => $label) {
            $full_url = $base_url . $path;
            echo "<tr>
                <th scope='row'>{$label}</th>
                <td>
                    <div style='display:flex;gap:10px;align-items:center;'>
                        <input type='text' value='{$full_url}' class='regular-text' readonly>
                        <button type='button' class='button button-secondary copy-url' data-clipboard-text='{$full_url}'>
                            <span class='dashicons dashicons-clipboard'></span>
                        </button>
                    </div>
                </td>
            </tr>";
        }
        echo "</tbody></table>";
    }

    public function rate_limit_field() {
        echo "<p class='description'>Rate limiting is enabled by default. Maximum 5 requests per minute.</p>";
    }

    private function get_auth_key() {
        return get_option('webhook_auth_key');
    }

    public function handle_request($request) {
        global $wpdb;
        
        try {
            // Verificação de autorização já realizada pelo 'permission_callback'

            // Verificar Limitação de Taxa
            if ($this->is_rate_limited()) {
                return $this->format_error_response(new WP_Error('rate_limited', 'Too many requests', ['status' => 429]));
            }

            $response_data = $this->process_request($request);
            $status_code = is_wp_error($response_data) ? ($response_data->get_error_data()['status'] ?? 500) : 200;
            if (!is_wp_error($response_data) && is_array($response_data) && isset($response_data['mediaId'])) {
                $data = array_merge(['success' => true], $response_data);
            } else {
                $response_json = is_wp_error($response_data) ? $response_data->get_error_message() : $response_data;
                $data = [
                    'success' => !is_wp_error($response_data),
                    'data' => $response_json
                ];
            }

            // Log the response data before sending
            $log_data = [
                'time' => current_time('mysql'),
                'endpoint' => $request->get_route(),
                'method' => $request->get_method(),
                'headers' => wp_json_encode($request->get_headers(), JSON_UNESCAPED_SLASHES),
                'params' => wp_json_encode($request->get_params(), JSON_FORCE_OBJECT | JSON_UNESCAPED_SLASHES),
                'files' => wp_json_encode($request->get_file_params(), JSON_UNESCAPED_SLASHES),
                'ip' => $_SERVER['REMOTE_ADDR'],
                'status_code' => $status_code,
                'response' => wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            ];

            // Insert log into the database
            $log_result = $wpdb->insert(
                $wpdb->prefix . 'webhook_logs',
                $log_data,
                ['%s','%s','%s','%s','%s','%s','%d','%s']
            );

            if (false === $log_result) {
                error_log('Webhook Logging Failed: ' . $wpdb->last_error);
            }

            // Set response headers
            header('Access-Control-Allow-Origin: *');
            header('Content-Type: application/json');
            
            // Send JSON response
            wp_send_json($data);
        } catch (Exception $e) {
            error_log('Webhook Critical Error: ' . $e->getMessage());
            return new WP_REST_Response([
                'error' => [
                    'code' => 'internal_error',
                    'message' => 'Internal Server Error',
                    'details' => WP_DEBUG ? $e->getMessage() : null
                ]
            ], 500);
        }
    }

    private function format_error_response(WP_Error $error) {
        $error_body = [
            'success' => false,
            'error' => [
                'code' => $error->get_error_code(),
                'message' => $error->get_error_message(),
                'details' => $error->get_error_data()
            ]
        ];

        $status_code = $error->get_error_data()['status'] ?? 500;

        $rest_response = new WP_REST_Response($error_body, $status_code);
        $rest_response->set_headers(['Content-Type' => 'application/json']);
        return $rest_response;
    }

    private function format_response($response) {
        if (is_wp_error($response)) {
            return $this->format_error_response($response);
        }

        // Ensure all responses go through this formatter
        return new WP_REST_Response([
            'success' => true,
            'data' => $response
        ], 200);
    }

    private function process_request($request) {
        if(get_transient('webhook_test_mode')) {
            return [
                'test_mode' => true,
                'captured_data' => [
                    'action' => $request['action'],
                    'params' => $request->get_params(),
                    'files' => $request->get_file_params(),
                    'headers' => $request->get_headers()
                ]
            ];
        }
    
        $action = $request['action'];
        
        switch($action) {
            case 'upload':
                return $this->handle_upload($request);
            case 'create-post':
                return $this->create_post($request);
            default:
                return new WP_Error('invalid_action', 'Invalid action specified', ['status' => 400]);
        }
    }

    private function handle_upload($request) {
        $files = $request->get_file_params();
        $params = $request->get_params();
        
        if(empty($files['file']) && empty($params['file_url'])) {
            return new WP_Error('no_file', 'No file uploaded or URL provided', ['status' => 400]);
        }

        if (!empty($params['file_url'])) {
            return $this->handle_upload_from_url($params['file_url']);
        }

        $allowed_types = ['image/jpeg', 'image/png', 'application/pdf'];
        if (!in_array($files['file']['type'], $allowed_types)) {
            return new WP_Error('invalid_type', 'Unsupported file type', ['status' => 400]);
        }

        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        $upload = wp_handle_upload($files['file'], ['test_form' => false]);

        if (isset($upload['error'])) {
            error_log('Upload Error: ' . $upload['error']);
            error_log('File Path: ' . $files['file']['tmp_name']);
            return new WP_Error('upload_error', [
                'error' => $upload['error'],
                'file_path' => $files['file']['tmp_name'],
                'file_type' => $files['file']['type'],
                'file_size' => $files['file']['size'],
            ], ['status' => 500]);
        }

        $attachment = [
            'post_title' => basename($upload['file']),
            'post_content' => '',
            'post_status' => 'inherit',
            'guid' => $upload['url'],
            'post_mime_type' => $files['file']['type']
        ];

        $attachment_id = wp_insert_attachment($attachment, $upload['file']);
        $metadata = wp_generate_attachment_metadata($attachment_id, $upload['file']);
        wp_update_attachment_metadata($attachment_id, $metadata);

        if (is_wp_error($attachment_id)) {
            @unlink($upload['file']);
            return $attachment_id;
        }

        return [
            'mediaID' => $attachment_id,
            'mediaUrl' => wp_get_attachment_url($attachment_id),
            'editUrl' => admin_url("post.php?post=$attachment_id&action=edit")
        ];
    }

    private function create_post($request) {
        $required = ['title', 'content', 'author'];
        foreach ($required as $field) {
            if (empty($request[$field])) {
                return new WP_Error('missing_field', "Missing required field: $field", ['status' => 400]);
            }
        }

        $post_data = [
            'post_title' => sanitize_text_field($request['title']),
            'post_content' => wp_kses_post($request['content']),
            'post_status' => $request['status'] ?? 'draft',
            'post_author' => intval($request['author']),
            'post_type' => 'post'
        ];

        $post_id = wp_insert_post($post_data, true);
        if (is_wp_error($post_id)) {
            return new WP_Error('post_creation_failed', $post_id->get_error_message(), ['status' => 500]);
        }

        // Handle featured image from URL
        if (!empty($request['featured_image'])) {
            $attachment_id = $this->handle_upload_from_url($request['featured_image']);
            if (!is_wp_error($attachment_id)) {
                set_post_thumbnail($post_id, $attachment_id);
            }
        }

        // Handle featured media by ID
        if (!empty($request['featuredMediaId'])) {
            $media_id = intval($request['featuredMediaId']);
            if (get_post_type($media_id) === 'attachment') {
                set_post_thumbnail($post_id, $media_id);
            } else {
                return new WP_Error('invalid_media', 'Invalid media ID provided', ['status' => 400]);
            }
        }

        return [
            'postID' => $post_id,
            'postUrl' => get_permalink($post_id),
            'postEditUrl' => admin_url("post.php?post=$post_id&action=edit"),
            'published' => ('publish' === ($post_data['post_status'] ?? 'draft'))
        ];
    }

    private function handle_upload_from_url($url) {
        $upload = wp_upload_bits(basename($url), null, file_get_contents($url));
        if (!$upload['error']) {
            $wp_filetype = wp_check_filetype($upload['file'], null);
            $attachment = [
                'post_mime_type' => $wp_filetype['type'],
                'post_title' => preg_replace('/\.[^.]+$/', '', basename($url)),
                'post_content' => '',
                'post_status' => 'inherit'
            ];
            $attach_id = wp_insert_attachment($attachment, $upload['file']);
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            $attach_data = wp_generate_attachment_metadata($attach_id, $upload['file']);
            wp_update_attachment_metadata($attach_id, $attach_data);

            return  [
                'success' => true,
                'mediaId' => $attach_id,
                'public_url' => wp_get_attachment_url($attach_id),
                'edit_url' => admin_url("upload.php?item=$attach_id")
            ];
        } else {
            return new WP_Error('upload_error', $upload['error'], ['status' => 500]);
        }
    }

    private function sanitize_post_status($status) {
        $allowed = ['draft', 'publish', 'pending', 'private'];
        return in_array($status, $allowed) ? $status : 'draft';
    }

    private function validate_author($user_id) {
        $user = get_userdata(absint($user_id));
        return $user ? $user->ID : 1;
    }

    public function verify_request($request) {
        $auth_header = $request->get_header('X-Auth-Key');
        if(empty($auth_header)) {
            return new WP_Error('missing_auth', 'Authentication required', ['status' => 401]);
        }
        if (!hash_equals($this->get_auth_key(), $auth_header)) {
            return new WP_Error('invalid_auth', 'Invalid authentication key', ['status' => 403]);
        }
        return true;
    }

    private function is_rate_limited() {
        $transient_name = 'webhook_limit_' . $_SERVER['REMOTE_ADDR'];
        $attempts = get_transient($transient_name) ?: 0;
        
        if($attempts >= 5) { // Permit exatamente 5 tentativas
            return true;
        }

        set_transient($transient_name, $attempts + 1, MINUTE_IN_SECONDS);
        return false;
    }

    public function ajax_get_logs() {
        check_ajax_referer('webhook_logs', 'security');
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'webhook_logs';
        
        // Temporary debug
        error_log('[Webhook] Log table exists: '.$wpdb->get_var("SHOW TABLES LIKE '$table_name'"));
        error_log('[Webhook] Last SQL error: '.$wpdb->last_error);

        $logs = $wpdb->get_results("SELECT * FROM $table_name ORDER BY time DESC LIMIT 20");
        error_log('[Webhook] Logs found: '.print_r($logs, true));
        
        wp_send_json_success([
            'html' => $this->render_logs_html($logs),
            'page' => 1
        ]);
    }

    private function render_logs_html($logs) {
        $html = '';
        foreach ($logs as $log) {
            $html .= $this->render_log_card($log);
        }
        return $html;
    }

    private function render_log_card($log) {
        $params = json_decode($log->params, true) ?: [];
        $headers = json_decode($log->headers, true) ?: [];
        
        $status_class = 'status-' . substr($log->status_code, 0, 1);
        return '
        <div class="log-card ' . $status_class . '">
            <div class="log-summary">
                <div class="log-info">
                    <span class="log-time">'.mysql2date('M j, Y H:i:s', $log->time).'</span>
                    <span class="log-status">HTTP '.$log->status_code.'</span>
                    <span class="log-method">'.$log->method.'</span>
                    <span class="log-endpoint"><a href="'.$log->endpoint.'" target="_blank">'.$log->endpoint.'</a></span>
                </div>
                <button class="log-toggle button button-small">Details</button>
            </div>
            <div class="log-details">
                <div class="log-section">
                    <strong>Headers:</strong> 
                    <pre>'.wp_kses_post($this->make_clickable(print_r($headers, true))).'</pre>
                </div>
            <div class="log-section">
                    <strong>Parameters:</strong>
                    <pre>'.wp_kses_post($this->make_clickable(print_r($params, true))).'</pre>
                </div>
                <div class="log-section">
                    <strong>Response:</strong> 
                    <pre>'.wp_kses_post($this->make_clickable($log->response)).'</pre>
                </div>
            </div>
        </div>';
    }

    private function make_clickable($text) {
        return preg_replace(
            '/(https?:\/\/\S+)/',
            '<a href="$1" target="_blank">$1</a>',
            $text
        );
    }

    public function activate() {
        $this->create_log_table();
        flush_rewrite_rules();
    }

    public function deactivate() {
        flush_rewrite_rules();
    }
}

new Webhook_Handler();