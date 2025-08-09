<?php
// Direct database access for profile - Stateless API
require_once 'config.php';

// CORS Headers - Must be first
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, Authorization');
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

$pdo = getDbConnection();

// Get client ID from query/body
$clientId = null;
if ($method === 'GET') {
    $clientId = $_GET['client_id'] ?? null;
} elseif ($method === 'PUT') {
    $clientId = $input['client_id'] ?? null;
}

if (!$clientId) {
    sendResponse(false, 'client_id is required');
}

switch ($method) {
    case 'GET':
        getProfile($pdo, $clientId);
        break;
    case 'PUT':
        updateProfile($pdo, $clientId, $input);
        break;
    default:
        sendResponse(false, 'Method not allowed');
}

function getFullImageUrl($relativePath) {
    $baseUrl = 'https://khulumaeswatini.com/client/'; // Replace with your actual domain
    return $baseUrl . $relativePath;
}

function getProfile($pdo, $clientId) {
    try {
        error_log("Getting profile for client_id: " . $clientId);
        
        $stmt = $pdo->prepare("
            SELECT id, name, surname, email, phone, date_of_birth, address, profile_image
            FROM clients 
            WHERE id = ?
        ");
        $stmt->execute([$clientId]);
        
        $profile = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($profile) {
            // Convert relative image path to full URL if it exists
            if (!empty($profile['profile_image'])) {
                $profile['profile_image'] = getFullImageUrl($profile['profile_image']);
            }

        
        error_log("Profile found: " . ($profile ? 'yes' : 'no'));
        if ($profile) {
            error_log("Profile data: " . json_encode($profile));
        }
        
        if ($profile) {
            sendResponse(true, 'Profile retrieved successfully', $profile);
        } else {
            sendResponse(false, 'Profile not found');
        }
        
    } catch (Exception $e) {
        error_log("Error getting profile: " . $e->getMessage());
        sendResponse(false, 'Database error occurred');
    }
}

}

function updateProfile($pdo, $clientId, $data) {
    try {
        // Build dynamic update query
        $updateFields = [];
        $params = [];
        
        $allowedFields = ['name', 'surname', 'email', 'phone', 'date_of_birth', 'address', 'profile_image'];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updateFields[] = "$field = ?";
                $params[] = $data[$field];
            }
        }
        
        if (empty($updateFields)) {
            sendResponse(false, 'No valid fields to update');
        }
        
        $params[] = $clientId; // Add client_id for WHERE clause
        
        $sql = "UPDATE clients SET " . implode(', ', $updateFields) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        
        if ($stmt->execute($params)) {
            // Get updated profile
            $stmt = $pdo->prepare("
                SELECT id, name, surname, email, phone, date_of_birth, address, profile_image
                FROM clients 
                WHERE id = ?
            ");
            $stmt->execute([$clientId]);
            $updatedProfile = $stmt->fetch(PDO::FETCH_ASSOC);
            
            sendResponse(true, 'Profile updated successfully', $updatedProfile);
        } else {
            sendResponse(false, 'Failed to update profile');
        }
        
    } catch (Exception $e) {
        error_log("Error updating profile: " . $e->getMessage());
        sendResponse(false, 'Database error occurred');
    }
}
?>