<?php
/**
 * Configuration Module
 * Centralized configuration for the Archive Uploader & Extractor system
 */

// System Configuration
define('SYSTEM_NAME', 'Archive Uploader & Extractor');
define('SYSTEM_VERSION', '2.0.0');
define('MAX_LOG_LINES', 50);

// Directory Configuration
define('UPLOAD_DIR', 'uploads/');
define('EXTRACT_DIR', 'extracted/');
define('LOG_FILE', 'uploader_operations.log');

// File Size Configuration
define('MAX_FILE_SIZE', PHP_INT_MAX); // Unlimited
define('CHUNK_SIZE', 65536); // 64KB chunks for streaming

// Archive Extensions Configuration
class ArchiveConfig {
    
    /**
     * Generate multipart ZIP extensions dynamically
     * @param int $max_parts Maximum number of parts to support
     * @return array Array of extensions
     */
    public static function generateMultipartExtensions($max_parts = 999) {
        $extensions = [];
        
        // Add main archive formats
        $main_formats = ['zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz', 'lzma'];
        $extensions = array_merge($extensions, $main_formats);
        
        // Generate z01-z999 extensions dynamically
        for ($i = 1; $i <= $max_parts; $i++) {
            $extensions[] = 'z' . sprintf('%02d', $i);
        }
        
        return $extensions;
    }
    
    /**
     * Get all supported archive extensions
     * @return array
     */
    public static function getAllowedExtensions() {
        return self::generateMultipartExtensions();
    }
    
    /**
     * Check if extension is a main archive format
     * @param string $extension
     * @return bool
     */
    public static function isMainArchiveFormat($extension) {
        $main_formats = ['zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz', 'lzma'];
        return in_array(strtolower($extension), $main_formats);
    }
    
    /**
     * Check if extension is a multipart extension
     * @param string $extension
     * @return bool
     */
    public static function isMultipartExtension($extension) {
        return preg_match('/^z\d+$/', strtolower($extension));
    }
}

// PHP Configuration
class PHPConfig {
    
    /**
     * Set optimized PHP limits for large file handling
     */
    public static function setOptimizedLimits() {
        @ini_set('max_execution_time', 0);
        @ini_set('max_input_time', -1);
        @ini_set('memory_limit', '2G');
        @ini_set('post_max_size', 0);
        @ini_set('upload_max_filesize', 0);
        @ini_set('max_file_uploads', 1000);
        @ini_set('max_input_vars', 10000);
    }
    
    /**
     * Get current PHP configuration
     * @return array
     */
    public static function getCurrentConfig() {
        return [
            'php_version' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'post_max_size' => ini_get('post_max_size'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'max_file_uploads' => ini_get('max_file_uploads'),
            'ziparchive_available' => class_exists('ZipArchive'),
            'curl_available' => function_exists('curl_init')
        ];
    }
}

// Directory Management
class DirectoryManager {
    
    /**
     * Create required directories if they don't exist
     */
    public static function createDirectories() {
        $directories = [UPLOAD_DIR, EXTRACT_DIR];
        
        foreach ($directories as $dir) {
            if (!is_dir($dir)) {
                if (!mkdir($dir, 0755, true)) {
                    throw new Exception("Failed to create directory: $dir");
                }
            }
        }
    }
    
    /**
     * Check if all required directories exist and are writable
     * @return array Status of each directory
     */
    public static function checkDirectories() {
        $directories = [UPLOAD_DIR, EXTRACT_DIR];
        $status = [];
        
        foreach ($directories as $dir) {
            $status[$dir] = [
                'exists' => is_dir($dir),
                'writable' => is_writable($dir),
                'readable' => is_readable($dir)
            ];
        }
        
        return $status;
    }
}

// Initialize system
PHPConfig::setOptimizedLimits();
DirectoryManager::createDirectories();
?> 