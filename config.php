<?php
// ============================================
// JARVIS OSINT AI v5.0 CONFIGURATION
// ============================================

// Database Configuration
define("DB_SERVER", "localhost");
define("DB_USERNAME", "dostond1_davlatov");
define("DB_PASSWORD", "Doston_Davlatov_2006");
define("DB_NAME", "dostond1_personal_db");

// API Keys Configuration
define("GROQ_API_KEY", "YOUR_API_KEY_HERE"); // https://console.groq.com
define("SERPAPI_KEY", ""); // https://serpapi.com (web search uchun)
define("OPENWEATHER_API_KEY", "344e095ee65a3864f666e43c629872d1"); // https://openweathermap.org
define("NEWSAPI_KEY", "38c66680fdbd498189e47306c65dc423"); // https://newsapi.org

// System Settings
define("DEBUG_MODE", true);
define("ENABLE_WEB_SEARCH", true);
define("ENABLE_VOICE_SYNTHESIS", true);
define("ENABLE_CACHING", true);
define("CACHE_DURATION", 3600); // 1 hour
define("RATE_LIMIT_REQUESTS", 20); // 20 so'rov/daq
define("RATE_LIMIT_WINDOW", 60); // 60 soniya

// AI Model Settings
define("DEFAULT_GROQ_MODEL", "llama-3.3-70b-versatile");
define("BACKUP_MODEL", "mixtral-8x7b-32768");
define("MAX_TOKENS", 1500);
define("AI_TEMPERATURE", 0.7);
define("MAX_CONTEXT_LENGTH", 4000);

// Web Search Settings
define("SEARCH_RESULTS_LIMIT", 5);
define("SEARCH_TIMEOUT", 10); // seconds
define("ENABLE_DUCKDUCKGO", true);
define("ENABLE_WIKIPEDIA", true);
define("ENABLE_NEWS", false);

// Voice Settings
define("DEFAULT_VOICE_LANG", "uz-UZ");
define("VOICE_SPEED", 1.0);
define("VOICE_PITCH", 1.0);

// Security Settings
define("ENABLE_IP_BLOCKING", false);
define("MAX_REQUEST_SIZE", 5000); // bytes
define("ALLOWED_ORIGINS", ["https://doston-davlatov.uz", "http://localhost"]);

// Timezone
date_default_timezone_set('Asia/Tashkent');

// Error Reporting
if (DEBUG_MODE) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(0);
}

// Auto-loader for classes
spl_autoload_register(function ($class_name) {
    $directories = [
        'classes/',
        'models/',
        'controllers/',
        'services/'
    ];
    
    foreach ($directories as $directory) {
        $file = $directory . $class_name . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

// Session handling for user analytics
if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_lifetime' => 86400,
        'read_and_close'  => false,
    ]);
}

// Global functions
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

function logActivity($message, $type = 'info') {
    $logFile = 'logs/activity_' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] [$type] $message\n";
    
    if (!file_exists('logs')) {
        mkdir('logs', 0755, true);
    }
    
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

