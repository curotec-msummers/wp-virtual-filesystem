<?php
/**
 * Admin interface for WordPress Virtual Filesystem
 *
 * @package WPVirtualFilesystem
 * @author Matthew Summers <matt.summers@curotec.com>
 * @copyright 2025 Curotec
 * @license GPL v2 or later
 * @link https://curotec.com
 */

namespace WPVirtualFilesystem;

class Admin {
    private $options;

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'init_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
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

    public function init_settings() {
        register_setting('wpvfs_options', 'wpvfs_options', [$this, 'validate_options']);

        add_settings_section(
            'wpvfs_general',
            __('General Settings', 'wp-virtual-filesystem'),
            [$this, 'render_section_info'],
            'wp-virtual-filesystem'
        );

        add_settings_field(
            'enabled',
            __('Enable Virtual Filesystem', 'wp-virtual-filesystem'),
            [$this, 'render_enabled_field'],
            'wp-virtual-filesystem',
            'wpvfs_general'
        );

        add_settings_field(
            'cache_enabled',
            __('Enable Caching', 'wp-virtual-filesystem'),
            [$this, 'render_cache_field'],
            'wp-virtual-filesystem',
            'wpvfs_general'
        );

        add_settings_section(
            'wpvfs_plugins',
            __('Plugin Settings', 'wp-virtual-filesystem'),
            [$this, 'render_plugins_section_info'],
            'wp-virtual-filesystem'
        );

        add_settings_field(
            'plugin_settings',
            __('Plugin Configuration', 'wp-virtual-filesystem'),
            [$this, 'render_plugin_settings_field'],
            'wp-virtual-filesystem',
            'wpvfs_plugins'
        );
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('wpvfs_options');
                do_settings_sections('wp-virtual-filesystem');
                submit_button();
                ?>
            </form>

            <div class="wpvfs-file-browser">
                <h2><?php _e('File Browser', 'wp-virtual-filesystem'); ?></h2>
                <div id="wpvfs-file-list">
                    <!-- File list will be populated via AJAX -->
                </div>
            </div>
        </div>
        <?php
    }

    public function render_section_info() {
        echo '<p>' . esc_html__('Configure the virtual filesystem settings. When enabled, all SCORM content will automatically be stored in the database.', 'wp-virtual-filesystem') . '</p>';
    }

    public function render_enabled_field() {
        $options = get_option('wpvfs_options');
        ?>
        <label>
            <input type="checkbox" name="wpvfs_options[enabled]" value="1" <?php checked(1, $options['enabled'] ?? false); ?>>
            <?php _e('Enable virtual filesystem (Required for SCORM content storage)', 'wp-virtual-filesystem'); ?>
        </label>
        <p class="description">
            <?php _e('When enabled, SCORM content will be automatically stored in the database instead of the filesystem.', 'wp-virtual-filesystem'); ?>
        </p>
        <?php
    }

    public function render_cache_field() {
        $options = get_option('wpvfs_options');
        ?>
        <label>
            <input type="checkbox" name="wpvfs_options[cache_enabled]" value="1" <?php checked(1, $options['cache_enabled'] ?? false); ?>>
            <?php _e('Enable content caching', 'wp-virtual-filesystem'); ?>
        </label>
        <p class="description">
            <?php _e('Cache frequently accessed files in memory for better performance.', 'wp-virtual-filesystem'); ?>
        </p>
        <?php
    }

    public function render_plugins_section_info() {
        echo '<p>' . esc_html__('Configure which plugins should use the virtual filesystem and enable SCORM handling for specific plugins.', 'wp-virtual-filesystem') . '</p>';
    }

    public function render_plugin_settings_field() {
        $options = get_option('wpvfs_options', []);
        $active_plugins = get_option('active_plugins');
        $plugin_settings = $options['plugin_settings'] ?? [];

        echo '<div class="wpvfs-plugin-list">';
        echo '<table class="widefat" style="margin-top: 10px;">
                <thead>
                    <tr>
                        <th>' . __('Plugin', 'wp-virtual-filesystem') . '</th>
                        <th>' . __('Enable VFS', 'wp-virtual-filesystem') . '</th>
                        <th>' . __('Enable SCORM Handling', 'wp-virtual-filesystem') . '</th>
                    </tr>
                </thead>
                <tbody>';

        foreach ($active_plugins as $plugin_path) {
            $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_path);
            $plugin_slug = dirname($plugin_path);
            if (empty($plugin_slug)) {
                $plugin_slug = basename($plugin_path, '.php');
            }

            $settings = $plugin_settings[$plugin_slug] ?? [
                'vfs_enabled' => false,
                'scorm_enabled' => false
            ];

            echo '<tr>
                    <td>' . esc_html($plugin_data['Name']) . '</td>
                    <td>
                        <label>
                            <input type="checkbox" 
                                   name="wpvfs_options[plugin_settings][' . esc_attr($plugin_slug) . '][vfs_enabled]" 
                                   value="1"
                                   ' . checked(1, $settings['vfs_enabled'], false) . '>
                            ' . __('Enable VFS', 'wp-virtual-filesystem') . '
                        </label>
                    </td>
                    <td>
                        <label>
                            <input type="checkbox" 
                                   name="wpvfs_options[plugin_settings][' . esc_attr($plugin_slug) . '][scorm_enabled]" 
                                   value="1"
                                   ' . checked(1, $settings['scorm_enabled'], false) . '
                                   ' . disabled(!$settings['vfs_enabled'], true) . '>
                            ' . __('Enable SCORM', 'wp-virtual-filesystem') . '
                        </label>
                    </td>
                </tr>';
        }

        echo '</tbody></table>';
        echo '</div>';

        // Add JavaScript to handle dependency
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('.wpvfs-plugin-list input[type="checkbox"]').on('change', function() {
                var row = $(this).closest('tr');
                var vfsCheckbox = row.find('input[name*="[vfs_enabled]"]');
                var scormCheckbox = row.find('input[name*="[scorm_enabled]"]');

                if ($(this).is('[name*="[vfs_enabled]"]')) {
                    if (!$(this).prop('checked')) {
                        scormCheckbox.prop('checked', false);
                    }
                    scormCheckbox.prop('disabled', !$(this).prop('checked'));
                }
            });
        });
        </script>
        <?php
    }

    public function validate_options($input) {
        $valid = [];
        
        $valid['enabled'] = !empty($input['enabled']);
        $valid['cache_enabled'] = !empty($input['cache_enabled']);

        // Validate plugin settings
        $valid['plugin_settings'] = [];
        if (!empty($input['plugin_settings']) && is_array($input['plugin_settings'])) {
            foreach ($input['plugin_settings'] as $plugin_slug => $settings) {
                $valid['plugin_settings'][sanitize_text_field($plugin_slug)] = [
                    'vfs_enabled' => !empty($settings['vfs_enabled']),
                    'scorm_enabled' => !empty($settings['vfs_enabled']) && !empty($settings['scorm_enabled'])
                ];
            }
        }

        return $valid;
    }

    public function enqueue_admin_scripts($hook) {
        if ('settings_page_wp-virtual-filesystem' !== $hook) {
            return;
        }

        wp_enqueue_style(
            'wpvfs-admin',
            WPVFS_PLUGIN_URL . 'assets/css/admin.css',
            [],
            WPVFS_VERSION
        );

        wp_enqueue_script(
            'wpvfs-admin',
            WPVFS_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            WPVFS_VERSION,
            true
        );

        wp_localize_script('wpvfs-admin', 'wpvfsAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wpvfs-admin'),
        ]);
    }
}
