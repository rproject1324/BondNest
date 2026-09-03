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
    $stmt = $pdo->prepare("DELETE FROM notifications WHERE user_id = ? AND type = 'post_approved' AND is_read = 1");
    $stmt->execute([$user_id]);
    
    // Set success message
    $_SESSION['notification_message'] = "All read notifications have been cleared.";
    
    // Redirect to refresh the page
    header("Location: approved_posts.php");
    exit();
}

// For AJAX requests - get approved post notifications
if (isset($_GET['format']) && $_GET['format'] === 'json') {
    // Get unread notifications about approved posts
    $stmt = $pdo->prepare("
        SELECT * FROM notifications 
        WHERE user_id = ? 
        AND type = 'post_approved' 
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
    WHERE user_id = ? AND type = 'post_approved'
");
$stmt->execute([$user_id]);
$total_count = $stmt->fetch()['count'];

// Count read notifications
$stmt = $pdo->prepare("
    SELECT COUNT(*) as count FROM notifications 
    WHERE user_id = ? AND type = 'post_approved' AND is_read = 1
");
$stmt->execute([$user_id]);
$read_count = $stmt->fetch()['count'];

// Get the notification detail if requested
$selected_notification = null;
$approved_post_data = null;
if (isset($_GET['notification_id'])) {
    $notification_id = intval($_GET['notification_id']);
    $stmt = $pdo->prepare("
        SELECT * FROM notifications 
        WHERE id = ? AND user_id = ? AND type = 'post_approved'
    ");
    $stmt->execute([$notification_id, $user_id]);
    if ($stmt->rowCount() > 0) {
        $selected_notification = $stmt->fetch();
        
        // Mark as read if it isn't already
        if ($selected_notification['is_read'] == 0) {
            $mark_stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?");
            $mark_stmt->execute([$notification_id]);
        }
        
        // Try to get the approved post data from the posts table
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
                $approved_post_data = $post_stmt->fetch();
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
    <title>Approved Posts - BondNest</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="homepage.css">
    <link rel="stylesheet" href="post-status-styles.css">
    
    <style>
        /* Custom modal dimensions - ADJUST THESE VALUES TO CHANGE THE CONTAINER SIZE */
        :root {
            --modal-width: 90%;            /* Width of the modal dialog (percentage of viewport) */
            --modal-max-width: 1600px;     /* Maximum width of the modal in pixels */
            --modal-max-height: 90vh;      /* Maximum height (viewport height percentage) */
            --modal-min-height: 700px;     /* Minimum height in pixels */
            --modal-aspect-ratio: 16/9;    /* Aspect ratio (width/height) */
            --modal-body-padding: 40px;    /* Padding inside the modal body */
        }
        
        /* For a more square-like container, change aspect-ratio to 4/3 or 1/1 */
        /* For a taller container, decrease min-height */
        /* For a wider container, increase max-width */
        
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
            /* Custom scrollbar styling */
            scrollbar-width: thin;
            scrollbar-color: #28a745 #f1f1f1;
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
            background-color: #28a745;
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
            background-color: rgba(40, 167, 69, 0.05);
            border-left: 4px solid #28a745;
            padding: 15px;
            max-width: 800px;
            margin-bottom: 15px;
            border-radius: 5px;
            position: relative;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .notification-card:hover {
            background-color: rgba(40, 167, 69, 0.1);
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
            background-color: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: #16a34a;
            padding: 8px 15px;
            border-radius: 5px;
            font-size: 0.9rem;
            cursor: pointer;
        }
        
        .clear-btn:hover {
            background-color: #dcfce7;
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
            color: #1a7f37;
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
        
        .approval-banner {
            background-color: #f0fdf4;
            color: #1a7f37;
            padding: 15px 20px;
            border-radius: 6px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
            font-size: 1.2rem;
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
        
        .approval-timestamp {
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
        
        /* 
        ** CUSTOM DIMENSIONS OVERRIDE **
        ** Uncomment and modify these values to customize the modal size **
        
        .modal-dialog {
            --modal-width: 85%;
            --modal-max-width: 1800px;
        }
        
        .modal-content {
            --modal-min-height: 750px;
            --modal-max-height: 85vh;
            --modal-aspect-ratio: 16/10;
        }
        
        .modal-body {
            --modal-body-padding: 35px;
        }
        */
        
        @media (max-width: 768px) {
            :root {
                --modal-width: 95%;
                --modal-aspect-ratio: 1/1;  /* More square on mobile */
                --modal-min-height: 600px;
                --modal-body-padding: 20px;
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
            Approved Posts Notifications
            <span class="notification-count"><?php echo $total_count; ?> notifications</span>
        </h2>
        
        <div class="notifications-container">
            <?php
            // Get approved post notifications
            $stmt = $pdo->prepare("
                SELECT * FROM notifications 
                WHERE user_id = ? 
                AND type = 'post_approved' 
                ORDER BY created_at DESC
                LIMIT 25
            ");
            $stmt->execute([$user_id]);
            
            $has_notifications = false;
            
            if ($stmt->rowCount() > 0) {
                $has_notifications = true;
                while ($notification = $stmt->fetch()) {
                    ?>
                    <div class="notification-card" onclick="showNotificationDetails(<?php echo $notification['id']; ?>)">
                        <div class="notification-message">
                            <i class="bi bi-check-circle-fill" style="color: #28a745;"></i>
                            <?php 
                            // Display message without the post content part for cleaner display
                            $display_message = preg_replace('/Post content: ".*?"/s', '', $notification['message']);
                            // Also hide the JSON data
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
                    <h3>No Approval Notifications</h3>
                    <p>When administrators approve your posts, notifications will appear here.</p>
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
                <form method="post" action="approved_posts.php" onsubmit="return confirm('Are you sure you want to clear all read notifications?');">
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
                    <h3 class="modal-title">Your Post Approval Details</h3>
                    <button class="modal-close" onclick="hideModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <?php if ($selected_notification): ?>
                        <?php
                        // Get post data from the approved post
                        $post_content = "";
                        $post_data = null;
                        
                        if ($approved_post_data) {
                            $post_content = $approved_post_data['content'] ?? '';
                            $post_data = $approved_post_data;
                        }
                        ?>
                        
                        <!-- Approval banner -->
                        <div class="approval-banner">
                            <i class="bi bi-check-circle-fill"></i>
                            <span>This post has been approved by an administrator</span>
                        </div>
                        
                        <!-- Begin post structure that matches admin.php -->
                        <div class="post-display">
                            <div class="post-author-info">
                                <?php 
                                if ($approved_post_data) {
                                    // Use data from posts table
                                    $has_profile_pic = !empty($approved_post_data['profile_picture']);
                                    $profile_pic = $has_profile_pic
                                        ? htmlspecialchars($approved_post_data['profile_picture'])
                                        : '';
                                    
                                    $author_first = $approved_post_data['first_name'] ?? '';
                                    $author_last = $approved_post_data['last_name'] ?? '';
                                    $author_name = !empty($author_first) && !empty($author_last)
                                        ? htmlspecialchars($author_first . ' ' . $author_last)
                                        : 'Your Post';
                                    
                                    $post_content = $approved_post_data['content'] ?? '';
                                    $post_image = $approved_post_data['image_path'] ?? '';
                                    $post_likes = $approved_post_data['likes'] ?? 0;
                                    $post_comments = $approved_post_data['comment_count'] ?? 0;
                                    $post_created = $approved_post_data['created_at'] ?? $selected_notification['created_at'];
                                } else {
                                    // Fallback to parsing from notification message
                                    $has_profile_pic = false;
                                    $profile_pic = '';
                                    
                                    $author_first = '';
                                    $author_last = '';
                                    $author_name = 'Your Post';
                                    
                                    $post_image = '';
                                    $post_likes = 0;
                                    $post_comments = 0;
                                    $post_created = $selected_notification['created_at'];
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
                                // Use the content from posts table if available, otherwise fallback
                                $display_content = $approved_post_data ? $approved_post_data['content'] : $post_content;
                                
                                if (!empty($display_content)): 
                                ?>
                                    <?php echo nl2br(htmlspecialchars($display_content)); ?>
                                <?php else: ?>
                                    <em>No content available</em>
                                <?php endif; ?>
                            </div>
                            
                            <?php 
                            // Check for image in posts table first
                            $image_src = $approved_post_data && !empty($approved_post_data['image_path']) 
                                ? $approved_post_data['image_path'] 
                                : '';
                                
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
                                    $likes_count = $approved_post_data ? intval($approved_post_data['likes']) : 0;
                                    
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
                                        <?php echo $approved_post_data ? intval($approved_post_data['comment_count']) : '0'; ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="approval-timestamp">
                            <i class="bi bi-clock-history"></i> 
                            Post was approved <?php echo time_elapsed_string($selected_notification['created_at'], true); ?>
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
            window.location.href = 'approved_posts.php?notification_id=' + notificationId;
        }
        
        // Function to hide modal
        function hideModal() {
            const detailModal = document.getElementById('detailModal');
            if (detailModal) {
                detailModal.classList.remove('active');
                // Remove the notification_id from URL
                history.replaceState({}, document.title, 'approved_posts.php');
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
