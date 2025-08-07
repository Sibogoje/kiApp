<?php
// Database configuration
define('DB_HOST', 'srv1212.hstgr.io');
define('DB_USER', 'u747325399_khulumaApp');
define('DB_PASS', '5is~l4oCm>N');
define('DB_NAME', 'u747325399_khulumaApp');

// Global PDO connection to reuse
$globalPdo = null;

// Simple memory cache for session data
$memoryCache = [];

// Cache functions
function getCached($key) {
    global $memoryCache;
    if (isset($memoryCache[$key]) && $memoryCache[$key]['expires'] > time()) {
        return $memoryCache[$key]['data'];
    }
    return null;
}

function setCache($key, $data, $ttl = 300) { // 5 minutes default
    global $memoryCache;
    $memoryCache[$key] = [
        'data' => $data,
        'expires' => time() + $ttl
    ];
}

function clearCache($key = null) {
    global $memoryCache;
    if ($key === null) {
        $memoryCache = [];
    } else {
        unset($memoryCache[$key]);
    }
}

// Create optimized database connection
function getDbConnection() {
    global $globalPdo;
    
    // Reuse existing connection if available
    if ($globalPdo !== null) {
        try {
            // Test if connection is still alive
            $globalPdo->query('SELECT 1');
            return $globalPdo;
        } catch (PDOException $e) {
            // Connection is dead, create new one
            $globalPdo = null;
        }
    }
    
    try {
        $globalPdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER, 
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => true, // Use persistent connections
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
                PDO::ATTR_TIMEOUT => 10, // 10 second timeout
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET sql_mode='', time_zone='+00:00', wait_timeout=300"
            ]
        );
        
        return $globalPdo;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]);
        exit();
    }
}



// Utility functions
function sendResponse($success, $message, $data = null) {
    header('Content-Type: application/json; charset=utf-8');
    
    $response = [
        'success' => $success,
        'message' => $message
    ];
    
    if ($data !== null) {
        $response['data'] = $data;
    }
    
    echo json_encode($response);
    exit();
}

function validateRequired($data, $fields) {
    foreach ($fields as $field) {
        if (!isset($data[$field]) || empty(trim($data[$field]))) {
            sendResponse(false, ucfirst($field) . ' is required');
        }
    }
}

function getAuthHeader() {
    // Enhanced debugging to see what headers are available
    error_log("=== DEBUG: Headers Debug ===");
    error_log("REQUEST_METHOD: " . ($_SERVER['REQUEST_METHOD'] ?? 'not set'));
    error_log("All _SERVER keys with HTTP_: " . json_encode(array_keys(array_filter($_SERVER, function($key) {
        return strpos($key, 'HTTP_') === 0;
    }, ARRAY_FILTER_USE_KEY))));
    
    // Try getallheaders() first (works in web context)
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        error_log("getallheaders() result: " . json_encode($headers));
        if (isset($headers['Authorization'])) {
            $token = str_replace('Bearer ', '', $headers['Authorization']);
            error_log("Found Authorization in getallheaders(): " . substr($token, 0, 10) . "... (length: " . strlen($token) . ")");
            return $token;
        }
    }
    
    // Fallback to $_SERVER (works in both web and CLI)
    $authHeader = null;
    
    // Check common authorization header variations
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
        error_log("Found HTTP_AUTHORIZATION: " . substr($authHeader, 0, 20) . "...");
    } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        error_log("Found REDIRECT_HTTP_AUTHORIZATION: " . substr($authHeader, 0, 20) . "...");
    } elseif (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        error_log("apache_request_headers() result: " . json_encode($headers));
        if (isset($headers['Authorization'])) {
            $authHeader = $headers['Authorization'];
            error_log("Found Authorization in apache_request_headers(): " . substr($authHeader, 0, 20) . "...");
        }
    }
    
    if ($authHeader) {
        $token = str_replace('Bearer ', '', $authHeader);
        error_log("Final extracted token: " . substr($token, 0, 10) . "... (length: " . strlen($token) . ")");
        return $token;
    }
    
    error_log("No authorization header found");
    return null;
}

function validateToken($token) {
    if (!$token) {
        sendResponse(false, 'Authorization token required');
    }
    
    // Check cache first for recent validations (cache for 2 minutes)
    $cacheKey = "token_validation_" . md5($token);
    $cachedResult = getCached($cacheKey);
    if ($cachedResult !== null) {
        return $cachedResult;
    }
    
    $pdo = getDbConnection();
    
    try {
        // Optimized query - check token validity first, then get client details only if needed
        $stmt = $pdo->prepare("
            SELECT client_id, expires_at
            FROM client_sessions 
            WHERE token = ? AND expires_at > NOW()
        ");
        $stmt->execute([$token]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$session) {
            sendResponse(false, 'Invalid or expired token');
        }
        
        // Only check client status if we have a valid session
        $clientStmt = $pdo->prepare("SELECT status FROM clients WHERE id = ?");
        $clientStmt->execute([$session['client_id']]);
        $client = $clientStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$client || $client['status'] !== 'active') {
            sendResponse(false, 'Account not active');
        }
        
        // Update last_used only every 5 minutes to reduce database writes
        $fiveMinutesAgo = date('Y-m-d H:i:s', strtotime('-5 minutes'));
        $updateStmt = $pdo->prepare("
            UPDATE client_sessions 
            SET last_used = NOW() 
            WHERE token = ? AND (last_used IS NULL OR last_used < ?)
        ");
        $updateStmt->execute([$token, $fiveMinutesAgo]);
        
        // Cache the result for 2 minutes
        setCache($cacheKey, $session['client_id'], 120);
        
        return $session['client_id'];
        
    } catch (PDOException $e) {
        sendResponse(false, 'Token verification failed: ' . $e->getMessage());
    }
}



// Initialize database connection
$pdo = getDbConnection();


?>
