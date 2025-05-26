<?php
/**
 * Archive Extractor Module
 * Handles extraction of various archive formats
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/FileManager.php';

class ArchiveExtractor {
    
    private $logger;
    
    public function __construct() {
        $this->logger = Logger::getInstance();
    }
    
    /**
     * Extract archive file to specified directory
     * @param string $archive_file Path to archive file
     * @param string $extract_to Base extraction directory
     * @return array Extraction result
     */
    public function extractArchive($archive_file, $extract_to) {
        $result = [
            'success' => false,
            'message' => '',
            'extract_path' => '',
            'files_extracted' => 0,
            'archive_type' => '',
            'error' => ''
        ];
        
        $this->logger->info('ARCHIVE_EXTRACT_START', [
            'Archive_File' => $archive_file,
            'Extract_To' => $extract_to,
            'File_Size' => file_exists($archive_file) ? filesize($archive_file) : 0,
            'File_Extension' => FileManager::getFileExtension($archive_file)
        ]);
        
        try {
            // Validate archive file
            if (!file_exists($archive_file)) {
                $result['error'] = "Archive file does not exist: $archive_file";
                $this->logger->error('ARCHIVE_EXTRACT_FAILED', [
                    'Archive_File' => $archive_file,
                    'Error' => 'Archive file does not exist'
                ]);
                return $result;
            }
            
            $file_extension = FileManager::getFileExtension($archive_file);
            $base_name = FileManager::getBaseName($archive_file);
            $extract_path = rtrim($extract_to, '/') . '/' . $base_name . '/';
            
            $result['extract_path'] = $extract_path;
            $result['archive_type'] = strtoupper($file_extension);
            
            // Create extraction directory
            if (!$this->createExtractionDirectory($extract_path)) {
                $result['error'] = "Failed to create extraction directory: $extract_path";
                return $result;
            }
            
            // Extract based on file type
            switch ($file_extension) {
                case 'zip':
                    $extraction_result = $this->extractZip($archive_file, $extract_path);
                    break;
                    
                case 'rar':
                    $extraction_result = $this->extractRar($archive_file, $extract_path);
                    break;
                    
                case '7z':
                    $extraction_result = $this->extract7z($archive_file, $extract_path);
                    break;
                    
                case 'tar':
                case 'gz':
                case 'bz2':
                case 'xz':
                    $extraction_result = $this->extractTar($archive_file, $extract_path);
                    break;
                    
                default:
                    // Try as ZIP first (for multipart files)
                    $extraction_result = $this->extractZip($archive_file, $extract_path);
                    if (!$extraction_result['success']) {
                        $extraction_result['message'] = "Unsupported archive format: $file_extension";
                    }
                    break;
            }
            
            // Update result with extraction outcome
            $result['success'] = $extraction_result['success'];
            $result['message'] = $extraction_result['message'];
            $result['files_extracted'] = $extraction_result['files_extracted'];
            
            if ($result['success']) {
                $this->logger->success('ARCHIVE_EXTRACT_SUCCESS', [
                    'Archive_File' => $archive_file,
                    'Extract_Path' => $extract_path,
                    'Files_Extracted' => $result['files_extracted'],
                    'Archive_Type' => $result['archive_type'],
                    'Archive_Size' => filesize($archive_file)
                ]);
            } else {
                $result['error'] = $extraction_result['message'];
                $this->logger->error('ARCHIVE_EXTRACT_FAILED', [
                    'Archive_File' => $archive_file,
                    'Extract_Path' => $extract_path,
                    'Archive_Type' => $result['archive_type'],
                    'Error' => $result['error']
                ]);
            }
            
        } catch (Exception $e) {
            $result['error'] = "Exception during extraction: " . $e->getMessage();
            $this->logger->error('ARCHIVE_EXTRACT_EXCEPTION', [
                'Archive_File' => $archive_file,
                'Error' => $e->getMessage(),
                'File' => $e->getFile(),
                'Line' => $e->getLine()
            ]);
        }
        
        return $result;
    }
    
    /**
     * Extract ZIP archive
     * @param string $archive_file
     * @param string $extract_path
     * @return array
     */
    private function extractZip($archive_file, $extract_path) {
        $result = [
            'success' => false,
            'message' => '',
            'files_extracted' => 0
        ];
        
        if (!class_exists('ZipArchive')) {
            $result['message'] = "ZipArchive class not available on this server";
            return $result;
        }
        
        $zip = new ZipArchive;
        $open_result = $zip->open($archive_file);
        
        if ($open_result === TRUE) {
            $files_extracted = $zip->numFiles;
            $extract_success = $zip->extractTo($extract_path);
            $zip->close();
            
            if ($extract_success) {
                // Verify extraction by counting actual files
                $actual_files = $this->countExtractedFiles($extract_path);
                $result['success'] = true;
                $result['files_extracted'] = $actual_files;
                $result['message'] = "Successfully extracted ZIP to: $extract_path (Files extracted: $actual_files)";
            } else {
                $result['message'] = "Failed to extract ZIP contents";
            }
        } else {
            $result['message'] = "Failed to open ZIP file. Error code: $open_result";
        }
        
        return $result;
    }
    
    /**
     * Extract RAR archive
     * @param string $archive_file
     * @param string $extract_path
     * @return array
     */
    private function extractRar($archive_file, $extract_path) {
        $result = [
            'success' => false,
            'message' => '',
            'files_extracted' => 0
        ];
        
        if (!extension_loaded('rar')) {
            $result['message'] = "RAR extension not available. Trying alternative method...";
            return $result;
        }
        
        try {
            $rar = rar_open($archive_file);
            if ($rar) {
                $entries = rar_list($rar);
                $files_extracted = 0;
                
                foreach ($entries as $entry) {
                    if ($entry->extract($extract_path)) {
                        $files_extracted++;
                    }
                }
                
                rar_close($rar);
                
                $result['success'] = true;
                $result['files_extracted'] = $files_extracted;
                $result['message'] = "Successfully extracted RAR to: $extract_path";
            } else {
                $result['message'] = "Failed to open RAR file";
            }
        } catch (Exception $e) {
            $result['message'] = "RAR extraction failed: " . $e->getMessage();
        }
        
        return $result;
    }
    
    /**
     * Extract 7Z archive using system command
     * @param string $archive_file
     * @param string $extract_path
     * @return array
     */
    private function extract7z($archive_file, $extract_path) {
        $result = [
            'success' => false,
            'message' => '',
            'files_extracted' => 0
        ];
        
        $command = "7z x " . escapeshellarg($archive_file) . " -o" . escapeshellarg($extract_path) . " -y";
        $output = shell_exec($command . " 2>&1");
        
        if (strpos($output, 'Everything is Ok') !== false) {
            $files_extracted = $this->countExtractedFiles($extract_path);
            $result['success'] = true;
            $result['files_extracted'] = $files_extracted;
            $result['message'] = "Successfully extracted 7Z to: $extract_path";
        } else {
            $result['message'] = "7Z extraction failed. Output: " . $output;
        }
        
        return $result;
    }
    
    /**
     * Extract TAR/GZ/BZ2/XZ archive using system command
     * @param string $archive_file
     * @param string $extract_path
     * @return array
     */
    private function extractTar($archive_file, $extract_path) {
        $result = [
            'success' => false,
            'message' => '',
            'files_extracted' => 0
        ];
        
        $command = "tar -xf " . escapeshellarg($archive_file) . " -C " . escapeshellarg($extract_path);
        $output = shell_exec($command . " 2>&1");
        
        if ($output === null || empty($output)) {
            $files_extracted = $this->countExtractedFiles($extract_path);
            $result['success'] = true;
            $result['files_extracted'] = $files_extracted;
            $result['message'] = "Successfully extracted TAR/GZ to: $extract_path";
        } else {
            $result['message'] = "TAR extraction failed. Output: " . $output;
        }
        
        return $result;
    }
    
    /**
     * Create extraction directory
     * @param string $extract_path
     * @return bool
     */
    private function createExtractionDirectory($extract_path) {
        if (!is_dir($extract_path)) {
            return mkdir($extract_path, 0755, true);
        }
        return true;
    }
    
    /**
     * Count files in extraction directory
     * @param string $extract_path
     * @return int
     */
    private function countExtractedFiles($extract_path) {
        if (!is_dir($extract_path)) {
            return 0;
        }
        
        $count = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($extract_path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $count++;
            }
        }
        
        return $count;
    }
    
    /**
     * Get supported archive formats
     * @return array
     */
    public function getSupportedFormats() {
        $formats = [];
        
        // ZIP support
        if (class_exists('ZipArchive')) {
            $formats['zip'] = [
                'name' => 'ZIP',
                'extensions' => ['zip'],
                'available' => true
            ];
        }
        
        // RAR support
        if (extension_loaded('rar')) {
            $formats['rar'] = [
                'name' => 'RAR',
                'extensions' => ['rar'],
                'available' => true
            ];
        }
        
        // 7Z support (requires system command)
        $formats['7z'] = [
            'name' => '7-Zip',
            'extensions' => ['7z'],
            'available' => $this->checkSystemCommand('7z')
        ];
        
        // TAR support (requires system command)
        $formats['tar'] = [
            'name' => 'TAR/GZ/BZ2/XZ',
            'extensions' => ['tar', 'gz', 'bz2', 'xz'],
            'available' => $this->checkSystemCommand('tar')
        ];
        
        return $formats;
    }
    
    /**
     * Check if system command is available
     * @param string $command
     * @return bool
     */
    private function checkSystemCommand($command) {
        $output = shell_exec("which $command 2>/dev/null");
        return !empty($output);
    }
    
    /**
     * Get extraction statistics
     * @param string $extract_base_dir
     * @return array
     */
    public function getExtractionStats($extract_base_dir) {
        $stats = [
            'total_extractions' => 0,
            'total_files' => 0,
            'total_size' => 0,
            'extractions' => []
        ];
        
        if (!is_dir($extract_base_dir)) {
            return $stats;
        }
        
        $directories = array_filter(scandir($extract_base_dir), function($item) use ($extract_base_dir) {
            return $item != '.' && $item != '..' && is_dir($extract_base_dir . $item);
        });
        
        foreach ($directories as $dir) {
            $dir_path = $extract_base_dir . $dir;
            $file_count = $this->countExtractedFiles($dir_path);
            $dir_size = $this->getDirectorySize($dir_path);
            
            $stats['extractions'][] = [
                'name' => $dir,
                'path' => $dir_path,
                'files' => $file_count,
                'size' => $dir_size,
                'modified' => filemtime($dir_path)
            ];
            
            $stats['total_extractions']++;
            $stats['total_files'] += $file_count;
            $stats['total_size'] += $dir_size;
        }
        
        // Sort by modification time (newest first)
        usort($stats['extractions'], function($a, $b) {
            return $b['modified'] - $a['modified'];
        });
        
        return $stats;
    }
    
    /**
     * Get directory size recursively
     * @param string $directory
     * @return int
     */
    private function getDirectorySize($directory) {
        $size = 0;
        
        if (!is_dir($directory)) {
            return 0;
        }
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }
        
        return $size;
    }
}
?> 