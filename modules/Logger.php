<?php
/**
 * Logger Module
 * Comprehensive logging system for the Archive Uploader & Extractor
 */

require_once __DIR__ . '/../config.php';

class Logger {
    
    private static $instance = null;
    private $log_file;
    private $max_lines;
    
    /**
     * Singleton pattern
     */
    private function __construct() {
        $this->log_file = LOG_FILE;
        $this->max_lines = MAX_LOG_LINES;
    }
    
    /**
     * Get Logger instance
     * @return Logger
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Write detailed log entry
     * @param string $operation Operation name
     * @param array $details Operation details
     * @param string $status Log level (INFO, SUCCESS, ERROR, WARNING)
     * @param string $handler Handler name (optional)
     */
    public function log($operation, $details = [], $status = 'INFO', $handler = 'MAIN') {
        try {
            $log_entry = $this->buildLogEntry($operation, $details, $status, $handler);
            $this->writeToFile($log_entry);
            $this->logToErrorLog($operation, $details, $status);
        } catch (Exception $e) {
            error_log("Logger error: " . $e->getMessage());
        }
    }
    
    /**
     * Build formatted log entry
     * @param string $operation
     * @param array $details
     * @param string $status
     * @param string $handler
     * @return string
     */
    private function buildLogEntry($operation, $details, $status, $handler) {
        $timestamp = date('Y-m-d H:i:s');
        $user_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        $request_method = $_SERVER['REQUEST_METHOD'] ?? 'unknown';
        $request_uri = $_SERVER['REQUEST_URI'] ?? 'unknown';
        
        $log_entry = "[$timestamp] [$status] [$operation] [$handler]\n";
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
        
        // Add system information
        $memory_used = round(memory_get_usage(true) / 1024 / 1024, 2);
        $memory_peak = round(memory_get_peak_usage(true) / 1024 / 1024, 2);
        $log_entry .= "  Memory: {$memory_used}MB (Peak: {$memory_peak}MB)\n";
        $log_entry .= "  PHP Version: " . PHP_VERSION . "\n";
        $log_entry .= "  " . str_repeat('-', 80) . "\n";
        
        return $log_entry;
    }
    
    /**
     * Write log entry to file with rotation
     * @param string $log_entry
     */
    private function writeToFile($log_entry) {
        $existing_lines = [];
        
        if (file_exists($this->log_file)) {
            $existing_content = file_get_contents($this->log_file);
            if (!empty($existing_content)) {
                $existing_lines = explode("\n", $existing_content);
            }
        }
        
        // Add new entry to beginning
        $new_lines = explode("\n", $log_entry);
        $existing_lines = array_merge($new_lines, $existing_lines);
        
        // Limit to max lines
        if (count($existing_lines) > $this->max_lines) {
            $existing_lines = array_slice($existing_lines, 0, $this->max_lines);
        }
        
        // Write back to file
        file_put_contents($this->log_file, implode("\n", $existing_lines));
    }
    
    /**
     * Also log to PHP error log for debugging
     * @param string $operation
     * @param array $details
     * @param string $status
     */
    private function logToErrorLog($operation, $details, $status) {
        $message = "UPLOADER_LOG [$status] $operation";
        if (!empty($details)) {
            $message .= ": " . json_encode($details);
        }
        error_log($message);
    }
    
    /**
     * Get log contents
     * @return string
     */
    public function getLogContents() {
        if (file_exists($this->log_file)) {
            return file_get_contents($this->log_file);
        }
        return "No operations logged yet.";
    }
    
    /**
     * Clear log file
     */
    public function clearLog() {
        if (file_exists($this->log_file)) {
            unlink($this->log_file);
        }
        
        // Log the clear operation
        $this->log('LOG_SYSTEM_RESTART', [
            'Action' => 'Log system restarted after manual clear'
        ]);
    }
    
    /**
     * Get log file information
     * @return array
     */
    public function getLogInfo() {
        return [
            'file' => $this->log_file,
            'exists' => file_exists($this->log_file),
            'size' => file_exists($this->log_file) ? filesize($this->log_file) : 0,
            'max_lines' => $this->max_lines
        ];
    }
    
    /**
     * Convenience methods for different log levels
     */
    public function info($operation, $details = [], $handler = 'MAIN') {
        $this->log($operation, $details, 'INFO', $handler);
    }
    
    public function success($operation, $details = [], $handler = 'MAIN') {
        $this->log($operation, $details, 'SUCCESS', $handler);
    }
    
    public function error($operation, $details = [], $handler = 'MAIN') {
        $this->log($operation, $details, 'ERROR', $handler);
    }
    
    public function warning($operation, $details = [], $handler = 'MAIN') {
        $this->log($operation, $details, 'WARNING', $handler);
    }
}

// Global logging functions for convenience
function logInfo($operation, $details = [], $handler = 'MAIN') {
    Logger::getInstance()->info($operation, $details, $handler);
}

function logSuccess($operation, $details = [], $handler = 'MAIN') {
    Logger::getInstance()->success($operation, $details, $handler);
}

function logError($operation, $details = [], $handler = 'MAIN') {
    Logger::getInstance()->error($operation, $details, $handler);
}

function logWarning($operation, $details = [], $handler = 'MAIN') {
    Logger::getInstance()->warning($operation, $details, $handler);
}
?> 