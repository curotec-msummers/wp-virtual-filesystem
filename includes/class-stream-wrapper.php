/**
 * Stream Wrapper implementation for WordPress Virtual Filesystem
 *
 * Implements PHP stream wrapper interface to handle file operations
 *
 * @package WPVirtualFilesystem
 * @author Matthew Summers <matt.summers@curotec.com>
 * @copyright 2025 Curotec
 * @license GPL v2 or later
 * @link https://curotec.com
 */

namespace WPVirtualFilesystem;

class Stream_Wrapper {
    private $position;
    private $data;
    private $path;
    private $mode;
    private $plugin_slug;
    private static $db;
    private $dir_handle;

    public function stream_open($path, $mode, $options, &$opened_path) {
        $this->position = 0;
        $this->path = $this->normalize_path($path);
        $this->mode = $mode;
        $this->plugin_slug = $this->extract_plugin_slug($path);

        if (!self::$db) {
            self::$db = new Database();
        }

        // Handle different modes (r, w, a, etc.)
        switch (true) {
            case strpos($mode, 'r') !== false:
                return $this->handle_read();
            case strpos($mode, 'w') !== false:
                return $this->handle_write();
            case strpos($mode, 'a') !== false:
                return $this->handle_append();
            default:
                return false;
        }
    }

    private function handle_read() {
        $file = self::$db->get_file($this->plugin_slug, $this->path);
        if (!$file) {
            return false;
        }
        $this->data = $file->file_data;
        return true;
    }

    private function handle_write() {
        $this->data = '';
        return true;
    }

    private function handle_append() {
        $file = self::$db->get_file($this->plugin_slug, $this->path);
        $this->data = $file ? $file->file_data : '';
        $this->position = strlen($this->data);
        return true;
    }

    public function stream_read($count) {
        $ret = substr($this->data, $this->position, $count);
        $this->position += strlen($ret);
        return $ret;
    }

    public function stream_write($data) {
        $left = substr($this->data, 0, $this->position);
        $right = substr($this->data, $this->position + strlen($data));
        $this->data = $left . $data . $right;
        $this->position += strlen($data);
        return strlen($data);
    }

    public function stream_close() {
        if (strpos($this->mode, 'w') !== false || strpos($this->mode, 'a') !== false) {
            self::$db->store_file(
                $this->plugin_slug,
                $this->path,
                $this->data,
                $this->get_mime_type($this->path)
            );
        }
    }

    public function stream_eof() {
        return $this->position >= strlen($this->data);
    }

    public function stream_tell() {
        return $this->position;
    }

    public function stream_seek($offset, $whence) {
        switch ($whence) {
            case SEEK_SET:
                if ($offset < strlen($this->data) && $offset >= 0) {
                    $this->position = $offset;
                    return true;
                }
                return false;
            case SEEK_CUR:
                if ($offset >= 0) {
                    $this->position += $offset;
                    return true;
                }
                return false;
            case SEEK_END:
                if (strlen($this->data) + $offset >= 0) {
                    $this->position = strlen($this->data) + $offset;
                    return true;
                }
                return false;
            default:
                return false;
        }
    }

    public function stream_stat() {
        return $this->url_stat($this->path, 0);
    }

    public function url_stat($path, $flags) {
        $path = $this->normalize_path($path);
        $plugin_slug = $this->extract_plugin_slug($path);
        
        $file = self::$db->get_file($plugin_slug, $path);
        if (!$file) {
            return false;
        }

        return [
            'dev' => 0,
            'ino' => $file->id,
            'mode' => 0777,
            'nlink' => 0,
            'uid' => 0,
            'gid' => 0,
            'rdev' => 0,
            'size' => $file->file_size,
            'atime' => strtotime($file->updated_at),
            'mtime' => strtotime($file->updated_at),
            'ctime' => strtotime($file->created_at),
            'blksize' => -1,
            'blocks' => -1,
        ];
    }

    public function mkdir($path, $mode, $options) {
        // Virtual directories don't need to be created
        return true;
    }

    public function rmdir($path) {
        // Virtual directories don't need to be removed
        return true;
    }

    public function unlink($path) {
        $path = $this->normalize_path($path);
        $plugin_slug = $this->extract_plugin_slug($path);
        return self::$db->delete_file($plugin_slug, $path);
    }

    private function normalize_path($path) {
        // Remove the stream wrapper prefix
        $path = str_replace('wpvfs://', '', $path);
        // Normalize directory separators
        $path = str_replace('\\', '/', $path);
        // Remove multiple slashes
        $path = preg_replace('|/+|', '/', $path);
        // Remove leading/trailing slashes
        return trim($path, '/');
    }

    private function extract_plugin_slug($path) {
        $path = $this->normalize_path($path);
        $parts = explode('/', $path);
        return $parts[0];
    }

    private function get_mime_type($path) {
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $mime_types = wp_get_mime_types();
        return isset($mime_types[$extension]) ? $mime_types[$extension] : 'application/octet-stream';
    }

    private function is_scorm_path($path) {
        $normalized_path = $this->normalize_path($path);
        return (
            strpos($normalized_path, 'wp-content/uploads/grassblade') !== false ||
            strpos($normalized_path, 'wp-content/uploads/scorm') !== false
        );
    }

    private function get_scorm_plugin_slug($path) {
        $normalized_path = $this->normalize_path($path);
        if (strpos($normalized_path, 'wp-content/uploads/grassblade') !== false) {
            return 'grassblade';
        }
        return 'scorm';
    }

    public function dir_opendir($path, $options) {
        $this->position = 0;
        $this->path = $this->normalize_path($path);
        
        if ($this->is_scorm_path($path)) {
            $plugin_slug = $this->get_scorm_plugin_slug($path);
            $this->dir_handle = self::$db->list_files($plugin_slug, $this->path);
            return !empty($this->dir_handle);
        }
        return false;
    }

    public function dir_readdir() {
        if (isset($this->dir_handle[$this->position])) {
            $entry = $this->dir_handle[$this->position];
            $this->position++;
            return basename($entry->virtual_path);
        }
        return false;
    }

    public function dir_rewinddir() {
        $this->position = 0;
        return true;
    }

    public function dir_closedir() {
        $this->position = 0;
        $this->dir_handle = null;
        return true;
    }
}
