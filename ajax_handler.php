<?php
// Separate AJAX handler to prevent output buffer conflicts
// Disable all error reporting for clean JSON responses
error_reporting(0);
ini_set('display_errors', 0);

// Clean any existing output buffers
while (ob_get_level()) {
    ob_end_clean();
}

// Start fresh output buffering
ob_start();

// Set JSON headers
@header('Content-Type: application/json');
@header('Cache-Control: no-cache');

// Comprehensive Logging System (shared with main script)
$log_file = 'uploader_operations.log';
$max_log_lines = 50;

function writeDetailedLog($operation, $details = [], $status = 'INFO') {
    global $log_file, $max_log_lines;
    
    // Prepare log entry
    $timestamp = date('Y-m-d H:i:s');
    $user_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    $request_method = $_SERVER['REQUEST_METHOD'] ?? 'unknown';
    $request_uri = $_SERVER['REQUEST_URI'] ?? 'unknown';
    
    // Build detailed log entry
    $log_entry = "[$timestamp] [$status] [$operation] [AJAX_HANDLER]\n";
    $log_entry .= "  IP: $user_ip\n";
    $log_entry .= "  Method: $request_method\n";
    $log_entry .= "  URI: $request_uri\n";
    $log_entry .= "  User-Agent: " . substr($user_agent, 0, 100) . "\n";
    
    // Add operation-specific details
    if (!empty($details)) {
        foreach ($details as $key => $value) {
            if (is_array($value)) {
                $log_entry .= "  $key: " . json_encode($value) . "\n";
            } else {
                $log_entry .= "  $key: $value\n";
            }
        }
    }
    
    // Add memory usage
    $memory_used = round(memory_get_usage(true) / 1024 / 1024, 2);
    $memory_peak = round(memory_get_peak_usage(true) / 1024 / 1024, 2);
    $log_entry .= "  Memory: {$memory_used}MB (Peak: {$memory_peak}MB)\n";
    $log_entry .= "  PHP Version: " . PHP_VERSION . "\n";
    $log_entry .= "  " . str_repeat('-', 80) . "\n";
    
    // Read existing log
    $existing_lines = [];
    if (file_exists($log_file)) {
        $existing_content = file_get_contents($log_file);
        if (!empty($existing_content)) {
            $existing_lines = explode("\n", $existing_content);
        }
    }
    
    // Add new entry to beginning
    $new_lines = explode("\n", $log_entry);
    $existing_lines = array_merge($new_lines, $existing_lines);
    
    // Limit to max lines
    if (count($existing_lines) > $max_log_lines) {
        $existing_lines = array_slice($existing_lines, 0, $max_log_lines);
    }
    
    // Write back to file
    @file_put_contents($log_file, implode("\n", $existing_lines));
}

// Include the main configuration and functions
$upload_dir = 'uploads/';
$extract_dir = 'extracted/';
$max_file_size = PHP_INT_MAX;
$allowed_extensions = [
    'zip', 'z01', 'z02', 'z03', 'z04', 'z05', 'z06', 'z07', 'z08', 'z09',
    'z10', 'z11', 'z12', 'z13', 'z14', 'z15', 'z16', 'z17', 'z18', 'z19',
    'z20', 'z21', 'z22', 'z23', 'z24', 'z25', 'z26', 'z27', 'z28', 'z29',
    'z30', 'z31', 'z32', 'z33', 'z34', 'z35', 'z36', 'z37', 'z38', 'z39',
    'z40', 'z41', 'z42', 'z43', 'z44', 'z45', 'z46', 'z47', 'z48', 'z49',
    'z50', 'z51', 'z52', 'z53', 'z54', 'z55', 'z56', 'z57', 'z58', 'z59',
    'z60', 'z61', 'z62', 'z63', 'z64', 'z65', 'z66', 'z67', 'z68', 'z69',
    'z70', 'z71', 'z72', 'z73', 'z74', 'z75', 'z76', 'z77', 'z78', 'z79',
    'z80', 'z81', 'z82', 'z83', 'z84', 'z85', 'z86', 'z87', 'z88', 'z89',
    'z90', 'z91', 'z92', 'z93', 'z94', 'z95', 'z96', 'z97', 'z98', 'z99',
    'rar', '7z', 'tar', 'gz', 'bz2', 'xz', 'lzma'
];

