<?php
session_start();
require_once 'db_connection.php';
date_default_timezone_set('Asia/Manila');

if (!function_exists('getInitialsHtml')) {
    function getInitialsHtml($first, $last, $size = 44) {
        $f = mb_strtoupper(mb_substr(trim($first), 0, 1));
        $l = mb_strtoupper(mb_substr(trim($last), 0, 1));
        $initials = $f . $l;
        $colors = ['#2B9E9E','#3CB5A6','#E67E22','#3498DB','#9B59B6','#E74C3C','#1ABC9C','#2C3E50'];
        $hash = 0;
        $name = trim($first . $last);
        for ($i = 0; $i < mb_strlen($name); $i++) { $hash = ($hash * 31 + mb_ord(mb_substr($name, $i, 1))) & 0x7FFFFFFF; }
        $bg = $colors[$hash % count($colors)];
        return '<div class="initials-avatar" style="width:'.$size.'px;height:'.$size.'px;border-radius:50%;background:'.$bg.';color:#fff;display:flex;align-items:center;justify-content:center;font-weight:600;font-size:'.($size * 0.38).'px;font-family:Poppins,sans-serif;flex-shrink:0;">'.$initials.'</div>';
    }
}

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// Verify admin status or auto-detect from configured admin email
if (empty($_SESSION['is_admin'])) {
    $adminCheck = $pdo->prepare("SELECT email, is_admin FROM users WHERE id = ?");
    $adminCheck->execute([$_SESSION['user_id']]);
    $userRow = $adminCheck->fetch(PDO::FETCH_ASSOC);
    if ($userRow && (isConfiguredAdminEmail($userRow['email'] ?? '') || (int)($userRow['is_admin'] ?? 0) === 1)) {
        $_SESSION['is_admin'] = true;
        if ((int)($userRow['is_admin'] ?? 0) !== 1) {
            try {
                $pdo->prepare("UPDATE users SET is_admin = 1 WHERE id = ?")->execute([$_SESSION['user_id']]);
            } catch (Exception $e) {}
        }
    } else {
        header("Location: homepage.php");
        exit();
    }
}

// Database connection

// Pagination settings
$posts_per_page = 9;
$current_page = isset($_GET['page']) ? intval($_GET['page']) : 1;
if ($current_page < 1) $current_page = 1;
$offset = ($current_page - 1) * $posts_per_page;

// Process admin actions if any
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && isset($_POST['post_id'])) {
        $admin_id = $_SESSION['user_id'];
        $post_id = $_POST['post_id'];
        $action = $_POST['action'];
        $comment = $_POST['comment'] ?? '';
        
        try {
        // Insert admin action
        $stmt = $pdo->prepare("
            INSERT INTO admin_actions (admin_id, post_id, action_type, comment) 
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$admin_id, $post_id, $action, $comment]);
        
            // Update post status based on action
        if ($action === 'delete') {
                // Get post owner to send notification before deleting
                $get_owner_stmt = $pdo->prepare("SELECT p.*, u.first_name, u.last_name, u.profile_picture, 
                    COUNT(DISTINCT c.id) AS comment_count 
                    FROM posts p 
                    JOIN users u ON p.user_id = u.id 
                    LEFT JOIN comments c ON p.id = c.post_id 
                    WHERE p.id = ? 
                    GROUP BY p.id, u.first_name, u.last_name, u.profile_picture");
                $get_owner_stmt->execute([$post_id]);
                
                if ($owner_row = $get_owner_stmt->fetch()) {
                    $post_owner_id = $owner_row['user_id'];
                    $post_content = $owner_row['content']; // Store full content
                    
                    // Save to deleted_posts table
                    $store_deleted_post = $pdo->prepare("INSERT INTO deleted_posts 
                        (original_post_id, user_id, admin_id, content, image_path, likes, comment_count, 
                        profile_picture, first_name, last_name, deletion_reason, created_at, deleted_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                    
                    $store_deleted_post->execute([
                        $post_id, 
                        $post_owner_id, 
                        $admin_id, 
                        $post_content, 
                        $owner_row['image_path'], 
                        $owner_row['likes'], 
                        $owner_row['comment_count'], 
                        $owner_row['profile_picture'], 
                        $owner_row['first_name'], 
                        $owner_row['last_name'], 
                        $comment,
                        $owner_row['created_at']
                    ]);
                    $deleted_post_id = $pdo->lastInsertId();
                    
                    // Create notification about post deletion - now referencing the deleted_posts entry
                    $notification_message = "Your post has been deleted by an administrator. ";
                    if (!empty($comment)) {
                        $notification_message .= "Reason: " . $comment;
                    }
                    
                    $notification_stmt = $pdo->prepare("INSERT INTO notifications 
                        (user_id, type, message, reference_id, is_read) 
                        VALUES (?, 'post_deleted', ?, ?, 0)");
                    $notification_stmt->execute([$post_owner_id, $notification_message, $deleted_post_id]);
                }
                
                // Now delete the post
            $delete_stmt = $pdo->prepare("DELETE FROM posts WHERE id = ?");
            $delete_stmt->execute([$post_id]);
            }             elseif ($action === 'hold') {
                            // If action is "hold", update the post status to "on-hold" and update timestamp
            $update_stmt = $pdo->prepare("UPDATE posts SET status = 'on-hold', updated_at = NOW() WHERE id = ?");
                $update_stmt->execute([$post_id]);
                
                // Get post owner to send notification
                $get_owner_stmt = $pdo->prepare("SELECT user_id FROM posts WHERE id = ?");
                $get_owner_stmt->execute([$post_id]);
                
                if ($owner_row = $get_owner_stmt->fetch()) {
                    $post_owner_id = $owner_row['user_id'];
                    
                    // Create notification
                    $notification_message = "Your post has been placed on hold. Reason: " . substr($comment, 0, 100) . 
                                        (strlen($comment) > 100 ? "..." : "");
                
                    $notification_stmt = $pdo->prepare("INSERT INTO notifications 
                        (user_id, type, message, reference_id, is_read) 
                        VALUES (?, 'post_on_hold', ?, ?, 0)");
                    $notification_stmt->execute([$post_owner_id, $notification_message, $post_id]);
                }
            } elseif ($action === 'approve') {
                // If action is "approve", update the post status to "approved" and update timestamp
                $update_stmt = $pdo->prepare("UPDATE posts SET status = 'approved', updated_at = NOW() WHERE id = ?");
                $update_stmt->execute([$post_id]);
                
                // Get post owner to send notification
                $get_owner_stmt = $pdo->prepare("SELECT user_id FROM posts WHERE id = ?");
                $get_owner_stmt->execute([$post_id]);
                
                if ($owner_row = $get_owner_stmt->fetch()) {
                    $post_owner_id = $owner_row['user_id'];
                    
                    // Create notification
                    $notification_message = "Your post has been approved.";
                    
                    $notification_stmt = $pdo->prepare("INSERT INTO notifications 
                        (user_id, type, message, reference_id, is_read) 
                        VALUES (?, 'post_approved', ?, ?, 0)");
                    $notification_stmt->execute([$post_owner_id, $notification_message, $post_id]);
                }
        }
        
        // Return response for AJAX requests
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'action' => $action, 'post_id' => $post_id]);
                exit;
            } else {
                // Also respond to fetch API requests
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'action' => $action, 'post_id' => $post_id]);
                exit;
            }
        } catch (Exception $e) {
            // Return error response
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
        
        // Redirect to refresh the page for non-AJAX requests
        header("Location: admin.php");
        exit();
    }
}

// Get total number of posts for pagination
$total_posts_query = "SELECT COUNT(*) as total FROM posts";
$total_result = $pdo->query($total_posts_query);
$total_posts = $total_result->fetch()['total'];
$total_pages = ceil($total_posts / $posts_per_page);

// Get all posts with user info and status with pagination
$posts_query = "
    SELECT p.*, u.username, u.first_name, u.last_name, u.profile_picture,
           COALESCE(p.status, 'posted') as status,
           COUNT(DISTINCT c.id) AS comment_count,
           p.likes,
           EXISTS(SELECT 1 FROM likes l WHERE l.user_id = ? AND l.post_id = p.id) AS user_has_liked
    FROM posts p
    JOIN users u ON p.user_id = u.id
    LEFT JOIN comments c ON p.id = c.post_id
    GROUP BY p.id, u.username, u.first_name, u.last_name, u.profile_picture
    ORDER BY p.created_at DESC
    LIMIT ? OFFSET ?
";
$stmt = $pdo->prepare($posts_query);
$stmt->execute([$_SESSION['user_id'], $posts_per_page, $offset]);
$posts = [];
try {
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Log the error for debugging
    error_log("SQL Error in admin.php: " . $e->getMessage());
}

