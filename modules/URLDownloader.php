<?php
/**
 * URL Downloader Module
 * Handles downloading files from URLs with progress tracking
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/FileManager.php';

class URLDownloader {
    
    private $target_dir;
    private $logger;
    private $progress_callback;
    
    public function __construct($target_dir = './', $progress_callback = null) {
        $this->target_dir = rtrim($target_dir, '/') . '/';
        $this->logger = Logger::getInstance();
        $this->progress_callback = $progress_callback;
    }
    
    /**
     * Download file from URL
     * @param string $url URL to download from
     * @param string $custom_filename Optional custom filename
     * @return array Download result
     */
    public function downloadFromURL($url, $custom_filename = '') {
        $result = [
            'success' => false,
            'filename' => '',
            'file_path' => '',
            'file_size' => 0,
            'content_type' => '',
            'download_time' => 0,
            'error' => ''
        ];
        
        $start_time = microtime(true);
        
        $this->logger->info('URL_DOWNLOAD_START', [
            'Download_URL' => $url,
            'Custom_Filename' => $custom_filename,
            'Target_Dir' => $this->target_dir
        ]);
        
        try {
            // Validate URL
            $validation = $this->validateURL($url);
            if (!$validation['valid']) {
                $result['error'] = $validation['error'];
                $this->logger->error('URL_DOWNLOAD_VALIDATION_FAILED', [
                    'URL' => $url,
                    'Error' => $validation['error']
                ]);
                return $result;
            }
            
            // Determine filename
            $filename = $this->determineFilename($url, $custom_filename);
            $target_path = $this->target_dir . $filename;
            
            // Check for existing file and generate unique name if needed
            $target_path = FileManager::getUniqueFilename($target_path);
            $filename = basename($target_path);
            
            $this->logProgress("📁 Target filename: $filename");
            $this->logProgress("💾 Saving to: $target_path");
            
            // Check cURL availability
            if (!function_exists('curl_init')) {
                $result['error'] = "cURL extension is required for URL downloads";
                $this->logger->error('URL_DOWNLOAD_CURL_UNAVAILABLE', [
                    'URL' => $url,
                    'Error' => 'cURL extension not available'
                ]);
                return $result;
            }
            
            // Perform download
            $download_result = $this->performDownload($url, $target_path);
            
            if ($download_result['success']) {
                $end_time = microtime(true);
                $download_time = $end_time - $start_time;
                
                $result['success'] = true;
                $result['filename'] = $filename;
                $result['file_path'] = $target_path;
                $result['file_size'] = $download_result['file_size'];
                $result['content_type'] = $download_result['content_type'];
                $result['download_time'] = $download_time;
                
                $this->logger->success('URL_DOWNLOAD_SUCCESS', [
                    'Download_URL' => $url,
                    'Filename' => $filename,
                    'File_Path' => $target_path,
                    'File_Size' => $download_result['file_size'],
                    'File_Size_Formatted' => FileManager::formatFileSize($download_result['file_size']),
                    'Content_Type' => $download_result['content_type'],
                    'Download_Time' => round($download_time, 2) . ' seconds',
                    'HTTP_Code' => $download_result['http_code']
                ]);
                
            } else {
                $result['error'] = $download_result['error'];
                
                $this->logger->error('URL_DOWNLOAD_FAILED', [
                    'Download_URL' => $url,
                    'Target_Path' => $target_path,
                    'Error' => $download_result['error'],
                    'HTTP_Code' => $download_result['http_code'] ?? 'unknown'
                ]);
            }
            
        } catch (Exception $e) {
            $result['error'] = "Download exception: " . $e->getMessage();
            
            $this->logger->error('URL_DOWNLOAD_EXCEPTION', [
                'Download_URL' => $url,
                'Error' => $e->getMessage(),
                'File' => $e->getFile(),
                'Line' => $e->getLine()
            ]);
        }
        
        return $result;
    }
    
    /**
     * Validate URL
     * @param string $url
     * @return array
     */
    private function validateURL($url) {
        $result = ['valid' => false, 'error' => ''];
        
        if (empty($url)) {
            $result['error'] = "No URL provided";
            return $result;
        }
        
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $result['error'] = "Invalid URL format";
            return $result;
        }
        
        // Check if URL scheme is supported
        $parsed_url = parse_url($url);
        if (!in_array($parsed_url['scheme'], ['http', 'https', 'ftp'])) {
            $result['error'] = "Unsupported URL scheme: " . $parsed_url['scheme'];
            return $result;
        }
        
        $result['valid'] = true;
        return $result;
    }
    
    /**
     * Determine filename from URL or custom input
     * @param string $url
     * @param string $custom_filename
     * @return string
     */
    private function determineFilename($url, $custom_filename) {
        if (!empty($custom_filename)) {
            $filename = $custom_filename;
            // Add .zip extension if no extension provided
            if (!pathinfo($filename, PATHINFO_EXTENSION)) {
                $filename .= '.zip';
            }
        } else {
            $url_info = parse_url($url);
            $path_info = pathinfo($url_info['path']);
            
            if (!empty($path_info['basename'])) {
                $filename = $path_info['basename'];
            } else {
                $filename = 'downloaded_file_' . time() . '.zip';
            }
        }
        
        // Sanitize filename
        return FileManager::sanitizeFilename($filename);
    }
    
    /**
     * Perform the actual download
     * @param string $url
     * @param string $target_path
     * @return array
     */
    private function performDownload($url, $target_path) {
        $result = [
            'success' => false,
            'file_size' => 0,
            'content_type' => '',
            'http_code' => 0,
            'error' => ''
        ];
        
        // Open target file for writing
        $fp = fopen($target_path, 'wb');
        if (!$fp) {
            $result['error'] = "Failed to create target file: $target_path";
            return $result;
        }
        
        // Initialize cURL
        $ch = curl_init();
        
        // Progress tracking variables
        $last_progress_time = time();
        
        // Progress callback function
        $progress_callback = function($resource, $download_size, $downloaded, $upload_size, $uploaded) use (&$last_progress_time) {
            $current_time = time();
            
            // Update progress every 2 seconds or when download completes
            if ($current_time - $last_progress_time >= 2 || ($download_size > 0 && $downloaded >= $download_size)) {
                $last_progress_time = $current_time;
                
                if ($download_size > 0) {
                    $percent = round(($downloaded / $download_size) * 100, 1);
                    $size_formatted = FileManager::formatFileSize($download_size);
                    $downloaded_formatted = FileManager::formatFileSize($downloaded);
                    
                    $this->logProgress("⬇️ Progress: {$percent}% ({$downloaded_formatted} / {$size_formatted})");
                } else {
                    $downloaded_formatted = FileManager::formatFileSize($downloaded);
                    $this->logProgress("⬇️ Downloaded: {$downloaded_formatted} (size unknown)");
                }
            }
        };
        
        // Configure cURL options
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 3600, // 1 hour timeout
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_BUFFERSIZE => CHUNK_SIZE,
            CURLOPT_PROGRESSFUNCTION => $progress_callback,
            CURLOPT_NOPROGRESS => false,
        ]);
        
        $this->logProgress("🚀 Starting download...");
        
        // Execute download
        $curl_result = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $download_size = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
        $curl_error = curl_error($ch);
        
        curl_close($ch);
        fclose($fp);
        
        $result['http_code'] = $http_code;
        $result['content_type'] = $content_type;
        
        // Check for cURL errors
        if ($curl_result === false || !empty($curl_error)) {
            $result['error'] = "cURL error: $curl_error";
            FileManager::deleteFile($target_path);
            return $result;
        }
        
        // Check HTTP status code
        if ($http_code >= 400) {
            $result['error'] = "HTTP error: $http_code";
            FileManager::deleteFile($target_path);
            return $result;
        }
        
        // Verify downloaded file
        $file_size = file_exists($target_path) ? filesize($target_path) : 0;
        
        if ($file_size > 0) {
            $result['success'] = true;
            $result['file_size'] = $file_size;
            
            $this->logProgress("✅ Download completed successfully!");
            $this->logProgress("📊 File size: " . FileManager::formatFileSize($file_size));
            $this->logProgress("📄 Content type: " . ($content_type ?: 'unknown'));
            
            // Verify file extension
            $file_ext = FileManager::getFileExtension(basename($target_path));
            $allowed_extensions = ArchiveConfig::getAllowedExtensions();
            
            if (in_array($file_ext, $allowed_extensions)) {
                $this->logProgress("✅ File type verified: .$file_ext");
            } else {
                $this->logProgress("⚠️ Warning: File extension .$file_ext may not be supported");
            }
            
        } else {
            $result['error'] = "Downloaded file is empty or corrupted";
            FileManager::deleteFile($target_path);
        }
        
        return $result;
    }
    
    /**
     * Log progress message
     * @param string $message
     */
    private function logProgress($message) {
        if ($this->progress_callback && is_callable($this->progress_callback)) {
            call_user_func($this->progress_callback, $message);
        }
        
        // Also log to error log for debugging
        error_log("URLDownloader: $message");
    }
    
    /**
     * Get download statistics
     * @return array
     */
    public function getDownloadStats() {
        $files = FileManager::listFiles($this->target_dir);
        $archive_files = FileManager::filterArchiveFiles($files);
        
        $total_size = 0;
        foreach ($files as $file) {
            $file_path = $this->target_dir . $file;
            if (file_exists($file_path)) {
                $total_size += filesize($file_path);
            }
        }
        
        return [
            'target_directory' => $this->target_dir,
            'total_files' => count($files),
            'archive_files' => count($archive_files),
            'total_size' => $total_size,
            'total_size_formatted' => FileManager::formatFileSize($total_size),
            'supported_schemes' => ['http', 'https', 'ftp'],
            'curl_available' => function_exists('curl_init')
        ];
    }
    
    /**
     * Test URL accessibility
     * @param string $url
     * @return array
     */
    public function testURL($url) {
        $result = [
            'accessible' => false,
            'http_code' => 0,
            'content_type' => '',
            'content_length' => 0,
            'response_time' => 0,
            'error' => ''
        ];
        
        if (!function_exists('curl_init')) {
            $result['error'] = "cURL extension not available";
            return $result;
        }
        
        $start_time = microtime(true);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_NOBODY => true, // HEAD request only
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        
        $curl_result = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $content_length = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        $curl_error = curl_error($ch);
        
        curl_close($ch);
        
        $end_time = microtime(true);
        $response_time = $end_time - $start_time;
        
        $result['http_code'] = $http_code;
        $result['content_type'] = $content_type;
        $result['content_length'] = $content_length;
        $result['response_time'] = round($response_time, 3);
        
        if ($curl_result === false || !empty($curl_error)) {
            $result['error'] = "cURL error: $curl_error";
        } elseif ($http_code >= 400) {
            $result['error'] = "HTTP error: $http_code";
        } else {
            $result['accessible'] = true;
        }
        
        return $result;
    }
}
?> 