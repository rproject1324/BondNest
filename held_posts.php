<?php
session_start();
require_once 'db_connection.php';
date_default_timezone_set('Asia/Manila');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'User not logged in']);
    exit();
}

// Get user ID
$user_id = $_SESSION['user_id'];

// Mark notification as read if specified
if (isset($_GET['mark_read']) && is_numeric($_GET['mark_read'])) {
    $notification_id = intval($_GET['mark_read']);
    try {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        $stmt->execute([$notification_id, $user_id]);
    } catch (PDOException $e) {
        // Ignore errors
    }
}

// Handle "Clear All" action
if (isset($_POST['clear_all'])) {
    $stmt = $pdo->prepare("DELETE FROM notifications WHERE user_id = ? AND type = 'post_on_hold' AND is_read = 1");
    $stmt->execute([$user_id]);
    
    // Set success message
    $_SESSION['notification_message'] = "All read hold notifications have been cleared.";
    
    // Redirect to refresh the page
    header("Location: held_posts.php");
    exit();
}

// For AJAX requests - get hold notifications
if (isset($_GET['format']) && $_GET['format'] === 'json') {
    // Get unread notifications about held posts
    $stmt = $pdo->prepare("
        SELECT * FROM notifications 
        WHERE user_id = ? 
        AND type = 'post_on_hold' 
        AND is_read = 0
        ORDER BY created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$user_id]);
    
    $notifications = [];
    while ($row = $stmt->fetch()) {
        $notifications[] = [
            'id' => $row['id'],
            'message' => $row['message'],
            'reference_id' => $row['reference_id'],
            'created_at' => $row['created_at']
        ];
    }
    
    // Mark these notifications as read
    if (!empty($notifications)) {
        $notification_ids = array_map(function($notification) {
            return $notification['id'];
        }, $notifications);
        
        $placeholders = implode(',', array_fill(0, count($notification_ids), '?'));
        $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id IN ($placeholders)")->execute($notification_ids);
    }
    
    // Return JSON response
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true, 
        'notifications' => $notifications,
        'count' => count($notifications)
    ]);
    exit();
}

// For non-AJAX requests - mark notification as read and redirect
if (isset($_GET['mark_read']) && isset($_GET['id'])) {
    $notification_id = intval($_GET['id']);
    
    // Mark as read
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmt->execute([$notification_id, $user_id]);
    
    // Redirect back
    $redirect = $_GET['redirect'] ?? 'homepage.php';
    header("Location: $redirect");
    exit();
}

// Function to get time elapsed string
function time_elapsed_string($datetime, $full = false) {
    $tz = new DateTimeZone('UTC');
    $now = new DateTime('now', $tz);
    $ago = new DateTime($datetime, $tz);
    $diff = $now->diff($ago);

    $diff->w = floor($diff->d / 7);
    $diff->d -= $diff->w * 7;

    $string = array(
        'y' => 'year',
        'm' => 'month',
        'w' => 'week',
        'd' => 'day',
        'h' => 'hour',
        'i' => 'minute',
        's' => 'second',
    );
    
    foreach ($string as $k => &$v) {
        if ($diff->$k) {
            $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
        } else {
            unset($string[$k]);
        }
    }

    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' ago' : 'just now';
}

function formatManilaTime($utcDatetime) {
    if (empty($utcDatetime)) return '';
    $dt = new DateTime($utcDatetime, new DateTimeZone('UTC'));
    $dt->setTimezone(new DateTimeZone('Asia/Manila'));
    return $dt->format('F j, Y, g:i a');
}

