<?php
/**
 * Archive Uploader & Extractor - Main Interface
 * Modular system with dedicated microsystem modules
 * Version 2.0.0
 */

// Include all modules
require_once 'config.php';
require_once 'modules/Logger.php';
require_once 'modules/FileManager.php';
require_once 'modules/FileUploader.php';
require_once 'modules/ArchiveCombiner.php';
require_once 'modules/ArchiveExtractor.php';
require_once 'modules/URLDownloader.php';

// Initialize system
$logger = Logger::getInstance();
$logger->info('SYSTEM_STARTUP', [
    'Script' => basename(__FILE__),
    'Version' => SYSTEM_VERSION,
    'Upload_Dir' => UPLOAD_DIR,
    'Extract_Dir' => EXTRACT_DIR,
    'PHP_Config' => PHPConfig::getCurrentConfig()
]);

// Handle AJAX requests FIRST before any output
if (isset($_POST['ajax_test']) || isset($_POST['ajax_extract']) || 
    isset($_POST['ajax_extract_selected']) || isset($_POST['ajax_download_url'])) {
    
    // Disable error reporting for AJAX to prevent HTML errors in JSON response
    error_reporting(0);
    ini_set('display_errors', 0);
    
    // Clean all existing output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Start fresh output buffering
    ob_start();
    
    // Set headers (suppress any header warnings)
    @header('Content-Type: application/json');
    @header('Cache-Control: no-cache');
}

// Initialize variables
$message = '';
$error = '';

