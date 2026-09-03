<?php
session_start();

require_once 'db_connection.php';

date_default_timezone_set('Asia/Manila'); // Adjust to your timezone

// If user is not logged in, redirect to login page
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}



// Get user info
$user_id = $_SESSION['user_id'];

$sql = "SELECT first_name, last_name, username, profile_picture FROM users WHERE id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    echo "User not found!";
    exit();
}



// Get posts with comment counts and like information
$posts = [];
$user_id = $_SESSION['user_id'];
$sql = "SELECT p.*, 
        u.first_name, u.last_name, u.profile_picture,
        COUNT(DISTINCT c.id) AS comment_count,
        p.likes,
        EXISTS(SELECT 1 FROM likes l WHERE l.user_id = ? AND l.post_id = p.id) AS user_has_liked
        FROM posts p 
        JOIN users u ON p.user_id = u.id 
        LEFT JOIN comments c ON p.id = c.post_id
        WHERE p.status = 'approved' OR p.status = 'posted' OR p.status IS NULL
        GROUP BY p.id, u.first_name, u.last_name, u.profile_picture
        ORDER BY p.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$user_id]);
$posts = $stmt->fetchAll();




// Handle comment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['comment-content'])) {
    // Preserve line breaks but escape HTML to prevent XSS
    $content = htmlspecialchars($_POST['comment-content'], ENT_QUOTES, 'UTF-8');
    $post_id = $_POST['post_id'];
    $user_id = $_SESSION['user_id'];
    $parent_id = isset($_POST['parent_id']) && $_POST['parent_id'] !== '' ? (int)$_POST['parent_id'] : null;
    
    $sql = "INSERT INTO comments (post_id, user_id, content, parent_id, created_at) VALUES (?, ?, ?, ?, ?)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$post_id, $user_id, $content, $parent_id, gmdate('Y-m-d H:i:s')]);
    
    // Get the newly inserted comment with user info
    $new_comment_id = $pdo->lastInsertId();
    $sql = "SELECT c.*, u.first_name, u.last_name, u.profile_picture 
            FROM comments c 
            JOIN users u ON c.user_id = u.id 
            WHERE c.id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$new_comment_id]);
    $new_comment = $stmt->fetch();

    // Get updated comment count
    $count_sql = "SELECT COUNT(*) AS comment_count FROM comments WHERE post_id = ?";
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute([$post_id]);
    $count_result = $count_stmt->fetch();

    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'comment' => $new_comment,
            'new_count' => $count_result['comment_count']
        ]);
        exit();
    } else {
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit();
    }

}

date_default_timezone_set('Asia/Manila');

// Helper function to format time (uses UTC to match PostgreSQL timestamps)
function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime('now', new DateTimeZone('UTC'));
    $ago = new DateTime($datetime, new DateTimeZone('UTC'));
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

// Set default profile picture if not set
$profile_picture = !empty($user['profile_picture']) ? $user['profile_picture'] : './web-images/pfp.jpg';

// Combine full name
$full_name = htmlspecialchars($user['first_name'] . ' ' . $user['last_name']);
$username = htmlspecialchars($user['username']);



?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BondNest | Social Media</title>
    <!-- Fix for border issues -->
    <style>
    /* Like button animations and styles */
    .post-action.like-button[data-liked="true"] i.bi-heart-fill {
        color: #e74c3c !important;
    }
    
    .post-action.like-button {
        transition: transform 0.3s ease;
    }
    
    /* Remove ALL teal borders from profile pictures */
    .post-avatar, .profile_picture, [class*="avatar"], [class*="profile"] {
        border: none !important;
    }

    .initials-avatar {
        display: flex !important;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        font-family: 'Poppins', sans-serif;
        letter-spacing: 0.5px;
        flex-shrink: 0;
        border: none !important;
    }

    .post .post-avatar img, .feed .post-avatar img, .post-header .post-avatar img {
        border: none !important;
    }

    /* Target parent elements that might have the border */
    .post-avatar, .post-avatar *, .profile_picture, .profile_picture * {
        border: none !important;
        box-shadow: none !important;
    }

    /* Force remove teal color */
    .post-avatar, .profile_picture, .avatar, .post-avatar img, .profile_picture img {
        border-color: transparent !important;
    }
    </style>
    <!-- WE USE BOOTSTRAPICON FOR ICONS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">    <!-- LINKING THE CSS FILE -->
    <!-- Add this with your other CSS links -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="homepage.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="homepage2.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="sidebar.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="post-pending-styles.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="post-status-styles.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="post-styles.css?v=<?php echo time(); ?>">
    
    <!-- Include post synchronization script -->
    <script src="post_sync.js?v=<?php echo time(); ?>"></script>
    
    <!-- Include post status management script -->
    <script src="post_status.js?v=<?php echo time(); ?>"></script>
    
    <!-- Include deleted post notification script -->
    <script src="deleted_post_notification.js?v=<?php echo time(); ?>"></script>
    
    <style>
/* Enhanced Modal Styles */
/* Define teal color as primary color variable for consistency */
:root {
    --primary-color-hue: 180; /* Teal hue value */
    --color-primary: #008080;
    --color-primary-light: rgba(0, 128, 128, 0.1);
    --color-primary-dark: #006666;
}

/* Remove redundant navbar styles since we're now including navbar.php */
/* Add margin to main for the new navbar */
main {
    margin-top: 20px;
}

.logout-confirmation-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.7);
    backdrop-filter: blur(4px);
    z-index: 1000;
    display: none;
    justify-content: center;
    align-items: center;
    transition: all 0.3s ease;
}

.logout-confirmation-container {
    background: var(--color-white);
    padding: 1.5rem;
    border-radius: 16px;
    box-shadow: 0 15px 35px rgba(0, 0, 0, 0.25);
    width: 100% !important;
    max-width: 380px !important;
    transform: scale(0.95);
    animation: modal-in 0.3s forwards;
    border-top: 4px solid var(--color-primary);
}

/* Special styling for delete modals */
.delete-modal {
    border-top: 4px solid #e74c3c;
}

@keyframes modal-in {
    to { transform: scale(1); }
}

.logout-header {
    display: flex;
    align-items: center;
    margin-bottom: 1.2rem;
    color: var(--color-primary);
    padding-bottom: 0.75rem;
    border-bottom: 1px solid rgba(0, 0, 0, 0.05);
}

.delete-modal .logout-header {
    color: #e74c3c;
}

.logout-header i {
    font-size: 1.3rem;
    margin-right: 0.75rem;
    width: 32px;
    height: 32px;
    background: var(--color-primary-light);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}

.delete-modal .logout-header i {
    background: rgba(231, 76, 60, 0.1);
}

.logout-header h2 {
    font-size: 1.3rem;
    font-weight: 600;
    margin: 0;
}

.logout-message {
    font-size: 1.1rem;
    margin-bottom: 1.5rem;
    color: var(--color-dark);
    line-height: 1.5;
    text-align: center;
}

.logout-actions {
    display: flex;
    justify-content: center;
    gap: 0.75rem;
    padding-top: 0.5rem;
}

.btn-cancel, .btn-save {
    padding: 0.7rem 1.5rem;
    border-radius: 8px;
    font-weight: 500;
    font-size: 1rem;
    cursor: pointer;
    transition: all 0.2s ease;
    border: none;
    min-width: 100px;
    text-align: center;
}

.btn-cancel {
    background: #f0f0f0;
    color: #555;
}

.btn-cancel:hover {
    background: #e0e0e0;
}

.btn-save {
    background: var(--color-primary);
    color: white;
}

.btn-save:hover {
    opacity: 0.95;
    box-shadow: 0 3px 8px rgba(0, 128, 128, 0.2);
}

.delete-btn {
    background: #e74c3c;
}

.delete-btn:hover {
    background: #d62c1a;
    box-shadow: 0 3px 8px rgba(231, 76, 60, 0.2);
}

.delete-modal .logout-header {
    color: #e74c3c;
}

/* Post Menu Styles */
.post-menu-trigger {
    cursor: pointer;
    padding: 5px;
    border-radius: 50%;
    transition: all 0.3s ease;
}

.post-menu-trigger:hover {
    background: var(--color-light);
}

.post-menu-dropdown {
    position: absolute;
    right: 0;
    top: 100%;
    background: var(--color-white);
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
    border-radius: 0.7rem;
    padding: 0.7rem 0;
    min-width: 160px;
    z-index: 100;
    display: none;
    transform-origin: top right;
    animation: dropdown-in 0.2s forwards;
}

@keyframes dropdown-in {
    from { opacity: 0; transform: scale(0.9); }
    to { opacity: 1; transform: scale(1); }
}

.post-menu-dropdown.show {
    display: block;
}

.post-menu-item {
    padding: 0.7rem 1rem;
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
}

.post-menu-item i {
    margin-right: 0.7rem;
}

.post-menu-item:hover {
    background: var(--color-light);
}

.post-menu-item.delete-post {
    color: #e74c3c;
}

.post-menu-item.delete-post:hover {
    background: rgba(231, 76, 60, 0.1);
}

.post-menu-item.edit-post:hover {
    background: rgba(0, 0, 0, 0.05);
}

/* Fix for post and comment container expansion with long text */
.post-content p {
    word-wrap: break-word;
    overflow-wrap: break-word;
    word-break: break-word;
    hyphens: auto;
    max-width: 100%;
    white-space: normal;
}

.feed {
    max-width: 100%;
    word-wrap: break-word;
}

/* Improve comment layout */
.comment-item {
    padding: 10px;
    margin-bottom: 10px;
    border-bottom: 1px solid var(--color-light);
}

.comment-content {
    display: flex;
    align-items: flex-start;
}

.comment-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    object-fit: cover;
    margin-right: 10px;
    flex-shrink: 0;
}

.comment-body {
    flex: 1;
    max-width: calc(100% - 50px);
    word-wrap: break-word;
}

.comment-author {
    margin: 0 0 5px 0;
    font-weight: 600;
    font-size: 0.95rem;
    color: var(--color-dark);
}

.comment-text {
    margin: 0 0 5px 0;
    word-wrap: break-word;
    overflow-wrap: break-word;
    word-break: break-word;
    hyphens: auto;
    white-space: normal;
    font-size: 0.9rem;
}

.comment-time {
    display: block;
    font-size: 0.75rem;
    color: var(--text-medium);
}

/* Comment form styling */
.comment-form-fixed {
    position: sticky;
    bottom: 0;
    background: var(--color-white);
    padding: 15px;
    border-top: 1px solid var(--color-light);
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
}

.comment-form-fixed .reply-indicator {
    width: 100%;
    justify-content: flex-start;
}

.comment-input {
    flex: 1;
    border: 1px solid var(--color-light);
    border-radius: 20px;
    padding: 10px 15px;
    resize: none;
    height: 40px;
    min-height: 40px;
    font-family: inherit;
    font-size: 0.9rem;
}

.comment-input:focus {
    outline: none;
    border-color: var(--color-primary);
}

.comment-list {
    max-height: 400px;
    overflow-y: auto;
    padding: 0 15px;
    margin-bottom: 60px; /* Space for fixed comment form */
}

/* Make sure content doesn't overflow containers */
.post-content, .comment-content {
    max-width: 100%;
    overflow: hidden;
}

/* Fix for long words and URLs */
.post-content, .comment-item {
    overflow-wrap: break-word;
    word-wrap: break-word;
    -ms-word-break: break-all;
    word-break: break-word;
}

/* More specific fix for the post media */
.post-media {
    max-width: 100%;
    overflow: hidden;
}

.post-media img {
    max-width: 100%;
    height: auto;
}

/* Override comment layout styles to ensure proper display */
#commentList .comment-item {
    padding: 10px !important;
    margin-bottom: 15px !important;
    border-bottom: 1px solid var(--color-light) !important;
    background: #f9f9f9 !important;
    border-radius: 8px !important;
    display: block !important;
}

.replies-container .comment-item {
    display: block !important;
    padding: 6px 0 !important;
    margin-bottom: 0 !important;
    border-bottom: none !important;
    background: transparent !important;
    border-radius: 0 !important;
}

#commentList .comment-content {
    display: flex !important;
    align-items: flex-start !important;
    width: 100% !important;
}

#commentList .comment-avatar {
    width: 40px !important;
    height: 40px !important;
    border-radius: 50% !important;
    margin-right: 10px !important;
    flex-shrink: 0 !important;
}

#commentList .comment-body {
    flex: 1 !important;
    max-width: calc(100% - 50px) !important;
    display: flex !important;
    flex-direction: column !important;
}

#commentList .comment-author {
    margin: 0 0 3px 0 !important;
    font-weight: 600 !important;
    font-size: 0.95rem !important;
    color: var(--color-dark) !important;
}

#commentList .comment-text {
    position: relative !important;
    background: #f0f2f5 !important;
    padding: 8px 12px !important;
    border-radius: 18px !important;
    word-wrap: break-word !important;
    overflow-wrap: break-word !important;
    width: fit-content !important;
    max-width: 100% !important;
    margin: 2px 0 !important;
    line-height: 1.4 !important;
    box-shadow: 0 1px 2px rgba(0,0,0,0.05) !important;
}

#commentList .comment-time {
    font-size: 0.75rem !important;
    color: #999 !important;
    margin-top: 2px !important;
}

/* Enhanced comment input styling */
.comment-form-fixed {
    position: sticky !important;
    bottom: 0 !important;
    background: var(--color-white) !important;
    padding: 15px !important;
    border-top: 1px solid var(--color-light) !important;
    display: flex !important;
    gap: 10px !important;
    align-items: center !important;
    box-shadow: 0 -2px 10px rgba(0,0,0,0.05) !important;
    z-index: 10 !important;
}

.comment-input {
    flex: 1 !important;
    border: 1px solid var(--color-light) !important;
    border-radius: 20px !important;
    padding: 10px 15px !important;
    resize: vertical !important;
    min-height: 40px !important;
    max-height: 120px !important;
    font-family: inherit !important;
    font-size: 0.9rem !important;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05) !important;
    transition: border-color 0.2s, box-shadow 0.2s !important;
}

