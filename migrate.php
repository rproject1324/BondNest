<?php
/**
 * Database migration — creates all tables if they don't exist.
 * Safe to run on every request (uses IF NOT EXISTS).
 */

function runMigration($pdo) {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $isPg = ($driver === 'pgsql');

    $tables = [];

    // ── users ──
    if ($isPg) {
        $tables['users'] = "CREATE TABLE IF NOT EXISTS users (
            id SERIAL PRIMARY KEY,
            first_name VARCHAR(50) NOT NULL,
            last_name VARCHAR(50) NOT NULL,
            username VARCHAR(50) NOT NULL UNIQUE,
            email VARCHAR(100) DEFAULT NULL,
            age INTEGER DEFAULT NULL,
            birthday DATE NOT NULL,
            gender VARCHAR(10) NOT NULL,
            password VARCHAR(255) NOT NULL,
            profile_picture VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            recovery_code VARCHAR(20) NOT NULL,
            bio TEXT DEFAULT NULL,
            location VARCHAR(100) DEFAULT NULL,
            interests VARCHAR(255) DEFAULT NULL,
            website VARCHAR(255) DEFAULT NULL,
            last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            user_status VARCHAR(10) DEFAULT 'offline',
            is_admin SMALLINT DEFAULT 0
        )";
    } else {
        $tables['users'] = "CREATE TABLE IF NOT EXISTS users (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            first_name VARCHAR(50) NOT NULL,
            last_name VARCHAR(50) NOT NULL,
            username VARCHAR(50) NOT NULL UNIQUE,
            email VARCHAR(100) DEFAULT NULL,
            age INT(11) DEFAULT NULL,
            birthday DATE NOT NULL,
            gender VARCHAR(10) NOT NULL,
            password VARCHAR(255) NOT NULL,
            profile_picture VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            recovery_code VARCHAR(20) NOT NULL,
            bio TEXT DEFAULT NULL,
            location VARCHAR(100) DEFAULT NULL,
            interests VARCHAR(255) DEFAULT NULL,
            website VARCHAR(255) DEFAULT NULL,
            last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            user_status ENUM('active','inactive','offline') DEFAULT 'offline',
            is_admin TINYINT(1) DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    // ── posts ──
    if ($isPg) {
        $tables['posts'] = "CREATE TABLE IF NOT EXISTS posts (
            id SERIAL PRIMARY KEY,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            content TEXT NOT NULL,
            image_path VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            likes INTEGER DEFAULT 0,
            status VARCHAR(20) DEFAULT NULL
        )";
    } else {
        $tables['posts'] = "CREATE TABLE IF NOT EXISTS posts (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            user_id INT(11) NOT NULL,
            content TEXT NOT NULL,
            image_path VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            likes INT(11) DEFAULT 0,
            status VARCHAR(20) DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    // ── comments ──
    if ($isPg) {
        $tables['comments'] = "CREATE TABLE IF NOT EXISTS comments (
            id SERIAL PRIMARY KEY,
            post_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            content TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
    } else {
        $tables['comments'] = "CREATE TABLE IF NOT EXISTS comments (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            post_id INT(11) NOT NULL,
            user_id INT(11) NOT NULL,
            content TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    // ── likes ──
    if ($isPg) {
        $tables['likes'] = "CREATE TABLE IF NOT EXISTS likes (
            id SERIAL PRIMARY KEY,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            post_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(user_id, post_id)
        )";
    } else {
        $tables['likes'] = "CREATE TABLE IF NOT EXISTS likes (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            user_id INT(11) NOT NULL,
            post_id INT(11) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_like (user_id, post_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    // ── notifications ──
    if ($isPg) {
        $tables['notifications'] = "CREATE TABLE IF NOT EXISTS notifications (
            id SERIAL PRIMARY KEY,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            type VARCHAR(50) NOT NULL,
            message TEXT NOT NULL,
            reference_id INTEGER DEFAULT NULL,
            is_read SMALLINT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            additional_data TEXT DEFAULT NULL
        )";
    } else {
        $tables['notifications'] = "CREATE TABLE IF NOT EXISTS notifications (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            user_id INT(11) NOT NULL,
            type VARCHAR(50) NOT NULL,
            message TEXT NOT NULL,
            reference_id INT(11) DEFAULT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            additional_data LONGTEXT DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    // ── messages ──
    if ($isPg) {
        $tables['messages'] = "CREATE TABLE IF NOT EXISTS messages (
            id SERIAL PRIMARY KEY,
            sender_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            receiver_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            content TEXT,
            image_path TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            is_read SMALLINT DEFAULT 0,
            updated_at TIMESTAMP DEFAULT NULL,
            deleted BOOLEAN NOT NULL DEFAULT FALSE
        )";
    } else {
        $tables['messages'] = "CREATE TABLE IF NOT EXISTS messages (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            sender_id INT(11) NOT NULL,
            receiver_id INT(11) NOT NULL,
            content TEXT,
            image_path TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            is_read TINYINT(1) DEFAULT 0,
            updated_at TIMESTAMP DEFAULT NULL,
            deleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    // ── deleted_posts ──
    if ($isPg) {
        $tables['deleted_posts'] = "CREATE TABLE IF NOT EXISTS deleted_posts (
            id SERIAL PRIMARY KEY,
            original_post_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            admin_id INTEGER NOT NULL,
            content TEXT NOT NULL,
            image_path VARCHAR(255) DEFAULT NULL,
            likes INTEGER DEFAULT 0,
            comment_count INTEGER DEFAULT 0,
            profile_picture VARCHAR(255) DEFAULT NULL,
            first_name VARCHAR(100) DEFAULT NULL,
            last_name VARCHAR(100) DEFAULT NULL,
            deletion_reason TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            deleted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
    } else {
        $tables['deleted_posts'] = "CREATE TABLE IF NOT EXISTS deleted_posts (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            original_post_id INT(11) NOT NULL,
            user_id INT(11) NOT NULL,
            admin_id INT(11) NOT NULL,
            content TEXT NOT NULL,
            image_path VARCHAR(255) DEFAULT NULL,
            likes INT(11) DEFAULT 0,
            comment_count INT(11) DEFAULT 0,
            profile_picture VARCHAR(255) DEFAULT NULL,
            first_name VARCHAR(100) DEFAULT NULL,
            last_name VARCHAR(100) DEFAULT NULL,
            deletion_reason TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            deleted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    // ── admin_actions ──
    if ($isPg) {
        $tables['admin_actions'] = "CREATE TABLE IF NOT EXISTS admin_actions (
            id SERIAL PRIMARY KEY,
            admin_id INTEGER NOT NULL,
            post_id INTEGER DEFAULT NULL,
            action_type VARCHAR(20) NOT NULL,
            comment TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
    } else {
        $tables['admin_actions'] = "CREATE TABLE IF NOT EXISTS admin_actions (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            admin_id INT(11) NOT NULL,
            post_id INT(11) DEFAULT NULL,
            action_type ENUM('view','warn','delete','approve','hold') NOT NULL,
            comment TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    // ── pending_registrations ──
    if ($isPg) {
        $tables['pending_registrations'] = "CREATE TABLE IF NOT EXISTS pending_registrations (
            email VARCHAR(255) PRIMARY KEY,
            username VARCHAR(64) NOT NULL,
            password_hash VARCHAR(256) NOT NULL,
            first_name VARCHAR(64),
            last_name VARCHAR(64),
            gender VARCHAR(32),
            birthday DATE,
            otp_code VARCHAR(6) NOT NULL,
            otp_expires_at TIMESTAMP NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
    } else {
        $tables['pending_registrations'] = "CREATE TABLE IF NOT EXISTS pending_registrations (
            email VARCHAR(255) PRIMARY KEY,
            username VARCHAR(64) NOT NULL,
            password_hash VARCHAR(256) NOT NULL,
            first_name VARCHAR(64),
            last_name VARCHAR(64),
            gender VARCHAR(32),
            birthday DATE,
            otp_code VARCHAR(6) NOT NULL,
            otp_expires_at TIMESTAMP NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    // ── password_resets ──
    if ($isPg) {
        $tables['password_resets'] = "CREATE TABLE IF NOT EXISTS password_resets (
            email VARCHAR(255) PRIMARY KEY,
            reset_code VARCHAR(6) NOT NULL,
            expires_at TIMESTAMP NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
    } else {
        $tables['password_resets'] = "CREATE TABLE IF NOT EXISTS password_resets (
            email VARCHAR(255) PRIMARY KEY,
            reset_code VARCHAR(6) NOT NULL,
            expires_at TIMESTAMP NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    // ── email_change_challenges ──
    if ($isPg) {
        $tables['email_change_challenges'] = "CREATE TABLE IF NOT EXISTS email_change_challenges (
            user_id INTEGER PRIMARY KEY,
            new_email VARCHAR(255) NOT NULL,
            otp_code VARCHAR(6) NOT NULL,
            otp_expires_at TIMESTAMP NOT NULL,
            pending_payload TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
    } else {
        $tables['email_change_challenges'] = "CREATE TABLE IF NOT EXISTS email_change_challenges (
            user_id INT(11) PRIMARY KEY,
            new_email VARCHAR(255) NOT NULL,
            otp_code VARCHAR(6) NOT NULL,
            otp_expires_at TIMESTAMP NOT NULL,
            pending_payload TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    foreach ($tables as $name => $sql) {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            error_log("Migration error on table {$name}: " . $e->getMessage());
        }
    }

    // Migrate existing users table: add email column and make age nullable if needed
    try {
        if ($isPg) {
            $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS email VARCHAR(100)");
            $pdo->exec("ALTER TABLE users ALTER COLUMN age DROP NOT NULL");
            $pdo->exec("ALTER TABLE users ALTER COLUMN age DROP DEFAULT");
        } else {
            $check = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='email'");
            $exists = $check && $check->fetch();
            if (!$exists) {
                $pdo->exec("ALTER TABLE users ADD COLUMN email VARCHAR(100) DEFAULT NULL");
            }
            // Make age nullable (ignore error if already nullable)
            try { $pdo->exec("ALTER TABLE users MODIFY age INT(11) DEFAULT NULL"); } catch (PDOException $e) {}
        }
    } catch (PDOException $e) {
        error_log("Migration email/age: " . $e->getMessage());
    }

    // Ensure is_admin column exists on users table
    try {
        if ($isPg) {
            $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS is_admin SMALLINT DEFAULT 0");
        } else {
            $checkAdmin = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='is_admin'");
            $adminColExists = $checkAdmin && $checkAdmin->fetch();
            if (!$adminColExists) {
                $pdo->exec("ALTER TABLE users ADD COLUMN is_admin TINYINT(1) DEFAULT 0");
            }
        }
    } catch (PDOException $e) {
        error_log("Migration is_admin: " . $e->getMessage());
    }

    // Auto-promote any existing user matching configured admin email
    if (function_exists('getConfiguredAdminEmail')) {
        $adminEmailStr = getConfiguredAdminEmail();
        if (!empty($adminEmailStr)) {
            try {
                $emails = array_filter(array_map('trim', explode(',', strtolower($adminEmailStr))));
                if (!empty($emails)) {
                    $placeholders = implode(',', array_fill(0, count($emails), '?'));
                    $promoteStmt = $pdo->prepare("UPDATE users SET is_admin = 1 WHERE LOWER(email) IN ($placeholders) AND (is_admin IS NULL OR is_admin != 1)");
                    $promoteStmt->execute($emails);
                }
            } catch (Exception $e) {
                error_log("Migration auto-promote admin: " . $e->getMessage());
            }
        }
    }

    // Fix any absolute paths stored in database (convert /data/uploads/ to uploads/)
    try {
        $pdo->exec("UPDATE users SET profile_picture = REPLACE(profile_picture, '/data/uploads/', 'uploads/') WHERE profile_picture LIKE '/data/uploads/%'");
        $pdo->exec("UPDATE posts SET image_path = REPLACE(image_path, '/data/uploads/', 'uploads/') WHERE image_path LIKE '/data/uploads/%'");
    } catch (PDOException $e) {
        // Ignore if columns don't exist yet
    }

    // Add image_path column to messages table if missing
    try {
        if ($isPg) {
            $pdo->exec("ALTER TABLE messages ADD COLUMN IF NOT EXISTS image_path TEXT");
            $pdo->exec("ALTER TABLE messages ALTER COLUMN content DROP NOT NULL");
        } else {
            $check = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='messages' AND COLUMN_NAME='image_path'");
            $exists = $check && $check->fetch();
            if (!$exists) {
                $pdo->exec("ALTER TABLE messages ADD COLUMN image_path TEXT DEFAULT NULL");
            }
        }
    } catch (PDOException $e) {
        error_log("Migration messages image_path: " . $e->getMessage());
    }

    // Add parent_id column to comments table for reply feature
    try {
        if ($isPg) {
            $pdo->exec("ALTER TABLE comments ADD COLUMN IF NOT EXISTS parent_id INTEGER REFERENCES comments(id) ON DELETE CASCADE");
        } else {
            $check = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='comments' AND COLUMN_NAME='parent_id'");
            $exists = $check && $check->fetch();
            if (!$exists) {
                $pdo->exec("ALTER TABLE comments ADD COLUMN parent_id INT(11) DEFAULT NULL");
            }
        }
    } catch (PDOException $e) {
        error_log("Migration comments parent_id: " . $e->getMessage());
    }
}
