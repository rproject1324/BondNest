<?php
// Database connection — PostgreSQL (Railway) or MySQL (local XAMPP)

/**
 * Get configured admin email(s) from environment variables.
 * Checks DIARI_ADMIN_EMAIL (used in reference project/Railway), BONDNEST_ADMIN_EMAIL, and ADMIN_EMAIL.
 */
if (!function_exists('getConfiguredAdminEmail')) {
    function getConfiguredAdminEmail() {
        $email = getenv('DIARI_ADMIN_EMAIL') ?: getenv('BONDNEST_ADMIN_EMAIL') ?: getenv('ADMIN_EMAIL');
        if (!$email && isset($_ENV['DIARI_ADMIN_EMAIL'])) $email = $_ENV['DIARI_ADMIN_EMAIL'];
        if (!$email && isset($_ENV['BONDNEST_ADMIN_EMAIL'])) $email = $_ENV['BONDNEST_ADMIN_EMAIL'];
        if (!$email && isset($_ENV['ADMIN_EMAIL'])) $email = $_ENV['ADMIN_EMAIL'];
        if (!$email && isset($_SERVER['DIARI_ADMIN_EMAIL'])) $email = $_SERVER['DIARI_ADMIN_EMAIL'];
        if (!$email && isset($_SERVER['BONDNEST_ADMIN_EMAIL'])) $email = $_SERVER['BONDNEST_ADMIN_EMAIL'];
        if (!$email && isset($_SERVER['ADMIN_EMAIL'])) $email = $_SERVER['ADMIN_EMAIL'];
        return strtolower(trim((string)$email));
    }
}

/**
 * Check if the given email matches the configured admin email.
 * Supports comma-separated list of emails and is case-insensitive.
 */
if (!function_exists('isConfiguredAdminEmail')) {
    function isConfiguredAdminEmail($email) {
        $adminEmailStr = getConfiguredAdminEmail();
        if (!$adminEmailStr || !$email) {
            return false;
        }
        $target = strtolower(trim((string)$email));
        $configured = array_filter(array_map('trim', explode(',', strtolower($adminEmailStr))));
        return in_array($target, $configured, true);
    }
}

// Upload directory from environment variable
$upload_dir = getenv('UPLOADS_DIR') ?: (__DIR__ . '/uploads');

// Railway provides DATABASE_URL automatically when you add a PostgreSQL service
$database_url = getenv('DATABASE_URL');

if ($database_url) {
    // Parse DATABASE_URL (format: postgres://user:pass@host:port/dbname)
    $db_driver = 'pgsql';
    $url = parse_url($database_url);
    $db_host = $url['host'] ?? 'localhost';
    $db_port = $url['port'] ?? '5432';
    $db_name = ltrim($url['path'], '/');
    $db_user = $url['user'] ?? '';
    $db_pass = $url['pass'] ?? '';

    $dsn = "pgsql:host={$db_host};port={$db_port};dbname={$db_name}";
} elseif (getenv('DB_HOST')) {
    // PostgreSQL via individual env vars
    $db_driver = 'pgsql';
    $db_host = getenv('DB_HOST');
    $db_name = getenv('DB_NAME') ?: 'bondnest_db';
    $db_user = getenv('DB_USER') ?: 'postgres';
    $db_pass = getenv('DB_PASS') ?: '';
    $db_port = getenv('DB_PORT') ?: '5432';
    $dsn = "pgsql:host={$db_host};port={$db_port};dbname={$db_name}";
} else {
    // MySQL (local XAMPP)
    $db_driver = 'mysql';
    $db_host = 'localhost';
    $db_name = getenv('DB_NAME') ?: 'bondnest_db';
    $db_user = 'root';
    $db_pass = '';
    $db_port = '3306';
    $dsn = "mysql:host={$db_host};port={$db_port};dbname={$db_name};charset=utf8mb4";
}

try {
    $pdo = new PDO($dsn, $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    // Ensure PostgreSQL uses UTC for consistent timestamps
    if ($db_driver === 'pgsql') {
        $pdo->exec("SET timezone = 'UTC'");
    }
} catch (PDOException $e) {
    http_response_code(500);
    die("Connection failed: " . $e->getMessage());
}

// Create symlink for uploads if using a different upload directory (Railway volume)
$local_uploads = __DIR__ . '/uploads';
if ($upload_dir !== $local_uploads && !is_link($local_uploads)) {
    @symlink($upload_dir, $local_uploads);
}

// Backward-compatible alias
$connection = $pdo;

