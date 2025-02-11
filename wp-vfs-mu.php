<?php
/**
 * Plugin Name: WordPress Virtual Filesystem
 * Plugin URI: https://curotec.com
 * Description: A WordPress plugin that implements a virtual filesystem layer, storing files in MySQL database.
 * Version: 1.0.0
 * Author: Matthew Summers
 * Author URI: https://curotec.com
 * Author Email: matt.summers@curotec.com
 * Company: Curotec
 * License: GPL v2 or later
 */

namespace WPVirtualFilesystem\Core;

if (!defined('ABSPATH')) {
    exit;
}

class VirtualFilesystemHandler {
    private static $instance = null;
    private $db;
    private $enabled_paths = [];
    private $current_upload_context = null;

    public static function getInstance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Initialize plugin after all plugins are loaded
        add_action('plugins_loaded', [$this, 'init']);
        
        // Add admin functionality
        if (is_admin()) {
            add_action('admin_menu', [$this, 'add_admin_menu']);
            add_action('admin_init', [$this, 'register_settings']);
            add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
            add_action('wp_ajax_wpvfs_save_settings', [$this, 'handle_save_settings']);
            add_action('wp_ajax_wpvfs_test_path', [$this, 'handle_test_path']);
        }
    }

    public function init() {
        // Load database handler
        require_once __DIR__ . '/includes/class-database.php';
        $this->db = new \WPVirtualFilesystem\Database();
        
        // Get enabled paths from options
        $options = get_option('wpvfs_options', []);
        $this->enabled_paths = $options['enabled_paths'] ?? [];

        // Core WordPress upload hooks
        add_filter('upload_dir', [$this, 'modify_upload_dir'], 1);
        add_filter('wp_handle_upload_prefilter', [$this, 'pre_handle_upload'], 1);
        add_filter('wp_handle_upload', [$this, 'post_handle_upload'], 1);

        // File type and mime handling
        add_filter('upload_mimes', [$this, 'modify_upload_mimes']);
        add_filter('wp_check_filetype_and_ext', [$this, 'check_filetype'], 10, 5);

        // WordPress filesystem method hooks
        add_filter('filesystem_method', [$this, 'override_filesystem_method'], 1);
        add_filter('filesystem_method_file', [$this, 'intercept_filesystem_file'], 1);

        // File existence and URL handling
        add_filter('wp_exists_file', [$this, 'check_file_exists'], 10, 2);
        add_filter('wp_get_attachment_url', [$this, 'modify_attachment_url'], 10, 2);
        
        // Handle file deletion
        add_action('delete_attachment', [$this, 'handle_file_deletion'], 10);
        add_action('wp_delete_file', [$this, 'handle_file_deletion_by_path'], 10);

        // Register URL handler
        add_action('init', [$this, 'register_url_handlers']);
        add_action('parse_request', [$this, 'handle_virtual_file_request']);

        // Create tables if needed
        $this->maybe_create_tables();
    }

    private function maybe_create_tables() {
        if (!get_option('wpvfs_db_version')) {
            $this->db->create_tables();
            update_option('wpvfs_db_version', '1.0.0');
        }
    }

    public function register_settings() {
        register_setting('wpvfs_options', 'wpvfs_options', [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_options']
        ]);
    }

    public function sanitize_options($options) {
        if (!is_array($options)) {
            return [];
        }

        return [
            'enabled_paths' => isset($options['enabled_paths']) ? array_map('sanitize_text_field', $options['enabled_paths']) : [],
            'cache_enabled' => !empty($options['cache_enabled']),
            'cache_ttl' => isset($options['cache_ttl']) ? absint($options['cache_ttl']) : 3600
        ];
    }

    public function enqueue_admin_assets($hook) {
        if ('settings_page_wp-virtual-filesystem' !== $hook) {
            return;
        }

        wp_enqueue_style('wpvfs-admin', plugins_url('assets/css/admin.css', __FILE__));
        wp_enqueue_script('wpvfs-admin', plugins_url('assets/js/admin.js', __FILE__), ['jquery'], '1.0.0', true);
        wp_localize_script('wpvfs-admin', 'wpvfs', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wpvfs_settings')
        ]);
    }

    public function add_admin_menu() {
        add_options_page(
            __('Virtual Filesystem Settings', 'wp-virtual-filesystem'),
            __('Virtual Filesystem', 'wp-virtual-filesystem'),
            'manage_options',
            'wp-virtual-filesystem',
            [$this, 'render_settings_page']
        );
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        $options = get_option('wpvfs_options', [
            'enabled_paths' => [],
            'cache_enabled' => true,
            'cache_ttl' => 3600
        ]);
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <form id="wpvfs-settings-form">
                <?php wp_nonce_field('wpvfs_settings', 'wpvfs_nonce'); ?>
                
                <h2>Virtual Filesystem Paths</h2>
                <p>Specify which upload paths should be handled by the virtual filesystem. Files uploaded to these paths will be stored in the database.</p>
                
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">Enabled Paths</th>
                            <td>
                                <div id="path-container">
                                    <?php foreach ($options['enabled_paths'] as $path): ?>
                                    <div class="path-row">
                                        <input type="text" name="paths[]" value="<?php echo esc_attr($path); ?>" class="regular-text">
                                        <button type="button" class="button remove-path">Remove</button>
                                        <button type="button" class="button test-path">Test Path</button>
                                        <span class="path-status"></span>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <button type="button" class="button" id="add-path">Add Path</button>
                                <p class="description">Enter relative paths from the uploads directory (e.g., 'grassblade' or 'scorm/packages')</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">Enable Caching</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="cache_enabled" value="1" <?php checked($options['cache_enabled']); ?>>
                                    Cache virtual files for better performance
                                </label>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">Cache TTL</th>
                            <td>
                                <input type="number" name="cache_ttl" value="<?php echo esc_attr($options['cache_ttl']); ?>" class="small-text">
                                <p class="description">Time in seconds to cache virtual files (default: 3600)</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
                
                <p class="submit">
                    <button type="submit" class="button button-primary" id="save-settings">Save Changes</button>
                </p>
            </form>
        </div>
        <?php
    }

    public function handle_save_settings() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'wp-virtual-filesystem'));
        }

        if (!check_ajax_referer('wpvfs_settings', 'nonce', false)) {
            wp_send_json_error(__('Invalid nonce', 'wp-virtual-filesystem'));
        }

        $settings = [
            'enabled_paths' => isset($_POST['paths']) ? array_map('sanitize_text_field', $_POST['paths']) : [],
            'cache_enabled' => !empty($_POST['cache_enabled']),
            'cache_ttl' => isset($_POST['cache_ttl']) ? absint($_POST['cache_ttl']) : 3600
        ];

        update_option('wpvfs_options', $settings);
        wp_send_json_success(__('Settings saved', 'wp-virtual-filesystem'));
    }

    public function handle_test_path() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'wp-virtual-filesystem'));
        }

        if (!check_ajax_referer('wpvfs_settings', 'nonce', false)) {
            wp_send_json_error(__('Invalid nonce', 'wp-virtual-filesystem'));
        }

        $path = sanitize_text_field($_POST['path'] ?? '');
        if (empty($path)) {
            wp_send_json_error(__('Invalid path', 'wp-virtual-filesystem'));
        }

        // Validate path format
        if (preg_match('/[^a-zA-Z0-9\-_\/]/', $path)) {
            wp_send_json_error(__('Path contains invalid characters', 'wp-virtual-filesystem'));
        }

        $upload_dir = wp_upload_dir();
        $full_path = path_join($upload_dir['basedir'], $path);

        // Create directory if it doesn't exist
        if (!file_exists($full_path)) {
            if (!wp_mkdir_p($full_path)) {
                wp_send_json_error(__('Could not create directory', 'wp-virtual-filesystem'));
            }
        }

        if (!is_writable($full_path)) {
            wp_send_json_error(__('Directory is not writable', 'wp-virtual-filesystem'));
        }

        wp_send_json_success(__('Path is valid and writable', 'wp-virtual-filesystem'));
    }

    public function modify_upload_dir($uploads) {
        // Reset context at the start of new upload
        if (!isset($uploads['vfs_processed'])) {
            $this->current_upload_context = null;
        }

        foreach ($this->enabled_paths as $path) {
            if ($this->should_handle_path($uploads['path'], $path)) {
                // Store original paths
                $this->current_upload_context = [
                    'original_path' => $uploads['path'],
                    'original_url' => $uploads['url'],
                    'virtual_path' => $path
                ];

                // Modify paths for virtual storage
                $uploads['path'] = 'wpvfs://' . $path;
                $uploads['url'] = site_url('wpvfs/' . $path);
                $uploads['vfs_enabled'] = true;
                $uploads['vfs_processed'] = true;
                break;
            }
        }
        return $uploads;
    }

    public function pre_handle_upload($file) {
        if (!$this->current_upload_context || empty($file['tmp_name'])) {
            return $file;
        }

        // Store original file info
        $file['vfs_data'] = [
            'tmp_name' => $file['tmp_name'],
            'name' => sanitize_file_name($file['name']),
            'type' => $file['type'],
            'context' => $this->current_upload_context
        ];

        return $file;
    }

    public function post_handle_upload($upload) {
        if (empty($upload['vfs_data']) || empty($upload['vfs_data']['tmp_name'])) {
            return $upload;
        }

        $context = $upload['vfs_data']['context'];
        $relative_path = str_replace($context['original_path'], '', $upload['file']);
        $relative_path = ltrim($relative_path, '/\\');

        // Store file in database
        if (file_exists($upload['vfs_data']['tmp_name'])) {
            $file_contents = file_get_contents($upload['vfs_data']['tmp_name']);
            if ($file_contents !== false) {
                $stored = $this->db->store_file(
                    $context['virtual_path'],
                    $relative_path,
                    $file_contents,
                    $upload['type']
                );

                if ($stored) {
                    // Update paths to virtual filesystem
                    $upload['file'] = 'wpvfs://' . $context['virtual_path'] . '/' . $relative_path;
                    $upload['url'] = site_url('wpvfs/' . $context['virtual_path'] . '/' . $relative_path);
                    
                    // Clean up temporary file
                    @unlink($upload['vfs_data']['tmp_name']);
                }
            }
        }

        return $upload;
    }

    public function modify_upload_mimes($mimes) {
        // No modification needed, just pass through
        return $mimes;
    }

    public function check_filetype($data, $file, $filename, $mimes, $real_mime = null) {
        if ($this->current_upload_context && !empty($file)) {
            // Allow file type if we're handling it
            $data['ext'] = pathinfo($filename, PATHINFO_EXTENSION);
            $data['type'] = $real_mime ?: mime_content_type($file);
        }
        return $data;
    }

    public function override_filesystem_method($method) {
        if ($this->is_virtual_path(current_filter())) {
            return 'direct';
        }
        return $method;
    }

    public function intercept_filesystem_file($file) {
        if (empty($file)) {
            return $file;
        }

        foreach ($this->enabled_paths as $path) {
            if ($this->should_handle_path($file, $path)) {
                return 'wpvfs://' . $path . '/' . basename($file);
            }
        }
        return $file;
    }

    public function check_file_exists($exists, $file) {
        if (empty($file)) {
            return $exists;
        }

        if (strpos($file, 'wpvfs://') === 0) {
            $path = str_replace('wpvfs://', '', $file);
            list($base_path, $virtual_path) = explode('/', $path, 2);
            return $this->db->file_exists($base_path, $virtual_path);
        }
        return $exists;
    }

    public function modify_attachment_url($url, $post_id) {
        if (empty($post_id)) {
            return $url;
        }

        $file = get_post_meta($post_id, '_wp_attached_file', true);
        if ($file) {
            foreach ($this->enabled_paths as $path) {
                if ($this->should_handle_path($file, $path)) {
                    return site_url('wpvfs/' . $path . '/' . basename($file));
                }
            }
        }
        return $url;
    }

    public function handle_file_deletion($post_id) {
        if (empty($post_id)) {
            return;
        }

        $file = get_post_meta($post_id, '_wp_attached_file', true);
        if ($file) {
            $this->handle_file_deletion_by_path($file);
        }
    }

    public function handle_file_deletion_by_path($file) {
        if (empty($file)) {
            return;
        }

        foreach ($this->enabled_paths as $path) {
            if ($this->should_handle_path($file, $path)) {
                $virtual_path = basename($file);
                $this->db->delete_file($path, $virtual_path);
                break;
            }
        }
    }

    public function register_url_handlers() {
        add_rewrite_tag('%wpvfs_path%', '(.+)');
        add_rewrite_rule(
            '^wpvfs/(.+)$',
            'index.php?wpvfs_path=$matches[1]',
            'top'
        );
        
        // Flush rules only if needed
        if (!get_option('wpvfs_rewrite_rules_flushed')) {
            flush_rewrite_rules();
            update_option('wpvfs_rewrite_rules_flushed', true);
        }
    }

    public function handle_virtual_file_request($wp) {
        if (!isset($wp->query_vars['wpvfs_path'])) {
            return;
        }

        $path = sanitize_text_field($wp->query_vars['wpvfs_path']);
        if (empty($path)) {
            status_header(404);
            exit;
        }

        list($base_path, $virtual_path) = array_pad(explode('/', $path, 2), 2, '');
        if (empty($virtual_path)) {
            status_header(404);
            exit;
        }

        $file = $this->db->get_file($base_path, $virtual_path);
        if ($file && !empty($file['content'])) {
            $etag = md5($file['content']);
            $last_modified = gmdate('D, d M Y H:i:s', strtotime($file['modified'])) . ' GMT';

            // Check if-none-match
            if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
                status_header(304);
                exit;
            }

            // Check if-modified-since
            if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) >= strtotime($last_modified)) {
                status_header(304);
                exit;
            }

            header('Content-Type: ' . $file['mime_type']);
            header('Content-Length: ' . strlen($file['content']));
            header('ETag: ' . $etag);
            header('Last-Modified: ' . $last_modified);
            header('Cache-Control: public, max-age=31536000');
            echo $file['content'];
            exit;
        }

        status_header(404);
        exit;
    }

    private function should_handle_path($path, $virtual_path) {
        if (empty($path) || empty($virtual_path)) {
            return false;
        }
        return strpos($path, $virtual_path) !== false || 
               strpos($path, 'wpvfs://' . $virtual_path) !== false;
    }

    private function is_virtual_path($path) {
        if (empty($path)) {
            return false;
        }
        if (strpos($path, 'wpvfs://') === 0) {
            return true;
        }
        foreach ($this->enabled_paths as $enabled_path) {
            if ($this->should_handle_path($path, $enabled_path)) {
                return true;
            }
        }
        return false;
    }
}

// Initialize the plugin
VirtualFilesystemHandler::getInstance();
