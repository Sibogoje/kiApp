<?php
// api/upload_profile_image.php
require_once 'config.php';
header('Content-Type: application/json');

// Authenticate user (implement your own logic)
// $client_id = ...;
$client_id = isset($_POST['client_id']) ? intval($_POST['client_id']) : 0;
if ($client_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid client ID']);
    exit;
}

if (!isset($_FILES['profile_image'])) {
    echo json_encode(['success' => false, 'message' => 'No image uploaded']);
    exit;
}

// Use uploads/profile/ as the folder
$target_dir = __DIR__ . '/../uploads/profile/';
if (!is_dir($target_dir)) {
    mkdir($target_dir, 0777, true);
}

$filename = basename($_FILES["profile_image"]["name"]);
$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
$allowed = ['jpg', 'jpeg', 'png', 'gif'];
if (!in_array($ext, $allowed)) {
    echo json_encode(['success' => false, 'message' => 'Invalid file type']);
    exit;
}

$new_filename = "client_{$client_id}_" . time() . ".{$ext}";
$target_file = $target_dir . $new_filename;

if (move_uploaded_file($_FILES["profile_image"]["tmp_name"], $target_file)) {
    // Save accessible URL (adjust domain/path as needed)
    $image_url = "/uploads/profile/{$new_filename}";
    $stmt = $pdo->prepare("UPDATE clients SET profile_image = ? WHERE id = ?");
    $stmt->execute([$image_url, $client_id]);
    echo json_encode(['success' => true, 'image_url' => $image_url]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to upload image']);
}
?>