.comment-input:focus {
    outline: none !important;
    border-color: var(--color-primary) !important;
    box-shadow: 0 1px 5px rgba(0,0,0,0.1) !important;
}

/* Comment list scrolling */
.comment-list {
    max-height: 400px !important;
    overflow-y: auto !important;
    padding: 0 15px !important;
    margin-bottom: 60px !important; /* Space for fixed comment form */
    scrollbar-width: thin !important;
}

/* Animating new comments */
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

.comment-item.new-comment {
    animation: fadeIn 0.3s ease-out forwards !important;
}

/* Reply styles */
.reply-btn {
    background: none;
    border: none;
    color: #666;
    font-size: 0.75rem;
    cursor: pointer;
    padding: 2px 8px;
    margin-left: 4px;
    border-radius: 12px;
    transition: background-color 0.2s, color 0.2s;
    font-weight: 500;
}
.reply-btn:hover {
    background: rgba(0, 128, 128, 0.1);
    color: #008080;
}
.reply-indicator {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 12px;
    background: #e8f5f5;
    border-radius: 8px;
    margin-bottom: 8px;
    font-size: 0.85rem;
    color: #555;
}
.reply-indicator .cancel-reply {
    background: none;
    border: none;
    color: #999;
    cursor: pointer;
    font-size: 1rem;
    padding: 0 4px;
    margin-left: auto;
}
.reply-indicator .cancel-reply:hover {
    color: #e74c3c;
}
.replies-container {
    margin-left: 44px;
    margin-top: 4px;
    padding-left: 12px;
    border-left: 2px solid #e0e0e0;
}
.replies-container .comment-item {
    padding: 6px 0;
}
.replies-container .comment-avatar {
    width: 28px !important;
    height: 28px !important;
}
.replies-container .comment-text {
    font-size: 0.9rem !important;
}
.replies-container .comment-author {
    font-size: 0.85rem !important;
}
.comment-time-row {
    display: flex;
    align-items: center;
    gap: 4px;
    margin-top: 2px;
}

/* Comment menu styles */
.comment-header {
    display: flex !important;
    justify-content: space-between !important;
    align-items: flex-start !important;
    width: 100% !important;
    margin-bottom: 2px !important;
}

.comment-actions {
    position: relative !important;
    margin-left: 8px !important;
}

.comment-menu-trigger {
    cursor: pointer !important;
    padding: 3px 5px !important;
    border-radius: 50% !important;
    font-size: 14px !important;
    color: #888 !important;
    transition: all 0.2s ease !important;
}

.comment-menu-trigger:hover {
    background-color: var(--color-light) !important;
    color: var(--color-dark) !important;
}

.comment-menu-dropdown {
    position: absolute !important;
    right: 0 !important;
    top: 100% !important;
    background: var(--color-white) !important;
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1) !important;
    border-radius: 8px !important;
    padding: 5px 0 !important;
    min-width: 150px !important;
    z-index: 1001 !important; /* Higher z-index */
    display: none !important;
    transform-origin: top right !important;
    animation: dropdown-in 0.2s forwards !important;
}

.comment-menu-dropdown.show {
    display: block !important;
}

.comment-menu-item {
    padding: 8px 12px !important;
    cursor: pointer !important;
    transition: all 0.2s ease !important;
    display: flex !important;
    align-items: center !important;
    font-size: 14px !important;
    white-space: nowrap !important; /* Prevent wrapping */
}

.comment-menu-item i {
    margin-right: 8px !important;
    font-size: 14px !important;
}

.comment-menu-item:hover {
    background: var(--color-light) !important;
}

.comment-menu-item.delete-comment {
    color: #e74c3c !important;
}

.comment-menu-item.delete-comment:hover {
    background: rgba(231, 76, 60, 0.1) !important;
}

.comment-menu-item.edit-comment:hover {
    background: rgba(0, 0, 0, 0.05) !important;
}

/* Fix modal overlapping issues */
#editCommentModal, #deleteCommentConfirmationOverlay {
    z-index: 1200 !important; /* Higher than comment modal */
}

.comment-modal {
    z-index: 1000 !important;
}

#commentModal .comment-modal-content {
    max-width: 600px !important;
    max-height: 80vh !important;
    overflow: hidden !important;
    display: flex !important;
    flex-direction: column !important;
}

.comments-container {
    flex: 1 !important;
    overflow: hidden !important;
    display: flex !important;
    flex-direction: column !important;
}

.comment-list {
    flex: 1 !important;
    overflow-y: auto !important;
}

/* Better visibility for comment menu */
.comment-actions {
    opacity: 1 !important; /* Always visible */
}

.edit-post-modal {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    width: 100% !important;
    height: 100% !important;
    background: rgba(0, 0, 0, 0.7) !important;
    backdrop-filter: blur(4px) !important;
    z-index: 1200 !important;
    display: none !important;
    justify-content: center !important;
    align-items: center !important;
}

.logout-confirmation-overlay {
    z-index: 1200 !important;
    backdrop-filter: blur(4px) !important;
}

/* Edit comment modal styles */
#editCommentModal .edit-post-modal-content {
    max-width: 500px !important;
}

#editCommentContent {
    min-height: 100px !important;
    padding: 12px !important;
    font-size: 14px !important;
    line-height: 1.5 !important;
    resize: vertical !important;
}

/* Animation for comment actions */
@keyframes fadeInRight {
    from { opacity: 0; transform: translateX(10px); }
    to { opacity: 1; transform: translateX(0); }
}

.comment-item .comment-actions {
    opacity: 0;
    transition: opacity 0.2s ease;
}

.comment-item:hover .comment-actions {
    opacity: 1;
}

/* Fix for edit comment and delete comment dropdown in comment modal */
.comment-modal .comment-menu-dropdown {
    position: absolute !important;
    top: 30px !important;
    right: 0 !important;
    background-color: #fff !important;
    border-radius: 8px !important;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1) !important;
    width: 180px !important;
    padding: 8px 0 !important;
    z-index: 1500 !important;
}

.comment-modal .comment-menu-item {
    display: flex !important;
    align-items: center !important;
    padding: 8px 15px !important;
    color: #333 !important;
    font-size: 14px !important;
    transition: background-color 0.2s ease !important;
}

.comment-modal .comment-menu-item i {
    margin-right: 10px !important;
    font-size: 16px !important;
}

.comment-modal .comment-menu-item:hover {
    background-color: #f5f5f5 !important;
}

.comment-modal .comment-menu-item.delete-comment {
    color: #e74c3c !important;
}

.comment-modal .comment-menu-item.delete-comment:hover {
    background-color: rgba(231, 76, 60, 0.1) !important;
}

.comment-modal .comment-menu-trigger {
    cursor: pointer !important;
    font-size: 16px !important;
    color: #888 !important;
    background: transparent !important;
    border: none !important;
    padding: 5px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    border-radius: 50% !important;
    width: 30px !important;
    height: 30px !important;
    transition: background-color 0.2s ease !important;
}

.comment-modal .comment-menu-trigger:hover {
    background-color: rgba(0, 0, 0, 0.05) !important;
    color: #333 !important;
}

/* Additional fix for edit modals */
#editCommentModal, #editPostModal {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    width: 100% !important;
    height: 100% !important;
    background: rgba(0, 0, 0, 0.7) !important;
    backdrop-filter: blur(4px) !important;
    z-index: 2000 !important;
    display: none !important;
    justify-content: center !important;
    align-items: center !important;
}

.edit-post-modal-content {
    background: white !important;
    border-radius: 10px !important;
    width: 90% !important;
    max-width: 500px !important;
    max-height: 80vh !important;
    overflow: hidden !important;
    display: flex !important;
    flex-direction: column !important;
    box-shadow: 0 5px 20px rgba(0, 0, 0, 0.2) !important;
}

.edit-post-header {
    display: flex !important;
    justify-content: space-between !important;
    align-items: center !important;
    padding: 15px 20px !important;
    border-bottom: 1px solid #eee !important;
}

.edit-post-header h2 {
    margin: 0 !important;
    font-size: 18px !important;
}

.edit-post-close {
    font-size: 24px !important;
    cursor: pointer !important;
    color: #aaa !important;
    transition: color 0.2s ease !important;
}

.edit-post-close:hover {
    color: #333 !important;
}

.edit-post-body {
    padding: 20px !important;
    flex: 1 !important;
    overflow-y: auto !important;
}

.edit-post-body textarea {
    width: 100% !important;
    min-height: 120px !important;
    padding: 12px !important;
    border: 1px solid #ddd !important;
    border-radius: 8px !important;
    font-family: inherit !important;
    font-size: 14px !important;
    resize: vertical !important;
}

.edit-post-actions {
    display: flex !important;
    justify-content: flex-end !important;
    gap: 10px !important;
    padding: 15px 20px !important;
    border-top: 1px solid #eee !important;
}

/* Fix for comment positions */
.comment-actions {
    position: absolute !important;
    right: 10px !important;
    top: 10px !important;
}

/* Improved menu for comments in comment modal */
.comment-modal .comment-actions {
    position: absolute !important;
    right: 15px !important;
    top: 10px !important;
    z-index: 2000 !important;
}

.comment-modal .comment-menu-dropdown {
    position: absolute !important;
    right: 0 !important;
    top: 30px !important;
    background: white !important;
    border-radius: 8px !important;
    box-shadow: 0 0 15px rgba(0,0,0,0.1) !important;
    overflow: hidden !important;
    width: 180px !important;
    z-index: 2001 !important;
}

.comment-modal .comment-menu-item {
    padding: 12px 15px !important;
    display: flex !important;
    align-items: center !important;
    font-size: 14px !important;
    cursor: pointer !important;
    transition: background-color 0.2s !important;
}

.comment-modal .comment-menu-item:hover {
    background-color: #f5f5f5 !important;
}

.comment-modal .comment-menu-item i {
    margin-right: 10px !important;
    font-size: 16px !important;
}

.comment-modal .comment-menu-item.edit-comment {
    color: #2979ff !important;
}

.comment-modal .comment-menu-item.delete-comment {
    color: #e74c3c !important;
}

.comment-modal .comment-menu-item.edit-comment:hover {
    background-color: rgba(41, 121, 255, 0.1) !important;
}

.comment-modal .comment-menu-item.delete-comment:hover {
    background-color: rgba(231, 76, 60, 0.1) !important;
}

/* Dialog-style modals */
.edit-post-modal, 
#editCommentModal, 
#editPostModal,
#deleteCommentConfirmationOverlay,
#deletePostConfirmationOverlay {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    width: 100% !important;
    height: 100% !important;
    background-color: rgba(0, 0, 0, 0.7) !important;
    backdrop-filter: blur(4px) !important;
    z-index: 9999 !important;
    display: none !important;
    justify-content: center !important;
    align-items: center !important;
    overflow: auto !important;
}

/* Additional universal comment styling */
.comment-item {
    padding: 10px !important;
    margin-bottom: 15px !important;
    border-bottom: 1px solid var(--color-light) !important;
    background: transparent !important; /* Changed from #f9f9f9 to transparent */
    border-radius: 8px !important;
    display: block !important;
}

.comment-content {
    display: flex !important;
    align-items: flex-start !important;
    width: 100% !important;
}

.comment-avatar {
    width: 40px !important;
    height: 40px !important;
    border-radius: 50% !important;
    margin-right: 10px !important;
    flex-shrink: 0 !important;
}

.comment-body {
    flex: 1 !important;
    max-width: calc(100% - 50px) !important;
    display: flex !important;
    flex-direction: column !important;
}

.comment-author {
    margin: 0 0 3px 0 !important;
    font-weight: 600 !important;
    font-size: 0.95rem !important;
    color: var(--color-dark) !important;
}

.comment-text {
    position: relative !important;
    background: #f0f2f5 !important;
    padding: 8px 12px !important;
    border-radius: 18px !important;
    word-wrap: break-word !important;
    overflow-wrap: break-word !important;
    width: fit-content !important;
    max-width: 100% !important;
    margin: 2px 0 !important;
    line-height: 1.4 !important;
    box-shadow: 0 1px 2px rgba(0,0,0,0.05) !important;
}

.comment-time {
    font-size: 0.75rem !important;
    color: #999 !important;
    margin-top: 2px !important;
}

// Ensure the comment content is properly styled
const commentContent = div.querySelector('.comment-content');
if (commentContent) {
    commentContent.style.cssText = `
        display: flex !important;
        align-items: flex-start !important;
        width: 100% !important;
        background: transparent !important;
        position: relative !important; /* Add position relative */
    `;
}

// Improve comment actions menu styling
const commentActions = div.querySelector('.comment-actions');
if (commentActions) {
    commentActions.style.cssText = `
        position: absolute !important;
        right: 0 !important;
        top: 0 !important;
        z-index: 100 !important;
    `;
}

// Fix menu dropdown positioning and styling
const menuDropdown = div.querySelector('.comment-menu-dropdown');
if (menuDropdown) {
    menuDropdown.style.cssText = `
        position: absolute !important;
        right: 0 !important;
        top: 100% !important;
        background: white !important;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1) !important;
        border-radius: 8px !important;
        padding: 5px 0 !important;
        min-width: 180px !important;
        z-index: 1000 !important;
        display: none !important;
        transform-origin: top right !important;
    `;
}

// Fix menu items styling
const menuItems = div.querySelectorAll('.comment-menu-item');
menuItems.forEach(item => {
    item.style.cssText = `
        padding: 10px 15px !important;
        cursor: pointer !important;
        display: flex !important;
        align-items: center !important;
        transition: background-color 0.2s !important;
        white-space: nowrap !important;
    `;
    
    // Add hover effect via event listeners
    item.addEventListener('mouseenter', () => {
        item.style.backgroundColor = '#f5f5f5';
    });
    item.addEventListener('mouseleave', () => {
        item.style.backgroundColor = 'transparent';
    });
});

// Apply hover effect to the menu trigger icon
const menuTrigger = div.querySelector('.comment-menu-trigger');
if (menuTrigger) {
    menuTrigger.style.cssText = `
        cursor: pointer !important;
        padding: 8px !important;
        border-radius: 50% !important;
        transition: background-color 0.2s !important;
        color: #666 !important;
        position: absolute !important;
        right: 5px !important;
        top: 0 !important;
    `;
    
    menuTrigger.addEventListener('mouseenter', () => {
        menuTrigger.style.backgroundColor = '#f0f0f0';
    });
    menuTrigger.addEventListener('mouseleave', () => {
        menuTrigger.style.backgroundColor = 'transparent';
    });
}

