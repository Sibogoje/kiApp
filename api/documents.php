<?php
// Direct database access for documents - Stateless API
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
if ($method === 'GET' || $method === 'DELETE') {
    $clientId = $_GET['client_id'] ?? null;
} elseif ($method === 'POST' || $method === 'PUT') {
    $clientId = $input['client_id'] ?? null;
}

if (!$clientId) {
    sendResponse(false, 'client_id is required');
}

switch ($method) {
    case 'GET':
        getDocuments($pdo, $clientId);
        break;
    case 'POST':
        createDocument($pdo, $clientId, $input);
        break;
    case 'PUT':
        updateDocument($pdo, $clientId, $input);
        break;
    case 'DELETE':
        deleteDocument($pdo, $clientId);
        break;
    default:
        sendResponse(false, 'Method not allowed');
}

function getDocuments($pdo, $clientId) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                id, 
                document_name, 
                document_type, 
                file_path, 
                file_size, 
                description,
                created_at,
                updated_at
            FROM documents 
            WHERE client_id = ?
            ORDER BY created_at DESC
        ");
        $stmt->execute([$clientId]);
        
        $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format file sizes and dates
        foreach ($documents as &$document) {
            $document['file_size_formatted'] = formatFileSize($document['file_size']);
            $document['created_at_formatted'] = date('M j, Y', strtotime($document['created_at']));
            $document['updated_at_formatted'] = date('M j, Y', strtotime($document['updated_at']));
        }
        
        sendResponse(true, 'Documents retrieved successfully', $documents);
        
    } catch (Exception $e) {
        error_log("Error getting documents: " . $e->getMessage());
        sendResponse(false, 'Database error occurred');
    }
}

function createDocument($pdo, $clientId, $data) {
    try {
        // Validate required fields
        $requiredFields = ['document_name', 'document_type', 'file_path', 'file_size'];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                sendResponse(false, "Field '$field' is required");
                return;
            }
        }
        
        // For now, uploaded_by will be the same as client_id since clients upload their own documents
        // In a real system, this might be an admin ID
        $uploadedBy = $clientId;
        
        $stmt = $pdo->prepare("
            INSERT INTO documents (
                client_id, 
                document_name, 
                document_type, 
                file_path, 
                file_size, 
                description, 
                uploaded_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        $success = $stmt->execute([
            $clientId,
            $data['document_name'],
            $data['document_type'],
            $data['file_path'],
            $data['file_size'],
            $data['description'] ?? null,
            $uploadedBy
        ]);
        
        if ($success) {
            $documentId = $pdo->lastInsertId();
            
            // Get the created document
            $stmt = $pdo->prepare("
                SELECT 
                    id, 
                    document_name, 
                    document_type, 
                    file_path, 
                    file_size, 
                    description,
                    created_at,
                    updated_at
                FROM documents 
                WHERE id = ?
            ");
            $stmt->execute([$documentId]);
            $document = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($document) {
                $document['file_size_formatted'] = formatFileSize($document['file_size']);
                $document['created_at_formatted'] = date('M j, Y', strtotime($document['created_at']));
                $document['updated_at_formatted'] = date('M j, Y', strtotime($document['updated_at']));
            }
            
            sendResponse(true, 'Document created successfully', $document);
        } else {
            sendResponse(false, 'Failed to create document');
        }
        
    } catch (Exception $e) {
        error_log("Error creating document: " . $e->getMessage());
        sendResponse(false, 'Database error occurred');
    }
}

function updateDocument($pdo, $clientId, $data) {
    try {
        if (!isset($data['id'])) {
            sendResponse(false, 'Document ID is required');
            return;
        }
        
        // Build dynamic update query
        $updateFields = [];
        $params = [];
        
        $allowedFields = ['document_name', 'document_type', 'description'];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updateFields[] = "$field = ?";
                $params[] = $data[$field];
            }
        }
        
        if (empty($updateFields)) {
            sendResponse(false, 'No valid fields to update');
            return;
        }
        
        $params[] = $data['id'];
        $params[] = $clientId;
        
        $sql = "UPDATE documents SET " . implode(', ', $updateFields) . " WHERE id = ? AND client_id = ?";
        $stmt = $pdo->prepare($sql);
        
        if ($stmt->execute($params)) {
            // Get updated document
            $stmt = $pdo->prepare("
                SELECT 
                    id, 
                    document_name, 
                    document_type, 
                    file_path, 
                    file_size, 
                    description,
                    created_at,
                    updated_at
                FROM documents 
                WHERE id = ? AND client_id = ?
            ");
            $stmt->execute([$data['id'], $clientId]);
            $document = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($document) {
                $document['file_size_formatted'] = formatFileSize($document['file_size']);
                $document['created_at_formatted'] = date('M j, Y', strtotime($document['created_at']));
                $document['updated_at_formatted'] = date('M j, Y', strtotime($document['updated_at']));
                sendResponse(true, 'Document updated successfully', $document);
            } else {
                sendResponse(false, 'Document not found');
            }
        } else {
            sendResponse(false, 'Failed to update document');
        }
        
    } catch (Exception $e) {
        error_log("Error updating document: " . $e->getMessage());
        sendResponse(false, 'Database error occurred');
    }
}

function deleteDocument($pdo, $clientId) {
    try {
        $documentId = $_GET['document_id'] ?? null;
        
        if (!$documentId) {
            sendResponse(false, 'document_id is required');
            return;
        }
        
        // First get the document to check if it exists and get file path for deletion
        $stmt = $pdo->prepare("
            SELECT file_path 
            FROM documents 
            WHERE id = ? AND client_id = ?
        ");
        $stmt->execute([$documentId, $clientId]);
        $document = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$document) {
            sendResponse(false, 'Document not found');
            return;
        }
        
        // Delete from database
        $stmt = $pdo->prepare("DELETE FROM documents WHERE id = ? AND client_id = ?");
        
        if ($stmt->execute([$documentId, $clientId])) {
            // Also delete the actual file from storage
            if ($document['file_path']) {
                // Handle both absolute and relative paths
                $filePath = $document['file_path'];
                
                // If path doesn't start with uploads/, add it
                if (!str_starts_with($filePath, 'uploads/')) {
                    $filePath = 'uploads/documents/' . $filePath;
                }
                
                // Convert to absolute path
                $absolutePath = __DIR__ . '/' . $filePath;
                
                if (file_exists($absolutePath)) {
                    if (unlink($absolutePath)) {
                        error_log("Successfully deleted file: " . $absolutePath);
                    } else {
                        error_log("Failed to delete file: " . $absolutePath);
                        // Don't fail the entire operation if file deletion fails
                        // The database record is already deleted
                    }
                } else {
                    error_log("File not found: " . $absolutePath);
                }
            } else {
                error_log("File path is empty or null");
            }
            
            sendResponse(true, 'Document deleted successfully');
        } else {
            sendResponse(false, 'Failed to delete document');
        }
        
    } catch (Exception $e) {
        error_log("Error deleting document: " . $e->getMessage());
        sendResponse(false, 'Database error occurred');
    }
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
