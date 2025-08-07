
<?php
// Direct database access for applications - Stateless API
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

// ...existing code...
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
    exit();
}

error_log("DirectApplications: Using stateless client_id = $clientId");

switch ($method) {
    case 'GET':
        getApplications($pdo, $clientId);
        break;
    case 'POST':
        submitApplication($pdo, $clientId, $input);
        break;
    case 'PUT':
        updateApplication($pdo, $clientId, $input);
        break;
    case 'DELETE':
        deleteApplication($pdo, $clientId, $_GET);
        break;
    default:
        sendResponse(false, 'Method not allowed');
}

function getApplications($pdo, $clientId) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                a.id,
                a.opportunity_id,
                a.status,
                a.message,
                a.document_id,
                a.applied_at,
                o.title as opportunity_title,
                o.company_name as opportunity_company,
                o.description as opportunity_description,
                o.location as opportunity_location,
                c.name as opportunity_category
            FROM applications a
            JOIN opportunities o ON a.opportunity_id = o.id
            LEFT JOIN categories c ON o.category_id = c.id
            WHERE a.client_id = ?
            ORDER BY a.applied_at DESC
        ");
        
        $stmt->execute([$clientId]);
        $applications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        sendResponse(true, 'Applications retrieved successfully', $applications);
    } catch (Exception $e) {
        sendResponse(false, 'Error retrieving applications: ' . $e->getMessage());
    }
}

function submitApplication($pdo, $clientId, $input) {
    try {
        if (!isset($input['opportunity_id'])) {
            sendResponse(false, 'Opportunity ID is required');
            return;
        }
        
        $opportunityId = $input['opportunity_id'];
        $message = $input['message'] ?? '';
        $status = $input['status'] ?? 'pending';
        $documentId = $input['document_id'] ?? null;
        
        // Check if application already exists
        $stmt = $pdo->prepare("SELECT id FROM applications WHERE client_id = ? AND opportunity_id = ?");
        $stmt->execute([$clientId, $opportunityId]);
        
        if ($stmt->fetch()) {
            sendResponse(false, 'Application already submitted for this opportunity');
            return;
        }
        
        // Add application
        $stmt = $pdo->prepare("
            INSERT INTO applications (client_id, opportunity_id, status, message, document_id, applied_at) 
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$clientId, $opportunityId, $status, $message, $documentId]);
        
        sendResponse(true, 'Application submitted successfully');
    } catch (Exception $e) {
        sendResponse(false, 'Error submitting application: ' . $e->getMessage());
    }
}

function updateApplication($pdo, $clientId, $input) {
    try {
        if (!isset($input['application_id'])) {
            sendResponse(false, 'Application ID is required');
            return;
        }
        
        $applicationId = $input['application_id'];
        $status = $input['status'] ?? null;
        $message = $input['message'] ?? null;
        $documentId = $input['document_id'] ?? null;
        
        $updates = [];
        $params = [];
        
        if ($status !== null) {
            $updates[] = "status = ?";
            $params[] = $status;
        }
        
        if ($message !== null) {
            $updates[] = "message = ?";
            $params[] = $message;
        }
        
        if ($documentId !== null) {
            $updates[] = "document_id = ?";
            $params[] = $documentId;
        }
        
        if (empty($updates)) {
            sendResponse(false, 'No fields to update');
            return;
        }
        
        $params[] = $applicationId;
        $params[] = $clientId;
        
        $sql = "UPDATE applications SET " . implode(', ', $updates) . " WHERE id = ? AND client_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        if ($stmt->rowCount() > 0) {
            sendResponse(true, 'Application updated successfully');
        } else {
            sendResponse(false, 'Application not found');
        }
    } catch (Exception $e) {
        sendResponse(false, 'Error updating application: ' . $e->getMessage());
    }
}

function deleteApplication($pdo, $clientId, $params) {
    try {
        if (!isset($params['application_id'])) {
            sendResponse(false, 'Application ID is required');
            return;
        }
        
        $stmt = $pdo->prepare("DELETE FROM applications WHERE id = ? AND client_id = ?");
        $stmt->execute([$params['application_id'], $clientId]);
        
        if ($stmt->rowCount() > 0) {
            sendResponse(true, 'Application deleted successfully');
        } else {
            sendResponse(false, 'Application not found');
        }
    } catch (Exception $e) {
        sendResponse(false, 'Error deleting application: ' . $e->getMessage());
    }
}
?>