/* Reduce spacing between posts */
.feeds {
    display: flex !important;
    flex-direction: column !important;
    gap: 0px !important; /* Reduced from 12px to 6px */
    width: 100% !important;
}

.feed {
    background: white !important;
    border-radius: 12px !important;
    padding: 12px !important; /* Reduced from 15px to 12px */
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05) !important;
    margin-bottom: 0 !important; /* Explicitly set to 0 */
}

/* Compress internal post elements */
.post-header {
    margin-bottom: 8px !important; /* Reduced from 10px to 8px */
}

.post-content {
    margin-bottom: 8px !important; /* Reduced from 10px to 8px */
}

.post-media {
    margin-bottom: 8px !important; /* Reduced from 10px to 8px */
}

.liked-by {
    margin-top: 5px !important; /* Reduced from 10px */
}

.middle .create-post {
    margin-bottom: 10px !important; /* Reduced from 15px to 10px */
}

.container {
    margin-top: -50px !important;
}


/* Create Post Modal position adjustment (matches profile-page look) */
.modal-container {
    position: fixed;
    top: 74px; /* Start below navbar (adjust based on navbar height) */
    left: 0;
    width: 100%;
    height: calc(100% - 74px); /* Full height minus navbar height */
    background-color: rgba(0, 0, 0, 0.7);
    backdrop-filter: blur(4px);
    display: none;
    z-index: 1000;
    justify-content: center;
    align-items: flex-start; /* Changed from center to align at top */
    overflow-y: auto;
    padding-top: 50px; /* Add padding instead of margin */
    padding-bottom: 50px;
    box-sizing: border-box;
}

/* Modal styles to ensure clicking outside works from all directions */
#createPostModal {
    /* Keep display:none by default - will be set to flex when opened */
    display: none;
    justify-content: center;
    align-items: flex-start;
    top: 74px;
    padding-top: 10px;
    padding-bottom: 50px;
    box-sizing: border-box;
}

#editPostModal, #editCommentModal {
    display: flex;
    justify-content: center;
    align-items: center;
}

#postForm {
    position: relative;
    z-index: 1001;
    margin: auto;
    width: 90%;
    max-width: 500px;
}

.modal-backdrop {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    width: 100vw !important;
    height: 100vh !important;
    cursor: pointer !important;
}

#createPostModal .modal-content {
    background-color: white;
    border-radius: 10px;
    width: 100%;
    max-width: 500px;
    max-height: 90vh;
    overflow-y: auto;
    padding: 20px;
    margin: 0 auto;
    box-sizing: border-box;
    box-shadow: 0 5px 20px rgba(0, 0, 0, 0.3);
    animation: modal-in 0.3s forwards;
}

/* Image preview styling improvements (same 300px cap as profile page) */
.image-preview-wrapper {
    position: relative;
    margin: 10px 0 0 0;
    max-width: 100%;
}

.image-preview {
    width: 100%;
    max-width: 100%;
    max-height: 300px;
    object-fit: cover;
    border-radius: 8px;
    display: block;
}

.remove-image-btn {
    position: absolute;
    top: 10px;
    right: 10px;
    width: 30px;
    height: 30px;
    background-color: rgba(0, 0, 0, 0.6);
    color: white;
    border: none;
    border-radius: 50%;
    font-size: 20px;
    font-weight: bold;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.3);
    transition: all 0.2s ease;
    z-index: 10;
}

.remove-image-btn:hover {
    background-color: rgba(0, 0, 0, 0.8);
    transform: scale(1.1);
}

.image-preview-container {
    margin-top: 15px;
}

.logout-confirmation-container {
    background: var(--color-white);
    padding: 1.5rem;
    border-radius: 16px;
    box-shadow: 0 15px 35px rgba(0, 0, 0, 0.25);
    width: 100% !important;
    max-width: 380px !important;
    transform: scale(0.95);
    animation: modal-in 0.3s forwards;
    border-top: 4px solid var(--color-primary);
}

/* Special styling for delete modals */
.delete-modal {
    border-top: 4px solid #e74c3c;
}

.logout-header i {
    font-size: 1.3rem;
    margin-right: 0.75rem;
    width: 32px;
    height: 32px;
    background: var(--color-primary-light);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}

.btn-save {
    background: var(--color-primary);
    color: white;
}

.btn-save:hover {
    opacity: 0.95;
    box-shadow: 0 3px 8px rgba(0, 128, 128, 0.2);
}

/* Style for edit post and edit comment text */
.post-menu-item.edit-post {
    color: var(--color-primary);
}

.post-menu-item.edit-post:hover {
    background: var(--color-primary-light);
}

/* Update edit comment color in floating menu */
.comment-menu-item.edit-comment {
    color: var(--color-primary) !important;
}

.comment-menu-item.edit-comment:hover {
    background: var(--color-primary-light) !important;
}

/* Make sure the sidebar Profile text is black */
.sidebar .menu-item h3 {
    color: var(--color-dark);
}

/* Edit modals header styling */
#editCommentModal .edit-post-header, 
#editPostModal .edit-post-header {
    color: var(--color-primary);
    border-bottom: 1px solid var(--color-primary-light);
}

/* Edit modal save button styling */
#saveEditComment, #saveEditPost {
    background-color: var(--color-primary);
}

/* Create post modal styling */
.modal-footer .post-button {
    background-color: var(--color-primary);
    color: white;
    border: none;
    border-radius: 6px;
    padding: 8px 20px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
}

.modal-footer .post-button:hover {
    background-color: var(--color-primary-dark);
    box-shadow: 0 3px 8px rgba(0, 128, 128, 0.2);
}

/* Fix for create post modal user info alignment */
.user-info {
    display: flex;
    align-items: center;
    margin-bottom: 15px;
}

.user-info .profile-picture {
    flex-shrink: 0;
}

.user-info .user-details {
    margin-left: 5px;
    padding-left: 0;
}

.modal-body .profile-picture img {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    object-fit: cover;
}
</style>
</head>

<body class="homepage">
    <?php include 'navbar.php'; ?>
    <?php include 'mobile-nav.php'; ?>

    <!-- Delete Post Confirmation Modal -->
    <div class="logout-confirmation-overlay" id="deletePostConfirmationOverlay">
        <div class="logout-confirmation-container delete-modal">
            <div class="logout-header">
                <i class="fas fa-trash-alt"></i>
                <h2>Confirm Delete</h2>
            </div>
            <p class="logout-message">Are you sure you want to delete this post?</p>
            <div class="logout-actions">
                <button class="btn-cancel logout-cancel-button" id="cancelDeletePost">Cancel</button>
                <button class="btn-save logout-confirm-button delete-btn" id="confirmDeletePost">Delete</button>
            </div>
        </div>
    </div>

    <!-- Delete Comment Confirmation Modal -->
    <div class="logout-confirmation-overlay" id="deleteCommentConfirmationOverlay">
        <div class="logout-confirmation-container delete-modal">
            <div class="logout-header">
                <i class="fas fa-trash-alt"></i>
                <h2>Delete Comment</h2>
            </div>
            <p class="logout-message">Are you sure you want to delete this comment?</p>
            <div class="logout-actions">
                <button class="btn-cancel logout-cancel-button" id="cancelDeleteComment">Cancel</button>
                <button class="btn-save logout-confirm-button delete-btn" id="confirmDeleteComment">Delete</button>
            </div>
        </div>
    </div>

    <!-- Edit Comment Modal -->
    <div class="edit-post-modal" id="editCommentModal">
        <div class="modal-backdrop" style="position:fixed; top:0; left:0; width:100vw; height:100vh; cursor:pointer; z-index:1000;" onclick="document.getElementById('editCommentModal').style.display='none';"></div>
        <div class="edit-post-modal-content" style="position:relative; z-index:1001;">
            <div class="edit-post-header">
                <h2>Edit Comment</h2>
                <span class="edit-post-close" id="closeEditComment">&times;</span>
            </div>
            <div class="edit-post-body">
                <textarea id="editCommentContent" placeholder="Edit your comment..."></textarea>
            </div>
            <div class="edit-post-actions">
                <button class="btn btn-secondary" id="cancelEditComment">Cancel</button>
                <button class="btn btn-primary" id="saveEditComment">Save Changes</button>
            </div>
        </div>
    </div>

    <!--------------------------------------------------------------MAIN ----------------------------------------------------->
    <main>
        <div class="container">
            <?php include 'sidebar.php'; ?>

            <!--------------------------------------------- MIDDLE SECTION -------------------------------------------------->
            <div class="middle">
                <div class="create-post">
                    <div class="input-area">
                    <div class="profile_picture">
                        <?php if (!empty($user['profile_picture'])): ?>
                            <img src="<?php echo $user['profile_picture']; ?>">
                        <?php else: ?>
                            <?php echo getInitialsHtml($user['first_name'], $user['last_name'], 44); ?>
                        <?php endif; ?>
            </div>
            <input type="text" placeholder="What's on your mind, <?php echo htmlspecialchars($user['first_name']); ?>?" name="create-post">

                    </div>
                    <div class="options">
                        <div class="option">
                            <i class="bi bi-plus-square-dotted" style="color: teal;"></i>
                            <span>Photo/Image</span>
                        </div>
                        <div class="option">
                        <i class="fa-regular fa-face-smile-beam" style="color: teal; vertical-align: middle; margin-top: 3px; display: inline-block;"></i>
                            <span>Feeling/Activity</span>
                        </div>
                    </div>
                </div>
                <div class="modal-container" id="createPostModal">
            <div class="modal-backdrop" style="position:fixed; top:0; left:0; width:100vw; height:100vh; cursor:pointer; z-index:1000;" onclick="document.getElementById('createPostModal').style.display='none';"></div>
            <form id="postForm" method="POST" enctype="multipart/form-data" action="create_post.php" style="position:relative; z-index:1001;">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2>Create post</h2>
                        <span class="close-button">&times;</span>
                    </div>
                    <div class="modal-body">
                        <div class="user-info">
                            <div class="profile-picture">
                                <?php if (!empty($user['profile_picture'])): ?>
                                    <img src="<?php echo $user['profile_picture']; ?>" alt="Profile Picture">
                                <?php else: ?>
                                    <?php echo getInitialsHtml($user['first_name'], $user['last_name'], 44); ?>
                                <?php endif; ?>
                            </div>
                            <div class="user-details">
                                <span><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></span>
                            </div>
                        </div>
                        <textarea placeholder="What's on your mind, <?php echo htmlspecialchars($user['first_name']); ?>?" name="post-content" id="postContent"></textarea>
                        <div class="add-to-post">
                            <span>Add to your post</span>
                            <div class="icons">
                                <label for="post-image" style="cursor: pointer;">
                                    <i class="bi bi-plus-square-dotted" style="color: teal;"></i>

                                </label>
                                <input type="file" id="post-image" name="post-image" accept="image/*" style="display: none;">
                                <i class="fa-regular fa-face-smile-beam" style="color: teal; vertical-align: middle; margin-top: 5px; display: inline-block;"></i>
                                <i class="bi bi-file-gif" style="color: purple;"></i>
                            </div>
                        </div>
                        <div class="image-preview-container" id="imagePreviewContainer"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="post-button">Post</button>
                    </div>
                </div>
            </form>
        </div>
                <!------------------------------------------- FEED SECTION -------------------------------------------->
<!------------------------------------------- FEED SECTION -------------------------------------------->
<div class="feeds">
    <?php foreach ($posts as $post): ?>
    <div class="feed post-item" data-post-id="<?php echo $post['id']; ?>" data-status="<?php echo htmlspecialchars($post['status'] ?? 'posted'); ?>">
        <?php if (isset($post['status']) && $post['status'] === 'on-hold' && $post['user_id'] == $_SESSION['user_id']): ?>
        <div class="post-pending-notice">
            <i class="bi bi-exclamation-circle-fill"></i> 
            This post has been placed on hold by administrators. Please check your notifications for details.
        </div>
        <?php endif; ?>
        <div class="post-header">
    <div class="post-avatar" style="border: none !important;">
        <?php if (!empty($post['profile_picture'])): ?>
            <img src="<?php echo $post['profile_picture']; ?>" style="border: none !important;">
        <?php else: ?>
            <?php echo getInitialsHtml($post['first_name'], $post['last_name'], 44); ?>
        <?php endif; ?>
    </div>
    <div>
        <div class="post-user"><?php echo htmlspecialchars($post['first_name'] . ' ' . $post['last_name']); ?></div>
        <div class="post-meta">
            <?php if (isset($post['status']) && $post['status'] !== 'posted'): ?>
                <span class="status-indicator <?php echo htmlspecialchars($post['status']); ?>" 
                      title="<?php echo ($post['status'] === 'approved') ? 'Approved by admin' : 'On hold'; ?>"></span>
            <?php endif; ?>
            <i class="bi bi-globe"></i> BondNest · 
            <span class="time-ago" data-timestamp="<?php echo htmlspecialchars($post['created_at']); ?>">
                <?php echo time_elapsed_string($post['created_at']); ?>
            </span>
            <?php if (isset($post['status']) && $post['status'] !== 'posted'): ?>
                <span class="status-badge <?php echo htmlspecialchars($post['status']); ?>">
                    <?php echo ucfirst(htmlspecialchars($post['status'])); ?>
                </span>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($post['user_id'] == $_SESSION['user_id']): ?>
    <div class="post-actions-menu">
        <i class="bi bi-three-dots-vertical post-menu-trigger" data-post-id="<?php echo $post['id']; ?>"></i>
        <div class="post-menu-dropdown" data-post-id="<?php echo $post['id']; ?>">
            <div class="post-menu-item edit-post" data-post-id="<?php echo $post['id']; ?>">
                <i class="bi bi-pencil"></i> Edit Post
            </div>
            <div class="post-menu-item delete-post" data-post-id="<?php echo $post['id']; ?>">
                <i class="bi bi-trash"></i> Delete Post
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

        <div class="post-content">
            <p><?php echo nl2br(htmlspecialchars($post['content'])); ?></p>
        </div>

        <?php if (!empty($post['image_path'])): ?>
        <div class="post-media">
            <img src="<?php echo $post['image_path']; ?>" style="width: 100%; height: auto;">
        </div>
        <?php endif; ?>

        <div class="post-actions">
            <div class="post-action like-button" data-post-id="<?php echo $post['id']; ?>" data-liked="<?php echo $post['user_has_liked'] ? 'true' : 'false'; ?>">
    <i class="bi bi-heart<?php echo $post['user_has_liked'] ? '-fill' : ''; ?>"></i>
    <span><?php echo $post['user_has_liked'] ? 'Liked' : 'Like'; ?></span>
    <small class="text-muted like-count" style="margin-left: 5px;">
        <?php echo $post['likes']; ?>
    </small>
