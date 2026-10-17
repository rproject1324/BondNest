<?php
// Session should already be started in the parent file
require_once 'db_connection.php';
// Get user info from the session
$user_id = $_SESSION['user_id'] ?? null;

// Always fetch fresh user data from the database to avoid stale data
if ($user_id) {
    // Database connection should be established in the parent file
    global $pdo;
    
    // If connection isn't available from parent file
    if (!isset($pdo) || !$pdo) {
        include 'db_connection.php';
    }
    
    // Get user data - fetch fresh data every time
    $sql = "SELECT first_name, last_name, username, profile_picture FROM users WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Update session data to keep it fresh
    $_SESSION['user'] = $user;
} else {
    $user = null;
}

// Default profile picture if not set
$profile_picture = !empty($user['profile_picture']) ? $user['profile_picture'] : '';
$full_name = htmlspecialchars($user['first_name'] . ' ' . $user['last_name'] ?? '');
$username = htmlspecialchars($user['username'] ?? '');

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
?>

<style>
/* Global initials-avatar override - force to fill parent container */
.initials-avatar {
    width: 100% !important;
    height: 100% !important;
    border-radius: 50% !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
}

/* Sidebar Styles */

/* LEFT SECTION */
main .container .left {
    height: max-content;
    position: sticky;
    top: var(--sticky-top-left);
}