// Get current stats
$stats = [];
$current_stats_query = $pdo->query("
    SELECT 
        (SELECT COUNT(*) FROM users) as total_users,
        (SELECT COUNT(*) FROM posts) as total_posts,
        (SELECT COUNT(*) FROM posts WHERE DATE(created_at) = CURRENT_DATE) as today_posts,
        (SELECT COUNT(*) FROM admin_actions WHERE DATE(created_at) = CURRENT_DATE) as today_actions
");
if ($current_stats_query) {
    $stats = $current_stats_query->fetch();
}

// Get stats from last month for comparison
$last_month_stats = [];
global $db_driver;
if ($db_driver === 'pgsql') {
    $last_month_stats_query = $pdo->query("
        SELECT 
            (SELECT COUNT(*) FROM users WHERE DATE(created_at) < CURRENT_DATE - INTERVAL '30 days') as last_month_users,
            (SELECT COUNT(*) FROM posts WHERE DATE(created_at) < CURRENT_DATE - INTERVAL '30 days') as last_month_posts,
            (SELECT COUNT(*) FROM posts WHERE DATE(created_at) = CURRENT_DATE - INTERVAL '1 day') as yesterday_posts,
            (SELECT COUNT(*) FROM admin_actions WHERE DATE(created_at) = CURRENT_DATE - INTERVAL '1 day') as yesterday_actions
    ");
} else {
    $last_month_stats_query = $pdo->query("
        SELECT 
            (SELECT COUNT(*) FROM users WHERE DATE(created_at) < DATE_SUB(CURRENT_DATE, INTERVAL 30 DAY)) as last_month_users,
            (SELECT COUNT(*) FROM posts WHERE DATE(created_at) < DATE_SUB(CURRENT_DATE, INTERVAL 30 DAY)) as last_month_posts,
            (SELECT COUNT(*) FROM posts WHERE DATE(created_at) = DATE_SUB(CURRENT_DATE, INTERVAL 1 DAY)) as yesterday_posts,
            (SELECT COUNT(*) FROM admin_actions WHERE DATE(created_at) = DATE_SUB(CURRENT_DATE, INTERVAL 1 DAY)) as yesterday_actions
    ");
}
if ($last_month_stats_query) {
    $last_month_stats = $last_month_stats_query->fetch();
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

// Format date function
function formatDate($date) {
    if (empty($date)) return '';
    $dt = new DateTime($date, new DateTimeZone('UTC'));
    $dt->setTimezone(new DateTimeZone('Asia/Manila'));
    return $dt->format('M j, Y, g:i a');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BondNest Admin Dashboard</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Store posts data for JavaScript to use -->
    <script id="posts-data" type="application/json">
        <?php echo json_encode($posts); ?>
    </script>
    <style>
        :root {
            --primary: #4361ee;
            --primary-light: #eef2ff;
            --secondary: #3f37c9;
            --accent: #f72585;
            --dark: #1a1a2e;
            --light: #f8f9fa;
            --gray: #6c757d;
            --light-gray: #e9ecef;
            --success: #008080;
            --warning: #f8961e;
            --danger: #ef233c;
            --info: #4895ef;
            --white: #ffffff;
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background-color: #f5f7fb;
            color: var(--dark);
            overflow-x: hidden;
        }

        .container {
            min-height: 100vh;
        }
        
        /* Admin Layout */
        .admin-layout {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 25px;
            min-height: 100vh;
        }
        
        /* Admin Sidebar */
        .admin-sidebar {
            background-color: var(--white);
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
            position: sticky;
            top: 25px;
            height: calc(100vh - 50px);
            overflow-y: auto;
        }
        
        .sidebar-header {
            padding-bottom: 15px;
            margin-bottom: 15px;
            border-bottom: 1px solid var(--light-gray);
        }
        
        .sidebar-header h3 {
            font-size: 1.1rem;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        /* Stats Column */
        .stats-column {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        /* Main content */
        .main-content {
            flex: 1;
            min-height: 100vh;
        }

        /* Header */
        .header {
            display: none;
        }

        /* Content */
        .content {
            padding: 25px;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background-color: var(--white);
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.03);
            transition: var(--transition);
            border-left: 4px solid var(--primary);
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.08);
        }

        .stat-card.users {
            border-left-color: var(--success);
        }

        .stat-card.posts {
            border-left-color: var(--info);
        }

        .stat-card.today {
            border-left-color: var(--warning);
        }

        .stat-card.actions {
            border-left-color: var(--accent);
        }

        .stat-card .title {
            font-size: 0.9rem;
            color: var(--gray);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .stat-card .title i {
            font-size: 1rem;
        }

        .stat-card .value {
            font-size: 1.8rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 5px;
        }

        .stat-card .change {
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .stat-card .change.positive {
            color: var(--success);
        }

        .stat-card .change.negative {
            color: var(--danger);
        }

        /* Section Header - keeping this for other sections */
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .section-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--dark);
        }

        /* Post Table */
        .post-table-container {
            background-color: var(--white);
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.03);
            overflow: hidden;
            margin-top: -10px;
        }

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px;
            border-bottom: 1px solid var(--light-gray);
        }

        .table-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .search-box {
            position: relative;
        }

        .search-box input {
            padding: 8px 15px 8px 35px;
            border-radius: 6px;
            border: 1px solid var(--light-gray);
            font-size: 0.9rem;
            width: 200px;
            transition: var(--transition);
        }

        .search-box input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
            width: 250px;
        }

        .search-box i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray);
            font-size: 0.9rem;
        }

        .refresh-btn {
            padding: 8px 15px;
            border-radius: 6px;
            border: 1px solid var(--light-gray);
            background-color: var(--white);
            color: var(--gray);
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 5px;
            cursor: pointer;
            transition: var(--transition);
        }

        .refresh-btn:hover {
            background-color: var(--light);
            color: var(--dark);
        }
        
        .refresh-btn {
            position: relative;
        }
        
        .refresh-btn.refreshing i {
            animation: rotate 1s linear infinite;
        }
        
        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .post-table {
            width: 100%;
            border-collapse: collapse;
        }

        .post-table th {
            padding: 15px;
            text-align: left;
            background-color: var(--light);
            font-weight: 500;
            color: var(--gray);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .post-table td {
            padding: 15px;
            border-bottom: 1px solid var(--light-gray);
            font-size: 0.9rem;
            vertical-align: middle;
        }

        .post-table tr:last-child td {
            border-bottom: none;
        }

        .post-table tr:hover td {
            background-color: var(--primary-light);
        }

        .user-cell {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .user-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            object-fit: cover;
        }

        .user-info {
            line-height: 1.3;
        }

        .user-name {
            font-weight: 500;
            font-size: 0.95rem;
        }

        .username {
            font-size: 0.8rem;
            color: var(--gray);
        }

        .content-cell {
            max-width: 300px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .image-cell {
            text-align: center;
        }

        .has-image {
            color: var(--success);
        }

        .no-image {
            color: var(--gray);
        }

        .status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .status-on-hold {
            background-color: rgba(255, 193, 7, 0.1);
            color: #ffc107;
        }
        
        .status-posted {
            background-color: rgba(76, 201, 240, 0.1);
            color: #4cc9f0;
        }

        .status-approved {
            background-color: rgba(76, 201, 240, 0.1);
            color: var(--success);
        }

        .status-warned {
            background-color: rgba(239, 35, 60, 0.1);
            color: var(--danger);
        }

        .action-btns {
            display: flex;
            gap: 8px;
        }

        .btn {
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 5px;
            cursor: pointer;
            transition: var(--transition);
            border: none;
        }

        .btn-sm {
            padding: 5px 10px;
            font-size: 0.75rem;
        }

        .btn-view {
            background-color: rgba(67, 97, 238, 0.1);
            color: var(--primary);
        }

        .btn-view:hover {
            background-color: rgba(67, 97, 238, 0.2);
        }

        .btn-warn {
            background-color: rgba(248, 150, 30, 0.1);
            color: var(--warning);
        }

        .btn-warn:hover {
            background-color: rgba(248, 150, 30, 0.2);
        }

        .btn-delete {
            background-color: rgba(239, 35, 60, 0.1);
            color: var(--danger);
        }

        .btn-delete:hover {
            background-color: rgba(239, 35, 60, 0.2);
        }

        .btn-approve {
            background-color: rgba(76, 201, 240, 0.1);
            color: var(--success);
        }

        .btn-approve:hover {
            background-color: rgba(76, 201, 240, 0.2);
        }

        .btn-hold {
            background-color: rgba(255, 193, 7, 0.1);
            color: #ffc107;
        }

        .btn-hold:hover {
            background-color: rgba(255, 193, 7, 0.2);
        }

        .pagination {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 20px;
            border-top: 1px solid var(--light-gray);
        }

        .pagination-info {
            font-size: 0.85rem;
            color: var(--gray);
        }

        .pagination-controls {
            display: flex;
            gap: 5px;
        }

        .page-btn {
            width: 32px;
            height: 32px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: var(--white);
            border: 1px solid var(--light-gray);
            color: var(--gray);
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            margin: 0 2px;
        }

        .page-btn:hover {
            background-color: var(--primary);
            color: var(--white);
            border-color: var(--primary);
        }

        .page-btn.active {
            background-color: var(--primary);
            color: var(--white);
            border-color: var(--primary);
        }

        .page-btn.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .page-ellipsis {
            margin: 0 5px;
            color: var(--gray);
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
        }

        /* Post Modal */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            padding: 20px;
        }

        .modal-container {
            background-color: var(--white);
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
            animation: modalFadeIn 0.3s ease;
        }

        @keyframes modalFadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px;
            border-bottom: 1px solid var(--light-gray);
            position: sticky;
            top: 0;
            background-color: var(--white);
            z-index: 1;
        }

        .modal-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--dark);
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--gray);
            transition: var(--transition);
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }

        .close-modal:hover {
            background-color: var(--light);
            color: var(--danger);
        }

        .modal-body {
            padding: 20px;
        }

        .post-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
        }

        .post-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--light-gray);
        }

        .post-user-info h3 {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 3px;
        }

        .post-user-info p {
            font-size: 0.85rem;
            color: var(--gray);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .post-time {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .post-content {
            margin-bottom: 20px;
            line-height: 1.6;
            font-size: 0.95rem;
        }

        .post-image-container {
            margin: 20px 0;
            border-radius: 8px;
            overflow: hidden;
        }

        .post-image {
            width: 100%;
            max-height: 400px;
            object-fit: contain;
            border-radius: 8px;
            display: block;
        }

        .post-stats {
            display: flex;
            gap: 20px;
            margin: 20px 0;
            padding: 15px 0;
            border-top: 1px solid var(--light-gray);
            border-bottom: 1px solid var(--light-gray);
        }

        .post-stat {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
            color: var(--gray);
        }

        .post-stat i {
            font-size: 1rem;
        }

        .post-actions-section {
            margin-top: 20px;
        }

        .section-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 15px;
            color: var(--dark);
        }

        .action-form {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .form-group label {
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--dark);
        }

        .form-group textarea {
            padding: 12px;
            border-radius: 8px;
            border: 1px solid var(--light-gray);
            resize: vertical;
            min-height: 100px;
            font-size: 0.9rem;
            transition: var(--transition);
        }

        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }

        .action-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .action-btn {
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            border: none;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
        }

        .action-btn i {
            font-size: 1rem;
        }

        .btn-approve {
            background-color: var(--success);
            color: white;
        }

        .btn-approve:hover {
            background-color: #3aa8d8;
        }

        .btn-warn {
            background-color: var(--warning);
            color: white;
        }

        .btn-warn:hover {
            background-color: #e08600;
        }

        .btn-delete {
            background-color: var(--danger);
            color: white;
        }

        .btn-delete:hover {
            background-color: #d11a2d;
        }

        /* Empty State */
        .empty-state {
            padding: 40px 20px;
            text-align: center;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 15px;
            opacity: 0.5;
        }

        .empty-state h3 {
            font-size: 1.2rem;
            margin-bottom: 10px;
            color: var(--dark);
        }

        .empty-state p {
            font-size: 0.95rem;
            max-width: 500px;
            margin: 0 auto 20px;
        }

        /* Responsive */
        @media (max-width: 1200px) {
            .content-cell {
                max-width: 200px;
            }
        }

        @media (max-width: 992px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .post-table th, .post-table td {
                padding: 10px;
            }

            .action-btns {
                flex-direction: column;
                gap: 5px;
            }

            .btn {
                justify-content: center;
            }
        }

        @media (max-width: 576px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .table-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }

            .table-actions {
                width: 100%;
                flex-wrap: wrap;
            }

            .search-box {
                width: 100%;
            }

            .search-box input {
                width: 100%;
            }

            .search-box input:focus {
                width: 100%;
            }

            .post-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .action-buttons {
                flex-direction: column;
            }

            .action-btn {
                justify-content: center;
            }
        }

        /* Reset some default styles */
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
        font-family: 'Poppins', sans-serif;
    }

    /* Navbar styles */
    .navbar {
        background-color: white;
        color: white;
        padding: 8px 20px;
        position: sticky;
        top: 0;
        z-index: 1000;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 0;
    }

    .navbar-container {
        display: flex;
        justify-content: space-between;
        align-items: center;
        width: 100%;
    }

    .navbar-main {
        display: flex;
        align-items: center;
        gap: 30px;
        flex-grow: 1;
    }

    .navbar-brand {
        display: flex;
        align-items: center;
        z-index: 2;
    }

    .navbar-logo {
        color: #008080;
        text-decoration: none;
        font-size: 1.8rem;
        font-weight: 600;
        transition: color 0.3s ease;
        display: flex;
        align-items: center;
        margin-left: 20px;
    }

    .navbar-logo:hover {
        color: #00cccc;
    }

    .logo-img {
        height: 2em;
        width: auto;
        margin-right: 10px;
    }

    /* Right side navigation items */
    .navbar-right {
        display: flex;
        align-items: center;
        gap: 20px;
        margin-left: auto;
        margin-right: 20px;
    }

    .logout-btn {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        background-color: #008080;
        color: white;
        text-decoration: none;
        font-weight: 500;
        padding: 8px 16px;
        border-radius: 20px;
        transition: all 0.3s ease;
    }

    .logout-btn i {
        color: white;
    }

    .logout-btn:hover {
        background-color: #006666;
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
    }

    .logout-btn:active {
        transform: translateY(0);
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }

    /* Mobile menu toggle */
    .navbar-toggle {
        display: none;
        cursor: pointer;
    }

    .navbar-toggle-icon {
        display: block;
        width: 25px;
        height: 3px;
        background-color: #008080;
        position: relative;
        transition: all 0.3s ease;
    }

    .navbar-toggle-icon::before,
    .navbar-toggle-icon::after {
        content: '';
        position: absolute;
        width: 100%;
        height: 100%;
        background-color: #008080;
        transition: all 0.3s ease;
    }

    .navbar-toggle-icon::before {
        top: -8px;
    }

    .navbar-toggle-icon::after {
        top: 8px;
    }
    
    /* Logout confirmation overlay */
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
        background: white;
        padding: 1.5rem;
        border-radius: 16px;
        box-shadow: 0 15px 35px rgba(0, 0, 0, 0.25);
        width: 100%;
        max-width: 380px;
        transform: scale(0.95);
        animation: modal-in 0.3s forwards;
        border-top: 4px solid #008080;
    }

    @keyframes modal-in {
        to { transform: scale(1); }
    }

    .logout-header {
        display: flex;
        align-items: center;
        margin-bottom: 1.2rem;
        color: #008080;
        padding-bottom: 0.75rem;
        border-bottom: 1px solid rgba(0, 0, 0, 0.05);
    }

    .logout-header i {
        font-size: 1.3rem;
        margin-right: 0.75rem;
        width: 32px;
        height: 32px;
        background: rgba(0, 128, 128, 0.1);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .logout-header h2 {
        font-size: 1.3rem;
        font-weight: 600;
        margin: 0;
    }

    .logout-message {
        font-size: 1.1rem;
        margin-bottom: 1.5rem;
        color: #333;
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
        background: #008080;
        color: white;
    }

    .btn-save:hover {
        opacity: 0.95;
        box-shadow: 0 3px 8px rgba(0, 128, 128, 0.2);
    }

    @media (max-width: 768px) {
        .navbar-toggle {
            display: block;
        }
        .navbar-main {
            gap: 15px;
        }
        .navbar-right {
            display: none;
            position: absolute;
            top: 60px;
            right: 20px;
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            flex-direction: column;
        }
        .navbar-right.active {
            display: flex;
        }
    }

    /* Toast Notifications */
    .toast {
        position: fixed;
        top: 80px;
        right: 20px;
        background: white;
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        border-radius: 8px;
        padding: 12px 15px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        min-width: 300px;
        max-width: 450px;
        z-index: 10000;
        transform: translateX(calc(100% + 24px));
        opacity: 0;
        transition: all 0.3s ease;
        border-left: 4px solid var(--primary);
    }

    .toast.show {
        transform: translateX(0);
        opacity: 1;
    }

    .toast-success {
        border-left-color: var(--success);
    }

    .toast-error {
        border-left-color: var(--danger);
    }

    .toast-warning {
        border-left-color: var(--warning);
    }

    .toast-content {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .toast-content i {
        font-size: 1.2rem;
    }

    .toast-success i {
        color: var(--success);
    }

    .toast-error i {
        color: var(--danger);
    }

    .toast-warning i {
        color: var(--warning);
    }

    .toast-close {
        background: none;
        border: none;
        font-size: 1.2rem;
        cursor: pointer;
        color: var(--gray);
        transition: color 0.2s ease;
    }

    .toast-close:hover {
        color: var(--dark);
    }
    
    /* Important toast styles for new post notifications */
    .toast-important {
        transform: scale(1.05);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3) !important;
        border-width: 4px !important;
        animation: toast-pulse 1s infinite alternate;
    }
    
    @keyframes toast-pulse {
        0% { box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3); }
        100% { box-shadow: 0 8px 30px rgba(76, 201, 240, 0.4); }
    }
    
    /* Highlight for new rows */
    @keyframes highlightRow {
        0% { background-color: rgba(67, 97, 238, 0.2); }
        100% { background-color: transparent; }
    }
    
    .highlight-new-row {
        animation: highlightRow 2s ease-out;
    }
        
        /* Animation for updated cells (likes/comments) */
        @keyframes flashUpdate {
            0%, 50%, 100% { background-color: transparent; }
            25%, 75% { background-color: rgba(76, 201, 240, 0.3); }
        }
        
        .flash-update {
            animation: flashUpdate 1.5s ease;
            border-radius: 6px;
    }
    
    /* Spinning icon animation */
    .spinning {
        animation: rotate 1s linear infinite;
    }
    
    @keyframes rotate {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
        }

            /* Post actions styling */
        .post-actions {
            display: flex;
            justify-content: space-around;
            padding: 15px 0;
            border-top: 1px solid var(--light-gray);
            border-bottom: 1px solid var(--light-gray);
            margin: 20px 0;
        }
        
        .post-action {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 8px;
            cursor: pointer;
            transition: var(--transition);
            color: var(--gray);
        }
        
        .post-action:hover {
            background-color: var(--light);
        }
        
        .post-action i {
            font-size: 1.1rem;
        }
        
        .post-action .bi-heart-fill,
        #modalLikeIcon.bi-heart-fill {
            color: #e63946 !important;
        }
        
        .like-button[data-liked="true"],
        #modalLikeButton[data-liked="true"] {
            color: #e63946;
        }
        
        .like-count, .comment-count {
            color: var(--gray);
            font-size: 0.85rem;
            font-weight: 500;
        }

        /* Make the post actions look more like homepage.php */
        #modalPostActions {
            display: flex;
            justify-content: flex-start;
            gap: 20px;
            padding-left: 10px;
            margin: 15px 0;
            border-top: 1px solid var(--light-gray);
            border-bottom: 1px solid var(--light-gray);
            padding: 15px 0;
        }
        
        #modalPostActions .post-action {
            padding: 8px 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        #modalPostActions .post-action:hover {
            background-color: rgba(0, 0, 0, 0.05);
            border-radius: 6px;
        }
        
        #modalLikeButton[data-liked="true"] span {
            color: #e63946;
        }
        
        #modalLikeButton[data-liked="true"] i,
        i.bi-heart-fill {
            color: #e63946 !important;
        }
        
        /* Make likes and comments match the table view */
        #modalLikeCount, #modalCommentCount {
            color: var(--gray);
            font-size: 0.85rem;
            font-weight: 500;
            margin-left: 5px;
        }

        .post-actions-section {
            margin-top: 20px;
        }

    /* Engagement count styling */
    .engagement-count {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 5px;
        font-size: 0.9rem;
        color: var(--gray);
        font-weight: 500;
    }

    .engagement-count i {
        font-size: 1rem;
    }
    </style>
