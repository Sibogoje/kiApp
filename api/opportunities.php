<?php
require_once 'config.php';

// Set CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

switch ($method) {
    case 'GET':
        if (isset($_GET['id'])) {
            getOpportunity($pdo, $_GET['id']);
        } else {
            getOpportunities($pdo);
        }
        break;
    case 'POST':
        if (isset($_GET['action']) && $_GET['action'] === 'apply') {
            applyForOpportunity($pdo, $input);
        } else {
            sendResponse(false, 'Invalid action');
        }
        break;
    default:
        sendResponse(false, 'Method not allowed');
}

function getOpportunities($pdo) {
    try {
        // Get current client ID if authenticated
        $clientId = null;
        $token = getAuthHeader();
        if ($token) {
            try {
                $clientId = validateToken($token);
            } catch (Exception $e) {
                // Token invalid, continue without authentication
            }
        }
        
        // Build query with filters - only show published opportunities
        $where = ["o.status = 'published'"];
        $params = [];
        
        if (isset($_GET['category']) && !empty($_GET['category'])) {
            $where[] = "c.name = ?";
            $params[] = $_GET['category'];
        }
        
        if (isset($_GET['type']) && !empty($_GET['type'])) {
            $where[] = "o.type = ?";
            $params[] = $_GET['type'];
        }
        
        if (isset($_GET['search']) && !empty($_GET['search'])) {
            $where[] = "(o.title LIKE ? OR o.description LIKE ? OR o.location LIKE ? OR o.company_name LIKE ?)";
            $searchTerm = '%' . $_GET['search'] . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        // Pagination
        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $limit = isset($_GET['limit']) ? max(1, min(50, intval($_GET['limit']))) : 1000;
        $offset = ($page - 1) * $limit;
        
        // Get total count
        $countSql = "SELECT COUNT(*) FROM opportunities o LEFT JOIN categories c ON o.category_id = c.id WHERE " . implode(' AND ', $where);
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $totalCount = $countStmt->fetchColumn();
        
        // Get opportunities with category name and user-specific data in one query
        if ($clientId) {
            $sql = "SELECT 
                        o.*, 
                        c.name as category_name, 
                        c.id as category_id,
                        a.status as application_status,
                        CASE WHEN a.id IS NOT NULL THEN 1 ELSE 0 END as has_applied,
                        CASE WHEN b.id IS NOT NULL THEN 1 ELSE 0 END as is_bookmarked
                    FROM opportunities o 
                    LEFT JOIN categories c ON o.category_id = c.id 
                    LEFT JOIN applications a ON o.id = a.opportunity_id AND a.client_id = ?
                    LEFT JOIN bookmarks b ON o.id = b.opportunity_id AND b.client_id = ?
                    WHERE " . implode(' AND ', $where) . " 
                    ORDER BY CASE o.priority WHEN 'urgent' THEN 4 WHEN 'high' THEN 3 WHEN 'medium' THEN 2 WHEN 'low' THEN 1 ELSE 0 END DESC, o.published_at DESC 
                    LIMIT " . $limit . " OFFSET " . $offset;
            
            // Add client ID to params (twice for applications and bookmarks JOINs)
            $queryParams = array_merge([$clientId, $clientId], $params);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($queryParams);
        } else {
            // Non-authenticated query (simpler)
            $sql = "SELECT 
                        o.*, 
                        c.name as category_name, 
                        c.id as category_id,
                        NULL as application_status,
                        0 as has_applied,
                        0 as is_bookmarked
                    FROM opportunities o 
                    LEFT JOIN categories c ON o.category_id = c.id 
                    WHERE " . implode(' AND ', $where) . " 
                    ORDER BY CASE o.priority WHEN 'urgent' THEN 4 WHEN 'high' THEN 3 WHEN 'medium' THEN 2 WHEN 'low' THEN 1 ELSE 0 END DESC, o.published_at DESC 
                    LIMIT " . $limit . " OFFSET " . $offset;
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        }
        
        $opportunities = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Convert numeric flags to booleans for consistency
        foreach ($opportunities as &$opportunity) {
            $opportunity['has_applied'] = (bool)$opportunity['has_applied'];
            $opportunity['is_bookmarked'] = (bool)$opportunity['is_bookmarked'];
        }
        
        $hasMore = ($page * $limit) < $totalCount;
        
        sendResponse(true, 'Opportunities retrieved successfully', [
            'opportunities' => $opportunities,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $totalCount,
                'has_more' => $hasMore
            ]
        ]);
        
    } catch (PDOException $e) {
        sendResponse(false, 'Failed to get opportunities: ' . $e->getMessage());
    }
}

