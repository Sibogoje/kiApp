<?php
// Direct database access for bookmarks - Stateless API
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
    exit();
}

error_log("DirectBookmarks: Using stateless client_id = $clientId");

switch ($method) {
    case 'GET':
        getBookmarkedOpportunities($pdo, $clientId);
        break;
    case 'POST':
        if (isset($input['action']) && $input['action'] === 'toggle') {
            toggleBookmark($pdo, $clientId, $input);
        } else {
            addBookmark($pdo, $clientId, $input);
        }
        break;
    case 'DELETE':
        removeBookmark($pdo, $clientId, $_GET);
        break;
    default:
        sendResponse(false, 'Method not allowed');
}

function getBookmarkedOpportunities($pdo, $clientId) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                b.id,
                b.opportunity_id,
                b.created_at,
                o.title as opportunity_title,
                o.description as opportunity_description,
                o.company_name as opportunity_company,
                o.location as opportunity_location,
                c.name as opportunity_category
            FROM bookmarks b
            JOIN opportunities o ON b.opportunity_id = o.id
            LEFT JOIN categories c ON o.category_id = c.id
            WHERE b.client_id = ?
            ORDER BY b.created_at DESC
        ");
        
        $stmt->execute([$clientId]);
        $bookmarks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        sendResponse(true, 'Bookmarks retrieved successfully', $bookmarks);
    } catch (Exception $e) {
        sendResponse(false, 'Error retrieving bookmarks: ' . $e->getMessage());
    }
}

function toggleBookmark($pdo, $clientId, $input) {
    try {
        if (!isset($input['opportunity_id'])) {
            sendResponse(false, 'Opportunity ID is required');
            return;
        }
        
        $opportunityId = $input['opportunity_id'];
        
        // Check if bookmark exists
        $stmt = $pdo->prepare("SELECT id FROM bookmarks WHERE client_id = ? AND opportunity_id = ?");
        $stmt->execute([$clientId, $opportunityId]);
        $bookmark = $stmt->fetch();
        
        if ($bookmark) {
            // Remove bookmark
            $stmt = $pdo->prepare("DELETE FROM bookmarks WHERE client_id = ? AND opportunity_id = ?");
            $stmt->execute([$clientId, $opportunityId]);
            sendResponse(true, 'Bookmark removed successfully');
        } else {
            // Add bookmark
            $stmt = $pdo->prepare("INSERT INTO bookmarks (client_id, opportunity_id, created_at) VALUES (?, ?, NOW())");
            $stmt->execute([$clientId, $opportunityId]);
            sendResponse(true, 'Bookmark added successfully');
        }
    } catch (Exception $e) {
        sendResponse(false, 'Error toggling bookmark: ' . $e->getMessage());
    }
}

function addBookmark($pdo, $clientId, $input) {
    try {
        if (!isset($input['opportunity_id'])) {
            sendResponse(false, 'Opportunity ID is required');
            return;
        }
        
        $opportunityId = $input['opportunity_id'];
        
        // Check if bookmark already exists
        $stmt = $pdo->prepare("SELECT id FROM bookmarks WHERE client_id = ? AND opportunity_id = ?");
        $stmt->execute([$clientId, $opportunityId]);
        
        if ($stmt->fetch()) {
            sendResponse(false, 'Opportunity already bookmarked');
            return;
        }
        
        // Add bookmark
        $stmt = $pdo->prepare("INSERT INTO bookmarks (client_id, opportunity_id, created_at) VALUES (?, ?, NOW())");
        $stmt->execute([$clientId, $opportunityId]);
        
        sendResponse(true, 'Bookmark added successfully');
    } catch (Exception $e) {
        sendResponse(false, 'Error adding bookmark: ' . $e->getMessage());
    }
}

function removeBookmark($pdo, $clientId, $params) {
    try {
        if (!isset($params['opportunity_id']) && !isset($params['bookmark_id'])) {
            sendResponse(false, 'Opportunity ID or Bookmark ID is required');
            return;
        }
        
        if (isset($params['opportunity_id'])) {
            $stmt = $pdo->prepare("DELETE FROM bookmarks WHERE client_id = ? AND opportunity_id = ?");
            $stmt->execute([$clientId, $params['opportunity_id']]);
        } else {
            $stmt = $pdo->prepare("DELETE FROM bookmarks WHERE client_id = ? AND id = ?");
            $stmt->execute([$clientId, $params['bookmark_id']]);
        }
        
        sendResponse(true, 'Bookmark removed successfully');
    } catch (Exception $e) {
        sendResponse(false, 'Error removing bookmark: ' . $e->getMessage());
    }
}
?>