</head>
<body>
<nav class="navbar">
    <div class="navbar-container">
        <div class="navbar-main">
            <div class="navbar-brand">
                <a href="homepage.php" class="navbar-logo">
                    <img src="./web-images/bn-logo.png" class="logo-img" alt="BondNest Logo">
                    BondNest Admin
                </a>
                        </div>
                    </div>
                    
        <div class="navbar-right">
            <a href="index.php" class="logout-btn" id="logoutButton">
                <i class="fas fa-sign-out-alt"></i>
                <span>Log Out</span>
                    </a>
                </div>
        
        <div class="navbar-toggle">
            <span class="navbar-toggle-icon"></span>
            </div>
    </div>
</nav>

<!-- Logout confirmation overlay -->
<div class="logout-confirmation-overlay" id="logoutConfirmationOverlay">
    <div class="logout-confirmation-container">
        <div class="logout-header">
            <i class="fas fa-sign-out-alt"></i>
            <h2>Confirm Logout</h2>
        </div>
        <p class="logout-message">Are you sure you want to log out?</p>
        <div class="logout-actions">
            <button class="btn-cancel logout-cancel-button" id="cancelLogout">Cancel</button>
            <a href="index.php" class="btn-save logout-confirm-button">Log Out</a>
        </div>
    </div>