main .container .left .profile {
    padding: 1.2rem;
    background: linear-gradient(135deg, #ffffff 0%, #f0fafa 100%);
    border-radius: 14px;
    border: 1px solid rgba(0, 128, 128, 0.12);
    display: flex;
    align-items: center;
    column-gap: 1rem;
    width: 100%;
    box-shadow: 0 2px 12px rgba(0, 128, 128, 0.08);
    transition: box-shadow 0.25s ease;
}

main .container .left .profile:hover {
    box-shadow: 0 4px 18px rgba(0, 128, 128, 0.14);
}

/* Ensure profile picture in left sidebar displays correctly */
main .container .left .profile .profile_picture {
    width: 2.7rem;
    height: 2.7rem;
    overflow: hidden;
    border-radius: 50%;
    border: 2px solid rgba(0, 128, 128, 0.25);
    flex-shrink: 0;
}

main .container .left .profile .profile_picture img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

/* SIDEBAR SECTION */
.left .sidebar {
    margin-top: 1rem;
    background: linear-gradient(180deg, #ffffff 0%, #f7fbfb 100%);
    border-radius: 14px;
    border: 1px solid rgba(0, 128, 128, 0.1);
    box-shadow: 0 2px 12px rgba(0, 128, 128, 0.06);
    overflow: hidden;
}

.left .sidebar .menu-item {
    display: flex;
    align-items: center;
    height: 3.5rem;
    cursor: pointer;
    transition: all 0.2s ease;
    position: relative;
    border-left: 3px solid transparent;
}

.left .sidebar .menu-item:hover {
    background: rgba(0, 128, 128, 0.06);
}

.left .sidebar .menu-item:hover i {
    color: var(--color-primary, #008080);
}

.left .sidebar i {
    font-size: 1.3rem;
    color: #5a6a6a;
    margin-left: 1.4rem;
    position: relative;
    transition: color 0.2s ease;
}

.left .sidebar i .notification-count {
    background: var(--color-danger);
    color: white;
    font-size: 0.7rem;
    width: fit-content;
    border-radius: 0.8rem;
    padding: 0.1rem 0.4rem;
    position: absolute;
    top: -0.2rem;
    right: -0.3rem; 
}

.left .sidebar h3 {
    margin-left: 1rem;
    font-size: 0.92rem;
    color: #3a4a4a !important;
    font-weight: 500;
}

.left .sidebar .menu-item:not(.active) h3 {
    color: #3a4a4a !important;
}

.left .sidebar .active {
    background: rgba(0, 128, 128, 0.08);
    border-left: 3px solid var(--color-primary, #008080);
}

.left .sidebar .active i {
    color: var(--color-primary, #008080);
}

.left .sidebar .active h3 {
    color: var(--color-primary, #008080) !important;
    font-weight: 600;
}

.left .sidebar .active::before {
    display: none;
}

.left .sidebar .menu-item:first-child.active {
    border-top-left-radius: 0;
    overflow: hidden;
}

.left .sidebar .menu-item:last-child.active {
    border-bottom-left-radius: 0;
    overflow: hidden;
}

.left .btn {
    margin-top: 1rem;
    width: 100%;
    text-align: center;
    padding: 1rem 0;
}

/* INITIALS AVATAR - fits properly in circular containers */
.initials-avatar {
    display: flex !important;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    letter-spacing: 0.5px;
    flex-shrink: 0;
    border-radius: 50%;
    overflow: hidden;
    box-sizing: border-box;
}

/* Mobile Navigation Styles */
.mobile-nav {
    display: none;
    position: fixed;
    left: 20px;
    top: 50%;
    transform: translateY(-50%);
    z-index: 1000;
    background: var(--color-white);
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
    padding: 10px;
}

.mobile-nav .menu-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    width: 60px;
    height: 60px;
    margin: 5px 0;
    border-radius: 8px;
    text-decoration: none;
    transition: all 0.3s ease;
}

.mobile-nav .menu-item i {
    font-size: 1.5rem;
    margin: 0;
    color: var(--color-gray);
}

.mobile-nav .menu-item h3 {
    font-size: 0.7rem;
    margin: 5px 0 0 0;
    color: var(--color-gray);
}

.mobile-nav .menu-item:hover,
.mobile-nav .menu-item.active {
    background: var(--color-primary);
}

.mobile-nav .menu-item:hover i,
.mobile-nav .menu-item:hover h3,
.mobile-nav .menu-item.active i,
.mobile-nav .menu-item.active h3 {
    color: white;
}

/* Add styles for create post button in mobile nav */
.mobile-nav .create-post-btn {
    background: var(--color-primary);
    color: white;
    cursor: pointer;
}

.mobile-nav .create-post-btn i,
.mobile-nav .create-post-btn h3 {
    color: white;
}

.mobile-nav .create-post-btn:hover {
    background: var(--color-primary-dark);
}

@media screen and (max-width: 1500px) {
    .left {
        display: none !important;
    }
    
    .mobile-nav {
        display: block;
    }
    
    .mobile-nav .menu-item {
        width: 50px;
        height: 50px;
    }
    
    .mobile-nav .menu-item i {
        font-size: 1.3rem;
    }
    
    .mobile-nav .menu-item h3 {
        font-size: 0.6rem;
    }
}

@media screen and (max-width: 480px) {
    .mobile-nav {
        left: 10px;
    }
    
    .mobile-nav .menu-item {
        width: 45px;
        height: 45px;
    }
    
    .mobile-nav .menu-item i {
        font-size: 1.2rem;
    }
    
    .mobile-nav .menu-item h3 {
        font-size: 0.5rem;
    }
}
</style>

<!-- LEFT SECTION -->
<div class="left">
    <a class="profile">
        <div class="profile_picture">
            <?php if (!empty($user['profile_picture'])): ?>
                <img src="<?php echo $user['profile_picture']; ?>" alt="Profile Picture">
            <?php else: ?>
                <?php echo getInitialsHtml($user['first_name'], $user['last_name'], 44); ?>
            <?php endif; ?>
        </div>
        <div class="handle">
            <h4><?php echo $full_name; ?></h4>
            <p class="text-muted">@<?php echo $username; ?></p>
        </div>
    </a>

    <div class="sidebar">
        <a class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == 'homepage.php' ? 'active' : ''; ?>" href="homepage.php">
            <i class="bi bi-house"></i>
            <h3>Home</h3>
        </a>
        <a class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == 'profile-page.php' ? 'active' : ''; ?>" href="profile-page.php">
            <i class="bi bi-person-square"></i>
            <h3>Profile</h3>
        </a>
        <a class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'active' : ''; ?>" href="settings.php">
            <i class="bi bi-gear"></i>
            <h3>Settings</h3>
        </a>
    </div>
</div>

<!-- Mobile Navigation -->
<div class="mobile-nav">
    <a class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == 'homepage.php' ? 'active' : ''; ?>" href="homepage.php">
        <i class="bi bi-house"></i>
        <h3>Home</h3>
    </a>
    <a class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == 'profile-page.php' ? 'active' : ''; ?>" href="profile-page.php">
        <i class="bi bi-person-square"></i>
        <h3>Profile</h3>
    </a>
    <a class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'active' : ''; ?>" href="settings.php">
        <i class="bi bi-gear"></i>
        <h3>Settings</h3>
    </a>
    <?php if (basename($_SERVER['PHP_SELF']) !== 'message.php'): ?>
    <label for="create_post" class="menu-item create-post-btn" onclick="showCreatePostModal()">
        <i class="bi bi-plus-lg"></i>
        <h3>Create</h3>
    </label>
    <?php endif; ?>
</div>

<!-- JavaScript for sidebar functionality -->
<script>
// Initialize sidebar functionality when the document is loaded
document.addEventListener('DOMContentLoaded', function() {
    // FOR THE SIDEBAR
    const menuItems = document.querySelectorAll('.menu-item');
    
    // REMOVES ACTIVE CLASS FROM ALL MENU ITEMS
    const changeActiveItem = () => {
        menuItems.forEach(item => {
            item.classList.remove('active');
        });
    };

    // Add click event listeners to menu items for active state
    menuItems.forEach(item => {
        item.addEventListener('click', function() {
            changeActiveItem();
            this.classList.add('active');
        });
    });
});

// Function to show create post modal
function showCreatePostModal() {
    const modal = document.getElementById('createPostModal');
    if (modal) {
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
}
</script> 