</div>
            <div class="post-action comment-trigger" data-post-id="<?php echo $post['id']; ?>">
                <i class="bi bi-chat-dots"></i>
                <span>Comment</span>
                <small class="text-muted comment-count" style="margin-left: 5px;">
                    <?php echo $post['comment_count']; ?>
                </small>
            </div>
        </div>

        <div class="liked-by">
            <?php if ($post['likes'] > 0): ?>
                <div style="display: flex; align-items: center; margin-top: 10px;">
                    <p style="font-size: 0.8rem; color: var(--text-medium);">
                        <?php 
                        echo $post['likes'] . ' ' . ($post['likes'] === 1 ? 'person' : 'people') . ' liked this';
                        ?>
                    </p>
                </div>
            <?php else: ?>
                <div style="display: flex; align-items: center; margin-top: 10px;">
                    <p style="font-size: 0.8rem; color: var(--text-medium);">Be the first to like this</p>
                </div>
            <?php endif; ?>
        </div>
    </div> <!-- Closing feed div -->
    <?php endforeach; ?>
</div> <!-- Closing feeds div -->
            <!---------------------------------------- END OF MID SECTION     ------------------------------------------->

            <!------------------------------------ FOR NOTIFICATION DISPLAY --------------------------------------------->
            <!-- <div class="notifications-display">
                <div class="heading">
                    <h4>Notifications</h4><i class="bi bi-bell-fill"></i>
                </div>
                <div class="message">
                    <div class="profile_picture">
                        <img src="./web-images/notif1.jpg">
                    </div>
                    <div class="message-body">
                        <h5>Miguel Isles</h5>
                        <p class="text-muted">accepted your friend request</p>
                        <small class="text-muted">1 HOUR AGO</small>
                    </div>
                </div>
                <div class="message">
                    <div class="profile_picture">
                        <img src="./web-images/notif2.jpg">
                    </div>
                    <div class="message-body">
                        <h5>Zakari Cuenca</h5>
                        <p class="text-muted">accepted your friend request</p>
                        <small class="text-muted">1 DAY AGO</small>
                    </div>
                </div>
                <div class="message">
                    <div class="profile_picture">
                        <img src="./web-images/notif3.jpg">
                    </div>
                    <div class="message-body">
                        <h5>Oliver Valenzuela</h5>
                        <p class="text-muted">accepted your friend request</p>
                        <small class="text-muted">3 HOURS AGO</small>
                    </div>
                </div>
            </div> -->
            

            <!------------------------------------------ RIGHT SECTION --------------------------------------------->
            <!-- <div class="right">
                <div class="messages">
                    <div class="heading">
                        <h4>Messages</h4><i class="bi bi-pencil-square"></i>
                    </div>
                    <div class="search-bar">
                        <i class="bi bi-search"></i>
                        <input type="search" placeholder="Search Messages" id="message-search">
                    </div>
                    <div class="category">
                        <h6 class="active">Primary</h6>
                        <h6>General</h6>
                        <h6 class="message-requests">Requests(2)</h6>
                    </div>
                    <div class="message">
                        <div class="profile_picture">
                            <img src="./web-images/notif2.jpg">
                        </div>
                        <div class="message-body">
                            <h5>Zakari Cuenca</h5>
                            <p class="text-muted">Just woke up bruh</p>
                        </div>
                    </div>
                </div>
                <div class="friend-requests-section">
                    <div class="heading">
                        <h4>Requests</h4>
                    </div>
                    <div class="request-card">
                        <div class="user-info">
                            <div class="profile_picture">
                                <img src="./web-images/notif1.jpg" alt="Miguel Isles">
                            </div>
                            <div class="details">
                                <h5>Miguel Isles</h5>
                                <p class="text-muted"><span><img src="./web-images/friend_icon.png" style="width: 1rem; height: 1rem; vertical-align: middle; margin-right: 0.2rem;"></span> 12 mutual friends</p>
                            </div>
                        </div>
                        <div class="actions">
                            <button class="btn btn-primary">Accept</button>
                            <button class="btn">Decline</button>
                        </div>
                    </div>
                </div>
                <div class="contacts-section">
                    <div class="heading">
                        <h4>Contacts</h4>
                        <div class="icons">
                            <i class="bi bi-search"></i>
                            <i class="bi bi-three-dots"></i>
                        </div>
                    </div>
                    <div class="contact-list">
                        <div class="contact-card">
                            <div class="user-info">
                                <div class="profile_picture">
                                    <img src="./web-images/notif1.jpg" alt="KD Kenneth Ace Tolentino">
                                </div>
                                <div class="details">
                                    <h5>KD Kenneth Ace Tolentino</h5>
                                </div>
                            </div>
                        </div>
                        <div class="contact-card">
                            <div class="user-info">
                                <div class="profile_picture">
                                    <img src="./web-images/notif2.jpg" alt="Zakari Cuenca-Ausa">
                                </div>
                                <div class="details">
                                    <h5>Zakari Cuenca-Ausa</h5>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div> -->
            <!---------------------------------------- END OF THE RIGHT SECTION ----------------------------------------->
        <!-- </div>
    </main> -->

    <div class="comment-modal" id="commentModal">
    <div class="comment-modal-content">
        <div class="comment-header">
            <h3>Comments</h3>
            <span class="close-comment">&times;</span>
        </div>
        <div class="comments-container">
            <!-- Scrollable comments area -->
            <div class="comment-list" id="commentList">
                <!-- Comments will be loaded here -->
            </div>
            
            <!-- Fixed comment form at bottom -->
            <form id="commentForm" method="POST" class="comment-form-fixed">
                <div class="reply-indicator" id="replyIndicator" style="display: none;">
                    <span>Replying to <strong id="replyToName"></strong></span>
                    <button type="button" class="cancel-reply" id="cancelReply">&times;</button>
                </div>
                <input type="hidden" name="parent_id" id="replyParentId" value="">
                <textarea class="comment-input" placeholder="Write a comment..." name="comment-content" required rows="2" maxlength="1000"></textarea>
                <button type="submit" class="btn btn-primary">Post Comment</button>
            </form>
        </div>
    </div>
</div>

<!-- Edit Post Modal -->
<div class="edit-post-modal" id="editPostModal">
    <div class="modal-backdrop" style="position:fixed; top:0; left:0; width:100vw; height:100vh; cursor:pointer; z-index:1000;" onclick="document.getElementById('editPostModal').style.display='none';"></div>
    <div class="edit-post-modal-content" style="position:relative; z-index:1001;">
        <div class="edit-post-header">
            <h2>Edit Post</h2>
            <span class="edit-post-close" id="closeEditPost">&times;</span>
        </div>
        <div class="edit-post-body">
            <textarea id="editPostContent" placeholder="Edit your post..."></textarea>
            <img src="" class="edit-image-preview" id="editImagePreview">
            <div class="add-to-post">
                <span>Change image</span>
                <div class="icons">
                    <label for="editPostImage" style="cursor: pointer;">
                        <i class="bi bi-image" style="color: lightgreen;"></i>
                    </label>
                    <input type="file" id="editPostImage" name="editPostImage" accept="image/*" style="display: none;">
                    <button class="btn btn-danger" id="removePostImage">Remove Image</button>
                </div>
            </div>
        </div>
        <div class="edit-post-actions">
            <button class="btn btn-secondary" id="cancelEditPost">Cancel</button>
            <button class="btn btn-primary" id="saveEditPost">Save Changes</button>
        </div>
    </div>
</div>

    
    


    <script>
// Special handling to close modals when clicking outside
window.addEventListener('click', function(event) {
    // Handle Create Post Modal
    const createPostModal = document.getElementById('createPostModal');
    if (createPostModal && (createPostModal.style.display === 'block' || createPostModal.style.display === 'flex')) {
        // Check if click is outside the modal content
        if (event.target === createPostModal) {
            createPostModal.style.display = 'none';
            console.log('Closed create post modal via window click');
        }
    }
    
    // Handle Edit Post Modal
    const editPostModal = document.getElementById('editPostModal');
    if (editPostModal && (editPostModal.style.display === 'flex' || editPostModal.style.display === 'block')) {
        // Check if click is outside the modal content
        if (event.target === editPostModal) {
            editPostModal.style.display = 'none';
            console.log('Closed edit post modal via window click');
        }
    }
    
    // Handle Edit Comment Modal
    const editCommentModal = document.getElementById('editCommentModal');
    if (editCommentModal && (editCommentModal.style.display === 'flex' || editCommentModal.style.display === 'block')) {
        // Check if click is outside the modal content
        if (event.target === editCommentModal) {
            editCommentModal.style.display = 'none';
            console.log('Closed edit comment modal via window click');
        }
    }
});

// TIME AGO FUNCTIONS - Moved to the top for better organization
// Server-client clock sync: PHP renders time with the SERVER clock, but JS `new Date()`
// uses the CLIENT clock. If they differ by a few seconds, the page shows the right
// time on first paint (e.g. "14 seconds ago") then jumps backwards (e.g. "9 seconds
// ago") as soon as this JS runs. We capture the offset once and reuse it.
(function initServerClockSync() {
    if (window.__serverNow) return; // already initialised (e.g. duplicate script block)
    var serverNowStr = "<?php echo gmdate('Y-m-d H:i:s'); ?>";
    var serverNowMs = new Date(serverNowStr.replace(' ', 'T') + 'Z').getTime();
    var clientLoadMs = Date.now();
    window.__clockOffsetMs = (serverNowMs && isFinite(serverNowMs)) ? (serverNowMs - clientLoadMs) : 0;
    window.__serverNow = function () { return new Date(Date.now() + window.__clockOffsetMs); };
})();
function updateAllTimeAgo() {
    document.querySelectorAll('.time-ago').forEach(element => {
        const dateString = element.getAttribute('data-timestamp');
        if (dateString) {
            element.textContent = formatTimeAgo(dateString);
        }
    });
}

function formatTimeAgo(dateString) {
    // DB stores UTC "2026-08-23 10:07:00" — JS would treat it as local time, causing 8h offset
    let dateStr = dateString;
    if (dateStr && !dateStr.includes('Z') && !dateStr.includes('+') && !dateStr.match(/T.*[+-]/)) {
        // No timezone info: treat as UTC
        dateStr = dateStr.replace(' ', 'T') + 'Z';
    }
    const date = new Date(dateStr);
    const now = window.__serverNow ? window.__serverNow() : new Date();
    const secondsPast = (now - date) / 1000;

    if (secondsPast < 1) return 'just now';
    if (secondsPast < 60) return `${Math.floor(secondsPast)} seconds ago`;
    if (secondsPast < 3600) return `${Math.floor(secondsPast / 60)} minutes ago`;
    if (secondsPast < 86400) return `${Math.floor(secondsPast / 3600)} hours ago`;
    if (secondsPast < 604800) return `${Math.floor(secondsPast / 86400)} days ago`;
    if (secondsPast < 2419200) return `${Math.floor(secondsPast / 604800)} weeks ago`;
    if (secondsPast < 29030400) return `${Math.floor(secondsPast / 2419200)} months ago`;
    return `${Math.floor(secondsPast / 29030400)} years ago`;
}

