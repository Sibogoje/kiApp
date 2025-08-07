<?php
// File upload endpoint for documents
require_once 'config.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Enhanced CORS Headers - Must be first, before any output
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, Authorization, Accept, Origin');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Max-Age: 86400'); // 24 hours

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Content-Type: application/json');
    http_response_code(200);
    echo json_encode(['status' => 'OK']);
    exit();
}

// Log request for debugging
error_log("Upload request - Method: " . $_SERVER['REQUEST_METHOD']);
error_log("Upload request - Content-Type: " . ($_SERVER['CONTENT_TYPE'] ?? 'not set'));
error_log("Upload request - POST data: " . json_encode($_POST));
error_log("Upload request - FILES data: " . json_encode(array_keys($_FILES)));

// Set content type for JSON response after CORS headers
header('Content-Type: application/json');

// Only accept POST requests for file uploads
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Only POST method allowed for file uploads');
}

// Check if client_id is provided
$clientId = $_POST['client_id'] ?? null;
if (!$clientId) {
    sendResponse(false, 'client_id is required');
}

// Validate required fields
$documentName = $_POST['document_name'] ?? null;
$documentType = $_POST['document_type'] ?? 'other';
$description = $_POST['description'] ?? null;

if (!$documentName) {
    sendResponse(false, 'document_name is required');
}

// Check if file was uploaded
if (!isset($_FILES['document_file']) || $_FILES['document_file']['error'] !== UPLOAD_ERR_OK) {
    $errorMessage = 'File upload failed';
    if (isset($_FILES['document_file']['error'])) {
        switch ($_FILES['document_file']['error']) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $errorMessage = 'File too large';
                break;
            case UPLOAD_ERR_PARTIAL:
                $errorMessage = 'File upload was interrupted';
                break;
            case UPLOAD_ERR_NO_FILE:
                $errorMessage = 'No file was uploaded';
                break;
            case UPLOAD_ERR_NO_TMP_DIR:
                $errorMessage = 'Missing temporary folder';
                break;
            case UPLOAD_ERR_CANT_WRITE:
                $errorMessage = 'Failed to write file to disk';
                break;
            case UPLOAD_ERR_EXTENSION:
                $errorMessage = 'File upload stopped by extension';
                break;
        }
    }
    sendResponse(false, $errorMessage);
}

$uploadedFile = $_FILES['document_file'];

// Validate file size (10MB limit)
$maxSize = 10 * 1024 * 1024; // 10MB
if ($uploadedFile['size'] > $maxSize) {
    sendResponse(false, 'File size exceeds 10MB limit');
}

// Validate file extension
$allowedExtensions = ['pdf', 'doc', 'docx', 'txt', 'rtf', 'jpg', 'jpeg', 'png', 'gif', 'bmp', 'xls', 'xlsx', 'csv', 'zip', 'rar'];
$fileExtension = strtolower(pathinfo($uploadedFile['name'], PATHINFO_EXTENSION));

if (!in_array($fileExtension, $allowedExtensions)) {
    sendResponse(false, 'File type not allowed. Allowed types: ' . implode(', ', $allowedExtensions));
}

// Create upload directory if it doesn't exist
$uploadDir = __DIR__ . '/uploads/documents/';
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) {
        sendResponse(false, 'Failed to create upload directory');
    }
}

// Generate unique filename
$fileBaseName = pathinfo($uploadedFile['name'], PATHINFO_FILENAME);
$uniqueFileName = uniqid() . '_' . time() . '.' . $fileExtension;
$filePath = $uploadDir . $uniqueFileName;

// Move uploaded file
if (!move_uploaded_file($uploadedFile['tmp_name'], $filePath)) {
    sendResponse(false, 'Failed to save uploaded file');
}

// Save to database
try {
    $pdo = getDbConnection();
    
    $stmt = $pdo->prepare("
        INSERT INTO documents (
            client_id, 
            document_name, 
            document_type, 
            file_path, 
            file_size, 
            description, 
            uploaded_by, 
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    
    $result = $stmt->execute([
        $clientId,
        $documentName,
        $documentType,
        'uploads/documents/' . $uniqueFileName,
        $uploadedFile['size'],
        $description,
        null // uploaded_by can be null now
    ]);
    
    if ($result) {
        $documentId = $pdo->lastInsertId();
        
        // Get the inserted document for response
        $stmt = $pdo->prepare("SELECT * FROM documents WHERE id = ?");
        $stmt->execute([$documentId]);
        $document = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($document) {
            $document['file_size_formatted'] = formatFileSize($document['file_size']);
            $document['created_at_formatted'] = date('M j, Y', strtotime($document['created_at']));
            $document['updated_at_formatted'] = date('M j, Y', strtotime($document['updated_at']));
            $document['original_filename'] = $uploadedFile['name'];
        }
        
        sendResponse(true, 'Document uploaded successfully', $document);
    } else {
        // Delete the uploaded file if database insert failed
        unlink($filePath);
        sendResponse(false, 'Failed to save document information');
    }
    
} catch (Exception $e) {
    // Delete the uploaded file if database operation failed
    if (file_exists($filePath)) {
        unlink($filePath);
    }
    error_log("Error uploading document: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    sendResponse(false, 'Database error: ' . $e->getMessage());
}

function formatFileSize($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    
    $bytes /= (1 << (10 * $pow));
    
    return round($bytes, 2) . ' ' . $units[$pow];
}
?>
