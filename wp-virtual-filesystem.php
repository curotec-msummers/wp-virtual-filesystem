<?php
/**
 * Plugin Name: WordPress Virtual Filesystem
 * Plugin URI: https://curotec.com
 * Description: A WordPress plugin that implements a plugin-specific virtual filesystem layer, storing files in MySQL database instead of the physical filesystem.
 * Version: 1.0.0
 * Author: Matthew Summers
 * Author URI: https://curotec.com
 * Author Email: matt.summers@curotec.com
 * Company: Curotec
 * License: GPL v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: wp-virtual-filesystem
 * Domain Path: /languages
 *
 * @package WPVirtualFilesystem
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Plugin version
define('WPVFS_VERSION', '1.0.0');
// Plugin root path
define('WPVFS_PLUGIN_PATH', plugin_dir_path(__FILE__));
// Plugin root URL
define('WPVFS_PLUGIN_URL', plugin_dir_url(__FILE__));

// Autoloader for plugin classes
spl_autoload_register(function ($class) {
    $prefix = 'WPVirtualFilesystem\\';
    $base_dir = WPVFS_PLUGIN_PATH . 'includes/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Main plugin class
class WP_Virtual_Filesystem {
    private static $instance = null;
    private $stream_wrapper;
    private $admin;
    private $db;
    private $plugin_settings;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init();
        $this->plugin_settings = get_option('wpvfs_options', [])['plugin_settings'] ?? [];
    }

    private function init() {
        // Initialize components on plugins_loaded to ensure WordPress is fully loaded
        add_action('plugins_loaded', [$this, 'load_components']);
        
        // Register activation and deactivation hooks
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
    }

    public function load_components() {
        // Load required files
        require_once WPVFS_PLUGIN_PATH . 'includes/class-database.php';
        require_once WPVFS_PLUGIN_PATH . 'includes/class-stream-wrapper.php';
        require_once WPVFS_PLUGIN_PATH . 'includes/class-admin.php';

        // Initialize components
        $this->db = new WPVirtualFilesystem\Database();
        $this->stream_wrapper = new WPVirtualFilesystem\Stream_Wrapper();
        $this->admin = new WPVirtualFilesystem\Admin();

        // Register the stream wrapper
        stream_wrapper_register('wpvfs', WPVirtualFilesystem\Stream_Wrapper::class);

        // Only add SCORM hooks if GrassBlade plugin has VFS and SCORM enabled
        if ($this->is_plugin_vfs_enabled('grassblade')) {
            // Add basic VFS hooks for the plugin
            add_filter('plugins_url', [$this, 'modify_plugin_urls'], 10, 3);
            add_filter('wp_get_attachment_url', [$this, 'modify_attachment_url'], 10, 2);

            // Add SCORM-specific hooks only if SCORM is enabled for this plugin
            if ($this->is_plugin_scorm_enabled('grassblade')) {
                add_filter('grassblade_process_upload', [$this, 'intercept_scorm_upload'], 10, 3);
                add_filter('grassblade_content_url', [$this, 'modify_scorm_content_url'], 10, 2);
                add_filter('gb_file_exists', [$this, 'check_scorm_file_exists'], 10, 1);
            }
        }
    }

    private function is_plugin_vfs_enabled($plugin_slug) {
        return !empty($this->plugin_settings[$plugin_slug]['vfs_enabled']);
    }

    private function is_plugin_scorm_enabled($plugin_slug) {
        return !empty($this->plugin_settings[$plugin_slug]['vfs_enabled']) && 
               !empty($this->plugin_settings[$plugin_slug]['scorm_enabled']);
    }

    public function modify_plugin_urls($url, $path, $plugin) {
        $plugin_slug = dirname($plugin);
        if (empty($plugin_slug)) {
            $plugin_slug = basename($plugin, '.php');
        }

        if ($this->is_plugin_vfs_enabled($plugin_slug)) {
            if (strpos($path, 'uploads/' . $plugin_slug) !== false) {
                return str_replace('wp-content/uploads/' . $plugin_slug, 'wpvfs://' . $plugin_slug, $url);
            }
        }
        return $url;
    }

    public function modify_attachment_url($url, $attachment_id) {
        foreach ($this->plugin_settings as $plugin_slug => $settings) {
            if ($this->is_plugin_vfs_enabled($plugin_slug)) {
                if (strpos($url, 'uploads/' . $plugin_slug) !== false) {
                    return str_replace('wp-content/uploads/' . $plugin_slug, 'wpvfs://' . $plugin_slug, $url);
                }
            }
        }
        return $url;
    }

    public function intercept_scorm_upload($params, $post, $upload) {
        // Only intercept if SCORM handling is enabled for this plugin
        if (!$this->is_plugin_scorm_enabled('grassblade')) {
            return $params;
        }

        if (!empty($params['process_status']) || empty($upload['content_path'])) {
            return $params;
        }

        $content_path = $upload['content_path'];
        $content_url = $upload['content_url'];

        // Check if this is a SCORM package
        if (!file_exists($content_path . DIRECTORY_SEPARATOR . 'imsmanifest.xml')) {
            return $params;
        }

        // Store all files in the virtual filesystem
        $this->store_directory_in_vfs('grassblade', $content_path, $content_url);

        // Modify the content URL to use our virtual filesystem
        $upload['content_url'] = str_replace('wp-content/uploads/', 'wpvfs://', $content_url);
        $params['content_url'] = $upload['content_url'];

        return $params;
    }

    public function modify_scorm_content_url($url, $content_id) {
        if (strpos($url, 'wp-content/uploads/grassblade/') !== false) {
            return str_replace('wp-content/uploads/grassblade/', 'wpvfs://grassblade/', $url);
        }
        return $url;
    }

    public function check_scorm_file_exists($file) {
        if (strpos($file, 'wpvfs://') === 0) {
            $path = str_replace('wpvfs://', '', $file);
            list($plugin_slug, $virtual_path) = explode('/', $path, 2);
            return $this->db->file_exists($plugin_slug, $virtual_path);
        }
        return $file;
    }

    public function activate() {
        // Create database tables
        $this->get_db()->create_tables();
        
        // Set default options
        $default_options = [
            'enabled' => true,
            'intercepted_plugins' => [],
            'cache_enabled' => true,
            'cache_ttl' => 3600,
            'plugin_settings' => [
                'grassblade' => [
                    'vfs_enabled' => true,
                    'scorm_enabled' => true,
                ],
            ],
        ];
        
        add_option('wpvfs_options', $default_options);
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }

    public function deactivate() {
        // Unregister stream wrapper
        stream_wrapper_unregister('wpvfs');
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }

    public function get_db() {
        return $this->db;
    }

    public function get_stream_wrapper() {
        return $this->stream_wrapper;
    }

    public function get_admin() {
        return $this->admin;
    }

    private function store_directory_in_vfs($plugin_slug, $dir_path, $base_url) {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir_path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($files as $file) {
            if ($file->isFile()) {
                $relative_path = str_replace($dir_path, '', $file->getPathname());
                $virtual_path = str_replace('\\', '/', $relative_path);
                $file_contents = file_get_contents($file->getPathname());
                
                $this->db->store_file(
                    $plugin_slug,
                    $virtual_path,
                    $file_contents,
                    mime_content_type($file->getPathname())
                );
            }
        }
    }
}

// Initialize the plugin
function wpvfs() {
    return WP_Virtual_Filesystem::get_instance();
}

// Start the plugin
wpvfs();