document.addEventListener('DOMContentLoaded', function() {
    // Initial time update (now matches server-rendered value thanks to clock sync)
    updateAllTimeAgo();

    // Update every second so "N seconds ago" ticks forward instead of going stale/backwards
    setInterval(updateAllTimeAgo, 1000);
    
    // Add global click handler to close modals when clicking outside
    document.body.addEventListener('click', function(e) {
        // Create post modal - close when clicking outside
        const createPostModal = document.getElementById('createPostModal');
        if (createPostModal && (createPostModal.style.display === 'block' || createPostModal.style.display === 'flex') && !createPostModal.querySelector('.modal-content').contains(e.target) && e.target === createPostModal) {
            createPostModal.style.display = 'none';
            console.log('Closed create post modal by global click outside');
        }
        
        // Edit post modal - close when clicking outside
        const editPostModal = document.getElementById('editPostModal');
        if (editPostModal && editPostModal.style.display === 'flex' && !editPostModal.querySelector('.edit-post-modal-content').contains(e.target) && e.target === editPostModal) {
            editPostModal.style.display = 'none';
            console.log('Closed edit post modal by global click outside');
        }
    });
    
    // Check if modals exist in the DOM
    console.log('Checking if modals exist in the DOM:');
    const editCommentModal = document.getElementById('editCommentModal');
    const editPostModal = document.getElementById('editPostModal');
    const deleteCommentModal = document.getElementById('deleteCommentConfirmationOverlay');
    
    console.log('Edit Comment Modal exists:', !!editCommentModal);
    console.log('Edit Post Modal exists:', !!editPostModal);
    console.log('Delete Comment Modal exists:', !!deleteCommentModal);
    
    if (editCommentModal) {
        console.log('Edit Comment Modal display style:', getComputedStyle(editCommentModal).display);
        console.log('Edit Comment Modal z-index:', getComputedStyle(editCommentModal).zIndex);
    }
    
    if (editPostModal) {
        console.log('Edit Post Modal display style:', getComputedStyle(editPostModal).display);
        console.log('Edit Post Modal z-index:', getComputedStyle(editPostModal).zIndex);
    }

    // MAIN FUNCTIONALITY
    console.log('DOM fully loaded');
    
    // Right messages section
    const rightMessagesSection = document.querySelector('.right .messages');
    if (rightMessagesSection) rightMessagesSection.style.display = 'none';

    // Create post modal
    const createPostInput = document.querySelector('.create-post input[type="text"]');
    if (createPostInput) createPostInput.setAttribute('autocomplete', 'off');

    const inputArea = document.querySelector('.create-post .input-area input[name="create-post"]');
    const modal = document.getElementById('createPostModal');
    const closeButton = modal?.querySelector('.close-button');
    const createPostButton = document.querySelector('label[for="create_post"]');
    const photoImageOption = document.querySelector('.option:nth-child(1)');
    const feelingActivityOption = document.querySelector('.option:nth-child(2)');

    // Function to show create post modal
    const showCreatePostModal = () => {
        if (modal) {
            modal.style.display = "flex"; // Use flex instead of block for better centering
        }
    };

    // Event listeners for all create post triggers
    if (inputArea) {
        inputArea.addEventListener('click', showCreatePostModal);
    }

    if (createPostButton) {
        createPostButton.addEventListener('click', showCreatePostModal);
    }

    if (photoImageOption) {
        photoImageOption.addEventListener('click', showCreatePostModal);
    }

    if (feelingActivityOption) {
        feelingActivityOption.addEventListener('click', showCreatePostModal);
    }

    if (closeButton) {
        closeButton.addEventListener('click', function() {
            modal.style.display = "none";
        });
    }
    
    // Close create post modal when clicking outside the content
    if (modal) {
        modal.addEventListener('click', function(e) {
            // Check if click is directly on the modal container but not on the modal content
            if (e.target === modal) {
                modal.style.display = "none";
                console.log('Closed create post modal by clicking outside');
            }
        });
    }

    window.addEventListener('click', function(event) {
        // Close create post modal when clicking outside
        if (event.target == modal) {
            modal.style.display = "none";
        }
        
        // Close edit post modal when clicking outside
        const editPostModal = document.getElementById('editPostModal');
        if (editPostModal && event.target == editPostModal) {
            editPostModal.style.display = "none";
            console.log('Closed edit post modal by clicking outside');
        }
    });

    // POST CREATION AND IMAGE PREVIEW
    const postForm = document.getElementById('postForm');
    const postImageInput = document.getElementById('post-image');
    const imagePreviewContainer = document.getElementById('imagePreviewContainer');
    const imageIcon = document.querySelector('#createPostModal .bi-image');

    // Image preview functionality
    const createImagePreview = (imageSrc) => {
        const previewWrapper = document.createElement('div');
        previewWrapper.className = 'image-preview-wrapper';
        
        const img = document.createElement('img');
        img.className = 'image-preview';
        img.src = imageSrc;
        
        const removeBtn = document.createElement('button');
        removeBtn.className = 'remove-image-btn';
        removeBtn.innerHTML = '×';
        removeBtn.addEventListener('click', () => {
            previewWrapper.remove();
            updatePreviewContainerVisibility();
        });
        
        previewWrapper.appendChild(img);
        previewWrapper.appendChild(removeBtn);
        imagePreviewContainer.appendChild(previewWrapper);
        
        updatePreviewContainerVisibility();
    };

    const updatePreviewContainerVisibility = () => {
        if (imagePreviewContainer) {
            imagePreviewContainer.style.display = 
                imagePreviewContainer.children.length > 0 ? 'grid' : 'none';
        }
    };

    // Handle image upload
    if (postImageInput) {
        postImageInput.addEventListener('change', function(e) {
            if (imagePreviewContainer) {
                imagePreviewContainer.innerHTML = '';
                
                Array.from(e.target.files).forEach(file => {
                    if (file.type.startsWith('image/')) {
                        const reader = new FileReader();
                        reader.onload = (event) => createImagePreview(event.target.result);
                        reader.readAsDataURL(file);
                    }
                });
            }
        });
    }

    // Trigger file input when image icon is clicked
    if (imageIcon && postImageInput) {
        imageIcon.addEventListener('click', (e) => {
            e.preventDefault();
            postImageInput.click();
        });
    }

    // Form submission handling
    if (postForm) {
        postForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalBtnText = submitBtn.textContent;
            const postContent = this.querySelector('#postContent').value.trim();
            const imageInput = this.querySelector('#post-image');
            
            // Validate: must have either content or image
            if (postContent === '' && (!imageInput.files || imageInput.files.length === 0)) {
                alert('Please add some text or an image to your post.');
                return;
            }
            
            submitBtn.disabled = true;
            submitBtn.textContent = 'Posting...';
            
            // Create FormData object
            const formData = new FormData(this);
            
            // Make AJAX request
            fetch('create_post.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    // Close the modal
                    modal.style.display = 'none';
                    
                    // Reload the page to show the new post
                    window.location.reload();
                } else {
                    alert('Error: ' + data.message);
                    submitBtn.disabled = false;
                    submitBtn.textContent = originalBtnText;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while posting');
                submitBtn.disabled = false;
                submitBtn.textContent = originalBtnText;
            });
        });
    }

    // LOGOUT FUNCTIONALITY
    const logoutButton = document.getElementById('logoutButton');
    const logoutOverlay = document.getElementById('logoutConfirmationOverlay');
    const cancelLogout = document.getElementById('cancelLogout');

    if (logoutButton && logoutOverlay) {
        logoutButton.addEventListener('click', function(e) {
            e.preventDefault();
            logoutOverlay.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        });
    }

    if (cancelLogout) {
        cancelLogout.addEventListener('click', function(e) {
            e.preventDefault();
            logoutOverlay.style.display = 'none';
            document.body.style.overflow = 'auto';
        });
    }

    if (logoutOverlay) {
        logoutOverlay.addEventListener('click', function(e) {
            if (e.target === logoutOverlay) {
                logoutOverlay.style.display = 'none';
                document.body.style.overflow = 'auto';
            }
        });
    }
});


// Comment functionality
let currentPostId = null;
const commentModal = document.getElementById('commentModal');
const closeComment = document.querySelector('.close-comment');
const commentForm = document.getElementById('commentForm');

// Open comment modal when clicking comment button - Fix display issue
document.addEventListener('click', function(e) {
    const commentTrigger = e.target.closest('.comment-trigger');
    if (commentTrigger) {
        e.preventDefault();
        console.log('Comment trigger clicked for post ID:', commentTrigger.dataset.postId);
        currentPostId = commentTrigger.dataset.postId;
        
        // Get the modal element
        const commentModal = document.getElementById('commentModal');
        
        // Check if the modal exists
        if (!commentModal) {
            console.error('Comment modal not found in the DOM!');
            alert('Error: Could not find the comment modal');
            return;
        }
        
        // Force display style to be visible with important flag and higher z-index
        commentModal.style.cssText = `
            display: block !important;
            z-index: 9999 !important;
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            width: 100% !important;
            height: 100% !important;
            background-color: rgba(0, 0, 0, 0.6) !important;
            backdrop-filter: blur(3px) !important;
            overflow: auto !important;
        `;
        
        // Also force styling on modal content
        const modalContent = commentModal.querySelector('.comment-modal-content');
        if (modalContent) {
            modalContent.style.cssText = `
                background: white !important;
                border-radius: 10px !important;
                margin: 50px auto !important;
                padding: 20px !important;
                width: 90% !important;
                max-width: 600px !important;
                max-height: 80vh !important;
                overflow: hidden !important;
                display: flex !important;
                flex-direction: column !important;
                box-shadow: 0 5px 20px rgba(0, 0, 0, 0.2) !important;
            `;
            
            // Also style the comments container to have a transparent background
            const commentsContainer = modalContent.querySelector('.comments-container');
            if (commentsContainer) {
                commentsContainer.style.cssText = `
                    flex: 1 !important;
                    overflow: hidden !important;
                    display: flex !important;
                    flex-direction: column !important;
                    background: transparent !important;
                `;
            }
            
            // Style the comment list to have a transparent background
            const commentList = modalContent.querySelector('.comment-list');
            if (commentList) {
                commentList.style.cssText = `
                    flex: 1 !important;
                    overflow-y: auto !important;
                    padding: 0 15px !important;
                    margin-bottom: 60px !important;
                    background: transparent !important;
                `;
            }
            
            // Style the comment header to have a transparent background
            const commentHeader = modalContent.querySelector('.comment-header');
            if (commentHeader) {
                commentHeader.style.cssText = `
                    display: flex !important;
                    justify-content: space-between !important;
                    align-items: center !important;
                    margin-bottom: 15px !important;
                    background: transparent !important;
                `;
            }
        }
        
        // Force browser to repaint the element
        void commentModal.offsetWidth;
        
        // Prevent body scrolling
        document.body.style.overflow = 'hidden';
        
        console.log('Comment modal display style:', commentModal.style.display);
        
        // Load comments
        loadComments(currentPostId);
    }
});

// Close comment modal with better cleanup
document.querySelector('.close-comment').addEventListener('click', () => {
    const commentModal = document.getElementById('commentModal');
    
    if (!commentModal) {
        console.error('Comment modal not found in the DOM!');
        return;
    }
    
    commentModal.style.cssText = `display: none !important;`;
    document.body.style.overflow = 'auto'; // Re-enable scrolling
    
    console.log('Comment modal closed');
    
    // Clear comment list when closing to prevent stacking on next open
    setTimeout(() => {
        const commentList = document.getElementById('commentList');
        if (commentList) {
            commentList.innerHTML = '';
        }
    }, 300);
});

// Close modal when clicking outside
document.addEventListener('click', function(e) {
    const commentModal = document.getElementById('commentModal');
    
    if (commentModal && e.target === commentModal) {
        commentModal.style.cssText = `display: none !important;`;
        document.body.style.overflow = 'auto'; // Re-enable scrolling
        
        console.log('Comment modal closed by clicking outside');
        
        // Clear comment list when closing to prevent stacking on next open
        setTimeout(() => {
            const commentList = document.getElementById('commentList');
            if (commentList) {
                commentList.innerHTML = '';
            }
        }, 300);
    }
});

// Reply state
let currentReplyToId = null;
let currentReplyToName = null;

function setReplyTo(commentId, authorName) {
    currentReplyToId = commentId;
    currentReplyToName = authorName;
    document.getElementById('replyIndicator').style.display = 'flex';
    document.getElementById('replyToName').textContent = authorName;
    document.getElementById('replyParentId').value = commentId;
    document.getElementById('messageInput')?.focus();
}

function clearReply() {
    currentReplyToId = null;
    currentReplyToName = null;
    document.getElementById('replyIndicator').style.display = 'none';
    document.getElementById('replyParentId').value = '';
}

// Cancel reply button
document.getElementById('cancelReply')?.addEventListener('click', clearReply);

// Handle comment submission
commentForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);
    formData.append('post_id', currentPostId);
    
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        
        if (!response.ok) throw new Error('Network response was not ok');
        
        const data = await response.json();
        
        if (data.success) {
            form.querySelector('textarea').value = '';
            clearReply();
            const commentCountElement = document.querySelector(`[data-post-id="${currentPostId}"] .comment-count`);
            if (commentCountElement) {
                commentCountElement.textContent = data.new_count;
            }
            loadComments(currentPostId);
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Error posting comment. Please try again.');
    }
});

// Function to load comments for a post
function loadComments(postId) {
    console.log('Loading comments for post ID:', postId);
    
    const commentList = document.getElementById('commentList');
    if (!commentList) {
        console.error('Comment list element not found in the DOM!');
        return;
    }
    
    // Clear existing comments first to prevent stacking
    commentList.innerHTML = '<div class="loading-comments" style="text-align: center; padding: 20px;">Loading comments...</div>';
    
    fetch(`get_comments.php?post_id=${postId}`)
        .then(response => {
            console.log('Got response from server:', response.status);
            
            // First check if the response is OK
            if (!response.ok) {
                throw new Error(`Server responded with status: ${response.status}`);
            }
            
            // Then check if the response is JSON
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                return response.text().then(text => {
                    console.error('Invalid content type:', contentType, 'Response text:', text.substring(0, 100));
                    throw new Error(`Invalid response format: ${contentType}`);
                });
            }
            
            return response.json();
        })
        .then(data => {
            if (data.error) {
                throw new Error(data.error);
            }
            
            commentList.innerHTML = '';
            clearReply();
            
            if (!data.comments || data.comments.length === 0) {
                commentList.innerHTML = '<div class="no-comments" style="text-align: center; padding: 40px; color: #888;">No comments yet. Be the first to comment!</div>';
                return;
            }
            
            commentList.style.maxHeight = '400px';
            commentList.style.overflowY = 'auto';
            commentList.style.padding = '0 15px';
            commentList.style.marginBottom = '60px';
            commentList.style.background = 'transparent';
            
            data.comments.forEach(comment => {
                try {
                    const commentElement = createCommentElement(comment);
                    commentList.appendChild(commentElement);
                } catch(err) {
                    console.error('Error rendering comment', comment.id, err);
                }
            });
        })
        .catch(error => {
            console.error('Error loading comments:', error);
            commentList.innerHTML = `
                <div class="error-loading" style="text-align: center; padding: 30px; color: #e74c3c;">
                    <p style="margin-bottom: 15px;">Error loading comments: ${error.message}</p>
                    <button onclick="loadComments('${postId}')" style="padding: 8px 16px; background: #3498db; color: white; border: none; border-radius: 4px; cursor: pointer;">Try Again</button>
                </div>
            `;
        });
}

