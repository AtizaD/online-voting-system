<?php
class Logger {
    const DEBUG = 'DEBUG';
    const INFO = 'INFO';
    const WARNING = 'WARNING';
    const ERROR = 'ERROR';
    const CRITICAL = 'CRITICAL';
    
    private static $logLevels = [
        'DEBUG' => 0,
        'INFO' => 1,
        'WARNING' => 2,
        'ERROR' => 3,
        'CRITICAL' => 4
    ];
    
    private static $initialized = false;
    private static $originalErrorHandler = null;
    private static $originalExceptionHandler = null;
    
    /**
     * Initialize the Logger with automatic error capture
     */
    public static function init($logLevel = 'INFO', $logDir = null) {
        if (self::$initialized) {
            return;
        }
        
        // Set default log level if not defined
        if (!defined('LOG_LEVEL')) {
            define('LOG_LEVEL', $logLevel);
        }
        
        // Set default log max size if not defined
        if (!defined('LOG_MAX_SIZE')) {
            define('LOG_MAX_SIZE', 10 * 1024 * 1024); // 10MB
        }
        
        // Set log directory
        if ($logDir && !defined('LOG_DIR')) {
            define('LOG_DIR', rtrim($logDir, '/') . '/');
        } elseif (!defined('LOG_DIR')) {
            define('LOG_DIR', __DIR__ . '/../logs/');
        }
        
        // Create log directory if it doesn't exist
        if (!is_dir(LOG_DIR)) {
            mkdir(LOG_DIR, 0755, true);
        }
        
        // Set up automatic error and exception handlers
        self::setupErrorHandlers();
        
        self::$initialized = true;
    }
    
    /**
     * Set up automatic error and exception handlers
     */
    private static function setupErrorHandlers() {
        // Store original handlers in case we need to restore them
        self::$originalErrorHandler = set_error_handler([__CLASS__, 'handleError']);
        self::$originalExceptionHandler = set_exception_handler([__CLASS__, 'handleException']);
        
        // Set up fatal error handler using register_shutdown_function
        register_shutdown_function([__CLASS__, 'handleFatalError']);
    }
    
    /**
     * Automatic error handler for PHP errors
     */
    public static function handleError($errno, $errstr, $errfile, $errline) {
        // Don't log if error reporting is turned off for this error type
        if (!(error_reporting() & $errno)) {
            return false;
        }
        
        // Map PHP error types to log levels
        $level = self::ERROR;
        $errorType = 'PHP_ERROR';
        
        switch ($errno) {
            case E_ERROR:
            case E_CORE_ERROR:
            case E_COMPILE_ERROR:
            case E_PARSE:
                $level = self::CRITICAL;
                $errorType = 'FATAL_ERROR';
                break;
            case E_WARNING:
            case E_CORE_WARNING:
            case E_COMPILE_WARNING:
            case E_USER_WARNING:
                $level = self::WARNING;
                $errorType = 'WARNING';
                break;
            case E_NOTICE:
            case E_USER_NOTICE:
            case E_STRICT:
                $level = self::INFO;
                $errorType = 'NOTICE';
                break;
            case E_DEPRECATED:
            case E_USER_DEPRECATED:
                $level = self::DEBUG;
                $errorType = 'DEPRECATED';
                break;
        }
        
        $context = [
            'errno' => $errno,
            'error_type' => $errorType,
            'file' => $errfile,
            'line' => $errline,
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true)
        ];
        
        self::log($level, "[$errorType] $errstr", $context, 'error.log');
        
        // Call original error handler if it exists
        if (self::$originalErrorHandler && is_callable(self::$originalErrorHandler)) {
            return call_user_func(self::$originalErrorHandler, $errno, $errstr, $errfile, $errline);
        }
        
