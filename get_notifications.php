<?php
session_start();
require_once 'db_connection.php';

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 5;
$offset = ($page - 1) * $per_page;

try {
    // Count total notifications
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND type IN ('post_warning','post_deleted','post_on_hold','post_approved')");
    $stmt->execute([$user_id]);
    $total = $stmt->fetch()['count'];
    $total_pages = max(1, ceil($total / $per_page));

    // Fetch page of notifications
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? AND type IN ('post_warning','post_deleted','post_on_hold','post_approved') ORDER BY created_at DESC LIMIT ? OFFSET ?");
    $stmt->execute([$user_id, $per_page, $offset]);
    $notifications = $stmt->fetchAll();

    // Format notifications for display
    $formatted_notifications = [];
    foreach ($notifications as $notif) {
        $type = $notif['type'];
        $type_url = match($type) {
            'post_warning' => 'warnings.php?mark_read=' . $notif['id'],
            'post_deleted' => 'deleted_posts.php?mark_read=' . $notif['id'],
            'post_on_hold' => 'held_posts.php?mark_read=' . $notif['id'],
            'post_approved' => 'approved_posts.php?mark_read=' . $notif['id'],
            default => '#'
        };
        $type_class = match($type) {
            'post_warning' => 'warning',
            'post_deleted' => 'deleted',
            'post_on_hold' => 'hold',
            'post_approved' => 'approved',
            default => ''
        };
        $type_icon = match($type) {
            'post_warning' => 'bi-exclamation-triangle-fill',
            'post_deleted' => 'bi-trash-fill',
            'post_on_hold' => 'bi-pause-circle-fill',
            'post_approved' => 'bi-check-circle-fill',
            default => 'bi-bell'
        };

        // Strip embedded snapshot data from message for clean display
        $clean_msg = preg_replace('/Post content: ".*?"/s', '', $notif['message']);
        $clean_msg = preg_replace('/Post data: \{.*\}/s', '', $clean_msg);
        $clean_msg = trim(preg_replace('/\s+/', ' ', $clean_msg));

        // Format time
        $nz = new DateTimeZone('UTC');
        $nn = new DateTime('now', $nz);
        $na = new DateTime($notif['created_at'], $nz);
        $nd = $nn->diff($na);
        if ($nd->d > 0) $time_str = $nd->d . ' day' . ($nd->d > 1 ? 's' : '') . ' ago';
        elseif ($nd->h > 0) $time_str = $nd->h . ' hour' . ($nd->h > 1 ? 's' : '') . ' ago';
        elseif ($nd->i > 0) $time_str = $nd->i . ' min' . ($nd->i > 1 ? 's' : '') . ' ago';
        else $time_str = 'just now';

        $formatted_notifications[] = [
            'id' => $notif['id'],
            'type' => $type,
            'type_class' => $type_class,
            'type_icon' => $type_icon,
            'type_url' => $type_url,
            'message' => htmlspecialchars($clean_msg),
            'time' => $time_str,
            'is_read' => $notif['is_read']
        ];
    }

    // Count unread notifications
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND type IN ('post_warning','post_deleted','post_on_hold','post_approved') AND is_read = 0");
    $stmt->execute([$user_id]);
    $unread_count = $stmt->fetch()['count'];

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'notifications' => $formatted_notifications,
        'total' => $total,
        'total_pages' => $total_pages,
        'current_page' => $page,
        'unread_count' => $unread_count
    ]);
} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>