// Set optimized PHP limits
@ini_set('max_execution_time', 0);
@ini_set('max_input_time', -1);
@ini_set('memory_limit', '2G');

// Create directories if they don't exist
if (!is_dir($upload_dir)) {
    @mkdir($upload_dir, 0755, true);
}
if (!is_dir($extract_dir)) {
    @mkdir($extract_dir, 0755, true);
}

// Helper functions (simplified versions)
function getFileExtension($filename) {
    return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
}

function getBaseName($filename) {
    $pathinfo = pathinfo($filename);
    return $pathinfo['filename'];
}

function listUploadedFiles($upload_dir) {
    $files = [];
    if (is_dir($upload_dir)) {
        $scan = @scandir($upload_dir);
        if ($scan) {
            foreach ($scan as $file) {
                if ($file != '.' && $file != '..' && is_file($upload_dir . $file)) {
                    $files[] = $file;
                }
            }
        }
    }
    return $files;
}

function groupMultipartFiles($files) {
    $groups = [];
    foreach ($files as $file) {
        $ext = getFileExtension($file);
        if (in_array($ext, ['zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz', 'lzma'])) {
            $base = getBaseName($file);
            $groups[$base]['main'] = $file;
        } elseif (preg_match('/^z\d+$/', $ext)) {
            $base = getBaseName($file);
            $groups[$base]['parts'][] = $file;
        }
    }
    return $groups;
}

// Simple AJAX test endpoint
if (isset($_POST['ajax_test'])) {
    // Log AJAX test
    writeDetailedLog('AJAX_TEST_HANDLER', [
        'Handler' => 'ajax_handler.php',
        'Test_Type' => 'Connection Test'
    ]);
    
    $response = [
        'success' => true,
        'message' => 'AJAX is working!',
        'logs' => [
            '✅ AJAX test successful (separate handler)',
            '🔧 Server is responding correctly',
            '📡 Communication established',
            '🔍 PHP version: ' . PHP_VERSION,
            '💾 Memory limit: ' . @ini_get('memory_limit'),
            '🌐 Handler: ajax_handler.php'
        ]
    ];
    
    echo json_encode($response);
    
    if (ob_get_level()) {
        ob_end_flush();
    }
    exit;
}