        // Don't execute PHP internal error handler
        return true;
    }
    
    /**
     * Automatic exception handler for uncaught exceptions
     */
    public static function handleException($exception) {
        $context = [
            'exception_class' => get_class($exception),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true)
        ];
        
        // Log as critical since it's an uncaught exception
        self::critical("Uncaught Exception: " . $exception->getMessage(), $context, 'error.log');
        
        // Also log to exception-specific log
        self::log(self::CRITICAL, "Uncaught Exception: " . $exception->getMessage(), $context, 'exceptions.log');
        
        // Call original exception handler if it exists
        if (self::$originalExceptionHandler && is_callable(self::$originalExceptionHandler)) {
            return call_user_func(self::$originalExceptionHandler, $exception);
        }
        
        // Display error message based on debug mode
        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            echo "<h1>Uncaught Exception</h1>";
            echo "<p><strong>Message:</strong> " . htmlspecialchars($exception->getMessage()) . "</p>";
            echo "<p><strong>File:</strong> " . htmlspecialchars($exception->getFile()) . "</p>";
            echo "<p><strong>Line:</strong> " . $exception->getLine() . "</p>";
            echo "<details><summary>Stack Trace</summary><pre>" . htmlspecialchars($exception->getTraceAsString()) . "</pre></details>";
        } else {
            echo "<h1>System Error</h1>";
            echo "<p>An error occurred. Please try again later.</p>";
        }
    }
    
    /**
     * Handle fatal errors that can't be caught by normal error handler
     */
    public static function handleFatalError() {
        $error = error_get_last();
        
        if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
            $context = [
                'errno' => $error['type'],
                'error_type' => 'FATAL_ERROR',
                'file' => $error['file'],
                'line' => $error['line'],
                'memory_usage' => memory_get_usage(true),
                'peak_memory' => memory_get_peak_usage(true)
            ];
            
            self::log(self::CRITICAL, "[FATAL_ERROR] " . $error['message'], $context, 'error.log');
        }
    }
    
    /**
     * Main logging method
     */
    public static function log($level, $message, $context = [], $logFile = 'audit.log') {
        // Initialize if not already done
        if (!self::$initialized) {
            self::init();
        }
        
        if (!defined('LOG_LEVEL') || !isset(self::$logLevels[$level]) || !isset(self::$logLevels[LOG_LEVEL])) {
            return false;
        }
        
        if (self::$logLevels[$level] < self::$logLevels[LOG_LEVEL]) {
            return false;
        }
        
        $fullPath = LOG_DIR . $logFile;
        
        $timestamp = date('Y-m-d H:i:s');
        $userInfo = '';
        
        if (isset($_SESSION['user'])) {
            $user = $_SESSION['user'];
            $userInfo = " [User: {$user['id']} - {$user['email']}]";
        }
        
        $ip = self::getClientIP();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $requestMethod = $_SERVER['REQUEST_METHOD'] ?? '';
        
        // Add system context
        $systemContext = [
            'process_id' => getmypid(),
            'memory_usage' => memory_get_usage(true),
            'execution_time' => microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true))
        ];
        
        $context = array_merge($context, $systemContext);
        
        $contextStr = '';
        if (!empty($context)) {
            $contextStr = ' | Context: ' . json_encode($context, JSON_UNESCAPED_SLASHES);
        }
        
        $logEntry = "[$timestamp] [$level]{$userInfo} [IP: $ip] [$requestMethod $requestUri] - $message{$contextStr}" . PHP_EOL;
        
        if (file_exists($fullPath) && filesize($fullPath) > LOG_MAX_SIZE) {
            self::rotateLog($fullPath);
        }
        
        return file_put_contents($fullPath, $logEntry, FILE_APPEND | LOCK_EX) !== false;
    }
    
    public static function debug($message, $context = [], $logFile = 'debug.log') {
        return self::log(self::DEBUG, $message, $context, $logFile);
    }
    
    public static function info($message, $context = [], $logFile = 'audit.log') {
        return self::log(self::INFO, $message, $context, $logFile);
    }
    
    public static function warning($message, $context = [], $logFile = 'audit.log') {
        return self::log(self::WARNING, $message, $context, $logFile);
    }
    
    public static function error($message, $context = [], $logFile = 'error.log') {
        return self::log(self::ERROR, $message, $context, $logFile);
    }
    
    public static function critical($message, $context = [], $logFile = 'error.log') {
        return self::log(self::CRITICAL, $message, $context, $logFile);
    }
    
    public static function security($message, $context = []) {
        return self::log(self::WARNING, $message, $context, 'security.log');
    }
    
    public static function voting($message, $context = []) {
        return self::log(self::INFO, $message, $context, 'voting.log');
    }
    
    public static function access($message, $context = []) {
        return self::log(self::INFO, $message, $context, 'access.log');
    }
    
    /**
     * Log database queries for debugging
     */
    public static function query($query, $params = [], $executionTime = null) {
        $context = [
            'query' => $query,
            'params' => $params,
            'execution_time_ms' => $executionTime ? round($executionTime * 1000, 2) : null
        ];
        
        return self::log(self::DEBUG, "Database Query", $context, 'queries.log');
    }
    
    /**
     * Enhanced IP detection with proxy support
     */
    private static function getClientIP() {
        $ipKeys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
        
        foreach ($ipKeys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    }
    
    /**
     * Rotate log files when they get too large
     */
    private static function rotateLog($logFile) {
        $backupFile = $logFile . '.' . date('Y-m-d-H-i-s') . '.bak';
        if (rename($logFile, $backupFile)) {
            touch($logFile);
            chmod($logFile, 0644);
            
            // Log rotation completed (avoiding recursive logging)
        }
    }
    
    /**
     * Clean up old backup log files
     */
    public static function cleanupOldLogs($days = 30) {
        $files = glob(LOG_DIR . '*.bak');
        $cutoffTime = time() - ($days * 24 * 60 * 60);
        $deletedCount = 0;
        
        foreach ($files as $file) {
            if (filemtime($file) < $cutoffTime) {
                if (unlink($file)) {
                    $deletedCount++;
                }
            }
        }
        
        // Cleanup completed (avoiding recursive logging)
        
        return $deletedCount;
    }
    
    /**
     * Get system health information
     */
    public static function getSystemHealth() {
        return [
            'logger_initialized' => self::$initialized,
            'log_level' => defined('LOG_LEVEL') ? LOG_LEVEL : 'Not set',
            'log_dir' => defined('LOG_DIR') ? LOG_DIR : 'Not set',
            'log_dir_writable' => defined('LOG_DIR') ? is_writable(LOG_DIR) : false,
            'max_log_size' => defined('LOG_MAX_SIZE') ? LOG_MAX_SIZE : 'Not set',
            'error_handler_active' => self::$originalErrorHandler !== null,
            'exception_handler_active' => self::$originalExceptionHandler !== null,
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'php_version' => PHP_VERSION
        ];
    }
    
    /**
     * Restore original error handlers (useful for testing or cleanup)
     */
    public static function restoreErrorHandlers() {
        if (self::$originalErrorHandler !== null) {
            set_error_handler(self::$originalErrorHandler);
            self::$originalErrorHandler = null;
        }
        
        if (self::$originalExceptionHandler !== null) {
            set_exception_handler(self::$originalExceptionHandler);
            self::$originalExceptionHandler = null;
        }
        
        // Original error handlers restored (avoiding recursive logging)
    }
}

// Helper functions for backwards compatibility and convenience
function logSecurityEvent($event, $details = '', $severity = 'WARNING', $context = []) {
    $message = "Security Event: $event";
    if ($details) {
        $message .= " | Details: $details";
    }
    
    Logger::security($message, $context);
    
    if ($severity === 'CRITICAL') {
        Logger::critical($message, $context, 'security.log');
    }
}

function logVotingActivity($action, $election_id = null, $candidate_id = null, $details = '', $context = []) {
    $message = "Voting Action: $action";
    if ($election_id) {
        $message .= " | Election ID: $election_id";
    }
    if ($candidate_id) {
        $message .= " | Candidate ID: $candidate_id";
    }
    if ($details) {
        $message .= " | Details: $details";
    }
    
    Logger::voting($message, $context);
}

function logError($error, $file = '', $line = '', $context = []) {
    $message = "Application Error: $error";
    if ($file) {
        $message .= " | File: $file";
    }
    if ($line) {
        $message .= " | Line: $line";
    }
    
    Logger::error($message, $context);
}

// Auto-initialize the logger when the file is included
Logger::init();

?>