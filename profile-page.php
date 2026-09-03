<?php
session_start();

require_once 'db_connection.php';

date_default_timezone_set('Asia/Manila'); // Match timezone with homepage.php



// Handle AJAX username availability check
if (isset($_POST['check_username'])) {
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $current_user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;

    // Validate username length
    if (strlen($username) < 3) {
        die(json_encode(['available' => false, 'message' => 'Username too short']));
    }

    // Check if username exists (excluding current user)
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
    $stmt->execute([$username, $current_user_id]);
    $count = $stmt->fetchColumn();

    echo json_encode([
        'available' => $count == 0,
        'message' => $count == 0 ? 'Username available' : 'Username taken'
    ]);
    exit();
}

// Redirect if not logged in (for non-AJAX requests)
if (!isset($_SESSION['user_id']) && empty($_POST['check_username'])) {
    header("Location: process_login.php");
    exit();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_SESSION['user_id'];
    
    // Enhanced debug output
    $debug_data = [
        'timestamp' => date('Y-m-d H:i:s'),
        'user_id' => $user_id,
        'POST' => $_POST,
        'FILES' => $_FILES
    ];
    file_put_contents('debug.log', print_r($debug_data, true) . "\n\n", FILE_APPEND);
    
    // // Handle profile form submission (with explicit submit button check)
    if (isset($_POST['profile_submit'])) {
        try {
            // Verify required fields
            if (empty($_POST['first_name']) || empty($_POST['last_name']) || empty($_POST['username'])) {
                throw new Exception("All fields are required");
            }

            // Verify user exists first
            $check_user = $pdo->prepare("SELECT id FROM users WHERE id = ?");
            $check_user->execute([$user_id]);
            if (!$check_user->fetch()) {
                throw new Exception("Invalid user ID");
            }
            
            // Check if username is already taken by another user
            $username = trim($_POST['username']);
            if (strlen($username) < 3) {
                throw new Exception("Username must be at least 3 characters");
            }
            
            $check_username = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
            $check_username->execute([$username, $user_id]);
            if ($check_username->fetch()) {
                throw new Exception("Username is already taken");
            }

            // Handle file upload
            $profile_picture = null;
            if (isset($_FILES['profile_picture']['error']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['profile_picture'];
                
                // Validate file type
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
                $file_info = finfo_open(FILEINFO_MIME_TYPE);
                $mime_type = finfo_file($file_info, $file['tmp_name']);
                finfo_close($file_info);
                
                if (!in_array($mime_type, $allowed_types)) {
                    throw new Exception("Only JPG, PNG, and GIF images are allowed");
                }
                
                // Validate file size (max 2MB)
                if ($file['size'] > 2097152) {
                    throw new Exception("Image size must be less than 2MB");
                }
                
                // Create uploads directory if it doesn't exist
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                // Generate unique filename
                $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                $filename = 'profile_' . $user_id . '_' . time() . '.' . $ext;
                $upload_path = $upload_dir . '/' . $filename;
                
                if (!move_uploaded_file($file['tmp_name'], $upload_path)) {
                    throw new Exception("Failed to upload profile picture");
                }
                
                $profile_picture = 'uploads/' . $filename;
            }
            
            // Prepare the SQL statement
            if ($profile_picture) {
                $sql = "UPDATE users SET 
                        first_name = ?,
                        last_name = ?,
                        username = ?,
                        profile_picture = ?
                        WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $_POST['first_name'],
                    $_POST['last_name'],
                    $username,
                    $profile_picture,
                    $user_id
                ]);
            } else {
                // Keep existing profile picture if no new one uploaded
                $sql = "UPDATE users SET 
                        first_name = ?,
                        last_name = ?,
                        username = ?
                        WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $_POST['first_name'],
                    $_POST['last_name'],
                    $username,
                    $user_id
                ]);
            }
            
            if (!$stmt) {
                throw new Exception("Execute failed");
            }
            
            // Update session variables
            $_SESSION['username'] = $username;
            if ($profile_picture) {
                $_SESSION['profile_picture'] = $profile_picture;
            }
            
            $_SESSION['success_message'] = 'Profile updated successfully!';
            error_log("Profile updated successfully for user $user_id");
            error_log("Update executed. Affected rows: " . $stmt->affected_rows);
            
        } catch (Exception $e) {
            error_log("Error updating profile: " . $e->getMessage());
            $_SESSION['error_message'] = $e->getMessage();
            $_SESSION['form_data'] = $_POST; // Store form data for repopulation
        }
        
        header("Location: profile-page.php");
        exit();
    }
    
      if (isset($_POST['details_submit'])) {
    try {
        error_log("=== DETAILS SUBMISSION STARTED ===");
        error_log("User ID from session: " . $_SESSION['user_id']);
        
        // Verify user exists first
        $check_user = $pdo->prepare("SELECT id FROM users WHERE id = ?");
        $check_user->execute([$user_id]);
        $user_exists = $check_user->fetch();
        
        error_log("User exists check: " . ($user_exists ? "YES" : "NO"));
        
        if (!$user_exists) {
            throw new Exception("Invalid user ID");
        }
        
        // Validate bio length
        $bio = trim($_POST['bio'] ?? '');
        error_log("Bio length: " . strlen($bio));
        
        if (strlen($bio) < 10) {
            throw new Exception("Bio must be at least 10 characters");
        }

        
        
        // Prepare the SQL statement
        $sql = "UPDATE users SET 
                age = ?, 
                birthday = ?, 
                gender = ?, 
                location = ?, 
                interests = ?, 
                website = ?, 
                bio = ? 
                WHERE id = ?";
        
        error_log("Preparing SQL: " . $sql);
        
        $stmt = $pdo->prepare($sql);
        if (!$stmt) {
            throw new Exception("Prepare failed");
        }
        
        // Bind parameters
        $age = !empty($_POST['age']) ? (int)$_POST['age'] : NULL;
        $birthday = !empty($_POST['birthday']) ? $_POST['birthday'] : NULL;
        $gender = !empty($_POST['gender']) ? trim($_POST['gender']) : NULL;
        $location = !empty($_POST['location']) ? $_POST['location'] : NULL;
        $interests = !empty($_POST['interests']) ? $_POST['interests'] : NULL;
        
        $website = NULL;
        if (!empty($_POST['website'])) {
            $website = filter_var(trim($_POST['website']), FILTER_SANITIZE_URL);
            if (!preg_match("~^(?:f|ht)tps?://~i", $website)) {
                $website = "http://" . $website;
            }
        }
        
        error_log("Binding parameters: " . print_r([
            'age' => $age,
            'birthday' => $birthday,
            'gender' => $gender,
            'location' => $location,
            'interests' => $interests,
            'website' => $website,
            'bio' => $bio,
            'user_id' => $user_id
        ], true));
        
        $stmt->execute([
            $age,
            $birthday,
            $gender,
            $location,
            $interests,
            $website,
            $bio,
            $user_id
        ]);
        
        if (!$stmt) {
            error_log("Execute error");
            throw new Exception("Execute failed");
        }
        
        error_log("Update successful. Affected rows: " . $stmt->affected_rows);
        
        $_SESSION['success_message'] = 'Details updated successfully!';
        error_log("Details updated successfully for user $user_id");
        
    } catch (Exception $e) {
        error_log("Error updating details: " . $e->getMessage());
        $_SESSION['error_message'] = $e->getMessage();
        $_SESSION['form_data'] = $_POST;
    }
    
    header("Location: profile-page.php");
    exit();
}}

// Get user data
$user_id = $_SESSION['user_id'];

// Check if viewing a specific user's profile or own profile
$profile_user_id = isset($_GET['id']) ? intval($_GET['id']) : $user_id;

$sql = "SELECT first_name, last_name, username, profile_picture,
               age, gender, birthday, location, interests, website, bio 
        FROM users WHERE id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$profile_user_id]);
$user = $stmt->fetch();

if (!$user) {
    die("User not found!");
}

// Get user's posts with comment counts and like information
$posts = [];
$viewing_own_profile = ($user_id == $profile_user_id);

// Show all posts for the user's own profile (including on-hold ones), but only approved and posted posts when viewing someone else's profile
$status_condition = $viewing_own_profile ? "" : "AND (p.status = 'approved' OR p.status = 'posted' OR p.status IS NULL)";

$sql = "SELECT p.*, 
        u.first_name, u.last_name, u.profile_picture,
        COUNT(DISTINCT c.id) AS comment_count,
        p.likes,
        EXISTS(SELECT 1 FROM likes l WHERE l.user_id = ? AND l.post_id = p.id) AS user_has_liked
        FROM posts p 
        JOIN users u ON p.user_id = u.id 
        LEFT JOIN comments c ON p.id = c.post_id
        WHERE p.user_id = ? $status_condition
        GROUP BY p.id, u.first_name, u.last_name, u.profile_picture
        ORDER BY p.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$user_id, $profile_user_id]);
$posts = $stmt->fetchAll();

// Helper function to format time
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

// Set default values
$profile_picture = !empty($user['profile_picture']) ? $user['profile_picture'] : './web-images/default_pfp.jpg';

// Sanitize output
function clean($data) {
    return htmlspecialchars($data ?? '');
}

$full_name = clean($user['first_name']) . ' ' . clean($user['last_name']);
$username = clean($user['username']);
$age = clean($user['age']);
$gender = clean($user['gender']);
$birthday = clean($user['birthday']);
$location = clean($user['location']);
$interests = clean($user['interests']);
$website = clean($user['website']);
$bio = clean($user['bio']);

// Display messages
$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
$form_data = $_SESSION['form_data'] ?? null; // For repopulating form

