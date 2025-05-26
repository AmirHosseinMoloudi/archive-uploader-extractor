<?php
/**
 * File Uploader Module
 * Handles file upload operations with validation and logging
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/FileManager.php';

class FileUploader {
    
    private $upload_dir;
    private $logger;
    
    public function __construct($upload_dir = UPLOAD_DIR) {
        $this->upload_dir = rtrim($upload_dir, '/') . '/';
        $this->logger = Logger::getInstance();
        
        // Ensure upload directory exists
        if (!is_dir($this->upload_dir)) {
            mkdir($this->upload_dir, 0755, true);
        }
    }
    
    /**
     * Handle file upload from $_FILES
     * @param array $files $_FILES array
     * @return array Upload results
     */
    public function handleUpload($files) {
        $results = [
            'success' => [],
            'errors' => [],
            'total_uploaded' => 0,
            'total_size' => 0
        ];
        
        // Log upload attempt
        $file_count = is_array($files['name']) ? count($files['name']) : 1;
        $total_size = is_array($files['size']) ? array_sum($files['size']) : $files['size'];
        
        $this->logger->info('FILE_UPLOAD_START', [
            'Files_Count' => $file_count,
            'Total_Size' => $total_size,
            'Total_Size_Formatted' => FileManager::formatFileSize($total_size),
            'File_Names' => is_array($files['name']) ? implode(', ', $files['name']) : $files['name']
        ]);
        
        try {
            if (is_array($files['name'])) {
                // Multiple file upload
                $results = $this->handleMultipleUpload($files);
            } else {
                // Single file upload
                $results = $this->handleSingleUpload($files);
            }
            
            // Log final results
            $this->logger->info('FILE_UPLOAD_COMPLETE', [
                'Files_Uploaded' => $results['total_uploaded'],
                'Total_Size' => $results['total_size'],
                'Total_Size_Formatted' => FileManager::formatFileSize($results['total_size']),
                'Errors_Count' => count($results['errors'])
            ]);
            
        } catch (Exception $e) {
            $this->logger->error('FILE_UPLOAD_EXCEPTION', [
                'Error' => $e->getMessage(),
                'File' => $e->getFile(),
                'Line' => $e->getLine()
            ]);
            
            $results['errors'][] = "Upload exception: " . $e->getMessage();
        }
        
        return $results;
    }
    
    /**
     * Handle multiple file upload
     * @param array $files
     * @return array
     */
    private function handleMultipleUpload($files) {
        $results = [
            'success' => [],
            'errors' => [],
            'total_uploaded' => 0,
            'total_size' => 0
        ];
        
        $file_count = count($files['name']);
        
        for ($i = 0; $i < $file_count; $i++) {
            $file_info = [
                'name' => $files['name'][$i],
                'type' => $files['type'][$i],
                'tmp_name' => $files['tmp_name'][$i],
                'error' => $files['error'][$i],
                'size' => $files['size'][$i]
            ];
            
            $upload_result = $this->uploadSingleFile($file_info);
            
            if ($upload_result['success']) {
                $results['success'][] = $upload_result;
                $results['total_uploaded']++;
                $results['total_size'] += $upload_result['size'];
            } else {
                $results['errors'][] = $upload_result['error'];
            }
        }
        
        return $results;
    }
    
    /**
     * Handle single file upload
     * @param array $files
     * @return array
     */
    private function handleSingleUpload($files) {
        $results = [
            'success' => [],
            'errors' => [],
            'total_uploaded' => 0,
            'total_size' => 0
        ];
        
        $upload_result = $this->uploadSingleFile($files);
        
        if ($upload_result['success']) {
            $results['success'][] = $upload_result;
            $results['total_uploaded'] = 1;
            $results['total_size'] = $upload_result['size'];
        } else {
            $results['errors'][] = $upload_result['error'];
        }
        
        return $results;
    }
    
    /**
     * Upload a single file
     * @param array $file_info
     * @return array
     */
    private function uploadSingleFile($file_info) {
        $result = [
            'success' => false,
            'filename' => '',
            'size' => 0,
            'target_path' => '',
            'error' => ''
        ];
        
        try {
            // Validate file
            $validation = FileManager::validateUploadedFile($file_info);
            
            if (!$validation['valid']) {
                $result['error'] = $validation['error'];
                
                $this->logger->error('FILE_UPLOAD_VALIDATION_FAILED', [
                    'Filename' => $file_info['name'],
                    'Error' => $validation['error'],
                    'Upload_Error_Code' => $file_info['error']
                ]);
                
                return $result;
            }
            
            // Sanitize filename
            $filename = FileManager::sanitizeFilename($validation['filename']);
            $target_path = $this->upload_dir . $filename;
            
            // Check for existing file and generate unique name if needed
            $target_path = FileManager::getUniqueFilename($target_path);
            $filename = basename($target_path);
            
            // Move uploaded file
            if (move_uploaded_file($file_info['tmp_name'], $target_path)) {
                $result['success'] = true;
                $result['filename'] = $filename;
                $result['size'] = $validation['size'];
                $result['target_path'] = $target_path;
                
                $this->logger->success('FILE_UPLOAD_SUCCESS', [
                    'Filename' => $filename,
                    'Original_Name' => $file_info['name'],
                    'Size' => $validation['size'],
                    'Size_Formatted' => FileManager::formatFileSize($validation['size']),
                    'Target_Path' => $target_path,
                    'Extension' => $validation['extension']
                ]);
                
            } else {
                $result['error'] = "Failed to move uploaded file: $filename";
                
                $this->logger->error('FILE_UPLOAD_MOVE_FAILED', [
                    'Filename' => $filename,
                    'Original_Name' => $file_info['name'],
                    'Target_Path' => $target_path,
                    'Error' => 'move_uploaded_file failed'
                ]);
            }
            
        } catch (Exception $e) {
            $result['error'] = "Upload exception: " . $e->getMessage();
            
            $this->logger->error('FILE_UPLOAD_EXCEPTION', [
                'Filename' => $file_info['name'],
                'Error' => $e->getMessage(),
                'File' => $e->getFile(),
                'Line' => $e->getLine()
            ]);
        }
        
        return $result;
    }
    
    /**
     * Get upload directory information
     * @return array
     */
    public function getUploadInfo() {
        $files = FileManager::listFiles($this->upload_dir);
        $archive_files = FileManager::filterArchiveFiles($files);
        $groups = FileManager::groupMultipartFiles($archive_files);
        
        $total_size = 0;
        foreach ($files as $file) {
            $file_path = $this->upload_dir . $file;
            if (file_exists($file_path)) {
                $total_size += filesize($file_path);
            }
        }
        
        return [
            'directory' => $this->upload_dir,
            'total_files' => count($files),
            'archive_files' => count($archive_files),
            'file_groups' => count($groups),
            'total_size' => $total_size,
            'total_size_formatted' => FileManager::formatFileSize($total_size),
            'files' => $files,
            'archive_files' => $archive_files,
            'groups' => $groups
        ];
    }
    
    /**
     * Delete uploaded file
     * @param string $filename
     * @return bool
     */
    public function deleteFile($filename) {
        $file_path = $this->upload_dir . basename($filename);
        
        if (!file_exists($file_path)) {
            $this->logger->warning('FILE_DELETE_NOT_FOUND', [
                'Filename' => $filename,
                'File_Path' => $file_path
            ]);
            return true; // File doesn't exist, consider it deleted
        }
        
        $file_size = filesize($file_path);
        $success = FileManager::deleteFile($file_path);
        
        if ($success) {
            $this->logger->success('FILE_DELETE_SUCCESS', [
                'Filename' => $filename,
                'File_Path' => $file_path,
                'Size' => $file_size,
                'Size_Formatted' => FileManager::formatFileSize($file_size)
            ]);
        }
        
        return $success;
    }
    
    /**
     * Get upload statistics
     * @return array
     */
    public function getUploadStats() {
        $info = $this->getUploadInfo();
        
        $stats = [
            'directory_info' => $info,
            'supported_extensions' => ArchiveConfig::getAllowedExtensions(),
            'max_file_size' => MAX_FILE_SIZE,
            'chunk_size' => CHUNK_SIZE,
            'php_config' => PHPConfig::getCurrentConfig()
        ];
        
        return $stats;
    }
    
    /**
     * Clean up old uploaded files
     * @param int $max_age_hours Maximum age in hours
     * @return array Cleanup results
     */
    public function cleanupOldFiles($max_age_hours = 24) {
        $results = [
            'deleted_files' => [],
            'deleted_count' => 0,
            'freed_space' => 0,
            'errors' => []
        ];
        
        $files = FileManager::listFiles($this->upload_dir);
        $cutoff_time = time() - ($max_age_hours * 3600);
        
        foreach ($files as $file) {
            $file_path = $this->upload_dir . $file;
            
            if (file_exists($file_path) && filemtime($file_path) < $cutoff_time) {
                $file_size = filesize($file_path);
                
                if (FileManager::deleteFile($file_path)) {
                    $results['deleted_files'][] = $file;
                    $results['deleted_count']++;
                    $results['freed_space'] += $file_size;
                } else {
                    $results['errors'][] = "Failed to delete: $file";
                }
            }
        }
        
        if ($results['deleted_count'] > 0) {
            $this->logger->info('CLEANUP_COMPLETED', [
                'Files_Deleted' => $results['deleted_count'],
                'Space_Freed' => $results['freed_space'],
                'Space_Freed_Formatted' => FileManager::formatFileSize($results['freed_space']),
                'Max_Age_Hours' => $max_age_hours
            ]);
        }
        
        return $results;
    }
}
?> 