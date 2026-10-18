<?php
session_start();

require_once 'db_connection.php';

date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['user_id'])) {
    header("Location: process_login.php");
    exit();
}



// Get current user info
$current_user_id = $_SESSION['user_id'];
$sql = "SELECT first_name, last_name, username, profile_picture FROM users WHERE id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$current_user_id]);
$current_user = $stmt->fetch();

// Define getInitialsHtml early so AJAX search handler can use it before navbar.php is included
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
        return '<div class="initials-avatar" style="width:'.$size.'px;height:'.$size.'px;border-radius:50%;background:'.$bg.';color:#fff;display:flex;align-items:center;justify-content:center;font-weight:600;font-size:'.($size * 0.38).'px;flex-shrink:0;letter-spacing:0.5px;">'.$initials.'</div>';
    }
}

// Handle search
$search_results = [];
if (isset($_GET['search'])) {
    $search_term = $_GET['search'] . '%';
    // Exclude Admin users from search results (hardcoded)
    $sql = "SELECT id, first_name, last_name, username, profile_picture FROM users 
            WHERE (first_name LIKE ? OR last_name LIKE ? OR username LIKE ?) 
            AND id != ?
            AND username != 'Admin' 
            AND username != 'admin'";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$search_term, $search_term, $search_term, $current_user_id]);
    $search_results = $stmt->fetchAll();
    
    // Return only the search results for AJAX requests
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        ob_start();
        foreach ($search_results as $user): ?>
            <a href="message.php?user_id=<?php echo $user['id']; ?>" class="user-item">
                <?php if (!empty($user['profile_picture'])): ?>
                    <img src="<?php echo $user['profile_picture']; ?>" class="user-avatar">
                <?php else: ?>
                    <div class="user-avatar" style="display:flex;align-items:center;justify-content:center;flex-shrink:0;"><?php echo getInitialsHtml($user['first_name'], $user['last_name'], 40); ?></div>
                <?php endif; ?>
                <div class="user-details">
                    <div class="user-name"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></div>
                    <div class="user-username">@<?php echo htmlspecialchars($user['username']); ?></div>
                </div>
            </a>
        <?php endforeach;
        $html = ob_get_clean();
        echo $html;
        exit;
    }
}

// Mark messages as read BEFORE fetching conversations so unread counts are accurate
$selected_user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : null;
if ($selected_user_id) {
    $mark_read = $pdo->prepare("UPDATE messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ? AND is_read = 0");
    $mark_read->execute([$selected_user_id, $current_user_id]);
}

// Get all conversations for the current user with unread message counts
$conversations = [];
$sql = "SELECT u.id, u.first_name, u.last_name, u.username, u.profile_picture, 
               MAX(m.created_at) as last_message_time,
               SUM(CASE WHEN m.receiver_id = ? AND m.is_read = 0 THEN 1 ELSE 0 END) as unread_count
        FROM messages m
        JOIN users u ON (m.sender_id = u.id OR m.receiver_id = u.id) AND u.id != ?
        WHERE ? IN (m.sender_id, m.receiver_id)
        GROUP BY u.id, u.first_name, u.last_name, u.username, u.profile_picture
        ORDER BY last_message_time DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$current_user_id, $current_user_id, $current_user_id]);
$conversations = $stmt->fetchAll();

// Get messages for selected conversation
$messages = [];
$selected_user = null;

if ($selected_user_id) {

    // Get selected user info
    $sql = "SELECT id, first_name, last_name, username, profile_picture, last_activity, user_status FROM users WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$selected_user_id]);
    $selected_user = $stmt->fetch();

    // Compute initial status server-side to avoid flash of "Active recently"
    $initial_status_text = 'Active recently';
    $initial_status_class = 'recently';
    if ($selected_user) {
        $last_activity = strtotime($selected_user['last_activity'] . ' UTC');
        $diff_minutes = round((time() - $last_activity) / 60);
        $user_status = isset($selected_user['user_status']) ? $selected_user['user_status'] : 'offline';

        if ($user_status === 'offline') {
            $initial_status_text = 'Last seen recently';
            $initial_status_class = 'offline';
        } else if ($user_status === 'inactive') {
            $initial_status_text = 'Away';
            $initial_status_class = 'away';
        } else {
            if ($diff_minutes < 1) {
                $initial_status_text = 'Active now';
                $initial_status_class = 'online';
            } else if ($diff_minutes < 5) {
                $initial_status_text = 'Active ' . $diff_minutes . ' minutes ago';
                $initial_status_class = 'recently';
            } else if ($diff_minutes < 60) {
                $initial_status_text = 'Active ' . $diff_minutes . ' minutes ago';
                $initial_status_class = 'away';
            } else if ($diff_minutes < 1440) {
                $hours = floor($diff_minutes / 60);
                $initial_status_text = 'Active ' . $hours . ' hours ago';
                $initial_status_class = 'away';
            } else {
                $days = floor($diff_minutes / 1440);
                $initial_status_text = 'Active ' . $days . ' days ago';
                $initial_status_class = 'offline';
            }
        }
    }

    // Get messages between current user and selected user, including deleted ones
    $sql = "SELECT m.*, u.first_name, u.last_name, u.profile_picture
            FROM messages m
            JOIN users u ON m.sender_id = u.id
            WHERE (m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?)
            ORDER BY m.created_at ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$current_user_id, $selected_user_id, $selected_user_id, $current_user_id]);
    $messages = $stmt->fetchAll();
}

// Handle sending a new message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $selected_user_id) {
    $message = isset($_POST['message']) ? trim($_POST['message']) : '';
    $image_paths = [];

    // Handle multiple image uploads
    if (!empty($_FILES['message_images']['name'][0])) {
        $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $dir = __DIR__ . '/uploads/message_images';
        if (!is_dir($dir)) mkdir($dir, 0777, true);

        $fileCount = min(count($_FILES['message_images']['name']), 5);
        for ($i = 0; $i < $fileCount; $i++) {
            if ($_FILES['message_images']['error'][$i] !== UPLOAD_ERR_OK) continue;

            $mime = finfo_file($finfo, $_FILES['message_images']['tmp_name'][$i]);
            if (in_array($mime, $allowed, true)) {
                $ext = match($mime) {
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/gif'  => 'gif',
                    'image/webp' => 'webp',
                    default      => 'jpg'
                };
                $filename = 'msg_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                $filepath = $dir . '/' . $filename;
                if (move_uploaded_file($_FILES['message_images']['tmp_name'][$i], $filepath)) {
                    $image_paths[] = 'uploads/message_images/' . $filename;
                }
            }
        }
        finfo_close($finfo);
    }

    $image_path = !empty($image_paths) ? json_encode($image_paths) : null;

    if ($message !== '' || $image_path !== null) {
        $sql = "INSERT INTO messages (sender_id, receiver_id, content, image_path) VALUES (?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$current_user_id, $selected_user_id, $message ?: null, $image_path]);

        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            $new_message_id = $pdo->lastInsertId();

            $sql = "SELECT m.*, u.first_name, u.last_name, u.profile_picture
                    FROM messages m
                    JOIN users u ON m.sender_id = u.id
                    WHERE m.id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$new_message_id]);
            $new_message = $stmt->fetch();

            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'message' => $new_message
            ]);
            exit();
        } else {
            header("Location: message.php?user_id=" . $selected_user_id);
            exit();
        }
    }
}

