<?php
session_start();
require_once 'db_connection.php';

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['notification_ids']) || !is_array($input['notification_ids'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

$notification_ids = array_map('intval', $input['notification_ids']);
$user_id = $_SESSION['user_id'];

if (empty($notification_ids)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

try {
    $placeholders = implode(',', array_fill(0, count($notification_ids), '?'));
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id IN ($placeholders) AND user_id = ?");
    $stmt->execute(array_merge($notification_ids, [$user_id]));
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>