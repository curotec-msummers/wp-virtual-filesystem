jQuery(document).ready(function($) {
    // Global enable/disable toggle
    $('#wpvfs-global-toggle').on('change', function() {
        var isEnabled = $(this).prop('checked');
        $('input[name="wpvfs_options[intercepted_plugins][]"]').prop('disabled', !isEnabled);
    });

    // File browser functionality
    function refreshFileList() {
        $.ajax({
            url: wpvfsAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'wpvfs_list_files',
                nonce: wpvfsAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    renderFileList(response.data);
                }
            }
        });
    }

    function renderFileList(files) {
        var $fileList = $('#wpvfs-file-list');
        $fileList.empty();

        files.forEach(function(file) {
            var $fileItem = $('<div>', {
                class: 'wpvfs-file-item',
                'data-id': file.id
            });

            $fileItem.append(
                $('<span>', {
                    class: 'wpvfs-file-name',
                    text: file.virtual_path
                })
            );

            $fileItem.append(
                $('<span>', {
                    class: 'wpvfs-file-size',
                    text: formatFileSize(file.file_size)
                })
            );

            var $actions = $('<div>', {
                class: 'wpvfs-file-actions'
            });

            $actions.append(
                $('<button>', {
                    class: 'button button-small',
                    text: 'Download',
                    click: function(e) {
                        e.preventDefault();
                        downloadFile(file.id);
                    }
                })
            );

            $actions.append(
                $('<button>', {
                    class: 'button button-small button-link-delete',
                    text: 'Delete',
                    click: function(e) {
                        e.preventDefault();
                        if (confirm('Are you sure you want to delete this file?')) {
                            deleteFile(file.id);
                        }
                    }
                })
            );

            $fileItem.append($actions);
            $fileList.append($fileItem);
        });
    }

    function formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        var k = 1024;
        var sizes = ['Bytes', 'KB', 'MB', 'GB'];
        var i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    function downloadFile(fileId) {
        window.location.href = wpvfsAdmin.ajaxUrl + '?action=wpvfs_download_file&id=' + fileId + '&nonce=' + wpvfsAdmin.nonce;
    }

    function deleteFile(fileId) {
        $.ajax({
            url: wpvfsAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'wpvfs_delete_file',
                id: fileId,
                nonce: wpvfsAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    refreshFileList();
                }
            }
        });
    }

    // Initial file list load
    refreshFileList();

    // Refresh button
    $('#wpvfs-refresh-files').on('click', function(e) {
        e.preventDefault();
        refreshFileList();
    });
});