// Function to create a comment element
function createCommentElement(comment) {
    const formattedContent = comment.content.replace(/\r\n|\r|\n/g, '<br>');
    const isCommentAuthor = <?php echo $_SESSION['user_id']; ?> === parseInt(comment.user_id);
    
    const div = document.createElement('div');
    div.className = 'comment-item new-comment';
    div.dataset.commentId = comment.id;
    
    const avatarHtml = comment.profile_picture 
        ? `<img src="${comment.profile_picture}" class="comment-avatar" alt="User avatar">`
        : (() => { const f = (comment.first_name||'')[0]||''; const l = (comment.last_name||'')[0]||''; const n = (comment.first_name||'')+(comment.last_name||''); let h=0; for(let i=0;i<n.length;i++){h=(h*31+n.charCodeAt(i))&0x7FFFFFFF;} const c=['#2B9E9E','#3CB5A6','#E67E22','#3498DB','#9B59B6','#E74C3C','#1ABC9C','#2C3E50']; return `<div class="comment-avatar initials-avatar" style="background:${c[h%c.length]};color:#fff;display:flex;align-items:center;justify-content:center;font-weight:600;font-size:14px;font-family:Poppins,sans-serif;">${(f+l).toUpperCase()}</div>`; })()
    
    const replyToHtml = comment.reply_to_name 
        ? `<div style="font-size:0.75rem;color:#008080;margin-bottom:2px;display:flex;align-items:center;gap:4px;"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 14 4 9 9 4"></polyline><path d="M20 20v-7a4 4 0 0 0-4-4H4"></path></svg> Replying to <strong>${comment.reply_to_name}</strong></div>`
        : '';
    
    div.innerHTML = `
        <div class="comment-content">
            ${avatarHtml}
            <div class="comment-body">
                ${replyToHtml}
                <div style="display: flex; width: 100%; background: transparent;">
                    <h4 class="comment-author" style="background: transparent; margin: 0; padding: 0;">${comment.first_name} ${comment.last_name}</h4>
                </div>
                <p class="comment-text">${formattedContent}</p>
                <div class="comment-time-row">
                    <small class="comment-time" style="font-size: 0.75rem; color: #999;" data-timestamp="${comment.created_at}">${formatTimeAgo(comment.created_at)}</small>
                    <button class="reply-btn" data-comment-id="${comment.id}" data-author="${comment.first_name} ${comment.last_name}">Reply</button>
                </div>
            </div>
            ${isCommentAuthor ? `
            <div class="comment-actions" style="position: absolute; right: 5px; top: 5px; z-index: 100;">
                <i class="bi bi-three-dots-vertical comment-menu-trigger" data-comment-id="${comment.id}" style="cursor: pointer; padding: 8px; border-radius: 50%; transition: background-color 0.2s;"></i>
            </div>
            ` : ''}
        </div>
    `;
    
    const commentText = div.querySelector('.comment-text');
    if (commentText) {
        commentText.style.cssText = `
            position: relative !important;
            background: #f0f2f5 !important;
            padding: 8px 12px !important;
            border-radius: 18px !important;
            word-wrap: break-word !important;
            overflow-wrap: break-word !important;
            width: fit-content !important;
            max-width: 100% !important;
            margin: 2px 0 !important;
            line-height: 1.4 !important;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05) !important;
        `;
    }
    
    const commentContent = div.querySelector('.comment-content');
    if (commentContent) {
        commentContent.style.cssText = `
            display: flex !important;
            align-items: flex-start !important;
            width: 100% !important;
            background: transparent !important;
            position: relative !important;
        `;
    }
    
    div.style.background = 'transparent';
    
    const commentBody = div.querySelector('.comment-body');
    if (commentBody) {
        commentBody.style.cssText = `
            flex: 1 !important;
            max-width: calc(100% - 50px) !important;
            display: flex !important;
            flex-direction: column !important;
            background: transparent !important;
        `;
    }
    
    // Reply button click
    const replyBtn = div.querySelector('.reply-btn');
    if (replyBtn) {
        replyBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            setReplyTo(comment.id, comment.first_name + ' ' + comment.last_name);
            commentForm.querySelector('textarea').focus();
        });
    }
    
    // Menu trigger
    const menuTrigger = div.querySelector('.comment-menu-trigger');
    if (menuTrigger) {
        menuTrigger.addEventListener('mouseenter', () => {
            menuTrigger.style.backgroundColor = '#f0f0f0';
        });
        menuTrigger.addEventListener('mouseleave', () => {
            menuTrigger.style.backgroundColor = 'transparent';
        });
        
        menuTrigger.addEventListener('click', (e) => {
            e.stopPropagation();
            
            document.querySelectorAll('.floating-comment-menu').forEach(menu => {
                document.body.removeChild(menu);
            });
            
            const floatingMenu = document.createElement('div');
            floatingMenu.className = 'floating-comment-menu';
            floatingMenu.dataset.commentId = comment.id;
            
            floatingMenu.style.cssText = `
                position: fixed;
                background: white;
                border-radius: 8px;
                box-shadow: 0 4px 15px rgba(0,0,0,0.2);
                z-index: 9999;
                min-width: 180px;
                padding: 5px 0;
            `;
            
            floatingMenu.innerHTML = `
                <div class="comment-menu-item edit-comment" data-comment-id="${comment.id}" style="color: var(--color-primary); padding: 10px 15px; cursor: pointer; display: flex; align-items: center;">
                    <i class="bi bi-pencil-fill" style="margin-right: 8px; font-size: 14px;"></i> Edit Comment
                </div>
                <div class="comment-menu-item delete-comment" data-comment-id="${comment.id}" style="color: #e74c3c; padding: 10px 15px; cursor: pointer; display: flex; align-items: center;">
                    <i class="bi bi-trash-fill" style="margin-right: 8px; font-size: 14px;"></i> Delete Comment
                </div>
            `;
            
            const triggerRect = menuTrigger.getBoundingClientRect();
            floatingMenu.style.top = (triggerRect.bottom + 5) + 'px';

            const rightEdgeOffset = window.innerWidth - triggerRect.right;
            if (rightEdgeOffset < 180) {
                floatingMenu.style.right = (rightEdgeOffset + 10) + 'px';
                floatingMenu.style.left = 'auto';
            } else {
                floatingMenu.style.left = triggerRect.right + 'px';
            }
            
            document.body.appendChild(floatingMenu);
            
            floatingMenu.querySelector('.edit-comment').addEventListener('click', () => {
                const editEvent = new CustomEvent('edit-comment-clicked', {
                    detail: { commentId: comment.id }
                });
                document.dispatchEvent(editEvent);
                document.body.removeChild(floatingMenu);
            });
            
            floatingMenu.querySelector('.delete-comment').addEventListener('click', () => {
                const deleteEvent = new CustomEvent('delete-comment-clicked', {
                    detail: { commentId: comment.id }
                });
                document.dispatchEvent(deleteEvent);
                document.body.removeChild(floatingMenu);
            });
            
            setTimeout(() => {
                const closeMenu = (e) => {
                    if (!floatingMenu.contains(e.target) && e.target !== menuTrigger) {
                        if (document.body.contains(floatingMenu)) {
                            document.body.removeChild(floatingMenu);
                        }
                        document.removeEventListener('click', closeMenu);
                    }
                };
                document.addEventListener('click', closeMenu);
            }, 100);
        });
    }
    
    // If this comment has replies, render them nested with "View replies" toggle
    if (comment.replies && comment.replies.length > 0) {
        const repliesContainer = document.createElement('div');
        repliesContainer.className = 'replies-container';
        
        if (comment.replies.length > 1) {
            // Hide ALL replies behind a toggle
            const hiddenReplies = document.createElement('div');
            hiddenReplies.className = 'hidden-replies';
            hiddenReplies.style.display = 'none';
            comment.replies.forEach(reply => {
                hiddenReplies.appendChild(createCommentElement(reply));
            });
            repliesContainer.appendChild(hiddenReplies);
            
            const toggleBtn = document.createElement('button');
            toggleBtn.className = 'view-replies-btn';
            toggleBtn.textContent = `View ${comment.replies.length} replies`;
            toggleBtn.style.cssText = 'background:none;border:none;color:#008080;font-size:0.8rem;cursor:pointer;padding:4px 0;margin-top:4px;font-weight:500;display:block;';
            let expanded = false;
            toggleBtn.addEventListener('click', () => {
                expanded = !expanded;
                hiddenReplies.style.display = expanded ? 'block' : 'none';
                toggleBtn.textContent = expanded 
                    ? 'Hide replies' 
                    : `View ${comment.replies.length} replies`;
            });
            repliesContainer.appendChild(toggleBtn);
        } else {
            comment.replies.forEach(reply => {
                repliesContainer.appendChild(createCommentElement(reply));
            });
        }
        
        div.appendChild(repliesContainer);
    }
    
    return div;
}

// Helper function to format time (reuses the server-synced formatTimeAgo defined above;
// kept as a guarded alias so the duplicate definition can't overwrite the synced version)
if (typeof formatTimeAgoComment === 'undefined' && typeof formatTimeAgo !== 'undefined') {
    var formatTimeAgoComment = formatTimeAgo;
}

document.addEventListener('click', async function(e) {
    const likeButton = e.target.closest('.like-button');
    if (likeButton) {
        e.preventDefault();
        
        // Prevent multiple clicks
        if (likeButton.classList.contains('processing')) {
            return;
        }
        
        // Add processing class
        likeButton.classList.add('processing');
        
        const postId = likeButton.dataset.postId;
        const isLiked = likeButton.dataset.liked === 'true';
        const action = isLiked ? 'unlike' : 'like';
        
        // Get all elements we need to update
        const heartIcon = likeButton.querySelector('i');
        const likeText = likeButton.querySelector('span');
        const likeCountElement = likeButton.querySelector('.like-count');
        const likedBySection = likeButton.closest('.feed').querySelector('.liked-by');
        
        // Optimistic UI update
        if (isLiked) {
            // Changing from liked to unliked
            heartIcon.classList.remove('bi-heart-fill');
            heartIcon.classList.add('bi-heart');
            likeText.textContent = 'Like';
            likeButton.dataset.liked = 'false';
            likeCountElement.textContent = parseInt(likeCountElement.textContent) - 1;
        } else {
            // Changing from unliked to liked
            heartIcon.classList.remove('bi-heart');
            heartIcon.classList.add('bi-heart-fill');
            likeText.textContent = 'Liked';
            likeButton.dataset.liked = 'true';
            likeCountElement.textContent = parseInt(likeCountElement.textContent) + 1;
        }

        // Update the "liked by" section
        if (likedBySection) {
            const newLikes = parseInt(likeCountElement.textContent);
            likedBySection.innerHTML = `
                <div style="display: flex; align-items: center; margin-top: 10px;">
                    <p style="font-size: 0.8rem; color: var(--text-medium);">
                        ${newLikes > 0 ? 
                            `${newLikes} ${newLikes === 1 ? 'person' : 'people'} liked this` : 
                            'Be the first to like this'}
                    </p>
                </div>
            `;
        }

        // Rest of your AJAX code remains the same...
        try {
            const response = await fetch('like_post.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `post_id=${postId}&action=${action}`
            });
            
            if (!response.ok) {
                throw new Error(`Server error: ${response.status}`);
            }
            
            const data = await response.json();
            
            if (data.success) {
                // Update like count with accurate count from server
                likeCountElement.textContent = data.likes;
                
                // Add animation effect
                likeButton.style.transform = 'scale(1.2)';
                setTimeout(() => {
                    likeButton.style.transform = '';
                }, 300);
                
                console.log(`Post ${postId} ${action} successful, likes: ${data.likes}`);
            } else {
                throw new Error(data.error || 'Unknown error');
            }
        } catch (error) {
            console.error('Error:', error);
            // Revert changes on error
            if (isLiked) {
                heartIcon.classList.remove('bi-heart');
                heartIcon.classList.add('bi-heart-fill');
                likeText.textContent = 'Liked';
                likeButton.dataset.liked = 'true';
            } else {
                heartIcon.classList.remove('bi-heart-fill');
                heartIcon.classList.add('bi-heart');
                likeText.textContent = 'Like';
                likeButton.dataset.liked = 'false';
            }
            likeCountElement.textContent = parseInt(likeCountElement.textContent) + (isLiked ? 1 : -1);
        } finally {
            // Remove processing class when done
            setTimeout(() => {
                likeButton.classList.remove('processing');
            }, 300);
        }
    }
});

// Post menu functionality
document.addEventListener('click', function(e) {
    // Toggle post menu
    if (e.target.classList.contains('post-menu-trigger')) {
        const postId = e.target.dataset.postId;
        const dropdown = document.querySelector(`.post-menu-dropdown[data-post-id="${postId}"]`);
        
        // Close all other dropdowns first
        document.querySelectorAll('.post-menu-dropdown').forEach(d => {
            if (d !== dropdown) d.classList.remove('show');
        });
        
        dropdown.classList.toggle('show');
        e.stopPropagation();
    }
    
    // Close dropdown when clicking outside
    if (!e.target.closest('.post-actions-menu')) {
        document.querySelectorAll('.post-menu-dropdown').forEach(d => {
            d.classList.remove('show');
        });
    }
});

// Delete post functionality
let postToDelete = null;

document.addEventListener('click', async function(e) {
    if (e.target.classList.contains('delete-post') || e.target.closest('.delete-post')) {
        e.preventDefault();
        e.stopPropagation();
        
        const postId = e.target.dataset.postId || e.target.closest('.delete-post').dataset.postId;
        postToDelete = postId;
        
        console.log('Delete post clicked for post ID:', postId);
        
        // Show delete confirmation modal
        const deleteOverlay = document.getElementById('deletePostConfirmationOverlay');
        
        if (!deleteOverlay) {
            console.error('Delete post modal not found in the DOM!');
            alert('Error: Could not find the delete post modal.');
            return;
        }
        
        // Show the modal with inline styles to ensure it displays
        deleteOverlay.style.cssText = `
            display: flex !important;
            z-index: 9999 !important;
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            width: 100% !important;
            height: 100% !important;
            background: rgba(0, 0, 0, 0.7) !important;
            backdrop-filter: blur(4px) !important;
            justify-content: center !important;
            align-items: center !important;
        `;
        
        // Log modal display status
        console.log('Delete post modal display status:', deleteOverlay.style.display);
        
        // Force browser to repaint
        void deleteOverlay.offsetWidth;
    }
});