// Database class
class Database {
    private $conn;
    private static $instance = null;
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        try {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            
            $this->conn = new mysqli(
                DB_SERVER, 
                DB_USERNAME, 
                DB_PASSWORD, 
                DB_NAME
            );
            
            if ($this->conn->connect_error) {
                throw new Exception("Database connection failed: " . $this->conn->connect_error);
            }
            
            $this->conn->set_charset("utf8mb4");
            $this->initializeTables();
            
        } catch (Exception $e) {
            error_log("Database Error: " . $e->getMessage());
            throw $e;
        }
    }
    
    private function initializeTables() {
        $tables = [
            "CREATE TABLE IF NOT EXISTS jarvis_cache (
                id INT AUTO_INCREMENT PRIMARY KEY,
                query_hash VARCHAR(64) UNIQUE,
                response MEDIUMTEXT,
                source VARCHAR(50),
                model VARCHAR(50),
                tokens_used INT,
                expires_at TIMESTAMP,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_query_hash (query_hash),
                INDEX idx_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            
            "CREATE TABLE IF NOT EXISTS analytics (
                id INT AUTO_INCREMENT PRIMARY KEY,
                session_id VARCHAR(64),
                user_ip VARCHAR(45),
                query TEXT,
                response_time INT,
                tokens_used INT,
                model VARCHAR(50),
                source VARCHAR(50),
                cache_hit TINYINT(1) DEFAULT 0,
                response_length INT DEFAULT 0,
                error TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_session (session_id),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            
            "CREATE TABLE IF NOT EXISTS user_sessions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                session_id VARCHAR(64) UNIQUE,
                user_ip VARCHAR(45),
                user_agent TEXT,
                total_queries INT DEFAULT 0,
                start_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_session (session_id),
                INDEX idx_last_activity (last_activity)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            
            "CREATE TABLE IF NOT EXISTS learning_data (
                id INT AUTO_INCREMENT PRIMARY KEY,
                query TEXT,
                response TEXT,
                category VARCHAR(50),
                quality_score FLOAT DEFAULT 1.0,
                usage_count INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_category (category),
                INDEX idx_quality (quality_score)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        ];
        
        foreach ($tables as $tableSQL) {
            $this->conn->query($tableSQL);
        }
    }
    
    public function getConnection() {
        return $this->conn;
    }
    
    public function executeQuery($sql, $params = [], $types = "") {
        $stmt = $this->conn->prepare($sql);
        
        if (!$stmt) {
            throw new Exception("SQL prepare error: " . $this->conn->error);
        }
        
        if ($params && $types) {
            $stmt->bind_param($types, ...$params);
        }
        
        if (!$stmt->execute()) {
            throw new Exception("SQL execute error: " . $stmt->error);
        }
        
        return $stmt;
    }
    
    public function select($table, $columns = "*", $conditions = [], $order = "", $limit = "", $offset = 0) {
        $sql = "SELECT $columns FROM $table";
        $params = [];
        $types = "";
        
        if (!empty($conditions)) {
            $whereParts = [];
            foreach ($conditions as $key => $value) {
                $whereParts[] = "$key = ?";
                $params[] = $value;
                $types .= is_int($value) ? 'i' : 's';
            }
            $sql .= " WHERE " . implode(' AND ', $whereParts);
        }
        
        if ($order) {
            $sql .= " ORDER BY $order";
        }
        
        if ($limit) {
            $sql .= " LIMIT ?";
            $params[] = (int)$limit;
            $types .= 'i';
            
            if ($offset > 0) {
                $sql .= " OFFSET ?";
                $params[] = $offset;
                $types .= 'i';
            }
        }
        
        try {
            $stmt = $this->executeQuery($sql, $params, $types);
            $result = $stmt->get_result();
            return $result->fetch_all(MYSQLI_ASSOC);
        } catch (Exception $e) {
            error_log("Select Error: " . $e->getMessage());
            return [];
        }
    }
    
    public function insert($table, $data) {
        $keys = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        
        $sql = "INSERT INTO $table ($keys) VALUES ($placeholders)";
        $types = str_repeat('s', count($data));
        
        try {
            $stmt = $this->executeQuery($sql, array_values($data), $types);
            return $this->conn->insert_id;
        } catch (Exception $e) {
            error_log("Insert Error: " . $e->getMessage());
            return false;
        }
    }
    
    public function update($table, $data, $conditions) {
        $setParts = [];
        $params = [];
        $types = "";
        
        foreach ($data as $key => $value) {
            $setParts[] = "$key = ?";
            $params[] = $value;
            $types .= is_int($value) ? 'i' : 's';
        }
        
        $whereParts = [];
        foreach ($conditions as $key => $value) {
            $whereParts[] = "$key = ?";
            $params[] = $value;
            $types .= is_int($value) ? 'i' : 's';
        }
        
        $sql = "UPDATE $table SET " . implode(', ', $setParts) . 
               " WHERE " . implode(' AND ', $whereParts);
        
        try {
            $stmt = $this->executeQuery($sql, $params, $types);
            return $this->conn->affected_rows;
        } catch (Exception $e) {
            error_log("Update Error: " . $e->getMessage());
            return false;
        }
    }
    
    public function delete($table, $conditions) {
        $whereParts = [];
        $params = [];
        $types = "";
        
        foreach ($conditions as $key => $value) {
            $whereParts[] = "$key = ?";
            $params[] = $value;
            $types .= is_int($value) ? 'i' : 's';
        }
        
        $sql = "DELETE FROM $table WHERE " . implode(' AND ', $whereParts);
        
        try {
            $stmt = $this->executeQuery($sql, $params, $types);
            return $this->conn->affected_rows;
        } catch (Exception $e) {
            error_log("Delete Error: " . $e->getMessage());
            return false;
        }
    }
    
    public function beginTransaction() {
        $this->conn->begin_transaction();
    }
    
    public function commit() {
        $this->conn->commit();
    }
    
    public function rollback() {
        $this->conn->rollback();
    }
    
    public function __destruct() {
        if ($this->conn) {
            $this->conn->close();
        }
    }
}

// Create global database instance
$db = Database::getInstance();

// Initialize user session
if (!isset($_SESSION['jarvis_session_id'])) {
    $_SESSION['jarvis_session_id'] = generateUUID();
    $_SESSION['jarvis_start_time'] = time();
    
    // Log new session
    $db->insert('user_sessions', [
        'session_id' => $_SESSION['jarvis_session_id'],
        'user_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
    ]);
    
    logActivity("New session started: {$_SESSION['jarvis_session_id']}");
}
?>