// Count total notifications
$stmt = $pdo->prepare("
    SELECT COUNT(*) as count FROM notifications 
    WHERE user_id = ? AND type = 'post_on_hold'
");
$stmt->execute([$user_id]);
$total_count = $stmt->fetch()['count'];

// Count read notifications
$stmt = $pdo->prepare("
    SELECT COUNT(*) as count FROM notifications 
    WHERE user_id = ? AND type = 'post_on_hold' AND is_read = 1
");
$stmt->execute([$user_id]);
$read_count = $stmt->fetch()['count'];

// Get the notification detail if requested
$selected_notification = null;
$hold_data = null;
if (isset($_GET['notification_id'])) {
    $notification_id = intval($_GET['notification_id']);
    $stmt = $pdo->prepare("
        SELECT * FROM notifications 
        WHERE id = ? AND user_id = ? AND type = 'post_on_hold'
    ");
    $stmt->execute([$notification_id, $user_id]);
    if ($stmt->rowCount() > 0) {
        $selected_notification = $stmt->fetch();
        
        // Mark as read if it isn't already
        if ($selected_notification['is_read'] == 0) {
            $mark_stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?");
            $mark_stmt->execute([$notification_id]);
        }
        
        // Get the held post data from posts table using reference_id
        if (!empty($selected_notification['reference_id'])) {
            $post_id = $selected_notification['reference_id'];
            $post_stmt = $pdo->prepare("
                SELECT p.*, u.first_name, u.last_name, u.profile_picture, 
                       COUNT(DISTINCT c.id) AS comment_count
                FROM posts p 
                JOIN users u ON p.user_id = u.id 
                LEFT JOIN comments c ON p.id = c.post_id 
                WHERE p.id = ?
                GROUP BY p.id, u.first_name, u.last_name, u.profile_picture
            ");
            $post_stmt->execute([$post_id]);
            if ($post_stmt->rowCount() > 0) {
                $hold_data = $post_stmt->fetch();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Held Posts - BondNest</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="homepage.css">
    <link rel="stylesheet" href="post-status-styles.css">
    
    <style>
        /* Custom modal dimensions */
        :root {
            --modal-width: 90%;
            --modal-max-width: 1600px;
            --modal-max-height: 90vh;
            --modal-min-height: 700px;
            --modal-aspect-ratio: 16/9;
            --modal-body-padding: 40px;
        }
        
        .notifications-list {
            max-width: 800px;
            margin: 30px auto;
            background-color: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            padding: 20px;
        }
        
        /* Notification container with scrollbar */
        .notifications-container {
            max-height: 500px;
            overflow-y: auto;
            margin-bottom: 20px;
            padding-right: 10px;
            scrollbar-width: thin;
            scrollbar-color: #f59f0b #f1f1f1;
        }
        
        /* For WebKit browsers (Chrome, Safari) */
        .notifications-container::-webkit-scrollbar {
            width: 8px;
        }
        
        .notifications-container::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        
        .notifications-container::-webkit-scrollbar-thumb {
            background-color: #f59f0b;
            border-radius: 10px;
        }
        
        .page-title {
            font-size: 1.5rem;
            color: #333;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .notification-count {
            font-size: 0.9rem;
            color: #777;
            font-weight: normal;
        }
        
        .notification-card {
            background-color: rgba(245, 159, 11, 0.05);
            border-left: 4px solid #f59f0b;
            padding: 15px;
            max-width: 800px;
            margin-bottom: 15px;
            border-radius: 5px;
            position: relative;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .notification-card:hover {
            background-color: rgba(245, 159, 11, 0.1);
            transform: translateY(-2px);
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.05);
        }
        
        .notification-message {
            font-size: 0.95rem;
            line-height: 1.5;
            color: #333;
            margin-bottom: 10px;
        }
        
        .notification-time {
            font-size: 0.8rem;
            color: #777;
        }
        
        .notification-actions {
            margin-top: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .notification-buttons {
            display: flex;
            gap: 10px;
        }
        
        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background-color: #f5f5f5;
            border: none;
            padding: 8px 15px;
            border-radius: 5px;
            font-size: 0.9rem;
            cursor: pointer;
            text-decoration: none;
            color: #333;
        }
        
        .back-btn:hover {
            background-color: #e5e5e5;
        }
        
        .clear-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background-color: #fef3c7;
            border: 1px solid #fde68a;
            color: #d97706;
            padding: 8px 15px;
            border-radius: 5px;
            font-size: 0.9rem;
            cursor: pointer;
        }
        
        .clear-btn:hover {
            background-color: #fef3c7;
        }
        
        .clear-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .empty-state {
            padding: 40px;
            text-align: center;
            color: #777;
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 15px;
            color: #ddd;
        }
        
        .empty-state h3 {
            font-size: 1.2rem;
            margin-bottom: 10px;
            color: #555;
        }
        
        .notification-message {
            position: relative;
        }
        
        .view-details-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background-color: #f0f0f0;
            color: #555;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 0.8rem;
            text-decoration: none;
            margin-top: 8px;
        }
        
        .view-details-btn:hover {
            background-color: #e0e0e0;
        }
        
        /* Modal Overlay */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            display: none;
            align-items: center;
            justify-content: center;
        }
        
        .modal-overlay.active {
            display: flex;
        }
        
        /* Modal Dialog */
        .modal-dialog {
            width: 100%;
            max-width: 2500px !important;
            margin: 20px auto;
            pointer-events: auto;
        }
        
        .modal-content {
            background-color: #fff;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
            overflow: hidden;
            max-height: var(--modal-max-height, 95vh);
            min-height: var(--modal-min-height, 800px);
            display: flex;
            flex-direction: column;
            aspect-ratio: var(--modal-aspect-ratio, 16/9);
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 25px 30px;
            border-bottom: 1px solid #eee;
            background-color: #f8f9fa;
        }
        
        .modal-title {
            font-size: 1.8rem;
            font-weight: 600;
            margin: 0;
            color: #f59f0b;
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 1.8rem;
            cursor: pointer;
            color: #666;
            line-height: 1;
            padding: 10px;
        }
        
        .modal-close:hover {
            color: #333;
        }
        
        .modal-body {
            padding: var(--modal-body-padding, 50px);
            overflow-y: auto;
            flex-grow: 1;
        }
        
        .hold-banner {
            background-color: #fef3c7;
            color: #92400e;
            padding: 15px 20px;
            border-radius: 6px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
            font-size: 1.2rem;
        }
        
        .hold-reason {
            background-color: #f5f5f5;
            border-left: 3px solid #f59f0b;
            padding: 20px;
            margin-bottom: 30px;
            border-radius: 4px;
        }
        
        .reason-header {
            font-weight: 600;
            margin-bottom: 10px;
            font-size: 1.2rem;
        }
        
        .reason-text {
            line-height: 1.6;
            font-size: 1.1rem;
        }
        
        .post-display {
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 40px;
            background-color: white;
            margin-bottom: 30px;
            min-height: 400px;
        }
        
        .post-author-info {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            object-fit: cover;
        }
        
        .post-meta {
            color: #666;
            font-size: 0.85rem;
            margin-top: 2px;
        }
        
        .post-content-display {
            font-size: 1rem;
            line-height: 1.7;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }
        
        .post-media {
            margin-bottom: 20px;
            border-bottom: 1px solid #eee;
            border-radius: 10px;
            overflow: hidden;
            text-align: center;
            padding-bottom: 0;
            line-height: 0;
        }
        
        .post-media img {
            width: 100%;
            max-width: 100%;
            max-height: 600px;
            object-fit: contain;
            border-radius: 8px;
            display: block;
            margin: 0 auto;
        }
        
        .post-actions-bar {
            display: flex;
            gap: 20px;
            margin-top: 15px;
            padding-top: 10px;
        }
        
        .post-action {
            display: flex;
            align-items: center;
            gap: 6px;
            color: #666;
            font-size: 0.9rem;
            padding: 5px 10px;
            border-radius: 4px;
        }
        
        /* Heart styling */
        .bi-heart-fill {
            color: #e63946;
        }
        
        .hold-timestamp {
            text-align: center;
            color: #777;
            font-size: 0.85rem;
            margin-top: 10px;
        }
        
        /* Responsive adjustments */
        @media (max-width: 1200px) {
            :root {
                --modal-max-width: 95%;
                --modal-body-padding: 30px;
            }
        }
        
        @media (max-width: 768px) {
            :root {
                --modal-width: 95%;
                --modal-aspect-ratio: 1/1;  /* More square on mobile */
                --modal-min-height: 600px;
                --modal-body-padding: 20px;
            }
            
            .hold-reason {
                padding: 15px;
            }
            
            .post-display {
                padding: 20px;
            }
        }
        
        @media (max-width: 576px) {
            :root {
                --modal-body-padding: 15px;
            }
            
            .modal-title {
                font-size: 1.4rem;
            }
            
            .post-content-display {
                font-size: 1.1rem;
            }
        }
        
        @media (max-width: 1000px) {
            .modal-overlay {
                align-items: stretch !important;
                justify-content: stretch !important;
            }
            .modal-dialog {
                width: 100vw !important;
                max-width: 100vw !important;
                height: 100vh !important;
                max-height: 100vh !important;
                margin: 0 !important;
                border-radius: 0 !important;
                display: flex;
                flex-direction: column;
            }
            .modal-content {
                width: 100vw !important;
                height: 100vh !important;
                max-width: 100vw !important;
                max-height: 100vh !important;
                min-height: 100vh !important;
                border-radius: 0 !important;
                aspect-ratio: unset !important;
                padding: 0 !important;
                display: flex;
                flex-direction: column;
            }
            .modal-header, .modal-body {
                padding-left: 10px !important;
                padding-right: 10px !important;
            }
            .modal-header {
                padding-top: 15px !important;
                padding-bottom: 15px !important;
            }
            .modal-body {
                padding-top: 10px !important;
                padding-bottom: 10px !important;
                overflow-y: auto !important;
                flex-grow: 1;
            }
        }
        @media (max-width: 600px) {
            .modal-header, .modal-body {
                padding-left: 5px !important;
                padding-right: 5px !important;
            }
            .modal-title {
                font-size: 1.1rem !important;
            }
        }
    </style>
</head>
<body>
    <!-- Include navbar -->
    <?php include 'navbar.php'; ?>
    
    <!-- Success message notification -->
    <?php if (isset($_SESSION['notification_message'])): ?>
    <div class="notification-message" id="notification-message">
        <?php echo $_SESSION['notification_message']; ?>
    </div>
    <?php unset($_SESSION['notification_message']); ?>
    <?php endif; ?>
    
    <div class="notifications-list">
        <h2 class="page-title">
            Held Posts Notifications
            <span class="notification-count"><?php echo $total_count; ?> notifications</span>
        </h2>
        
        <div class="notifications-container">
            <?php
            // Get hold notifications
            $stmt = $pdo->prepare("
                SELECT * FROM notifications 
                WHERE user_id = ? 
                AND type = 'post_on_hold' 
                ORDER BY created_at DESC
                LIMIT 25
            ");
            $stmt->execute([$user_id]);
            
            $has_notifications = false;
            
            if ($stmt->rowCount() > 0) {
                $has_notifications = true;
                while ($notification = $stmt->fetch()) {
                    // Extract the post content from the message if possible
                    $post_content = "";
                    if (preg_match('/Post content: "(.*?)"/s', $notification['message'], $matches)) {
                        $post_content = $matches[1];
                    }
                    ?>
                    <div class="notification-card" onclick="showNotificationDetails(<?php echo $notification['id']; ?>)">
                        <div class="notification-message">
                            <i class="bi bi-pause-circle-fill" style="color: #f59f0b;"></i>
                            <?php 
                            // Display message without the post content part for cleaner display
                            $display_message = preg_replace('/Post content: ".*?"/s', '', $notification['message']);
                            // Also hide any JSON data
                            $display_message = preg_replace('/Post data: \{.*\}/s', '', $display_message);
                            echo htmlspecialchars($display_message); 
                            ?>
                            <a href="?notification_id=<?php echo $notification['id']; ?>" class="view-details-btn">
                                <i class="bi bi-eye"></i> View Details
                            </a>
                        </div>
                        <div class="notification-time">
                            <?php echo time_elapsed_string($notification['created_at']); ?>
                        </div>
                    </div>
                    <?php
                }
            } else {
                ?>
                <div class="empty-state">
                    <i class="bi bi-inbox"></i>
                    <h3>No Hold Notifications</h3>
                    <p>When administrators place your posts on hold, notifications will appear here.</p>
                </div>
                <?php
            }
            ?>
        </div>
        
        <div class="notification-actions">
            <a href="homepage.php" class="back-btn">
                <i class="bi bi-arrow-left"></i> Back to Home
            </a>
            
            <div class="notification-buttons">
                <?php if ($read_count > 0): ?>
                <form method="post" action="held_posts.php" onsubmit="return confirm('Are you sure you want to clear all read notifications?');">
                    <button type="submit" name="clear_all" class="clear-btn">
                        <i class="bi bi-trash"></i> Clear All Read Notifications
                    </button>
                </form>
                <?php else: ?>
                <button disabled class="clear-btn">
                    <i class="bi bi-trash"></i> Clear All Read Notifications
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Notification Detail Modal -->
    <div class="modal-overlay" id="detailModal">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">Your Post Hold Details</h3>
                    <button class="modal-close" onclick="hideModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <?php if ($selected_notification): ?>
                        <?php
                        // Debug output to see what's in the notification message
                        $debug_message = htmlspecialchars($selected_notification['message']);
                        
                        // Extract the hold reason
                        $reason = "";
                        if (preg_match('/Reason: (.*?)(?=Post content:|$)/s', $selected_notification['message'], $reason_matches)) {
                            $reason = trim($reason_matches[1]);
                        }
                        
                        // Extract the post content from the message
                        $post_content = "";
                        if (preg_match('/Post content: "(.*?)"/s', $selected_notification['message'], $matches)) {
                            $post_content = $matches[1];
                        } else {
                            // Alternative pattern if the first one fails
                            if (preg_match('/Post content: (.*?)(?=Post data:|$)/s', $selected_notification['message'], $matches)) {
                                $post_content = trim($matches[1]);
                            }
                        }
                        
                        // Extract post data JSON if available
                        $post_data = null;
                        
                        // More aggressive pattern to extract JSON
                        if (preg_match('/Post data: (\{.*)/s', $selected_notification['message'], $data_matches)) {
                            $json_string = $data_matches[1];
                            // Clean up the JSON string
                            $json_string = trim(preg_replace('/[^\{]*(\{.*\})[^\}]*/', '$1', $json_string));
                            $post_data = json_decode($json_string, true);
                            
                            // Fallback if json_decode fails
                            if (json_last_error() !== JSON_ERROR_NONE) {
                                // Try to manually extract key values
                                preg_match('/\"image_path\":\"(.*?)\"/s', $json_string, $img_match);
                                preg_match('/\"likes\":(.*?),/s', $json_string, $likes_match);
                                preg_match('/\"comment_count\":(.*?),/s', $json_string, $comments_match);
                                preg_match('/\"profile_picture\":\"(.*?)\"/s', $json_string, $pfp_match);
                                preg_match('/\"first_name\":\"(.*?)\"/s', $json_string, $fname_match);
                                preg_match('/\"last_name\":\"(.*?)\"/s', $json_string, $lname_match);
                                
                                $post_data = [
                                    'image_path' => $img_match[1] ?? '',
                                    'likes' => (int)($likes_match[1] ?? 0),
                                    'comment_count' => (int)($comments_match[1] ?? 0),
                                    'profile_picture' => $pfp_match[1] ?? './web-images/default_profile.png',
                                    'first_name' => $fname_match[1] ?? 'Your',
                                    'last_name' => $lname_match[1] ?? 'Post'
                                ];
                            }
                        }
                        
                        // Last resort - check if we can find the JSON directly
                        if (!$post_data && strpos($selected_notification['message'], '{') !== false && strpos($selected_notification['message'], '}') !== false) {
                            $start = strpos($selected_notification['message'], '{');
                            $end = strrpos($selected_notification['message'], '}');
                            if ($start !== false && $end !== false && $end > $start) {
                                $json_string = substr($selected_notification['message'], $start, $end - $start + 1);
                                $post_data = json_decode($json_string, true);
                            }
                        }
                        
                        // If still no data, create a basic structure
                        if (!$post_data) {
                            $post_data = [
                                'image_path' => '',
                                'likes' => 0,
                                'comment_count' => 0,
                                'profile_picture' => './web-images/default_profile.png',
                                'first_name' => 'Your',
                                'last_name' => 'Post'
                            ];
                        }
                        
                        // Debug data
                        $debug_data = [
                            'post_content_found' => !empty($post_content),
                            'post_data_found' => $post_data !== null,
                            'json_error' => json_last_error_msg(),
                            'extracted_data' => $post_data
                        ];
                        ?>
                        
                        <!-- Hidden debug info that can be viewed in page source -->
                        <!-- DEBUG: <?php echo json_encode(['message' => $debug_message, 'data' => $debug_data]); ?> -->
                        
                        <!-- Hold banner -->
                        <div class="hold-banner">
                            <i class="bi bi-pause-circle-fill"></i>
                            <span>This post has been placed on hold by an administrator</span>
                        </div>
                        
                        <!-- Hold reason if available -->
                        <?php
                        // Get reason from hold data if available, otherwise parse from notification
                        $hold_reason = $hold_data && !empty($hold_data['hold_reason'])
                            ? $hold_data['hold_reason']
                            : $reason;
                        
                        if (!empty($hold_reason)):
                        ?>
                        <div class="hold-reason">
                            <div class="reason-header">Reason for Hold:</div>
                            <div class="reason-text"><?php echo htmlspecialchars($hold_reason); ?></div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Begin post structure -->
                        <div class="post-display">
                            <div class="post-author-info">
                                <?php
                                if ($hold_data) {
                                    // Use data from posts table joined with users
                                    $has_profile_pic = !empty($hold_data['profile_picture']);
                                    $profile_pic = $has_profile_pic
                                        ? htmlspecialchars($hold_data['profile_picture'])
                                        : '';
                                    
                                    $author_first = $hold_data['first_name'] ?? '';
                                    $author_last = $hold_data['last_name'] ?? '';
                                    $author_name = !empty($author_first) && !empty($author_last)
                                        ? htmlspecialchars($author_first . ' ' . $author_last)
                                        : 'Your Post';
                                    
                                    $post_content = $hold_data['content'] ?? '';
                                    $post_image = $hold_data['image_path'] ?? '';
                                    $post_likes = $hold_data['likes'] ?? 0;
                                    $post_comments = $hold_data['comment_count'] ?? 0;
                                    $post_created = $hold_data['created_at'] ?? $selected_notification['created_at'];
                                    $post_held = $selected_notification['created_at'];
                                } else {
                                    // Fallback to old method of parsing from notification message
                                    $has_profile_pic = isset($post_data['profile_picture']) && !empty($post_data['profile_picture']);
                                    $profile_pic = $has_profile_pic
                                        ? htmlspecialchars($post_data['profile_picture'])
                                        : '';
                                    
                                    $author_first = $post_data['first_name'] ?? '';
                                    $author_last = $post_data['last_name'] ?? '';
                                    $author_name = '';
                                    if (isset($post_data['first_name']) && isset($post_data['last_name'])) {
                                        $author_name = htmlspecialchars($post_data['first_name'] . ' ' . $post_data['last_name']);
                                    }
                                    if (empty(trim($author_name))) {
                                        $author_name = 'Your Post';
                                    }
                                    
                                    $post_image = $post_data['image_path'] ?? '';
                                    $post_likes = $post_data['likes'] ?? 0;
                                    $post_comments = $post_data['comment_count'] ?? 0;
                                    $post_created = $selected_notification['created_at'];
                                    $post_held = $selected_notification['created_at'];
                                }
                                ?>
                                <?php if ($has_profile_pic): ?>
                                    <img src="<?php echo $profile_pic; ?>" alt="Profile Picture" class="avatar">
                                <?php else: ?>
                                    <?php echo getInitialsHtml($author_first ?? '', $author_last ?? '', 48); ?>
                                <?php endif; ?>
                                <div>
                                    <strong><?php echo $author_name; ?></strong>
                                    <div class="post-meta">
                                        <span><i class="bi bi-clock"></i> <?php echo formatManilaTime($post_created); ?></span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="post-content-display">
                                <?php
                                // Use the content from posts table if available, otherwise use the parsed content
                                $display_content = $hold_data ? $hold_data['content'] : $post_content;
                                
                                if (!empty($display_content)):
                                ?>
                                    <?php echo nl2br(htmlspecialchars($display_content)); ?>
                                <?php else: ?>
                                    <em>No content available</em>
                                <?php endif; ?>
                            </div>
                            
                            <?php
                            // Check for image in posts table first, then fall back to parsed data
                            $image_src = $hold_data && !empty($hold_data['image_path'])
                                ? $hold_data['image_path']
                                : ($post_data['image_path'] ?? '');
                            
                            if (!empty($image_src)):
                            ?>
                            <div class="post-media">
                                <img src="<?php echo htmlspecialchars($image_src); ?>" alt="Post image">
                            </div>
                            <?php endif; ?>
                            
                            <div class="post-actions-bar">
                                <div class="post-action">
                                    <?php
                                    // Get like count
                                    $likes_count = $hold_data ? intval($hold_data['likes']) : (isset($post_data['likes']) ? intval($post_data['likes']) : 0);
                                    
                                    // Display filled heart icon if there are likes, empty heart if zero
                                    if ($likes_count > 0):
                                    ?>
                                        <i class="bi bi-heart-fill"></i>
                                    <?php else: ?>
                                        <i class="bi bi-heart"></i>
                                    <?php endif; ?>
                                    Like
                                    <small class="text-muted" style="margin-left: 5px;">
                                        <?php echo $likes_count; ?>
                                    </small>
                                </div>
                                <div class="post-action">
                                    <i class="bi bi-chat"></i> Comment
                                    <small class="text-muted" style="margin-left: 5px;">
                                        <?php echo $hold_data ? intval($hold_data['comment_count']) : (isset($post_data['comment_count']) ? intval($post_data['comment_count']) : '0'); ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="hold-timestamp">
                            <i class="bi bi-clock-history"></i>
                             Post placed on hold <?php echo time_elapsed_string($selected_notification['created_at'], true); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <script>
    // Show modal if notification_id is in URL
    document.addEventListener('DOMContentLoaded', function() {
        const notificationMessage = document.getElementById('notification-message');
        if (notificationMessage) {
            // Show the notification
            setTimeout(() => {
                notificationMessage.classList.add('show');
            }, 100);
            
            // Auto hide after 3 seconds
            setTimeout(() => {
                notificationMessage.classList.remove('show');
                setTimeout(() => {
                    if (notificationMessage.parentNode) {
                        notificationMessage.parentNode.removeChild(notificationMessage);
                    }
                }, 300);
            }, 3000);
        }
        
        // Show modal if notification_id is in URL
        <?php if ($selected_notification): ?>
        const detailModal = document.getElementById('detailModal');
        if (detailModal) {
            detailModal.classList.add('active');
        }
        <?php endif; ?>
    });
    
    // Function to show notification details modal
    function showNotificationDetails(notificationId) {
        // Redirect to the same page with notification_id parameter
        window.location.href = 'held_posts.php?notification_id=' + notificationId;
    }
    
    // Function to hide modal
    function hideModal() {
        const detailModal = document.getElementById('detailModal');
        if (detailModal) {
            detailModal.classList.remove('active');
            // Remove the notification_id from URL
            history.replaceState({}, document.title, 'held_posts.php');
        }
    }
    
    // Close modal when clicking outside
    document.addEventListener('click', function(e) {
        const detailModal = document.getElementById('detailModal');
        if (detailModal && e.target === detailModal) {
            hideModal();
        }
    });
    </script>
</body>
</html>
