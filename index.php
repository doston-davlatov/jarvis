<?php
// ============================================
// JARVIS OSINT AI - Main Entry Point
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
require_once './config.php';

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

// Handle errors
set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function(Throwable $e) {
    $response = [
        'success' => false,
        'error' => [
            'message' => 'Internal server error',
            'code' => 500
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    if (DEBUG_MODE) {
        $response['error']['debug'] = $e->getMessage();
        $response['error']['file'] = $e->getFile();
        $response['error']['line'] = $e->getLine();
    }
    
    error_log("JARVIS Exception: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    
    http_response_code(500);
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit();
});

// Rate limiting
function checkRateLimit() {
    if (!defined('RATE_LIMIT_REQUESTS') || !defined('RATE_LIMIT_WINDOW')) {
        return true;
    }
    
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = 'rate_limit_' . $ip;
    
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

// Security checks
function validateRequest() {
    // Check request size
    if (isset($_SERVER['CONTENT_LENGTH']) && $_SERVER['CONTENT_LENGTH'] > MAX_REQUEST_SIZE) {
        throw new Exception('Request too large', 413);
    }
    
    // Check origin if specified
    if (defined('ALLOWED_ORIGINS') && !empty(ALLOWED_ORIGINS)) {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin && !in_array($origin, ALLOWED_ORIGINS)) {
            throw new Exception('Origin not allowed', 403);
        }
    }
    
    // Check for suspicious input
    $input = file_get_contents('php://input');
    if ($input) {
        $decoded = json_decode($input, true);
        if ($decoded) {
            foreach ($decoded as $key => $value) {
                if (is_string($value)) {
                    // Basic XSS protection
                    if (preg_match('/<script|javascript:|onload=|onerror=/i', $value)) {
                        throw new Exception('Invalid input detected', 400);
                    }
                }
            }
        }
    }
    
    return true;
}

// Main request handler
function handleRequest() {
    // Validate request
    validateRequest();
    
    // Check rate limit
    if (!checkRateLimit()) {
        throw new Exception('Rate limit exceeded. Please try again later.', 429);
    }
    
    // Load required classes
    require_once 'classes/WebSearch.php';
    require_once 'classes/AIAnalyzer.php';
    require_once 'classes/GroqAI.php';
    require_once 'classes/VoiceSynthesizer.php';
    require_once 'classes/AnalyticsTracker.php';
    require_once 'classes/CacheManager.php';
    require_once 'classes/ResponseFormatter.php';
    require_once 'controllers/ApiController.php';
    
    // Initialize API controller
    $apiController = new ApiController();
    
    // Handle the request
    $response = $apiController->handleRequest();
    
    // Return response
    return $response;
}

// Handle different endpoints
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = trim($path, '/');

// Route requests
switch ($path) {
    case '':
    case 'index.php':
    case 'api':
        // Main API endpoint
        $response = handleRequest();
        break;
        
    case 'status':
        // System status endpoint
        $response = [
            'status' => 'online',
            'system' => 'JARVIS OSINT AI v5.0',
            'version' => '5.0.0',
            'timestamp' => date('Y-m-d H:i:s'),
            'environment' => DEBUG_MODE ? 'development' : 'production',
            'uptime' => getSystemUptime(),
            'services' => [
                'groq_api' => defined('GROQ_API_KEY') && !empty(GROQ_API_KEY),
                'web_search' => ENABLE_WEB_SEARCH,
                'voice_synthesis' => ENABLE_VOICE_SYNTHESIS,
                'database' => true,
                'caching' => ENABLE_CACHING
            ]
        ];
        break;
        
    case 'health':
        // Health check endpoint
        try {
            require_once 'controllers/ApiController.php';
            $apiController = new ApiController();
            $response = $apiController->handleHealthCheck();
        } catch (Exception $e) {
            $response = [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
                'timestamp' => date('Y-m-d H:i:s')
            ];
        }
        break;
        
    case 'docs':
        // API documentation
        $response = getAPIDocumentation();
        break;
        
    case 'audio':
        // Serve audio files
        serveAudioFile();
        exit();
        
    default:
        // Check if it's a file request
        if (file_exists($path) && !is_dir($path)) {
            // Serve static file
            $mimeTypes = [
                'html' => 'text/html',
                'css' => 'text/css',
                'js' => 'application/javascript',
                'json' => 'application/json',
                'png' => 'image/png',
                'jpg' => 'image/jpeg',
                'gif' => 'image/gif',
                'svg' => 'image/svg+xml'
            ];
            
            $ext = pathinfo($path, PATHINFO_EXTENSION);
            if (isset($mimeTypes[$ext])) {
                header('Content-Type: ' . $mimeTypes[$ext]);
                readfile($path);
                exit();
            }
        }
        
        // Not found
        http_response_code(404);
        $response = [
            'success' => false,
            'error' => [
                'message' => 'Endpoint not found',
                'code' => 404
            ],
            'timestamp' => date('Y-m-d H:i:s')
        ];
        break;
}

// Send response
if (is_array($response) || is_object($response)) {
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} else {
    echo $response;
}

// Helper functions
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

function getAPIDocumentation() {
    $docs = [
        'api' => 'JARVIS OSINT AI API v5.0',
        'base_url' => $_SERVER['HTTP_HOST'] ?? 'localhost',
        'endpoints' => [
            'POST /api' => [
                'description' => 'Main API endpoint for AI interactions',
                'parameters' => [
                    'action' => [
                        'required' => false,
                        'default' => 'chat',
                        'options' => ['chat', 'web_search', 'analyze', 'translate', 'summarize', 'code_assist', 'voice_synthesize', 'get_stats', 'clear_cache', 'health_check']
                    ],
                    'message' => 'The user message (required for chat action)',
                    'format' => ['html', 'markdown', 'json', 'plain'],
                    'include_sources' => 'boolean',
                    'generate_voice' => 'boolean',
                    'model' => 'Groq model to use'
                ]
            ],
            'GET /status' => [
                'description' => 'Get system status information'
            ],
            'GET /health' => [
                'description' => 'Health check endpoint'
            ],
            'GET /docs' => [
                'description' => 'API documentation'
            ]
        ],
        'authentication' => 'No authentication required for public endpoints',
        'rate_limits' => [
            'requests' => RATE_LIMIT_REQUESTS ?? 20,
            'window' => RATE_LIMIT_WINDOW ?? 60,
            'unit' => 'requests per minute'
        ],
        'supported_models' => [
            'llama-3.3-70b-versatile' => 'Most capable model',
            'mixtral-8x7b-32768' => 'Good balance of capability and speed',
            'gemma2-9b-it' => 'Lightweight and fast',
            'llama-3.2-1b-preview' => 'Smallest and fastest'
        ],
        'features' => [
            'real_time_web_search' => ENABLE_WEB_SEARCH,
            'voice_synthesis' => ENABLE_VOICE_SYNTHESIS,
            'caching' => ENABLE_CACHING,
            'analytics' => true,
            'multiple_languages' => true,
            'code_assistance' => true,
            'sentiment_analysis' => true
        ],
        'example_requests' => [
            'chat' => [
                'curl' => "curl -X POST https://doston-davlatov.uz/api \\\n  -H 'Content-Type: application/json' \\\n  -d '{\"action\":\"chat\",\"message\":\"Doston Davlatov kim?\"}'",
                'response_format' => 'JSON with AI response, sources, and metadata'
            ],
            'web_search' => [
                'curl' => "curl -X POST https://doston-davlatov.uz/api \\\n  -H 'Content-Type: application/json' \\\n  -d '{\"action\":\"web_search\",\"query\":\"latest AI developments\"}'",
                'response_format' => 'JSON with search results and analysis'
            ]
        ],
        'contact' => [
            'developer' => 'Doston Davlatov',
            'website' => 'https://doston-davlatov.uz',
            'email' => 'contact@doston-davlatov.uz'
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    return $docs;
}

function serveAudioFile() {
    $hash = $_GET['hash'] ?? '';
    $format = $_GET['format'] ?? 'mp3';
    
    if (empty($hash)) {
        http_response_code(400);
        echo json_encode(['error' => 'Audio hash required']);
        exit();
    }
    
    $audioDir = 'audio_cache/';
    $audioFile = $audioDir . $hash . '.' . $format;
    
    if (!file_exists($audioFile)) {
        http_response_code(404);
        echo json_encode(['error' => 'Audio file not found']);
        exit();
    }
    
    // Set appropriate headers
    $mimeTypes = [
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'ogg' => 'audio/ogg'
    ];
    
    if (isset($mimeTypes[$format])) {
        header('Content-Type: ' . $mimeTypes[$format]);
        header('Content-Length: ' . filesize($audioFile));
        header('Cache-Control: public, max-age=86400');
        readfile($audioFile);
    } else {
        http_response_code(415);
        echo json_encode(['error' => 'Unsupported audio format']);
    }
    
    exit();
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

// Cleanup old sessions and cache periodically (1% chance)
if (rand(1, 100) === 1) {
    cleanupOldData();
}

function cleanupOldData() {
    try {
        require_once 'classes/AnalyticsTracker.php';
        require_once 'classes/CacheManager.php';
        
        $db = Database::getInstance();
        $analyticsTracker = new AnalyticsTracker($db);
        $cacheManager = new CacheManager($db);
        
        // Cleanup old analytics data (older than 90 days)
        $analyticsTracker->cleanupOldData(90);
        
        // Optimize cache
        $cacheManager->optimize();
        
        // Cleanup old session files
        $sessionFiles = glob(session_save_path() . '/sess_*');
        $now = time();
        foreach ($sessionFiles as $file) {
            if ($now - filemtime($file) > 86400) { // 24 hours
                unlink($file);
            }
        }
        
    } catch (Exception $e) {
        // Silently fail cleanup
        error_log("Cleanup failed: " . $e->getMessage());
    }
}

// Log request for analytics (in production)
if (!DEBUG_MODE) {
    register_shutdown_function(function() {
        $logData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
            'uri' => $_SERVER['REQUEST_URI'] ?? '/',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'response_time' => round((microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000, 2),
            'memory_usage' => memory_get_usage(true) / 1024 / 1024
        ];
        
        $logFile = 'logs/access_' . date('Y-m-d') . '.log';
        if (!file_exists('logs')) {
            mkdir('logs', 0755, true);
        }
        
        file_put_contents($logFile, json_encode($logData) . PHP_EOL, FILE_APPEND);
    });
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

?>
