<?php
/**
 * File Manager Module
 * Handles file operations, grouping, and validation
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/Logger.php';

class FileManager {
    
    /**
     * Get file extension
     * @param string $filename
     * @return string
     */
    public static function getFileExtension($filename) {
        return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    }
    
    /**
     * Get base filename without extension
     * @param string $filename
     * @return string
     */
    public static function getBaseName($filename) {
        $pathinfo = pathinfo($filename);
        return $pathinfo['filename'];
    }
    
    /**
     * List files in a directory
     * @param string $directory
     * @return array
     */
    public static function listFiles($directory) {
        $files = [];
        
        if (is_dir($directory)) {
            $scan = scandir($directory);
            if ($scan) {
                foreach ($scan as $file) {
                    if ($file != '.' && $file != '..' && is_file($directory . $file)) {
                        $files[] = $file;
                    }
                }
            }
        }
        
        return $files;
    }
    
    /**
     * Filter files by archive extensions
     * @param array $files
     * @return array
     */
    public static function filterArchiveFiles($files) {
        $allowed_extensions = ArchiveConfig::getAllowedExtensions();
        
        return array_filter($files, function($file) use ($allowed_extensions) {
            $ext = self::getFileExtension($file);
            return in_array($ext, $allowed_extensions);
        });
    }
    
    /**
     * Group multipart files by base name
     * @param array $files
     * @return array
     */
    public static function groupMultipartFiles($files) {
        $groups = [];
        
        foreach ($files as $file) {
            $ext = self::getFileExtension($file);
            $base = self::getBaseName($file);
            
            if (ArchiveConfig::isMainArchiveFormat($ext)) {
                $groups[$base]['main'] = $file;
            } elseif (ArchiveConfig::isMultipartExtension($ext)) {
                $groups[$base]['parts'][] = $file;
            }
        }
        
        // Sort parts numerically for each group
        foreach ($groups as $base_name => &$group) {
            if (isset($group['parts'])) {
                usort($group['parts'], function($a, $b) {
                    $ext_a = self::getFileExtension($a);
                    $ext_b = self::getFileExtension($b);
                    
                    $num_a = (int)substr($ext_a, 1);
                    $num_b = (int)substr($ext_b, 1);
                    
                    return $num_a - $num_b;
                });
            }
        }
        
        return $groups;
    }
    
    /**
     * Validate uploaded file
     * @param array $file_info $_FILES array element
     * @return array Validation result
     */
    public static function validateUploadedFile($file_info) {
        $result = [
            'valid' => false,
            'error' => '',
            'filename' => '',
            'size' => 0,
            'extension' => ''
        ];
        
        if ($file_info['error'] !== UPLOAD_ERR_OK) {
            $result['error'] = self::getUploadErrorMessage($file_info['error']);
            return $result;
        }
        
        $filename = basename($file_info['name']);
        $extension = self::getFileExtension($filename);
        $size = $file_info['size'];
        
        // Check if extension is allowed
        $allowed_extensions = ArchiveConfig::getAllowedExtensions();
        if (!in_array($extension, $allowed_extensions)) {
            $result['error'] = "Invalid file type: .$extension";
            return $result;
        }
        
        $result['valid'] = true;
        $result['filename'] = $filename;
        $result['size'] = $size;
        $result['extension'] = $extension;
        
        return $result;
    }
    
    /**
     * Get upload error message
     * @param int $error_code
     * @return string
     */
    private static function getUploadErrorMessage($error_code) {
        switch ($error_code) {
            case UPLOAD_ERR_INI_SIZE:
                return 'File exceeds upload_max_filesize directive';
            case UPLOAD_ERR_FORM_SIZE:
                return 'File exceeds MAX_FILE_SIZE directive';
            case UPLOAD_ERR_PARTIAL:
                return 'File was only partially uploaded';
            case UPLOAD_ERR_NO_FILE:
                return 'No file was uploaded';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Missing temporary folder';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Failed to write file to disk';
            case UPLOAD_ERR_EXTENSION:
                return 'File upload stopped by extension';
            default:
                return 'Unknown upload error';
        }
    }
    
    /**
     * Get memory usage information
     * @return array
     */
    public static function getMemoryUsage() {
        $memory_used = memory_get_usage(true);
        $memory_peak = memory_get_peak_usage(true);
        
        return [
            'current' => round($memory_used / 1024 / 1024, 2) . ' MB',
            'peak' => round($memory_peak / 1024 / 1024, 2) . ' MB',
            'current_bytes' => $memory_used,
            'peak_bytes' => $memory_peak
        ];
    }
    
    /**
     * Format file size
     * @param int $bytes
     * @return string
     */
    public static function formatFileSize($bytes) {
        if ($bytes >= 1024 * 1024 * 1024 * 1024) {
            return round($bytes / (1024 * 1024 * 1024 * 1024), 2) . ' TB';
        } elseif ($bytes >= 1024 * 1024 * 1024) {
            return round($bytes / (1024 * 1024 * 1024), 2) . ' GB';
        } elseif ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' bytes';
        }
    }
    
    /**
     * Sanitize filename for safe storage
     * @param string $filename
     * @return string
     */
    public static function sanitizeFilename($filename) {
        // Remove any path components
        $filename = basename($filename);
        
        // Replace unsafe characters
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
        
        // Ensure it's not empty
        if (empty($filename)) {
            $filename = 'file_' . time();
        }
        
        return $filename;
    }
    
    /**
     * Check if file exists and generate unique name if needed
     * @param string $filepath
     * @return string
     */
    public static function getUniqueFilename($filepath) {
        if (!file_exists($filepath)) {
            return $filepath;
        }
        
        $pathinfo = pathinfo($filepath);
        $directory = $pathinfo['dirname'] . '/';
        $filename = $pathinfo['filename'];
        $extension = isset($pathinfo['extension']) ? '.' . $pathinfo['extension'] : '';
        
        $counter = 1;
        do {
            $new_filepath = $directory . $filename . '_' . $counter . $extension;
            $counter++;
        } while (file_exists($new_filepath));
        
        return $new_filepath;
    }
    
    /**
     * Delete file safely with logging
     * @param string $filepath
     * @return bool
     */
    public static function deleteFile($filepath) {
        if (!file_exists($filepath)) {
            return true;
        }
        
        $success = unlink($filepath);
        
        if ($success) {
            logInfo('FILE_DELETED', [
                'File' => $filepath,
                'Size' => 'unknown (file deleted)'
            ]);
        } else {
            logError('FILE_DELETE_FAILED', [
                'File' => $filepath,
                'Error' => 'unlink() failed'
            ]);
        }
        
        return $success;
    }
}
?> 