// Handle delete confirmation
document.getElementById('confirmDeletePost').addEventListener('click', async function(e) {
    e.preventDefault();
    e.stopPropagation();
    
    if (!postToDelete) {
        console.error('No post ID to delete!');
        return;
    }
    
    console.log('Confirming delete for post ID:', postToDelete);
    
    // Show loading indicator
    const deleteButton = this;
    const originalText = deleteButton.textContent;
    deleteButton.textContent = 'Deleting...';
    deleteButton.disabled = true;
    
    try {
        // Use URLSearchParams for proper encoding
        const formData = new URLSearchParams();
        formData.append('post_id', postToDelete);
        
        console.log('Sending delete request to server...');
        const response = await fetch('delete_post.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: formData.toString()
        });
        
        console.log('Raw response:', response);
        
        // Check if the response is OK
        if (!response.ok) {
            throw new Error(`Server responded with status: ${response.status}`);
        }
        
        // Parse the response JSON
        const responseText = await response.text();
        console.log('Response text:', responseText);
        
        let data;
        try {
            data = JSON.parse(responseText);
        } catch (parseError) {
            console.error('Failed to parse response as JSON:', parseError);
            throw new Error('Server returned invalid JSON');
        }
        
        console.log('Response data:', data);
        
        if (data.success) {
            // Hide the modal
            const deleteOverlay = document.getElementById('deletePostConfirmationOverlay');
            if (deleteOverlay) {
                deleteOverlay.style.display = 'none';
                document.body.style.overflow = 'auto'; // Re-enable scrolling
            }
            
            // Animate the post removal
            const postElement = document.querySelector(`.feed`);
            if (postElement) {
                // Store the original height for smooth animation
                const originalHeight = postElement.offsetHeight;
                
                // First set a fixed height to enable smooth transition
                postElement.style.height = originalHeight + 'px';
                
                // Add the deletion animation class
                postElement.classList.add('post-item-deleting');
                
                // Reduce height after a small delay to allow opacity transition to start
                setTimeout(() => {
                    postElement.style.height = '0px';
                    
                    // Remove the element after the transition completes
                    postElement.addEventListener('transitionend', function handler(e) {
                        // Only proceed if it's the height transition that ended
                        if (e.propertyName === 'height') {
                            // Remove the event listener to prevent multiple calls
                            postElement.removeEventListener('transitionend', handler);
                            
                            // Either reload page or remove the element
                            window.location.reload();
                        }
                    });
                }, 50);
                
                console.log('Post deletion animation started');
            } else {
                // Just reload if we can't find the element
                window.location.reload();
            }
        } else {
            console.error('Server reported error:', data.error);
            alert('Error: ' + data.error);
        }
    } catch (error) {
        console.error('Error deleting post:', error);
        alert('An error occurred while deleting the post: ' + error.message);
    } finally {
        // Reset button state
        deleteButton.textContent = originalText;
        deleteButton.disabled = false;
        postToDelete = null;
    }
});

// Cancel delete post
document.getElementById('cancelDeletePost').addEventListener('click', function(e) {
    e.preventDefault();
    e.stopPropagation();
    
    const deleteOverlay = document.getElementById('deletePostConfirmationOverlay');
    if (deleteOverlay) {
        deleteOverlay.style.display = 'none';
        console.log('Delete post modal hidden');
    } else {
        console.error('Delete post modal not found in the DOM!');
    }
    
    postToDelete = null;
});

// Close delete post modal when clicking outside
document.getElementById('deletePostConfirmationOverlay').addEventListener('click', function(e) {
    if (e.target === this) {
        e.preventDefault();
        e.stopPropagation();
        console.log('Closing delete post modal on outside click');
        this.style.display = 'none';
        postToDelete = null;
    }
});

// Edit post functionality
let currentEditingPostId = null;

document.addEventListener('click', async function(e) {
    if (e.target.classList.contains('edit-post') || e.target.closest('.edit-post')) {
        e.preventDefault();
        e.stopPropagation();
        
        const postId = e.target.dataset.postId || e.target.closest('.edit-post').dataset.postId;
        currentEditingPostId = postId;
        
        console.log('Edit post clicked for post ID:', postId);
        
        // Fetch post data for editing
        try {
            const response = await fetch(`get_post_for_edit.php?post_id=${postId}`);
            const data = await response.json();
            
            if (data.success) {
                const modal = document.getElementById('editPostModal');
                
                if (!modal) {
                    console.error('Edit post modal not found in the DOM!');
                    alert('Error: Could not find the edit post modal.');
                    return;
                }
                
                const content = document.getElementById('editPostContent');
                const imagePreview = document.getElementById('editImagePreview');
                
                if (!content || !imagePreview) {
                    console.error('Edit post form elements not found!');
                    alert('Error: Could not find the edit post form elements.');
                    return;
                }
                
                content.value = data.post.content;
                
                if (data.post.image_path) {
                    imagePreview.src = data.post.image_path;
                    imagePreview.style.display = 'block';
                    document.getElementById('removePostImage').style.display = 'inline-block';
                } else {
                    imagePreview.style.display = 'none';
                    document.getElementById('removePostImage').style.display = 'none';
                }
                
                // Show the modal with inline styles to ensure it displays
                modal.style.cssText = `
                    display: flex !important;
                    z-index: 9999 !important;
                    position: fixed !important;
                    top: 0 !important;
                    left: 0 !important;
                    width: 100% !important;
                    height: 100% !important;
                    background: rgba(0, 0, 0, 0.7) !important;
                    backdrop-filter: blur(4px) !important;
                    justify-content: center !important;
                    align-items: center !important;
                `;
                
                // Log modal display status
                console.log('Edit post modal display status:', modal.style.display);
                
                // Force browser to repaint
                void modal.offsetWidth;
            } else {
                alert('Error: ' + data.error);
            }
        } catch (error) {
            console.error('Error:', error);
            alert('An error occurred while loading the post');
        }
    }
});

// Close edit modal
document.getElementById('closeEditPost').addEventListener('click', function() {
    document.getElementById('editPostModal').style.display = 'none';
    console.log('Closing Edit Post modal via X button');
});

// Add a more specific selector for the Edit Post modal close button
document.querySelectorAll('.edit-post-close').forEach(closeBtn => {
    closeBtn.addEventListener('click', function() {
        // Find the closest modal container
        const modal = this.closest('.edit-post-modal');
        if (modal) {
            modal.style.display = 'none';
            console.log('Closing modal via generic handler:', modal.id);
        }
    });
});

document.getElementById('cancelEditPost').addEventListener('click', function() {
    document.getElementById('editPostModal').style.display = 'none';
});

// Click outside to close edit post modal
document.getElementById('editPostModal').addEventListener('click', function(e) {
    if (e.target === this) {
        this.style.display = 'none';
        console.log('Closed edit post modal by clicking outside');
    }
});

// Handle image change in edit modal
document.getElementById('editPostImage').addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        const reader = new FileReader();
        reader.onload = function(event) {
            const imagePreview = document.getElementById('editImagePreview');
            imagePreview.src = event.target.result;
            imagePreview.style.display = 'block';
            document.getElementById('removePostImage').style.display = 'inline-block';
        };
        reader.readAsDataURL(file);
    }
});

// Handle image removal
document.getElementById('removePostImage').addEventListener('click', function() {
    document.getElementById('editImagePreview').src = '';
    document.getElementById('editImagePreview').style.display = 'none';
    document.getElementById('editPostImage').value = '';
    this.style.display = 'none';
});

// Save edited post
document.getElementById('saveEditPost').addEventListener('click', async function() {
    const content = document.getElementById('editPostContent').value.trim();
    const imageFile = document.getElementById('editPostImage').files[0];
    const hasImageShowing = document.getElementById('editImagePreview').style.display !== 'none';
    const removeImage = document.getElementById('editImagePreview').style.display === 'none';
    
    // Validate: must have either content or image
    if (!content && !imageFile && !hasImageShowing) {
        alert('Please add some text or an image to your post.');
        return;
    }
    
    // Show loading indicator
    const saveButton = this;
    const originalText = saveButton.textContent;
    saveButton.textContent = 'Saving...';
    saveButton.disabled = true;
    
    const formData = new FormData();
    formData.append('post_id', currentEditingPostId);
    formData.append('content', content);
    if (imageFile) {
        formData.append('image', imageFile);
    }
    formData.append('remove_image', removeImage);
    
    try {
        const response = await fetch('update_post.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            // Close the modal
            document.getElementById('editPostModal').style.display = 'none';
            
            // Update the post in the DOM
            const postElement = document.querySelector(`.feed`);
            if (postElement) {
                // Update content
                const contentElement = postElement.querySelector('.post-content p');
                if (contentElement) {
                    contentElement.innerHTML = nl2br(content);
                }
                
                // Update image
                const mediaDiv = postElement.querySelector('.post-media');
                if (data.post.image_path) {
                    if (mediaDiv) {
                        mediaDiv.innerHTML = `<img src="${data.post.image_path}" style="width: 100%; height: auto;">`;
                    } else {
                        // Create image element if it didn't exist before
                        const newMediaDiv = document.createElement('div');
                        newMediaDiv.className = 'post-media';
                        newMediaDiv.innerHTML = `<img src="${data.post.image_path}" style="width: 100%; height: auto;">`;
                        postElement.querySelector('.post-content').after(newMediaDiv);
                    }
                } else if (mediaDiv) {
                    // Remove image if it was removed
                    mediaDiv.remove();
                }
            }
        } else {
            alert('Error: ' + data.error);
        }
    } catch (error) {
        console.error('Error:', error);
        alert('An error occurred while updating the post');
    } finally {
        // Reset button state
        saveButton.textContent = originalText;
        saveButton.disabled = false;
    }
});

// Helper function to convert newlines to <br> like PHP's nl2br
function nl2br(str) {
    return str.replace(/\n/g, '<br>');
}

// COMMENT MENU FUNCTIONALITY
// Variables to track current comment being edited or deleted
let commentToEdit = null;
let commentToDelete = null;

// Set up event listeners for the new custom events
document.addEventListener('edit-comment-clicked', function(e) {
    const commentId = e.detail.commentId;
    console.log('Edit comment event received for comment ID:', commentId);
    
    // Find the comment item
    const commentItem = document.querySelector(`.comment-item[data-comment-id="${commentId}"]`);
    if (!commentItem) {
        console.error('Comment item not found for editing');
        return;
    }
    
    const commentText = commentItem.querySelector('.comment-text').innerHTML;
    
    // Set up edit modal
    commentToEdit = commentId;
    const editCommentModal = document.getElementById('editCommentModal');
    
    if (!editCommentModal) {
        console.error('Edit comment modal not found in the DOM!');
        alert('Error: Could not find the edit comment modal.');
        return;
    }
    
    const editCommentContent = document.getElementById('editCommentContent');
    if (!editCommentContent) {
        console.error('Edit comment content textarea not found!');
        alert('Error: Could not find the edit comment textarea.');
        return;
    }
    
    // Clean up any HTML entities for editing
    const cleanText = commentText.replace(/<br\s*\/?>/gi, '\n');
    editCommentContent.value = cleanText.replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');
    
    // Show the modal with improved display technique
    editCommentModal.style.cssText = `
        display: flex !important;
        z-index: 10000 !important;
        position: fixed !important;
        top: 0 !important;
        left: 0 !important;
        width: 100% !important;
        height: 100% !important;
        background: rgba(0, 0, 0, 0.7) !important;
        backdrop-filter: blur(4px) !important;
        justify-content: center !important;
        align-items: center !important;
    `;
    
    // Force browser to repaint
    void editCommentModal.offsetWidth;
});

document.addEventListener('delete-comment-clicked', function(e) {
    const commentId = e.detail.commentId;
    console.log('Delete comment event received for comment ID:', commentId);
    
    commentToDelete = commentId;
    
    // Show delete confirmation modal
    const deleteOverlay = document.getElementById('deleteCommentConfirmationOverlay');
    
    if (!deleteOverlay) {
        console.error('Delete comment modal not found in the DOM!');
        alert('Error: Could not find the delete comment modal.');
        return;
    }
    
    // Show the modal with inline styles to ensure it displays
    deleteOverlay.style.cssText = `
        display: flex !important;
        z-index: 10000 !important; /* Higher z-index to ensure it's on top */
        position: fixed !important;
        top: 0 !important;
        left: 0 !important;
        width: 100% !important;
        height: 100% !important;
        background: rgba(0, 0, 0, 0.7) !important;
        backdrop-filter: blur(4px) !important;
        justify-content: center !important;
        align-items: center !important;
    `;
    
    document.body.style.overflow = 'hidden'; // Prevent scrolling behind modal
});

// Handle edit comment modal close
document.getElementById('closeEditComment').addEventListener('click', function(e) {
    e.preventDefault();
    e.stopPropagation();
    document.getElementById('editCommentModal').style.display = 'none';
    commentToEdit = null;
    console.log('Closing Edit Comment modal via X button');
});

document.getElementById('cancelEditComment').addEventListener('click', function(e) {
    e.preventDefault();
    e.stopPropagation();
    document.getElementById('editCommentModal').style.display = 'none';
    commentToEdit = null;
});

// Handle edit comment save
document.getElementById('saveEditComment').addEventListener('click', async function(e) {
    e.preventDefault();
    e.stopPropagation();
    
    if (!commentToEdit) {
        console.error('No comment ID to edit!');
        alert('Error: No comment selected for editing.');
        return;
    }
    
    const content = document.getElementById('editCommentContent').value.trim();
    if (!content) {
        alert('Comment cannot be empty');
        return;
    }
    
    console.log('Saving edited comment:', commentToEdit, 'with content:', content);
    
    // Show loading indicator
    const saveButton = this;
    const originalText = saveButton.textContent;
    saveButton.textContent = 'Saving...';
    saveButton.disabled = true;
    
    try {
        // Use URLSearchParams for proper encoding
        const formData = new URLSearchParams();
        formData.append('comment_id', commentToEdit);
        formData.append('content', content);
        
        console.log('Sending update request to server...');
        const response = await fetch('update_comment.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: formData.toString()
        });
        
        console.log('Raw response:', response);
        
        // Check if the response is OK
        if (!response.ok) {
            throw new Error(`Server responded with status: ${response.status}`);
        }
        
        // Parse the response JSON
        const responseText = await response.text();
        console.log('Response text:', responseText);
        
        let data;
        try {
            data = JSON.parse(responseText);
        } catch (parseError) {
            console.error('Failed to parse response as JSON:', parseError);
            throw new Error('Server returned invalid JSON');
        }
        
        console.log('Response data:', data);
        
        if (data.success) {
            // Update comment in DOM
            const commentItem = document.querySelector(`.comment-item[data-comment-id="${commentToEdit}"]`);
            if (commentItem) {
                const commentTextElement = commentItem.querySelector('.comment-text');
                if (commentTextElement) {
                    // Format the content for display
                    const formattedContent = content.replace(/\r\n|\r|\n/g, '<br>');
                    commentTextElement.innerHTML = formattedContent;
                    console.log('Updated comment in DOM');
                } else {
                    console.error('Comment text element not found in DOM');
                }
            } else {
                console.error('Comment item not found in DOM');
            }
            
            // Close the modal
            document.getElementById('editCommentModal').style.display = 'none';
            commentToEdit = null;
            console.log('Comment updated successfully');
        } else {
            console.error('Server reported error:', data.error);
            alert('Error: ' + data.error);
        }
    } catch (error) {
        console.error('Error updating comment:', error);
        alert('An error occurred while updating the comment: ' + error.message);
    } finally {
        // Reset button state
        saveButton.textContent = originalText;
        saveButton.disabled = false;
    }
});

