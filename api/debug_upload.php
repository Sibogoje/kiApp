<?php
// Debug upload endpoint to test multipart uploads
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, Authorization, Accept, Origin');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

header('Content-Type: application/json');

// Log all request details
$debug_info = [
    'method' => $_SERVER['REQUEST_METHOD'],
    'headers' => getallheaders(),
    'post_data' => $_POST,
    'files_data' => $_FILES,
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'not set',
    'content_length' => $_SERVER['CONTENT_LENGTH'] ?? 'not set',
    'timestamp' => date('Y-m-d H:i:s')
];

// Write debug info to a log file
file_put_contents(__DIR__ . '/upload_debug.log', 
    date('Y-m-d H:i:s') . " - " . json_encode($debug_info) . "\n", 
    FILE_APPEND | LOCK_EX);

echo json_encode([
    'success' => true,
    'message' => 'Debug upload endpoint reached',
    'debug' => $debug_info
]);
?>
