<?php
// Client registration endpoint
require_once 'config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Only POST method allowed');
}

$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
$requiredFields = ['name', 'surname', 'email', 'password', 'phone', 'date_of_birth'];
foreach ($requiredFields as $field) {
    if (!isset($input[$field]) || empty(trim($input[$field]))) {
        sendResponse(false, ucfirst($field) . ' is required');
    }
}

// Validate email format
if (!filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
    sendResponse(false, 'Invalid email format');
}

// Validate password strength
if (strlen($input['password']) < 6) {
    sendResponse(false, 'Password must be at least 6 characters long');
}

// Validate phone number (basic validation)
$phone = preg_replace('/[^0-9+]/', '', $input['phone']);
if (strlen($phone) < 8) {
    sendResponse(false, 'Invalid phone number');
}

// Validate date of birth
try {
    $dob = new DateTime($input['date_of_birth']);
    $today = new DateTime();
    $age = $today->diff($dob)->y;
    
    if ($age < 16) {
        sendResponse(false, 'You must be at least 16 years old to register');
    }
    if ($age > 100) {
        sendResponse(false, 'Invalid date of birth');
    }
} catch (Exception $e) {
    sendResponse(false, 'Invalid date of birth format. Use YYYY-MM-DD');
}

$pdo = getDbConnection();

try {
    // Check if email already exists
    $stmt = $pdo->prepare("SELECT id FROM clients WHERE email = ?");
    $stmt->execute([$input['email']]);
    if ($stmt->fetch()) {
        sendResponse(false, 'Email address is already registered');
    }

    // Check if phone already exists
    $stmt = $pdo->prepare("SELECT id FROM clients WHERE phone = ?");
    $stmt->execute([$phone]);
    if ($stmt->fetch()) {
        sendResponse(false, 'Phone number is already registered');
    }

    // Hash password
    $passwordHash = password_hash($input['password'], PASSWORD_DEFAULT);

    // Insert new client
    $stmt = $pdo->prepare("
        INSERT INTO clients (
            name, surname, email, password_hash, phone, 
            date_of_birth, address, status, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 'active', NOW())
    ");
    
    $stmt->execute([
        trim($input['name']),
        trim($input['surname']),
        trim($input['email']),
        $passwordHash,
        $phone,
        $input['date_of_birth'],
        isset($input['address']) ? trim($input['address']) : null
    ]);

    $clientId = $pdo->lastInsertId();

    // Get the created client data (without password)
    $stmt = $pdo->prepare("
        SELECT id, name, surname, email, phone, date_of_birth, 
               address, profile_image, status, created_at 
        FROM clients WHERE id = ?
    ");
    $stmt->execute([$clientId]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);

    sendResponse(true, 'Registration successful! You can now log in.', [
        'client' => $client
    ]);

} catch (PDOException $e) {
    error_log("Registration error: " . $e->getMessage());
    
    // Check for specific constraint violations
    if (strpos($e->getMessage(), 'email') !== false) {
        sendResponse(false, 'Email address is already registered');
    } elseif (strpos($e->getMessage(), 'phone') !== false) {
        sendResponse(false, 'Phone number is already registered');
    } else {
        sendResponse(false, 'Registration failed. Please try again.');
    }
}
?>
