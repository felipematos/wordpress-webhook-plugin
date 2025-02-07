<?php
/**
 * Plugin Name: Simple Webhook Handler
 * Description: Custom API-Rest webhook endpoint for media upload, post creation and post retrivael.
 * Author: Felipe Matos
 * Version: 1.9.13
 */

 
class Webhook_Handler {
    public function __construct() {
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        add_action('rest_api_init', [$this, 'register_routes']);
        add_filter('rest_pre_dispatch', [$this, 'log_invalid_json_request'], 10, 3);
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp_ajax_start_webhook_test', [$this, 'start_test_mode']);
        add_action('wp_ajax_stop_webhook_test', [$this, 'stop_test_mode']);
        add_action('wp_ajax_get_webhook_test', [$this, 'get_test_results']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_get_logs', [$this, 'ajax_get_logs']);
        add_action('wp_ajax_clear_logs', [$this, 'clear_logs']);
        add_action('wp_ajax_refresh_auth_key', [$this, 'refresh_auth_key']);
        add_action('wp_ajax_toggle_trigger', [$this, 'ajax_toggle_trigger']);
        add_action('wp_ajax_save_trigger_url', [$this, 'ajax_save_trigger_url']);
        add_action('wp_ajax_save_trigger_headers', [$this, 'ajax_save_trigger_headers']);
        
        // Add trigger hooks
        add_action('save_post', [$this, 'handle_post_created'], 10, 3);
        add_action('transition_post_status', [$this, 'handle_post_published'], 10, 3);
        add_action('wp_insert_comment', [$this, 'handle_new_comment'], 10, 2);
        
        // Add plugin action links
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'plugin_settings_link']);

        // Update version on plugin load
        if (!function_exists('get_plugin_data')) {
            require_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }
        $plugin_data = get_plugin_data(__FILE__);
        update_option('webhook_plugin_version', $plugin_data['Version']);
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
            direction varchar(10) DEFAULT 'incoming',
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
        
        // Register trigger settings
        register_setting('webhook_settings', 'webhook_trigger_post_created');
        register_setting('webhook_settings', 'webhook_trigger_post_created_url');
        register_setting('webhook_settings', 'webhook_trigger_post_created_headers');
        
        register_setting('webhook_settings', 'webhook_trigger_post_published');
        register_setting('webhook_settings', 'webhook_trigger_post_published_url');
        register_setting('webhook_settings', 'webhook_trigger_post_published_headers');
        
        register_setting('webhook_settings', 'webhook_trigger_new_comment');
        register_setting('webhook_settings', 'webhook_trigger_new_comment_url');
        register_setting('webhook_settings', 'webhook_trigger_new_comment_headers');

