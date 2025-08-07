<?php
// Simple session-based authentication system
// Configure session for CORS
ini_set('session.cookie_samesite', 'None');
ini_set('session.cookie_secure', '0'); // Set to 1 for HTTPS
ini_set('session.cookie_httponly', '1');

session_start();

require_once 'config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

$pdo = getDbConnection();

switch ($method) {
    case 'POST':
        if (isset($input['action']) && $input['action'] === 'login') {
            login($pdo, $input);
        } else {
            sendResponse(false, 'Invalid action');
        }
        break;
    case 'GET':
        if (isset($_GET['action']) && $_GET['action'] === 'verify') {
            verifySession();
        } else {
            sendResponse(false, 'Invalid action');
        }
        break;
    case 'DELETE':
        logout();
        break;
    default:
        sendResponse(false, 'Method not allowed');
}

function login($pdo, $input) {
    if (!isset($input['email']) || !isset($input['password'])) {
        sendResponse(false, 'Email and password required');
    }

    try {
        $stmt = $pdo->prepare("SELECT id, name, surname, email, password_hash, phone, date_of_birth, address, profile_image, status FROM clients WHERE email = ?");
        $stmt->execute([$input['email']]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$client) {
            sendResponse(false, 'Invalid credentials');
        }

        if (!password_verify($input['password'], $client['password_hash'])) {
            sendResponse(false, 'Invalid credentials');
        }

        if ($client['status'] !== 'active') {
            sendResponse(false, 'Account not active');
        }

        // Store client info in session
        $_SESSION['client_id'] = $client['id'];
        $_SESSION['client_email'] = $client['email'];
        $_SESSION['client_name'] = $client['name'];
        $_SESSION['login_time'] = time();

        // Remove password from response
        unset($client['password_hash']);

        sendResponse(true, 'Login successful', [
            'client' => $client,
            'session_id' => session_id()
        ]);

    } catch (PDOException $e) {
        sendResponse(false, 'Login failed: ' . $e->getMessage());
    }
}

function verifySession() {
    if (!isset($_SESSION['client_id'])) {
        sendResponse(false, 'Not authenticated');
    }

    // Session expires after 24 hours
    if (time() - $_SESSION['login_time'] > 86400) {
        session_destroy();
        sendResponse(false, 'Session expired');
    }

    sendResponse(true, 'Session valid', [
        'client_id' => $_SESSION['client_id'],
        'client_email' => $_SESSION['client_email'],
        'client_name' => $_SESSION['client_name']
    ]);
}

function logout() {
    session_destroy();
    sendResponse(true, 'Logged out successfully');
}

function requireAuth() {
    if (!isset($_SESSION['client_id'])) {
        sendResponse(false, 'Authentication required');
    }
    
    // Check session timeout
    if (time() - $_SESSION['login_time'] > 86400) {
        session_destroy();
        sendResponse(false, 'Session expired');
    }
    
    return $_SESSION['client_id'];
}
?>