// Clear messages after displaying
unset($_SESSION['success_message']);
unset($_SESSION['error_message']);
unset($_SESSION['form_data']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BondNest | Profile</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="profile-page.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="post-pending-styles.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="post-status-styles.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="post-styles.css?v=<?php echo time(); ?>">
    
    <!-- Include post synchronization script -->
    <script src="post_sync.js?v=<?php echo time(); ?>"></script>
    
    <!-- Include deleted post notification script -->
    <script src="deleted_post_notification.js?v=<?php echo time(); ?>"></script>
    
    <style>
        /* Root variables (CSS custom properties) */
        :root {
            /* Base colors */
            --color-white: #ffffff;
            --color-black: #000000;
            --color-dark: #333333;
            --color-light: #f0f8f8;
            --color-gray: #999999;
            --color-text-medium: #65676b;
            
            /* Primary theme color and variants */
            --color-primary: #008080; /* Teal */
            --color-primary-light: rgba(0, 128, 128, 0.1);
            --color-primary-dark: #006666;
            
            /* Utility colors */
            --color-success: #28a745;
            --color-danger: #dc3545;
            --color-warning: #ffc107;
            --color-info: #17a2b8;
            
            /* Layout dimensions */
            --card-padding: 1.5rem;
            --card-border-radius: 0.7rem;
        }
        
        /* Remove these rules that hide the navbar */
        /* nav {
            display: none !important;
        }
        
        body {
            padding-top: 0 !important;
            margin-top: 0 !important;
        } */
        
        /* Account for new navbar */
        .profile-app {
            margin-top: -60px;
            background: transparent !important;
            box-shadow: none !important;
            border-radius: 0 !important;
        }
        
        /* Update button styles */
        .btn-primary {
            background-color: var(--color-primary) !important;
            border-color: var(--color-primary) !important;
        }
        
        .btn-primary:hover {
            background-color: var(--color-primary-hover) !important;
            border-color: var(--color-primary-hover) !important;
        }
        
        /* Update links */
        a {
            color: var(--color-primary);
        }
        
        a:hover {
            color: var(--color-primary-hover);
        }
        
        /* Update active states */
        .nav-pills .nav-link.active, 
        .nav-pills .show > .nav-link {
            background-color: var(--color-primary) !important;
        }
        
        /* Update other teal-colored elements */
        .sidebar-menu-item.active {
            background-color: var(--color-primary-light) !important;
            border-left-color: var(--color-primary) !important;
        }
        
        .logout-confirm-button {
            background-color: var(--color-primary) !important;
        }
        
        .post-action.like-button[data-liked="true"] i {
            color: #e74c3c; /* Red color when liked */
        }
        
        /* Update any other relevant colors */
        .active-indicator {
            background-color: var(--color-primary) !important;
        }
        
        input:focus, textarea:focus, select:focus {
            border-color: var(--color-primary) !important;
            box-shadow: 0 0 0 0.2rem var(--color-primary-transparent) !important;
        }
        
        /* Define teal color as primary color variable for consistency */
        :root {
            --primary-color-hue: 180; /* Teal hue value */
            --color-primary: #008080;
            --color-primary-light: rgba(0, 128, 128, 0.1);
            --color-primary-dark: #006666;
            --post-image-border-radius: 10px; /* New variable for post image border radius */
        }
        
        /* Update sidebar active styles */
        .left .sidebar .active{
            background: var(--color-light);
        }
        
        .left .sidebar .active i,
        .left .sidebar .active h3 {
            color: var(--color-primary);
        }
        
        .left .sidebar .active::before{
            content: "";
            display: block;
            width: 0.5rem;
            height: 100%;
            position: absolute;
            background: var(--color-primary);
        }

        /* Make Home button text black when not active */
        .left .sidebar .menu-item:not(.active) h3 {
            color: var(--color-dark);
        }
        
        /* Ensure proper sidebar item positioning for indicator */
        .left .sidebar .menu-item {
            position: relative;
            display: flex;
            align-items: center;
            height: 4rem;
            cursor: pointer;
            transition: all 300ms ease;
            padding-left: 1rem;
        }
        
        /* Update buttons to teal - EXCLUDING Edit Profile and Edit Details buttons */
        .btn-primary:not(.profile-btn):not(.profile-card .profile-btn), 
        button[type="submit"]:not(.btn-secondary):not(.profile-btn):not(.profile-card .profile-btn),
        .save-button:not(.profile-btn):not(.profile-card .profile-btn) {
            background: var(--color-primary) !important;
        }
        
        .btn-primary:not(.profile-btn):not(.profile-card .profile-btn):hover, 
        button[type="submit"]:not(.btn-secondary):not(.profile-btn):not(.profile-card .profile-btn):hover,
        .save-button:not(.profile-btn):not(.profile-card .profile-btn):hover {
            background: var(--color-primary-dark) !important;
        }
        
        /* Restore the original button colors for Edit Profile and Edit Details */
        .profile-actions .profile-btn,
        .profile-card .profile-btn {
            background: var(--color-white) !important;
            color: var(--color-dark);
            border: 1px solid var(--color-light);
        }
        
        .profile-actions .profile-btn:hover,
        .profile-card .profile-btn:hover {
            background: var(--color-light) !important;
        }
        
        /* Fix for Edit Profile and Edit Details buttons text and icons */
        .profile-actions .profile-btn i,
        .profile-card .profile-btn i,
        .profile-actions .profile-btn,
        .profile-card .profile-btn {
            color: var(--color-dark) !important;
            font-weight: 500;
        }
        
        /* Update icons and links to teal */
        .profile-tabs .tab.active {
            color: var(--color-primary);
            border-bottom-color: var(--color-primary);
        }
        
        .profile-stats .stat i,
        .profile-links a i,
        .profile-data i {
            color: var(--color-primary);
        }
        
        /* Update form input focus states */
        input:focus, 
        textarea:focus, 
        select:focus {
            border-color: var(--color-primary) !important;
            box-shadow: 0 0 0 2px var(--color-primary-light) !important;
        }
        
        /* Any other color overrides for the profile page */
        .validation-feedback.valid {
            color: var(--color-primary) !important;
        }
        
        /* Post display styles from homepage.css and homepage2.css */
        .feeds {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .feed {
            background: var(--color-white);
            border-radius: var(--card-border-radius);
            padding: var(--card-padding);
            margin-bottom: 1rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
        }
        
        .feed:hover {
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
            transform: translateY(-2px);
        }
        
        .post-header {
            display: flex;
            align-items: center;
            gap: 3px;
            margin-bottom: 1rem;
            position: relative;
        }
        
        .post-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            overflow: hidden;
            border: 2px solid var(--color-light);
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        
        .post-avatar:hover {
            transform: scale(1.1);
        }
        
        .post-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .post-avatar .initials-avatar {
            width: 100% !important;
            height: 100% !important;
            font-size: 1rem !important;
            border-radius: 50% !important;
        }
        
        .post-user {
            font-weight: 600;
            font-size: 0.95rem;
            margin-bottom: 2px;
        }
        
        /* Update post meta text size */
        .post-meta {
            font-size: 0.85rem !important; /* Increased from 0.75rem */
            color: var(--text-medium);
        }
        
        .post-meta i {
            font-size: 0.9rem !important; /* Increased from 0.8rem */
        }
        
        .post-content {
            margin-bottom: 1rem;
            line-height: 1.5;
            font-size: 0.95rem;
            color: var(--color-dark);
            padding: 0 5px;
        }
        
        .post-media {
            margin-bottom: 1rem;
            border-radius: 8px;
            overflow: hidden;
            max-height: none;
            width: 100%;
            display: block;
        }
        
        .post-media img {
            width: 100%;
            height: auto;
            object-fit: cover;
            border-radius: 8px;
            max-height: none;
        }
        
        /* Enhance post divider lines - make more visible */
        .post-actions {
            display: flex;
            justify-content: flex-start;
            gap: 10px;
            border-top: 1px solid #d0d0d0 !important; /* Darker color and !important flag */
            padding-top: 0.75rem;
            margin-top: 0.5rem;
        }
        
        /* Add separation line between likes and comments - make more visible */
        .liked-by {
            padding: 0.5rem 0;
            border-top: 1px solid #e0e0e0 !important; /* Darker color and !important flag */
            margin-top: 0.5rem;
        }
        
        /* Make post media bottom border more visible */
        .post-media {
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: none;
        }
        
        .post-action {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.2s ease;
            color: var(--text-medium);
            font-size: 0.9rem;
            font-weight: 500;
            flex: 0;
            justify-content: flex-start;
        }
        
        .post-action:hover {
            background: rgba(0, 128, 128, 0.1);
            color: var(--color-primary);
        }
        
        .liked-by {
            padding: 0.5rem 0;
        }
        
        /* Post menu styles */
        .post-actions-menu {
            position: relative;
            margin-left: auto;
            cursor: pointer;
        }
        
        .post-menu-dropdown {
            position: absolute;
            right: 0;
            top: 100%;
            background: var(--color-white);
            border-radius: var(--card-border-radius);
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            padding: 0.5rem 0;
            width: 150px;
            z-index: 100;
            display: none;
        }
        
        .post-menu-dropdown.show {
            display: block;
            animation: dropdown-in 0.2s forwards;
        }
        
        @keyframes dropdown-in {
            from { opacity: 0; transform: scale(0.9); }
            to { opacity: 1; transform: scale(1); }
        }
        
        .post-menu-item {
            padding: 0.5rem 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .post-menu-item:hover {
            background: var(--color-light);
        }
        
        .post-menu-item i {
            font-size: 1rem;
        }
        
        /* Like button styles */
        .post-action.like-button i {
            transition: all 0.2s ease;
            color: var(--color-gray);
        }
        
        .post-action.like-button[data-liked="true"] i {
            color: var(--color-danger);
        }
        
        .post-action.like-button:hover i {
            transform: scale(1.1);
        }
        
        /* Empty feed styles */
        .empty-feed {
            background: var(--color-white);
            border-radius: var(--card-border-radius);
            padding: 2rem;
            text-align: center;
            color: var(--text-medium);
        }
        
        /* Comment modal styles */
        .comment-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
        }
        
        .comment-modal-content {
            background: white;
            width: 500px;
            max-height: 80vh;
            margin: 2rem auto;
            padding: 20px;
            border-radius: 10px;
            overflow-y: auto;
        }
        
        .comment-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .close-comment {
            cursor: pointer;
            font-size: 1.5rem;
        }

        /* Additional styles from homepage.css and homepage2.css */
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
            transform-origin: top right;
            animation: dropdown-in 0.2s forwards;
        }
        
        .post-menu-item {
            padding: 0.7rem 1rem;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
        }
        
        .post-menu-item.delete-post {
            color: #e74c3c;
        }
        
        .post-menu-item.delete-post:hover {
            background: rgba(231, 76, 60, 0.1);
        }
        
        .post-menu-item.edit-post {
            color: var(--color-primary); /* Teal color */
        }
        
        .post-menu-item.edit-post i {
            color: var(--color-primary); /* Teal color for the icon */
        }
        
        .post-menu-item.edit-post:hover {
            background: var(--color-primary-light); /* Light teal background on hover */
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
            background: #f0f2f5;
            padding: 8px 12px;
            border-radius: 15px;
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
        
        .comments-container {
            display: flex;
            flex-direction: column;
            height: 70vh;
        }
        
        .loading-comments, .no-comments {
            text-align: center;
            padding: 20px;
            color: #666;
        }
        
        /* Custom scrollbar for comment list */
        .comment-list::-webkit-scrollbar {
            width: 8px;
        }
        
        .comment-list::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        
        .comment-list::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 10px;
        }
        
        .comment-list::-webkit-scrollbar-thumb:hover {
            background: #555;
        }
        
        /* Animation for post deletion */
        .post-item-deleting {
            animation: postFadeOut 0.3s forwards;
            pointer-events: none;
        }
        
        @keyframes postFadeOut {
            from {
                opacity: 1;
                transform: translateY(0);
            }
            to {
                opacity: 0;
                transform: translateY(-10px);
            }
        }
        
        /* Activity Feed container without extra outer box */
        .activity-feed {
            padding: 0;
            background: transparent;
            border-radius: 0;
            box-shadow: none;
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .activity-feed-header-card {
            background: var(--color-white);
            border-radius: 15px;
            padding: 15px 20px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .feed-title {
            color: var(--color-primary);
            font-size: 1.25rem;
            font-weight: 600;
            margin: 0;
        }
        
        /* Additional fixes for post display */
        .activity-feed .feeds {
            margin-top: 0;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .activity-feed .feed {
            background: var(--color-white);
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 0;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }
        
        /* Ensure consistent post content styling */
        .post-content {
            font-size: 0.95rem;
            line-height: 1.5;
            color: var(--color-dark);
            padding: 0 5px;
        }
        
        /* Fix post image display - more specific selectors to override previous styles */
        .activity-feed .post-media {
            margin-bottom: 1rem;
            border-radius: 8px;
            overflow: hidden;
            max-height: none !important;
            width: 100%;
            display: block;
        }
        
        .activity-feed .post-media img {
            width: 100%;
            height: auto;
            object-fit: cover;
            border-radius: 8px;
            max-height: none !important;
        }
        
        /* Fix post actions layout - more specific selectors */
        .activity-feed .post-actions {
            display: flex;
            justify-content: flex-start !important;
            gap: 10px;
            border-top: 1px solid var(--color-light);
            padding-top: 0.75rem;
            margin-top: 0.5rem;
        }
        
        .activity-feed .post-action {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.2s ease;
            color: var(--text-medium);
            font-size: 0.9rem;
            font-weight: 500;
            flex: 0 !important;
            justify-content: flex-start !important;
        }
        
        /* Fix spacing between post elements */
        .activity-feed .post-content {
            margin-bottom: 15px;
        }
        
        /* Fix post media container - more aggressive approach */
        .activity-feed .post-media {
            margin: 0 -1rem 15px -1rem;
            width: calc(100% + 2rem);
            border-radius: 0;
            max-height: none !important;
            overflow: visible;
            display: block !important;
            position: relative !important;
        }
        
        .activity-feed .post-media img {
            border-radius: 0;
            width: 100% !important;
            height: auto !important;
            max-height: none !important;
            object-fit: contain !important;
            display: block !important;
            aspect-ratio: auto !important;
        }
        
        /* Ensure the post content is properly displayed */
        .activity-feed .post-content p {
            margin-bottom: 15px;
            word-wrap: break-word;
            overflow-wrap: break-word;
            word-break: break-word;
            hyphens: auto;
            max-width: 100%;
            white-space: normal;
        }
        
        /* Override any flex display that might be affecting the layout */
        .activity-feed .feed {
            display: block !important;
        }
        
        /* Fix liked-by section */
        .activity-feed .liked-by {
            padding: 0.5rem 0;
            margin-top: 5px;
        }
        
        /* Ensure post menu is properly positioned */
        .activity-feed .post-actions-menu {
            position: relative;
            margin-left: auto;
        }
        
        /* Add animation for like button */
        @keyframes likeAnimation {
            0% { transform: scale(1); }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); }
        }
        
        .post-action.like-button[data-liked="true"] i.bi-heart-fill {
            animation: likeAnimation 0.3s ease;
            color: var(--color-danger);
        }
        
        /* Direct fix for post images */
        .activity-feed .feed .post-media {
            margin: 0 -1rem 15px -1rem !important;
            width: calc(100% + 2rem) !important;
            border-radius: 0 !important;
            max-height: none !important;
            overflow: visible !important;
            display: block !important;
            position: relative !important;
            box-sizing: border-box !important;
        }
        
        .activity-feed .feed .post-media img {
            border-radius: 0 !important;
            width: 100% !important;
            height: auto !important;
            max-height: none !important;
            object-fit: cover !important;
            display: block !important;
            aspect-ratio: auto !important;
            margin: 0 !important;
            padding: 0 !important;
        }
        
        /* Reset any transforms or filters that might be applied */
        .activity-feed .feed .post-media img {
            transform: none !important;
            filter: none !important;
        }
        
        /* Ensure the image container doesn't have any weird constraints */
        .activity-feed .feed .post-media {
            min-height: 0 !important;
            min-width: 0 !important;
            transform: none !important;
        }
        
        /* Complete reset of post image styles */
        .activity-feed .post-media,
        .activity-feed .feed .post-media {
            display: block !important;
            width: 100% !important;
            margin: 0 0 15px 0 !important;
            padding: 0 !important;
            border-radius: 0 !important;
            overflow: hidden !important;
            position: relative !important;
            max-height: none !important;
            height: auto !important;
            background: transparent !important;
        }
        
        .activity-feed .post-media img,
        .activity-feed .feed .post-media img {
            display: block !important;
            width: 100% !important;
            height: auto !important;
            max-width: 100% !important;
            max-height: none !important;
            object-fit: contain !important;
            border-radius: 0 !important;
            margin: 0 !important;
            padding: 0 !important;
            position: relative !important;
        }
        
        /* Ensure post content has proper spacing */
        .activity-feed .post-content {
            margin-bottom: 15px !important;
            padding: 0 !important;
        }
        
        /* Fix specific issue with image display */
        .activity-feed .post-media::after {
            content: "" !important;
            display: block !important;
            clear: both !important;
        }
        
        /* Remove any transforms that might affect the layout */
        .activity-feed .feed,
        .activity-feed .post-media,
        .activity-feed .post-media img {
            transform: none !important;
            transition: none !important;
        }
        
        /* Add animation for like button */
        @keyframes likeAnimation {
            0% { transform: scale(1); }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); }
        }
        
        .post-action.like-button[data-liked="true"] i.bi-heart-fill {
            animation: likeAnimation 0.3s ease;
            color: var(--color-danger);
        }
        
        /* Profile post image style */
        .profile-post-image {
            width: 100%;
            height: auto;
            display: block;
            object-fit: cover;
            max-width: 100%;
            border-radius: 8px;
            margin-bottom: 0;
        }
        
        /* Ensure feed has proper styling */
        .activity-feed .feed {
            border-radius: 10px;
            overflow: hidden;
            background: var(--color-white);
            padding: var(--card-padding);
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        
        /* Override any conflicting styles for post-media */
        .activity-feed .post-media {
            border-radius: 8px !important;
            overflow: hidden !important;
            margin: 0 0 15px 0 !important;
        }
        
        /* Direct styling for post images */
        .activity-feed img {
            border-radius: 12px !important;
        }
        
        /* Ensure all corners are rounded */
        .feed, .post-media, .post-media img, 
        .activity-feed .feed, .activity-feed .post-media, .activity-feed .post-media img {
            border-radius: 12px !important;
        }
        
        /* Exact post-media styling from homepage.css */
        .activity-feed .post-media {
            background: #f5f5f5 !important;
            border-radius: 8px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            margin-bottom: 15px !important;
            overflow: hidden !important;
        }
        
        /* New specific styles for post images */
        .activity-feed .post-media,
        .feed .post-media {
            border-radius: var(--post-image-border-radius) !important;
            overflow: hidden !important;
        }
        
        .activity-feed .post-media img,
        .feed .post-media img {
            border-radius: var(--post-image-border-radius) !important;
        }
        
        /* Make divider lines more visible to match homepage.php */
        .post-actions {
            display: flex;
            justify-content: flex-start;
            gap: 10px;
            border-top: 1px solid #d0d0d0 !important; /* Darker color and !important flag */
            padding-top: 0.75rem;
            margin-top: 0.5rem;
        }
        
        /* Style for liked-by section divider - more visible */
        .liked-by {
            padding: 0.5rem 0;
            border-top: 1px solid #e0e0e0 !important; /* Darker color and !important flag */
            margin-top: 0.5rem;
        }
        
        /* Post content bottom margin adjustment */
        .post-content {
            margin-bottom: 1rem;
            border-bottom: none;
        }
        
        /* Add additional high-specificity selectors */
        .activity-feed .feed .post-actions {
            border-top: 1px solid #d0d0d0 !important;
        }
        
        .activity-feed .feed .liked-by {
            border-top: 1px solid #e0e0e0 !important;
        }
        
        /* Match divider lines exactly with homepage.css */
        .post-actions {
            display: flex;
            gap: 15px;
            padding-top: 15px;
            border-top: 1px solid rgba(0, 0, 0, 0.1);
        }
        
        .liked-by {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid rgba(0, 0, 0, 0.1);
        }
        
        /* Make sure the above styles apply with higher specificity */
        .activity-feed .feed .post-actions {
            display: flex;
            gap: 15px;
            padding-top: 15px;
            border-top: 1px solid rgba(0, 0, 0, 0.1);
        }
        
        .activity-feed .feed .liked-by {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid rgba(0, 0, 0, 0.1);
        }

        /* Enhanced Modal Styles */
        /* Define teal color as primary color variable for consistency */
        :root {
            --primary-color-hue: 180; /* Teal hue value */
            --color-primary: #008080;
            --color-primary-light: rgba(0, 128, 128, 0.1);
            --color-primary-dark: #006666;
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
            border-top: 4px solid var(--color-primary); /* Changed from red to teal */
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
            color: #e74c3c; /* Keeping original red color */
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
            background: rgba(231, 76, 60, 0.1); /* Keeping original light red background */
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
            background: var(--color-primary); /* Changed from red to teal */
        }

        .delete-btn:hover {
            background: var(--color-primary-dark); /* Changed from dark red to dark teal */
            box-shadow: 0 3px 8px rgba(0, 128, 128, 0.2); /* Changed shadow color to teal */
        }

        /* Edit post modal */
        .edit-post-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .edit-post-modal-content {
            background: var(--color-white);
            border-radius: var(--card-border-radius);
            width: 500px;
            max-width: 90%;
            padding: 1.5rem;
        }

        .edit-post-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .edit-post-header h2 {
            font-size: 1.2rem;
        }

        .edit-post-close {
            font-size: 1.5rem;
            cursor: pointer;
        }

        .edit-post-body textarea {
            width: 100%;
            min-height: 100px;
            padding: 0.5rem;
            border: 1px solid var(--color-gray);
            border-radius: var(--card-border-radius);
            margin-bottom: 1rem;
        }

        .edit-image-preview {
            max-width: 100%;
            max-height: 300px;
            margin-bottom: 1rem;
            display: none;
        }

        .edit-post-actions {
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
        }

        /* Add animation for deleting posts */
        .post-item-deleting {
            opacity: 0;
            transform: scale(0.95);
            height: 0;
            margin: 0;
            padding: 0;
            overflow: hidden;
            transition: all 0.3s ease;
        }
        
        /* Center profile picture in edit modal */
        #editProfileModal .current-avatar {
            display: flex;
            justify-content: center;
            align-items: center;
            margin: 20px 0;
        }
        
        #editProfileModal #currentProfilePic {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid #f0f8f8;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }
        
        #editProfileModal .btn-secondary {
            display: block;
            margin: 15px auto;
        }
        
        /* Default positioning for modals to ensure they appear at the top */
        #editProfileModal, #editDetailsModal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 9999;
            justify-content: center;
            align-items: flex-start !important;
            padding-top: 100px !important;
        }
        
        /* Make modal content scrollable by default */
        #editProfileModal .modal-content, #editDetailsModal .modal-content {
            max-height: 90vh;
            overflow-y: auto;
            transform: none !important;
            margin-top: 0 !important;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3) !important;
            border: 2px solid var(--color-primary) !important;
        }

        /* Add these styles for like button animations */
        .post-action.like-button.processing {
            opacity: 0.7;
        }
        
        .post-action.like-button.liked-animation {
            animation: likeAnimation 0.7s ease;
        }
        
        @keyframes likeAnimation {
            0% { transform: scale(1); }
            25% { transform: scale(1.2); }
            50% { transform: scale(0.95); }
            75% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        /* Heart fill animation */
        .post-action.like-button[data-liked="true"] i.bi-heart-fill {
            color: #e74c3c;
            transform: scale(1);
            animation: heartPulse 0.4s ease;
        }
        
        @keyframes heartPulse {
            0% { transform: scale(0.8); }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); }
        }

        /* Hidden sidebar state */
        .profile-sidebar.hidden {
            display: none !important;
        }

        /* Reset some default styles */
        .profile-sidebar .menu-item {
            padding-left: 1rem;
        }

        /* Add profile and sidebar styling from homepage.css */
        .profile {
            padding: var(--card-padding);
            background: var(--color-white);
            border-radius: var(--card-border-radius);
            display: flex;
            align-items: center;
            column-gap: 1rem;
            width: 100%;
            margin-top: -1000px;
            margin-left: -180px; /* Remove negative margin */
            margin-bottom: 1rem; /* Add space between profile and sidebar */
            overflow: y;
        }

        .profile_picture {
            width: 2.7rem;
            aspect-ratio: 1/1;
            border-radius: 50%;
            overflow: hidden;
        }

        .profile_picture img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .handle h4 {
            font-weight: 600;
            margin-bottom: 0.2rem;
        }

        .handle p {
            font-size: 0.8rem;
        }

        .sidebar {
            margin-top: 1rem;
            background: var(--color-white);
            border-radius: var(--card-border-radius);
            overflow-y: visible !important; /* Prevent scrollbar */
            height: auto !important; /* Allow natural height */
            max-height: none !important; /* Remove any max-height restrictions */
        }

        .sidebar .menu-item {
            position: relative;
            display: flex;
            align-items: center;
            height: 4rem;
            cursor: pointer;
            transition: all 300ms ease;
            padding-left: 0; /* Remove left padding to align with left edge */
            margin-left: 0; /* Remove any margin that might affect alignment */
        }

        .sidebar .menu-item:hover {
            background: var(--color-light);
        }

        .sidebar i {
            font-size: 1.4rem;
            color: var(--color-gray);
            margin-right: 1.5rem;
            margin-left: 2rem; /* Add left margin to compensate for removed padding */
            position: relative;
        }

        .sidebar h3 {
            margin-left: 0;
            font-size: 1rem;
            color: var(--color-dark);
        }

        .sidebar .active {
            background: var(--color-light);
        }

        .sidebar .active i,
        .sidebar .active h3 {
            color: var(--color-primary);
        }
        
        .sidebar .active::before {
            content: "";
            display: block;
            width: 0.5rem;
            height: 100%;
            position: absolute;
            background: var(--color-primary);
            left: 0;
        }
        
        .sidebar .menu-item:first-child.active {
            border-top-left-radius: var(--card-border-radius);
            overflow: hidden;
        }

        .sidebar .menu-item:last-child.active {
            border-bottom-left-radius: var(--card-border-radius);
            overflow: hidden;
        }

        .btn-primary {
            display: inline-block;
            padding: var(--btn-padding);
            font-weight: 500;
            border-radius: var(--border-radius);
            cursor: pointer;
            transition: all 300ms ease;
            font-size: 0.9rem;
            background: var(--color-primary);
            color: white;
        }

        .btn-primary:hover {
            opacity: 0.8;
        }

        .left .btn {
            margin-top: 1rem;
            width: 100%;
            text-align: center;
            padding: 1rem 0;
            box-sizing: border-box;
            height: auto !important;
            min-height: 3rem;
        }

        /* Original teal color override */
        :root {
            --primary-color-hue: 180; /* Teal hue value */
            --color-primary: #008080;
            --color-primary-light: rgba(0, 128, 128, 0.1);
            --color-primary-dark: #006666;
            
            /* Add additional variables needed for the profile components */
            --color-white: white;
            --color-light: #f5f5f5;
            --color-gray: #888;
            --color-dark: #333;
            
            --border-radius: 2rem;
            --card-border-radius: 1rem;
            --btn-padding: 0.6rem 2rem;
            --search-padding: 0.6rem 1rem;
            --card-padding: 1rem;
            
            --sticky-top-left: 5.4rem;
        }

        main .container {
            display: grid;
            grid-template-columns: 18vw auto 20vw;
            column-gap: 2rem;
            position: relative;
        }

        main .container .left {
            height: max-content;
            position: sticky;
            top: var(--sticky-top-left);
            overflow: visible !important; /* Prevent scrollbar */
            display: flex;
            flex-direction: column;
            align-items: stretch;
        }

        /* Container layout adjustments */
        .container {
            width: 80%;
            margin: 0 auto;
        }

        /* Text styling */
        .text-muted {
            color: var(--color-gray);
        }

        /* Fix scroll issues in the sidebar when new items are added */
        .left, 
        .left .sidebar, 
        .left .profile, 
        .left .btn {
            overflow: visible !important;
            height: auto !important;
        }

        /* Fix profile alignment */
        .container > .profile {
            margin-bottom: 1rem;
        }

        /* Fix for negative margin on top */
        main {
            position: relative;
            top: 5.4rem;
            margin-bottom: 2rem;
        }

        /* Media queries for responsive design */
        @media screen and (max-width: 1200px) {
            .container {
                width: 96%;
            }
            
            main .container {
                grid-template-columns: 20% auto;
                gap: 2rem;
            }
        }

        @media screen and (max-width: 992px) {
            main .container {
                grid-template-columns: 25% auto;
            }
        }

        @media screen and (max-width: 768px) {
            main .container {
                grid-template-columns: 100%;
            }
            
            .profile {
                width: 90%;
                margin: 0 auto;
            }
            
            .sidebar {
                width: 90%;
                margin: 1rem auto 0;
            }
            
            .left .btn {
                width: 90%;
                margin: 1rem auto 0;
            }
        }

        /* Navbar styles */
        // ... existing code ...
    </style>
    
    <!-- Comment Modal Styles -->
    <style>
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
            display: block;
            margin-bottom: 0;
            border-bottom: none;
            background: transparent;
            border-radius: 0;
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
            border: 1px solid #ddd !important;
            border-radius: 8px !important;
            padding: 12px !important;
            min-height: 120px !important;
            font-size: 16px !important;
            line-height: 1.5 !important;
            resize: vertical !important;
        }

        .edit-post-actions {
            padding: 15px 20px !important;
            display: flex !important;
            justify-content: flex-end !important;
            border-top: 1px solid #eee !important;
            gap: 10px !important;
        }

        /* Floating comment menu */
        .floating-comment-menu {
            position: fixed !important;
            background: white !important;
            border-radius: 8px !important;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2) !important;
            z-index: 9999 !important;
            min-width: 180px !important;
            padding: 5px 0 !important;
        }

        .comment-menu-item {
            padding: 10px 15px !important;
            cursor: pointer !important;
            display: flex !important;
            align-items: center !important;
            transition: background-color 0.2s ease !important;
        }

        .comment-menu-item:hover {
            background-color: #f5f5f5 !important;
        }

        .comment-menu-item.edit-comment {
            color: var(--color-primary) !important;
        }

        .comment-menu-item.delete-comment {
            color: #e74c3c !important;
        }

        .comment-menu-item i {
            margin-right: 8px !important;
            font-size: 14px !important;
        }
    </style>

    <!-- Add this CSS in the head section or before the closing body tag -->
    <style>
    /* Comment modal styles */
    .comment-form-fixed {
        position: sticky;
        bottom: 0;
        left: 0;
        width: 100%;
        background: white;
        padding: 15px;
        box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
        display: flex;
        flex-direction: column;
        z-index: 2;
    }

    .comment-input {
        width: 100%;
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 8px;
        resize: none;
        margin-bottom: 10px;
        font-family: inherit;
    }

    .comment-input:focus {
        outline: none;
        border-color: #7c3aed;
    }

    .comment-form-fixed button {
        align-self: flex-end;
    }

    .comment-list {
        max-height: 400px;
        overflow-y: auto;
        padding: 0 15px;
        margin-bottom: 60px;
    }

    .loading-comments {
        text-align: center;
        padding: 20px;
        color: #666;
    }

    .no-comments {
        text-align: center;
        padding: 40px;
        color: #888;
    }

    .comment-item {
        margin-bottom: 15px;
        padding: 10px;
        border-radius: 8px;
        background-color: #f9f9f9;
        position: relative;
        display: block;
    }

    .comment-item.new-comment {
        animation: highlightNew 2s ease;
    }

    @keyframes highlightNew {
        0% { background-color: #e8f4ff; }
        100% { background-color: #f9f9f9; }
    }

    .comment-content {
        display: flex;
        gap: 10px;
    }

    .comment-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        object-fit: cover;
    }

    .comment-body {
        flex: 1;
    }

    .comment-author {
        font-weight: 600;
        margin: 0 0 5px 0;
    }

    .comment-text {
        margin: 0;
        white-space: pre-wrap;
        word-break: break-word;
    }

    .comment-time {
        font-size: 0.75rem;
        color: #999;
        display: block;
        margin-top: 5px;
        }
    </style>
</head>
<body class="profile-page">
<?php include 'navbar.php'; ?>
<?php include 'mobile-nav.php'; ?>

    <!-- Main Profile Container -->
    <div class="profile-app">

        <!-- Profile Content - Split Layout -->
        <div class="profile-content" id="profileContent">
            <!-- Left Sidebar - Profile Info -->
            <div class="profile-sidebar" id="profileSidebar">
                <!-- Moved Profile Header to Sidebar -->
                <div class="profile-header">
                    <div class="profile-banner">
                        <img src="./web-images/bg3.png" alt="Profile Banner">
                    </div>
                    
                    <div class="profile-avatar">
                        <?php if (!empty($user['profile_picture'])): ?>
                            <img src="<?php echo $user['profile_picture']; ?>">
                        <?php else: ?>
                            <?php echo getInitialsHtml($user['first_name'], $user['last_name'], 100); ?>
                        <?php endif; ?>
                    </div>
                    
                    <h1 class="profile-name"><?php echo $full_name; ?></h1>
                    <div class="profile-handle">@<?php echo htmlspecialchars($user['username']); ?></div>
                    
                    <div class="profile-stats">
                        <?php
                        // Count number of posts by the user
                        $post_count_sql = "SELECT COUNT(*) AS post_count FROM posts WHERE user_id = ?";
                        $post_stmt = $pdo->prepare($post_count_sql);
                        $post_stmt->execute([$user_id]);
                        $post_count = $post_stmt->fetch()['post_count'];
                        
                        // Count likes received by the user
                        $likes_received_sql = "SELECT SUM(likes) AS total_likes FROM posts WHERE user_id = ?";
                        $likes_received_stmt = $pdo->prepare($likes_received_sql);
                        $likes_received_stmt->execute([$user_id]);
                        $likes_received = $likes_received_stmt->fetch()['total_likes'] ?? 0;
                        
                        // Count likes given by the user
                        $likes_given_sql = "SELECT COUNT(*) AS likes_given FROM likes WHERE user_id = ?";
                        $likes_given_stmt = $pdo->prepare($likes_given_sql);
                        $likes_given_stmt->execute([$profile_user_id]);
                        $likes_given = $likes_given_stmt->fetch()['likes_given'];
                        ?>
                        <style>
                            /* Enhanced Stats Styling */
                            .profile-stats {
                                display: flex;
                                justify-content: space-between;
                                padding: 10px 8px;
                                background: #ffffff;
                                border-radius: 8px;
                                margin: 15px 0;
                                box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
                                max-width: 100%;
                                box-sizing: border-box;
                                overflow: hidden;
                            }
                            
                            .stat-item {
                                text-align: center;
                                padding: 8px 4px;
                                margin: 0 2px;
                                flex: 1;
                                background: #f8f9fa;
                                border-radius: 6px;
                                border-bottom: 3px solid #00cccc;
                                transition: all 0.3s ease;
                                min-width: 0;
                            }
                            
                            .stat-item:hover {
                                transform: translateY(-2px);
                                box-shadow: 0 3px 10px rgba(0, 204, 204, 0.2);
                            }
                            
                            .stat-value {
                                font-size: 1.3rem;
                                font-weight: bold;
                                color: #006666;
                                margin-bottom: 3px;
                                position: relative;
                                text-shadow: 0 1px 1px rgba(255, 255, 255, 0.8);
                                white-space: nowrap;
                            }
                            
                            .stat-label {
                                font-size: 0.78rem;
                                color: #333333;
                                font-weight: 500;
                                white-space: normal;
                                overflow: visible;
                                text-overflow: unset;
                                word-break: break-word;
                            }
                            
                            /* Admin-style animation for stats */
                            @keyframes flashUpdate {
                                0%, 50%, 100% { color: #006666; }
                                25%, 75% { color: #00cccc; }
                            }
                            
                            .flash-update {
                                animation: flashUpdate 1.5s ease;
                            }
                            
                            .stat-item.updating {
                                box-shadow: 0 0 18px rgba(0, 204, 204, 0.5);
                                background-color: #f0ffff;
                                transition: all 0.3s ease;
                            }
                        </style>
                        
                        <div class="stat-item">
                            <div class="stat-value" id="posts-count" data-value="<?php echo $post_count; ?>">0</div>
                            <div class="stat-label">Posts</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value" id="appreciation-count" data-value="<?php echo $likes_received; ?>">0</div>
                            <div class="stat-label">Appreciations</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value" id="shared-count" data-value="<?php echo $likes_given; ?>">0</div>
                            <div class="stat-label">Shared Love</div>
                        </div>
                    </div>
                    
                    <script>
                        // Counter animation for profile stats (admin-style)
                        document.addEventListener('DOMContentLoaded', function() {
                            // Animate value changes using the admin-style animation
                            function animateValueChange(element, oldValue, newValue) {
                                // First highlight the element 
                                element.classList.add('flash-update');
                                
                                // Find and highlight the parent stat item for better visibility
                                const statItem = element.closest('.stat-item');
                                if (statItem) {
                                    statItem.classList.add('updating');
                                    setTimeout(() => {
                                        statItem.classList.remove('updating');
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
                                    element.textContent = currentValue.toLocaleString();
                                    
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
                            
                            // Get elements and their target values
                            const postsElement = document.getElementById('posts-count');
                            const appreciationElement = document.getElementById('appreciation-count');
                            const sharedElement = document.getElementById('shared-count');
                            
                            const postsValue = parseInt(postsElement.dataset.value);
                            const appreciationValue = parseInt(appreciationElement.dataset.value);
                            const sharedValue = parseInt(sharedElement.dataset.value);
                            
                            // Start animations with a slight delay between each
                            setTimeout(() => animateValueChange(postsElement, 0, postsValue), 300);
                            setTimeout(() => animateValueChange(appreciationElement, 0, appreciationValue), 600);
                            setTimeout(() => animateValueChange(sharedElement, 0, sharedValue), 900);
                        });
                    </script>
                </div>

<!-- EDIT DETAILS DISPLAY              -->
<div class="profile-card">
    <h2 class="card-title"><i class="fas fa-info-circle"></i> Bio</h2>
    <div class="card-content">
        <ul class="info-list">
            <!-- Bio Section -->
            <li class="info-item">
                <i class="fas fa-align-left info-icon"></i>
                <div class="info-text">
                    <?php if (!empty($user['bio'])): ?>
                        <?php echo nl2br(htmlspecialchars($user['bio'])); ?>
                    <?php else: ?>
                        <span class="empty-info">Add your bio (minimum 10 characters)</span>
                    <?php endif; ?>
                </div>
            </li>
            
            <!-- Demographic Information -->
            <li class="info-item">
                <i class="fas fa-user info-icon"></i>
                <div class="info-text">
                    <?php echo htmlspecialchars($user['age']); ?> years old
                </div>
            </li>
            
            <li class="info-item">
                <i class="fas fa-venus-mars info-icon"></i>
                <div class="info-text">
                    <?php echo htmlspecialchars($user['gender']); ?>
                </div>
            </li>
            
            <li class="info-item">
                <i class="fas fa-birthday-cake info-icon"></i>
                <div class="info-text">
                    Born on <?php echo date('F j, Y', strtotime($user['birthday'])); ?>
                </div>
            </li>
            
            <li class="info-item">
                <i class="fas fa-home info-icon"></i>
                <div class="info-text">
                    <?php if (!empty($user['location'])): ?>
                        Lives in <?php echo htmlspecialchars($user['location']); ?>
                    <?php else: ?>
                        <span class="empty-info">Add your location</span>
                    <?php endif; ?>
                </div>
            </li>
            
            <li class="info-item">
                <i class="fas fa-heart info-icon"></i>
                <div class="info-text">
                    <?php if (!empty($user['interests'])): ?>
                        Interested in <?php echo htmlspecialchars($user['interests']); ?>
                    <?php else: ?>
                        <span class="empty-info">Add your interests</span>
                    <?php endif; ?>
                </div>
            </li>
            
            <li class="info-item">
    <i class="fas fa-link info-icon"></i>
    <div class="info-text">
        <?php if (!empty($user['website'])): ?>
            <a href="<?php echo htmlspecialchars($user['website']); ?>" 
               target="_blank" rel="noopener noreferrer"
               style="color: var(--primary);">
                <?php 
                // Display the full URL
                echo htmlspecialchars($user['website']);
                ?>
            </a>
        <?php else: ?>
            <span class="empty-info">Add your website</span>
        <?php endif; ?>
    </div>
</li>
        </ul>
    </div>
</div>

                
                <!-- <div class="profile-card">
                    <h2 class="card-title"><i class="fas fa-camera"></i> Photo Gallery</h2>
                    <div class="card-content">
                        <div class="photo-grid">
                            <div class="photo-item">
                                <img src="./web-images/pfp.jpg" alt="Photo 1">
                            </div>
                            <div class="photo-item">
                                <img src="./web-images/notif1.jpg" alt="Photo 2">
                            </div>
                            <div class="photo-item">
                                <img src="./web-images/notif2.jpg" alt="Photo 3">
                            </div>
                            <div class="photo-item">
                                <img src="./web-images/notif3.jpg" alt="Photo 4">
                            </div>
                            <div class="photo-item">
                                <img src="./web-images/pfp.jpg" alt="Photo 5">
                            </div>
                            <div class="photo-item">
                                <img src="./web-images/notif1.jpg" alt="Photo 6">
                            </div>
                        </div>
                        <a href="#" class="see-all">See All Photos</a>
                    </div>
                </div> -->

                
                
                <!-- <div class="profile-card">
                    <h2 class="card-title"><i class="fas fa-users"></i> Connections</h2>
                    <div class="card-content">
                        <p>1,243 connections</p>
                        <div class="photo-grid">
                            <div class="photo-item">
                                <img src="./web-images/pfp.jpg" alt="Connection">
                                <p style="font-size: 0.7rem; text-align: center; margin-top: 5px;">Zakari Cuence</p>
                            </div>
                            <div class="photo-item">
                                <img src="./web-images/notif1.jpg" alt="Connection">
                                <p style="font-size: 0.7rem; text-align: center; margin-top: 5px;">Lance Bautista</p>
                            </div>
                            <div class="photo-item">
                                <img src="./web-images/notif2.jpg" alt="Connection">
                                <p style="font-size: 0.7rem; text-align: center; margin-top: 5px;">Aivan Reyes</p>
                            </div>
                            <div class="photo-item">
                                <img src="./web-images/notif3.jpg" alt="Connection">
                                <p style="font-size: 0.7rem; text-align: center; margin-top: 5px;">Aguilus Peregrin</p>
                            </div>
                            <div class="photo-item">
                                <img src="./web-images/pfp.jpg" alt="Connection">
                                <p style="font-size: 0.7rem; text-align: center; margin-top: 5px;">Lance Oboza</p>
                            </div>
                            <div class="photo-item">
                                <img src="./web-images/notif1.jpg" alt="Connection">
                                <p style="font-size: 0.7rem; text-align: center; margin-top: 5px;">Mark Cruz</p>
                            </div>
                        </div>
                        <a href="#" class="see-all">See All Connections</a>
                    </div>
                </div> -->
            </div>
            
            <!-- Right Column - Composer + Activity Feed -->
            <div class="profile-main-column">
                <!-- 1. What's on your mind Composer Container (Top Container) -->
                <div class="profile-create-post">
                    <div class="profile-create-post-input-area" id="profileCreatePostTrigger">
                        <div class="profile-post-avatar">
                            <?php if (!empty($user['profile_picture'])): ?>
                                <img src="<?php echo $user['profile_picture']; ?>" alt="Profile Picture">
                            <?php else: ?>
                                <?php echo getInitialsHtml($user['first_name'], $user['last_name'], 40); ?>
                            <?php endif; ?>
                        </div>
                        <input type="text" placeholder="What's on your mind, <?php echo htmlspecialchars($user['first_name']); ?>?" readonly style="cursor:pointer;">
                    </div>
                    <div class="profile-post-options">
                        <div class="profile-post-option" id="profilePhotoOption">
                            <i class="bi bi-plus-square-dotted" style="color: teal;"></i>
                            <span>Photo/Image</span>
                        </div>
                        <div class="profile-post-option" id="profileFeelingOption">
                            <i class="fa-regular fa-face-smile-beam" style="color: teal; vertical-align: middle;"></i>
                            <span>Feeling/Activity</span>
                        </div>
                    </div>
                </div>

                <!-- 2. Your Activity Container (Below Composer) -->
                <div class="activity-feed">
                    <div class="activity-feed-header-card">
                        <h2 class="feed-title">Your Activity</h2>
                    </div>

                    <!-- Display user posts -->
                    <div class="feeds">
                        <?php if (empty($posts)): ?>
                            <div class="empty-feed">
                                <p>You haven't posted anything yet.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($posts as $post): ?>
                            <div class="feed post-item" data-post-id="<?php echo $post['id']; ?>" data-status="<?php echo htmlspecialchars($post['status'] ?? 'posted'); ?>">
                                <div class="post-header">
                                    <div class="post-avatar">
                                        <?php if (!empty($post['profile_picture'])): ?>
                                            <img src="<?php echo $post['profile_picture']; ?>">
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
                                            <i class="bi bi-globe"></i> BondNest &middot; 
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
                                <div class="post-media" style="border-radius: 10px !important; overflow: hidden !important;">
                                    <img src="<?php echo $post['image_path']; ?>" style="width: 100%; height: auto; border-radius: 10px !important;">
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
                        <?php endif; ?>
                    </div> <!-- Closing feeds div -->
                </div> <!-- Closing activity-feed div -->
            </div> <!-- Closing profile-main-column div -->
        </div> <!-- Closing profile-content div -->
    </div> <!-- Closing profile-app div -->

    <!-- Create Post Modal -->
    <div class="modal-container" id="createPostModal">
        <div class="modal-backdrop" style="position:fixed; top:0; left:0; width:100vw; height:100vh; cursor:pointer; z-index:1000;" onclick="document.getElementById('createPostModal').style.display='none';"></div>
        <form id="postForm" method="POST" enctype="multipart/form-data" action="create_post.php" style="position:relative; z-index:1001;">
            <div class="modal-content">
                <div class="modal-header">
                    <h2>Create post</h2>
                    <span class="close-button" id="createPostCloseBtn">&times;</span>
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
                            <label for="post-image" style="cursor: pointer; margin: 0; display: flex; align-items: center;">
                                <i class="bi bi-plus-square-dotted" style="color: teal;"></i>
                            </label>
                            <input type="file" id="post-image" name="post-image" accept="image/*" style="display: none;">
                            <i class="fa-regular fa-face-smile-beam" style="color: teal;"></i>
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

    <div class="error-notification" id="neggyErrorNotification">
        <div class="notification-icon">
            <i class="fas fa-exclamation-circle"></i>
        </div>
        <div class="notification-text">
            <h3>Neggy Says...</h3>
            <p id="neggyErrorMessage">Bio must be at least 15 characters long</p>
        </div>
    </div>
</div>

<!-- Comment Modal -->
<div class="comment-modal" id="commentModal" style="display:none;">
    <div class="comment-modal-content">
        <div class="comment-header">
            <h3>Comments</h3>
            <span class="close-comment" id="closeCommentModal">&times;</span>
        </div>
        <div class="comments-container">
            <div class="comment-list" id="commentList"></div>
            <form id="commentForm" method="POST" class="comment-form-fixed">
                <div class="reply-indicator" id="replyIndicator" style="display: none;">
                    <span>Replying to <strong id="replyToName"></strong></span>
                    <button type="button" class="cancel-reply" id="cancelReply">&times;</button>
                </div>
                <input type="hidden" name="parent_id" id="replyParentId" value="">
                <input type="hidden" name="post_id" value="">
                <textarea class="comment-input" placeholder="Write a comment..." name="comment-content" required rows="2" maxlength="1000"></textarea>
                <button type="submit" class="btn btn-primary">Post Comment</button>
            </form>
        </div>
    </div>
</div>

<!-- Delete Comment Confirmation Modal -->
<div class="logout-confirmation-overlay" id="deleteCommentConfirmationOverlay" style="display:none;">
    <div class="logout-confirmation-container delete-modal" style="border-top: 4px solid var(--color-primary);">
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
<div class="edit-post-modal" id="editCommentModal" style="display:none;">
    <div class="edit-post-modal-content">
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

<!-- Edit Post Modal -->
<div class="edit-post-modal" id="editPostModal" style="display:none;">
    <div class="edit-post-modal-content" style="position:relative; z-index:1001; background:white; border-radius:10px; max-width:600px; width:90%; max-height:80vh; overflow:hidden; box-shadow: 0 5px 15px rgba(0,0,0,0.3);">
        <div class="edit-post-header">
            <h2>Edit Post</h2>
            <span class="edit-post-close" id="closeEditPost">&times;</span>
        </div>
        <div class="edit-post-body">
            <textarea id="editPostContent" placeholder="Edit your post..."></textarea>
            <img src="" class="edit-image-preview" id="editImagePreview" style="display:none; max-width:100%; max-height:200px; border-radius:8px; margin-top:10px;">
            <div class="add-to-post">
                <span>Change image</span>
                <div class="icons">
                    <label for="editPostImage" style="cursor: pointer;">
                        <i class="bi bi-image" style="color: lightgreen;"></i>
                    </label>
                    <input type="file" id="editPostImage" name="editPostImage" accept="image/*" style="display: none;">
                    <button class="btn btn-danger" id="removePostImage" style="display:none;">Remove Image</button>
                </div>
            </div>
        </div>
        <div class="edit-post-actions">
            <button class="btn btn-secondary" id="cancelEditPost">Cancel</button>
            <button class="btn btn-primary" id="saveEditPost">Save Changes</button>
        </div>
    </div>
</div>

<!-- Delete Post Confirmation Modal -->
<div class="logout-confirmation-overlay" id="deletePostConfirmationOverlay" style="display:none;">
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

<script>
// TIME AGO - same as homepage.php
function formatTimeAgo(dateString) {
    let dStr = dateString;
    if (dStr && !dStr.includes('Z') && !dStr.includes('+') && !dStr.match(/T.*[+-]/)) {
        dStr = dStr.replace(' ', 'T') + 'Z';
    }
    const date = new Date(dStr);
    const now = new Date();
    const secondsPast = (now - date) / 1000;

    if (secondsPast < 1) return 'just now';
    if (secondsPast < 60) return `${Math.round(secondsPast)} seconds ago`;
    if (secondsPast < 3600) return `${Math.round(secondsPast / 60)} minutes ago`;
    if (secondsPast < 86400) return `${Math.round(secondsPast / 3600)} hours ago`;
    if (secondsPast < 604800) return `${Math.round(secondsPast / 86400)} days ago`;
    if (secondsPast < 2419200) return `${Math.round(secondsPast / 604800)} weeks ago`;
    if (secondsPast < 29030400) return `${Math.round(secondsPast / 2419200)} months ago`;
    return `${Math.round(secondsPast / 29030400)} years ago`;
}

function updateAllTimeAgo() {
    document.querySelectorAll('.time-ago').forEach(el => {
        const ts = el.getAttribute('data-timestamp') || el.dataset.originalDate;
        if (ts) el.textContent = formatTimeAgo(ts);
    });
}

document.addEventListener('DOMContentLoaded', function() {
    updateAllTimeAgo();
    setInterval(updateAllTimeAgo, 60000);
});

// ==================== LOGOUT ====================
document.addEventListener('DOMContentLoaded', function() {
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

// ==================== LIKE BUTTON ====================
document.addEventListener('click', async function(e) {
    const likeButton = e.target.closest('.like-button');
    if (likeButton) {
        e.preventDefault();
        if (likeButton.classList.contains('processing')) return;
        likeButton.classList.add('processing');

        const postId = likeButton.dataset.postId;
        const isLiked = likeButton.dataset.liked === 'true';
        const action = isLiked ? 'unlike' : 'like';

        const heartIcon = likeButton.querySelector('i');
        const likeText = likeButton.querySelector('span');
        const likeCountElement = likeButton.querySelector('.like-count');
        const likedBySection = likeButton.closest('.feed').querySelector('.liked-by');

        // Optimistic UI update
        if (isLiked) {
            heartIcon.classList.remove('bi-heart-fill');
            heartIcon.classList.add('bi-heart');
            likeText.textContent = 'Like';
            likeButton.dataset.liked = 'false';
            likeCountElement.textContent = parseInt(likeCountElement.textContent) - 1;
        } else {
            heartIcon.classList.remove('bi-heart');
            heartIcon.classList.add('bi-heart-fill');
            likeText.textContent = 'Liked';
            likeButton.dataset.liked = 'true';
            likeCountElement.textContent = parseInt(likeCountElement.textContent) + 1;
        }

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

        try {
            const response = await fetch('like_post.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `post_id=${postId}&action=${action}`
            });
            if (!response.ok) throw new Error(`Server error: ${response.status}`);
            const data = await response.json();
            if (data.success) {
                likeCountElement.textContent = data.likes;
                likeButton.style.transform = 'scale(1.2)';
                setTimeout(() => { likeButton.style.transform = ''; }, 300);
            } else {
                throw new Error(data.error || 'Unknown error');
            }
        } catch (error) {
            console.error('Error:', error);
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
            setTimeout(() => { likeButton.classList.remove('processing'); }, 300);
        }
    }
});

// ==================== POST 3-DOT MENU ====================
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('post-menu-trigger')) {
        const postId = e.target.dataset.postId;
        const dropdown = document.querySelector(`.post-menu-dropdown[data-post-id="${postId}"]`);
        document.querySelectorAll('.post-menu-dropdown').forEach(d => {
            if (d !== dropdown) d.classList.remove('show');
        });
        if (dropdown) dropdown.classList.toggle('show');
        e.stopPropagation();
    }
    if (!e.target.closest('.post-actions-menu')) {
        document.querySelectorAll('.post-menu-dropdown').forEach(d => d.classList.remove('show'));
    }
});

// ==================== DELETE POST ====================
let postToDelete = null;

document.addEventListener('click', async function(e) {
    if (e.target.classList.contains('delete-post') || e.target.closest('.delete-post')) {
        e.preventDefault();
        e.stopPropagation();

        const postId = e.target.dataset.postId || e.target.closest('.delete-post').dataset.postId;
        postToDelete = postId;

        const deleteOverlay = document.getElementById('deletePostConfirmationOverlay');
        if (!deleteOverlay) { console.error('Delete post modal not found!'); return; }

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
        void deleteOverlay.offsetWidth;
    }
});

document.getElementById('confirmDeletePost').addEventListener('click', async function(e) {
    e.preventDefault();
    e.stopPropagation();
    if (!postToDelete) return;

    const deleteButton = this;
    const originalText = deleteButton.textContent;
    deleteButton.textContent = 'Deleting...';
    deleteButton.disabled = true;

    try {
        const formData = new URLSearchParams();
        formData.append('post_id', postToDelete);
        const response = await fetch('delete_post.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData.toString()
        });
        if (!response.ok) throw new Error(`Server responded with status: ${response.status}`);
        const responseText = await response.text();
        let data;
        try { data = JSON.parse(responseText); } catch(pe) { throw new Error('Server returned invalid JSON'); }

        if (data.success) {
            const deleteOverlay = document.getElementById('deletePostConfirmationOverlay');
            if (deleteOverlay) { deleteOverlay.style.display = 'none'; document.body.style.overflow = 'auto'; }
            window.location.reload();
        } else {
            alert('Error: ' + (data.error || 'Failed to delete'));
        }
    } catch (error) {
        console.error('Error deleting post:', error);
        alert('An error occurred while deleting the post: ' + error.message);
    } finally {
        deleteButton.textContent = originalText;
        deleteButton.disabled = false;
        postToDelete = null;
    }
});

document.getElementById('cancelDeletePost').addEventListener('click', function(e) {
    e.preventDefault();
    e.stopPropagation();
    const deleteOverlay = document.getElementById('deletePostConfirmationOverlay');
    if (deleteOverlay) deleteOverlay.style.display = 'none';
    postToDelete = null;
});

document.getElementById('deletePostConfirmationOverlay').addEventListener('click', function(e) {
    if (e.target === this) { this.style.display = 'none'; postToDelete = null; }
});

// ==================== EDIT POST ====================
let currentEditingPostId = null;

document.addEventListener('click', async function(e) {
    if (e.target.classList.contains('edit-post') || e.target.closest('.edit-post')) {
        e.preventDefault();
        e.stopPropagation();

        const postId = e.target.dataset.postId || e.target.closest('.edit-post').dataset.postId;
        currentEditingPostId = postId;

        try {
            const response = await fetch(`get_post_for_edit.php?post_id=${postId}`);
            const data = await response.json();
            if (data.success) {
                const modal = document.getElementById('editPostModal');
                const content = document.getElementById('editPostContent');
                const imagePreview = document.getElementById('editImagePreview');
                if (!modal || !content || !imagePreview) { alert('Error: Could not find edit post form elements.'); return; }

                content.value = data.post.content;
                if (data.post.image_path) {
                    imagePreview.src = data.post.image_path;
                    imagePreview.style.display = 'block';
                    document.getElementById('removePostImage').style.display = 'inline-block';
                } else {
                    imagePreview.style.display = 'none';
                    document.getElementById('removePostImage').style.display = 'none';
                }

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
                void modal.offsetWidth;
            } else {
                alert('Error: ' + (data.error || 'Could not load post'));
            }
        } catch (error) {
            console.error('Error:', error);
            alert('An error occurred while loading the post');
        }
    }
});

document.getElementById('closeEditPost').addEventListener('click', function() {
    document.getElementById('editPostModal').style.display = 'none';
});
document.getElementById('cancelEditPost').addEventListener('click', function() {
    document.getElementById('editPostModal').style.display = 'none';
});
document.getElementById('editPostModal').addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});

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

document.getElementById('removePostImage').addEventListener('click', function() {
    document.getElementById('editImagePreview').src = '';
    document.getElementById('editImagePreview').style.display = 'none';
    document.getElementById('editPostImage').value = '';
    this.style.display = 'none';
});

document.getElementById('saveEditPost').addEventListener('click', async function() {
    const content = document.getElementById('editPostContent').value.trim();
    const imageFile = document.getElementById('editPostImage').files[0];
    const hasImageShowing = document.getElementById('editImagePreview').style.display !== 'none';
    const removeImage = document.getElementById('editImagePreview').style.display === 'none';

    if (!content && !imageFile && !hasImageShowing) {
        alert('Please add some text or an image to your post.');
        return;
    }

    const saveButton = this;
    const originalText = saveButton.textContent;
    saveButton.textContent = 'Saving...';
    saveButton.disabled = true;

    const formData = new FormData();
    formData.append('post_id', currentEditingPostId);
    formData.append('content', content);
    if (imageFile) formData.append('image', imageFile);
    formData.append('remove_image', removeImage);

    try {
        const response = await fetch('update_post.php', { method: 'POST', body: formData });
        const data = await response.json();
        if (data.success) {
            document.getElementById('editPostModal').style.display = 'none';
            window.location.reload();
        } else {
            alert('Error: ' + (data.error || 'Failed to update post'));
        }
    } catch (error) {
        console.error('Error:', error);
        alert('An error occurred while updating the post');
    } finally {
        saveButton.textContent = originalText;
        saveButton.disabled = false;
    }
});

// ==================== COMMENTS ====================
let currentPostId = null;

document.addEventListener('click', function(e) {
    const commentTrigger = e.target.closest('.comment-trigger');
    if (commentTrigger) {
        e.preventDefault();
        currentPostId = commentTrigger.dataset.postId;

        const commentModal = document.getElementById('commentModal');
        if (!commentModal) { console.error('Comment modal not found!'); return; }

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
            const commentsContainer = modalContent.querySelector('.comments-container');
            if (commentsContainer) {
                commentsContainer.style.cssText = `flex: 1 !important; overflow: hidden !important; display: flex !important; flex-direction: column !important; background: transparent !important;`;
            }
            const commentList = modalContent.querySelector('.comment-list');
            if (commentList) {
                commentList.style.cssText = `flex: 1 !important; overflow-y: auto !important; padding: 0 15px !important; margin-bottom: 60px !important; background: transparent !important;`;
            }
        }

        void commentModal.offsetWidth;
        document.body.style.overflow = 'hidden';
        loadComments(currentPostId);
    }
});

document.querySelector('.close-comment').addEventListener('click', () => {
    const commentModal = document.getElementById('commentModal');
    if (commentModal) commentModal.style.cssText = `display: none !important;`;
    document.body.style.overflow = 'auto';
    setTimeout(() => {
        const commentList = document.getElementById('commentList');
        if (commentList) commentList.innerHTML = '';
    }, 300);
});

document.addEventListener('click', function(e) {
    const commentModal = document.getElementById('commentModal');
    if (commentModal && e.target === commentModal) {
        commentModal.style.cssText = `display: none !important;`;
        document.body.style.overflow = 'auto';
        setTimeout(() => {
            const commentList = document.getElementById('commentList');
            if (commentList) commentList.innerHTML = '';
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
}

function clearReply() {
    currentReplyToId = null;
    currentReplyToName = null;
    document.getElementById('replyIndicator').style.display = 'none';
    document.getElementById('replyParentId').value = '';
}

document.getElementById('cancelReply')?.addEventListener('click', clearReply);

// Comment form submit - post to add_comment.php
document.getElementById('commentForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.target;
    const textarea = form.querySelector('textarea');
    const content = textarea ? textarea.value.trim() : '';
    if (!content) return;

    const formData = new FormData();
    formData.append('post_id', currentPostId);
    formData.append('content', content);
    if (currentReplyToId) {
        formData.append('parent_id', currentReplyToId);
    }

    const submitBtn = form.querySelector('button[type="submit"]');
    const origText = submitBtn.textContent;
    submitBtn.disabled = true;
    submitBtn.textContent = 'Posting...';

    try {
        const response = await fetch('add_comment.php', { method: 'POST', body: formData });
        if (!response.ok) throw new Error('Network response was not ok');
        const data = await response.json();
        if (data.success) {
            if (textarea) textarea.value = '';
            clearReply();
            const commentCountElement = document.querySelector(`[data-post-id="${currentPostId}"] .comment-count`);
            if (commentCountElement) commentCountElement.textContent = data.new_count;
            loadComments(currentPostId);
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Error posting comment. Please try again.');
    } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = origText;
    }
});

function loadComments(postId) {
    const commentList = document.getElementById('commentList');
    if (!commentList) { console.error('Comment list element not found!'); return; }
    commentList.innerHTML = '<div class="loading-comments" style="text-align: center; padding: 20px;">Loading comments...</div>';

    fetch(`get_comments.php?post_id=${postId}`)
        .then(response => {
            if (!response.ok) throw new Error(`Server responded with status: ${response.status}`);
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                return response.text().then(text => { throw new Error(`Invalid response format: ${contentType}`); });
            }
            return response.json();
        })
        .then(data => {
            if (data.error) throw new Error(data.error);
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
                    commentList.appendChild(createCommentElement(comment));
                } catch(err) {
                    console.error('Error rendering comment', comment.id, err);
                }
            });
        })
        .catch(error => {
            console.error('Error loading comments:', error);
            commentList.innerHTML = `<div style="text-align: center; padding: 30px; color: #e74c3c;"><p>Error loading comments: ${error.message}</p><button onclick="loadComments('${postId}')" style="padding: 8px 16px; background: #3498db; color: white; border: none; border-radius: 4px; cursor: pointer;">Try Again</button></div>`;
        });
}

