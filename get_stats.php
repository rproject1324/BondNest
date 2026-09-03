<?php
session_start();

require_once 'db_connection.php';

date_default_timezone_set('Asia/Manila');

// Allow both admin and regular users
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];
$is_admin = isset($_SESSION['is_admin']) && $_SESSION['is_admin'];

// Get notification counts for current user
$notification_count = 0;
if ($user_id) {
    try {
        // Count unread warning notifications
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND type = 'post_warning' AND is_read = 0");
        $stmt->execute([$user_id]);
        $warning_count = $stmt->fetch()['count'];

        // Count unread deleted post notifications
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND type = 'post_deleted' AND is_read = 0");
        $stmt->execute([$user_id]);
        $deleted_count = $stmt->fetch()['count'];

        // Count unread held post notifications
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND type = 'post_on_hold' AND is_read = 0");
        $stmt->execute([$user_id]);
        $hold_count = $stmt->fetch()['count'];

        // Count unread approved post notifications
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND type = 'post_approved' AND is_read = 0");
        $stmt->execute([$user_id]);
        $approved_count = $stmt->fetch()['count'];

        // Total notification count
        $notification_count = $warning_count + $deleted_count + $hold_count + $approved_count;
    } catch (PDOException $e) {
        $notification_count = 0;
    }
}

// If not admin, just return notification count
if (!$is_admin) {
    header('Content-Type: application/json');
    echo json_encode(['notification_count' => $notification_count]);
    exit();
}



// Get current stats
$stats = [];
$current_stats_stmt = $pdo->query("
    SELECT 
        (SELECT COUNT(*) FROM users) as total_users,
        (SELECT COUNT(*) FROM posts) as total_posts,
        (SELECT COUNT(*) FROM posts WHERE DATE(created_at) = CURRENT_DATE) as today_posts,
        (SELECT COUNT(*) FROM admin_actions WHERE DATE(created_at) = CURRENT_DATE) as today_actions
");
if ($current_stats_stmt) {
    $stats = $current_stats_stmt->fetch(PDO::FETCH_ASSOC);
}

// Get stats from last month for comparison
$last_month_stats = [];
$last_month_stats_stmt = $pdo->query("
    SELECT 
        (SELECT COUNT(*) FROM users WHERE DATE(created_at) < CURRENT_DATE - INTERVAL '30 day') as last_month_users,
        (SELECT COUNT(*) FROM posts WHERE DATE(created_at) < CURRENT_DATE - INTERVAL '30 day') as last_month_posts,
        (SELECT COUNT(*) FROM posts WHERE DATE(created_at) = CURRENT_DATE - INTERVAL '1 day') as yesterday_posts,
        (SELECT COUNT(*) FROM admin_actions WHERE DATE(created_at) = CURRENT_DATE - INTERVAL '1 day') as yesterday_actions
");
if ($last_month_stats_stmt) {
    $last_month_stats = $last_month_stats_stmt->fetch(PDO::FETCH_ASSOC);
}

// Calculate percentage changes
$percent_changes = [];

// Users change (from last month to now)
$users_now = $stats['total_users'] ?? 0;
$users_last_month = $last_month_stats['last_month_users'] ?? 0;
$users_new = $users_now - $users_last_month;
$percent_changes['users'] = ($users_last_month > 0) ? round(($users_new / $users_last_month) * 100) : 0;

// Posts change (from last month to now)
$posts_now = $stats['total_posts'] ?? 0;
$posts_last_month = $last_month_stats['last_month_posts'] ?? 0;
$posts_new = $posts_now - $posts_last_month;
$percent_changes['posts'] = ($posts_last_month > 0) ? round(($posts_new / $posts_last_month) * 100) : 0;

// Today's posts vs yesterday
$today_posts = $stats['today_posts'] ?? 0;
$yesterday_posts = $last_month_stats['yesterday_posts'] ?? 0;
$percent_changes['today_posts'] = ($yesterday_posts > 0) ? round((($today_posts - $yesterday_posts) / $yesterday_posts) * 100) : 0;

// Today's actions vs yesterday
$today_actions = $stats['today_actions'] ?? 0;
$yesterday_actions = $last_month_stats['yesterday_actions'] ?? 0;
$percent_changes['today_actions'] = ($yesterday_actions > 0) ? round((($today_actions - $yesterday_actions) / $yesterday_actions) * 100) : 0;

// Prepare the response
$response = [
    'stats' => $stats,
    'percent_changes' => $percent_changes,
    'notification_count' => $notification_count
];

// Send response as JSON
header('Content-Type: application/json');
echo json_encode($response); 