// Function to combine multipart ZIP files (memory-efficient streaming)
function combineMultipartZip($source_dir, $base_name, &$response) {
    $main_zip = $source_dir . $base_name . '.zip';
    $combined_zip = $source_dir . $base_name . '_combined.zip';
    
    // Start with the main ZIP file
    if (!file_exists($main_zip)) {
        $response['logs'][] = "❌ Main ZIP file not found: $main_zip";
        return false;
    }
    
    // Calculate total size and find parts
    $total_size = filesize($main_zip);
    $parts = [];
    $part_num = 1;
    while (true) {
        $part_file = $source_dir . $base_name . '.z' . sprintf('%02d', $part_num);
        if (!file_exists($part_file)) {
            break;
        }
        $parts[] = $part_file;
        $total_size += filesize($part_file);
        $part_num++;
    }
    
    if (empty($parts)) {
        $response['logs'][] = "🔧 No multipart files found, using single ZIP";
        return $main_zip; // Return original file if no parts
    }
    
    $response['logs'][] = "📊 Found " . count($parts) . " parts, total size: " . number_format($total_size / 1024 / 1024, 2) . " MB";
    
    // Open output file for writing
    $output_handle = @fopen($combined_zip, 'wb');
    if (!$output_handle) {
        $response['logs'][] = "❌ Failed to create combined ZIP file";
        return false;
    }
    
    $bytes_written = 0;
    
    // Copy main ZIP file first
    $response['logs'][] = "📦 Processing main file: " . basename($main_zip);
    $main_handle = @fopen($main_zip, 'rb');
    if (!$main_handle) {
        fclose($output_handle);
        @unlink($combined_zip);
        $response['logs'][] = "❌ Failed to open main ZIP file";
        return false;
    }
    
    // Stream copy main file
    while (!feof($main_handle)) {
        $chunk = fread($main_handle, 65536); // 64KB chunks
        if ($chunk === false) break;
        if (fwrite($output_handle, $chunk) === false) {
            fclose($main_handle);
            fclose($output_handle);
            @unlink($combined_zip);
            $response['logs'][] = "❌ Failed to write to combined file";
            return false;
        }
        $bytes_written += strlen($chunk);
    }
    fclose($main_handle);
    
    // Append all parts
    foreach ($parts as $part_file) {
        $response['logs'][] = "📦 Processing part: " . basename($part_file);
        
        $part_handle = @fopen($part_file, 'rb');
        if (!$part_handle) {
            $response['logs'][] = "⚠️ Failed to open part: " . basename($part_file);
            continue;
        }
        
        while (!feof($part_handle)) {
            $chunk = fread($part_handle, 65536);
            if ($chunk === false) break;
            if (fwrite($output_handle, $chunk) === false) {
                fclose($part_handle);
                fclose($output_handle);
                @unlink($combined_zip);
                $response['logs'][] = "❌ Failed to write part data";
                return false;
            }
            $bytes_written += strlen($chunk);
        }
        fclose($part_handle);
    }
    
    fclose($output_handle);
    
    // Verify the combined file
    $final_size = file_exists($combined_zip) ? filesize($combined_zip) : 0;
    if ($final_size > 0) {
        $response['logs'][] = "✅ Combined file created: " . number_format($final_size / 1024 / 1024, 2) . " MB";
        return $combined_zip;
    } else {
        $response['logs'][] = "❌ Failed to create valid combined file";
        if (file_exists($combined_zip)) {
            @unlink($combined_zip);
        }
        return false;
    }
}

