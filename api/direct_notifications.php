<?php
// Direct database access for notifications - Stateless API
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

error_log("DirectNotifications: Using stateless client_id = $clientId");

switch ($method) {
    case 'GET':
        getNotifications($pdo, $clientId);
        break;
    case 'POST':
        if (isset($input['action'])) {
            switch ($input['action']) {
                case 'mark_read':
                    markNotificationRead($pdo, $clientId, $input);
                    break;
                case 'mark_all_read':
                    markAllNotificationsRead($pdo, $clientId);
                    break;
                default:
                    sendResponse(false, 'Invalid action');
            }
        } else {
            sendResponse(false, 'Action is required');
        }
        break;
    case 'DELETE':
        deleteNotification($pdo, $clientId, $_GET);
        break;
    default:
        sendResponse(false, 'Method not allowed');
}

function getNotifications($pdo, $clientId) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                id,
                title,
                message,
                type,
                is_read,
                created_at,
                data
            FROM notifications 
            WHERE client_id = ?
            ORDER BY created_at DESC
        ");
        
        $stmt->execute([$clientId]);
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Parse JSON data if it exists
        foreach ($notifications as &$notification) {
            if (!empty($notification['data'])) {
                $notification['data'] = json_decode($notification['data'], true);
            }
        }
        
        sendResponse(true, 'Notifications retrieved successfully', $notifications);
    } catch (Exception $e) {
        sendResponse(false, 'Error retrieving notifications: ' . $e->getMessage());
    }
}

function markNotificationRead($pdo, $clientId, $input) {
    try {
        if (!isset($input['notification_id'])) {
            sendResponse(false, 'Notification ID is required');
            return;
        }
        
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND client_id = ?");
        $stmt->execute([$input['notification_id'], $clientId]);
        
        if ($stmt->rowCount() > 0) {
            sendResponse(true, 'Notification marked as read');
        } else {
            sendResponse(false, 'Notification not found');
        }
    } catch (Exception $e) {
        sendResponse(false, 'Error marking notification as read: ' . $e->getMessage());
    }
}

function markAllNotificationsRead($pdo, $clientId) {
    try {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE client_id = ? AND is_read = 0");
        $stmt->execute([$clientId]);
        
        sendResponse(true, 'All notifications marked as read');
    } catch (Exception $e) {
        sendResponse(false, 'Error marking all notifications as read: ' . $e->getMessage());
    }
}

function deleteNotification($pdo, $clientId, $params) {
    try {
        if (!isset($params['notification_id'])) {
            sendResponse(false, 'Notification ID is required');
            return;
        }
        
        $stmt = $pdo->prepare("DELETE FROM notifications WHERE id = ? AND client_id = ?");
        $stmt->execute([$params['notification_id'], $clientId]);
        
        if ($stmt->rowCount() > 0) {
            sendResponse(true, 'Notification deleted successfully');
        } else {
            sendResponse(false, 'Notification not found');
        }
    } catch (Exception $e) {
        sendResponse(false, 'Error deleting notification: ' . $e->getMessage());
    }
}
?>