        add_settings_section(
            'webhook_main',
            'Webhook Settings',
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
            'webhook_security'
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
            <h2>Simple Webhook Handler Settings</h2>
            <p>Plugin Version: <?php echo esc_html(get_option('webhook_plugin_version')); ?></p>
            
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

            <!-- Triggers Section -->
            <div class="card">
                <h3>Triggers</h3>
                <p>Configure webhooks to be triggered on specific WordPress events.</p>
                
                <div class="trigger-item">
                    <label>
                        <input type="checkbox" name="webhook_trigger_post_created" 
                            <?php checked(get_option('webhook_trigger_post_created'), 'on'); ?> />
                        When a new blog post is created
                    </label>
                    <input type="url" name="webhook_trigger_post_created_url" 
                        value="<?php echo esc_attr(get_option('webhook_trigger_post_created_url')); ?>" 
                        placeholder="Enter webhook URL" class="regular-text" />
                    <button type="button" class="button toggle-headers" data-trigger="post_created">Custom Headers</button>
                    <div class="custom-headers" style="display:none;">
                        <textarea name="webhook_trigger_post_created_headers" rows="3" class="large-text" 
                            placeholder='Enter headers in JSON format, e.g: {"X-Auth-Key": "your-key"}'><?php echo esc_textarea(get_option('webhook_trigger_post_created_headers')); ?></textarea>
                    </div>
                </div>

                <div class="trigger-item">
                    <label>
                        <input type="checkbox" name="webhook_trigger_post_published" 
                            <?php checked(get_option('webhook_trigger_post_published'), 'on'); ?> />
                        When a blog post is published
                    </label>
                    <input type="url" name="webhook_trigger_post_published_url" 
                        value="<?php echo esc_attr(get_option('webhook_trigger_post_published_url')); ?>" 
                        placeholder="Enter webhook URL" class="regular-text" />
                    <button type="button" class="button toggle-headers" data-trigger="post_published">Custom Headers</button>
                    <div class="custom-headers" style="display:none;">
                        <textarea name="webhook_trigger_post_published_headers" rows="3" class="large-text" 
                            placeholder='Enter headers in JSON format, e.g: {"X-Auth-Key": "your-key"}'><?php echo esc_textarea(get_option('webhook_trigger_post_published_headers')); ?></textarea>
                    </div>
                </div>

                <div class="trigger-item">
                    <label>
                        <input type="checkbox" name="webhook_trigger_new_comment" 
                            <?php checked(get_option('webhook_trigger_new_comment'), 'on'); ?> />
                        When a new comment is received
                    </label>
                    <input type="url" name="webhook_trigger_new_comment_url" 
                        value="<?php echo esc_attr(get_option('webhook_trigger_new_comment_url')); ?>" 
                        placeholder="Enter webhook URL" class="regular-text" />
                    <button type="button" class="button toggle-headers" data-trigger="new_comment">Custom Headers</button>
                    <div class="custom-headers" style="display:none;">
                        <textarea name="webhook_trigger_new_comment_headers" rows="3" class="large-text" 
                            placeholder='Enter headers in JSON format, e.g: {"X-Auth-Key": "your-key"}'><?php echo esc_textarea(get_option('webhook_trigger_new_comment_headers')); ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Log viewer section -->
            <div class="card">
                <h3>Recent Logs</h3>
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

    public function enqueue_assets($hook) {
        if ('settings_page_webhook-settings' !== $hook) {
            return;
        }

        // Enqueue clipboard.js
        wp_enqueue_script(
            'clipboard', 
            'https://cdnjs.cloudflare.com/ajax/libs/clipboard.js/2.0.11/clipboard.min.js',
            [],
            '2.0.11'
        );

        // Enqueue our plugin scripts and styles
        wp_enqueue_script('webhook-logs', plugins_url('assets/logs.js', __FILE__), array('jquery'), '1.0', true);
        wp_enqueue_script('webhook-triggers', plugins_url('assets/triggers.js', __FILE__), array('jquery'), '1.0', true);
        wp_enqueue_style('webhook-style', plugins_url('assets/style.css', __FILE__));
        wp_enqueue_style('webhook-triggers', plugins_url('assets/triggers.css', __FILE__));

        // Localize script with nonce
        wp_localize_script('webhook-logs', 'webhook_settings', array(
            'nonce' => wp_create_nonce('webhook_nonce')
        ));
        wp_localize_script('webhook-triggers', 'webhook_settings', array(
            'nonce' => wp_create_nonce('webhook_nonce')
        ));
        
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
            'upload' => 'Media Upload',
            'create-post' => 'Create Post',
            'get-post' => 'Get Post'
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

    public function get_post($request) {    
        // Validate that postId is provided 
        if ( empty( $request['postId'] ) ) { 
            return new WP_Error('missing_postId', 'Post ID is required', ['status' => 400]); }
            $postId = absint( $request['postId'] ); 
            $post = get_post( $postId ); 
            if ( !$post ) { 
                return new WP_Error('not_found', 'Post not found', ['status' => 404]); } 
                return (array) $post; }

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
        
        switch ( $request['action'] ) { 
            case 'upload': return $this->handle_upload($request); 
            case 'create-post': return $this->create_post($request); 
            case 'get-post': return $this->get_post($request); 
            default: return new WP_Error('invalid_action', 'Invalid action specified', ['status' => 400]); 
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

        // Handle categories
        if (!empty($request['categories']) && is_array($request['categories'])) {
            $category_ids = [];
            foreach ($request['categories'] as $category_data) {
                if (is_numeric($category_data)) {
                    $category_ids[] = intval($category_data);
                } else {
                    // Unescape category name
                    $category_data = $this->unescape_string($category_data);
                    $category = get_term_by('name', $category_data, 'category');
                    if ($category) {
                        $category_ids[] = intval($category->term_id);
                    } else {
                        $new_category = wp_insert_term($category_data, 'category');
                        if (!is_wp_error($new_category)) {
                            $category_ids[] = intval($new_category['term_id']);
                        }
                    }
                }
            }
            wp_set_post_categories($post_id, $category_ids);
        }

        // Handle tags
        if (!empty($request['tags']) && is_array($request['tags'])) {
            $tag_ids = [];
            foreach ($request['tags'] as $tag_data) {
                if (is_numeric($tag_data)) {
                    $tag_ids[] = intval($tag_data);
                } else {
                    $tag = get_term_by('name', $tag_data, 'post_tag');
                    if ($tag) {
                        $tag_ids[] = intval($tag->term_id);
                    } else {
                        $new_tag = wp_insert_term($tag_data, 'post_tag');
                        if (!is_wp_error($new_tag)) {
                            $tag_ids[] = intval($new_tag['term_id']);
                        }
                    }
                }
            }
            wp_set_post_tags($post_id, $tag_ids);
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
        $direction_arrow = isset($log->direction) && $log->direction === 'outgoing' ? '↑' : '↓';
        $html = '<div class="log-card ' . $status_class . '">'
             . '<div class="log-summary">'
             . '<div class="log-info">'
             . '<span class="log-direction">' . $direction_arrow . '</span>'
             . '<span class="log-time">' . mysql2date('M j, Y H:i:s', $log->time) . '</span>'
             . '<span class="log-status">HTTP ' . $log->status_code . '</span>'
             . '<span class="log-method">' . $log->method . '</span>'
             . '<span class="log-endpoint"><a href="' . $log->endpoint . '" target="_blank">' . $log->endpoint . '</a></span>'
             . '</div>'
             . '<button class="log-toggle button button-small">Show Details</button>'
             . '</div>'
             . '<div class="log-details" style="display:none;">'
             . '<div class="collapsible-panel">'
             . '<h3 class="collapsible-title">'
             . '<span class="toggle-btn">[+]</span>'
             . '<span>Headers</span>'
             . '</h3>'
             . '<div class="collapsible-content" style="display:none;">'
             . '<pre>' . wp_kses_post(json_encode($headers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre>'
             . '</div>'
             . '</div>'
             . '<div class="collapsible-panel">'
             . '<h3 class="collapsible-title">'
             . '<span class="toggle-btn">[+]</span>'
             . '<span>Parameters</span>'
             . '</h3>'
             . '<div class="collapsible-content" style="display:none;">'
             . '<pre>' . wp_kses_post(json_encode($params, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre>'
             . '</div>'
             . '</div>'
             . '<div class="collapsible-panel">'
             . '<h3 class="collapsible-title">'
             . '<span class="toggle-btn">[+]</span>'
             . '<span>Response</span>'
             . '</h3>'
             . '<div class="collapsible-content" style="display:none;">'
             . '<pre>' . wp_kses_post($this->syntax_highlight(esc_html($log->response))) . '</pre>'
             . '</div>'
             . '</div>'
             . '</div>'
             . '</div>';
        
        return $html;
    }

    private function syntax_highlight($json) {
        $json = str_replace('&', '&amp;', htmlspecialchars($json, ENT_NOQUOTES, 'UTF-8'));
        $json = preg_replace('!(https?://[^\s"]+)!i', '<a href="$1" target="_blank">$1</a>', $json);
        $json = preg_replace('/("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/', '<span style="color: #007bff;">$1</span>', $json);
        return $json;
    }

    private function make_clickable($text) {
        // First try to decode if it's a JSON string
        $decoded = json_decode($text, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            // If it's JSON, encode it back with pretty print and then make links clickable
            $text = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        // Make URLs clickable, being careful with JSON formatting
        return preg_replace(
            '/"?(https?:\/\/[^"\s]+)"?/',
            '<a href="$1" target="_blank">$1</a>',
            $text
        );
    }

    public function log_invalid_json_request($result, $server, $request) {
        global $wpdb;
        
        // Log all requests that result in an error
        if (is_wp_error($result)) {
            $raw_body = $request->get_body();
            $error_codes = $result->get_error_codes();
            $error_messages = [];
            
            // Collect all error messages
            foreach ($error_codes as $code) {
                $error_messages[$code] = $result->get_error_message($code);
            }
            
            // For invalid JSON, try to capture the raw body
            if (in_array('rest_invalid_json', $error_codes)) {
                error_log('Invalid JSON received: ' . $raw_body);
            }
            
            $log_data = [
                'time'       => current_time('mysql'),
                'endpoint'   => $request->get_route(),
                'method'     => $request->get_method(),
                'headers'    => wp_json_encode($request->get_headers(), JSON_UNESCAPED_SLASHES),
                'params'     => $raw_body,
                'files'      => wp_json_encode($request->get_file_params(), JSON_UNESCAPED_SLASHES),
                'ip'         => $_SERVER['REMOTE_ADDR'],
                'status_code'=> isset($result->get_error_data()['status']) ? $result->get_error_data()['status'] : 400,
                'response'   => wp_json_encode([
                    'error_codes' => $error_codes,
                    'error_messages' => $error_messages
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            ];
            
            try {
                $log_result = $wpdb->insert(
                    $wpdb->prefix . 'webhook_logs',
                    $log_data,
                    ['%s','%s','%s','%s','%s','%s','%d','%s']
                );
                
                if ($log_result === false) {
                    error_log('Failed to log webhook error: ' . $wpdb->last_error);
                }
            } catch (Exception $e) {
                error_log('Exception while logging webhook error: ' . $e->getMessage());
            }
            if (false === $log_result) {
                error_log('Webhook Logging Failed for invalid JSON: ' . $wpdb->last_error);
            }
        }
        return $result;
    }

    public function activate() {
        $this->create_log_table();
        
        // Get plugin data to store version
        if (!function_exists('get_plugin_data')) {
            require_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }
        $plugin_data = get_plugin_data(__FILE__);
        update_option('webhook_plugin_version', $plugin_data['Version']);
        
        flush_rewrite_rules();
    }

    public function deactivate() {
        flush_rewrite_rules();
    }

    private function send_webhook($url, $data, $headers = '{}') {
        if (empty($url)) return false;

        $headers_array = json_decode($headers, true);
        if (!is_array($headers_array)) $headers_array = array();

        // Add default headers
        $headers_array['Content-Type'] = 'application/json';
        $headers_array['User-Agent'] = 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url');

        $body = json_encode($data);
        $response = wp_remote_post($url, array(
            'headers' => $headers_array,
            'body' => $body,
            'timeout' => 15
        ));

        // Log the outgoing request
        global $wpdb;
        $status_code = is_wp_error($response) ? 500 : wp_remote_retrieve_response_code($response);
        $response_body = is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_body($response);

        $wpdb->insert(
            $wpdb->prefix . 'webhook_logs',
            array(
                'time' => current_time('mysql'),
                'endpoint' => $url,
                'method' => 'POST',
                'headers' => json_encode($headers_array),
                'params' => $body,
                'files' => '',
                'response' => $response_body,
                'status_code' => $status_code,
                'ip' => '',
                'direction' => 'outgoing'
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
        );

        if (is_wp_error($response)) {
            error_log('Webhook error: ' . $response->get_error_message());
            return false;
        }

        return true;
    }

    public function handle_post_created($post_id, $post, $update) {
        if (!get_option('webhook_trigger_post_created') || $update) return;
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;

        $data = array(
            'event' => 'post_created',
            'post_id' => $post_id,
            'post_title' => $post->post_title,
            'post_type' => $post->post_type,
            'post_status' => $post->post_status,
            'post_date' => $post->post_date,
            'post_author' => $post->post_author
        );

        $this->send_webhook(
            get_option('webhook_trigger_post_created_url'),
            $data,
            get_option('webhook_trigger_post_created_headers')
        );
    }

    public function handle_post_published($new_status, $old_status, $post) {
        if (!get_option('webhook_trigger_post_published')) return;
        if ($new_status !== 'publish' || $old_status === 'publish') return;
        if (wp_is_post_revision($post->ID) || wp_is_post_autosave($post->ID)) return;

        $data = array(
            'event' => 'post_published',
            'post_id' => $post->ID,
            'post_title' => $post->post_title,
            'post_type' => $post->post_type,
            'post_date' => $post->post_date,
            'post_author' => $post->post_author,
            'post_url' => get_permalink($post->ID)
        );

        $this->send_webhook(
            get_option('webhook_trigger_post_published_url'),
            $data,
            get_option('webhook_trigger_post_published_headers')
        );
    }

    public function handle_new_comment($comment_id, $comment) {
        if (!get_option('webhook_trigger_new_comment')) return;

        $data = array(
            'event' => 'new_comment',
            'comment_id' => $comment_id,
            'comment_post_id' => $comment->comment_post_ID,
            'comment_author' => $comment->comment_author,
            'comment_author_email' => $comment->comment_author_email,
            'comment_content' => $comment->comment_content,
            'comment_date' => $comment->comment_date,
            'comment_status' => $comment->comment_approved
        );

        $this->send_webhook(
            get_option('webhook_trigger_new_comment_url'),
            $data,
            get_option('webhook_trigger_new_comment_headers')
        );
    }

    public function ajax_toggle_trigger() {
        check_ajax_referer('webhook_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $trigger = sanitize_text_field($_POST['trigger']);
        $enabled = sanitize_text_field($_POST['enabled']);

        update_option($trigger, $enabled);
        wp_send_json_success();
    }

    public function ajax_save_trigger_url() {
        check_ajax_referer('webhook_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $trigger = sanitize_text_field($_POST['trigger']);
        $url = esc_url_raw($_POST['url']);

        update_option($trigger . '_url', $url);
        wp_send_json_success();
    }

    public function ajax_save_trigger_headers() {
        check_ajax_referer('webhook_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $trigger = sanitize_text_field($_POST['trigger']);
        $headers = sanitize_textarea_field($_POST['headers']);

        // Validate JSON
        json_decode($headers);
        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error('Invalid JSON format');
        }

        update_option($trigger . '_headers', $headers);
        wp_send_json_success();
    }
}

new Webhook_Handler();