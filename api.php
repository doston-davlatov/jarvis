<!--api.php-->
<?php
// ============================================
// JARVIS OSINT AI - Main API Endpoint
// ============================================

// Enable error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Define root path
define('ROOT_PATH', dirname(__FILE__));

// Load configuration
require_once 'config.php';

// Set headers for API responses
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Max-Age: 3600");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Error handling
set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function(Throwable $e) {
    error_log("JARVIS API Exception: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    
    $response = [
        'success' => false,
        'error' => [
            'message' => 'Internal server error',
            'code' => 500
        ],
        'timestamp' => date('Y-m-d H:i:s'),
        'session_id' => $_SESSION['jarvis_session_id'] ?? 'unknown'
    ];
    
    if (DEBUG_MODE) {
        $response['error']['debug'] = $e->getMessage();
        $response['error']['file'] = $e->getFile();
        $response['error']['line'] = $e->getLine();
        $response['error']['trace'] = $e->getTraceAsString();
    }
    
    http_response_code(500);
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit();
});

// Global helper functions
function sanitizeInput($input) {
    if (is_array($input)) {
        return array_map('sanitizeInput', $input);
    }
    return htmlspecialchars(trim($input), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function generateUUID() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

function validateRequest() {
    // Check request size
    $maxSize = defined('MAX_REQUEST_SIZE') ? MAX_REQUEST_SIZE : 5000;
    if (isset($_SERVER['CONTENT_LENGTH']) && $_SERVER['CONTENT_LENGTH'] > $maxSize) {
        throw new Exception('Request too large', 413);
    }
    
    // Check origin if specified
    if (defined('ALLOWED_ORIGINS') && !empty(ALLOWED_ORIGINS)) {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
        if ($origin) {
            $allowed = false;
            foreach (ALLOWED_ORIGINS as $allowedOrigin) {
                if (strpos($origin, $allowedOrigin) === 0) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed) {
                throw new Exception('Origin not allowed', 403);
            }
        }
    }
    
    // Basic security checks
    $input = file_get_contents('php://input');
    if ($input && strlen($input) > 0) {
        $decoded = json_decode($input, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON input', 400);
        }
        
        // Check for suspicious patterns
        if (is_array($decoded)) {
            array_walk_recursive($decoded, function($value, $key) {
                if (is_string($value)) {
                    // Prevent XSS and injection attacks
                    if (preg_match('/(<script|javascript:|onload=|onerror=|eval\(|union.*select|insert.*into|delete.*from|drop.*table)/i', $value)) {
                        throw new Exception('Suspicious input detected', 400);
                    }
                }
            });
        }
    }
    
    return true;
}

function checkRateLimit() {
    if (!defined('RATE_LIMIT_REQUESTS') || !defined('RATE_LIMIT_WINDOW')) {
        return true;
    }
    
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = 'rate_limit_' . md5($ip);
    
    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = [
            'count' => 1,
            'start' => time()
        ];
        return true;
    }
    
    $limitData = $_SESSION[$key];
    
    // Reset if window has passed
    if (time() - $limitData['start'] > RATE_LIMIT_WINDOW) {
        $_SESSION[$key] = [
            'count' => 1,
            'start' => time()
        ];
        return true;
    }
    
    // Check limit
    if ($limitData['count'] >= RATE_LIMIT_REQUESTS) {
        return false;
    }
    
    // Increment count
    $limitData['count']++;
    $_SESSION[$key] = $limitData;
    
    return true;
}

function logRequest($data, $response, $responseTime) {
    if (!DEBUG_MODE) return;
    
    $logData = [
        'timestamp' => date('Y-m-d H:i:s'),
        'method' => $_SERVER['REQUEST_METHOD'],
        'uri' => $_SERVER['REQUEST_URI'],
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'request_data' => $data,
        'response_data' => is_array($response) ? $response : ['raw' => substr($response, 0, 200)],
        'response_time' => $responseTime,
        'memory_usage' => memory_get_usage(true) / 1024 / 1024
    ];
    
    $logFile = 'logs/api_requests_' . date('Y-m-d') . '.log';
    if (!file_exists('logs')) {
        mkdir('logs', 0755, true);
    }
    
    file_put_contents($logFile, json_encode($logData, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND);
}

function getSystemUptime() {
    if (!isset($_SESSION['system_start_time'])) {
        $_SESSION['system_start_time'] = time();
    }
    
    $uptime = time() - $_SESSION['system_start_time'];
    
    $days = floor($uptime / 86400);
    $hours = floor(($uptime % 86400) / 3600);
    $minutes = floor(($uptime % 3600) / 60);
    $seconds = $uptime % 60;
    
    if ($days > 0) {
        return sprintf('%d kun, %02d:%02d:%02d', $days, $hours, $minutes, $seconds);
    }
    
    return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
}

// Initialize user session
if (!isset($_SESSION['jarvis_session_id'])) {
    $_SESSION['jarvis_session_id'] = generateUUID();
    $_SESSION['jarvis_start_time'] = time();
    
    // Log new session
    $logFile = 'logs/sessions_' . date('Y-m-d') . '.log';
    $sessionData = [
        'session_id' => $_SESSION['jarvis_session_id'],
        'start_time' => date('Y-m-d H:i:s'),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ];
    
    if (!file_exists('logs')) {
        mkdir('logs', 0755, true);
    }
    
    file_put_contents($logFile, json_encode($sessionData) . PHP_EOL, FILE_APPEND);
}

// Main request processing
try {
    $startTime = microtime(true);
    
    // Validate request
    validateRequest();
    
    // Check rate limit
    if (!checkRateLimit()) {
        throw new Exception('Rate limit exceeded. Please try again later.', 429);
    }
    
    // Get request method
    $method = $_SERVER['REQUEST_METHOD'];
    
    // Load required classes
    $requiredClasses = [
        'classes/Database.php',
        'classes/WebSearch.php',
        'classes/AIAnalyzer.php',
        'classes/GroqAI.php',
        'classes/VoiceSynthesizer.php',
        'classes/AnalyticsTracker.php',
        'classes/CacheManager.php',
        'classes/ResponseFormatter.php',
        'controllers/ApiController.php'
    ];
    
    foreach ($requiredClasses as $classFile) {
        if (file_exists($classFile)) {
            require_once $classFile;
        } else {
            throw new Exception("Required class file not found: $classFile", 500);
        }
    }
    
    // Initialize ApiController
    $apiController = new ApiController();
    
    // Handle request based on method
    switch ($method) {
        case 'POST':
            // Get input data
            $input = json_decode(file_get_contents('php://input'), true);
            
            if ($input === null && json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid JSON input', 400);
            }
            
            $input = $input ?: [];
            $input = sanitizeInput($input);
            
            // Set default action if not provided
            if (empty($input['action'])) {
                $input['action'] = 'chat';
            }
            
            // Process the request
            $response = $apiController->handlePostRequest($input);
            break;
            
        case 'GET':
            $queryParams = $_GET;
            $queryParams = sanitizeInput($queryParams);
            
            if (empty($queryParams['action'])) {
                $queryParams['action'] = 'status';
            }
            
            $response = $apiController->handleGetRequest($queryParams);
            break;
            
        default:
            throw new Exception('Method not allowed', 405);
    }
    
    // Calculate response time
    $responseTime = round((microtime(true) - $startTime) * 1000, 2);
    
    // Add response time to response if it's an array
    if (is_array($response)) {
        $response['response_time'] = $responseTime;
        $response['timestamp'] = date('Y-m-d H:i:s');
        $response['session_id'] = $_SESSION['jarvis_session_id'];
    }
    
    // Log the request (for debugging)
    logRequest($input ?? $queryParams ?? [], $response, $responseTime);
    
    // Send response
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    $errorCode = $e->getCode() ?: 500;
    http_response_code($errorCode);
    
    $errorResponse = [
        'success' => false,
        'error' => [
            'message' => $e->getMessage(),
            'code' => $errorCode,
            'type' => get_class($e)
        ],
        'timestamp' => date('Y-m-d H:i:s'),
        'session_id' => $_SESSION['jarvis_session_id'] ?? 'unknown'
    ];
    
    // Add debug info in development
    if (DEBUG_MODE) {
        $errorResponse['debug'] = [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ];
    }
    
    // Log the error
    error_log("API Error [" . $errorCode . "]: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    
    echo json_encode($errorResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

// Cleanup function for old data (run occasionally)
if (rand(1, 100) === 1) {
    cleanupOldData();
}

function cleanupOldData() {
    try {
        // Cleanup old session files
        $sessionPath = session_save_path();
        if ($sessionPath && is_dir($sessionPath)) {
            $sessionFiles = glob($sessionPath . '/sess_*');
            $now = time();
            $maxAge = 24 * 60 * 60; // 24 hours
            
            foreach ($sessionFiles as $file) {
                if ($now - filemtime($file) > $maxAge) {
                    unlink($file);
                }
            }
        }
        
        // Cleanup old log files (older than 30 days)
        if (file_exists('logs')) {
            $logFiles = glob('logs/*.log');
            $maxLogAge = 30 * 24 * 60 * 60; // 30 days
            
            foreach ($logFiles as $file) {
                if (time() - filemtime($file) > $maxLogAge) {
                    unlink($file);
                }
            }
        }
        
    } catch (Exception $e) {
        // Silently fail cleanup
        error_log("Cleanup failed: " . $e->getMessage());
    }
}

// Maintenance mode check
if (file_exists('maintenance.lock')) {
    http_response_code(503);
    $response = [
        'success' => false,
        'error' => [
            'message' => 'System is under maintenance. Please try again later.',
            'code' => 503
        ],
        'timestamp' => date('Y-m-d H:i:s'),
        'estimated_recovery' => file_get_contents('maintenance.lock')
    ];
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    exit();
}

// Memory cleanup
if (function_exists('gc_collect_cycles')) {
    gc_collect_cycles();
}
?>