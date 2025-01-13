/**
 * Database operations for WordPress Virtual Filesystem
 *
 * Handles all database interactions for storing and retrieving files
 *
 * @package WPVirtualFilesystem
 * @author Matthew Summers <matt.summers@curotec.com>
 * @copyright 2025 Curotec
 * @license GPL v2 or later
 * @link https://curotec.com
 */

namespace WPVirtualFilesystem;

class Database {
    private $table_name;
    private $wpdb;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_name = $wpdb->prefix . 'virtual_filesystem';
    }

    public function create_tables() {
        $charset_collate = $this->wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            plugin_slug varchar(191) NOT NULL,
            virtual_path varchar(512) NOT NULL,
            mime_type varchar(127) DEFAULT NULL,
            file_data longblob NOT NULL,
            file_size bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            metadata json DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY plugin_path (plugin_slug(191), virtual_path(333)),
            KEY plugin_slug (plugin_slug),
            KEY created_at (created_at),
            KEY updated_at (updated_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public function store_file($plugin_slug, $virtual_path, $file_data, $mime_type = null) {
        $data = [
            'plugin_slug' => $plugin_slug,
            'virtual_path' => $virtual_path,
            'file_data' => $file_data,
            'mime_type' => $mime_type,
            'file_size' => strlen($file_data),
            'metadata' => json_encode([
                'original_name' => basename($virtual_path),
                'hash' => md5($file_data)
            ])
        ];

        return $this->wpdb->replace(
            $this->table_name,
            $data,
            ['%s', '%s', '%s', '%s', '%d', '%s']
        );
    }

    public function get_file($plugin_slug, $virtual_path) {
        return $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table_name} 
                WHERE plugin_slug = %s AND virtual_path = %s",
                $plugin_slug,
                $virtual_path
            )
        );
    }

    public function delete_file($plugin_slug, $virtual_path) {
        return $this->wpdb->delete(
            $this->table_name,
            [
                'plugin_slug' => $plugin_slug,
                'virtual_path' => $virtual_path
            ],
            ['%s', '%s']
        );
    }

    public function list_files($plugin_slug, $path_prefix = '') {
        return $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table_name} 
                WHERE plugin_slug = %s AND virtual_path LIKE %s
                ORDER BY virtual_path ASC",
                $plugin_slug,
                $path_prefix . '%'
            )
        );
    }

    public function file_exists($plugin_slug, $virtual_path) {
        return (bool) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_name} 
                WHERE plugin_slug = %s AND virtual_path = %s",
                $plugin_slug,
                $virtual_path
            )
        );
    }

    public function update_file_data($plugin_slug, $virtual_path, $file_data) {
        return $this->wpdb->update(
            $this->table_name,
            [
                'file_data' => $file_data,
                'file_size' => strlen($file_data),
                'metadata' => json_encode([
                    'original_name' => basename($virtual_path),
                    'hash' => md5($file_data)
                ])
            ],
            [
                'plugin_slug' => $plugin_slug,
                'virtual_path' => $virtual_path
            ],
            ['%s', '%d', '%s'],
            ['%s', '%s']
        );
    }

    public function get_plugin_storage_usage($plugin_slug) {
        return $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT SUM(file_size) FROM {$this->table_name} 
                WHERE plugin_slug = %s",
                $plugin_slug
            )
        );
    }
}