// Handle AJAX extraction requests
if (isset($_POST['ajax_extract']) && isset($_POST['zip_group'])) {
    $base_name = $_POST['zip_group'];
    $source_dir = isset($_POST['source_dir']) ? $_POST['source_dir'] : $upload_dir;
    $main_zip = $source_dir . $base_name . '.zip';
    
    // Log extraction start
    writeDetailedLog('AJAX_EXTRACT_HANDLER', [
        'Base_Name' => $base_name,
        'Source_Dir' => $source_dir,
        'Main_ZIP' => $main_zip,
        'Handler' => 'ajax_handler.php'
    ]);
    
    $response = ['success' => false, 'message' => '', 'logs' => []];
    
    try {
        $response['logs'][] = "🔧 AJAX extraction started (separate handler)";
        $response['logs'][] = "📂 Source directory: $source_dir";
        $response['logs'][] = "📄 Main ZIP file: $main_zip";
        
        // Check if it's a multipart ZIP and combine if needed
        $current_files = listUploadedFiles($source_dir);
        $groups = groupMultipartFiles($current_files);
        
        $archive_to_extract = null;
        $cleanup_file = null;
        
        if (isset($groups[$base_name]['parts']) && count($groups[$base_name]['parts']) > 0) {
            $response['logs'][] = "🔧 Found multipart archive with " . count($groups[$base_name]['parts']) . " parts";
            $response['logs'][] = "🔄 Combining multipart files...";
            
            $combined_zip = combineMultipartZip($source_dir, $base_name, $response);
            if ($combined_zip) {
                $archive_to_extract = $combined_zip;
                $cleanup_file = $combined_zip; // Mark for cleanup
                $response['logs'][] = "✅ Parts combined successfully";
            } else {
                $response['logs'][] = "❌ Failed to combine multipart files";
                $response['message'] = "Failed to combine multipart archive";
            }
        } elseif (file_exists($main_zip)) {
            $response['logs'][] = "🔧 Processing single archive file";
            $archive_to_extract = $main_zip;
        } else {
            $response['logs'][] = "❌ Archive file not found: $main_zip";
            $response['message'] = "Archive file not found";
        }
        
        // Extract the archive
        if ($archive_to_extract) {
            $response['logs'][] = "🗜️ Extracting archive...";
            
            if (class_exists('ZipArchive')) {
                $zip = new ZipArchive;
                $result = $zip->open($archive_to_extract);
                
                if ($result === TRUE) {
                    $extract_path = $extract_dir . $base_name . '/';
                    if (!is_dir($extract_path)) {
                        @mkdir($extract_path, 0755, true);
                    }
                    
                    $extract_success = $zip->extractTo($extract_path);
                    $num_files = $zip->numFiles;
                    $zip->close();
                    
                    if ($extract_success) {
                        $response['logs'][] = "✅ Extraction successful ($num_files files)";
                        $response['success'] = true;
                        $response['message'] = "Archive extracted successfully ($num_files files)";
                    } else {
                        $response['logs'][] = "❌ Extraction failed";
                        $response['message'] = "Failed to extract archive";
                    }
                } else {
                    $response['logs'][] = "❌ Failed to open ZIP file (Error code: $result)";
                    $response['message'] = "Failed to open ZIP file (Error code: $result)";
                }
            } else {
                $response['logs'][] = "❌ ZipArchive not available";
                $response['message'] = "ZipArchive extension not available";
            }
            
            // Clean up temporary combined file
            if ($cleanup_file && file_exists($cleanup_file)) {
                @unlink($cleanup_file);
                $response['logs'][] = "🧹 Cleaned up temporary files";
            }
        }
        
    } catch (Exception $e) {
        $response['logs'][] = "❌ Error: " . $e->getMessage();
        $response['message'] = "Extraction failed: " . $e->getMessage();
    }
    
    echo json_encode($response);
    
    if (ob_get_level()) {
        ob_end_flush();
    }
    exit;
}