// Handle delete comment cancel
document.getElementById('cancelDeleteComment').addEventListener('click', function(e) {
    e.preventDefault();
    e.stopPropagation();
    
    const deleteOverlay = document.getElementById('deleteCommentConfirmationOverlay');
    if (deleteOverlay) {
        deleteOverlay.style.display = 'none';
        document.body.style.overflow = 'auto'; // Re-enable scrolling
        console.log('Delete comment modal hidden');
    } else {
        console.error('Delete comment modal not found in the DOM!');
    }
    
    commentToDelete = null;
});

// Handle delete comment confirm
document.getElementById('confirmDeleteComment').addEventListener('click', async function(e) {
    e.preventDefault();
    e.stopPropagation();
    
    if (!commentToDelete) {
        console.error('No comment ID to delete!');
        return;
    }
    
    console.log('Confirming delete for comment ID:', commentToDelete);
    
    // Show loading indicator
    const deleteButton = this;
    const originalText = deleteButton.textContent;
    deleteButton.textContent = 'Deleting...';
    deleteButton.disabled = true;
    
    try {
        // Use URLSearchParams for proper encoding
        const formData = new URLSearchParams();
        formData.append('comment_id', commentToDelete);
        
        console.log('Sending delete request to server...');
        const response = await fetch('delete_comment.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: formData.toString()
        });
        
        if (!response.ok) {
            throw new Error(`Server responded with status: ${response.status}`);
        }
        
        const data = await response.json();
        
        if (data.success) {
            // Hide the delete confirmation modal
            const deleteOverlay = document.getElementById('deleteCommentConfirmationOverlay');
            if (deleteOverlay) {
                deleteOverlay.style.display = 'none';
                document.body.style.overflow = 'auto'; // Re-enable scrolling
            }
            
            // Find and animate the comment removal
            const commentItem = document.querySelector(`.comment-item[data-comment-id="${commentToDelete}"]`);
            if (commentItem) {
                // Immediately add the deletion class to start the animation
                commentItem.classList.add('comment-item-deleting');
                
                // Use animation end event to completely remove from DOM
                const animationDuration = 400; // Should match the CSS transition duration in ms
                
                setTimeout(() => {
                    // Remove the element completely from DOM
                    if (commentItem && commentItem.parentNode) {
                        commentItem.parentNode.removeChild(commentItem);
                    }
                    
                    // Update comment count
                    const commentCountElement = document.querySelector(`[data-post-id="${currentPostId}"] .comment-count`);
                    if (commentCountElement) {
                        const newCount = parseInt(commentCountElement.textContent) - 1;
                        commentCountElement.textContent = newCount > 0 ? newCount : '0';
                    }
                    
                    // Check if we need to show "no comments" message
                    const commentList = document.getElementById('commentList');
                    if (commentList && commentList.children.length === 0) {
                        commentList.innerHTML = '<div class="no-comments">No comments yet. Be the first to comment!</div>';
                    }
                    
                    console.log('Comment fully removed from DOM');
                }, animationDuration);
                
                console.log('Comment deletion animation started');
            } else {
                console.error('Comment element not found in DOM');
                // Refresh comments if we can't find the element
                if (currentPostId) {
                    loadComments(currentPostId);
                }
            }
        } else {
            console.error('Server reported error:', data.error);
            alert('Error: ' + data.error);
        }
    } catch (error) {
        console.error('Error deleting comment:', error);
        alert('An error occurred while deleting the comment: ' + error.message);
    } finally {
        // Reset button state
        deleteButton.textContent = originalText;
        deleteButton.disabled = false;
        commentToDelete = null;
    }
});

// Close delete comment modal when clicking outside
document.getElementById('deleteCommentConfirmationOverlay').addEventListener('click', function(e) {
    if (e.target === this) {
        e.stopPropagation();
        this.style.display = 'none';
        commentToDelete = null;
    }
});

// Move modals to be direct children of body for better z-index handling
document.addEventListener('DOMContentLoaded', function() {
    const modals = [
        'editCommentModal',
        'editPostModal',
        'deleteCommentConfirmationOverlay',
        'deletePostConfirmationOverlay'
    ];
    
    modals.forEach(modalId => {
        const modal = document.getElementById(modalId);
        if (modal) {
            // Remove from current position and append to body
            document.body.appendChild(modal);
            console.log(`Moved ${modalId} to be a direct child of body`);
        }
    });
});

// Additional test functions to manually open and close modals
function openEditCommentModal() {
    const modal = document.getElementById('editCommentModal');
    if (modal) {
        modal.style.cssText = `
            display: flex !important;
            z-index: 9999 !important;
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            width: 100% !important;
            height: 100% !important;
            background: rgba(0, 0, 0, 0.7) !important;
            backdrop-filter: blur(4px) !important;
            justify-content: center !important;
            align-items: center !important;
        `;
        console.log('Manual open: Edit comment modal display status:', modal.style.display);
    } else {
        console.error('Edit comment modal not found!');
    }
}

function closeEditCommentModal() {
    const modal = document.getElementById('editCommentModal');
    if (modal) {
        modal.style.display = 'none';
        console.log('Manual close: Edit comment modal hidden');
    }
}

function openEditPostModal() {
    const modal = document.getElementById('editPostModal');
    if (modal) {
        modal.style.cssText = `
            display: flex !important;
            z-index: 9999 !important;
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            width: 100% !important;
            height: 100% !important;
            background: rgba(0, 0, 0, 0.7) !important;
            backdrop-filter: blur(4px) !important;
            justify-content: center !important;
            align-items: center !important;
        `;
        console.log('Manual open: Edit post modal display status:', modal.style.display);
    } else {
        console.error('Edit post modal not found!');
    }
}

function closeEditPostModal() {
    const modal = document.getElementById('editPostModal');
    if (modal) {
        modal.style.display = 'none';
        console.log('Manual close: Edit post modal hidden');
    }
}

// Add test code to try opening modals after DOM is fully loaded
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM fully loaded - adding test functions to window');
    window.openEditCommentModal = openEditCommentModal;
    window.closeEditCommentModal = closeEditCommentModal;
    window.openEditPostModal = openEditPostModal;
    window.closeEditPostModal = closeEditPostModal;
    
    console.log('You can test the modals by opening the browser console and running:');
    console.log('openEditCommentModal()');
    console.log('openEditPostModal()');
});

// Additional test functions for delete modals
function openDeleteCommentModal() {
    const modal = document.getElementById('deleteCommentConfirmationOverlay');
    if (modal) {
        modal.style.cssText = `
            display: flex !important;
            z-index: 9999 !important;
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            width: 100% !important;
            height: 100% !important;
            background: rgba(0, 0, 0, 0.7) !important;
            backdrop-filter: blur(4px) !important;
            justify-content: center !important;
            align-items: center !important;
        `;
        console.log('Manual open: Delete comment modal display status:', modal.style.display);
    } else {
        console.error('Delete comment modal not found!');
    }
}

function closeDeleteCommentModal() {
    const modal = document.getElementById('deleteCommentConfirmationOverlay');
    if (modal) {
        modal.style.display = 'none';
        console.log('Manual close: Delete comment modal hidden');
    }
}

function openDeletePostModal() {
    const modal = document.getElementById('deletePostConfirmationOverlay');
    if (modal) {
        modal.style.cssText = `
            display: flex !important;
            z-index: 9999 !important;
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            width: 100% !important;
            height: 100% !important;
            background: rgba(0, 0, 0, 0.7) !important;
            backdrop-filter: blur(4px) !important;
            justify-content: center !important;
            align-items: center !important;
        `;
        console.log('Manual open: Delete post modal display status:', modal.style.display);
    } else {
        console.error('Delete post modal not found!');
    }
}

function closeDeletePostModal() {
    const modal = document.getElementById('deletePostConfirmationOverlay');
    if (modal) {
        modal.style.display = 'none';
        console.log('Manual close: Delete post modal hidden');
    }
}

// Add test code to try opening modals after DOM is fully loaded
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM fully loaded - adding delete modal test functions to window');
    window.openDeleteCommentModal = openDeleteCommentModal;
    window.closeDeleteCommentModal = closeDeleteCommentModal;
    window.openDeletePostModal = openDeletePostModal;
    window.closeDeletePostModal = closeDeletePostModal;
    
    console.log('You can test the delete modals by opening the browser console and running:');
    console.log('openDeleteCommentModal()');
    console.log('openDeletePostModal()');
});

// Set up all modal close handlers
document.addEventListener('DOMContentLoaded', function() {
    console.log('Setting up modal close handlers');
    
    // Define the modals and their close elements
    const modalConfig = [
        {
            modalId: 'editPostModal',
            closeButtons: ['closeEditPost', 'cancelEditPost']
        },
        {
            modalId: 'editCommentModal',
            closeButtons: ['closeEditComment', 'cancelEditComment']
        },
        {
            modalId: 'deleteCommentConfirmationOverlay',
            closeButtons: ['cancelDeleteComment']
        },
        {
            modalId: 'deletePostConfirmationOverlay',
            closeButtons: ['cancelDeletePost']
        },
        {
            modalId: 'commentModal',
            closeButtons: ['close-comment'] // Using class for this one since it doesn't have an ID
        }
    ];
    
    // Set up each modal
    modalConfig.forEach(config => {
        const modal = document.getElementById(config.modalId);
        if (!modal) {
            console.warn(`Modal ${config.modalId} not found in DOM`);
            return;
        }
        
        // Add click handlers for each close button
        config.closeButtons.forEach(btnId => {
            let closeElement;
            if (btnId.includes('-')) {
                // Assume it's a class if it contains a hyphen
                closeElement = modal.querySelector(`.${btnId}`);
            } else {
                closeElement = document.getElementById(btnId);
            }
            
            if (closeElement) {
                console.log(`Adding close handler for ${config.modalId} using button ${btnId}`);
                closeElement.addEventListener('click', function() {
                    modal.style.display = 'none';
                    if (modal.id === 'commentModal') {
                        document.body.style.overflow = 'auto'; // Re-enable scrolling
                    }
                    console.log(`Closed ${config.modalId} via ${btnId}`);
                });
            } else {
                console.warn(`Close button ${btnId} not found for modal ${config.modalId}`);
            }
        });
        
        // Close modal when clicking outside (for overlay type modals)
        if (config.modalId.includes('Overlay') || config.modalId === 'commentModal') {
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    modal.style.display = 'none';
                    document.body.style.overflow = 'auto'; // Re-enable scrolling
                    console.log(`Closed ${config.modalId} by clicking outside`);
                }
            });
        }
    });
});

// For even more space (e.g., 120px from top)
adjustModalPosition('editProfileModal', 120);
adjustModalPosition('editDetailsModal', 120);

// For less space (e.g., 90px from top)
adjustModalPosition('editProfileModal', 90);
adjustModalPosition('editDetailsModal', 90);

document.addEventListener('DOMContentLoaded', function() {
    const createPostModal = document.getElementById('createPostModal');
    const openModalBtn = document.querySelector('.create-post .input-area');
    const closeModalBtn = document.querySelector('.close-button');
    const postForm = document.getElementById('postForm');
    const postImage = document.getElementById('post-image');
    const imagePreviewContainer = document.getElementById('imagePreviewContainer');
    
    // Add dedicated click handler for create post modal
    if (createPostModal) {
        createPostModal.addEventListener('mousedown', function(e) {
            // Check if click is directly on the modal container (not its content)
            if (e.target === createPostModal) {
                createPostModal.style.display = 'none';
                console.log('Closed create post modal by direct click on modal background');
            }
        });
    }
    
    // Removed logout button functionality
    // const logoutButton = document.getElementById('logoutButton');
    // const logoutOverlay = document.getElementById('logoutConfirmationOverlay');
    // const cancelLogout = document.getElementById('cancelLogout');
    
    // if (logoutButton && logoutOverlay) {
    //     logoutButton.addEventListener('click', function(e) {
    //         e.preventDefault();
    //         logoutOverlay.style.display = 'flex';
    //     });
    // }
    
    // if (cancelLogout) {
    //     cancelLogout.addEventListener('click', function(e) {
    //         e.preventDefault();
    //         logoutOverlay.style.display = 'none';
    //     });
    // }
    
    // if (logoutOverlay) {
    //     logoutOverlay.addEventListener('click', function(e) {
    //         if (e.target === logoutOverlay) {
    //             logoutOverlay.style.display = 'none';
    //             document.body.style.overflow = 'auto';
    //         }
    //     });
    // }

    // Open modal
    if (openModalBtn && createPostModal) {
        openModalBtn.addEventListener('click', function() {
            createPostModal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        });
    }
});
</script>
<!-- Post Status Updates Scripts -->
<script src="toast-notification.js?v=<?php echo time(); ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Add homepage class to body for specific CSS targeting
    document.body.classList.add('homepage');
});
</script>
<!-- Include post status checker for real-time updates from admin actions -->
<script src="post_status_checker.js?v=<?php echo time(); ?>"></script>
</body>
</html>