</div>
    <div class="container">
        <div class="admin-layout">
            <!-- Sidebar with Stats Cards -->
            <div class="admin-sidebar">
                <div class="sidebar-header">
                    <h3><i class="bi bi-bar-chart-line"></i> Dashboard Stats</h3>
                </div>
                <!-- Stats Cards - Now in sidebar -->
                <div class="stats-column">
                    <div class="stat-card users">
                        <div class="title">
                            <i class="bi bi-people-fill"></i>
                            Total Users
                        </div>
                        <div class="value" id="total-users-value"><?php echo number_format($stats['total_users'] ?? 0); ?></div>
                        <div class="change <?php echo ($percent_changes['users'] >= 0) ? 'positive' : 'negative'; ?>" id="total-users-change">
                            <i class="bi bi-<?php echo ($percent_changes['users'] >= 0) ? 'arrow-up' : 'arrow-down'; ?>"></i>
                            <span><?php echo abs($percent_changes['users'] ?? 0); ?>% from last month</span>
                        </div>
                    </div>
                    
                    <div class="stat-card posts">
                        <div class="title">
                            <i class="bi bi-file-earmark-text"></i>
                            Total Posts
                        </div>
                        <div class="value" id="total-posts-value"><?php echo number_format($stats['total_posts'] ?? 0); ?></div>
                        <div class="change <?php echo ($percent_changes['posts'] >= 0) ? 'positive' : 'negative'; ?>" id="total-posts-change">
                            <i class="bi bi-<?php echo ($percent_changes['posts'] >= 0) ? 'arrow-up' : 'arrow-down'; ?>"></i>
                            <span><?php echo abs($percent_changes['posts'] ?? 0); ?>% from last month</span>
                        </div>
                    </div>
                    
                    <div class="stat-card today">
                        <div class="title">
                            <i class="bi bi-calendar-day"></i>
                            Today's Posts
                        </div>
                        <div class="value" id="today-posts-value"><?php echo number_format($stats['today_posts'] ?? 0); ?></div>
                        <div class="change <?php echo ($percent_changes['today_posts'] >= 0) ? 'positive' : 'negative'; ?>" id="today-posts-change">
                            <i class="bi bi-<?php echo ($percent_changes['today_posts'] >= 0) ? 'arrow-up' : 'arrow-down'; ?>"></i>
                            <span><?php echo abs($percent_changes['today_posts'] ?? 0); ?>% from yesterday</span>
                        </div>
                    </div>
                    
                    <div class="stat-card actions">
                        <div class="title">
                            <i class="bi bi-activity"></i>
                            Today's Actions
                        </div>
                        <div class="value" id="today-actions-value"><?php echo number_format($stats['today_actions'] ?? 0); ?></div>
                        <div class="change <?php echo ($percent_changes['today_actions'] >= 0) ? 'positive' : 'negative'; ?>" id="today-actions-change">
                            <i class="bi bi-<?php echo ($percent_changes['today_actions'] >= 0) ? 'arrow-up' : 'arrow-down'; ?>"></i>
                            <span><?php echo abs($percent_changes['today_actions'] ?? 0); ?>% from yesterday</span>
                        </div>
                        </div>

                    </div>
                </div>
                
            <!-- Main content -->
            <div class="main-content">
                <div class="content">

                
                <!-- Posts Table -->
                <div class="post-table-container">
                    <div class="table-header">
                        <h2 class="section-title">Recent Posts</h2>
                        
                        <div class="table-actions">
                            <div class="search-box">
                                <i class="bi bi-search"></i>
                                <input type="text" placeholder="Search posts...">
                            </div>
                                
                                <button class="refresh-btn" id="refreshPostsBtn">
                                    <i class="bi bi-arrow-clockwise"></i>
                                    Refresh
                            </button>
                        </div>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="post-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>User</th>
                                    <th>Content</th>
                                    <th>Image</th>
                                    <th>Created</th>
                                    <th>Likes</th>
                                    <th>Comments</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                                <tbody id="posts-table-body">
                                <?php if (!empty($posts)): ?>
                                    <?php foreach ($posts as $post): ?>
                                    <tr>
                                        <td><?php echo $post['id']; ?></td>
                                        <td>
                                            <div class="user-cell">
                                                <?php if (!empty($post['profile_picture'])): ?>
                                                    <img src="<?php echo htmlspecialchars($post['profile_picture']); ?>" alt="User" class="user-avatar">
                                                <?php else: ?>
                                                    <?php echo getInitialsHtml($post['first_name'] ?? '', $post['last_name'] ?? '', 40); ?>
                                                <?php endif; ?>
                                                <div class="user-info">
                                                    <div class="user-name"><?php echo htmlspecialchars($post['first_name'] . ' ' . $post['last_name']); ?></div>
                                                    <div class="username">@<?php echo htmlspecialchars($post['username']); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="content-cell" title="<?php echo htmlspecialchars($post['content']); ?>">
                                            <?php echo htmlspecialchars(substr($post['content'], 0, 50)) . (strlen($post['content']) > 50 ? '...' : ''); ?>
                                        </td>
                                        <td class="image-cell">
                                            <span class="<?php echo $post['image_path'] ? 'has-image' : 'no-image'; ?>">
                                                <i class="bi bi-<?php echo $post['image_path'] ? 'image-fill' : 'image'; ?>"></i>
                                            </span>
                                        </td>
                                        <td><?php echo formatDate($post['created_at']); ?></td>
                                        <td>
                                            <span class="engagement-count">
                                                <?php if (($post['likes'] ?? 0) > 0): ?>
                                                    <i class="bi bi-heart-fill" style="color: #e63946;"></i>
                                                <?php else: ?>
                                                    <i class="bi bi-heart"></i>
                                                <?php endif; ?>
                                                <?php echo $post['likes'] ?? 0; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="engagement-count">
                                                <i class="bi bi-chat-dots"></i>
                                                <?php echo $post['comment_count'] ?? 0; ?>
                                            </span>
                                        </td>
                                        <td>
                                                <span class="status-badge status-<?php echo strtolower($post['status']); ?>">
                                                    <?php if($post['status'] === 'on-hold'): ?>
                                                        <i class="bi bi-pause-circle-fill"></i>
                                                        On Hold
                                                    <?php elseif($post['status'] === 'approved'): ?>
                                                        <i class="bi bi-check-circle-fill"></i>
                                                        Approved
                                                    <?php elseif($post['status'] === 'posted'): ?>
                                                        <i class="bi bi-file-post"></i>
                                                        Posted
                                                    <?php else: ?>
                                                <i class="bi bi-clock"></i>
                                                        <?php echo ucfirst($post['status']); ?>
                                                    <?php endif; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-btns">
                                                <button class="btn btn-view btn-sm view-btn" data-post-id="<?php echo $post['id']; ?>">
                                                    <i class="bi bi-eye-fill"></i>
                                                    View
                                                </button>
                                                <button class="btn btn-warn btn-sm warn-btn" data-post-id="<?php echo $post['id']; ?>">
                                                    <i class="bi bi-exclamation-triangle-fill"></i>
                                                    Warn
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="9">
                                            <div class="empty-state">
                                                <i class="bi bi-file-earmark-excel"></i>
                                                <h3>No Posts Found</h3>
                                                <p>There are currently no posts to display. Check back later or encourage users to create content.</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="pagination" <?php echo ($total_posts == 0) ? 'style="display: none;"' : ''; ?>>
                        <div class="pagination-info">
                            <?php
                            $start = min($offset + 1, $total_posts);
                            $end = min($offset + $posts_per_page, $total_posts);
                            echo "Showing $start to $end of $total_posts entries";
                            ?>
                        </div>
                        
                        <div class="pagination-controls">
                            <?php if ($current_page > 1): ?>
                                <button data-page="<?php echo $current_page - 1; ?>" class="page-btn page-link">
                                    <i class="bi bi-chevron-left"></i>
                                </button>
                            <?php else: ?>
                            <button class="page-btn disabled">
                                <i class="bi bi-chevron-left"></i>
                            </button>
                            <?php endif; ?>
                            
                            <?php
                            // Determine which page numbers to show
                            $start_page = max(1, min($current_page - 2, $total_pages - 4));
                            $end_page = min($total_pages, max($current_page + 2, 5));
                            
                            // Show first page if we're not starting at 1
                            if ($start_page > 1) {
                                echo '<button data-page="1" class="page-btn page-link">1</button>';
                                if ($start_page > 2) {
                                    echo '<span class="page-ellipsis">...</span>';
                                }
                            }
                            
                            // Generate page numbers
                            for ($i = $start_page; $i <= $end_page; $i++) {
                                if ($i == $current_page) {
                                    echo '<button class="page-btn active">' . $i . '</button>';
                                } else {
                                    echo '<button data-page="' . $i . '" class="page-btn page-link">' . $i . '</button>';
                                }
                            }
                            
                            // Show last page if we're not ending at the last page
                            if ($end_page < $total_pages) {
                                if ($end_page < $total_pages - 1) {
                                    echo '<span class="page-ellipsis">...</span>';
                                }
                                echo '<button data-page="' . $total_pages . '" class="page-btn page-link">' . $total_pages . '</button>';
                            }
                            ?>
                            
                            <?php if ($current_page < $total_pages): ?>
                                <button data-page="<?php echo $current_page + 1; ?>" class="page-btn page-link">
                                <i class="bi bi-chevron-right"></i>
                            </button>
                            <?php else: ?>
                                <button class="page-btn disabled">
                                    <i class="bi bi-chevron-right"></i>
                                </button>
                            <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Post Modal -->
    <div class="modal-overlay" id="postModal">
        <div class="modal-container">
            <div class="modal-header">
                <h2 class="modal-title">Post Details</h2>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="post-header">
                    <div class="post-avatar" id="modalAvatarContainer" style="width:48px;height:48px;border-radius:50%;overflow:hidden;flex-shrink:0;"></div>
                    <div class="post-user-info">
                        <h3 id="modalAuthor"></h3>
                        <p>
                            @<span id="modalUsername"></span>
                            <span class="post-time">
                                <i class="bi bi-clock"></i>
                                <span id="modalCreatedAt"></span>
                            </span>
                        </p>
                    </div>
                </div>
                
                <div class="post-content" id="modalContent"></div>
                
                <div class="post-image-container" id="modalImageContainer" style="display: none;">
                    <img src="" alt="Post image" class="post-image" id="modalImage">
                </div>
                
                <!-- Post actions matching homepage.php format -->
                <div class="post-actions" id="modalPostActions">
                    <div class="post-action like-button" id="modalLikeButton">
                        <i class="bi bi-heart" id="modalLikeIcon"></i>
                        <span id="modalLikeText">Like</span>
                        <small class="text-muted like-count" style="margin-left: 5px;" id="modalLikeCount">
                            0
                        </small>
                    </div>
                    <div class="post-action comment-trigger" id="modalCommentButton">
                        <i class="bi bi-chat-dots"></i>
                        <span>Comment</span>
                        <small class="text-muted comment-count" style="margin-left: 5px;" id="modalCommentCount">
                            0
                        </small>
                    </div>
                </div>
                
                <div class="post-actions-section">
                    <h3 class="section-title">Admin Actions</h3>
                    <form class="action-form" id="postActionForm">
                        <input type="hidden" id="actionPostId" name="post_id">
                        
                        <div class="form-group">
                            <label for="actionComment">Comments / Reason for Action:</label>
                            <textarea id="actionComment" name="comment" placeholder="Enter your comments or reason for taking this action..."></textarea>
                        </div>
                        
                        <div class="action-buttons">
                            <button type="button" class="action-btn btn-approve" data-action="approve">
                                <i class="bi bi-check-circle-fill"></i>
                                Approve Post
                            </button>
                            <button type="button" class="action-btn btn-hold" data-action="hold">
                                <i class="bi bi-pause-circle-fill"></i>
                                Hold Post
                            </button>
                            <button type="button" class="action-btn btn-warn" data-action="warn">
                                <i class="bi bi-exclamation-triangle-fill"></i>
                                Send Warning
                            </button>
                            <button type="button" class="action-btn btn-delete" data-action="delete">
                                <i class="bi bi-trash-fill"></i>
                                Delete Post
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Include our admin JavaScript -->
    <script src="admin.js?v=<?php echo time(); ?>"></script>
    
    <!-- Include the stats auto-refresh script -->
    <script src="admin_refresh.js?v=<?php echo time(); ?>"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Stats refresh functionality
            function refreshStats() {
                fetch('get_stats.php?t=' + new Date().getTime()) // Add timestamp to prevent caching
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Network response was not ok');
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.error) {
                            console.error('Error fetching stats:', data.error);
                            return;
                        }
                        
                        // Get current values to check for changes
                        const oldUsers = parseInt(document.getElementById('total-users-value').textContent.replace(/,/g, ''));
                        const oldPosts = parseInt(document.getElementById('total-posts-value').textContent.replace(/,/g, ''));
                        const oldTodayPosts = parseInt(document.getElementById('today-posts-value').textContent.replace(/,/g, ''));
                        const oldTodayActions = parseInt(document.getElementById('today-actions-value').textContent.replace(/,/g, ''));
                        
                        // Update total users with animation if changed
                        const usersEl = document.getElementById('total-users-value');
                        const newUsers = parseInt(data.stats.total_users);
                        if (newUsers !== oldUsers) {
                            animateValueChange(usersEl, oldUsers, newUsers);
                        }
                        
                        // Update total posts with animation if changed
                        const postsEl = document.getElementById('total-posts-value');
                        const newPosts = parseInt(data.stats.total_posts);
                        if (newPosts !== oldPosts) {
                            animateValueChange(postsEl, oldPosts, newPosts);
                        }
                        
                        // Update today's posts with animation if changed
                        const todayPostsEl = document.getElementById('today-posts-value');
                        const newTodayPosts = parseInt(data.stats.today_posts);
                        if (newTodayPosts !== oldTodayPosts) {
                            animateValueChange(todayPostsEl, oldTodayPosts, newTodayPosts);
                        }
                        
                        // Update today's actions with animation if changed
                        const todayActionsEl = document.getElementById('today-actions-value');
                        const newTodayActions = parseInt(data.stats.today_actions);
                        if (newTodayActions !== oldTodayActions) {
                            animateValueChange(todayActionsEl, oldTodayActions, newTodayActions);
                        }
                        
                        // Update change indicators
                        updateChangeIndicator('total-users-change', data.percent_changes.users);
                        updateChangeIndicator('total-posts-change', data.percent_changes.posts);
                        updateChangeIndicator('today-posts-change', data.percent_changes.today_posts);
                        updateChangeIndicator('today-actions-change', data.percent_changes.today_actions);
                    })
                    .catch(error => {
                        console.error('Error refreshing stats:', error);
                    });
            }
            
            // Animate value changes
            function animateValueChange(element, oldValue, newValue) {
                // First highlight the element 
                element.classList.add('flash-update');
                
                // Find and highlight the parent stat card for better visibility
                const statCard = element.closest('.stat-card');
                if (statCard) {
                    statCard.classList.add('updating');
                    setTimeout(() => {
                        statCard.classList.remove('updating');
                    }, 1500);
                }
                
                // Animate from old to new value
                const duration = 1000; // 1 second animation
                const start = performance.now();
                
                function updateNumber(timestamp) {
                    const elapsed = timestamp - start;
                    const progress = Math.min(elapsed / duration, 1);
                    
                    // Ease in/out animation function
                    const easeProgress = progress < 0.5 
                        ? 2 * progress * progress 
                        : -1 + (4 - 2 * progress) * progress;
                    
                    // Calculate current value in the animation
                    const currentValue = Math.round(oldValue + (newValue - oldValue) * easeProgress);
                    
                    // Update the element with formatted number
                    element.textContent = numberWithCommas(currentValue);
                    
                    // Continue animation if not finished
                    if (progress < 1) {
                        requestAnimationFrame(updateNumber);
                    } else {
                        // Animation complete, remove highlight
                        setTimeout(() => {
                            element.classList.remove('flash-update');
                        }, 500);
                    }
                }
                
                requestAnimationFrame(updateNumber);
            }
            
            // Format numbers with commas
            function numberWithCommas(x) {
                return x.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
            }
            
            // Update change indicators
            function updateChangeIndicator(elementId, percentChange) {
                const element = document.getElementById(elementId);
                if (!element) return;
                
                const isPositive = percentChange >= 0;
                
                // Update class
                element.className = isPositive ? 'change positive' : 'change negative';
                
                // Update icon
                const icon = element.querySelector('i');
                if (icon) {
                icon.className = isPositive ? 'bi bi-arrow-up' : 'bi bi-arrow-down';
                }
                
                // Update text
                const span = element.querySelector('span');
                if (span) {
                span.textContent = Math.abs(percentChange) + '% from ' + 
                    (elementId.includes('today') ? 'yesterday' : 'last month');
                }
            }
            
            // Make refreshStats available globally for other functions to trigger updates
            window.refreshAdminStats = refreshStats;
            
            // Start periodic stats refresh (every 5 seconds)
            setInterval(refreshStats, 5000);
            
            // Call refreshStats immediately on page load
            refreshStats();
            
            // Add CSS for animating number changes
            const style = document.createElement('style');
            style.textContent = `
                @keyframes flashUpdate {
                    0%, 50%, 100% { color: inherit; }
                    25%, 75% { color: var(--success); }
                }
                
                .flash-update {
                    animation: flashUpdate 1.5s ease;
                }
                
                .stat-card.updating {
                    box-shadow: 0 0 15px rgba(76, 201, 240, 0.3);
                    transition: box-shadow 0.3s ease;
                }
            `;
            document.head.appendChild(style);
            
            // Replace the auto-refresh comment with clearer information
            console.log('Stats auto-refresh enabled - cards will update every 5 seconds');
            
            // Auto-refresh of stats disabled
            // You can manually refresh the page to update stats
            
            // Post modal functionality
            let currentOpenModalPostId = null;
            const viewButtons = document.querySelectorAll('.view-btn, .warn-btn');
            const modal = document.getElementById('postModal');
            const closeModal = document.querySelector('.close-modal');
            
            // Get modal elements
            const modalAuthor = document.getElementById('modalAuthor');
            const modalUsername = document.getElementById('modalUsername');
            const modalCreatedAt = document.getElementById('modalCreatedAt');
            const modalContent = document.getElementById('modalContent');
            const modalImageContainer = document.getElementById('modalImageContainer');
            const modalImage = document.getElementById('modalImage');
            const modalLikeButton = document.getElementById('modalLikeButton');
            const modalLikeIcon = document.getElementById('modalLikeIcon');
            const modalLikeText = document.getElementById('modalLikeText');
            const modalLikeCount = document.getElementById('modalLikeCount');
            const modalCommentCount = document.getElementById('modalCommentCount');
            const actionPostId = document.getElementById('actionPostId');
            
            // Get post data
            const posts = <?php echo json_encode($posts); ?>;
            
            // Function to open modal with post data
            function openPostModal(postId, action = null) {
                // Find post in the posts array
                let post = posts.find(p => p.id == postId);
                
                // If still not found, fetch the post data directly
                if (!post) {
                    // Show loading state
                    showToast('Loading post...', 'info');
                    
                    // Fetch the post data
                    fetch(`get_post.php?id=${postId}`)
                        .then(response => response.json())
                        .then(data => {
                            if (data.success && data.post) {
                                displayPostInModal(data.post, action);
                            } else {
                                showToast('Error loading post', 'error');
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            showToast('Error loading post', 'error');
                        });
                    return;
                }
                
                displayPostInModal(post, action);
            }
            
            // Function to display post data in the modal
            function displayPostInModal(post, action = null) {
                    // Track which post is currently displayed in the modal
                    currentOpenModalPostId = post.id;
                
                    // Set modal content
                    const avatarContainer = document.getElementById('modalAvatarContainer');
                    if (post.profile_picture) {
                        avatarContainer.innerHTML = '<img src="' + post.profile_picture + '" alt="User" style="width:100%;height:100%;object-fit:cover;">';
                    } else {
                        const fn = (post.first_name || '')[0] || '';
                        const ln = (post.last_name || '')[0] || '';
                        const n = (post.first_name || '') + (post.last_name || '');
                        let h = 0;
                        for (let i = 0; i < n.length; i++) { h = (h * 31 + n.charCodeAt(i)) & 0x7FFFFFFF; }
                        const colors = ['#2B9E9E','#3CB5A6','#E67E22','#3498DB','#9B59B6','#E74C3C','#1ABC9C','#2C3E50'];
                        const bg = colors[h % colors.length];
                        avatarContainer.innerHTML = '<div style="width:100%;height:100%;background:' + bg + ';color:#fff;display:flex;align-items:center;justify-content:center;font-weight:600;font-size:18px;font-family:Poppins,sans-serif;">' + (fn + ln).toUpperCase() + '</div>';
                    }
                    modalAuthor.textContent = post.first_name + ' ' + post.last_name;
                    modalUsername.textContent = post.username;
                    modalCreatedAt.textContent = formatDate(post.created_at);
                    modalContent.textContent = post.content;
                    actionPostId.value = post.id;
                    
                    // Set like and comment counts that exactly match what's shown in table
                    if (modalLikeCount) modalLikeCount.textContent = post.likes || '0';
                    if (modalCommentCount) modalCommentCount.textContent = post.comment_count || '0';
                    
                    // Set like button state
                    if (modalLikeButton && modalLikeIcon && modalLikeText) {
                        // Update the like button state
                        const isLiked = post.user_has_liked == 1;
                        modalLikeButton.setAttribute('data-post-id', post.id);
                        modalLikeButton.setAttribute('data-liked', isLiked ? 'true' : 'false');
                        
                        // Update icon and text
                        if (isLiked) {
                            modalLikeIcon.className = 'bi bi-heart-fill';
                            modalLikeText.textContent = 'Liked';
                            if (modalLikeIcon) modalLikeIcon.style.color = '#e63946';
                        } else {
                            modalLikeIcon.className = 'bi bi-heart';
                            modalLikeText.textContent = 'Like';
                            if (modalLikeIcon) modalLikeIcon.style.color = '';
                        }
                    }
                    
                    // Handle image
                    if (post.image_path) {
                        modalImageContainer.style.display = 'block';
                        if (modalImage) modalImage.src = post.image_path;
                    } else {
                        modalImageContainer.style.display = 'none';
                    }
                    
                    // Show modal
                    modal.style.display = 'flex';
                    document.body.style.overflow = 'hidden';
                    
                    // If opened from warn button, focus on warning action
                    if (action === 'warn') {
                        setTimeout(() => {
                            document.querySelector('.btn-warn').focus();
                        }, 100);
                    }
                    
                    // Record view action
                    recordAction('view', post.id);
            }
            
            // Format date function for JavaScript
            function formatDate(dateString) {
                let dStr = dateString;
                if (dStr && !dStr.includes('Z') && !dStr.includes('+') && !dStr.match(/T.*[+-]/)) {
                    dStr = dStr.replace(' ', 'T') + 'Z';
                }
                const date = new Date(dStr);
                const options = { 
                    year: 'numeric', 
                    month: 'short', 
                    day: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit',
                    timeZone: 'Asia/Manila'
                };
                return date.toLocaleDateString('en-US', options);
            }
            
            // Format relative time for display (e.g., "2 hours ago")
            function timeAgo(dateString) {
                const date = new Date(dateString);
                const now = new Date();
                const secondsPast = (now.getTime() - date.getTime()) / 1000;

                if (secondsPast < 60) {
                    return `${Math.floor(secondsPast)} seconds ago`;
                }
                if (secondsPast < 3600) {
                    return `${Math.floor(secondsPast / 60)} minutes ago`;
                }
                if (secondsPast < 86400) {
                    return `${Math.floor(secondsPast / 3600)} hours ago`;
                }
                if (secondsPast < 604800) {
                    return `${Math.floor(secondsPast / 86400)} days ago`;
                }
                if (secondsPast < 2419200) {
                    return `${Math.floor(secondsPast / 604800)} weeks ago`;
                }
                if (secondsPast < 29030400) {
                    return `${Math.floor(secondsPast / 2419200)} months ago`;
                }
                return `${Math.floor(secondsPast / 29030400)} years ago`;
            }
            
            // Open modal when view button is clicked
            viewButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const postId = this.getAttribute('data-post-id');
                    const action = this.classList.contains('warn-btn') ? 'warn' : null;
                    openPostModal(postId, action);
                });
            });
            
            // Close modal when close button is clicked
            closeModal.addEventListener('click', function() {
                modal.style.display = 'none';
                document.body.style.overflow = 'auto';
                // Clear the current open modal post ID
                currentOpenModalPostId = null;
            });
            
            // Close modal when clicking outside of modal content
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    modal.style.display = 'none';
                    document.body.style.overflow = 'auto';
                    // Clear the current open modal post ID
                    currentOpenModalPostId = null;
                }
            });
            
            // Action buttons in modal
            const actionButtons = document.querySelectorAll('.action-button, .action-btn');
            actionButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const action = this.getAttribute('data-action');
                    const postId = actionPostId.value;
                    const comment = document.getElementById('actionComment').value;
                    
                    // Confirm delete action
                    if (action === 'delete' && !confirm('Are you sure you want to delete this post? This action cannot be undone.')) {
                        return;
                    }
                    
                    // Record action
                    recordAction(action, postId, comment);
                });
            });
            
            // Record admin action
            function recordAction(action, postId, comment = '') {
                // Skip view actions for AJAX
                if (action === 'view') return;
                
                // Create form data
                const formData = new FormData();
                formData.append('action', action);
                formData.append('post_id', postId);
                formData.append('comment', comment);
                
                // Show loading state
                const buttons = document.querySelectorAll('.action-btn');
                buttons.forEach(btn => {
                    btn.disabled = true;
                    btn.innerHTML = `<i class="bi bi-arrow-repeat"></i> Processing...`;
                });
                
                // Send AJAX request
                fetch('admin.php', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    console.log('Success:', data);
                    if (data.success) {
                        // Handle success
                        if (action === 'delete') {
                            // Show success message
                            showToast('Post deleted successfully', 'success');
                            modal.style.display = 'none';
                            document.body.style.overflow = 'auto';
                    
                    // Store post action in session storage for homepage and profile page
                    storePostStatusChange(postId, 'deleted');
                    
                            // Manual refresh needed - auto refresh disabled
                            // Reload the page instead of using AJAX refresh
                            setTimeout(() => {
                                // Refresh stats immediately after post action
                                refreshStats();
                                window.location.reload();
                            }, 500);
                        } else if (action === 'warn') {
                            showToast('Warning sent successfully', 'success');
                            modal.style.display = 'none';
                            document.body.style.overflow = 'auto';
                            // Refresh stats since an admin action was taken
                            // Use the enhanced refreshAdminStats function for animations
                            if (typeof window.refreshAdminStats === 'function') {
                                window.refreshAdminStats();
                            } else {
                                document.dispatchEvent(new CustomEvent('postUpdated'));
                            }
                        } else if (action === 'approve') {
                            showToast('Post approved successfully', 'success');
                            modal.style.display = 'none';
                            document.body.style.overflow = 'auto';
                            
                            // Store post action in session storage for homepage and profile page
                            storePostStatusChange(postId, 'approved');
                            
                            // Manual refresh needed - auto refresh disabled
                            // Refresh stats immediately after post action
                            refreshStats();
                            setTimeout(() => {
                                window.location.reload();
                            }, 500);
                        } else if (action === 'hold') {
                            showToast('Post has been placed on-hold', 'success');
                            modal.style.display = 'none';
                            document.body.style.overflow = 'auto';
                            
                            // Store post action in session storage for homepage and profile page
                            storePostStatusChange(postId, 'on-hold');
                            
                            // Manual refresh needed - auto refresh disabled
                            // Refresh stats immediately after post action
                            refreshStats();
                            setTimeout(() => {
                                window.location.reload();
                            }, 500);
                        }
                    } else {
                        // Handle error
                        showToast('Error: ' + (data.error || 'Unknown error'), 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showToast('An error occurred. Please try again.', 'error');
                })
                .finally(() => {
                    // Reset buttons
                    buttons.forEach(btn => {
                        btn.disabled = false;
                        if (btn.dataset.action === 'approve') {
                            btn.innerHTML = `<i class="bi bi-check-circle-fill"></i> Approve Post`;
                        } else if (btn.dataset.action === 'warn') {
                            btn.innerHTML = `<i class="bi bi-exclamation-triangle-fill"></i> Send Warning`;
                        } else if (btn.dataset.action === 'delete') {
                            btn.innerHTML = `<i class="bi bi-trash-fill"></i> Delete Post`;
                        } else if (btn.dataset.action === 'hold') {
                            btn.innerHTML = `<i class="bi bi-pause-circle-fill"></i> Hold Post`;
                        }
                    });
                });
            }
            
            // Show toast notification
            function showToast(message, type = 'info', duration = 5000) {
                // Create toast element
                const toast = document.createElement('div');
                toast.className = `toast toast-${type}`;
                
                // For new post notifications during auto-refresh, make it more visible
                if (type === 'success' && message.includes('new post')) {
                    toast.classList.add('toast-important');
                }
                
                // Create icon based on type
                let icon = 'info-circle-fill';
                if (type === 'success') icon = 'check-circle-fill';
                if (type === 'error') icon = 'exclamation-triangle-fill';
                if (type === 'warning') icon = 'exclamation-circle-fill';
                
                // Set toast content
                toast.innerHTML = `
                    <div class="toast-content">
                        <i class="bi bi-${icon}"></i>
                        <span>${message}</span>
                    </div>
                    <button class="toast-close">×</button>
                `;
                
                // Remove any existing toasts with the same message (to prevent duplicates)
                document.querySelectorAll('.toast').forEach(existingToast => {
                    if (existingToast.textContent.trim() === message.trim()) {
                        existingToast.remove();
                    }
                });
                
                // Add to document
                document.body.appendChild(toast);
                
                // Show toast with animation
                setTimeout(() => {
                    toast.classList.add('show');
                }, 10);
                
                // Add close button functionality
                const closeBtn = toast.querySelector('.toast-close');
                closeBtn.addEventListener('click', () => {
                    toast.classList.remove('show');
                    setTimeout(() => {
                        if (document.body.contains(toast)) {
                            document.body.removeChild(toast);
                        }
                    }, 300);
                });
                
                // Auto remove after specified duration
                setTimeout(() => {
                    if (document.body.contains(toast)) {
                        toast.classList.remove('show');
                        setTimeout(() => {
                            if (document.body.contains(toast)) {
                                document.body.removeChild(toast);
                            }
                        }, 300);
                    }
                }, duration);
            }
            
            // Search functionality
            const searchInput = document.querySelector('.search-box input');
            searchInput.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                const rows = document.querySelectorAll('.post-table tbody tr');
                
                rows.forEach(row => {
                    const content = row.querySelector('.content-cell').textContent.toLowerCase();
                    const username = row.querySelector('.username').textContent.toLowerCase();
                    const name = row.querySelector('.user-name').textContent.toLowerCase();
                    
                    if (content.includes(searchTerm) || username.includes(searchTerm) || name.includes(searchTerm)) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });
            });
            
            // Logout button functionality
            const logoutButton = document.getElementById('logoutButton');
            const logoutConfirmationOverlay = document.getElementById('logoutConfirmationOverlay');
            const cancelLogoutButton = document.getElementById('cancelLogout');
            
            if (logoutButton && logoutConfirmationOverlay && cancelLogoutButton) {
                logoutButton.addEventListener('click', function(e) {
                    e.preventDefault();
                    logoutConfirmationOverlay.style.display = 'flex';
                    document.body.style.overflow = 'hidden';
                });
                
                cancelLogoutButton.addEventListener('click', function() {
                    logoutConfirmationOverlay.style.display = 'none';
                    document.body.style.overflow = 'auto';
                });
                
                logoutConfirmationOverlay.addEventListener('click', function(event) {
                    if (event.target === logoutConfirmationOverlay) {
                        logoutConfirmationOverlay.style.display = 'none';
                        document.body.style.overflow = 'auto';
                    }
                });
            }

            // Add auto refresh functionality for posts
            const refreshPostsBtn = document.getElementById('refreshPostsBtn');
            const postsTableBody = document.getElementById('posts-table-body');
            
            // Initial posts data from PHP
            let currentPosts = <?php echo json_encode($posts); ?>;
            
            // Function to refresh posts data
            function refreshPosts(showLoading = true) {
                if (showLoading) {
                    refreshPostsBtn.classList.add('refreshing');
                }
                
                fetch('get_recent_posts.php?page=' + <?php echo $current_page; ?> + '&t=' + new Date().getTime()) // Prevent caching
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Network response was not ok');
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) {
                            // Update current posts data
                            const oldPostIds = currentPosts.map(post => post.id);
                            currentPosts = data.posts;
                            
                            // Check if there are new posts
                            const newPosts = data.posts.filter(post => !oldPostIds.includes(parseInt(post.id)));
                            const hasNewPosts = newPosts.length > 0;
                            
                            // Log for debugging
                            console.log('Refresh check - Auto:', !showLoading, 'New posts:', hasNewPosts, 'Count:', newPosts.length);
                            
                            // Update the table
                            updatePostsTable(data.posts, hasNewPosts);
                            
                            // Always show notification if there are new posts (regardless of manual or auto refresh)
                            if (hasNewPosts) {
                                // Use a more visible toast for new posts
                                showToast(`${newPosts.length} new post(s) have been added`, 'success', 8000);
                                
                                // Play a notification sound for better visibility
                                try {
                                    const audio = new Audio('notification.mp3');
                                    audio.volume = 0.5;
                                    audio.play().catch(e => console.log('Sound notification not played:', e));
                                } catch(e) {
                                    console.log('Sound notification error:', e);
                                }
                            }
                        } else {
                            console.error('Failed to fetch posts:', data.error);
                            if (showLoading) {
                                showToast('Failed to refresh posts', 'error');
                            }
                        }
                    })
                    .catch(error => {
                        console.error('Error fetching posts:', error);
                        if (showLoading) {
                            showToast('Error refreshing posts', 'error');
                        }
                    })
                    .finally(() => {
                        if (showLoading) {
                            refreshPostsBtn.classList.remove('refreshing');
                        }
                    });
            }
            
            // Function to update the posts table
            function updatePostsTable(posts, animate = false) {
                // Build HTML for posts
                const html = posts.map(post => {
                    // Create status badge
                    let statusBadge = '';
                    if (post.status === 'on-hold') {
                        statusBadge = `<span class="status-badge status-on-hold">
                                        <i class="bi bi-pause-circle-fill"></i>
                                        On Hold
                                      </span>`;
                    } else if (post.status === 'approved') {
                        statusBadge = `<span class="status-badge status-approved">
                                        <i class="bi bi-check-circle-fill"></i>
                                        Approved
                                      </span>`;
                    } else if (post.status === 'posted') {
                        statusBadge = `<span class="status-badge status-posted">
                                        <i class="bi bi-file-post"></i>
                                        Posted
                                      </span>`;
                    } else {
                        statusBadge = `<span class="status-badge">
                                        <i class="bi bi-clock"></i>
                                        ${post.status}
                                      </span>`;
                    }
                    
                    // Handle image display
                    const hasImage = post.image_path ? 'has-image' : 'no-image';
                    const imageIcon = post.image_path ? 'image-fill' : 'image';
                    
                    // Format content preview
                    const contentPreview = post.content ? (post.content.length > 50 ? 
                                          post.content.substring(0, 50) + '...' : 
                                          post.content) : '';
                    
                    // Format profile picture
                    const profilePicHtml = post.profile_picture
                        ? `<img src="${post.profile_picture}" alt="User" class="user-avatar">`
                        : (() => { const fn=(post.first_name||'')[0]||''; const ln=(post.last_name||'')[0]||''; const n=(post.first_name||'')+(post.last_name||''); let h=0; for(let i=0;i<n.length;i++){h=(h*31+n.charCodeAt(i))&0x7FFFFFFF;} const c=['#2B9E9E','#3CB5A6','#E67E22','#3498DB','#9B59B6','#E74C3C','#1ABC9C','#2C3E50']; return `<div class="user-avatar" style="background:${c[h%c.length]};color:#fff;display:flex;align-items:center;justify-content:center;font-weight:600;font-size:14px;font-family:Poppins,sans-serif;">${(fn+ln).toUpperCase()}</div>`; })()
                    
                    return `
                    <tr class="${animate ? 'animate-new-row' : ''}" data-post-id="${post.id}">
                        <td>${post.id}</td>
                        <td>
                            <div class="user-cell">
                                ${profilePicHtml}
                                <div class="user-info">
                                    <div class="user-name">${post.first_name} ${post.last_name}</div>
                                    <div class="username">@${post.username}</div>
                                </div>
                            </div>
                        </td>
                        <td class="content-cell" title="${post.content || ''}">
                            ${contentPreview}
                        </td>
                        <td class="image-cell">
                            <span class="${hasImage}">
                                <i class="bi bi-${imageIcon}"></i>
                            </span>
                        </td>
                        <td>${formatDate(post.created_at)}</td>
                        <td>
                            <span class="engagement-count">
                                ${parseInt(post.likes) > 0 
                                    ? `<i class="bi bi-heart-fill" style="color: #e63946;"></i>` 
                                    : `<i class="bi bi-heart"></i>`}
                                ${post.likes || 0}
                            </span>
                        </td>
                        <td>
                            <span class="engagement-count">
                                <i class="bi bi-chat-dots"></i>
                                ${post.comment_count || 0}
                            </span>
                        </td>
                        <td>
                            ${statusBadge}
                        </td>
                        <td>
                            <div class="action-btns">
                                <button class="btn btn-view btn-sm view-btn" data-post-id="${post.id}">
                                    <i class="bi bi-eye-fill"></i>
                                    View
                                </button>
                                <button class="btn btn-warn btn-sm warn-btn" data-post-id="${post.id}">
                                    <i class="bi bi-exclamation-triangle-fill"></i>
                                    Warn
                                </button>
                            </div>
                        </td>
                    </tr>
                    `;
                }).join('');
                
                // Update table content
                postsTableBody.innerHTML = html;
            }
            
            // Use event delegation for view and warn buttons
            // This attaches a single event listener to the table body
            // which handles clicks on any buttons inside it
            
            // Remove any existing event listener
            postsTableBody.removeEventListener('click', tableClickHandler);
            
            // Add new event listener using event delegation
            postsTableBody.addEventListener('click', tableClickHandler);
            
            function tableClickHandler(event) {
                // Check if a button was clicked
                const button = event.target.closest('.view-btn, .warn-btn');
                if (!button) return; // Not a button click
                
                // Get post ID and action
                const postId = button.getAttribute('data-post-id');
                const action = button.classList.contains('warn-btn') ? 'warn' : null;
                
                // Open modal
                openPostModal(postId, action);
            }
            
            // Add CSS for animation
            const style = document.createElement('style');
            style.textContent = `
                @keyframes highlightRow {
                    0% { background-color: rgba(67, 97, 238, 0.2); }
                    100% { background-color: transparent; }
                }
                
                .animate-new-row {
                    animation: highlightRow 2s ease-out;
                }
            `;
            document.head.appendChild(style);
            
            // Set up click handler for refresh button to do a full page reload
            if (refreshPostsBtn) {
                refreshPostsBtn.addEventListener('click', function() {
                    // Show spinner
                    refreshPostsBtn.classList.add('refreshing');
                    // Reload the page and maintain current page parameter
                    window.location.href = '?page=<?php echo $current_page; ?>';
                });
            }
            
            // Update the pagination links to preserve other query parameters
            document.querySelectorAll('.pagination-controls a').forEach(link => {
                const url = new URL(link.href);
                const currentUrl = new URL(window.location.href);
                
                // Preserve all other query parameters except 'page'
                for (const [key, value] of currentUrl.searchParams.entries()) {
                    if (key !== 'page') {
                        url.searchParams.set(key, value);
                    }
                }
                
                link.href = url.toString();
            });
            
            // Auto-refresh disabled to prevent likes/comments columns from disappearing
            // You can manually refresh using the refresh button when needed
        });
    </script>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // AJAX Real-time Updates Setup
            const postsTableBody = document.getElementById('posts-table-body');
            let currentPosts = <?php echo json_encode($posts); ?>;
            let lastPostId = currentPosts.length > 0 ? currentPosts[0].id : 0;
            
                                      // Keep track of currently open modal post ID
             let currentOpenModalPostId = null;
             
             // Track last request time to prevent too many concurrent requests
             let lastRequestTime = 0;
             let currentlyFetching = false;
             
             // Function to check for new posts
             function checkForNewPosts() {
                 // Don't send a new request if one is already in progress
                 if (currentlyFetching) return;
                 
                 // Don't hammer the server if we just made a request
                 const now = Date.now();
                 if (now - lastRequestTime < 100) return;
                 
                 // Get the current page from URL or default to 1
                 const urlParams = new URLSearchParams(window.location.search);
                 const currentPage = parseInt(urlParams.get('page') || '1');
                 
                 // Mark that we're fetching data
                 currentlyFetching = true;
                 lastRequestTime = now;
                 
                 // Use fetch API to check for new posts with cache busting
                 fetch(`get_recent_posts.php?page=${currentPage}&t=${now}`, {
                     method: 'GET',
                     headers: {
                         'X-Requested-With': 'XMLHttpRequest'
                     }
                 })
                 .then(response => response.json())
                 .then(data => {
                     // Check if we went from 0 posts to having posts
                     const hadEmptyTable = currentPosts.length === 0;
                     const hasPostsNow = data.success && data.posts.length > 0;
                     const paginationNeeded = data.pagination && data.pagination.total_pages > 0;
                     
                     if (data.success && data.posts.length > 0) {
                         // Check if we have new posts by comparing IDs
                         const newPostIds = data.posts.map(post => post.id);
                         const oldPostIds = currentPosts.map(post => parseInt(post.id));
                         
                         // Find posts that weren't in our previous data
                         const newPosts = data.posts.filter(post => !oldPostIds.includes(parseInt(post.id)));
                         
                         // Check for updates in existing posts (content, images, likes, comments)
                         const updatedPosts = [];
                         const updatedLikes = [];
                         const updatedComments = [];
                         
                         data.posts.forEach(newPost => {
                             // Find corresponding old post
                             const oldPost = currentPosts.find(p => parseInt(p.id) === parseInt(newPost.id));
                             if (oldPost) {
                                 // Check for content or image updates
                                 if (oldPost.content !== newPost.content || oldPost.image_path !== newPost.image_path) {
                                     updatedPosts.push(newPost.id);
                                 }
                                 
                                 // Check for like count changes
                                 if (parseInt(oldPost.likes || 0) !== parseInt(newPost.likes || 0)) {
                                     updatedLikes.push({
                                         id: newPost.id,
                                         oldCount: oldPost.likes || 0,
                                         newCount: newPost.likes || 0
                                     });
                                 }
                                 
                                 // Check for comment count changes
                                 if (parseInt(oldPost.comment_count || 0) !== parseInt(newPost.comment_count || 0)) {
                                     updatedComments.push({
                                         id: newPost.id,
                                         oldCount: oldPost.comment_count || 0,
                                         newCount: newPost.comment_count || 0
                                     });
                                 }
                             }
                         });
                         
                         // Update the current posts with new data
                         currentPosts = data.posts;
                         
                         // Special case: We previously had no posts but now we have some
                         if (hadEmptyTable && hasPostsNow) {
                             console.log('First posts added, showing pagination');
                             
                             // Update the table with the first posts
                             updatePostsTable(data.posts, true);
                             
                             // Show pagination controls
                             const paginationContainer = document.querySelector('.pagination');
                             if (paginationContainer) {
                                 paginationContainer.style.display = 'flex';
                             }
                             
                             // Update pagination controls
                             updatePaginationControls(currentPage, data.pagination.total_pages);
                             
                             // Update pagination info (showing x to y of z entries)
                             updatePaginationInfo(data.pagination);
                             
                             // Show toast notification
                             showToast(`First post(s) added! Pagination now available.`, 'success', 5000);
                             
                             // Remove empty state if it exists
                             const emptyState = document.querySelector('.empty-state');
                             if (emptyState) {
                                 emptyState.remove();
                             }
                             
                             return; // Skip the regular update flow
                         }
                         
                         if (newPosts.length > 0 || updatedPosts.length > 0 || updatedLikes.length > 0 || updatedComments.length > 0) {
                             console.log(`Found ${newPosts.length} new posts and ${updatedPosts.length} updated posts`);
                             console.log(`Like updates: ${updatedLikes.length}, Comment updates: ${updatedComments.length}`);
                             
                             // Update the table with the new data
                             updatePostsTable(data.posts, true);
                             
                             // If a post modal is currently open, update it if the post has changed
                             if (currentOpenModalPostId !== null) {
                                 const modalPostData = data.posts.find(post => parseInt(post.id) === parseInt(currentOpenModalPostId));
                                 if (modalPostData) {
                                     // Check if this post was updated
                                     const wasPostUpdated = updatedPosts.includes(parseInt(currentOpenModalPostId));
                                     const wasLikesUpdated = updatedLikes.some(item => parseInt(item.id) === parseInt(currentOpenModalPostId));
                                     const wasCommentsUpdated = updatedComments.some(item => parseInt(item.id) === parseInt(currentOpenModalPostId));
                                     
                                     if (wasPostUpdated || wasLikesUpdated || wasCommentsUpdated) {
                                         console.log('Updating open modal with new post data');
                                         updateOpenPostModal(modalPostData, wasPostUpdated, wasLikesUpdated, wasCommentsUpdated);
                                     }
                                 }
                             }
                             
                             // Show appropriate notifications
                             if (newPosts.length > 0) {
                                 showToast(`${newPosts.length} new post(s) have been added`, 'success', 5000);
                                 
                                 // Trigger refresh of stats when new posts are added
                                 if (typeof window.refreshAdminStats === 'function') {
                                     window.refreshAdminStats();
                                 } else {
                                     // Dispatch a custom event that our stats refresh code listens for
                                     document.dispatchEvent(new CustomEvent('postUpdated'));
                                 }
                             }
                             
                             if (updatedPosts.length > 0) {
                                 showToast(`${updatedPosts.length} post(s) have been edited`, 'info', 5000);
                                 
                                 // Trigger stats refresh for post edits too
                                 if (typeof window.refreshAdminStats === 'function') {
                                     window.refreshAdminStats();
                                 } else {
                                     document.dispatchEvent(new CustomEvent('postUpdated'));
                                 }
                             }
                             
                             if (updatedLikes.length > 0 || updatedComments.length > 0) {
                                 const engagementUpdates = [];
                                 if (updatedLikes.length > 0) engagementUpdates.push(`${updatedLikes.length} like updates`);
                                 if (updatedComments.length > 0) engagementUpdates.push(`${updatedComments.length} comment updates`);
                                 
                                 showToast(`Engagement updated: ${engagementUpdates.join(', ')}`, 'info', 3000);
                                 
                                 // Also refresh stats for engagement updates
                                 if (typeof window.refreshAdminStats === 'function') {
                                     window.refreshAdminStats();
                                 } else {
                                     document.dispatchEvent(new CustomEvent('postUpdated'));
                                 }
                             }
                             
                             // Update pagination info if needed
                             updatePaginationInfo(data.pagination);
                             
                             // Check if pagination needs updating due to new posts
                             if (newPosts.length > 0 && data.pagination) {
                                 updatePaginationControls(currentPage, data.pagination.total_pages);
                             }
                             
                             // Highlight cells with updated values
                             highlightUpdatedCells(updatedLikes, updatedComments);
                         }
                     } else if (data.success && data.posts.length === 0 && hadEmptyTable) {
                         // Still no posts, keep pagination hidden
                         const paginationContainer = document.querySelector('.pagination');
                         if (paginationContainer) {
                             paginationContainer.style.display = 'none';
                         }
                     }
                 })
                 .catch(error => {
                     console.error('Error checking for new posts:', error);
                 })
                 .finally(() => {
                     // Reset fetching flag so we can make another request
                     currentlyFetching = false;
                 });
             }
             
             // Function to update an already open post modal with new data
             function updateOpenPostModal(post, contentChanged, likesChanged, commentsChanged) {
                 // Apply visual indication of updates
                 if (contentChanged) {
                     // Flash the content area to indicate update
                     const modalContent = document.getElementById('modalContent');
                     if (modalContent) {
                         modalContent.classList.add('flash-update');
                         setTimeout(() => modalContent.classList.remove('flash-update'), 1500);
                     }
                 }
                 
                 if (likesChanged) {
                     // Flash the likes count to indicate update
                     const modalLikeCount = document.getElementById('modalLikeCount');
                     if (modalLikeCount) {
                         modalLikeCount.classList.add('flash-update');
                         setTimeout(() => modalLikeCount.classList.remove('flash-update'), 1500);
                     }
                 }
                 
                 if (commentsChanged) {
                     // Flash the comments count to indicate update
                     const modalCommentCount = document.getElementById('modalCommentCount');
                     if (modalCommentCount) {
                         modalCommentCount.classList.add('flash-update');
                         setTimeout(() => modalCommentCount.classList.remove('flash-update'), 1500);
                     }
                 }
                 
                 // Update content
                 const modalContent = document.getElementById('modalContent');
                 if (modalContent) modalContent.textContent = post.content;
                 
                 // Update image if applicable
                 const modalImageContainer = document.getElementById('modalImageContainer');
                 const modalImage = document.getElementById('modalImage');
                 if (modalImageContainer && modalImage) {
                     if (post.image_path) {
                         modalImageContainer.style.display = 'block';
                         modalImage.src = post.image_path;
                     } else {
                         modalImageContainer.style.display = 'none';
                     }
                 }
                 
                 // Update like and comment counts
                 const modalLikeCount = document.getElementById('modalLikeCount');
                 const modalCommentCount = document.getElementById('modalCommentCount');
                 if (modalLikeCount) modalLikeCount.textContent = post.likes || '0';
                 if (modalCommentCount) modalCommentCount.textContent = post.comment_count || '0';
                 
                 // Update like button state
                 const modalLikeButton = document.getElementById('modalLikeButton');
                 const modalLikeIcon = document.getElementById('modalLikeIcon');
                 const modalLikeText = document.getElementById('modalLikeText');
                 
                 if (modalLikeButton && modalLikeIcon && modalLikeText) {
                     const isLiked = post.user_has_liked == 1;
                     modalLikeButton.setAttribute('data-liked', isLiked ? 'true' : 'false');
                     
                     if (isLiked) {
                         modalLikeIcon.className = 'bi bi-heart-fill';
                         modalLikeText.textContent = 'Liked';
                         modalLikeIcon.style.color = '#e63946';
                     } else {
                         modalLikeIcon.className = 'bi bi-heart';
                         modalLikeText.textContent = 'Like';
                         modalLikeIcon.style.color = '';
                     }
                 }
             }
             
             // Function to highlight cells that have been updated
             function highlightUpdatedCells(updatedLikes, updatedComments) {
                 // Highlight like count updates
                 updatedLikes.forEach(update => {
                     const row = document.querySelector(`tr[data-post-id="${update.id}"]`);
                     if (row) {
                         const likeCell = row.querySelector('td:nth-child(6)');
                         if (likeCell) {
                             // Add flash animation class
                             likeCell.classList.add('flash-update');
                             // Remove the class after the animation completes
                             setTimeout(() => {
                                 likeCell.classList.remove('flash-update');
                             }, 1500);
                         }
                     }
                 });
                 
                 // Highlight comment count updates
                 updatedComments.forEach(update => {
                     const row = document.querySelector(`tr[data-post-id="${update.id}"]`);
                     if (row) {
                         const commentCell = row.querySelector('td:nth-child(7)');
                         if (commentCell) {
                             // Add flash animation class
                             commentCell.classList.add('flash-update');
                             // Remove the class after the animation completes
                             setTimeout(() => {
                                 commentCell.classList.remove('flash-update');
                             }, 1500);
                         }
                     }
                 });
             }
            
            // Function to update the posts table
            function updatePostsTable(posts, highlightNewRows = false) {
                if (!postsTableBody) return;
                
                // Build HTML for posts
                const html = posts.map(post => {
                    // Determine if this is a new post to highlight
                    const isNewPost = post.id > lastPostId;
                    
                    // Update lastPostId if we found a newer post
                    if (isNewPost && post.id > lastPostId) {
                        lastPostId = post.id;
                    }
                    
                    // Create status badge
                    let statusBadge = '';
                    if (post.status === 'on-hold') {
                        statusBadge = `<span class="status-badge status-on-hold">
                                        <i class="bi bi-pause-circle-fill"></i>
                                        On Hold
                                      </span>`;
                    } else if (post.status === 'approved') {
                        statusBadge = `<span class="status-badge status-approved">
                                        <i class="bi bi-check-circle-fill"></i>
                                        Approved
                                      </span>`;
                    } else if (post.status === 'posted') {
                        statusBadge = `<span class="status-badge status-posted">
                                        <i class="bi bi-file-post"></i>
                                        Posted
                                      </span>`;
                    } else {
                        statusBadge = `<span class="status-badge">
                                        <i class="bi bi-clock"></i>
                                        ${post.status || 'Unknown'}
                                      </span>`;
                    }
                    
                    // Handle image display
                    const hasImage = post.image_path ? 'has-image' : 'no-image';
                    const imageIcon = post.image_path ? 'image-fill' : 'image';
                    
                    // Format content preview
                    const contentPreview = post.content ? (post.content.length > 50 ? 
                                          post.content.substring(0, 50) + '...' : 
                                          post.content) : '';
                    
                    // Format profile picture
                    const profilePicHtml = post.profile_picture
                        ? `<img src="${post.profile_picture}" alt="User" class="user-avatar">`
                        : (() => { const fn=(post.first_name||'')[0]||''; const ln=(post.last_name||'')[0]||''; const n=(post.first_name||'')+(post.last_name||''); let h=0; for(let i=0;i<n.length;i++){h=(h*31+n.charCodeAt(i))&0x7FFFFFFF;} const c=['#2B9E9E','#3CB5A6','#E67E22','#3498DB','#9B59B6','#E74C3C','#1ABC9C','#2C3E50']; return `<div class="user-avatar" style="background:${c[h%c.length]};color:#fff;display:flex;align-items:center;justify-content:center;font-weight:600;font-size:14px;font-family:Poppins,sans-serif;">${(fn+ln).toUpperCase()}</div>`; })()
                    
                    // Determine if this row should be highlighted as new
                    const highlightClass = (highlightNewRows && isNewPost) ? 'highlight-new-row' : '';
                    
                    return `
                    <tr class="${highlightClass}" data-post-id="${post.id}">
                        <td>${post.id}</td>
                        <td>
                            <div class="user-cell">
                                ${profilePicHtml}
                                <div class="user-info">
                                    <div class="user-name">${post.first_name} ${post.last_name}</div>
                                    <div class="username">@${post.username}</div>
                                </div>
                            </div>
                        </td>
                        <td class="content-cell" title="${post.content || ''}">
                            ${contentPreview}
                        </td>
                        <td class="image-cell">
                            <span class="${hasImage}">
                                <i class="bi bi-${imageIcon}"></i>
                            </span>
                        </td>
                        <td>${formatDate(post.created_at)}</td>
                        <td>
                            <span class="engagement-count">
                                ${parseInt(post.likes) > 0 
                                    ? `<i class="bi bi-heart-fill" style="color: #e63946;"></i>` 
                                    : `<i class="bi bi-heart"></i>`}
                                ${post.likes || 0}
                            </span>
                        </td>
                        <td>
                            <span class="engagement-count">
                                <i class="bi bi-chat-dots"></i>
                                ${post.comment_count || 0}
                            </span>
                        </td>
                        <td>
                            ${statusBadge}
                        </td>
                        <td>
                            <div class="action-btns">
                                <button class="btn btn-view btn-sm view-btn" data-post-id="${post.id}">
                                    <i class="bi bi-eye-fill"></i>
                                    View
                                </button>
                                <button class="btn btn-warn btn-sm warn-btn" data-post-id="${post.id}">
                                    <i class="bi bi-exclamation-triangle-fill"></i>
                                    Warn
                                </button>
                            </div>
                        </td>
                    </tr>
                    `;
                }).join('');
                
                // Update table content
                postsTableBody.innerHTML = html;
                
                // Reattach event handlers for the new buttons
                reattachButtonEventHandlers();
            }
            
            // Function to update pagination info based on new data
            function updatePaginationInfo(pagination) {
                if (!pagination) return;
                
                const paginationInfo = document.querySelector('.pagination-info');
                if (paginationInfo) {
                    const start = Math.min((pagination.current_page - 1) * pagination.posts_per_page + 1, pagination.total_posts);
                    const end = Math.min(pagination.current_page * pagination.posts_per_page, pagination.total_posts);
                    paginationInfo.textContent = `Showing ${start} to ${end} of ${pagination.total_posts} entries`;
                }
            }
            
            // Format date function
            function formatDate(dateString) {
                let dStr = dateString;
                if (dStr && !dStr.includes('Z') && !dStr.includes('+') && !dStr.match(/T.*[+-]/)) {
                    dStr = dStr.replace(' ', 'T') + 'Z';
                }
                const date = new Date(dStr);
                const options = { 
                    year: 'numeric', 
                    month: 'short', 
                    day: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit',
                    timeZone: 'Asia/Manila'
                };
                return date.toLocaleDateString('en-US', options);
}

