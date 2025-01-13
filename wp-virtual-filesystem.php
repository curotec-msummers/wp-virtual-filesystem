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
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: wp-virtual-filesystem
 */

namespace WPVirtualFilesystem;

if (!defined('ABSPATH')) {
    exit;
}

// Plugin version
define('WPVFS_VERSION', '1.0.0');
// Plugin root path
define('WPVFS_PLUGIN_PATH', plugin_dir_path(__FILE__));
// Plugin root URL
define('WPVFS_PLUGIN_URL', plugin_dir_url(__FILE__));

class WP_Virtual_Filesystem {
    private static $instance = null;
    private $db;
    private $admin;

    public static function getInstance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Register activation/deactivation hooks before anything else
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        
        // Initialize plugin after all plugins are loaded
        add_action('plugins_loaded', [$this, 'init']);
    }

    public function init() {
        // Load required files
        require_once WPVFS_PLUGIN_PATH . 'includes/class-database.php';
        require_once WPVFS_PLUGIN_PATH . 'includes/class-admin.php';

        // Initialize components
        $this->db = new Database();
        $this->admin = new Admin();

        // Add AJAX handlers
        add_action('wp_ajax_wpvfs_save_settings', [$this, 'handle_save_settings']);
        add_action('wp_ajax_wpvfs_test_path', [$this, 'handle_test_path']);

        // Add menu
        add_action('admin_menu', [$this, 'add_admin_menu']);

        // Add settings link
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_settings_link']);

        // Register plugin settings
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function activate() {
        // Check WordPress version
        if (version_compare(get_bloginfo('version'), '5.0', '<')) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die('This plugin requires WordPress version 5.0 or higher.');
        }

        // Initialize database component
        require_once WPVFS_PLUGIN_PATH . 'includes/class-database.php';
        $this->db = new Database();
        
        // Create database tables
        $this->db->create_tables();
        
        // Set default options if they don't exist
        if (!get_option('wpvfs_options')) {
            $default_options = [
                'enabled_paths' => [],
                'cache_enabled' => true,
                'cache_ttl' => 3600
            ];
            add_option('wpvfs_options', $default_options);
        }
        
        // Reset rewrite rules flag
        delete_option('wpvfs_rewrite_rules_flushed');
        
        // Flush rewrite rules
        flush_rewrite_rules();

        // Install MU plugin
        $this->install_mu_plugin();
    }

    public function deactivate() {
        // Remove rewrite rules flag
        delete_option('wpvfs_rewrite_rules_flushed');
        
        // Clean up rewrite rules
        flush_rewrite_rules();

        // Optionally remove MU plugin
        $this->remove_mu_plugin();
    }

    private function install_mu_plugin() {
        if (!defined('WPMU_PLUGIN_DIR')) {
            return;
        }

        $mu_plugins_dir = WPMU_PLUGIN_DIR;
        if (!file_exists($mu_plugins_dir)) {
            if (!wp_mkdir_p($mu_plugins_dir)) {
                return;
            }
        }

        $source = WPVFS_PLUGIN_PATH . 'wp-vfs-mu.php';
        $target = $mu_plugins_dir . '/wp-vfs-mu.php';

        if (file_exists($source)) {
            @copy($source, $target);
        }
    }

    private function remove_mu_plugin() {
        if (!defined('WPMU_PLUGIN_DIR')) {
            return;
        }

        $mu_plugin_file = WPMU_PLUGIN_DIR . '/wp-vfs-mu.php';
        if (file_exists($mu_plugin_file)) {
            @unlink($mu_plugin_file);
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

    public function add_admin_menu() {
        add_options_page(
            __('Virtual Filesystem Settings', 'wp-virtual-filesystem'),
            __('Virtual Filesystem', 'wp-virtual-filesystem'),
            'manage_options',
            'wp-virtual-filesystem',
            [$this->admin, 'render_settings_page']
        );
    }

    public function add_settings_link($links) {
        $settings_link = '<a href="' . admin_url('options-general.php?page=wp-virtual-filesystem') . '">' . __('Settings', 'wp-virtual-filesystem') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
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
        update_option('wpvfs_enabled_paths', $settings['enabled_paths']); // For MU plugin

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

    public function get_db() {
        return $this->db;
    }
}

// Initialize the plugin
function wpvfs() {
    return WP_Virtual_Filesystem::getInstance();
}

// Start the plugin
wpvfs();
