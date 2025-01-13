<?php
/**
 * Plugin Name: WordPress Virtual Filesystem MU Plugin
 * Plugin URI: https://curotec.com
 * Description: Must-Use plugin component for WordPress Virtual Filesystem
 * Version: 1.0.0
 * Author: Matthew Summers
 * Author URI: https://curotec.com
 * Author Email: matt.summers@curotec.com
 * Company: Curotec
 * License: GPL v2 or later
 */

namespace WPVirtualFilesystem\MU;

if (!defined('ABSPATH')) {
    exit;
}

class FileOperationInterceptor {
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
        add_action('muplugins_loaded', [$this, 'init'], 0);
    }

    public function init() {
        // Only proceed if main plugin is active
        if (!class_exists('\\WPVirtualFilesystem\\Database')) {
            return;
        }

        // Load database handler
        require_once WP_CONTENT_DIR . '/plugins/wp-virtual-filesystem/includes/class-database.php';
        $this->db = new \WPVirtualFilesystem\Database();
        
        // Get enabled paths from options
        $this->enabled_paths = get_option('wpvfs_enabled_paths', []);
        if (empty($this->enabled_paths)) {
            $options = get_option('wpvfs_options', []);
            $this->enabled_paths = $options['enabled_paths'] ?? [];
        }

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

// Initialize immediately
FileOperationInterceptor::getInstance();