// Handle AJAX manual file selection
if (isset($_POST['ajax_extract_selected']) && isset($_POST['selected_files'])) {
    $selected_files = $_POST['selected_files'];
    $response = ['success' => false, 'message' => '', 'logs' => []];
    
    try {
        if (!empty($selected_files)) {
            $response['logs'][] = "🔧 Starting manual file selection extraction";
            $response['logs'][] = "📁 Selected " . count($selected_files) . " files";
            
            // Create a temporary combined archive
            $temp_name = 'manual_selection_' . time();
            $temp_combined = $upload_dir . $temp_name . '_combined.zip';
            
            $response['logs'][] = "🔄 Creating temporary combined archive...";
            
            // Open output file for writing
            $output_handle = @fopen($temp_combined, 'wb');
            if (!$output_handle) {
                $response['logs'][] = "❌ Failed to create temporary file";
                $response['message'] = "Failed to create temporary combined file";
            } else {
                $files_combined = 0;
                
                // Combine selected files using streaming
                foreach ($selected_files as $file) {
                    $file_path = './' . basename($file);
                    if (file_exists($file_path)) {
                        $response['logs'][] = "📦 Adding file: " . basename($file);
                        
                        $input_handle = @fopen($file_path, 'rb');
                        if ($input_handle) {
                            // Stream copy file in chunks
                            while (!feof($input_handle)) {
                                $chunk = fread($input_handle, 65536);
                                if ($chunk !== false) {
                                    fwrite($output_handle, $chunk);
                                }
                            }
                            fclose($input_handle);
                            $files_combined++;
                        }
                    } else {
                        $response['logs'][] = "⚠️ File not found: " . basename($file);
                    }
                }
                
                fclose($output_handle);
                
                if ($files_combined > 0 && file_exists($temp_combined) && filesize($temp_combined) > 0) {
                    $response['logs'][] = "✅ Combined $files_combined files successfully";
                    $response['logs'][] = "🗜️ Extracting combined archive...";
                    
                    // Extract the combined archive
                    if (class_exists('ZipArchive')) {
                        $zip = new ZipArchive;
                        $result = $zip->open($temp_combined);
                        
                        if ($result === TRUE) {
                            $extract_path = $extract_dir . $temp_name . '/';
                            if (!is_dir($extract_path)) {
                                @mkdir($extract_path, 0755, true);
                            }
                            
                            $extract_success = $zip->extractTo($extract_path);
                            $num_files = $zip->numFiles;
                            $zip->close();
                            
                            if ($extract_success) {
                                $response['logs'][] = "✅ Extraction successful ($num_files files)";
                                $response['success'] = true;
                                $response['message'] = "Manual selection extracted successfully ($files_combined files combined, $num_files files extracted)";
                            } else {
                                $response['logs'][] = "❌ Extraction failed";
                                $response['message'] = "Failed to extract combined archive";
                            }
                        } else {
                            $response['logs'][] = "❌ Failed to open combined ZIP file (Error code: $result)";
                            $response['message'] = "Failed to open combined ZIP file";
                        }
                    } else {
                        $response['logs'][] = "❌ ZipArchive not available";
                        $response['message'] = "ZipArchive extension not available";
                    }
                    
                    // Clean up temporary file
                    if (file_exists($temp_combined)) {
                        @unlink($temp_combined);
                        $response['logs'][] = "🧹 Cleaned up temporary files";
                    }
                } else {
                    $response['logs'][] = "❌ No valid files were combined";
                    $response['message'] = "No valid files selected or files not found";
                    if (file_exists($temp_combined)) {
                        @unlink($temp_combined);
                    }
                }
            }
        } else {
            $response['logs'][] = "❌ No files selected";
            $response['message'] = "No files selected for extraction";
        }
    } catch (Exception $e) {
        $response['logs'][] = "❌ Error: " . $e->getMessage();
        $response['message'] = "Extraction failed: " . $e->getMessage();
    }
    
    echo json_encode($response);
    
    if (ob_get_level()) {
        ob_end_flush();
    }
    exit;
}