function getOpportunity($pdo, $id) {
    try {
        // Get current client ID if authenticated
        $clientId = null;
        $token = getAuthHeader();
        if ($token) {
            try {
                $clientId = validateToken($token);
            } catch (Exception $e) {
                // Token invalid, continue without authentication
            }
        }
        
        $stmt = $pdo->prepare("
            SELECT 
                o.*,
                c.name as category_name,
                c.id as category_id
            FROM opportunities o 
            LEFT JOIN categories c ON o.category_id = c.id 
            WHERE o.id = ? AND o.status = 'published'
        ");
        $stmt->execute([$id]);
        $opportunity = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$opportunity) {
            sendResponse(false, 'Opportunity not found');
        }
        
        // Increment view count
        $viewStmt = $pdo->prepare("UPDATE opportunities SET views_count = views_count + 1 WHERE id = ?");
        $viewStmt->execute([$id]);
        
        // If client is authenticated, add application and bookmark status
        if ($clientId) {
            // Check if client has applied
            $appStmt = $pdo->prepare("SELECT status FROM applications WHERE client_id = ? AND opportunity_id = ?");
            $appStmt->execute([$clientId, $opportunity['id']]);
            $application = $appStmt->fetch(PDO::FETCH_ASSOC);
            
            $opportunity['has_applied'] = $application ? true : false;
            $opportunity['application_status'] = $application ? $application['status'] : null;
            
            // Check if bookmarked
            $bookmarkStmt = $pdo->prepare("SELECT id FROM bookmarks WHERE client_id = ? AND opportunity_id = ?");
            $bookmarkStmt->execute([$clientId, $opportunity['id']]);
            $opportunity['is_bookmarked'] = $bookmarkStmt->fetch() ? true : false;
        } else {
            // Set default values for non-authenticated users
            $opportunity['has_applied'] = false;
            $opportunity['application_status'] = null;
            $opportunity['is_bookmarked'] = false;
        }
        
        sendResponse(true, 'Opportunity retrieved successfully', ['opportunity' => $opportunity]);
        
    } catch (PDOException $e) {
        sendResponse(false, 'Failed to get opportunity: ' . $e->getMessage());
    }
}

function applyForOpportunity($pdo, $data) {
    $token = getAuthHeader();
    $clientId = validateToken($token);
    
    validateRequired($data, ['opportunity_id', 'message']);
    
    $opportunityId = intval($data['opportunity_id']);
    $message = trim($data['message']);
    $documentId = isset($data['document_id']) ? intval($data['document_id']) : null;
    
    try {
        // Check if opportunity exists and is published
        $stmt = $pdo->prepare("SELECT id, title, status FROM opportunities WHERE id = ? AND status = 'published'");
        $stmt->execute([$opportunityId]);
        $opportunity = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$opportunity) {
            sendResponse(false, 'Opportunity not found or not available');
        }
        
        // Check if already applied
        $stmt = $pdo->prepare("SELECT id FROM applications WHERE client_id = ? AND opportunity_id = ?");
        $stmt->execute([$clientId, $opportunityId]);
        
        if ($stmt->fetch()) {
            sendResponse(false, 'You have already applied for this opportunity');
        }
        
        // Verify document belongs to client if provided
        if ($documentId) {
            $docStmt = $pdo->prepare("SELECT id FROM documents WHERE id = ? AND client_id = ?");
            $docStmt->execute([$documentId, $clientId]);
            if (!$docStmt->fetch()) {
                sendResponse(false, 'Invalid document selected');
            }
        }
        
        // Insert application
        $stmt = $pdo->prepare("
            INSERT INTO applications (client_id, opportunity_id, message, document_id, status) 
            VALUES (?, ?, ?, ?, 'pending')
        ");
        $stmt->execute([$clientId, $opportunityId, $message, $documentId]);
        
        // Increment applications count
        $countStmt = $pdo->prepare("UPDATE opportunities SET applications_count = applications_count + 1 WHERE id = ?");
        $countStmt->execute([$opportunityId]);
        
        sendResponse(true, 'Application submitted successfully');
        
    } catch (PDOException $e) {
        sendResponse(false, 'Failed to submit application: ' . $e->getMessage());
    }
}
?>
