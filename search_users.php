<?php

require_once 'db_connection.php';
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: process_login.php");
    exit();
}

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



// Get current user ID
$current_user_id = $_SESSION['user_id'];

// Handle search request
if (isset($_GET['search'])) {
    $search_term = $_GET['search'] . '%';
    
    // Search for users whose name or username matches the search term
    // Exclude Admin users from search results (hardcoded)
    $sql = "SELECT id, first_name, last_name, username, profile_picture FROM users 
            WHERE (first_name LIKE ? OR last_name LIKE ? OR username LIKE ?) 
            AND id != ?
            AND username != 'Admin' 
            AND username != 'admin'";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$search_term, $search_term, $search_term, $current_user_id]);
    $search_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Return search results as HTML for AJAX requests
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        if (count($search_results) > 0) {
            foreach ($search_results as $user): ?>
                <a href="message.php?user_id=<?php echo $user['id']; ?>" class="navbar-search-result">
                    <?php if (!empty($user['profile_picture'])): ?>
                        <img src="<?php echo $user['profile_picture']; ?>" class="result-avatar">
                    <?php else: ?>
                        <div class="result-avatar" style="display:flex;align-items:center;justify-content:center;flex-shrink:0;"><?php echo getInitialsHtml($user['first_name'], $user['last_name'], 40); ?></div>
                    <?php endif; ?>
                    <div class="result-details">
                        <div class="result-name"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></div>
                        <div class="result-username">@<?php echo htmlspecialchars($user['username']); ?></div>
                    </div>
                </a>
            <?php endforeach;
        } else {
            echo '<div class="no-results">No users found</div>';
        }
        exit;
    }
}

// If not an AJAX request or no search parameter, redirect to homepage
header("Location: homepage.php");
exit();
?>
