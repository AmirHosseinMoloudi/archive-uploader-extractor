<?php
/**
 * Archive Combiner Module
 * Handles combining multipart archive files with memory-efficient streaming
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/FileManager.php';

class ArchiveCombiner {
    
    private $source_dir;
    private $progress_callback;
    private $logger;
    
    public function __construct($source_dir, $progress_callback = null) {
        $this->source_dir = rtrim($source_dir, '/') . '/';
        $this->progress_callback = $progress_callback;
        $this->logger = Logger::getInstance();
    }
    
    /**
     * Combine multipart ZIP files into a single archive
     * @param string $base_name Base name of the archive
     * @return string|false Path to combined file or false on failure
     */
    public function combineMultipartArchive($base_name) {
        $main_zip = $this->source_dir . $base_name . '.zip';
        $combined_zip = $this->source_dir . $base_name . '_combined.zip';
        
        $this->logger->info('MULTIPART_COMBINE_START', [
            'Base_Name' => $base_name,
            'Source_Dir' => $this->source_dir,
            'Main_ZIP' => $main_zip,
            'Combined_ZIP' => $combined_zip
        ]);
        
        try {
            // Validate main ZIP file
            if (!file_exists($main_zip)) {
                $this->logProgress("❌ Main ZIP file not found: $main_zip");
                $this->logger->error('MULTIPART_COMBINE_FAILED', [
                    'Base_Name' => $base_name,
                    'Error' => 'Main ZIP file not found',
                    'Main_ZIP' => $main_zip
                ]);
                return false;
            }
            
            // Find and validate parts
            $parts = $this->findArchiveParts($base_name);
            $total_size = $this->calculateTotalSize($main_zip, $parts);
            
            $this->logProgress("📊 Total size to combine: " . FileManager::formatFileSize($total_size));
            
            // Perform the combination
            $result = $this->performCombination($main_zip, $parts, $combined_zip, $total_size);
            
            if ($result) {
                $final_size = filesize($combined_zip);
                $this->logger->success('MULTIPART_COMBINE_SUCCESS', [
                    'Base_Name' => $base_name,
                    'Combined_File' => $combined_zip,
                    'Final_Size' => $final_size,
                    'Final_Size_Formatted' => FileManager::formatFileSize($final_size),
                    'Parts_Combined' => count($parts)
                ]);
                
                return $combined_zip;
            } else {
                $this->logger->error('MULTIPART_COMBINE_FAILED', [
                    'Base_Name' => $base_name,
                    'Error' => 'Combination process failed'
                ]);
                return false;
            }
            
        } catch (Exception $e) {
            $this->logger->error('MULTIPART_COMBINE_EXCEPTION', [
                'Base_Name' => $base_name,
                'Error' => $e->getMessage(),
                'File' => $e->getFile(),
                'Line' => $e->getLine()
            ]);
            return false;
        }
    }
    
    /**
     * Find all parts for a multipart archive
     * @param string $base_name
     * @return array
     */
    private function findArchiveParts($base_name) {
        $parts = [];
        $part_num = 1;
        
        // Look for parts up to z999
        while ($part_num <= 999) {
            $part_file = $this->source_dir . $base_name . '.z' . sprintf('%02d', $part_num);
            if (!file_exists($part_file)) {
                break;
            }
            $parts[] = $part_file;
            $part_num++;
        }
        
        return $parts;
    }
    
    /**
     * Calculate total size of all files to be combined
     * @param string $main_zip
     * @param array $parts
     * @return int
     */
    private function calculateTotalSize($main_zip, $parts) {
        $total_size = filesize($main_zip);
        
        foreach ($parts as $part_file) {
            $total_size += filesize($part_file);
        }
        
        return $total_size;
    }
    
    /**
     * Perform the actual file combination
     * @param string $main_zip
     * @param array $parts
     * @param string $combined_zip
     * @param int $total_size
     * @return bool
     */
    private function performCombination($main_zip, $parts, $combined_zip, $total_size) {
        // Open output file for writing
        $output_handle = fopen($combined_zip, 'wb');
        if (!$output_handle) {
            $this->logProgress("❌ Failed to create combined ZIP file: $combined_zip");
            return false;
        }
        
        $bytes_written = 0;
        
        try {
            // Copy main ZIP file first
            $this->logProgress("📦 Processing main file: " . basename($main_zip));
            if (!$this->copyFileStreaming($main_zip, $output_handle, $bytes_written, $total_size)) {
                fclose($output_handle);
                FileManager::deleteFile($combined_zip);
                return false;
            }
            
            // Append all parts in order
            foreach ($parts as $part_file) {
                $this->logProgress("📦 Processing part: " . basename($part_file));
                if (!$this->copyFileStreaming($part_file, $output_handle, $bytes_written, $total_size)) {
                    fclose($output_handle);
                    FileManager::deleteFile($combined_zip);
                    return false;
                }
            }
            
            fclose($output_handle);
            
            // Verify the combined file
            $final_size = file_exists($combined_zip) ? filesize($combined_zip) : 0;
            if ($final_size > 0) {
                $this->logProgress("✅ Combined file created: " . FileManager::formatFileSize($final_size));
                return true;
            } else {
                $this->logProgress("❌ Failed to create valid combined ZIP file");
                FileManager::deleteFile($combined_zip);
                return false;
            }
            
        } catch (Exception $e) {
            fclose($output_handle);
            FileManager::deleteFile($combined_zip);
            $this->logProgress("❌ Exception during combination: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Copy file using streaming with progress tracking
     * @param string $source_file
     * @param resource $output_handle
     * @param int &$bytes_written
     * @param int $total_size
     * @return bool
     */
    private function copyFileStreaming($source_file, $output_handle, &$bytes_written, $total_size) {
        $input_handle = fopen($source_file, 'rb');
        if (!$input_handle) {
            $this->logProgress("❌ Failed to open source file: $source_file");
            return false;
        }
        
        $last_progress_update = 0;
        
        while (!feof($input_handle)) {
            $chunk = fread($input_handle, CHUNK_SIZE);
            if ($chunk === false) {
                fclose($input_handle);
                $this->logProgress("❌ Failed to read from source file: $source_file");
                return false;
            }
            
            if (fwrite($output_handle, $chunk) === false) {
                fclose($input_handle);
                $this->logProgress("❌ Failed to write to combined file");
                return false;
            }
            
            $bytes_written += strlen($chunk);
            
            // Progress callback every 1MB or 5% progress
            $progress_threshold = max(1024 * 1024, $total_size * 0.05);
            if ($bytes_written - $last_progress_update >= $progress_threshold) {
                $progress = ($bytes_written / $total_size) * 100;
                $this->logProgress("⚡ Progress: " . number_format($progress, 1) . "% (" . FileManager::formatFileSize($bytes_written) . ")");
                $last_progress_update = $bytes_written;
            }
            
            // Check for connection abort
            if (connection_aborted()) {
                fclose($input_handle);
                $this->logProgress("❌ Connection aborted during file processing");
                return false;
            }
        }
        
        fclose($input_handle);
        return true;
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
        error_log("ArchiveCombiner: $message");
    }
    
    /**
     * Check if archive needs combination (has parts)
     * @param string $base_name
     * @return bool
     */
    public function needsCombination($base_name) {
        $parts = $this->findArchiveParts($base_name);
        return !empty($parts);
    }
    
    /**
     * Get information about multipart archive
     * @param string $base_name
     * @return array
     */
    public function getArchiveInfo($base_name) {
        $main_zip = $this->source_dir . $base_name . '.zip';
        $parts = $this->findArchiveParts($base_name);
        
        $info = [
            'base_name' => $base_name,
            'main_file' => $main_zip,
            'main_exists' => file_exists($main_zip),
            'main_size' => file_exists($main_zip) ? filesize($main_zip) : 0,
            'parts_count' => count($parts),
            'parts' => $parts,
            'total_size' => 0,
            'needs_combination' => !empty($parts)
        ];
        
        if ($info['main_exists']) {
            $info['total_size'] += $info['main_size'];
        }
        
        foreach ($parts as $part) {
            if (file_exists($part)) {
                $info['total_size'] += filesize($part);
            }
        }
        
        return $info;
    }
}
?> 