// Helper function to format time
function formatMessageTime($timestamp) {
    $tz = new DateTimeZone('Asia/Manila');
    $now = new DateTime('now', $tz);
    $time = new DateTime($timestamp, new DateTimeZone('UTC'));
    $time->setTimezone($tz);
    
    $today = $now->format('Y-m-d');
    $msgDate = $time->format('Y-m-d');
    
    if ($today === $msgDate) {
        return 'Today at ' . $time->format('g:i A');
    }
    
    $yesterday = (clone $now)->modify('-1 day')->format('Y-m-d');
    if ($yesterday === $msgDate) {
        return 'Yesterday at ' . $time->format('g:i A');
    }
    
    $diff = $now->diff($time);
    if ($diff->d < 7) {
        return $time->format('l') . ' at ' . $time->format('g:i A');
    }
    
    return $time->format('M j, Y') . ' at ' . $time->format('g:i A');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BondNest | Messages</title>
    <?php include 'font-head.php'; ?>
    <!-- WE USE BOOTSTRAPICON FOR ICONS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="homepage.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="homepage2.css?v=<?php echo time(); ?>">
    
    <style>
    :root {
        --color-primary: #008080;
        --color-primary-dark: #006666;
        --color-primary-light: rgba(0, 128, 128, 0.1);
        --color-white: #ffffff;
        --color-dark: #333333;
        --color-gray: #666666;
        --card-border-radius: 12px;
    }

    /* Adjust main container to be closer to navbar */
    main {
        margin-top: 0; /* Reduced from default margin */
        padding-top: 5px; /* Even smaller padding */
    }

    .container {
        margin-top: 0; /* Remove any top margin */
        padding-top: 0; /* No padding top */
        gap: 10px; /* Reduce the gap between sections */
    }

    /* Adjust left sidebar */
    .left {
        margin-top: 0; /* No top margin */
        height: calc(100vh - 65px); /* Adjust height to be closer to navbar */
    }

    /* Middle chat area - Enhanced Design */
    .middle {
        flex: 1;
        display: flex;
        flex-direction: column;
        background-color: #ffffff;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        border: 1px solid var(--color-primary);
        margin-top: 0; /* Ensure no top margin */
        height: calc(100vh - 100px); /* Adjust height to be closer to navbar */
    }

    .chat-area {
        display: flex;
        flex-direction: column;
        flex: 1;
        background: #ffffff;
        border-radius: 0;
        overflow: hidden;
        height: calc(100vh - 65px); /* Adjust height to be closer to navbar */
        border: none;
    }

    .chat-header {
        padding: 15px 20px;
        background-color: #ffffff;
        border-bottom: 1px solid var(--color-primary);
        display: flex;
        align-items: center;
        gap: 20px;
        position: relative;
    }

    .chat-header::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        height: 1px;
        background: linear-gradient(90deg, transparent, var(--color-primary), transparent);
    }

    .chat-header-avatar {
        width: 42px;
        height: 42px;
        border-radius: 50%;
        object-fit: cover;
        flex-shrink: 0;
    }

    .chat-header-avatar .initials-avatar {
        width: 100% !important;
        height: 100% !important;
        font-size: 1rem !important;
    }

    .chat-user-info {
        flex: 1;
    }

    .chat-user {
        font-weight: 600;
        margin-bottom: 2px;
        color: var(--color-dark);
    }

    .chat-status {
        color: var(--color-gray);
        font-size: 13px;
        display: flex;
        align-items: center;
    }

    .chat-status::before {
        content: '';
        display: inline-block;
        width: 8px;
        height: 8px;
        background-color: #999; /* Default gray */
        border-radius: 50%;
        margin-right: 6px;
    }

    .chat-status.online::before {
        background-color: #4CAF50; /* Green */
    }

    .chat-status.recently::before {
        background-color: #2196F3; /* Blue */
    }

    .chat-status.away::before {
        background-color: #FF9800; /* Orange */
    }

    .chat-status.offline::before {
        background-color: #999; /* Gray */
    }

    .chat-messages {
        flex: 1;
        padding: 20px;
        overflow-y: auto;
        background-color: #f5fdfd;
        display: flex;
        flex-direction: column;
        background-image: 
            linear-gradient(rgba(0, 128, 128, 0.03) 1px, transparent 1px),
            linear-gradient(90deg, rgba(0, 128, 128, 0.03) 1px, transparent 1px);
        background-size: 20px 20px;
        max-height: calc(100vh - 185px); /* Adjusted fixed height */
        height: calc(100vh - 185px); /* Adjusted fixed height */
    }

    .message-container {
        display: flex;
        flex-direction: row;
        align-items: center;
        margin-bottom: 15px;
        max-width: 80%;
        width: fit-content;
        min-width: 0;
        transition: all 0.3s ease;
        position: relative;
        gap: 4px;
        z-index: 1;
    }

    .message-container:has(.message-dropdown.show) {
        z-index: 10;
    }

    .message-container:hover {
        transform: translateY(-1px);
    }

    .message-container.received {
        align-self: flex-start;
    }

    .message-container.sent {
        align-self: flex-end;
    }

    .message-content {
        display: flex;
        flex-direction: column;
        flex: 0 1 auto;
        min-width: 0;
        max-width: 100%;
        position: relative;
    }

    .sent .message-content {
        align-items: flex-end;
    }

    .received .message-content {
        align-items: flex-start;
    }

    .sent .message-content .timestamp {
        position: absolute;
        top: 100%;
        right: 0;
        white-space: nowrap;
    }

    .sent .message-content {
        margin-bottom: 20px;
    }

    .message {
        padding: 12px 16px;
        border-radius: 18px;
        font-size: 15px;
        max-width: 100%;
        width: fit-content;
        word-wrap: break-word;
        overflow-wrap: anywhere;
        word-break: break-word;
        white-space: normal;
        line-height: 1.4;
        position: relative;
    }

    .message:has(.message-image),
    .message:has(.message-image-grid) {
        background: transparent !important;
        padding: 0 !important;
        border: none !important;
        box-shadow: none !important;
        color: inherit !important;
    }

    .message:has(.message-image) .message-image,
    .message:has(.message-image-grid) .message-image-grid {
        border-radius: 12px;
    }

    .received .message {
        background-color: var(--color-white);
        border-bottom-left-radius: 5px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.08);
        border: 1px solid rgba(0, 128, 128, 0.1);
    }

    .sent .message {
        background-color: var(--color-primary);
        color: white;
        border-bottom-right-radius: 5px;
        box-shadow: 0 2px 6px rgba(0, 128, 128, 0.3);
    }

    .sent .message::after {
        content: '';
        position: absolute;
        right: -6px;
        top: 50%;
        width: 0;
        height: 0;
        border: 10px solid transparent;
        border-left-color: var(--color-primary);
        border-right: 0;
        margin-top: -10px;
        margin-right: -10px;
    }

    .sent .message:has(.message-image)::after,
    .sent .message:has(.message-image-grid)::after {
        display: none;
    }

    .received .message::before {
        content: '';
        position: absolute;
        left: -6px;
        top: 50%;
        width: 0;
        height: 0;
        border: 10px solid transparent;
        border-right-color: var(--color-white);
        border-left: 0;
        margin-top: -10px;
        margin-left: -10px;
    }

    .received .message:has(.message-image)::before,
    .received .message:has(.message-image-grid)::before {
        display: none;
    }

    .timestamp {
        color: var(--color-gray);
        font-size: 11px;
        margin-top: 4px;
        padding: 0 8px;
        font-weight: 500;
    }

    .timestamp .edited {
        font-style: italic;
        color: var(--color-primary);
    }

    .chat-input-container {
        padding: 15px 20px;
        background-color: var(--color-white);
        border-top: 1px solid rgba(0, 128, 128, 0.1);
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .chat-input {
        flex: 1;
        border: 1px solid var(--color-primary);
        border-radius: 24px;
        padding: 12px 20px;
        font-size: 15px;
        outline: none;
        transition: all 0.3s ease;
        background-color: #f9f9f9;
    }

    .chat-input:focus {
        border-color: var(--color-primary);
        box-shadow: 0 0 0 2px rgba(0, 128, 128, 0.2);
        background-color: var(--color-white);
    }

    .send-button {
        background-color: var(--color-primary);
        color: white;
        border: none;
        border-radius: 50%;
        width: 44px;
        height: 44px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.2s;
        box-shadow: 0 2px 6px rgba(0, 128, 128, 0.3);
    }

    .send-button:hover {
        background-color: var(--color-primary-dark);
        transform: translateY(-2px);
    }

    .send-button:active {
        transform: translateY(0);
    }

    .message-image-grid {
        display: grid;
        gap: 2px;
        max-width: 320px;
        border-radius: 12px;
        overflow: hidden;
    }
    .message-image-grid.grid-1 { grid-template-columns: 1fr; }
    .message-image-grid.grid-2 { grid-template-columns: 1fr 1fr; }
    .message-image-grid.grid-3 { grid-template-columns: 1fr 1fr; }
    .message-image-grid.grid-3 .message-image-cell:first-child { grid-row: span 2; }
    .message-image-grid.grid-4 { grid-template-columns: 1fr 1fr; grid-template-rows: 1fr 1fr; }
    .message-image-grid.grid-5 {
        grid-template-columns: repeat(6, 1fr);
        grid-template-rows: 1fr 1fr;
    }
    .message-image-grid.grid-5 .message-image-cell:nth-child(-n+3) {
        grid-column: span 2;
    }
    .message-image-grid.grid-5 .message-image-cell:nth-child(n+4) {
        grid-column: span 3;
    }

    .message-image-cell {
        overflow: hidden;
        cursor: pointer;
        position: relative;
        min-height: 100px;
    }
    .message-image-cell img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
        transition: transform 0.2s;
    }
    .message-image-cell img:hover {
        transform: scale(1.02);
    }

    .message-image {
        max-width: 280px;
        max-height: 300px;
        border-radius: 12px;
        display: block;
        cursor: pointer;
        transition: transform 0.2s;
    }
    .message-image:hover {
        transform: scale(1.01);
    }

    /* Lightbox */
    .message-lightbox {
        display: none;
        position: fixed;
        top: 0; left: 0;
        width: 100vw; height: 100vh;
        background: rgba(0,0,0,0.92);
        z-index: 10000;
        justify-content: center;
        align-items: center;
        flex-direction: column;
    }
    .message-lightbox.active { display: flex; }
    .message-lightbox img {
        max-width: 90vw;
        max-height: 80vh;
        border-radius: 4px;
        object-fit: contain;
    }
    .lightbox-close {
        position: absolute;
        top: 16px; right: 20px;
        color: white;
        font-size: 32px;
        cursor: pointer;
        z-index: 10001;
        background: none;
        border: none;
    }
    .lightbox-nav {
        position: absolute;
        top: 50%;
        transform: translateY(-50%);
        color: white;
        font-size: 40px;
        cursor: pointer;
        background: rgba(255,255,255,0.15);
        border: none;
        border-radius: 50%;
        width: 50px; height: 50px;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: background 0.2s;
    }
    .lightbox-nav:hover { background: rgba(255,255,255,0.3); }
    .lightbox-prev { left: 16px; }
    .lightbox-next { right: 16px; }
    .lightbox-counter {
        color: white;
        margin-top: 12px;
        font-size: 14px;
    }

    .image-upload-btn {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 40px;
        height: 40px;
        border-radius: 50%;
        cursor: pointer;
        color: var(--color-primary);
        font-size: 1.3rem;
        transition: background 0.2s;
        flex-shrink: 0;
    }

    .image-upload-btn:hover {
        background: rgba(0, 128, 128, 0.1);
    }

    .image-preview-bar {
        display: flex;
        align-items: center;
        gap: 6px;
        background: #f0f2f5;
        border-radius: 8px;
        padding: 4px 8px;
    }

    .image-preview-bar img {
        width: 36px;
        height: 36px;
        border-radius: 6px;
        object-fit: cover;
    }

    .remove-image-btn {
        background: none;
        border: none;
        font-size: 18px;
        color: #e74c3c;
        cursor: pointer;
        padding: 0 2px;
        line-height: 1;
    }

    /* Right section - Enhanced Design */
    .right-search {
        background-color: #ffffff;
        border-radius: 12px;
        padding: 0;
        overflow: hidden;
        height: calc(100vh - 100px);
        position: relative;
        border: 1px solid var(--color-primary);
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        margin-top: 0;
    }

    .search-header {
        padding: 20px;
        position: sticky;
        top: 0;
        background-color: #ffffff;
        z-index: 10;
        border-bottom: 1px solid var(--color-primary);
        display: flex;
        flex-direction: column;
        align-items: flex-start;
    }

    .search-header h2 {
        font-size: 20px;
        margin-bottom: 15px;
        color: #333333;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .search-header h2::before {
        content: '';
        display: block;
        width: 4px;
        height: 20px;
        background-color: var(--color-primary);
        border-radius: 2px;
    }

    .search-bar {
        display: flex;
        align-items: center;
        background-color: #f5fdfd;
        border-radius: 24px;
        padding: 10px 16px;
        border: none;
        transition: all 0.3s ease;
        width: 100%;
        margin-top: 0;
    }

    .search-bar:focus-within {
        border: none;
        box-shadow: none;
    }

    .search-bar i {
        color: var(--color-gray);
        margin-right: 8px;
        font-size: 16px;
    }

    .search-bar input {
        border: none;
        background: transparent;
        outline: none;
        width: 100%;
        font-size: 14px;
        color: var(--color-dark);
    }

    .user-list {
        padding: 0 10px 10px;
        overflow-y: auto;
        max-height: calc(100vh - 200px);
    }

    .user-list h4 {
        padding: 10px 10px 5px;
        color: var(--color-gray);
        font-size: 13px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .user-item {
        display: flex;
        align-items: center;
        padding: 12px 15px;
        cursor: pointer;
        border-radius: 8px;
        text-decoration: none;
        color: inherit;
        transition: all 0.2s ease;
        margin: 5px 0;
        position: relative;
    }

    .user-item:hover {
        background-color: rgba(0, 128, 128, 0.05);
    }

    .user-item.active {
        background-color: rgba(0, 128, 128, 0.1);
    }

    .user-item.active::after {
        content: '';
        position: absolute;
        left: 0;
        top: 0;
        bottom: 0;
        width: 3px;
        background-color: var(--color-primary);
        border-radius: 3px 0 0 3px;
    }

    .user-avatar {
        width: 44px;
        height: 44px;
        min-width: 44px;
        border-radius: 50%;
        object-fit: cover;
        margin-right: 12px;
        flex-shrink: 0;
    }

    .user-avatar.initials-avatar {
        object-fit: unset;
    }

    .user-details {
        flex: 1;
        min-width: 0;
    }

    .user-name {
        font-weight: 600;
        font-size: 15px;
        color: var(--color-dark);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .user-username {
        color: var(--color-gray);
        font-size: 13px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .unread-badge {
        background-color: var(--color-primary, #008080);
        color: white;
        font-size: 0.7rem;
        font-weight: 600;
        border-radius: 50%;
        min-width: 20px;
        height: 20px;
        display: flex;
        justify-content: center;
        align-items: center;
        flex-shrink: 0;
        margin-left: 8px;
        padding: 0 4px;
    }

    @keyframes pulse {
        0% { opacity: 1; }
        50% { opacity: 0.5; }
        100% { opacity: 1; }
    }

    .no-conversations {
        text-align: center;
        color: var(--color-gray);
        padding: 40px 20px;
        display: flex;
        flex-direction: column;
        align-items: center;
    }

    .no-conversations i {
        font-size: 40px;
        margin-bottom: 15px;
        color: rgba(0, 128, 128, 0.2);
    }

    .no-chat-selected {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        height: 100%;
        color: var(--color-gray);
        text-align: center;
        padding: 40px;
        background-color: #f5fdfd;
    }

    .no-chat-selected i {
        font-size: 60px;
        margin-bottom: 20px;
        color: rgba(0, 128, 128, 0.15);
    }

    .no-chat-selected h3 {
        font-size: 18px;
        margin-bottom: 10px;
        color: var(--color-dark);
    }

    /* Search results styling */
    #searchResults {
        background: white;
        border-radius: 8px;
        max-height: 300px;
        overflow-y: auto;
        display: none;
        z-index: 100;
        position: absolute;
        left: 10px;
        right: 10px;
        width: calc(100% - 20px);
        border: 1px solid var(--color-primary);
        box-shadow: 0 5px 20px rgba(0, 0, 0, 0.2);
    }

    #searchResults .user-item {
        border-bottom: 1px solid rgba(0, 128, 128, 0.08);
        margin: 0;
        padding: 12px 15px;
    }

    #searchResults .user-item:last-child {
        border-bottom: none;
    }

    #searchResults .user-name {
        color: #333;
    }

    #searchResults .user-username {
        color: #888;
    }

    #searchResults .no-conversations {
        padding: 20px;
        text-align: center;
        color: var(--color-gray);
    }

    /* Scrollbar styling */
    ::-webkit-scrollbar {
        width: 6px;
    }

    ::-webkit-scrollbar-track {
        background: transparent;
    }

    ::-webkit-scrollbar-thumb {
        background: #ccc;
        border-radius: 3px;
    }

    ::-webkit-scrollbar-thumb:hover {
        background: #999;
    }

    /* Typing indicator */
    .typing-indicator {
        display: flex;
        align-items: center;
        padding: 8px 15px;
        background-color: var(--color-white);
        border-radius: 18px;
        margin-bottom: 15px;
        align-self: flex-start;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        border: 1px solid var(--color-primary);
    }

    .typing-indicator span {
        height: 8px;
        width: 8px;
        background-color: var(--color-gray);
        border-radius: 50%;
        display: inline-block;
        margin: 0 2px;
        animation: bounce 1.5s infinite ease-in-out;
    }

    .typing-indicator span:nth-child(1) {
        animation-delay: 0s;
    }

    .typing-indicator span:nth-child(2) {
        animation-delay: 0.2s;
    }

    .typing-indicator span:nth-child(3) {
        animation-delay: 0.4s;
    }

    @keyframes bounce {
        0%, 60%, 100% {
            transform: translateY(0);
        }
        30% {
            transform: translateY(-5px);
        }
    }

    .sidebar a {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 15px; /* Reduced padding */
        text-decoration: none;
        color: #333;
        border-radius: 8px;
        transition: all 0.3s ease;
    }

    .sidebar a:hover {
        background-color: rgba(0, 128, 128, 0.1);
    }

    .sidebar a.active {
        background-color: rgba(0, 128, 128, 0.1);
        color: #333;
    }

    .sidebar a i {
        font-size: 1.2rem;
        color: #008080;
    }

    .sidebar a h3 {
        font-size: 1rem;
        font-weight: 500;
        color: #333;
    }

    /* Add new styles for message menu */
    .message-menu {
        opacity: 0;
        transition: opacity 0.2s ease;
        cursor: pointer;
        padding: 4px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.9);
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        z-index: 1;
        flex-shrink: 0;
        align-self: center;
        position: relative;
    }

    .message-container:hover .message-menu {
        opacity: 1;
    }

    .message-container.received .message-menu {
        display: none;
    }

    .message-menu i {
        color: var(--color-gray);
        font-size: 16px;
    }

    .message-menu:hover {
        background: rgba(255, 255, 255, 1);
    }

    .message-menu:hover i {
        color: var(--color-primary);
    }

    /* Add styles for dropdown menu */
    .message-dropdown {
        position: absolute;
        background: white;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        padding: 8px 0;
        min-width: 120px;
        display: none;
        z-index: 1000;
    }

    .message-dropdown.show {
        display: block;
    }

    .message-container.sent .message-dropdown {
        right: 0;
        top: calc(100% + 4px);
    }

    .message-container.received .message-dropdown {
        left: 0;
        top: calc(100% + 4px);
    }

    .dropdown-item {
        padding: 8px 16px;
        display: flex;
        align-items: center;
        gap: 8px;
        color: var(--color-dark);
        text-decoration: none;
        transition: all 0.2s ease;
        cursor: pointer;
    }

    .dropdown-item:hover {
        background: rgba(0, 128, 128, 0.1);
    }

    .dropdown-item i {
        font-size: 14px;
        color: var(--color-gray);
    }

    .dropdown-item:hover i {
        color: var(--color-primary);
    }

    .dropdown-item.delete {
        color: #dc3545;
    }

    .dropdown-item.delete:hover {
        background: rgba(220, 53, 69, 0.1);
    }

    .dropdown-item.delete i {
        color: #dc3545;
    }

    /* Delete Confirmation Modal */
    .delete-modal {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: rgba(0, 0, 0, 0.5);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 10000;
        opacity: 0;
        visibility: hidden;
        transition: all 0.3s ease;
    }

    .delete-modal.show {
        opacity: 1;
        visibility: visible;
    }

    .delete-modal-content {
        background-color: #fff;
        border-radius: 12px;
        width: 100%;
        max-width: 400px;
        padding: 0;
        overflow: hidden;
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
        transform: translateY(20px);
        transition: transform 0.3s ease;
    }

    .delete-modal.show .delete-modal-content {
        transform: translateY(0);
    }

    .delete-modal-header {
        background-color: var(--color-primary);
        color: white;
        padding: 15px 20px;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .delete-modal-header h3 {
        margin: 0;
        font-size: 18px;
        font-weight: 600;
    }

    .delete-modal-close {
        background: none;
        border: none;
        color: white;
        font-size: 20px;
        cursor: pointer;
        opacity: 0.8;
        transition: opacity 0.2s;
    }

    .delete-modal-close:hover {
        opacity: 1;
    }

    .delete-modal-body {
        padding: 20px;
        text-align: center;
    }

    .delete-modal-body p {
        margin: 0 0 15px;
        font-size: 16px;
        color: #555;
    }

    .delete-modal-message {
        background-color: rgba(0, 128, 128, 0.1);
        border-radius: 8px;
        padding: 15px;
        margin: 15px 0;
        max-height: 100px;
        overflow: hidden;
        text-overflow: ellipsis;
        word-break: break-word;
    }

    .delete-modal-footer {
        display: flex;
        justify-content: flex-end;
        padding: 15px 20px;
        background-color: #f9f9f9;
        border-top: 1px solid #eee;
        gap: 10px;
    }

    .delete-modal-cancel {
        background-color: #f1f1f1;
        color: #333;
        border: none;
        border-radius: 6px;
        padding: 10px 16px;
        cursor: pointer;
        font-weight: 500;
        transition: all 0.2s;
    }

    .delete-modal-cancel:hover {
        background-color: #e1e1e1;
    }

    .delete-modal-confirm {
        background-color: #dc3545;
        color: white;
        border: none;
        border-radius: 6px;
        padding: 10px 16px;
        cursor: pointer;
        font-weight: 500;
        transition: all 0.2s;
    }

    .delete-modal-confirm:hover {
        background-color: #c82333;
    }

    /* Deleted message styling */
    .message-container.deleted .message {
        background-color: #f1f1f1;
        color: #999;
        font-style: italic;
    }

    .message-container.deleted.sent .message {
        background-color: rgba(0, 128, 128, 0.1);
    }

    .message-container.deleted .message-menu {
        display: none;
    }

    .message-container.deleted .timestamp {
        opacity: 0.7;
    }

    /* Add responsive styles */
    @media screen and (max-width: 1500px) {
        .container {
            grid-template-columns: 1fr;
            padding: 0;
            margin: 0;
        }

        .left {
            display: none !important; /* Hide sidebar on mobile */
        }

        .right-search {
            display: none !important; /* Hide right section on mobile */
        }

        .middle {
            width: 100vw !important;
            margin: 0 !important;
            height: 100vh !important;
            border-radius: 0 !important;
            min-width: 0 !important;
        }

        .chat-area {
            height: 100%;
            border-radius: 0;
        }

        .chat-messages {
            height: calc(100vh - 130px);
            max-height: none;
        }

        .chat-header {
            position: sticky;
            top: 0;
            z-index: 100;
            background: white;
        }

        /* Add back button for mobile */
        .chat-header::before {
            content: '\F12A';
            font-family: 'bootstrap-icons';
            margin-right: 10px;
            font-size: 1.2rem;
            cursor: pointer;
        }

        /* Adjust message container width for mobile */
        .message-container {
            max-width: 95%;
        }

        /* Make chat input more mobile-friendly */
        .chat-input-container {
            position: sticky;
            bottom: 0;
            background: white;
            padding: 10px;
        }

        .chat-input {
            padding: 10px 15px;
        }

        .send-button {
            width: 40px;
            height: 40px;
        }

        .search-header {
            flex-direction: column;
            align-items: flex-start;
        }
        .search-header h2 {
            margin-bottom: 10px;
        }
        .search-bar {
            width: 100%;
            margin-top: 0;
            margin-bottom: 10px;
        }
    }

    /* Add styles for showing/hiding sections on mobile */
    .show-messages {
        display: block !important;
    }

    .show-chat {
        display: block !important;
    }

    /* Floating button for mobile */
    .open-panel-btn {
        display: none;
        position: fixed;
        right: 20px;
        top: 50%;
        transform: translateY(-50%);
        z-index: 2000;
        background: var(--color-primary);
        color: #fff;
        border: none;
        border-radius: 50%;
        width: 56px;
        height: 56px;
        box-shadow: 0 4px 16px rgba(0,0,0,0.15);
        font-size: 2rem;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: background 0.2s;
    }
    .open-panel-btn:hover {
        background: var(--color-primary-dark);
    }

    /* Sliding panel for mobile */
    #rightSearchPanel {
        transition: transform 0.3s cubic-bezier(.4,0,.2,1);
    }
    @media screen and (max-width: 1500px) {
        .right-search {
            position: fixed;
            top: 0;
            right: 0;
            width: 90vw;
            max-width: 400px;
            height: 100vh;
            background: #fff;
            z-index: 3000;
            box-shadow: -2px 0 16px rgba(0,0,0,0.12);
            transform: translateX(100%);
            display: block !important;
            border-radius: 0;
            padding-bottom: 0;
        }
        .right-search.open {
            transform: translateX(0);
        }
        .open-panel-btn {
            display: flex;
        }
        .close-panel-btn {
            display: block;
            position: absolute;
            top: 18px;
            right: 18px;
            background: none;
            border: none;
            font-size: 2rem;
            color: var(--color-primary);
            z-index: 4000;
            cursor: pointer;
        }
        .search-header {
            padding-bottom: 0;
        }
        .mobile-search-bar {
            margin-top: 10px;
            display: block !important; /* Ensure search bar is always visible */
        }
        .search-bar {
            display: flex !important; /* Ensure search bar is always visible */
            width: 100% !important;
            margin: 10px 0 !important;
        }
        .search-bar input {
            width: 100% !important;
            display: block !important;
        }
    }

    /* Additional styles for very small screens */
    @media screen and (max-width: 400px) {
        .search-header {
            padding: 15px;
        }
        .search-bar {
            padding: 8px 12px;
        }
        .search-bar input {
            font-size: 14px;
        }
        .search-header h2 {
            font-size: 18px;
        }
        .mobile-search-bar {
            margin-top: 5px;
        }
    }