// Function to store post status changes for other pages to pick up
function storePostStatusChange(postId, status) {
    // Store the change in sessionStorage to persist between page loads
    try {
        // Create or get existing status changes object
        let statusChanges = JSON.parse(sessionStorage.getItem('post_status_changes') || '{}');
        
        // Add this change
        statusChanges[postId] = {
            status: status,
            timestamp: new Date().getTime()
        };
        
        // Store back in session storage
        sessionStorage.setItem('post_status_changes', JSON.stringify(statusChanges));
        console.log(`Stored status change: Post ${postId} → ${status}`);
    } catch (error) {
        console.error('Error storing post status change:', error);
    }
            }
            
            // Reattach event handlers to view/warn buttons after table update
            function reattachButtonEventHandlers() {
                document.querySelectorAll('.view-btn, .warn-btn').forEach(button => {
                    button.addEventListener('click', function() {
                        const postId = this.getAttribute('data-post-id');
                        const action = this.classList.contains('warn-btn') ? 'warn' : null;
                        openPostModal(postId, action);
                    });
                });
            }
            
            // Toast notification function
            function showToast(message, type = 'info', duration = 5000) {
                // Create toast element
                const toast = document.createElement('div');
                toast.className = `toast toast-${type}`;
                
                // For new post notifications, make it more visible
                if (type === 'success' && message.includes('new post')) {
                    toast.classList.add('toast-important');
                }
                
                // Create icon based on type
                let icon = 'info-circle-fill';
                if (type === 'success') icon = 'check-circle-fill';
                if (type === 'error') icon = 'exclamation-triangle-fill';
                if (type === 'warning') icon = 'exclamation-circle-fill';
                
                // Set toast content
                toast.innerHTML = `
                    <div class="toast-content">
                        <i class="bi bi-${icon}"></i>
                        <span>${message}</span>
                    </div>
                    <button class="toast-close">×</button>
                `;
                
                // Remove any existing toasts with the same message
                document.querySelectorAll('.toast').forEach(existingToast => {
                    if (existingToast.textContent.trim() === message.trim()) {
                        existingToast.remove();
                    }
                });
                
                // Add to document
                document.body.appendChild(toast);
                
                // Show toast with animation
                setTimeout(() => {
                    toast.classList.add('show');
                }, 10);
                
                // Add close button functionality
                const closeBtn = toast.querySelector('.toast-close');
                closeBtn.addEventListener('click', () => {
                    toast.classList.remove('show');
                    setTimeout(() => {
                        if (document.body.contains(toast)) {
                            document.body.removeChild(toast);
                        }
                    }, 300);
                });
                
                // Auto remove after duration
                setTimeout(() => {
                    if (document.body.contains(toast)) {
                        toast.classList.remove('show');
                        setTimeout(() => {
                            if (document.body.contains(toast)) {
                                document.body.removeChild(toast);
                            }
                        }, 300);
                    }
                }, duration);
            }
            
                         // Start checking for new posts immediately and continuously
             // This creates real-time updates with virtually no delay
             function startRealTimeUpdates() {
                 // Initial check
                 checkForNewPosts();
                 
                 // Check for new posts every 5 seconds
                 setInterval(checkForNewPosts, 5000);
             }
            
                         // Initial call to set up real-time updates
             startRealTimeUpdates();
             
             // AJAX Pagination functionality
             function setupAjaxPagination() {
                 // Add event listeners to all pagination links
                 document.querySelectorAll('.page-link').forEach(pageLink => {
                     pageLink.addEventListener('click', function(e) {
                         e.preventDefault();
                         
                         // Get the page number from data attribute
                         const pageNum = parseInt(this.getAttribute('data-page'));
                         if (isNaN(pageNum)) return;
                         
                         // Show loading indicator
                         const postsTableBody = document.getElementById('posts-table-body');
                         postsTableBody.innerHTML = `
                             <tr>
                                 <td colspan="9" style="text-align: center; padding: 30px;">
                                     <i class="bi bi-arrow-repeat spinning" style="font-size: 2rem; color: var(--primary);"></i>
                                     <p>Loading page ${pageNum}...</p>
                                 </td>
                             </tr>
                         `;
                         
                         // Fetch the data for the requested page
                         fetch(`get_recent_posts.php?page=${pageNum}&t=${new Date().getTime()}`)
                             .then(response => response.json())
                             .then(data => {
                                 if (data.success) {
                                     // Update the current posts data
                                     currentPosts = data.posts;
                                     
                                     // Update the table with the new data
                                     updatePostsTable(data.posts);
                                     
                                     // Update URL without page refresh
                                     const url = new URL(window.location.href);
                                     url.searchParams.set('page', pageNum);
                                     history.pushState({ page: pageNum }, '', url.toString());
                                     
                                     // Update pagination controls
                                     updatePaginationControls(pageNum, data.pagination.total_pages);
                                     
                                     // Update pagination info
                                     updatePaginationInfo(data.pagination);
                                 } else {
                                     showToast('Failed to load page data', 'error');
                                 }
                             })
                             .catch(error => {
                                 console.error('Error fetching page data:', error);
                                 showToast('Error loading page data', 'error');
                             });
                     });
                 });
             }
             
             // Function to update pagination controls
             function updatePaginationControls(currentPage, totalPages) {
                 const controls = document.querySelector('.pagination-controls');
                 if (!controls) return;
                 
                 // Calculate page range
                 const startPage = Math.max(1, Math.min(currentPage - 2, totalPages - 4));
                 const endPage = Math.min(totalPages, Math.max(currentPage + 2, 5));
                 
                 let html = '';
                 
                 // Previous button
                 if (currentPage > 1) {
                     html += `<button data-page="${currentPage - 1}" class="page-btn page-link"><i class="bi bi-chevron-left"></i></button>`;
                 } else {
                     html += `<button class="page-btn disabled"><i class="bi bi-chevron-left"></i></button>`;
                 }
                 
                 // First page + ellipsis
                 if (startPage > 1) {
                     html += `<button data-page="1" class="page-btn page-link">1</button>`;
                     if (startPage > 2) {
                         html += `<span class="page-ellipsis">...</span>`;
                     }
                 }
                 
                 // Page numbers
                 for (let i = startPage; i <= endPage; i++) {
                     if (i === currentPage) {
                         html += `<button class="page-btn active">${i}</button>`;
                     } else {
                         html += `<button data-page="${i}" class="page-btn page-link">${i}</button>`;
                     }
                 }
                 
                 // Last page + ellipsis
                 if (endPage < totalPages) {
                     if (endPage < totalPages - 1) {
                         html += `<span class="page-ellipsis">...</span>`;
                     }
                     html += `<button data-page="${totalPages}" class="page-btn page-link">${totalPages}</button>`;
                 }
                 
                 // Next button
                 if (currentPage < totalPages) {
                     html += `<button data-page="${currentPage + 1}" class="page-btn page-link"><i class="bi bi-chevron-right"></i></button>`;
                 } else {
                     html += `<button class="page-btn disabled"><i class="bi bi-chevron-right"></i></button>`;
                 }
                 
                 // Update the controls HTML
                 controls.innerHTML = html;
                 
                 // Reattach event listeners to the new buttons
                 setupAjaxPagination();
             }
             
             // Set up the pagination when DOM is loaded
             setupAjaxPagination();
             
             // Handle browser back/forward navigation
             window.addEventListener('popstate', function(event) {
                 const pageNum = event.state?.page || 1;
                 fetch(`get_recent_posts.php?page=${pageNum}&t=${new Date().getTime()}`)
                     .then(response => response.json())
                     .then(data => {
                         if (data.success) {
                             currentPosts = data.posts;
                             updatePostsTable(data.posts);
                             updatePaginationControls(pageNum, data.pagination.total_pages);
                             updatePaginationInfo(data.pagination);
                         }
                     })
                     .catch(error => console.error('Error:', error));
             });
        });
    </script>

    <!-- Add direct logout functionality implementation -->
    <script>
        // Wait for DOM to be fully loaded
        document.addEventListener('DOMContentLoaded', function() {
            // Logout functionality
            const logoutButton = document.getElementById('logoutButton');
            const logoutConfirmationOverlay = document.getElementById('logoutConfirmationOverlay');
            const cancelLogoutButton = document.getElementById('cancelLogout');
            
            if (logoutButton) {
                logoutButton.addEventListener('click', function(e) {
                    e.preventDefault();
                    console.log("Logout button clicked");
                    if (logoutConfirmationOverlay) {
                        logoutConfirmationOverlay.style.display = 'flex';
                        document.body.style.overflow = 'hidden';
                    } else {
                        console.error("Logout overlay not found");
                    }
                });
            } else {
                console.error("Logout button not found");
            }
            
            if (cancelLogoutButton) {
                cancelLogoutButton.addEventListener('click', function() {
                    logoutConfirmationOverlay.style.display = 'none';
                    document.body.style.overflow = 'auto';
                });
            }
            
            if (logoutConfirmationOverlay) {
                logoutConfirmationOverlay.addEventListener('click', function(event) {
                    if (event.target === logoutConfirmationOverlay) {
                        logoutConfirmationOverlay.style.display = 'none';
                        document.body.style.overflow = 'auto';
                    }
                });
            }
        });
    </script>
</body>
</html>