// Handle AJAX URL download requests
if (isset($_POST['ajax_download_url']) && isset($_POST['download_url'])) {
    $download_url = trim($_POST['download_url']);
    $custom_filename = isset($_POST['custom_filename']) ? trim($_POST['custom_filename']) : '';
    
    $response = ['success' => false, 'message' => '', 'logs' => []];
    
    try {
        $response['logs'][] = "🌐 Starting URL download process (AJAX handler)";
        $response['logs'][] = "📡 URL: " . $download_url;
        
        // Validate URL
        if (empty($download_url)) {
            $response['logs'][] = "❌ No URL provided";
            $response['message'] = "Please provide a valid URL";
        } elseif (!filter_var($download_url, FILTER_VALIDATE_URL)) {
            $response['logs'][] = "❌ Invalid URL format";
            $response['message'] = "Invalid URL format";
        } else {
            // Get file info from URL
            $url_info = parse_url($download_url);
            $path_info = pathinfo($url_info['path']);
            
            // Determine filename
            if (!empty($custom_filename)) {
                $filename = $custom_filename;
                if (!pathinfo($filename, PATHINFO_EXTENSION)) {
                    $filename .= '.zip'; // Default to .zip if no extension
                }
            } elseif (!empty($path_info['basename'])) {
                $filename = $path_info['basename'];
            } else {
                $filename = 'downloaded_file_' . time() . '.zip';
            }
            
            // Sanitize filename
            $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
            $target_path = './' . $filename;
            
            $response['logs'][] = "📁 Target filename: " . $filename;
            $response['logs'][] = "💾 Saving to: " . $target_path;
            
            // Check if file already exists
            if (file_exists($target_path)) {
                $filename = pathinfo($filename, PATHINFO_FILENAME) . '_' . time() . '.' . pathinfo($filename, PATHINFO_EXTENSION);
                $target_path = './' . $filename;
                $response['logs'][] = "⚠️ File exists, using new name: " . $filename;
            }
            
            // Check if cURL is available
            if (!function_exists('curl_init')) {
                $response['logs'][] = "❌ cURL extension not available";
                $response['message'] = "cURL extension is required for URL downloads";
            } else {
                // Initialize cURL
                $ch = curl_init();
                
                // Open target file for writing
                $fp = @fopen($target_path, 'wb');
                if (!$fp) {
                    $response['logs'][] = "❌ Failed to create target file";
                    $response['message'] = "Failed to create target file";
                } else {
                    $response['logs'][] = "🔧 Initializing download...";
                    
                    // Configure cURL options
                    curl_setopt_array($ch, [
                        CURLOPT_URL => $download_url,
                        CURLOPT_FILE => $fp,
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_MAXREDIRS => 10,
                        CURLOPT_TIMEOUT => 3600, // 1 hour timeout
                        CURLOPT_CONNECTTIMEOUT => 30,
                        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
                        CURLOPT_SSL_VERIFYPEER => false,
                        CURLOPT_SSL_VERIFYHOST => false,
                        CURLOPT_BUFFERSIZE => 65536, // 64KB buffer
                        CURLOPT_NOPROGRESS => true, // Disable progress for AJAX handler
                    ]);
                    
                    $response['logs'][] = "🚀 Starting download...";
                    
                    // Execute download
                    $result = curl_exec($ch);
                    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $download_size = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
                    $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
                    $error = curl_error($ch);
                    
                    curl_close($ch);
                    fclose($fp);
                    
                    if ($result === false || !empty($error)) {
                        $response['logs'][] = "❌ cURL error: " . $error;
                        $response['message'] = "Download failed: " . $error;
                        if (file_exists($target_path)) {
                            @unlink($target_path);
                        }
                    } elseif ($http_code >= 400) {
                        $response['logs'][] = "❌ HTTP error: " . $http_code;
                        $response['message'] = "Download failed with HTTP code: " . $http_code;
                        if (file_exists($target_path)) {
                            @unlink($target_path);
                        }
                    } else {
                        $file_size = file_exists($target_path) ? filesize($target_path) : 0;
                        
                        if ($file_size > 0) {
                            $size_mb = round($file_size / 1024 / 1024, 2);
                            $response['logs'][] = "✅ Download completed successfully!";
                            $response['logs'][] = "📊 File size: {$size_mb} MB";
                            $response['logs'][] = "📄 Content type: " . ($content_type ?: 'unknown');
                            $response['logs'][] = "💾 Saved as: " . $filename;
                            
                            // Verify file extension
                            $file_ext = getFileExtension($filename);
                            if (in_array($file_ext, $allowed_extensions)) {
                                $response['logs'][] = "✅ File type verified: ." . $file_ext;
                            } else {
                                $response['logs'][] = "⚠️ Warning: File extension ." . $file_ext . " may not be supported";
                            }
                            
                            $response['success'] = true;
                            $response['message'] = "File downloaded successfully: " . $filename . " ({$size_mb} MB)";
                            $response['filename'] = $filename;
                            $response['file_size'] = $file_size;
                        } else {
                            $response['logs'][] = "❌ Downloaded file is empty or corrupted";
                            $response['message'] = "Downloaded file is empty or corrupted";
                            if (file_exists($target_path)) {
                                @unlink($target_path);
                            }
                        }
                    }
                }
            }
        }
    } catch (Exception $e) {
        $response['logs'][] = "❌ Exception: " . $e->getMessage();
        $response['message'] = "Download failed: " . $e->getMessage();
        if (isset($target_path) && file_exists($target_path)) {
            @unlink($target_path);
        }
    }
    
    echo json_encode($response);
    
    if (ob_get_level()) {
        ob_end_flush();
    }
    exit;
}

// Default response for invalid requests
echo json_encode([
    'success' => false,
    'message' => 'Invalid AJAX request',
    'logs' => ['❌ No valid AJAX action specified']
]);

if (ob_get_level()) {
    ob_end_flush();
}
exit;
?> 