</style>
    <link rel="stylesheet" href="delete-message-modal.css?v=<?php echo time(); ?>">
</head>
<body>
    <?php include 'navbar.php'; ?>
    <?php include 'mobile-nav.php'; ?>
    
    <main>
        <div class="container" style="padding-top: 0; margin-top: -30px;">
            <!-- LEFT SIDEBAR -->
            <?php include 'sidebar.php'; ?>
            
            <!-- MIDDLE SECTION - CHAT AREA -->
            <div class="middle">
                <div class="chat-area">
                    <?php if ($selected_user): ?>
                        <div class="chat-header">
                            <?php if (!empty($selected_user['profile_picture'])): ?>
                                <img src="<?php echo $selected_user['profile_picture']; ?>" class="chat-header-avatar">
                            <?php else: ?>
                                <div class="chat-header-avatar"><?php echo getInitialsHtml($selected_user['first_name'], $selected_user['last_name'], 44); ?></div>
                            <?php endif; ?>
                            <div class="chat-user-info">
                                <div class="chat-user"><?php echo htmlspecialchars($selected_user['first_name'] . ' ' . $selected_user['last_name']); ?></div>
                                <div class="chat-status <?php echo $initial_status_class; ?>" id="userStatus"><?php echo $initial_status_text; ?></div>
                            </div>
                        </div>
                        
                        <div class="chat-messages" id="chatMessages">
                            <?php foreach ($messages as $message): ?>
                                <div class="message-container <?php echo $message['sender_id'] == $current_user_id ? 'sent' : 'received'; ?> <?php echo isset($message['deleted']) && $message['deleted'] == 1 ? 'deleted' : ''; ?>" data-message-id="<?php echo $message['id']; ?>">
                                    <?php if ($message['sender_id'] == $current_user_id): ?>
                                    <div class="message-menu">
                                        <i class="bi bi-three-dots-vertical"></i>
                                        <div class="message-dropdown">
                                            <?php if (empty($message['image_path'])): ?>
                                            <div class="dropdown-item edit-message" data-message-id="<?php echo $message['id']; ?>">
                                                <i class="bi bi-pencil"></i>
                                                Edit
                                            </div>
                                            <?php endif; ?>
                                            <div class="dropdown-item delete delete-message" data-message-id="<?php echo $message['id']; ?>">
                                                <i class="bi bi-trash"></i>
                                                Delete
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    <div class="message-content">
                                    <div class="message">
                                        <?php if (isset($message['deleted']) && $message['deleted'] == 1): ?>
                                            <i class="bi bi-trash-fill" style="margin-right: 5px; font-size: 12px;"></i> This message was deleted
                                        <?php else: ?>
                                            <?php
                                            $imgPaths = null;
                                            if (!empty($message['image_path'])) {
                                                $decoded = json_decode($message['image_path'], true);
                                                $imgPaths = is_array($decoded) ? $decoded : [$message['image_path']];
                                            }
                                            ?>
                                            <?php if ($imgPaths): ?>
                                                <?php if (count($imgPaths) === 1): ?>
                                                    <img src="<?php echo htmlspecialchars($imgPaths[0]); ?>" class="message-image" alt="Sent image">
                                                <?php else: ?>
                                                    <div class="message-image-grid grid-<?php echo min(count($imgPaths), 5); ?>">
                                                        <?php foreach (array_slice($imgPaths, 0, 5) as $imgPath): ?>
                                                            <div class="message-image-cell">
                                                                <img src="<?php echo htmlspecialchars($imgPath); ?>" alt="Sent image">
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            <?php if (!empty($message['content'])): ?>
                                                <?php echo htmlspecialchars($message['content']); ?>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="timestamp">
                                        <?php echo formatMessageTime($message['created_at']); ?>
                                        <?php if (!empty($message['updated_at']) && (!isset($message['deleted']) || $message['deleted'] != 1)): ?>
                                        <span class="edited">(edited)</span>
                                        <?php endif; ?>
                                    </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="chat-input-container">
                            <label for="messageImageInput" class="image-upload-btn" title="Send image">
                                <i class="bi bi-image"></i>
                            </label>
                            <input type="file" id="messageImageInput" accept="image/*" multiple style="display:none;">
                            <div class="image-preview-bar" id="imagePreviewBar" style="display:none;"></div>
                            <input type="text" class="chat-input" id="messageInput" placeholder="Type a message...">
                            <button class="send-button" id="sendButton">
                                <i class="bi bi-send-fill"></i>
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="no-chat-selected">
                            <i class="bi bi-chat-square-text"></i>
                            <h3>Select a conversation</h3>
                            <p>Choose a user from the list to start chatting</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- RIGHT SECTION - USER SEARCH -->
            <div class="right-search" id="rightSearchPanel">
                <div class="search-header">
                    <h2>Messages</h2>
                    <form action="message.php" method="GET" class="search-bar mobile-search-bar">
                        <i class="bi bi-search"></i>
                        <input type="text" name="search" id="searchInput" placeholder="Search users..." autocomplete="off">
                    </form>
                </div>
                <!-- Search results container positioned to overlap the conversations below -->
                <div id="searchResults"></div>
                <div class="user-list" id="userList">
                    <?php if (!empty($search_results)): ?>
                        <h4>Search Results</h4>
                        <?php foreach ($search_results as $user): ?>
                            <a href="message.php?user_id=<?php echo $user['id']; ?>" class="user-item <?php echo $selected_user_id == $user['id'] ? 'active' : ''; ?>">
                                <?php if (!empty($user['profile_picture'])): ?>
                                    <img src="<?php echo $user['profile_picture']; ?>" class="user-avatar">
                                <?php else: ?>
                                    <div class="user-avatar" style="display:flex;align-items:center;justify-content:center;flex-shrink:0;"><?php echo getInitialsHtml($user['first_name'], $user['last_name'], 40); ?></div>
                                <?php endif; ?>
                                <div class="user-details">
                                    <div class="user-name"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></div>
                                    <div class="user-username">@<?php echo htmlspecialchars($user['username']); ?></div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php elseif (!empty($conversations)): ?>
                        <h4>Recent Conversations</h4>
                        <?php foreach ($conversations as $conversation): ?>
                            <a href="message.php?user_id=<?php echo $conversation['id']; ?>" class="user-item <?php echo $selected_user_id == $conversation['id'] ? 'active' : ''; ?>">
                                <?php if (!empty($conversation['profile_picture'])): ?>
                                    <img src="<?php echo $conversation['profile_picture']; ?>" class="user-avatar">
                                <?php else: ?>
                                    <div class="user-avatar"><?php echo getInitialsHtml($conversation['first_name'], $conversation['last_name'], 40); ?></div>
                                <?php endif; ?>
                                <div class="user-details">
                                    <div class="user-name"><?php echo htmlspecialchars($conversation['first_name'] . ' ' . $conversation['last_name']); ?></div>
                                    <div class="user-username">@<?php echo htmlspecialchars($conversation['username']); ?></div>
                                </div>
                                <?php if ($conversation['unread_count'] > 0): ?>
                                    <span class="unread-badge"><?php echo $conversation['unread_count']; ?></span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="no-conversations" id="defaultMessage">
                            <p>No conversations yet</p>
                            <p>Search for users to start chatting</p>
                        </div>
                    <?php endif; ?>
                </div>
                <button class="close-panel-btn" id="closePanelBtn" style="display:none;">&times;</button>
            </div>
            <!-- Floating button for mobile to open the right panel -->
            <button class="open-panel-btn" id="openPanelBtn" title="Show Messages">
                <i class="bi bi-chat-dots"></i>
            </button>
        </div>
    </main>

    <script>
        // Message handling functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Remove unread badge when a conversation is clicked
            document.querySelectorAll('.user-item').forEach(function(item) {
                item.addEventListener('click', function() {
                    var badge = this.querySelector('.unread-badge');
                    if (badge) badge.remove();
                });
            });

            const sendButton = document.getElementById('sendButton');
            const messageInput = document.getElementById('messageInput');
            const chatMessages = document.getElementById('chatMessages');
            const userStatus = document.getElementById('userStatus');
            
            // Handle mobile back button
            const chatHeader = document.querySelector('.chat-header');
            if (chatHeader) {
                chatHeader.addEventListener('click', function(e) {
                    if (e.target === chatHeader || e.target.parentElement === chatHeader) {
                        window.history.back();
                    }
                });
            }

            // Handle window resize
            function handleResize() {
                const container = document.querySelector('.container');
                if (window.innerWidth <= 768) {
                    container.style.gridTemplateColumns = '1fr';
                } else {
                    container.style.gridTemplateColumns = '250px 1fr 300px';
                }
            }

            window.addEventListener('resize', handleResize);
            handleResize(); // Initial call
            
            // Track the last message ID we've received
            let lastReceivedMessageId = <?php echo !empty($messages) ? end($messages)['id'] : 0; ?>;
            
            // Auto-scroll to bottom of messages on load
            function scrollToBottom() {
                if (chatMessages) {
                    chatMessages.scrollTop = chatMessages.scrollHeight;
                }
            }
            
            // Initial scroll to bottom
            scrollToBottom();
            // Also scroll after images load to account for their height
            window.addEventListener('load', function() {
                setTimeout(scrollToBottom, 100);
            });
            
            // Get selected user's status
            function getSelectedUserStatus() {
                <?php if ($selected_user_id): ?>
                fetch('get_user_status.php?user_id=<?php echo $selected_user_id; ?>', {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        userStatus.textContent = data.data.status_text;
                        userStatus.className = 'chat-status ' + data.data.status_class;
                    }
                })
                .catch(error => {
                    console.error('Error getting user status:', error);
                });
                <?php endif; ?>
            }
            
            // Activity tracking is now handled globally in navbar.php
            
            // Check selected user status periodically (every 5 seconds)
            <?php if ($selected_user_id): ?>
            getSelectedUserStatus();
            setInterval(getSelectedUserStatus, 5000);
            <?php endif; ?>
            
            // Small non-blocking toast for chat actions (replaces alert()).
            // Defined at page scope so both the send flow and the
            // document-level edit/delete handlers can use it.
            function showChatToast(msg) {
                let toast = document.getElementById('chatActionToast');
                if (!toast) {
                    toast = document.createElement('div');
                    toast.id = 'chatActionToast';
                    toast.style.position = 'fixed';
                    toast.style.bottom = '24px';
                    toast.style.left = '50%';
                    toast.style.transform = 'translateX(-50%)';
                    toast.style.background = 'rgba(30, 60, 66, 0.94)';
                    toast.style.color = '#fff';
                    toast.style.padding = '10px 18px';
                    toast.style.borderRadius = '8px';
                    toast.style.fontSize = '13px';
                    toast.style.zIndex = '9999';
                    toast.style.boxShadow = '0 4px 16px rgba(0,0,0,0.25)';
                    toast.style.display = 'none';
                    document.body.appendChild(toast);
                }
                toast.textContent = msg;
                toast.style.display = 'block';
                clearTimeout(toast._hideTimer);
                toast._hideTimer = setTimeout(function() { toast.style.display = 'none'; }, 3200);
            }

            // True while the bubble still carries an optimistic temp id,
            // i.e. the send request has not answered yet.
            function isMessagePending(container, messageId) {
                if (container && container.hasAttribute && container.hasAttribute('data-pending')) return true;
                return !!messageId && String(messageId).indexOf('temp-') === 0;
            }

            if (sendButton && messageInput && chatMessages) {
                const messageImageInput = document.getElementById('messageImageInput');
                const imagePreviewBar = document.getElementById('imagePreviewBar');
                let selectedImageFiles = [];

                function updateImagePreview() {
                    imagePreviewBar.innerHTML = '';
                    if (selectedImageFiles.length > 0) {
                        imagePreviewBar.style.display = 'flex';
                        selectedImageFiles.forEach(function(file, index) {
                            const thumbContainer = document.createElement('div');
                            thumbContainer.style.position = 'relative';
                            thumbContainer.style.display = 'inline-flex';

                            const img = document.createElement('img');
                            img.src = URL.createObjectURL(file);
                            img.style.width = '36px';
                            img.style.height = '36px';
                            img.style.borderRadius = '6px';
                            img.style.objectFit = 'cover';

                            const removeBtn = document.createElement('button');
                            removeBtn.type = 'button';
                            removeBtn.className = 'remove-image-btn';
                            removeBtn.innerHTML = '&times;';
                            removeBtn.style.position = 'absolute';
                            removeBtn.style.top = '-4px';
                            removeBtn.style.right = '-4px';
                            removeBtn.style.background = '#e74c3c';
                            removeBtn.style.color = 'white';
                            removeBtn.style.borderRadius = '50%';
                            removeBtn.style.width = '18px';
                            removeBtn.style.height = '18px';
                            removeBtn.style.fontSize = '14px';
                            removeBtn.style.padding = '0';
                            removeBtn.style.lineHeight = '1';
                            removeBtn.style.border = 'none';
                            removeBtn.style.cursor = 'pointer';
                            removeBtn.addEventListener('click', function() {
                                selectedImageFiles.splice(index, 1);
                                updateImagePreview();
                            });

                            thumbContainer.appendChild(img);
                            thumbContainer.appendChild(removeBtn);
                            imagePreviewBar.appendChild(thumbContainer);
                        });
                    } else {
                        imagePreviewBar.style.display = 'none';
                    }
                }

                if (messageImageInput) {
                    messageImageInput.addEventListener('change', function() {
                        var newFiles = Array.from(this.files);
                        var remaining = 5 - selectedImageFiles.length;
                        var filesToAdd = newFiles.slice(0, remaining);
                        selectedImageFiles = selectedImageFiles.concat(filesToAdd);
                        updateImagePreview();
                        this.value = '';
                    });
                }

                sendButton.addEventListener('click', sendMessage);
                messageInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        sendMessage();
                    }
                });
                
                function sendMessage() {
                    const messageText = messageInput.value.trim();
                    const receiverId = <?php echo $selected_user_id ?: 'null'; ?>;
                    
                    if ((!messageText && selectedImageFiles.length === 0) || !receiverId) return;

                    const formData = new FormData();
                    if (messageText) formData.append('message', messageText);
                    selectedImageFiles.forEach(function(file) {
                        formData.append('message_images[]', file);
                    });

                    // Create the message element immediately for better UX
                    const messageContainer = document.createElement('div');
                    messageContainer.classList.add('message-container', 'sent');
                    messageContainer.setAttribute('data-message-id', 'temp-' + Date.now());
                    messageContainer.setAttribute('data-pending', 'true');
                    
                    const messageMenu = document.createElement('div');
                    messageMenu.classList.add('message-menu');
                    if (selectedImageFiles.length === 0) {
                        messageMenu.innerHTML = `
                            <i class="bi bi-three-dots-vertical"></i>
                            <div class="message-dropdown">
                                <div class="dropdown-item edit-message">
                                    <i class="bi bi-pencil"></i>
                                    Edit
                                </div>
                                <div class="dropdown-item delete delete-message">
                                    <i class="bi bi-trash"></i>
                                    Delete
                                </div>
                            </div>
                        `;
                    } else {
                        messageMenu.innerHTML = `
                            <i class="bi bi-three-dots-vertical"></i>
                            <div class="message-dropdown">
                                <div class="dropdown-item delete delete-message">
                                    <i class="bi bi-trash"></i>
                                    Delete
                                </div>
                            </div>
                        `;
                    }
                    messageContainer.appendChild(messageMenu);
                    
                    const messageDiv = document.createElement('div');
                    messageDiv.classList.add('message');

                    if (selectedImageFiles.length === 1) {
                        const img = document.createElement('img');
                        img.src = URL.createObjectURL(selectedImageFiles[0]);
                        img.classList.add('message-image');
                        img.alt = 'Sent image';
                        messageDiv.appendChild(img);
                    } else if (selectedImageFiles.length > 1) {
                        const grid = document.createElement('div');
                        grid.classList.add('message-image-grid', 'grid-' + Math.min(selectedImageFiles.length, 5));
                        selectedImageFiles.forEach(function(file) {
                            const cell = document.createElement('div');
                            cell.classList.add('message-image-cell');
                            const img = document.createElement('img');
                            img.src = URL.createObjectURL(file);
                            img.alt = 'Sent image';
                            cell.appendChild(img);
                            grid.appendChild(cell);
                        });
                        messageDiv.appendChild(grid);
                    }
                    if (messageText) {
                        const textNode = document.createTextNode(messageText);
                        messageDiv.appendChild(textNode);
                    }
                    
                    const timestampDiv = document.createElement('div');
                    timestampDiv.classList.add('timestamp');
                    timestampDiv.textContent = getCurrentTime();
                    
                    const messageContent = document.createElement('div');
                    messageContent.classList.add('message-content');
                    messageContent.appendChild(messageDiv);
                    messageContent.appendChild(timestampDiv);
                    
                    messageContainer.appendChild(messageContent);
                    
                    chatMessages.appendChild(messageContainer);
                    messageInput.value = '';
                    selectedImageFiles = [];
                    updateImagePreview();
                    scrollToBottom();
                    
                    // Send the message to the server
                    formData.append('X-Requested-With', 'XMLHttpRequest');
                    fetch('message.php?user_id=' + receiverId, {
                        method: 'POST',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            if (data.message && data.message.id) {
                                lastReceivedMessageId = Math.max(lastReceivedMessageId, data.message.id);
                                // Swap the optimistic temp ID for the real DB id so
                                // edit/delete work immediately without a page refresh.
                                messageContainer.setAttribute('data-message-id', data.message.id);
                                messageContainer.querySelectorAll('.edit-message, .delete-message').forEach(function(item) {
                                    item.setAttribute('data-message-id', data.message.id);
                                });
                                messageContainer.removeAttribute('data-pending');
                            }
                        } else {
                            console.error('Failed to send message');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                    });
                }
                
                // Function to check for new messages periodically
                function checkForNewMessages() {
                    if (!<?php echo $selected_user_id ? 'true' : 'false'; ?>) return;
                    
                    fetch(`get_messages.php?user_id=<?php echo $selected_user_id; ?>&last_id=${lastReceivedMessageId}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.messages && data.messages.length > 0) {
                            let shouldScroll = chatMessages.scrollTop + chatMessages.clientHeight >= chatMessages.scrollHeight - 10;
                            
                            data.messages.forEach(message => {
                                // Only add messages that are not sent by current user (received messages)
                                if (message.sender_id != <?php echo $current_user_id; ?>) {
                                    const messageContainer = document.createElement('div');
                                    messageContainer.classList.add('message-container', 'received');
                                    
                                    const messageDiv = document.createElement('div');
                                    messageDiv.classList.add('message');
                                    if (message.image_path) {
                                        var imagePaths;
                                        try {
                                            imagePaths = JSON.parse(message.image_path);
                                            if (!Array.isArray(imagePaths)) imagePaths = [message.image_path];
                                        } catch (e) {
                                            imagePaths = [message.image_path];
                                        }
                                        if (imagePaths.length === 1) {
                                            var img = document.createElement('img');
                                            img.src = imagePaths[0];
                                            img.classList.add('message-image');
                                            img.alt = 'Sent image';
                                            messageDiv.appendChild(img);
                                        } else {
                                            var grid = document.createElement('div');
                                            grid.classList.add('message-image-grid', 'grid-' + Math.min(imagePaths.length, 5));
                                            imagePaths.forEach(function(path) {
                                                var cell = document.createElement('div');
                                                cell.classList.add('message-image-cell');
                                                var gImg = document.createElement('img');
                                                gImg.src = path;
                                                gImg.alt = 'Sent image';
                                                cell.appendChild(gImg);
                                                grid.appendChild(cell);
                                            });
                                            messageDiv.appendChild(grid);
                                        }
                                    }
                                    if (message.content) {
                                        const textNode = document.createTextNode(message.content);
                                        messageDiv.appendChild(textNode);
                                    }
                                    
                                    const timestampDiv = document.createElement('div');
                                    timestampDiv.classList.add('timestamp');
                                    timestampDiv.textContent = message.time;
                                    
                                    const messageContent = document.createElement('div');
                                    messageContent.classList.add('message-content');
                                    messageContent.appendChild(messageDiv);
                                    messageContent.appendChild(timestampDiv);
                                    
                                    messageContainer.appendChild(messageContent);
                                    
                                    chatMessages.appendChild(messageContainer);
                                    
                                    // Update the last received message ID
                                    lastReceivedMessageId = Math.max(lastReceivedMessageId, message.id);
                                }
                            });
                            
                            if (shouldScroll) {
                                scrollToBottom();
                            }
                        }
                    })
                    .catch(error => {
                        console.error('Error fetching messages:', error);
                    });
                    
                    // Check again after a delay
                    setTimeout(checkForNewMessages, 3000);
                }
                
                // Start checking for new messages
                setTimeout(checkForNewMessages, 1000);
            }
            
            // Enhanced search functionality
            const searchInput = document.getElementById('searchInput');
            const searchResults = document.getElementById('searchResults');
            const userList = document.getElementById('userList');
            const defaultMessage = document.getElementById('defaultMessage');
            
            if (searchInput) {
                // Prevent form submission - use AJAX only
                searchInput.closest('form').addEventListener('submit', function(e) {
                    e.preventDefault();
                });
                
                // Position search results overlay below the search header
                function positionSearchResults() {
                    const searchHeader = document.querySelector('.right-search .search-header');
                    if (searchHeader && searchResults) {
                        searchResults.style.top = (searchHeader.offsetHeight + 5) + 'px';
                    }
                }
                
                // Add real-time search functionality
                let searchTimeout = null;
                searchInput.addEventListener('input', function() {
                    const query = this.value.trim();
                    
                    // Clear any existing timeout
                    if (searchTimeout) {
                        clearTimeout(searchTimeout);
                    }
                    
                    if (query.length > 0) {
                        // Add a small delay to avoid too many requests
                        searchTimeout = setTimeout(() => {
                            // Show loading state
                            searchResults.innerHTML = '<div class="no-conversations"><p>Searching...</p></div>';
                            searchResults.style.display = 'block';
                            positionSearchResults();
                            
                            // Fetch users based on the input
                            fetch('message.php?search=' + encodeURIComponent(query), {
                                headers: {
                                    'X-Requested-With': 'XMLHttpRequest'
                                }
                            })
                            .then(response => response.text())
                            .then(html => {
                                console.log('Search response for "' + query + '":', html.substring(0, 200));
                                if (html.trim() === '') {
                                    searchResults.innerHTML = '<div class="no-conversations"><p>No users found</p></div>';
                                } else {
                                    searchResults.innerHTML = html;
                                }
                            })
                            .catch(error => {
                                console.error('Error fetching search results:', error);
                                searchResults.innerHTML = '<div class="no-conversations"><p>Error searching users</p></div>';
                            });
                        }, 300); // 300ms delay for typing
                    } else {
                        // Clear search results when input is empty
                        searchResults.innerHTML = '';
                        searchResults.style.display = 'none';
                    }
                });
                
                // Handle clicks outside the search results to close dropdown
                document.addEventListener('click', function(e) {
                    if (searchResults && !searchInput.contains(e.target) && !searchResults.contains(e.target)) {
                        searchResults.style.display = 'none';
                    }
                });
            }

            // Handle message menu clicks
            document.addEventListener('click', function(e) {
                // Close all dropdowns when clicking outside
                if (!e.target.closest('.message-menu')) {
                    document.querySelectorAll('.message-dropdown').forEach(dropdown => {
                        dropdown.classList.remove('show');
                    });
                }

                // Toggle dropdown when clicking the menu icon
                if (e.target.closest('.message-menu')) {
                    const dropdown = e.target.closest('.message-menu').querySelector('.message-dropdown');
                    const allDropdowns = document.querySelectorAll('.message-dropdown');
                    
                    // Close all other dropdowns
                    allDropdowns.forEach(d => {
                        if (d !== dropdown) {
                            d.classList.remove('show');
                        }
                    });
                    
                    // Toggle current dropdown
                    dropdown.classList.toggle('show');
                }

                // Handle edit message
                if (e.target.closest('.edit-message')) {
                    e.preventDefault();
                    e.stopPropagation();
                    const messageContainer = e.target.closest('.message-container');
                    const messageContent = messageContainer.querySelector('.message');
                    
                    // Check if this message is marked as deleted
                    if (messageContainer.classList.contains('deleted')) {
                        console.log('Cannot edit a deleted message');
                        return;
                    }
                    
                    // Get the inner HTML content instead of just textContent
                    // This preserves the exact content as displayed
                    let messageHTML = messageContent.innerHTML;
                    
                    // If the message has HTML elements, we need to extract just the text
                    // Create a temporary div to access the text without HTML tags
                    const tempDiv = document.createElement('div');
                    tempDiv.innerHTML = messageHTML;
                    const currentText = tempDiv.textContent.trim();
                    
                    // Skip editing if the message is empty or deleted
                    if (!currentText || currentText === 'This message was deleted') {
                        console.log('Empty or deleted message, cannot edit');
                        return;
                    }
                    
                    const messageId = e.target.closest('.edit-message').dataset.messageId || messageContainer.dataset.messageId;
                    
                    if (!messageId) {
                        console.error('Message ID not found', messageContainer);
                        return;
                    }

                    if (isMessagePending(messageContainer, messageId)) {
                        showChatToast('Message is still sending. Please wait a moment and try again.');
                        return;
                    }
                    
                    console.log('Editing message with ID:', messageId, 'Current text:', currentText);
                    
                    // Create edit input - using textarea instead of input to better handle spaces and newlines
                    const editInput = document.createElement('textarea');
                    editInput.value = currentText; // Set the current text as the input value
                    editInput.classList.add('chat-input');
                    // Match the sent bubble's exact size so the whole message stays readable
                    const bubbleRect = messageContent.getBoundingClientRect();
                    const bubbleWidth = Math.max(Math.round(bubbleRect.width), 120);
                    const bubbleHeight = Math.round(bubbleRect.height);

                    editInput.style.width = bubbleWidth + 'px';
                    editInput.style.height = bubbleHeight + 'px';
                    editInput.style.minHeight = '40px';
                    editInput.style.maxHeight = 'none';
                    editInput.style.overflow = 'hidden';
                    editInput.style.resize = 'none';
                    editInput.style.flex = '0 0 auto';
                    editInput.style.marginBottom = '10px';
                    editInput.style.padding = '12px 16px';
                    editInput.style.boxSizing = 'border-box';
                    editInput.style.border = '1px solid var(--color-primary)';
                    editInput.style.borderRadius = '18px';
                    editInput.style.lineHeight = '1.4';
                    editInput.style.fontSize = '15px';
                    
                    // Create an invisible clone div for perfect height calculation
                    const clone = document.createElement('div');
                    clone.style.visibility = 'hidden';
                    clone.style.position = 'absolute';
                    clone.style.top = '-9999px';
                    clone.style.left = '-9999px';
                    clone.style.width = bubbleWidth + 'px';
                    clone.style.padding = '12px 16px';
                    clone.style.boxSizing = 'border-box';
                    clone.style.border = '1px solid transparent';
                    clone.style.borderRadius = '18px';
                    clone.style.lineHeight = '1.4';
                    clone.style.fontSize = '15px';
                    clone.style.fontFamily = window.getComputedStyle(editInput).fontFamily;
                    clone.style.whiteSpace = 'pre-wrap';
                    clone.style.wordWrap = 'break-word';
                    clone.style.overflowWrap = 'anywhere';
                    clone.style.wordBreak = 'break-word';
                    document.body.appendChild(clone);
                    
                    // Replace message content with textarea
                    messageContent.style.display = 'none';
                    messageContent.parentNode.insertBefore(editInput, messageContent);
                    
                    // Function to auto-resize the textarea using clone div
                    const autoResizeTextarea = function() {
                        // Update clone with current textarea content
                        clone.textContent = editInput.value;
                        
                        // Calculate exact height needed
                        let newHeight = clone.offsetHeight;
                        
                        // Ensure minimum height
                        newHeight = Math.max(newHeight, 40);
                        
                        // Never clamp: keep the full message height so nothing needs scrolling
                        editInput.style.height = newHeight + 'px';
                        editInput.style.overflow = 'hidden';
                        
                        console.log('Resized textarea to height:', newHeight, 'px');
                    };
                    
                    // Auto-resize immediately after creating and appending
                    requestAnimationFrame(() => {
                        // Sync the measuring clone to the real rendered width
                        // (it was created while the input was still detached).
                        clone.style.width = editInput.offsetWidth + 'px';
                        autoResizeTextarea();
                        // Focus and select all text after sizing is done
                        editInput.focus();
                        editInput.select();
                    });
                    
                    // Auto-resize as user types
                    editInput.addEventListener('input', autoResizeTextarea);
                    
                    // Clean up clone element when done
                    const cleanup = () => {
                        if (clone && clone.parentNode) {
                            clone.parentNode.removeChild(clone);
                        }
                    };
                    
                    // Close dropdown menu
                    const dropdown = messageContainer.querySelector('.message-dropdown');
                    if (dropdown) {
                        dropdown.classList.remove('show');
                    }
                    
                    // Handle edit submission
                    const handleSubmit = function() {
                        cleanup(); // Clean up clone
                        const newText = editInput.value.trim();
                        if (newText && newText !== currentText) {
                            console.log('Updating message:', messageId, 'New text:', newText);
                            
                            // Make AJAX call to update the message
                            const formData = new FormData();
                            formData.append('action', 'edit');
                            formData.append('message_id', messageId);
                            formData.append('content', newText);

                            fetch('update_message.php', {
                                method: 'POST',
                                body: formData
                            })
                            .then(response => {
                                console.log('Response status:', response.status);
                                return response.json();
                            })
                            .then(data => {
                                console.log('Edit response:', data);
                                if (data.success) {
                                    messageContent.textContent = data.data.content;
                                    // Update timestamp to show edited status
                                    const timestamp = messageContainer.querySelector('.timestamp');
                                    if (!timestamp.querySelector('.edited')) {
                                        timestamp.innerHTML += ' <span class="edited">(edited)</span>';
                                    }
                                } else if (data.message === 'Invalid message ID') {
                                    // Just apply the edit locally if the ID is invalid (likely a new message)
                                    console.log('Message has a temporary ID, applying edit locally');
                                    messageContent.textContent = newText;
                                } else {
                                    console.error('Edit failed:', data.message);
                                }
                            })
                            .catch(error => {
                                console.error('Error updating message:', error);
                                // Apply edit locally on error
                                messageContent.textContent = newText;
                            });
                        } else {
                            console.log('No changes to message or empty message');
                        }
                        editInput.remove();
                        messageContent.style.display = 'block';
                    };
                    
                    // Handle keypress events with cleanup
                    editInput.addEventListener('keypress', function(e) {
                        if (e.key === 'Enter' && !e.shiftKey) {
                            e.preventDefault(); // Prevent newline when Enter is pressed without Shift
                            handleSubmit();
                        }
                    });
                    
                    // Handle edit cancellation with cleanup
                    editInput.addEventListener('blur', function() {
                        // Add a small delay to allow click handlers to fire first if user is clicking a button
                        setTimeout(() => {
                            cleanup(); // Clean up clone
                            editInput.remove();
                            messageContent.style.display = 'block';
                        }, 100);
                    });
                }

                // Handle delete message
                if (e.target.closest('.delete-message')) {
                    e.preventDefault();
                    e.stopPropagation();
                    const messageContainer = e.target.closest('.message-container');
                    const messageId = e.target.closest('.delete-message').dataset.messageId || messageContainer.dataset.messageId;
                    
                    if (!messageId) {
                        console.error('Message ID not found', messageContainer);
                        return;
                    }

                    if (isMessagePending(messageContainer, messageId)) {
                        showChatToast('Message is still sending. Please wait a moment and try again.');
                        return;
                    }
                    const messageContent = messageContainer.querySelector('.message').textContent;
                    
                    console.log('Preparing to delete message with ID:', messageId);
                    
                    // Show custom delete confirmation modal instead of browser alert
                    const deleteModal = document.getElementById('deleteModal');
                    const deleteMessageContent = document.getElementById('deleteMessageContent');
                    const confirmDeleteBtn = document.getElementById('confirmDelete');
                    const cancelDeleteBtn = document.getElementById('cancelDelete');
                    const closeDeleteModalBtn = document.getElementById('closeDeleteModal');
                    
                    // Display the message content in the modal
                    deleteMessageContent.textContent = messageContent;
                    
                    // Show the modal
                    deleteModal.classList.add('show');
                    
                    // Set up one-time event handlers
                    const handleConfirmDelete = function() {
                        // Make AJAX call to delete the message
                        const formData = new FormData();
                        formData.append('action', 'delete');
                        formData.append('message_id', messageId);

                        fetch('update_message.php', {
                            method: 'POST',
                            body: formData
                        })
                        .then(response => {
                            console.log('Response status:', response.status);
                            return response.json();
                        })
                        .then(data => {
                            console.log('Delete response:', data);
                            if (data.success) {
                                // Instead of removing the message, mark it as deleted
                                messageContainer.classList.add('deleted');
                                const messageContent = messageContainer.querySelector('.message');
                                messageContent.innerHTML = '<i class="bi bi-trash-fill" style="margin-right: 5px; font-size: 12px;"></i> This message was deleted';
                                
                                // Hide the menu button for deleted messages
                                const messageMenu = messageContainer.querySelector('.message-menu');
                                if (messageMenu) {
                                    messageMenu.style.display = 'none';
                                }
                                
                                // Hide the (edited) text if present
                                const editedSpan = messageContainer.querySelector('.edited');
                                if (editedSpan) {
                                    editedSpan.style.display = 'none';
                                }
                            } else {
                                showChatToast(data.message || 'Failed to delete message');
                            }
                        })
                        .catch(error => {
                            console.error('Error deleting message:', error);
                            showChatToast('Failed to delete message: ' + error.message);
                        });
                        
                        // Hide the modal
                        deleteModal.classList.remove('show');
                        
                        // Remove event listeners
                        confirmDeleteBtn.removeEventListener('click', handleConfirmDelete);
                        cancelDeleteBtn.removeEventListener('click', handleCancelDelete);
                        closeDeleteModalBtn.removeEventListener('click', handleCancelDelete);
                    };
                    
                    const handleCancelDelete = function() {
                        // Hide the modal
                        deleteModal.classList.remove('show');
                        
                        // Remove event listeners
                        confirmDeleteBtn.removeEventListener('click', handleConfirmDelete);
                        cancelDeleteBtn.removeEventListener('click', handleCancelDelete);
                        closeDeleteModalBtn.removeEventListener('click', handleCancelDelete);
                    };
                    
                    // Add event listeners
                    confirmDeleteBtn.addEventListener('click', handleConfirmDelete);
                    cancelDeleteBtn.addEventListener('click', handleCancelDelete);
                    closeDeleteModalBtn.addEventListener('click', handleCancelDelete);
                }
            });

            // Mobile panel open/close logic
            const openPanelBtn = document.getElementById('openPanelBtn');
            const rightSearchPanel = document.getElementById('rightSearchPanel');
            const closePanelBtn = document.getElementById('closePanelBtn');
            let panelAnimating = false;
            let debounceTimeout = null;
            function openPanel() {
                if (panelAnimating) return;
                panelAnimating = true;
                rightSearchPanel.classList.add('open');
                closePanelBtn.style.display = 'block';
                // Allow rapid toggling after animation
                clearTimeout(debounceTimeout);
                debounceTimeout = setTimeout(() => { panelAnimating = false; }, 300);
            }
            function closePanel() {
                if (panelAnimating) return;
                panelAnimating = true;
                rightSearchPanel.classList.remove('open');
                closePanelBtn.style.display = 'none';
                // Allow rapid toggling after animation
                clearTimeout(debounceTimeout);
                debounceTimeout = setTimeout(() => { panelAnimating = false; }, 300);
            }
            if (openPanelBtn && rightSearchPanel && closePanelBtn) {
                openPanelBtn.addEventListener('click', openPanel);
                closePanelBtn.addEventListener('click', closePanel);
                // Optional: close panel when clicking outside
                document.addEventListener('click', function(e) {
                    if (window.innerWidth <= 900 && rightSearchPanel.classList.contains('open')) {
                        if (!rightSearchPanel.contains(e.target) && e.target !== openPanelBtn) {
                            closePanel();
                        }
                    }
                });
            }

            function getCurrentTime() {
                const now = new Date();
                const options = { timeZone: 'Asia/Manila', hour: 'numeric', minute: '2-digit', hour12: true };
                return 'Today at ' + now.toLocaleString('en-US', options);
            }

            // Lightbox functionality
            const lightbox = document.getElementById('messageLightbox');
            const lightboxImage = document.getElementById('lightboxImage');
            const lightboxCloseBtn = document.getElementById('lightboxClose');
            const lightboxPrevBtn = document.getElementById('lightboxPrev');
            const lightboxNextBtn = document.getElementById('lightboxNext');
            const lightboxCounter = document.getElementById('lightboxCounter');
            let lightboxImages = [];
            let lightboxIndex = 0;

            function openLightbox(images, index) {
                lightboxImages = images;
                lightboxIndex = index;
                updateLightbox();
                lightbox.classList.add('active');
                document.body.style.overflow = 'hidden';
            }

            function closeLightbox() {
                lightbox.classList.remove('active');
                document.body.style.overflow = '';
            }

            function updateLightbox() {
                lightboxImage.src = lightboxImages[lightboxIndex];
                if (lightboxImages.length > 1) {
                    lightboxCounter.textContent = (lightboxIndex + 1) + '/' + lightboxImages.length;
                    lightboxPrevBtn.style.display = 'flex';
                    lightboxNextBtn.style.display = 'flex';
                } else {
                    lightboxCounter.textContent = '';
                    lightboxPrevBtn.style.display = 'none';
                    lightboxNextBtn.style.display = 'none';
                }
            }

            function lightboxNavigate(direction) {
                lightboxIndex = (lightboxIndex + direction + lightboxImages.length) % lightboxImages.length;
                updateLightbox();
            }

            if (lightboxCloseBtn) lightboxCloseBtn.addEventListener('click', closeLightbox);
            if (lightboxPrevBtn) lightboxPrevBtn.addEventListener('click', function() { lightboxNavigate(-1); });
            if (lightboxNextBtn) lightboxNextBtn.addEventListener('click', function() { lightboxNavigate(1); });

            if (lightbox) {
                lightbox.addEventListener('click', function(e) {
                    if (e.target === lightbox) closeLightbox();
                });
            }

            document.addEventListener('keydown', function(e) {
                if (!lightbox || !lightbox.classList.contains('active')) return;
                if (e.key === 'Escape') closeLightbox();
                if (e.key === 'ArrowLeft') lightboxNavigate(-1);
                if (e.key === 'ArrowRight') lightboxNavigate(1);
            });

            // Event delegation for image clicks
            chatMessages.addEventListener('click', function(e) {
                var img = null;
                if (e.target.classList.contains('message-image')) {
                    img = e.target;
                } else if (e.target.closest('.message-image-cell')) {
                    img = e.target.closest('.message-image-cell').querySelector('img');
                }
                if (!img) return;

                var images = [];
                var idx = 0;

                var grid = img.closest('.message-image-grid');
                if (grid) {
                    var cells = grid.querySelectorAll('.message-image-cell img');
                    images = Array.from(cells).map(function(c) { return c.src; });
                    idx = Array.from(cells).indexOf(img);
                } else {
                    images = [img.src];
                    idx = 0;
                }

                openLightbox(images, idx);
            });
        });


    </script>

    <!-- Delete Confirmation Modal -->
    <div class="delete-modal" id="deleteModal">
        <div class="delete-modal-content">
            <div class="delete-modal-header">
                <span class="delete-modal-icon"><i class="bi bi-trash"></i></span>
                <div class="delete-modal-header-text">
                    <h3>Delete message</h3>
                    <p class="delete-modal-subtitle">This action cannot be undone</p>
                </div>
                <button class="delete-modal-close" id="closeDeleteModal" aria-label="Close"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="delete-modal-body">
                <p class="delete-modal-question">Are you sure you want to delete this message?</p>
                <div class="delete-modal-message-wrap">
                    <span class="delete-modal-message-label">Message</span>
                    <div class="delete-modal-message" id="deleteMessageContent"></div>
                </div>
            </div>
            <div class="delete-modal-footer">
                <button class="delete-modal-cancel" id="cancelDelete">Cancel</button>
                <button class="delete-modal-confirm" id="confirmDelete"><i class="bi bi-trash"></i> Delete</button>
            </div>
        </div>
    </div>

    <!-- Message Lightbox -->
    <div class="message-lightbox" id="messageLightbox">
        <button class="lightbox-close" id="lightboxClose">&times;</button>
        <button class="lightbox-nav lightbox-prev" id="lightboxPrev">&#10094;</button>
        <button class="lightbox-nav lightbox-next" id="lightboxNext">&#10095;</button>
        <img id="lightboxImage" src="" alt="Full size image">
        <div class="lightbox-counter" id="lightboxCounter"></div>
    </div>
</body>
</html>