function createCommentElement(comment) {
    const formattedContent = (comment.content || '').replace(/\r\n|\r|\n/g, '<br>');
    const isCommentAuthor = <?php echo (int)($_SESSION['user_id'] ?? 0); ?> === parseInt(comment.user_id);

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
        commentText.style.cssText = `position: relative !important; background: #f0f2f5 !important; padding: 8px 12px !important; border-radius: 18px !important; word-wrap: break-word !important; overflow-wrap: break-word !important; width: fit-content !important; max-width: 100% !important; margin: 2px 0 !important; line-height: 1.4 !important; box-shadow: 0 1px 2px rgba(0,0,0,0.05) !important;`;
    }
    const commentContent = div.querySelector('.comment-content');
    if (commentContent) {
        commentContent.style.cssText = `display: flex !important; align-items: flex-start !important; width: 100% !important; background: transparent !important; position: relative !important;`;
    }
    div.style.background = 'transparent';
    const commentBody = div.querySelector('.comment-body');
    if (commentBody) {
        commentBody.style.cssText = `flex: 1 !important; max-width: calc(100% - 50px) !important; display: flex !important; flex-direction: column !important; background: transparent !important;`;
    }

    // Reply button click
    const replyBtn = div.querySelector('.reply-btn');
    if (replyBtn) {
        replyBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            setReplyTo(comment.id, comment.first_name + ' ' + comment.last_name);
            document.querySelector('#commentForm textarea')?.focus();
        });
    }

    const menuTrigger = div.querySelector('.comment-menu-trigger');
    if (menuTrigger) {
        menuTrigger.addEventListener('mouseenter', () => { menuTrigger.style.backgroundColor = '#f0f0f0'; });
        menuTrigger.addEventListener('mouseleave', () => { menuTrigger.style.backgroundColor = 'transparent'; });
        menuTrigger.addEventListener('click', (e) => {
            e.stopPropagation();
            document.querySelectorAll('.floating-comment-menu').forEach(menu => {
                if (document.body.contains(menu)) document.body.removeChild(menu);
            });

            const floatingMenu = document.createElement('div');
            floatingMenu.className = 'floating-comment-menu';
            floatingMenu.dataset.commentId = comment.id;
            floatingMenu.style.cssText = `position: fixed; background: white; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.2); z-index: 9999; min-width: 180px; padding: 5px 0;`;
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
                document.dispatchEvent(new CustomEvent('edit-comment-clicked', { detail: { commentId: comment.id } }));
                if (document.body.contains(floatingMenu)) document.body.removeChild(floatingMenu);
            });
            floatingMenu.querySelector('.delete-comment').addEventListener('click', () => {
                document.dispatchEvent(new CustomEvent('delete-comment-clicked', { detail: { commentId: comment.id } }));
                if (document.body.contains(floatingMenu)) document.body.removeChild(floatingMenu);
            });

            setTimeout(() => {
                const closeMenu = (ev) => {
                    if (!floatingMenu.contains(ev.target) && ev.target !== menuTrigger) {
                        if (document.body.contains(floatingMenu)) document.body.removeChild(floatingMenu);
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

// ==================== COMMENT EDIT/DELETE EVENTS ====================
let commentToEdit = null;
let commentToDelete = null;

document.addEventListener('edit-comment-clicked', function(e) {
    const commentId = e.detail.commentId;
    const commentItem = document.querySelector(`.comment-item[data-comment-id="${commentId}"]`);
    if (!commentItem) return;
    const commentText = commentItem.querySelector('.comment-text').innerHTML;
    commentToEdit = commentId;
    const editCommentModal = document.getElementById('editCommentModal');
    if (!editCommentModal) { alert('Error: Could not find the edit comment modal.'); return; }
    const editCommentContent = document.getElementById('editCommentContent');
    if (!editCommentContent) { alert('Error: Could not find the edit comment textarea.'); return; }
    const cleanText = commentText.replace(/<br\s*\/?>/gi, '\n').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');
    editCommentContent.value = cleanText;
    editCommentModal.style.cssText = `display: flex !important; z-index: 10000 !important; position: fixed !important; top: 0 !important; left: 0 !important; width: 100% !important; height: 100% !important; background: rgba(0, 0, 0, 0.7) !important; backdrop-filter: blur(4px) !important; justify-content: center !important; align-items: center !important;`;
    void editCommentModal.offsetWidth;
});

document.addEventListener('delete-comment-clicked', function(e) {
    const commentId = e.detail.commentId;
    commentToDelete = commentId;
    const deleteOverlay = document.getElementById('deleteCommentConfirmationOverlay');
    if (!deleteOverlay) { alert('Error: Could not find the delete comment modal.'); return; }
    deleteOverlay.style.cssText = `display: flex !important; z-index: 10000 !important; position: fixed !important; top: 0 !important; left: 0 !important; width: 100% !important; height: 100% !important; background: rgba(0, 0, 0, 0.7) !important; backdrop-filter: blur(4px) !important; justify-content: center !important; align-items: center !important;`;
    document.body.style.overflow = 'hidden';
});

document.getElementById('closeEditComment').addEventListener('click', function(e) {
    e.preventDefault(); e.stopPropagation();
    document.getElementById('editCommentModal').style.display = 'none';
    commentToEdit = null;
});
document.getElementById('cancelEditComment').addEventListener('click', function(e) {
    e.preventDefault(); e.stopPropagation();
    document.getElementById('editCommentModal').style.display = 'none';
    commentToEdit = null;
});

document.getElementById('saveEditComment').addEventListener('click', async function(e) {
    e.preventDefault(); e.stopPropagation();
    if (!commentToEdit) { alert('Error: No comment selected for editing.'); return; }
    const content = document.getElementById('editCommentContent').value.trim();
    if (!content) { alert('Comment cannot be empty'); return; }

    const saveButton = this;
    const originalText = saveButton.textContent;
    saveButton.textContent = 'Saving...';
    saveButton.disabled = true;

    try {
        const formData = new URLSearchParams();
        formData.append('comment_id', commentToEdit);
        formData.append('content', content);
        const response = await fetch('update_comment.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData.toString()
        });
        if (!response.ok) throw new Error(`Server responded with status: ${response.status}`);
        const responseText = await response.text();
        let data;
        try { data = JSON.parse(responseText); } catch(pe) { throw new Error('Server returned invalid JSON'); }

        if (data.success) {
            const commentItem = document.querySelector(`.comment-item[data-comment-id="${commentToEdit}"]`);
            if (commentItem) {
                const commentTextElement = commentItem.querySelector('.comment-text');
                if (commentTextElement) commentTextElement.innerHTML = content.replace(/\r\n|\r|\n/g, '<br>');
            }
            document.getElementById('editCommentModal').style.display = 'none';
            commentToEdit = null;
        } else {
            alert('Error: ' + (data.error || 'Failed to update comment'));
        }
    } catch (error) {
        console.error('Error updating comment:', error);
        alert('An error occurred while updating the comment: ' + error.message);
    } finally {
        saveButton.textContent = originalText;
        saveButton.disabled = false;
    }
});

document.getElementById('cancelDeleteComment').addEventListener('click', function(e) {
    e.preventDefault(); e.stopPropagation();
    const deleteOverlay = document.getElementById('deleteCommentConfirmationOverlay');
    if (deleteOverlay) { deleteOverlay.style.display = 'none'; document.body.style.overflow = 'auto'; }
    commentToDelete = null;
});

document.getElementById('confirmDeleteComment').addEventListener('click', async function(e) {
    e.preventDefault(); e.stopPropagation();
    if (!commentToDelete) return;

    const deleteButton = this;
    const originalText = deleteButton.textContent;
    deleteButton.textContent = 'Deleting...';
    deleteButton.disabled = true;

    try {
        const formData = new URLSearchParams();
        formData.append('comment_id', commentToDelete);
        const response = await fetch('delete_comment.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData.toString()
        });
        if (!response.ok) throw new Error(`Server responded with status: ${response.status}`);
        const data = await response.json();

        if (data.success) {
            const deleteOverlay = document.getElementById('deleteCommentConfirmationOverlay');
            if (deleteOverlay) { deleteOverlay.style.display = 'none'; document.body.style.overflow = 'auto'; }

            const commentItem = document.querySelector(`.comment-item[data-comment-id="${commentToDelete}"]`);
            if (commentItem) {
                commentItem.classList.add('comment-item-deleting');
                setTimeout(() => {
                    if (commentItem && commentItem.parentNode) commentItem.parentNode.removeChild(commentItem);
                    const commentCountElement = document.querySelector(`[data-post-id="${currentPostId}"] .comment-count`);
                    if (commentCountElement) {
                        const newCount = parseInt(commentCountElement.textContent) - 1;
                        commentCountElement.textContent = newCount > 0 ? newCount : '0';
                    }
                    const commentList = document.getElementById('commentList');
                    if (commentList && commentList.children.length === 0) {
                        commentList.innerHTML = '<div class="no-comments">No comments yet. Be the first to comment!</div>';
                    }
                }, 400);
            } else {
                if (currentPostId) loadComments(currentPostId);
            }
        } else {
            console.error('Server reported error:', data.error);
        }
    } catch (error) {
        console.error('Error deleting comment:', error);
    } finally {
        deleteButton.textContent = originalText;
        deleteButton.disabled = false;
        commentToDelete = null;
    }
});

document.getElementById('deleteCommentConfirmationOverlay').addEventListener('click', function(e) {
    if (e.target === this) { this.style.display = 'none'; commentToDelete = null; }
});

// Move modals to body for proper z-index
document.addEventListener('DOMContentLoaded', function() {
    ['editCommentModal','editPostModal','deleteCommentConfirmationOverlay','deletePostConfirmationOverlay','commentModal'].forEach(modalId => {
        const modal = document.getElementById(modalId);
        if (modal) document.body.appendChild(modal);
    });
});
</script>

<!-- Post Status Updates Scripts -->
<script>
function updateAllTimeAgo() {
    document.querySelectorAll('.time-ago').forEach(element => {
        const dateString = element.getAttribute('data-timestamp') || element.dataset.originalDate;
        if (dateString) {
            element.textContent = formatTimeAgo(dateString);
        }
    });
}

function timeElapsedString(dateString) {
    return formatTimeAgo(dateString);
}

// Global Tracking Variables (reuse declarations from first script block)

// ==================== DOM READY INITIALIZATION ====================
document.addEventListener('DOMContentLoaded', function() {
    // 1. Initial dynamic timestamp update
    updateAllTimeAgo();
    setInterval(updateAllTimeAgo, 60000);

    // 2. Sidebar active navigation
    const menuItems = document.querySelectorAll('.left .sidebar .menu-item');
    menuItems.forEach(item => {
        item.addEventListener('click', function() {
            menuItems.forEach(i => i.classList.remove('active'));
            this.classList.add('active');
        });
    });

    // 3. Logout modal
    const logoutBtn = document.getElementById('logoutButton');
    const logoutModal = document.getElementById('logoutConfirmationOverlay');
    const cancelLogoutBtn = document.getElementById('cancelLogout');
    if (logoutBtn && logoutModal) {
        logoutBtn.addEventListener('click', function(e) {
            e.preventDefault();
            logoutModal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        });
    }
    if (cancelLogoutBtn && logoutModal) {
        cancelLogoutBtn.addEventListener('click', function(e) {
            e.preventDefault();
            logoutModal.style.display = 'none';
            document.body.style.overflow = '';
        });
    }
    if (logoutModal) {
        logoutModal.addEventListener('click', function(e) {
            if (e.target === logoutModal) {
                logoutModal.style.display = 'none';
                document.body.style.overflow = '';
            }
        });
    }

    // 4. Move floating modals to body for proper overlay
    ['commentModal', 'deleteCommentConfirmationOverlay', 'editCommentModal', 'editPostModal', 'deletePostConfirmationOverlay'].forEach(id => {
        const el = document.getElementById(id);
        if (el && el.parentNode !== document.body) {
            document.body.appendChild(el);
        }
    });
});

// ==================== GLOBAL CLICK HANDLER FOR POSTS & MENUS ====================
document.addEventListener('click', async function(e) {
    // A. 3-Dots Post Menu Trigger (Toggle dropdown)
    if (e.target.classList.contains('post-menu-trigger') || e.target.closest('.post-menu-trigger')) {
        const trigger = e.target.classList.contains('post-menu-trigger') ? e.target : e.target.closest('.post-menu-trigger');
        const postId = trigger.dataset.postId;
        const dropdown = document.querySelector(`.post-menu-dropdown[data-post-id="${postId}"]`);
        
        document.querySelectorAll('.post-menu-dropdown').forEach(d => {
            if (d !== dropdown) d.classList.remove('show');
        });
        
        if (dropdown) {
            dropdown.classList.toggle('show');
        }
        e.stopPropagation();
        return;
    }

    // Close post dropdowns when clicking outside
    if (!e.target.closest('.post-actions-menu')) {
        document.querySelectorAll('.post-menu-dropdown.show').forEach(d => {
            d.classList.remove('show');
        });
    }

    // B. Like Button
    if (e.target.closest('.like-button')) {
        const likeButton = e.target.closest('.like-button');
        const postId = likeButton.dataset.postId;
        const isLiked = likeButton.dataset.liked === 'true';
        const likeIcon = likeButton.querySelector('i');
        const likeText = likeButton.querySelector('span');
        const likeCount = likeButton.querySelector('.like-count');
        
        likeButton.style.pointerEvents = 'none';
        likeButton.classList.add('processing');
        
        likeButton.dataset.liked = isLiked ? 'false' : 'true';
        likeIcon.className = isLiked ? 'bi bi-heart' : 'bi bi-heart-fill';
        likeText.textContent = isLiked ? 'Like' : 'Liked';
        
        let count = parseInt(likeCount.textContent) || 0;
        count = isLiked ? Math.max(0, count - 1) : count + 1;
        likeCount.textContent = count;
        
        const likedByP = likeButton.closest('.feed').querySelector('.liked-by p');
        if (likedByP) {
            likedByP.textContent = count > 0 
                ? (count === 1 ? '1 person liked this' : `${count} people liked this`)
                : 'Be the first to like this';
        }
        
        try {
            const res = await fetch('like_post.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `post_id=${postId}&action=${isLiked ? 'unlike' : 'like'}`
            });
            const data = await res.json();
            if (data.success && data.likes !== undefined) {
                likeCount.textContent = data.likes;
                if (likedByP) {
                    likedByP.textContent = data.likes > 0 
                        ? (data.likes === 1 ? '1 person liked this' : `${data.likes} people liked this`)
                        : 'Be the first to like this';
                }
            }
        } catch (err) {
            console.error('Error liking post:', err);
        } finally {
            likeButton.style.pointerEvents = '';
            likeButton.classList.remove('processing');
        }
        return;
    }

    // C. Comment Trigger Button (Open Comment Modal)
    if (e.target.closest('.comment-trigger')) {
        e.preventDefault();
        const btn = e.target.closest('.comment-trigger');
        const postId = btn.dataset.postId;
        currentPostId = postId;
        
        const commentModal = document.getElementById('commentModal');
        if (commentModal) {
            commentModal.style.cssText = `
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
            
            const postIdInput = commentModal.querySelector('input[name="post_id"]');
            if (postIdInput) postIdInput.value = postId;
            
            loadComments(postId);
            document.body.style.overflow = 'hidden';
        }
        return;
    }

    // Close comment modal
    if (e.target.classList.contains('close-comment') || e.target.closest('.close-comment') || e.target.id === 'commentModal') {
        const commentModal = document.getElementById('commentModal');
        if (commentModal) {
            commentModal.style.display = 'none';
            document.body.style.overflow = '';
        }
        return;
    }

    // D. Edit Post (Open Edit Post Modal)
    if (e.target.classList.contains('edit-post') || e.target.closest('.edit-post')) {
        e.preventDefault();
        e.stopPropagation();
        
        // Close any open dropdowns
        document.querySelectorAll('.post-menu-dropdown.show').forEach(d => d.classList.remove('show'));
        
        const el = e.target.classList.contains('edit-post') ? e.target : e.target.closest('.edit-post');
        const postId = el.dataset.postId;
        currentEditingPostId = postId;
        
        try {
            const res = await fetch(`get_post_for_edit.php?post_id=${postId}`);
            const data = await res.json();
            if (data.success && data.post) {
                const modal = document.getElementById('editPostModal');
                const content = document.getElementById('editPostContent');
                const imagePreview = document.getElementById('editImagePreview');
                const removeImageBtn = document.getElementById('removePostImage');
                
                if (modal && content && imagePreview) {
                    content.value = data.post.content || '';
                    if (data.post.image_path) {
                        imagePreview.src = data.post.image_path;
                        imagePreview.style.display = 'block';
                        if (removeImageBtn) removeImageBtn.style.display = 'inline-block';
                    } else {
                        imagePreview.src = '';
                        imagePreview.style.display = 'none';
                        if (removeImageBtn) removeImageBtn.style.display = 'none';
                    }
                    
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
                    document.body.style.overflow = 'hidden';
                }
            } else {
                alert('Error: ' + (data.error || 'Could not load post data'));
            }
        } catch (err) {
            console.error('Error fetching post for edit:', err);
            alert('An error occurred while loading the post');
        }
        return;
    }

    // Close Edit Post Modal
    if (e.target.id === 'closeEditPost' || e.target.id === 'cancelEditPost' || e.target.id === 'editPostModal') {
        const modal = document.getElementById('editPostModal');
        if (modal) {
            modal.style.display = 'none';
            document.body.style.overflow = '';
        }
        currentEditingPostId = null;
        return;
    }

    // E. Delete Post (Open Delete Post Modal)
    if (e.target.classList.contains('delete-post') || e.target.closest('.delete-post')) {
        e.preventDefault();
        e.stopPropagation();
        
        // Close any open dropdowns
        document.querySelectorAll('.post-menu-dropdown.show').forEach(d => d.classList.remove('show'));
        
        const el = e.target.classList.contains('delete-post') ? e.target : e.target.closest('.delete-post');
        postToDelete = el.dataset.postId;
        
        const deleteOverlay = document.getElementById('deletePostConfirmationOverlay');
        if (deleteOverlay) {
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
            document.body.style.overflow = 'hidden';
        }
        return;
    }

    // Close Delete Post Modal
    if (e.target.id === 'cancelDeletePost' || e.target.id === 'deletePostConfirmationOverlay') {
        const deleteOverlay = document.getElementById('deletePostConfirmationOverlay');
        if (deleteOverlay) {
            deleteOverlay.style.display = 'none';
            document.body.style.overflow = '';
        }
        postToDelete = null;
        return;
    }

    // F. Comment 3-Dots Menu Trigger
    if (e.target.classList.contains('comment-menu-trigger') || e.target.closest('.comment-menu-trigger')) {
        e.preventDefault();
        e.stopPropagation();
        
        const trigger = e.target.classList.contains('comment-menu-trigger') ? e.target : e.target.closest('.comment-menu-trigger');
        const commentId = trigger.dataset.commentId;
        
        // Remove existing floating menus
        document.querySelectorAll('.floating-comment-menu').forEach(m => m.remove());
        
        const menu = document.createElement('div');
        menu.className = 'floating-comment-menu';
        menu.dataset.commentId = commentId;
        menu.style.cssText = `
            position: fixed;
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            z-index: 10001;
            min-width: 160px;
            padding: 5px 0;
        `;
        
        menu.innerHTML = `
            <div class="comment-menu-item edit-comment-btn" style="color: var(--color-primary); padding: 10px 15px; cursor: pointer; display: flex; align-items: center;">
                <i class="bi bi-pencil-fill" style="margin-right: 8px; font-size: 14px;"></i> Edit Comment
            </div>
            <div class="comment-menu-item delete-comment-btn" style="color: #e74c3c; padding: 10px 15px; cursor: pointer; display: flex; align-items: center;">
                <i class="bi bi-trash-fill" style="margin-right: 8px; font-size: 14px;"></i> Delete Comment
            </div>
        `;
        
        const rect = trigger.getBoundingClientRect();
        menu.style.top = (rect.bottom + 5) + 'px';
        menu.style.left = Math.min(rect.left, window.innerWidth - 180) + 'px';
        document.body.appendChild(menu);
        
        menu.querySelector('.edit-comment-btn').addEventListener('click', function() {
            menu.remove();
            openEditCommentModal(commentId);
        });
        
        menu.querySelector('.delete-comment-btn').addEventListener('click', function() {
            menu.remove();
            openDeleteCommentModal(commentId);
        });
        
        setTimeout(() => {
            const closeFloatingMenu = (event) => {
                if (!menu.contains(event.target) && event.target !== trigger) {
                    menu.remove();
                    document.removeEventListener('click', closeFloatingMenu);
                }
            };
            document.addEventListener('click', closeFloatingMenu);
        }, 50);
        return;
    }
});

// ==================== EDIT POST ACTIONS ====================
const editPostImageInput = document.getElementById('editPostImage');
if (editPostImageInput) {
    editPostImageInput.addEventListener('change', function(e) {
        if (this.files && this.files[0]) {
            const reader = new FileReader();
            reader.onload = function(evt) {
                const preview = document.getElementById('editImagePreview');
                const removeBtn = document.getElementById('removePostImage');
                if (preview) {
                    preview.src = evt.target.result;
                    preview.style.display = 'block';
                }
                if (removeBtn) removeBtn.style.display = 'inline-block';
            };
            reader.readAsDataURL(this.files[0]);
        }
    });
}

const removePostImageBtn = document.getElementById('removePostImage');
if (removePostImageBtn) {
    removePostImageBtn.addEventListener('click', function() {
        const preview = document.getElementById('editImagePreview');
        const input = document.getElementById('editPostImage');
        if (preview) { preview.src = ''; preview.style.display = 'none'; }
        if (input) input.value = '';
        this.style.display = 'none';
    });
}

const saveEditPostBtn = document.getElementById('saveEditPost');
if (saveEditPostBtn) {
    saveEditPostBtn.addEventListener('click', async function() {
        if (!currentEditingPostId) return;
        
        const content = document.getElementById('editPostContent').value.trim();
        if (content === '') {
            alert('Post content cannot be empty');
            return;
        }
        
        const imageFile = document.getElementById('editPostImage') ? document.getElementById('editPostImage').files[0] : null;
        const imagePreview = document.getElementById('editImagePreview');
        const removeImage = imagePreview && imagePreview.style.display === 'none';
        
        const formData = new FormData();
        formData.append('post_id', currentEditingPostId);
        formData.append('content', content);
        if (imageFile) formData.append('image', imageFile);
        if (removeImage) formData.append('remove_image', 'true');
        
        this.disabled = true;
        this.textContent = 'Saving...';
        
        try {
            const res = await fetch('update_post.php', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();
            if (data.success) {
                const modal = document.getElementById('editPostModal');
                if (modal) modal.style.display = 'none';
                document.body.style.overflow = '';
                window.location.reload();
            } else {
                alert('Error: ' + (data.error || 'Failed to update post'));
            }
        } catch (err) {
            console.error('Error saving edited post:', err);
            alert('An error occurred while updating the post');
        } finally {
            this.disabled = false;
            this.textContent = 'Save Changes';
        }
    });
}

// ==================== DELETE POST CONFIRMATION ====================
const confirmDeletePostBtn = document.getElementById('confirmDeletePost');
if (confirmDeletePostBtn) {
    confirmDeletePostBtn.addEventListener('click', async function() {
        if (!postToDelete) return;
        
        this.disabled = true;
        this.textContent = 'Deleting...';
        
        try {
            const res = await fetch('delete_post.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `post_id=${postToDelete}`
            });
            const data = await res.json();
            if (data.success || data.status === 'success') {
                const postEl = document.querySelector(`.post-item[data-post-id="${postToDelete}"]`);
                if (postEl) postEl.remove();
                
                const deleteOverlay = document.getElementById('deletePostConfirmationOverlay');
                if (deleteOverlay) deleteOverlay.style.display = 'none';
                document.body.style.overflow = '';
            } else {
                alert('Error: ' + (data.error || data.message || 'Failed to delete post'));
            }
        } catch (err) {
            console.error('Error deleting post:', err);
            alert('An error occurred while deleting the post');
        } finally {
                
                // Close any existing menus
                document.querySelectorAll('.floating-comment-menu').forEach(menu => {
                    if (menu && menu.parentNode) {
                        menu.parentNode.removeChild(menu);
                    }
                });
                
                // Create floating menu
                const floatingMenu = document.createElement('div');
                floatingMenu.className = 'floating-comment-menu';
                floatingMenu.dataset.commentId = commentId;
                
                // Set menu styles
                floatingMenu.style.cssText = `
                    position: fixed !important;
                    background: white !important;
                    border-radius: 8px !important;
                    box-shadow: 0 4px 15px rgba(0,0,0,0.2) !important;
                    z-index: 9999 !important;
                    min-width: 180px !important;
                    padding: 5px 0 !important;
                `;
                
                // Add menu items
                floatingMenu.innerHTML = `
                    <div class="comment-menu-item edit-comment-btn" data-comment-id="${commentId}" style="color: var(--color-primary); padding: 10px 15px; cursor: pointer; display: flex; align-items: center;">
                        <i class="bi bi-pencil-fill" style="margin-right: 8px; font-size: 14px;"></i> Edit Comment
                    </div>
                    <div class="comment-menu-item delete-comment-btn" data-comment-id="${commentId}" style="color: #e74c3c; padding: 10px 15px; cursor: pointer; display: flex; align-items: center;">
                        <i class="bi bi-trash-fill" style="margin-right: 8px; font-size: 14px;"></i> Delete Comment
                    </div>
                `;
                
                // Position the menu
                const triggerRect = trigger.getBoundingClientRect();
                floatingMenu.style.top = (triggerRect.bottom + 5) + 'px';
                floatingMenu.style.left = (triggerRect.left - 5) + 'px';
                
                // Add to document
                document.body.appendChild(floatingMenu);
                
                // Add event handlers directly to the menu items
                const editBtn = floatingMenu.querySelector('.edit-comment-btn');
                const deleteBtn = floatingMenu.querySelector('.delete-comment-btn');
                
                editBtn.addEventListener('click', function() {
                    handleEditComment(commentId);
                    document.body.removeChild(floatingMenu);
                });
                
                deleteBtn.addEventListener('click', function() {
                    handleDeleteComment(commentId);
                    document.body.removeChild(floatingMenu);
                });
                
                // Close menu when clicking outside
                setTimeout(() => {
                    const closeMenu = (e) => {
                        if (!floatingMenu.contains(e.target) && e.target !== trigger) {
                            if (floatingMenu.parentNode) {
                                document.body.removeChild(floatingMenu);
                            }
                            document.removeEventListener('click', closeMenu);
                        }
                    };
                    document.addEventListener('click', closeMenu);
                }, 100);
            }
            
            // Direct handling for edit comment button clicks
            if (e.target.classList.contains('edit-comment-btn') || e.target.closest('.edit-comment-btn')) {
                e.preventDefault();
                e.stopPropagation();
                
                const elem = e.target.classList.contains('edit-comment-btn') ? 
                    e.target : e.target.closest('.edit-comment-btn');
                const commentId = elem.dataset.commentId;
                
                if (commentId) {
                    handleEditComment(commentId);
                }
            }
            
            // Direct handling for delete comment button clicks
            if (e.target.classList.contains('delete-comment-btn') || e.target.closest('.delete-comment-btn')) {
                e.preventDefault();
                e.stopPropagation();
                
                const elem = e.target.classList.contains('delete-comment-btn') ? 
                    e.target : e.target.closest('.delete-comment-btn');
                const commentId = elem.dataset.commentId;
                
                if (commentId) {
                    handleDeleteComment(commentId);
                }
            }
            
            // Handle cancel buttons on modals
            if (e.target.id === 'cancelEditComment' || e.target.id === 'closeEditComment') {
                e.preventDefault();
                const modal = document.getElementById('editCommentModal');
                if (modal) {
                    modal.style.display = 'none';
                    document.body.style.overflow = '';
                }
                window.commentToEdit = null;
            }
            
            if (e.target.id === 'cancelDeleteComment') {
                e.preventDefault();
                const modal = document.getElementById('deleteCommentConfirmationOverlay');
                if (modal) {
                    modal.style.display = 'none';
                    document.body.style.overflow = '';
                }
                window.commentToDelete = null;
            }
        });
        
        // Handle save edit comment action
        const saveEditBtn = document.getElementById('saveEditComment');
        if (saveEditBtn) {
            saveEditBtn.addEventListener('click', async function(e) {
                e.preventDefault();
                
                if (!window.commentToEdit) {
                    console.error('No comment ID for edit');
                    return;
                }
                
                const content = document.getElementById('editCommentContent').value.trim();
                if (!content) {
                    alert('Comment cannot be empty');
                    return;
                }
                
                // Show loading
                this.textContent = 'Saving...';
                this.disabled = true;
                
                try {
                    // Send request to update comment
                    const formData = new FormData();
                    formData.append('comment_id', window.commentToEdit);
                    formData.append('content', content);
                    
                    const response = await fetch('update_comment.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        // Update comment in DOM
                        const commentItem = document.querySelector(`.comment-item[data-comment-id="${window.commentToEdit}"]`);
                        if (commentItem) {
                            const textElem = commentItem.querySelector('.comment-text');
                            if (textElem) {
                                // Format content with <br> tags
                                textElem.innerHTML = content.replace(/\n/g, '<br>');
                            }
                        }
                        
                        // Close modal
                        const modal = document.getElementById('editCommentModal');
                        if (modal) {
                            modal.style.display = 'none';
                            document.body.style.overflow = '';
                        }
                        
                        window.commentToEdit = null;
                    } else {
                        alert('Error: ' + (data.error || 'Unknown error'));
                    }
                } catch (error) {
                    console.error('Error saving comment:', error);
                    alert('Error saving comment. Please try again.');
                } finally {
                    // Reset button
                    this.textContent = 'Save Changes';
                    this.disabled = false;
                }
            });
        }
        
        // Handle confirm delete comment action
        const confirmDeleteBtn = document.getElementById('confirmDeleteComment');
        if (confirmDeleteBtn) {
            confirmDeleteBtn.addEventListener('click', async function(e) {
                e.preventDefault();
                
                if (!window.commentToDelete) {
                    console.error('No comment ID for delete');
                    return;
                }
                
                // Show loading
                this.textContent = 'Deleting...';
                this.disabled = true;
                
                try {
                    // Send request to delete comment
                    const formData = new FormData();
                    formData.append('comment_id', window.commentToDelete);
                    
                    const response = await fetch('delete_comment.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        // Remove comment from DOM
                        const commentItem = document.querySelector(`.comment-item[data-comment-id="${window.commentToDelete}"]`);
                        if (commentItem && commentItem.parentNode) {
                            commentItem.parentNode.removeChild(commentItem);
                        }
                        
                        // Update comment count if needed
                        const countElem = document.querySelector(`[data-post-id="${window.currentPostId}"] .comment-count`);
                        if (countElem) {
                            const newCount = Math.max(0, parseInt(countElem.textContent) - 1);
                            countElem.textContent = newCount;
                        }
                        
                        // Close modal
                        const modal = document.getElementById('deleteCommentConfirmationOverlay');
                        if (modal) {
                            modal.style.display = 'none';
                            document.body.style.overflow = '';
                        }
                        
                        window.commentToDelete = null;
                    } else {
                        console.error('Error:', data.error || 'Unknown error');
                        // Removed alert - just log the error
                    }
                } catch (error) {
                    console.error('Error deleting comment:', error);
                    // Removed alert - just log the error
                } finally {
                    // Reset button
                    this.textContent = 'Delete';
                    this.disabled = false;
                }
            });
        }
    }
    
    // Function to handle edit comment action
    function handleEditComment(commentId) {
        console.log('Handling edit comment:', commentId);
        window.commentToEdit = commentId;
        
        // Find the comment item
        const commentItem = document.querySelector(`.comment-item[data-comment-id="${commentId}"]`);
        if (!commentItem) {
            console.error('Comment item not found');
            return;
        }
        
        // Get the comment text
        const commentTextElem = commentItem.querySelector('.comment-text');
        if (!commentTextElem) {
            console.error('Comment text element not found');
            return;
        }
        
        const commentText = commentTextElem.innerHTML;
        
        // Get the edit modal
        const modal = document.getElementById('editCommentModal');
        if (!modal) {
            console.error('Edit comment modal not found');
            alert('Error: Edit comment modal not found');
            return;
        }
        
        // Get the textarea
        const textarea = document.getElementById('editCommentContent');
        if (!textarea) {
            console.error('Edit comment textarea not found');
            alert('Error: Edit comment textarea not found');
            return;
        }
        
        // Clean the text and set in textarea
        const cleanText = commentText
            .replace(/<br\s*\/?>/gi, '\n')
            .replace(/&lt;/g, '<')
            .replace(/&gt;/g, '>')
            .replace(/&amp;/g, '&');
        
        textarea.value = cleanText;
        
        // Show the modal
        modal.style.cssText = `
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
        document.body.style.overflow = 'hidden';
    }
    
    // Function to handle delete comment action
    function handleDeleteComment(commentId) {
        console.log('Handling delete comment:', commentId);
        window.commentToDelete = commentId;
        
        // Get the delete modal
        const modal = document.getElementById('deleteCommentConfirmationOverlay');
        if (!modal) {
            console.error('Delete comment modal not found');
            
            // Create the modal dynamically if it doesn't exist
            const newModal = document.createElement('div');
            newModal.className = 'logout-confirmation-overlay';
            newModal.id = 'deleteCommentConfirmationOverlay';
            newModal.innerHTML = `
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
            `;
            document.body.appendChild(newModal);
            
            // Add event listeners
            const cancelBtn = newModal.querySelector('#cancelDeleteComment');
            if (cancelBtn) {
                cancelBtn.addEventListener('click', function() {
                    newModal.style.display = 'none';
                    document.body.style.overflow = '';
                    window.commentToDelete = null;
                });
            }
            
            const confirmBtn = newModal.querySelector('#confirmDeleteComment');
            if (confirmBtn) {
                confirmBtn.addEventListener('click', function() {
                    // Call the delete function
                    const deleteId = window.commentToDelete;
                    if (deleteId) {
                        // Submit deletion request
                        confirmBtn.disabled = true;
                        confirmBtn.textContent = 'Deleting...';
                        
                        const formData = new FormData();
                        formData.append('comment_id', deleteId);
                        
                        fetch('delete_comment.php', {
                            method: 'POST',
                            body: formData
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                // Remove the comment from DOM
                                const commentItem = document.querySelector(`.comment-item[data-comment-id="${deleteId}"]`);
                                if (commentItem) {
                                    commentItem.style.animation = 'fadeOut 0.3s ease forwards';
                                    setTimeout(() => {
                                        commentItem.remove();
                                    }, 300);
                                }
                            } else {
                                console.error('Error deleting comment:', data.error);
                                // Just log the error - no alert
                            }
                        })
                        .catch(error => {
                            console.error('Error deleting comment:', error);
                            // Just log the error - no alert
                        })
                        .finally(() => {
                            newModal.style.display = 'none';
                            document.body.style.overflow = '';
                            window.commentToDelete = null;
                            confirmBtn.disabled = false;
                            confirmBtn.textContent = 'Delete';
                        });
                    }
                });
            }
            
            // Display the modal
            newModal.style.cssText = `
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
            document.body.style.overflow = 'hidden';
            return;
        }
        
        // Show the modal
        modal.style.cssText = `
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
        document.body.style.overflow = 'hidden';
    }
    
    // Make sure we have the edit comment modal
    let editCommentModal = document.getElementById('editCommentModal');
    if (!editCommentModal) {
        console.log('Creating edit comment modal...');
        editCommentModal = document.createElement('div');
        editCommentModal.className = 'edit-post-modal';
        editCommentModal.id = 'editCommentModal';
        editCommentModal.innerHTML = `
            <div class="edit-post-modal-content">
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
        `;
        document.body.appendChild(editCommentModal);
        console.log('Edit comment modal created');
    }
    
    // Make sure we have the delete comment modal
    let deleteCommentModal = document.getElementById('deleteCommentConfirmationOverlay');
    if (!deleteCommentModal) {
        console.log('Creating delete comment modal...');
        deleteCommentModal = document.createElement('div');
        deleteCommentModal.className = 'logout-confirmation-overlay';
        deleteCommentModal.id = 'deleteCommentConfirmationOverlay';
        deleteCommentModal.innerHTML = `
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
        `;
        document.body.appendChild(deleteCommentModal);
        console.log('Delete comment modal created');
    }
    
    // Run the setup
    setupCommentMenuHandlers();
    
    console.log('Comment functionality fix loaded');
});
</script>

<!-- Add this script block to handle edit and delete functionality -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Variables to track current comment
    window.commentToEdit = null;
    window.commentToDelete = null;
    
    // Setup comment menu handlers for edit and delete
    function setupCommentMenuHandlers() {
        console.log('Setting up comment menu handlers...');
        
        // Handle comment menu clicks using event delegation
        document.addEventListener('click', function(e) {
            // Check for comment menu trigger (3 dots)
            if (e.target.classList.contains('comment-menu-trigger') || e.target.classList.contains('bi-three-dots-vertical')) {
                e.stopPropagation();
                
                // Find the closest menu trigger and comment ID
                const menuTrigger = e.target.closest('.comment-menu-trigger');
                if (!menuTrigger) return;
                
                const commentId = menuTrigger.dataset.commentId;
                console.log('Comment menu triggered for comment ID:', commentId);
                
                // Toggle the comment menu
                const commentItem = menuTrigger.closest('.comment-item');
                const menu = commentItem.querySelector('.comment-menu');
                
                // Create the menu if it doesn't exist
                if (!menu) {
                    const newMenu = document.createElement('div');
                    newMenu.className = 'comment-menu';
                    newMenu.innerHTML = `
                        <div class="comment-menu-item edit-comment" data-comment-id="${commentId}">
                            <i class="bi bi-pencil-square"></i> Edit
                        </div>
                        <div class="comment-menu-item delete-comment" data-comment-id="${commentId}">
                            <i class="bi bi-trash"></i> Delete
                        </div>
                    `;
                    newMenu.style.cssText = `
                        position: absolute;
                        right: 10px;
                        top: 30px;
                        background: white;
                        border-radius: 8px;
                        box-shadow: 0 2px 10px rgba(0,0,0,0.2);
                        z-index: 1000;
                        overflow: hidden;
                    `;
                    
                    // Style menu items
                    const menuItems = newMenu.querySelectorAll('.comment-menu-item');
                    menuItems.forEach(item => {
                        item.style.cssText = `
                            padding: 8px 15px;
                            cursor: pointer;
                            transition: background 0.2s;
                            display: flex;
                            align-items: center;
                            gap: 8px;
                        `;
                        
                        item.addEventListener('mouseenter', function() {
                            this.style.background = '#f0f0f0';
                        });
                        
                        item.addEventListener('mouseleave', function() {
                            this.style.background = 'white';
                        });
                    });
                    
                    commentItem.appendChild(newMenu);
                    
                    // Close menu when clicking outside
                    setTimeout(() => {
                        document.addEventListener('click', function closeMenu(evt) {
                            if (!newMenu.contains(evt.target) && evt.target !== menuTrigger) {
                                newMenu.remove();
                                document.removeEventListener('click', closeMenu);
                            }
                        });
                    }, 0);
                } else {
                    menu.remove();
                }
            }
            
            // Handle edit comment click
            if (e.target.closest('.edit-comment')) {
                const editBtn = e.target.closest('.edit-comment');
                const commentId = editBtn.dataset.commentId;
                console.log('Edit comment clicked for ID:', commentId);
                
                // Remove any open comment menus
                document.querySelectorAll('.comment-menu').forEach(menu => menu.remove());
                
                // Find the comment text
                const commentItem = document.querySelector(`.comment-item[data-comment-id="${commentId}"]`);
                if (!commentItem) {
                    console.error('Comment item not found');
                    return;
                }
                
                const commentTextElement = commentItem.querySelector('.comment-text');
                if (!commentTextElement) {
                    console.error('Comment text element not found');
                    return;
                }
                
                const commentText = commentTextElement.innerHTML.replace(/<br>/g, '\n');
                
                // Store the comment ID to edit
                window.commentToEdit = commentId;
                
                // Show edit comment modal
                const editCommentModal = document.getElementById('editCommentModal');
                if (editCommentModal) {
                    // Display with proper styling
                    editCommentModal.style.cssText = `
                        display: flex !important;
                        z-index: 10001 !important;
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
                    
                    // Set the content in the textarea
                    const textarea = editCommentModal.querySelector('#editCommentContent');
                    if (textarea) {
                        textarea.value = commentText;
                        setTimeout(() => textarea.focus(), 100);
                    }
                }
            }
            
            // Handle delete comment click
            if (e.target.closest('.delete-comment')) {
                const deleteBtn = e.target.closest('.delete-comment');
                const commentId = deleteBtn.dataset.commentId;
                console.log('Delete comment clicked for ID:', commentId);
                
                // Remove any open comment menus
                document.querySelectorAll('.comment-menu').forEach(menu => menu.remove());
                
                // Store the comment ID to delete
                window.commentToDelete = commentId;
                
                // Show delete confirmation modal
                const deleteModal = document.getElementById('deleteCommentConfirmationOverlay');
                if (deleteModal) {
                    deleteModal.style.cssText = `
                        display: flex !important;
                        z-index: 10001 !important;
                    `;
                }
            }
        });
        
        // Handle cancel and close buttons for edit comment modal
        document.querySelectorAll('#closeEditComment, #cancelEditComment').forEach(btn => {
            btn.addEventListener('click', function() {
                const editModal = document.getElementById('editCommentModal');
                if (editModal) {
                    editModal.style.display = 'none';
                    window.commentToEdit = null;
                }
            });
        });
        
        // Handle click outside edit comment modal
        const editCommentModal = document.getElementById('editCommentModal');
        if (editCommentModal) {
            editCommentModal.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.style.display = 'none';
                    window.commentToEdit = null;
                }
            });
        }
        
        // Handle save edited comment
        const saveEditComment = document.getElementById('saveEditComment');
        if (saveEditComment) {
            saveEditComment.addEventListener('click', function() {
                if (!window.commentToEdit) {
                    console.error('No comment ID to edit');
                    return;
                }
                
                const textarea = document.getElementById('editCommentContent');
                if (!textarea) {
                    console.error('Edit comment textarea not found');
                    return;
                }
                
                const content = textarea.value.trim();
                if (!content) {
                    alert('Comment cannot be empty');
                    return;
                }
                
                // Disable button during submission
                this.disabled = true;
                this.innerText = 'Saving...';
                
                // Prepare the form data
                const formData = new FormData();
                formData.append('comment_id', window.commentToEdit);
                formData.append('content', content);
                
                // Submit via AJAX
                fetch('update_comment.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`Server responded with status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        console.log('Comment updated successfully');
                        
                        // Update the comment in the DOM
                        const commentItem = document.querySelector(`.comment-item[data-comment-id="${window.commentToEdit}"]`);
                        if (commentItem) {
                            const commentText = commentItem.querySelector('.comment-text');
                            if (commentText) {
                                // Update with formatted content (newlines to <br>)
                                commentText.innerHTML = content.replace(/\n/g, '<br>');
                                
                                // Highlight the updated comment
                                commentItem.classList.add('comment-updated');
                                setTimeout(() => {
                                    commentItem.classList.remove('comment-updated');
                                }, 2000);
                            }
                        }
                        
                        // Close the modal
                        const editModal = document.getElementById('editCommentModal');
                        if (editModal) {
                            editModal.style.display = 'none';
                        }
                        
                        // Clear the edit state
                        window.commentToEdit = null;
                    } else {
                        alert('Error: ' + (data.error || 'Failed to update comment'));
                    }
                })
                .catch(error => {
                    console.error('Error updating comment:', error);
                    alert('An error occurred while updating your comment');
                })
                .finally(() => {
                    // Re-enable the button
                    this.disabled = false;
                    this.innerText = 'Save Changes';
                });
            });
        }
        
        // Handle cancel delete comment
        const cancelDeleteComment = document.getElementById('cancelDeleteComment');
        if (cancelDeleteComment) {
            cancelDeleteComment.addEventListener('click', function() {
                const deleteModal = document.getElementById('deleteCommentConfirmationOverlay');
                if (deleteModal) {
                    deleteModal.style.display = 'none';
                    window.commentToDelete = null;
                }
            });
        }
        
        // Handle confirm delete comment
        const confirmDeleteComment = document.getElementById('confirmDeleteComment');
        if (confirmDeleteComment) {
            confirmDeleteComment.addEventListener('click', function() {
                if (!window.commentToDelete) {
                    console.error('No comment ID to delete');
                    return;
                }
                
                // Disable button during submission
                this.disabled = true;
                this.innerText = 'Deleting...';
                
                // Prepare the form data
                const formData = new FormData();
                formData.append('comment_id', window.commentToDelete);
                
                // Submit via AJAX
                fetch('delete_comment.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`Server responded with status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        console.log('Comment deleted successfully');
                        
                        // Remove the comment from the DOM
                        const commentItem = document.querySelector(`.comment-item[data-comment-id="${window.commentToDelete}"]`);
                        if (commentItem) {
                            commentItem.style.animation = 'fadeOut 0.3s ease forwards';
                            setTimeout(() => {
                                commentItem.remove();
                                
                                // Check if there are no more comments
                                const commentList = document.getElementById('commentList');
                                if (commentList && commentList.children.length === 0) {
                                    commentList.innerHTML = '<div class="no-comments">No comments yet. Be the first to comment!</div>';
                                }
                                
                                // Update comment count in the post
                                if (data.post_id && data.comment_count !== undefined) {
                                    const commentCountElement = document.querySelector(`.comment-trigger[data-post-id="${data.post_id}"] .comment-count`);
                                    if (commentCountElement) {
                                        commentCountElement.textContent = data.comment_count;
                                    }
                                }
                            }, 300);
                        }
                        
                        // Close the modal
                        const deleteModal = document.getElementById('deleteCommentConfirmationOverlay');
                        if (deleteModal) {
                            deleteModal.style.display = 'none';
                        }
                        
                        // Clear the delete state
                        window.commentToDelete = null;
                    } else {
                        console.error('Server error while deleting comment:', data.error || 'Unknown error');
                        // Close the modal anyway since the server handled the request
                        const deleteModal = document.getElementById('deleteCommentConfirmationOverlay');
                        if (deleteModal) {
                            deleteModal.style.display = 'none';
                        }
                        window.commentToDelete = null;
                    }
                })
                .catch(error => {
                    console.error('Error deleting comment:', error);
                    // Close the modal anyway
                    const deleteModal = document.getElementById('deleteCommentConfirmationOverlay');
                    if (deleteModal) {
                        deleteModal.style.display = 'none';
                    }
                    window.commentToDelete = null;
                })
                .finally(() => {
                    // Re-enable the button
                    this.disabled = false;
                    this.innerText = 'Delete';
                });

            });
        }
        
        console.log('Comment menu handlers setup complete');
    }
    
    // Run the setup when the DOM is loaded
    setupCommentMenuHandlers();
    
    // Add styles for comment menu and animation
    const styleElement = document.createElement('style');
    styleElement.textContent = `
        .comment-updated {
            animation: highlightUpdated 2s ease;
        }
        
        @keyframes highlightUpdated {
            0% { background-color: #e8ffea; }
            100% { background-color: #f9f9f9; }
        }
        
        @keyframes fadeOut {
            0% { opacity: 1; transform: translateY(0); }
            100% { opacity: 0; transform: translateY(-10px); }
        }
    `;
    document.head.appendChild(styleElement);
});
</script>


