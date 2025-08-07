<?php
require_once 'config.php';

// Set CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        getCategories($pdo);
        break;
    default:
        sendResponse(false, 'Method not allowed');
}

function getCategories($pdo) {
    try {
        // Get only active categories, ordered by name
        $sql = "SELECT id, name, description, icon, color, status 
                FROM categories 
                WHERE status = 'active' 
                ORDER BY name ASC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        sendResponse(true, 'Categories retrieved successfully', [
            'categories' => $categories,
            'count' => count($categories)
        ]);
        
    } catch (Exception $e) {
        error_log("Error getting categories: " . $e->getMessage());
        sendResponse(false, 'Database error occurred');
    }
}
?>