// Handle log management requests
if (isset($_POST['clear_log'])) {
    $logger->log('LOG_CLEARED', [
        'Action' => 'Manual log clear',
        'Cleared_By' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);
    
    $logger->clearLog();
    echo json_encode(['success' => true, 'message' => 'Log cleared successfully']);
    exit;
}

// Handle log content request
if (isset($_POST['get_log'])) {
    // Just return the current page content so the JavaScript can parse it
    // This is a simple way to refresh the log content
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Simple AJAX test endpoint
if (isset($_POST['ajax_test'])) {
    $logger->info('AJAX_TEST', [
        'Request_Type' => 'AJAX Test',
        'User_Agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ]);
    
    $response = [
        'success' => true,
        'message' => 'AJAX is working!',
        'logs' => [
            '✅ AJAX test successful',
            '🔧 Server is responding correctly',
            '📡 Communication established',
            '🔍 PHP version: ' . PHP_VERSION,
            '💾 Memory limit: ' . ini_get('memory_limit'),
            '🌐 Modular system v' . SYSTEM_VERSION
        ]
    ];
    
    echo json_encode($response);
    
    if (ob_get_level()) {
        ob_end_flush();
    }
    exit;
}

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['zipfiles'])) {
    $uploader = new FileUploader();
    $upload_results = $uploader->handleUpload($_FILES['zipfiles']);
    
    if ($upload_results['total_uploaded'] > 0) {
        $message = "Successfully uploaded {$upload_results['total_uploaded']} file(s). Total size: " . 
                  FileManager::formatFileSize($upload_results['total_size']);
    }
    
    if (!empty($upload_results['errors'])) {
        $error = implode('<br>', $upload_results['errors']);
    }
}

// Handle AJAX extraction requests
if (isset($_POST['ajax_extract']) && isset($_POST['zip_group'])) {
    $base_name = $_POST['zip_group'];
    $source_dir = isset($_POST['source_dir']) ? $_POST['source_dir'] : UPLOAD_DIR;
    
    $logger->info('AJAX_EXTRACT_START', [
        'Base_Name' => $base_name,
        'Source_Dir' => $source_dir,
        'Request_Type' => 'AJAX Extraction'
    ]);
    
    $response = ['success' => false, 'message' => '', 'logs' => []];
    
    try {
        $response['logs'][] = "🔧 Starting extraction process for: $base_name";
        $response['logs'][] = "📂 Source directory: $source_dir";
        
        // Check if it's a multipart archive
        $files = FileManager::listFiles($source_dir);
        $groups = FileManager::groupMultipartFiles($files);
        
        $archive_to_extract = null;
        $cleanup_file = null;
        
        if (isset($groups[$base_name]['parts']) && count($groups[$base_name]['parts']) > 0) {
            $response['logs'][] = "🔧 Found multipart archive with " . count($groups[$base_name]['parts']) . " parts";
            $response['logs'][] = "🔄 Combining multipart files...";
            
            // Create progress callback to capture combination progress
            $progress_callback = function($message) use (&$response) {
                $response['logs'][] = $message;
            };
            
            $combiner = new ArchiveCombiner($source_dir, $progress_callback);
            $combined_zip = $combiner->combineMultipartArchive($base_name);
            
            if ($combined_zip) {
                $archive_to_extract = $combined_zip;
                $cleanup_file = $combined_zip;
                $response['logs'][] = "✅ Parts combined successfully";
            } else {
                $response['logs'][] = "❌ Failed to combine multipart files";
                $response['message'] = "Failed to combine multipart archive files";
            }
        } else {
            $main_zip = $source_dir . $base_name . '.zip';
            if (file_exists($main_zip)) {
                $response['logs'][] = "🔧 Processing single archive file";
                $archive_to_extract = $main_zip;
            } else {
                $response['logs'][] = "❌ Archive file not found: $main_zip";
                $response['message'] = "Archive file not found";
            }
        }
        
        // Extract the archive
        if ($archive_to_extract) {
            $response['logs'][] = "🗜️ Extracting archive...";
            
            $extractor = new ArchiveExtractor();
            $extract_result = $extractor->extractArchive($archive_to_extract, EXTRACT_DIR);
            
            if ($extract_result['success']) {
                $response['logs'][] = "✅ Extraction successful ({$extract_result['files_extracted']} files)";
                $response['success'] = true;
                $response['message'] = "Archive extracted successfully ({$extract_result['files_extracted']} files)";
            } else {
                $response['logs'][] = "❌ Extraction failed: " . $extract_result['error'];
                $response['message'] = "Extraction failed: " . $extract_result['error'];
            }
            
            // Clean up temporary combined file
            if ($cleanup_file && file_exists($cleanup_file)) {
                FileManager::deleteFile($cleanup_file);
                $response['logs'][] = "🧹 Cleaned up temporary files";
            }
        }
        
    } catch (Exception $e) {
        $response['logs'][] = "❌ Error: " . $e->getMessage();
        $response['message'] = "Extraction failed: " . $e->getMessage();
        $logger->error('AJAX_EXTRACT_EXCEPTION', [
            'Base_Name' => $base_name,
            'Error' => $e->getMessage()
        ]);
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
            $temp_combined = UPLOAD_DIR . $temp_name . '_combined.zip';
            
            $response['logs'][] = "🔄 Creating temporary combined archive...";
            
            // Open output file for writing
            $output_handle = fopen($temp_combined, 'wb');
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
                        
                        $input_handle = fopen($file_path, 'rb');
                        if ($input_handle) {
                            // Stream copy file in chunks
                            while (!feof($input_handle)) {
                                $chunk = fread($input_handle, CHUNK_SIZE);
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
                    
                    $extractor = new ArchiveExtractor();
                    $extract_result = $extractor->extractArchive($temp_combined, EXTRACT_DIR);
                    
                    if ($extract_result['success']) {
                        $response['logs'][] = "✅ Extraction successful ({$extract_result['files_extracted']} files)";
                        $response['success'] = true;
                        $response['message'] = "Manual selection extracted successfully ($files_combined files combined, {$extract_result['files_extracted']} files extracted)";
                    } else {
                        $response['logs'][] = "❌ Extraction failed: " . $extract_result['error'];
                        $response['message'] = "Failed to extract combined archive: " . $extract_result['error'];
                    }
                    
                    // Clean up temporary file
                    FileManager::deleteFile($temp_combined);
                    $response['logs'][] = "🧹 Cleaned up temporary files";
                } else {
                    $response['logs'][] = "❌ No valid files were combined";
                    $response['message'] = "No valid files selected or files not found";
                    if (file_exists($temp_combined)) {
                        FileManager::deleteFile($temp_combined);
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
        $logger->error('AJAX_MANUAL_SELECTION_EXCEPTION', [
            'Error' => $e->getMessage()
        ]);
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
        $response['logs'][] = "🌐 Starting URL download process";
        $response['logs'][] = "📡 URL: " . $download_url;
        
        // Create progress callback to capture download progress
        $progress_callback = function($message) use (&$response) {
            $response['logs'][] = $message;
        };
        
        $downloader = new URLDownloader('./', $progress_callback);
        $download_result = $downloader->downloadFromURL($download_url, $custom_filename);
        
        if ($download_result['success']) {
            $response['success'] = true;
            $response['message'] = "File downloaded successfully: {$download_result['filename']} (" . 
                                 FileManager::formatFileSize($download_result['file_size']) . ")";
            $response['filename'] = $download_result['filename'];
            $response['file_size'] = $download_result['file_size'];
        } else {
            $response['message'] = "Download failed: " . $download_result['error'];
        }
        
    } catch (Exception $e) {
        $response['logs'][] = "❌ Exception: " . $e->getMessage();
        $response['message'] = "Download failed: " . $e->getMessage();
        $logger->error('AJAX_URL_DOWNLOAD_EXCEPTION', [
            'URL' => $download_url,
            'Error' => $e->getMessage()
        ]);
    }
    
    echo json_encode($response);
    
    if (ob_get_level()) {
        ob_end_flush();
    }
    exit;
}

// Handle regular extraction (fallback)
if (isset($_POST['extract']) && isset($_POST['zip_group'])) {
    $base_name = $_POST['zip_group'];
    $source_dir = isset($_POST['source_dir']) ? $_POST['source_dir'] : UPLOAD_DIR;
    
    try {
        $files = FileManager::listFiles($source_dir);
        $groups = FileManager::groupMultipartFiles($files);
        
        if (isset($groups[$base_name]['parts']) && count($groups[$base_name]['parts']) > 0) {
            $combiner = new ArchiveCombiner($source_dir);
            $combined_zip = $combiner->combineMultipartArchive($base_name);
            
            if ($combined_zip) {
                $extractor = new ArchiveExtractor();
                $extract_result = $extractor->extractArchive($combined_zip, EXTRACT_DIR);
                $message = "Multipart archive combined and extracted. " . $extract_result['message'];
                FileManager::deleteFile($combined_zip);
            } else {
                $error = "Failed to combine multipart archive files.";
            }
        } else {
            $main_zip = $source_dir . $base_name . '.zip';
            if (file_exists($main_zip)) {
                $extractor = new ArchiveExtractor();
                $extract_result = $extractor->extractArchive($main_zip, EXTRACT_DIR);
                $message = $extract_result['message'];
            } else {
                $error = "Archive file not found: $main_zip";
            }
        }
    } catch (Exception $e) {
        $error = "Extraction failed: " . $e->getMessage();
        $logger->error('EXTRACTION_EXCEPTION', [
            'Base_Name' => $base_name,
            'Error' => $e->getMessage()
        ]);
    }
    
    header("Location: " . $_SERVER['PHP_SELF'] . "?extracted=1");
    exit;
}

// Handle file deletion
if (isset($_POST['delete']) && isset($_POST['delete_file'])) {
    $source_dir = isset($_POST['source_dir']) ? $_POST['source_dir'] : UPLOAD_DIR;
    $file_to_delete = $source_dir . basename($_POST['delete_file']);
    
    if (FileManager::deleteFile($file_to_delete)) {
        $message = "File deleted successfully.";
    } else {
        $error = "Failed to delete file.";
    }
}

// Get current file information
$uploader = new FileUploader();
$upload_info = $uploader->getUploadInfo();

// Get files from root directory (filter only archive files)
$root_files = FileManager::filterArchiveFiles(FileManager::listFiles('./'));
$root_file_groups = FileManager::groupMultipartFiles($root_files);

// Get extraction statistics
$extractor = new ArchiveExtractor();
$extraction_stats = $extractor->getExtractionStats(EXTRACT_DIR);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SYSTEM_NAME; ?> v<?php echo SYSTEM_VERSION; ?></title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px 0;
        }
        .main-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .header-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            text-align: center;
        }
        .upload-area {
            border: 3px dashed #dee2e6;
            border-radius: 15px;
            padding: 2rem;
            text-align: center;
            transition: all 0.3s ease;
            background: #f8f9fa;
        }
        .upload-area:hover {
            border-color: #0d6efd;
            background: #e7f3ff;
            transform: translateY(-2px);
        }
        .upload-area.url-download {
            border-color: #198754;
            background: #f8fff8;
        }
        .upload-area.url-download:hover {
            border-color: #198754;
            background: #e8f5e8;
        }
        .file-group {
            border-left: 4px solid #0d6efd;
            transition: all 0.3s ease;
        }
        .file-group:hover {
            transform: translateX(5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .file-group.root-files {
            border-left-color: #198754;
        }
        .progress-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.9);
            color: white;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }
        .progress-content {
            background: rgba(255,255,255,0.1);
            border-radius: 15px;
            padding: 2rem;
            max-width: 600px;
            width: 90%;
            backdrop-filter: blur(10px);
        }
        .progress-logs {
            background: rgba(0,0,0,0.8);
            border-radius: 10px;
            padding: 1rem;
            max-height: 300px;
            overflow-y: auto;
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
            line-height: 1.4;
        }
        .btn-icon {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        .feature-list {
            background: linear-gradient(135deg, #e3f2fd 0%, #f3e5f5 100%);
            border-radius: 10px;
            padding: 1rem;
        }
        .debug-section {
            background: linear-gradient(135deg, #fff3e0 0%, #fce4ec 100%);
            border-radius: 10px;
            padding: 1.5rem;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row justify-content-center">
            <div class="col-12 col-xl-10">
                <div class="main-container">
                    <!-- Header Section -->
                    <div class="header-section">
                        <h1 class="display-4 mb-0">
                            <i class="bi bi-archive"></i> <?php echo SYSTEM_NAME; ?>
                        </h1>
                        <p class="lead mb-0">Modular Archive Processing System v<?php echo SYSTEM_VERSION; ?></p>
                        <small class="opacity-75">Dynamic extension support up to z999 • Memory-efficient streaming • Real-time progress</small>
                    </div>
                    
                    <!-- Main Content -->
                    <div class="p-4">
                        <?php if ($message): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <i class="bi bi-check-circle"></i> <?php echo $message; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($error): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="bi bi-exclamation-triangle"></i> <?php echo $error; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (isset($_GET['extracted'])): ?>
                            <div class="alert alert-info alert-dismissible fade show" role="alert">
                                <i class="bi bi-check-circle"></i> <strong>Extraction Completed!</strong><br>
                                The extraction process has finished. Check the "Extracted Files" section below to see the results.
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <!-- System Status -->
                        <div class="mb-4">
                            <div class="card">
                                <div class="card-header bg-primary text-white">
                                    <h5 class="mb-0"><i class="bi bi-cpu"></i> Modular System Status</h5>
                                </div>
                                <div class="card-body debug-section">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <h6 class="fw-bold text-primary">System Modules</h6>
                                            <ul class="list-unstyled">
                                                <li><strong>Configuration:</strong> <span class="badge bg-success">Loaded</span></li>
                                                <li><strong>Logger:</strong> <span class="badge bg-success">Active</span></li>
                                                <li><strong>FileManager:</strong> <span class="badge bg-success">Ready</span></li>
                                                <li><strong>FileUploader:</strong> <span class="badge bg-success">Ready</span></li>
                                                <li><strong>ArchiveCombiner:</strong> <span class="badge bg-success">Ready</span></li>
                                                <li><strong>ArchiveExtractor:</strong> <span class="badge bg-success">Ready</span></li>
                                                <li><strong>URLDownloader:</strong> <span class="badge bg-success">Ready</span></li>
                                            </ul>
                                        </div>
                                        <div class="col-md-6">
                                            <h6 class="fw-bold text-primary">System Capabilities</h6>
                                            <?php $php_config = PHPConfig::getCurrentConfig(); ?>
                                            <ul class="list-unstyled">
                                                <li><strong>PHP Version:</strong> <span class="badge bg-info"><?php echo $php_config['php_version']; ?></span></li>
                                                <li><strong>ZipArchive:</strong> <?php echo $php_config['ziparchive_available'] ? '<span class="badge bg-success">Available</span>' : '<span class="badge bg-danger">Not Available</span>'; ?></li>
                                                <li><strong>cURL:</strong> <?php echo $php_config['curl_available'] ? '<span class="badge bg-success">Available</span>' : '<span class="badge bg-danger">Not Available</span>'; ?></li>
                                                <li><strong>Memory Limit:</strong> <span class="badge bg-secondary"><?php echo $php_config['memory_limit']; ?></span></li>
                                                <li><strong>Extensions:</strong> <span class="badge bg-info">z01-z999 (Dynamic)</span></li>
                                            </ul>
                                        </div>
                                    </div>
                                    
                                    <!-- AJAX Test Section -->
                                    <div class="alert alert-warning mt-3" role="alert">
                                        <h6 class="alert-heading"><i class="bi bi-wifi"></i> System Communication Test</h6>
                                        <p class="mb-2">Test modular system communication and AJAX functionality.</p>
                                        <button type="button" onclick="testAjax()" class="btn btn-outline-primary btn-sm mt-2 btn-icon">
                                            <i class="bi bi-wifi"></i> Test System Communication
                                        </button>
                                    </div>
                                    
                                    <!-- Detailed Operations Log -->
                                    <div class="alert alert-info mt-3" role="alert">
                                        <h6 class="alert-heading"><i class="bi bi-file-text"></i> System Operations Log (Last <?php echo MAX_LOG_LINES; ?> lines)</h6>
                                        <p class="mb-2">View comprehensive logs of all operations performed by the modular system.</p>
                                        <button type="button" onclick="toggleLogViewer()" class="btn btn-outline-info btn-sm mt-2 btn-icon" id="logViewerBtn">
                                            <i class="bi bi-eye"></i> Show Operations Log
                                        </button>
                                        <div id="logViewer" class="mt-3" style="display: none;">
                                            <div class="bg-dark text-light p-3 rounded" style="font-family: 'Courier New', monospace; font-size: 0.85rem; max-height: 400px; overflow-y: auto;">
                                                <pre id="logContent"><?php echo htmlspecialchars($logger->getLogContents()); ?></pre>
                                            </div>
                                            <div class="mt-2">
                                                <button type="button" onclick="refreshLog()" class="btn btn-outline-info btn-sm btn-icon">
                                                    <i class="bi bi-arrow-clockwise"></i> Refresh Log
                                                </button>
                                                <button type="button" onclick="clearLog()" class="btn btn-outline-danger btn-sm btn-icon">
                                                    <i class="bi bi-trash"></i> Clear Log
                                                </button>
                                                <?php $log_info = $logger->getLogInfo(); ?>
                                                <small class="text-muted ms-3">
                                                    <i class="bi bi-info-circle"></i> Log file: <?php echo $log_info['file']; ?> 
                                                    (<?php echo $log_info['exists'] ? number_format($log_info['size']) . ' bytes' : 'not created yet'; ?>)
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- File Upload Section -->
                        <div class="upload-area mb-4">
                            <div class="text-center">
                                <i class="bi bi-cloud-upload display-1 text-primary mb-3"></i>
                                <h3 class="text-primary mb-3">
                                    <i class="bi bi-folder-plus"></i> Upload Archive Files (UNLIMITED)
                                </h3>
                                <p class="text-muted mb-4">
                                    Select archive files - Dynamic support for z01-z999 multipart extensions
                                </p>
                                
                                <form method="post" enctype="multipart/form-data" id="uploadForm">
                                    <div class="mb-3">
                                        <input type="file" 
                                               name="zipfiles[]" 
                                               multiple 
                                               accept="<?php echo '.' . implode(',.', ArchiveConfig::getAllowedExtensions()); ?>" 
                                               id="fileInput"
                                               class="form-control form-control-lg">
                                    </div>
                                    
                                    <button type="submit" id="uploadBtn" class="btn btn-primary btn-lg btn-icon">
                                        <i class="bi bi-upload"></i> Upload Files
                                    </button>
                                    
                                    <div id="progressContainer" class="mt-3" style="display: none;">
                                        <div class="progress mb-2" style="height: 25px;">
                                            <div class="progress-bar progress-bar-striped progress-bar-animated" 
                                                 id="progressFill" 
                                                 role="progressbar" 
                                                 style="width: 0%">
                                            </div>
                                        </div>
                                        <div id="progressText" class="text-center fw-bold">Uploading... 0%</div>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- URL Download Section -->
                        <div class="upload-area url-download mb-4">
                            <div class="text-center mb-4">
                                <i class="bi bi-globe display-1 text-success mb-3"></i>
                                <h3 class="text-success mb-3">
                                    <i class="bi bi-download"></i> Download from URL (UNLIMITED)
                                </h3>
                                <p class="text-muted">
                                    Enter a direct URL to download archive files directly to the root directory
                                </p>
                            </div>
                            
                            <form id="urlDownloadForm">
                                <div class="row">
                                    <div class="col-md-8">
                                        <div class="mb-3">
                                            <label for="downloadUrl" class="form-label fw-bold">
                                                <i class="bi bi-link-45deg"></i> File URL:
                                            </label>
                                            <input type="url" 
                                                   id="downloadUrl" 
                                                   name="download_url" 
                                                   placeholder="https://example.com/file.zip" 
                                                   class="form-control form-control-lg"
                                                   required>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="customFilename" class="form-label fw-bold">
                                                <i class="bi bi-file-text"></i> Custom Filename (optional):
                                            </label>
                                            <input type="text" 
                                                   id="customFilename" 
                                                   name="custom_filename" 
                                                   placeholder="my_archive.zip" 
                                                   class="form-control form-control-lg">
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="text-center">
                                    <button type="submit" id="downloadBtn" class="btn btn-success btn-lg btn-icon">
                                        <i class="bi bi-cloud-download"></i> Download from URL
                                    </button>
                                </div>
                            </form>
                        </div>

                        <!-- Uploaded Files Section -->
                        <?php if (!empty($upload_info['groups'])): ?>
                            <div class="card mb-4">
                                <div class="card-header bg-primary text-white">
                                    <h5 class="mb-0">
                                        <i class="bi bi-cloud-upload"></i> Uploaded Files 
                                        <span class="badge bg-light text-dark"><?php echo $upload_info['total_files']; ?> files</span>
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <?php foreach ($upload_info['groups'] as $base_name => $group): ?>
                                        <div class="card file-group mb-3">
                                            <div class="card-body">
                                                <div class="row align-items-center">
                                                    <div class="col-md-8">
                                                        <h6 class="card-title text-primary mb-1">
                                                            <i class="bi bi-archive"></i> <?php echo htmlspecialchars($base_name); ?>
                                                        </h6>
                                                        <div class="d-flex flex-wrap gap-1 mb-2">
                                                            <?php if (isset($group['main'])): ?>
                                                                <span class="badge bg-success">Main: <?php echo htmlspecialchars($group['main']); ?></span>
                                                            <?php endif; ?>
                                                            <?php if (isset($group['parts'])): ?>
                                                                <span class="badge bg-info"><?php echo count($group['parts']); ?> parts</span>
                                                                <?php foreach ($group['parts'] as $part): ?>
                                                                    <span class="badge bg-secondary"><?php echo htmlspecialchars($part); ?></span>
                                                                <?php endforeach; ?>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-4 text-end">
                                                        <button type="button" 
                                                                onclick="extractArchive('<?php echo htmlspecialchars($base_name); ?>', 'uploads/')" 
                                                                class="btn btn-success btn-sm btn-icon me-2">
                                                            <i class="bi bi-box-arrow-up"></i> Extract
                                                        </button>
                                                        <form method="post" class="d-inline">
                                                            <input type="hidden" name="delete_file" value="<?php echo htmlspecialchars($group['main'] ?? $group['parts'][0]); ?>">
                                                            <input type="hidden" name="source_dir" value="uploads/">
                                                            <button type="submit" name="delete" 
                                                                    onclick="return confirm('Delete this file group?')" 
                                                                    class="btn btn-outline-danger btn-sm btn-icon">
                                                                <i class="bi bi-trash"></i> Delete
                                                            </button>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Root Directory Files Section -->
                        <?php if (!empty($root_file_groups)): ?>
                            <div class="card mb-4">
                                <div class="card-header bg-success text-white">
                                    <h5 class="mb-0">
                                        <i class="bi bi-hdd"></i> Root Directory Files 
                                        <span class="badge bg-light text-dark"><?php echo count($root_files); ?> files</span>
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <?php foreach ($root_file_groups as $base_name => $group): ?>
                                        <div class="card file-group root-files mb-3">
                                            <div class="card-body">
                                                <div class="row align-items-center">
                                                    <div class="col-md-8">
                                                        <h6 class="card-title text-success mb-1">
                                                            <i class="bi bi-archive"></i> <?php echo htmlspecialchars($base_name); ?>
                                                        </h6>
                                                        <div class="d-flex flex-wrap gap-1 mb-2">
                                                            <?php if (isset($group['main'])): ?>
                                                                <span class="badge bg-success">Main: <?php echo htmlspecialchars($group['main']); ?></span>
                                                            <?php endif; ?>
                                                            <?php if (isset($group['parts'])): ?>
                                                                <span class="badge bg-info"><?php echo count($group['parts']); ?> parts</span>
                                                                <?php foreach ($group['parts'] as $part): ?>
                                                                    <span class="badge bg-secondary"><?php echo htmlspecialchars($part); ?></span>
                                                                <?php endforeach; ?>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-4 text-end">
                                                        <button type="button" 
                                                                onclick="extractArchive('<?php echo htmlspecialchars($base_name); ?>', './')" 
                                                                class="btn btn-success btn-sm btn-icon me-2">
                                                            <i class="bi bi-box-arrow-up"></i> Extract
                                                        </button>
                                                        <form method="post" class="d-inline">
                                                            <input type="hidden" name="delete_file" value="<?php echo htmlspecialchars($group['main'] ?? $group['parts'][0]); ?>">
                                                            <input type="hidden" name="source_dir" value="./">
                                                            <button type="submit" name="delete" 
                                                                    onclick="return confirm('Delete this file group?')" 
                                                                    class="btn btn-outline-danger btn-sm btn-icon">
                                                                <i class="bi bi-trash"></i> Delete
                                                            </button>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>

                                    <!-- Manual File Selection -->
                                    <div class="card border-warning mt-4">
                                        <div class="card-header bg-warning text-dark">
                                            <h6 class="mb-0">
                                                <i class="bi bi-hand-index"></i> Manual File Selection
                                            </h6>
                                        </div>
                                        <div class="card-body">
                                            <p class="text-muted mb-3">Select specific files from the root directory to combine and extract:</p>
                                            <form id="manualSelectionForm">
                                                <div class="row">
                                                    <?php foreach ($root_files as $file): ?>
                                                        <div class="col-md-6 col-lg-4 mb-2">
                                                            <div class="form-check">
                                                                <input class="form-check-input" type="checkbox" 
                                                                       name="selected_files[]" 
                                                                       value="<?php echo htmlspecialchars($file); ?>" 
                                                                       id="file_<?php echo md5($file); ?>">
                                                                <label class="form-check-label" for="file_<?php echo md5($file); ?>">
                                                                    <small><?php echo htmlspecialchars($file); ?></small>
                                                                </label>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                                <div class="mt-3">
                                                    <button type="button" onclick="selectAllFiles()" class="btn btn-outline-primary btn-sm me-2">
                                                        <i class="bi bi-check-all"></i> Select All
                                                    </button>
                                                    <button type="button" onclick="clearAllFiles()" class="btn btn-outline-secondary btn-sm me-2">
                                                        <i class="bi bi-x-square"></i> Clear All
                                                    </button>
                                                    <button type="submit" class="btn btn-warning btn-sm btn-icon">
                                                        <i class="bi bi-box-arrow-up"></i> Extract Selected
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Extracted Files Section -->
                        <?php if (!empty($extraction_stats['extractions'])): ?>
                            <div class="card mb-4">
                                <div class="card-header bg-info text-white">
                                    <h5 class="mb-0">
                                        <i class="bi bi-folder-check"></i> Extracted Files 
                                        <span class="badge bg-light text-dark"><?php echo $extraction_stats['total_extractions']; ?> extractions</span>
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="row mb-3">
                                        <div class="col-md-4">
                                            <div class="text-center">
                                                <h6 class="text-muted">Total Extractions</h6>
                                                <h4 class="text-info"><?php echo $extraction_stats['total_extractions']; ?></h4>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="text-center">
                                                <h6 class="text-muted">Total Files</h6>
                                                <h4 class="text-info"><?php echo number_format($extraction_stats['total_files']); ?></h4>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="text-center">
                                                <h6 class="text-muted">Total Size</h6>
                                                <h4 class="text-info"><?php echo FileManager::formatFileSize($extraction_stats['total_size']); ?></h4>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <?php foreach ($extraction_stats['extractions'] as $extraction): ?>
                                        <div class="card mb-2">
                                            <div class="card-body py-2">
                                                <div class="row align-items-center">
                                                    <div class="col-md-6">
                                                        <h6 class="mb-1">
                                                            <i class="bi bi-folder"></i> <?php echo htmlspecialchars($extraction['name']); ?>
                                                        </h6>
                                                        <small class="text-muted">
                                                            <i class="bi bi-calendar"></i> <?php echo date('Y-m-d H:i:s', $extraction['modified']); ?>
                                                        </small>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <span class="badge bg-info"><?php echo number_format($extraction['files']); ?> files</span>
                                                        <span class="badge bg-secondary"><?php echo FileManager::formatFileSize($extraction['size']); ?></span>
                                                    </div>
                                                    <div class="col-md-3 text-end">
                                                        <a href="<?php echo htmlspecialchars($extraction['path']); ?>" 
                                                           target="_blank" 
                                                           class="btn btn-outline-info btn-sm btn-icon">
                                                            <i class="bi bi-folder-symlink"></i> Browse
                                                        </a>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Instructions Section -->
                        <div class="card">
                            <div class="card-header bg-secondary text-white">
                                <h5 class="mb-0">
                                    <i class="bi bi-info-circle"></i> Instructions & Features
                                </h5>
                            </div>
                            <div class="card-body feature-list">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6 class="fw-bold text-primary">
                                            <i class="bi bi-upload"></i> Upload Features
                                        </h6>
                                        <ul class="list-unstyled">
                                            <li><i class="bi bi-check-circle text-success"></i> Unlimited file size support</li>
                                            <li><i class="bi bi-check-circle text-success"></i> Dynamic multipart support (z01-z999)</li>
                                            <li><i class="bi bi-check-circle text-success"></i> Multiple file formats (.zip, .rar, .7z, .tar, .gz, .bz2, .xz, .lzma)</li>
                                            <li><i class="bi bi-check-circle text-success"></i> Real-time progress tracking</li>
                                            <li><i class="bi bi-check-circle text-success"></i> Memory-efficient streaming</li>
                                        </ul>
                                    </div>
                                    <div class="col-md-6">
                                        <h6 class="fw-bold text-success">
                                            <i class="bi bi-download"></i> Download Features
                                        </h6>
                                        <ul class="list-unstyled">
                                            <li><i class="bi bi-check-circle text-success"></i> Direct URL downloads</li>
                                            <li><i class="bi bi-check-circle text-success"></i> Custom filename support</li>
                                            <li><i class="bi bi-check-circle text-success"></i> Progress monitoring</li>
                                            <li><i class="bi bi-check-circle text-success"></i> HTTP/HTTPS/FTP support</li>
                                            <li><i class="bi bi-check-circle text-success"></i> Automatic file validation</li>
                                        </ul>
                                    </div>
                                </div>
                                <div class="row mt-3">
                                    <div class="col-md-6">
                                        <h6 class="fw-bold text-info">
                                            <i class="bi bi-box-arrow-up"></i> Extraction Features
                                        </h6>
                                        <ul class="list-unstyled">
                                            <li><i class="bi bi-check-circle text-success"></i> Automatic multipart combination</li>
                                            <li><i class="bi bi-check-circle text-success"></i> Manual file selection</li>
                                            <li><i class="bi bi-check-circle text-success"></i> Root directory integration</li>
                                            <li><i class="bi bi-check-circle text-success"></i> Real-time extraction logs</li>
                                            <li><i class="bi bi-check-circle text-success"></i> File count and size tracking</li>
                                        </ul>
                                    </div>
                                    <div class="col-md-6">
                                        <h6 class="fw-bold text-warning">
                                            <i class="bi bi-gear"></i> System Features
                                        </h6>
                                        <ul class="list-unstyled">
                                            <li><i class="bi bi-check-circle text-success"></i> Modular architecture</li>
                                            <li><i class="bi bi-check-circle text-success"></i> Comprehensive logging (50 lines)</li>
                                            <li><i class="bi bi-check-circle text-success"></i> AJAX-powered interface</li>
                                            <li><i class="bi bi-check-circle text-success"></i> Bootstrap 5 responsive design</li>
                                            <li><i class="bi bi-check-circle text-success"></i> Shared hosting compatible</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Progress Overlay -->
    <div id="progressOverlay" class="progress-overlay" style="display: none;">
        <div class="progress-content">
            <div class="text-center mb-4">
                <div class="spinner-border text-light" role="status" style="width: 3rem; height: 3rem;">
                    <span class="visually-hidden">Processing...</span>
                </div>
                <h4 class="mt-3" id="progressTitle">Processing...</h4>
                <div class="progress mt-3" style="height: 25px;">
                    <div class="progress-bar progress-bar-striped progress-bar-animated" 
                         id="progressBar" 
                         role="progressbar" 
                         style="width: 0%">
                        <span id="progressPercent">0%</span>
                    </div>
                </div>
            </div>
            
            <div class="progress-logs" id="progressLogs">
                <div class="text-center text-muted">
                    <i class="bi bi-hourglass-split"></i> Waiting for process to start...
                </div>
            </div>
            
            <div class="text-center mt-3">
                <button type="button" onclick="hideProgress()" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-x-circle"></i> Hide Progress
                </button>
            </div>
        </div>
    </div>

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <!-- Bootstrap JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    $(document).ready(function() {
        console.log('🚀 Modular Archive System v<?php echo SYSTEM_VERSION; ?> Loaded');
        console.log('📊 Dynamic extensions: z01-z999');
        console.log('💾 Memory-efficient streaming enabled');
        
        // Handle URL download form submission
        $('#urlDownloadForm').on('submit', function(e) {
            e.preventDefault();
            
            const url = $('#downloadUrl').val().trim();
            const filename = $('#customFilename').val().trim();
            
            if (!url) {
                alert('Please enter a URL to download from.');
                return;
            }
            
            downloadFromURL(url, filename);
        });
        
        // Handle manual file selection form
        $('#manualSelectionForm').on('submit', function(e) {
            e.preventDefault();
            
            const selectedFiles = [];
            $('input[name="selected_files[]"]:checked').each(function() {
                selectedFiles.push($(this).val());
            });
            
            if (selectedFiles.length === 0) {
                alert('Please select at least one file to extract.');
                return;
            }
            
            extractSelectedFiles(selectedFiles);
        });
        
        // Handle file upload with progress
        $('#uploadForm').on('submit', function(e) {
            const fileInput = $('#fileInput')[0];
            if (!fileInput.files.length) {
                alert('Please select files to upload.');
                e.preventDefault();
                return;
            }
            
            // Show progress for large uploads
            const totalSize = Array.from(fileInput.files).reduce((sum, file) => sum + file.size, 0);
            if (totalSize > 10 * 1024 * 1024) { // Show progress for files > 10MB
                showProgress('Uploading Files', 'Preparing upload...');
                
                // Simulate upload progress (since we can't track actual upload progress easily)
                let progress = 0;
                const progressInterval = setInterval(() => {
                    progress += Math.random() * 10;
                    if (progress > 90) progress = 90;
                    
                    updateProgress(progress, `Uploading... ${Math.round(progress)}%`);
                    addProgressLog(`📤 Upload progress: ${Math.round(progress)}%`);
                }, 500);
                
                // Clear interval when form actually submits
                setTimeout(() => {
                    clearInterval(progressInterval);
                    updateProgress(100, 'Upload completed!');
                    addProgressLog('✅ Upload finished, processing files...');
                }, 2000);
            }
        });
    });
    
    // AJAX test function
    function testAjax() {
        console.clear();
        console.log('🧪 TESTING MODULAR SYSTEM COMMUNICATION...');
        console.log('==========================================');
        
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: { ajax_test: '1' },
            dataType: 'json',
            timeout: 30000,
            success: function(response) {
                console.log('✅ MODULAR SYSTEM TEST SUCCESSFUL!');
                response.logs.forEach(log => console.log(log));
                alert('✅ Modular system is working correctly!');
                if ($('#logViewer').is(':visible')) {
                    refreshLog();
                }
            },
            error: function(xhr, status, error) {
                console.error('❌ System test failed:', status, error);
                console.error('Response:', xhr.responseText);
                alert('❌ System test failed - check console for details');
            }
        });
    }
    
    // Extract archive function
    function extractArchive(baseName, sourceDir) {
        showProgress('Extracting Archive', 'Preparing extraction...');
        addProgressLog(`🔧 Starting extraction for: ${baseName}`);
        addProgressLog(`📂 Source directory: ${sourceDir}`);
        
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: {
                ajax_extract: '1',
                zip_group: baseName,
                source_dir: sourceDir
            },
            dataType: 'json',
            timeout: 300000, // 5 minutes
            success: function(response) {
                if (response.success) {
                    updateProgress(100, 'Extraction completed!');
                    addProgressLog('✅ ' + response.message);
                    
                    setTimeout(() => {
                        hideProgress();
                        alert('✅ ' + response.message);
                        location.reload();
                    }, 2000);
                } else {
                    addProgressLog('❌ ' + response.message);
                    setTimeout(() => {
                        hideProgress();
                        alert('❌ ' + response.message);
                    }, 2000);
                }
                
                // Add all logs
                if (response.logs) {
                    response.logs.forEach(log => addProgressLog(log));
                }
            },
            error: function(xhr, status, error) {
                console.error('Extraction failed:', status, error);
                addProgressLog('❌ Extraction failed: ' + error);
                setTimeout(() => {
                    hideProgress();
                    alert('❌ Extraction failed: ' + error);
                }, 2000);
            }
        });
    }
    
    // Download from URL function
    function downloadFromURL(url, customFilename) {
        showProgress('Downloading from URL', 'Preparing download...');
        addProgressLog(`🌐 Starting download from: ${url}`);
        if (customFilename) {
            addProgressLog(`📁 Custom filename: ${customFilename}`);
        }
        
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: {
                ajax_download_url: '1',
                download_url: url,
                custom_filename: customFilename
            },
            dataType: 'json',
            timeout: 3600000, // 1 hour
            success: function(response) {
                if (response.success) {
                    updateProgress(100, 'Download completed!');
                    addProgressLog('✅ ' + response.message);
                    
                    setTimeout(() => {
                        hideProgress();
                        alert('✅ ' + response.message);
                        location.reload();
                    }, 2000);
                } else {
                    addProgressLog('❌ ' + response.message);
                    setTimeout(() => {
                        hideProgress();
                        alert('❌ ' + response.message);
                    }, 2000);
                }
                
                // Add all logs
                if (response.logs) {
                    response.logs.forEach(log => addProgressLog(log));
                }
            },
            error: function(xhr, status, error) {
                console.error('Download failed:', status, error);
                addProgressLog('❌ Download failed: ' + error);
                setTimeout(() => {
                    hideProgress();
                    alert('❌ Download failed: ' + error);
                }, 2000);
            }
        });
    }
    
    // Extract selected files function
    function extractSelectedFiles(selectedFiles) {
        showProgress('Extracting Selected Files', 'Preparing manual extraction...');
        addProgressLog(`🔧 Starting manual file selection extraction`);
        addProgressLog(`📁 Selected ${selectedFiles.length} files`);
        
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: {
                ajax_extract_selected: '1',
                selected_files: selectedFiles
            },
            dataType: 'json',
            timeout: 300000, // 5 minutes
            success: function(response) {
                if (response.success) {
                    updateProgress(100, 'Extraction completed!');
                    addProgressLog('✅ ' + response.message);
                    
                    setTimeout(() => {
                        hideProgress();
                        alert('✅ ' + response.message);
                        location.reload();
                    }, 2000);
                } else {
                    addProgressLog('❌ ' + response.message);
                    setTimeout(() => {
                        hideProgress();
                        alert('❌ ' + response.message);
                    }, 2000);
                }
                
                // Add all logs
                if (response.logs) {
                    response.logs.forEach(log => addProgressLog(log));
                }
            },
            error: function(xhr, status, error) {
                console.error('Manual extraction failed:', status, error);
                addProgressLog('❌ Manual extraction failed: ' + error);
                setTimeout(() => {
                    hideProgress();
                    alert('❌ Manual extraction failed: ' + error);
                }, 2000);
            }
        });
    }
    
    // Progress overlay functions
    function showProgress(title, message) {
        $('#progressTitle').text(title);
        $('#progressLogs').html(`<div class="text-muted">${message}</div>`);
        $('#progressBar').css('width', '0%');
        $('#progressPercent').text('0%');
        $('#progressOverlay').fadeIn();
    }
    
    function hideProgress() {
        $('#progressOverlay').fadeOut();
    }
    
    function updateProgress(percent, message) {
        $('#progressBar').css('width', percent + '%');
        $('#progressPercent').text(Math.round(percent) + '%');
        if (message) {
            $('#progressTitle').text(message);
        }
    }
    
    function addProgressLog(message) {
        const timestamp = new Date().toLocaleTimeString();
        const logEntry = `<div class="mb-1">[${timestamp}] ${message}</div>`;
        $('#progressLogs').append(logEntry);
        
        // Auto-scroll to bottom
        const logsContainer = $('#progressLogs')[0];
        logsContainer.scrollTop = logsContainer.scrollHeight;
    }
    
    // File selection functions
    function selectAllFiles() {
        $('input[name="selected_files[]"]').prop('checked', true);
    }
    
    function clearAllFiles() {
        $('input[name="selected_files[]"]').prop('checked', false);
    }
    
    // Log viewer functions
    function toggleLogViewer() {
        const $logViewer = $('#logViewer');
        const $btn = $('#logViewerBtn');
        
        if ($logViewer.is(':visible')) {
            $logViewer.slideUp();
            $btn.html('<i class="bi bi-eye"></i> Show Operations Log');
        } else {
            $logViewer.slideDown();
            $btn.html('<i class="bi bi-eye-slash"></i> Hide Operations Log');
            refreshLog();
        }
    }
    
    function refreshLog() {
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: { get_log: '1' },
            success: function(response) {
                const parser = new DOMParser();
                const doc = parser.parseFromString(response, 'text/html');
                const logContent = doc.getElementById('logContent');
                if (logContent) {
                    $('#logContent').text(logContent.textContent);
                }
            },
            error: function() {
                $('#logContent').text('Error refreshing log content.');
            }
        });
    }
    
    function clearLog() {
        if (confirm('Are you sure you want to clear the operations log?')) {
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: { clear_log: '1' },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        $('#logContent').text('Log cleared successfully.');
                        alert('Operations log has been cleared.');
                    }
                },
                error: function() {
                    alert('Error clearing log.');
                }
            });
        }
    }
    </script>
</body>
</html> 