<!-- Post Status Updates Scripts -->
<script src="post_sync.js?v=<?php echo time(); ?>"></script>
<script src="toast-notification.js?v=<?php echo time(); ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Add profile-page class to body for specific CSS targeting
    document.body.classList.add('profile-page');
});
</script>
<!-- Profile page create-post modal JS -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('createPostModal');
    const trigger = document.getElementById('profileCreatePostTrigger');
    const photoOption = document.getElementById('profilePhotoOption');
    const feelingOption = document.getElementById('profileFeelingOption');
    const closeBtn = document.getElementById('createPostCloseBtn');
    const postForm = document.getElementById('postForm');

    function openModal() {
        if (modal) {
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }
    }

    function closeModal() {
        if (modal) {
            modal.style.display = 'none';
            document.body.style.overflow = 'auto';
        }
    }

    if (trigger) trigger.addEventListener('click', openModal);
    if (photoOption) photoOption.addEventListener('click', openModal);
    if (feelingOption) feelingOption.addEventListener('click', openModal);
    if (closeBtn) closeBtn.addEventListener('click', closeModal);

    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === modal || e.target.classList.contains('modal-backdrop')) {
                closeModal();
            }
        });
    }

    // Image preview for create post modal
    const imageInput = document.getElementById('post-image');
    const imagePreview = document.getElementById('imagePreviewContainer');
    if (imageInput && imagePreview) {
        imageInput.addEventListener('change', function() {
            imagePreview.innerHTML = '';
            if (this.files && this.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const img = document.createElement('img');
                    img.src = e.target.result;
                    img.style.cssText = 'max-width:100%;max-height:300px;border-radius:8px;margin-top:10px;display:block;';
                    imagePreview.appendChild(img);
                };
                reader.readAsDataURL(this.files[0]);
            }
        });
    }

    // AJAX form submission for create post (matches homepage.php)
    if (postForm) {
        postForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalBtnText = submitBtn ? submitBtn.textContent : 'Post';
            const postContentInput = this.querySelector('#postContent');
            const postContent = postContentInput ? postContentInput.value.trim() : '';
            const imageInput = this.querySelector('#post-image');

            // Validate: must have either content or image
            if (postContent === '' && (!imageInput || !imageInput.files || imageInput.files.length === 0)) {
                alert('Please add some text or an image to your post.');
                return;
            }

            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Posting...';
            }

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
                    // Close the modal and restore scroll
                    closeModal();

                    // Reload the page to show the new post
                    window.location.reload();
                } else {
                    alert('Error: ' + (data.message || 'Could not create post'));
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = originalBtnText;
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while posting');
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = originalBtnText;
                }
            });
        });
    }
});
</script>
<!-- Include post status checker for real-time updates from admin actions -->
<script src="post_status_checker.js?v=<?php echo time(); ?>"></script>
<script>
// Keep profile-feed controls independent from the legacy duplicate scripts above.
(function () {
    function updateLike(button, liked, count) {
        const icon = button.querySelector('i');
        const label = button.querySelector('span');
        const countEl = button.querySelector('.like-count');
        button.dataset.liked = liked ? 'true' : 'false';
        if (icon) icon.className = liked ? 'bi bi-heart-fill' : 'bi bi-heart';
        if (label) label.textContent = liked ? 'Liked' : 'Like';
        if (countEl) countEl.textContent = count;
        const likedBy = button.closest('.feed')?.querySelector('.liked-by');
        if (likedBy) likedBy.textContent = count > 0 ? `${count} ${count === 1 ? 'person' : 'people'} liked this` : 'Be the first to like this';
    }

    document.addEventListener('click', async function (event) {
        const like = event.target.closest('.like-button');
        if (!like) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        if (like.dataset.processing === 'true') return;

        const wasLiked = like.dataset.liked === 'true';
        const countEl = like.querySelector('.like-count');
        const oldCount = Number.parseInt(countEl?.textContent || '0', 10) || 0;
        const nextLiked = !wasLiked;
        like.dataset.processing = 'true';
        updateLike(like, nextLiked, Math.max(0, oldCount + (nextLiked ? 1 : -1)));

        try {
            const response = await fetch('like_post.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `post_id=${encodeURIComponent(like.dataset.postId)}&action=${nextLiked ? 'like' : 'unlike'}`
            });
            const data = await response.json();
            if (!response.ok || !data.success) throw new Error(data.error || 'Unable to update like.');
            updateLike(like, nextLiked, Number(data.likes));
        } catch (error) {
            console.error(error);
            updateLike(like, wasLiked, oldCount);
        } finally {
            delete like.dataset.processing;
        }
    }, true);

    document.addEventListener('click', function (event) {
        const trigger = event.target.closest('.post-menu-trigger');
        if (trigger) {
            event.preventDefault();
            event.stopImmediatePropagation();
            const menu = document.querySelector(`.post-menu-dropdown[data-post-id="${trigger.dataset.postId}"]`);
            document.querySelectorAll('.post-menu-dropdown').forEach(function (item) {
                if (item !== menu) item.classList.remove('show');
            });
            if (menu) menu.classList.toggle('show');
            return;
        }
        if (!event.target.closest('.post-actions-menu')) {
            document.querySelectorAll('.post-menu-dropdown').forEach(function (item) { item.classList.remove('show'); });
        }
    }, true);


})();
</script>
</body>
</html>
