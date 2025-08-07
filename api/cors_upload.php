<?php
// CORS Proxy for file uploads
// Set all CORS headers before any processing
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, Authorization, Accept, Origin');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Max-Age: 86400');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    echo 'OK';
    exit();
}

// For POST requests, forward to the actual upload script
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Include and execute the upload script
    require_once 'upload_document.php';
} else {
    header('Content-Type: application/json');
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Only POST method allowed'
    ]);
}
?>
