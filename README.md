# WordPress Virtual Filesystem Plugin

A WordPress plugin that implements a plugin-specific virtual filesystem layer, storing files in MySQL database instead of the physical filesystem. This plugin is particularly useful for environments with read-only filesystems or when you need to intercept file operations from specific plugins.

## Key Features

- **Plugin-Specific VFS**: Enable virtual filesystem for specific plugins only
- **SCORM Support**: Optional SCORM handling for supported plugins
- **Database Storage**: Store files as BLOBs in MySQL database
- **Transparent Operation**: No changes required to intercepted plugins
- **Granular Control**: Independent VFS and SCORM settings per plugin
- **Performance Optimized**: Built-in caching system for frequently accessed files

## Primary Use Case

This plugin was designed to solve the specific problem of plugins that need to write files in WordPress VIP or similar environments with read-only filesystems. It's particularly useful for:

- SCORM plugins that expect to write files locally
- Plugins that need file write access in read-only environments
- Situations where file storage needs to be database-driven

## Installation

1. Create the `mu-plugins` directory in your WordPress installation if it doesn't exist:
   ```
   wp-content/mu-plugins/
   ```

2. Copy the following files to your `mu-plugins` directory:
   - `wp-vfs-mu.php` → `wp-content/mu-plugins/wp-vfs-mu.php`
   - `includes/` directory → `wp-content/mu-plugins/includes/`
   - `assets/` directory → `wp-content/mu-plugins/assets/`

The plugin will be automatically activated as it's installed as a Must-Use plugin.

## Configuration

### Per-Plugin Settings
Each active plugin will have two configurable options:

1. **Enable VFS**
   - Enables virtual filesystem for the specific plugin
   - Files will be stored in the database instead of filesystem
   - Must be enabled before SCORM handling can be enabled

2. **Enable SCORM Handling**
   - Only available if VFS is enabled for the plugin
   - Adds SCORM-specific file handling capabilities
   - Intercepts SCORM package uploads and manages them in the VFS

### Example Configuration for GrassBlade
To use with GrassBlade xAPI Companion:
1. Go to Settings > Virtual Filesystem
2. Find "GrassBlade xAPI Companion" in the plugin list
3. Enable VFS for GrassBlade
4. Enable SCORM Handling for GrassBlade
5. Save Settings

## Technical Details

### Database Structure
The plugin creates a single table with the following structure:
- ID (auto-increment)
- Plugin identifier
- Virtual file path
- MIME type
- File data (LONGBLOB)
- File size
- Created/Updated timestamps
- Metadata (JSON)
- Unique constraint on plugin + path combination

### File Operations
When enabled for a plugin, the virtual filesystem:
1. Intercepts file operations using PHP stream wrappers
2. Stores file content in the database
3. Maintains virtual paths that match original expectations
4. Handles common operations (read, write, delete, list)

### SCORM-Specific Features
When SCORM handling is enabled:
1. Intercepts SCORM package uploads
2. Stores package contents in the database
3. Maintains package structure virtually
4. Provides transparent access to SCORM files

## Security

- All admin actions are protected by nonces
- User capabilities are checked for administrative functions
- File paths are properly escaped and validated
- Database queries are properly prepared
- Large files are handled in chunks

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- MySQL 5.6 or higher

## Support

For support, please contact:
- Email: matt.summers@curotec.com
- Website: https://curotec.com

## License

This plugin is licensed under the GPL v2 or later.

## Contributing

Contributions are welcome! Please submit pull requests to the plugin's repository.
