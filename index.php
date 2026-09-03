<?php


require_once 'db_connection.php';
require_once 'migrate.php';
runMigration($pdo);

function bondGenerateOtp() {
    return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}
function bondSendPasswordResetOtp($email, $code, $name) {
    $apiKey = getenv('BREVO_API_KEY');
    $senderEmail = getenv('BREVO_SENDER_EMAIL');
    $senderName = getenv('BREVO_SENDER_NAME') ?: 'BondNest';
    if (!$apiKey || !$senderEmail) {
        error_log("[PWD RESET DEV MODE] $email -> $code");
        return true;
    }
    $payload = [
        'sender' => ['name' => $senderName, 'email' => $senderEmail],
        'to' => [['email' => $email, 'name' => $name ?: $email]],
        'subject' => 'BondNest password reset code',
        'htmlContent' => "<html><body style='font-family: Arial, sans-serif; color: #2F3E36;'><h2>Reset your BondNest password</h2><p>Hello " . htmlspecialchars($name) . ",</p><p>Your password reset code is:</p><p style='font-size:28px;font-weight:bold;letter-spacing:6px;'>$code</p><p>This code expires in 10 minutes.</p><p>If you did not request this, please ignore this email.</p></body></html>",
        'textContent' => "Your BondNest password reset code is $code. It expires in 10 minutes.",
    ];
    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'api-key: ' . $apiKey],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode >= 200 && $httpCode < 300) return true;
    error_log("[PWD RESET SEND FAILED] $email HTTP $httpCode: $result");
    return false;
}

// Handle all POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $action = '';
    if (isset($_POST['forgot_request'])) $action = 'forgot_request';
    if (isset($_POST['forgot_resend']))  $action = 'forgot_request';
    if (isset($_POST['forgot_verify']))  $action = 'forgot_verify';
    if (isset($_POST['forgot_reset']))   $action = 'forgot_reset';
    if (isset($_POST['check_username'])) $action = 'check_username';
    if (isset($_POST['username']) && isset($_POST['password']) && $action === '') $action = 'login';

    switch ($action) {
        case 'login':
            $input_username = $_POST['username'] ?? '';
            $input_password = $_POST['password'] ?? '';
            if (empty($input_username) && empty($input_password)) {
                echo json_encode(['success' => false, 'error' => 'Please enter both of your username and password']);
                exit;
            }
            if (empty($input_username)) {
                echo json_encode(['success' => false, 'error' => 'Please enter your username']);
                exit;
            }
            if (empty($input_password)) {
                echo json_encode(['success' => false, 'error' => 'Please enter your password']);
                exit;
            }
            try {
                $stmt = $pdo->prepare("SELECT id, username, email, password, is_admin FROM users WHERE LOWER(username) = LOWER(?) OR LOWER(email) = LOWER(?)");
                $stmt->execute([$input_username, $input_username]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$user) {
                    echo json_encode(['success' => false, 'error' => 'Incorrect username or password.']);
                    exit;
                }
                if (password_verify($input_password, $user['password'])) {
                    session_start();
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];

                    $isAdmin = false;
                    if (isset($user['is_admin']) && (int)$user['is_admin'] === 1) {
                        $isAdmin = true;
                    }
                    if (!empty($user['email']) && isConfiguredAdminEmail($user['email'])) {
                        $isAdmin = true;
                        // Synchronize is_admin in database if not already set
                        if (!isset($user['is_admin']) || (int)$user['is_admin'] !== 1) {
                            try {
                                $promote = $pdo->prepare("UPDATE users SET is_admin = 1 WHERE id = ?");
                                $promote->execute([$user['id']]);
                            } catch (Exception $e) {}
                        }
                    }

                    if ($isAdmin) {
                        $_SESSION['is_admin'] = true;
                        echo json_encode(['success' => true, 'redirect' => 'admin.php']);
                    } else {
                        $_SESSION['is_admin'] = false;
                        echo json_encode(['success' => true, 'redirect' => 'homepage.php']);
                    }
                    exit;
                } else {
                    echo json_encode(['success' => false, 'error' => 'Incorrect username or password.']);
                    exit;
                }
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'error' => 'Database error']);
                exit;
            }
            break;

        case 'forgot_request':
            $email = strtolower(trim($_POST['email'] ?? ''));
            if (empty($email)) {
                echo json_encode(['success' => false, 'error' => 'Please enter your email address.']);
                exit;
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['success' => false, 'error' => 'Please enter a valid email address.']);
                exit;
            }
            try {
                $stmt = $pdo->prepare("SELECT id, email, username, first_name, last_name FROM users WHERE email = ? LIMIT 1");
                $stmt->execute([$email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$user) {
                    echo json_encode(['success' => false, 'error' => 'This email doesn\'t appear to be associated with any account yet.']);
                    exit;
                }
                $code = bondGenerateOtp();
                $expires = gmdate('Y-m-d H:i:s', time() + 600);
                // Store OTP
                $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                if ($driver === 'pgsql') {
                    $stmt2 = $pdo->prepare("INSERT INTO password_resets (email, reset_code, expires_at) VALUES (?, ?, ?) ON CONFLICT (email) DO UPDATE SET reset_code = EXCLUDED.reset_code, expires_at = EXCLUDED.expires_at, created_at = CURRENT_TIMESTAMP");
                    $stmt2->execute([$email, $code, $expires]);
                } else {
                    $stmt2 = $pdo->prepare("INSERT INTO password_resets (email, reset_code, expires_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE reset_code = VALUES(reset_code), expires_at = VALUES(expires_at)");
                    $stmt2->execute([$email, $code, $expires]);
                }
                $displayName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: $user['username'];
                if (!bondSendPasswordResetOtp($email, $code, $displayName)) {
                    echo json_encode(['success' => false, 'error' => 'Failed to send reset code. Please try again.']);
                    exit;
                }
                echo json_encode(['success' => true, 'message' => 'Reset code sent. Check your email.']);
                exit;
            } catch (PDOException $e) {
                error_log("forgot_request: " . $e->getMessage());
                echo json_encode(['success' => false, 'error' => 'Database error. Please try again.']);
                exit;
            }
            break;

        case 'forgot_verify':
            $email = strtolower(trim($_POST['email'] ?? ''));
            $code = trim($_POST['code'] ?? '');
            if (empty($email)) {
                echo json_encode(['success' => false, 'error' => 'Email address is required.']);
                exit;
            }
            if (empty($code)) {
                echo json_encode(['success' => false, 'error' => 'Reset code is required.']);
                exit;
            }
            try {
                $stmt = $pdo->prepare("SELECT email FROM users WHERE email = ? LIMIT 1");
                $stmt->execute([$email]);
                if (!$stmt->fetch()) {
                    echo json_encode(['success' => false, 'error' => 'Invalid or expired reset code.']);
                    exit;
                }
                $stmt2 = $pdo->prepare("SELECT reset_code, expires_at FROM password_resets WHERE email = ? LIMIT 1");
                $stmt2->execute([$email]);
                $row = $stmt2->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    echo json_encode(['success' => false, 'error' => 'Invalid or expired reset code.']);
                    exit;
                }
                $exp = new DateTime($row['expires_at'], new DateTimeZone('UTC'));
                $now = new DateTime('now', new DateTimeZone('UTC'));
                if ($now > $exp || ($row['reset_code'] ?? '') !== $code) {
                    echo json_encode(['success' => false, 'error' => 'Invalid or expired reset code.']);
                    exit;
                }
                echo json_encode(['success' => true, 'message' => 'Code verified.']);
                exit;
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'error' => 'Database error.']);
                exit;
            }
            break;

        case 'forgot_reset':
            $email = strtolower(trim($_POST['email'] ?? ''));
            $code = trim($_POST['code'] ?? '');
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';
            if (empty($email) || empty($code) || empty($newPassword) || empty($confirmPassword)) {
                echo json_encode(['success' => false, 'error' => 'All fields are required.']);
                exit;
            }
            if ($newPassword !== $confirmPassword) {
                echo json_encode(['success' => false, 'error' => 'Passwords do not match.']);
                exit;
            }
            // Password policy (same as signup - 12 chars etc.)
            $pw = $newPassword;
            if (strlen($pw) < 12) {
                echo json_encode(['success' => false, 'field' => 'forgotNewPassword', 'error' => 'Password must be at least 12 characters.']);
                exit;
            }
            if (strlen($pw) > 64) {
                echo json_encode(['success' => false, 'field' => 'forgotNewPassword', 'error' => 'Password must not exceed 64 characters.']);
                exit;
            }
            if (strpos($pw, ' ') !== false) {
                echo json_encode(['success' => false, 'field' => 'forgotNewPassword', 'error' => 'Password must not contain spaces.']);
                exit;
            }
            if (!preg_match('/[A-Z]/', $pw)) {
                echo json_encode(['success' => false, 'field' => 'forgotNewPassword', 'error' => 'Password must include at least one uppercase letter (A-Z).']);
                exit;
            }
            if (!preg_match('/[a-z]/', $pw)) {
                echo json_encode(['success' => false, 'field' => 'forgotNewPassword', 'error' => 'Password must include at least one lowercase letter (a-z).']);
                exit;
            }
            if (!preg_match('/[0-9]/', $pw)) {
                echo json_encode(['success' => false, 'field' => 'forgotNewPassword', 'error' => 'Password must include at least one digit (0-9).']);
                exit;
            }
            if (!preg_match('/[!@#$%^&*()_+\-=\[\]{}|;:\'",.<>?\/`~\\\\]/', $pw)) {
                echo json_encode(['success' => false, 'field' => 'forgotNewPassword', 'error' => 'Password must include at least one special character (!@#$...).']);
                exit;
            }
            $common = ['password','12345678','123456789','qwerty','qwerty123','111111','iloveyou','admin','welcome','monkey','dragon','letmein','abc123','password1'];
            if (in_array(strtolower($pw), $common)) {
                echo json_encode(['success' => false, 'field' => 'forgotNewPassword', 'error' => 'This password is too common. Choose a less predictable password.']);
                exit;
            }
            try {
                $stmt = $pdo->prepare("SELECT id, username, email, first_name, last_name, password FROM users WHERE email = ? LIMIT 1");
                $stmt->execute([$email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$user) {
                    echo json_encode(['success' => false, 'error' => 'Account not found.']);
                    exit;
                }
                // Check not same as old password
                if (password_verify($pw, $user['password'])) {
                    echo json_encode(['success' => false, 'field' => 'forgotNewPassword', 'error' => 'Please enter a password different from your previous one.']);
                    exit;
                }
                // Verify OTP still valid
                $stmt2 = $pdo->prepare("SELECT reset_code, expires_at FROM password_resets WHERE email = ? LIMIT 1");
                $stmt2->execute([$email]);
                $row = $stmt2->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    echo json_encode(['success' => false, 'error' => 'Invalid or expired reset code.']);
                    exit;
                }
                $exp = new DateTime($row['expires_at'], new DateTimeZone('UTC'));
                $now = new DateTime('now', new DateTimeZone('UTC'));
                if ($now > $exp || ($row['reset_code'] ?? '') !== $code) {
                    echo json_encode(['success' => false, 'error' => 'Invalid or expired reset code.']);
                    exit;
                }
                $hash = password_hash($pw, PASSWORD_DEFAULT);
                $upd = $pdo->prepare("UPDATE users SET password = ? WHERE email = ?");
                $upd->execute([$hash, $email]);
                $del = $pdo->prepare("DELETE FROM password_resets WHERE email = ?");
                $del->execute([$email]);
                echo json_encode(['success' => true, 'message' => 'Password updated successfully. You can now sign in.']);
                exit;
            } catch (PDOException $e) {
                error_log("forgot_reset: " . $e->getMessage());
                echo json_encode(['success' => false, 'error' => 'Database error.']);
                exit;
            }
            break;

        case 'check_username':
            $username = $_POST['username'] ?? '';
            if (empty($username)) {
                echo json_encode(['exists' => false]);
                exit;
            }
            try {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                $stmt->execute([$username]);
                echo json_encode(['exists' => $stmt->rowCount() > 0]);
                exit;
            } catch (PDOException $e) {
                echo json_encode(['error' => $e->getMessage()]);
                exit;
            }
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
            exit;
    }
}

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
$loggedIn = isset($_SESSION['user_id']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BondNest - Log In / Sign Up</title>
    <link rel="stylesheet" href="login-signup.css"> <link rel="stylesheet" href="login.css"> <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    
</head>

<body>
  <div class="bondnest-title-container">
    <img src="./web-images/bn-logo.png" alt="BondNest Icon" class="bondnest-icon-top">
    <h1 class="bondnest-title-top">
        <span style="--random-angle: 3;">B</span>
        <span style="--random-angle: -2;">o</span>
        <span style="--random-angle: 5;">n</span>
        <span style="--random-angle: -1;">d</span>
        <span style="--random-angle: 4;">N</span>
        <span style="--random-angle: -3;">e</span>
        <span style="--random-angle: 2;">s</span>
        <span style="--random-angle: -4;">t</span>
    </h1>
</div>

<div class="flying-bird">
    <img src="./web-images/bird.gif" alt="Flying Bird">
</div>

<div class="flying-bird-secondary">
    <img src="./web-images/bird.gif" alt="Flying Bird Secondary">
</div>

<div class="login-wrapper">
    <div class="form-container" id="loginContainer">
        <h2 class="login-title">Welcome Back</h2>
        <p class="login-subtitle">Please enter your account</p>

        <form class="login-form" id="loginForm">
            <div class="form-group">
                <div class="login-form-group">
                    <div class="login-icon-container">
                        <i class="fas fa-user"></i>
                    </div>
                    <div class="login-input-wrapper">
                        <input type="text" id="email" name="username" placeholder="Your username" autocomplete="off">
                    </div>
                </div>
            </div>
        
            <div class="form-group">
                <div class="login-form-group" id="loginPasswordGroup">
                    <div class="login-icon-container">
                        <i class="fas fa-lock"></i>
                    </div>
                    <div class="login-input-wrapper">
                        <input type="password" id="password" name="password" placeholder="Your password" autocomplete="current-password">
                    </div>
                </div>
                <div class="login-inline-error" id="loginInlineError"></div>
                <a href="#" class="login-forgot-link" id="forgotPasswordTrigger">Forgot password?</a>
            </div>
        
            <button type="submit" class="login-button" id="loginSubmit">Sign In</button>
        </form>
    </div>

    <div class="right-section">
        <h2>Your Network Awaits!</h2>
        <p>Join BondNest and start connecting.</p>
        <div class="signup-button-section">
            <a href="signup.php" class="signup-button" style="text-decoration:none; display:inline-block;">Sign Up</a>
        </div>
    </div>
</div>

<style>
 .forgot-alert{padding:10px 12px;border-radius:8px;font-size:.84rem;margin-bottom:14px;text-align:center;line-height:1.4}
 .forgot-alert[hidden]{display:none !important}
 .forgot-alert--error{background:#fef2f2;border:1px solid #f8b4bf;color:#b84252}
 .forgot-alert--success{background:#e9f4f0;border:1px solid rgba(43,158,158,0.22);color:#1a6b5a}
 .forgot-password-wrapper{max-width:500px;padding:40px}
 .forgot-password-header{text-align:left;margin-bottom:26px}
 .forgot-password-title{font-size:2rem;line-height:1.2;margin-bottom:8px}
 .forgot-password-subtitle{line-height:1.5}
 .forgot-password-wrapper form{width:100%}
 .forgot-password-wrapper .form-group{width:100%;margin-bottom:14px}
 .forgot-password-wrapper .input-container{width:100%;height:52px;min-height:52px;position:relative;border:1px solid #D5DFD9;border-radius:8px;overflow:hidden;box-sizing:border-box;transition:border-color .2s,box-shadow .2s}
 .forgot-password-wrapper .input-container:focus-within{border-color:#2B9E9E;box-shadow:0 0 0 3px rgba(43,158,158,.11)}
 .forgot-password-wrapper .input-container.error,.forgot-password-wrapper .input-container:has(input.error){border-color:#f59ca8;box-shadow:0 0 0 3px rgba(245,156,168,.16)}
 .forgot-password-wrapper .input-container .icon-container{width:46px;padding:0;justify-content:center;flex-shrink:0}
 .forgot-password-wrapper .input-container input{min-width:0;height:50px;padding:0 14px;font-size:.95rem}
 .forgot-password-wrapper .input-container input[type=password]{padding-right:56px}
 .forgot-password-wrapper .input-icon{position:absolute;right:22px !important;top:50%;transform:translateY(-50%);z-index:2;margin:0}
 .forgot-password-wrapper .custom-error{display:none;background:#fef2f2;border:1px solid #f8b4bf;color:#b84252;padding:8px 10px;border-radius:8px;font-size:13px;font-weight:500;line-height:1.25;margin-top:6px;margin-bottom:0;box-sizing:border-box;width:100%;text-align:left}
 .forgot-password-wrapper .custom-error.show{display:block}
 .forgot-password-wrapper .create-account-button{height:52px;margin-top:0;border-radius:26px;padding:0 18px;justify-content:center;text-align:center;text-transform:uppercase;font-size:.9rem;letter-spacing:.3px}
 #forgotRequestForm .form-group{margin-bottom:10px}
 #forgotRequestForm .forgot-alert{margin-top:6px;margin-bottom:0}
 #forgotResetForm{width:100%}
 #forgotResetForm .form-group{width:100%;display:block;margin-bottom:10px}
 #forgotResetForm .input-container{width:100%}
 #forgotResetForm .form-group--pwd-full{margin-top:2px;margin-bottom:10px}
 #forgotResetForm .form-group--pwd-full:has(.pwd-live:not([hidden])),#forgotResetForm .form-group--pwd-full:has(.custom-error.show){margin-bottom:10px}
 #forgotResetForm .form-group--pwd-full:has(.pwd-live[hidden]){display:none}
 #forgotResetForm .pwd-live[hidden]{display:none !important}
 #forgotResetForm .pwd-live{width:100%;margin:0 0 6px 0}
 #forgotResetForm .pwd-strength{display:flex;align-items:center;gap:.55rem;margin:0;width:100%}
 #forgotResetForm .pwd-strength__track{flex:1;min-width:0;height:6px;border-radius:6px;background:rgba(0,128,128,.12);overflow:hidden}
 #forgotResetForm .pwd-strength__fill{height:100%;width:0;border-radius:6px;transition:width .22s ease,background-color .25s ease}
 #forgotResetForm .pwd-strength__label{flex-shrink:0;font-size:11px;font-weight:600;min-width:2.75rem;text-align:right}
 .forgot-otp-label{font-size:.84rem;letter-spacing:.08em;text-transform:uppercase;color:#58756f;text-align:center;margin:0 0 16px;font-weight:600}
 .forgot-otp-digits{display:flex;gap:10px;justify-content:center;margin-bottom:24px}
 .forgot-otp-digit{width:46px;height:52px;text-align:center;font-size:1.25rem;font-weight:600;border:2px solid #D5DFD9;border-radius:12px;background:#fff;color:#2F3E36;box-shadow:0 3px 0 rgba(32,80,71,.08);transition:border-color .2s,box-shadow .2s;outline:none}
.forgot-otp-digit:focus{border-color:#2B9E9E;box-shadow:0 0 0 3px rgba(43,158,158,0.12)}
.forgot-otp-feedback{display:flex;align-items:center;justify-content:space-between;gap:10px;background:#fef2f2;border:1px solid #f8b4bf;color:#b84252;border-radius:8px;padding:8px 10px;font-size:.82rem;margin-bottom:10px}
.forgot-otp-feedback[hidden]{display:none !important}
.forgot-otp-feedback--success{background:#e9f4f0;border-color:rgba(43,158,158,0.22);color:#1a6b5a}
.forgot-otp-feedback__left{display:flex;align-items:center;gap:6px}
.forgot-otp-feedback__close{background:none;border:none;font-size:18px;cursor:pointer;color:inherit;line-height:1;padding:0 2px}
 #forgotVerifyBtn{width:312px;max-width:100%;margin:0 auto;display:flex}
 .forgot-meta{display:flex;align-items:center;justify-content:center;gap:6px;flex-wrap:wrap;font-size:.82rem;margin-top:22px;color:#5a706d}
.forgot-resend-btn{background:none;border:none;color:#008080;font-weight:600;cursor:pointer;text-decoration:underline;padding:0;font-size:.82rem}
.forgot-resend-btn:disabled{opacity:.5;cursor:not-allowed;text-decoration:none}
.forgot-timer{font-weight:600;color:#5a706d}
 .forgot-verified-banner{width:100%;box-sizing:border-box;background:rgba(43,158,158,0.08);border:1px solid rgba(43,158,158,0.28);color:#1a6b5a;border-radius:8px;padding:10px 12px;font-size:.82rem;text-align:left;margin-bottom:14px}
.forgot-verified-banner[hidden]{display:none !important}
 .forgot-password-wrapper .form-footer{margin-top:18px}
 .forgot-password-wrapper .back-to-login{font-size:.9rem}
 .forgot-password-wrapper .back-to-login .link{display:inline-flex;gap:7px;align-items:center}
 @media(max-width:480px){.forgot-password-wrapper{padding:30px 22px}.forgot-otp-digits{gap:6px}.forgot-otp-digit{width:40px;height:48px}}
</style>
<div class="forgot-password-container" id="forgotPasswordContainer">
    <div class="forgot-password-wrapper">
        <div class="forgot-password-header">
            <h2 class="forgot-password-title" id="forgotTitle">Forgot Your Password?</h2>
            <p class="forgot-password-subtitle" id="forgotSubtitle">Enter the email associated with your account to reset your password.</p>
        </div>
        <!-- Phase 1: Email -->
        <form id="forgotRequestForm" class="forgot-password-form" novalidate>
            <div class="form-group">
                <div class="input-container">
                    <div class="icon-container"><i class="fas fa-envelope"></i></div>
                    <input type="text" id="forgotEmail" name="email" placeholder="Email Address" autocomplete="off">
                </div>
                <div class="custom-error" id="forgotEmail-error"></div>
                <div class="forgot-alert" id="forgotAlert" hidden role="alert"></div>
            </div>
            <button type="submit" class="create-account-button" id="forgotRequestBtn">Send Reset Code</button>
        </form>

        <!-- Phase 2: OTP -->
        <form id="forgotVerifyForm" hidden novalidate>
            <div class="form-group">
                <p class="forgot-otp-label">Enter verification code</p>
                <div class="forgot-otp-digits" id="forgotOtpInputs">
                    <input class="forgot-otp-digit" inputmode="numeric" maxlength="1" aria-label="Digit 1">
                    <input class="forgot-otp-digit" inputmode="numeric" maxlength="1" aria-label="Digit 2">
                    <input class="forgot-otp-digit" inputmode="numeric" maxlength="1" aria-label="Digit 3">
                    <input class="forgot-otp-digit" inputmode="numeric" maxlength="1" aria-label="Digit 4">
                    <input class="forgot-otp-digit" inputmode="numeric" maxlength="1" aria-label="Digit 5">
                    <input class="forgot-otp-digit" inputmode="numeric" maxlength="1" aria-label="Digit 6">
                </div>
                <div class="forgot-otp-feedback" id="forgotOtpFeedback" hidden>
                    <span class="forgot-otp-feedback__left"><i class="fas fa-exclamation-circle"></i><span class="forgot-otp-feedback__text"></span></span>
                    <button type="button" class="forgot-otp-feedback__close" id="forgotOtpFeedbackClose" aria-label="Close">&times;</button>
                </div>
            </div>
            <button type="submit" class="create-account-button" id="forgotVerifyBtn">Verify Code</button>
            <div class="forgot-meta">
                <span class="forgot-meta-text">Didn't receive the code?</span>
                <button type="button" class="forgot-resend-btn" id="forgotResendBtn">Resend OTP</button>
                <span class="forgot-timer" id="forgotTimerLabel"></span>
            </div>
        </form>

        <!-- Phase 3: New password -->
        <form id="forgotResetForm" hidden novalidate>
            <div class="forgot-verified-banner" id="forgotVerifiedBanner" hidden>Code verified. Set your new password.</div>
            <div class="form-group">
                <div class="input-container">
                    <div class="icon-container"><i class="fas fa-lock"></i></div>
                    <input type="password" id="forgotNewPassword" name="new_password" placeholder="New Password" autocomplete="new-password" maxlength="64">
                    <i class="fas fa-eye-slash input-icon toggle-password" id="forgotToggleNew" style="cursor:pointer;color:#9AA9A1;"></i>
                </div>
                <div class="custom-error" id="forgotNewPassword-error"></div>
            </div>
            <div class="form-group">
                <div class="input-container">
                    <div class="icon-container"><i class="fas fa-lock"></i></div>
                    <input type="password" id="forgotConfirmPassword" name="confirm_password" placeholder="Confirm New Password" autocomplete="new-password" maxlength="64">
                    <i class="fas fa-eye-slash input-icon toggle-password" id="forgotToggleConfirm" style="cursor:pointer;color:#9AA9A1;"></i>
                </div>
                <div class="custom-error" id="forgotConfirmPassword-error"></div>
            </div>
            <div class="form-group form-group--pwd-full">
                <div class="pwd-live" id="forgotPwLive" hidden></div>
                <div class="custom-error" id="forgotPw-common-error"></div>
            </div>
            <button type="submit" class="create-account-button" id="forgotResetBtn" disabled>Update Password</button>
        </form>

        <div class="form-footer">
            <div class="back-to-login">
                <a href="#" id="hideForgotPassword" class="link"><i class="fas fa-arrow-left" aria-hidden="true"></i> Back to Log In</a>
            </div>
        </div>
    </div>
</div>






  

<script>
    const loginWrapper = document.querySelector('.login-wrapper');
    const bondnestContainer = document.querySelector('.bondnest-title-container');
    const flyingBird = document.querySelector('.flying-bird');
    const flyingBirdSecondary = document.querySelector('.flying-bird-secondary');
    const body = document.body;
    const loginForm = document.getElementById('loginForm');
    const forgotPasswordButton = document.getElementById('forgotPasswordTrigger');
    const loginInlineError = document.getElementById('loginInlineError');
    const forgotPasswordContainer = document.getElementById('forgotPasswordContainer');
    const forgotRequestForm = document.getElementById('forgotRequestForm');
    const forgotVerifyForm = document.getElementById('forgotVerifyForm');
    const forgotResetForm = document.getElementById('forgotResetForm');
    const forgotAlert = document.getElementById('forgotAlert');
    const forgotOtpInputs = document.getElementById('forgotOtpInputs');
    const forgotOtpFeedback = document.getElementById('forgotOtpFeedback');
    const forgotVerifiedBanner = document.getElementById('forgotVerifiedBanner');
    const hideForgotPassword = document.getElementById('hideForgotPassword');
    let forgotEmail = '';
    let verifiedForgotCode = '';
    let forgotResendInterval = null;
    let forgotResendRemaining = 0;

    let currentValidationTimer;

    function showBondToast(message, type, durationMs) {
        var existing = document.querySelector('.bond-toast');
        if (existing) existing.remove();
        var kind = type || 'info';
        var duration = typeof durationMs === 'number' ? durationMs : 3500;
        var toast = document.createElement('div');
        toast.className = 'bond-toast bond-toast--' + kind;
        toast.setAttribute('role', 'status');
        var icon = kind === 'success' ? 'fa-check-circle' : kind === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle';
        toast.innerHTML = '<i class="fas ' + icon + '" aria-hidden="true"></i><span></span>';
        var span = toast.querySelector('span');
        if (span) span.textContent = String(message || '');
        toast.style.cssText = 'position:fixed;top:24px;right:24px;padding:14px 20px;border-radius:10px;display:flex;align-items:center;gap:10px;font-weight:500;font-size:.88rem;z-index:13000;box-shadow:0 4px 20px rgba(0,0,0,0.15);transform:translateX(calc(100% + 28px));transition:transform .3s ease,opacity .3s ease;max-width:400px;word-wrap:break-word;opacity:0;font-family:Poppins,sans-serif;';
        if (kind === 'success') { toast.style.backgroundColor = '#008080'; toast.style.color = '#fff'; }
        else if (kind === 'error') { toast.style.backgroundColor = '#e74c3c'; toast.style.color = '#fff'; }
        else { toast.style.backgroundColor = '#5a9068'; toast.style.color = '#fff'; }
        document.body.appendChild(toast);
        requestAnimationFrame(function() { requestAnimationFrame(function() { toast.style.opacity = '1'; toast.style.transform = 'translateX(0)'; }); });
        setTimeout(function() { toast.style.opacity = '0'; toast.style.transform = 'translateX(calc(100% + 28px))'; setTimeout(function() { if (toast.parentNode) toast.remove(); }, 300); }, duration);
    }

    function clearAllTimers() {
        if (currentValidationTimer) {
            clearTimeout(currentValidationTimer);
            currentValidationTimer = null;
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        function resetLoginForm() {
            const loginFormElement = document.getElementById('loginForm');
            if (loginFormElement) {
                const emailInput = loginFormElement.querySelector('#email');
                const passwordInput = loginFormElement.querySelector('#password');
                if (emailInput) emailInput.value = '';
                if (passwordInput) passwordInput.value = '';
            }
            hideLoginError();
            clearAllTimers();
        }

        // Click handler to cancel timers when navigating
        document.addEventListener('click', function(e) {
            if (e.target.closest('#hideForgotPassword, #forgotPasswordTrigger')) {
                clearAllTimers();
            }
        });

        // ── Forgot password: 3-phase OTP flow ──
        function isValidEmailBond(v){ return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v); }
        function showForgotAlert(msg,type){
            if(!forgotAlert) return;
            if(!msg){ forgotAlert.hidden=true; forgotAlert.textContent=''; forgotAlert.className='forgot-alert'; return; }
            forgotAlert.textContent=msg;
            forgotAlert.className='forgot-alert '+(type==='success'?'forgot-alert--success':'forgot-alert--error');
            forgotAlert.hidden=false;
        }
        function showForgotOtpFeedback(msg,type){
            if(!forgotOtpFeedback) return;
            const t=forgotOtpFeedback.querySelector('.forgot-otp-feedback__text');
            if(!msg){ forgotOtpFeedback.hidden=true; return; }
            if(t) t.textContent=msg;
            forgotOtpFeedback.classList.toggle('forgot-otp-feedback--success', type==='success');
            forgotOtpFeedback.hidden=false;
        }
        function clearForgotOtpFeedback(){ if(forgotOtpFeedback) forgotOtpFeedback.hidden=true; }
        function getForgotOtpCode(){
            if(!forgotOtpInputs) return '';
            return Array.from(forgotOtpInputs.querySelectorAll('.forgot-otp-digit')).map(function(i){return i.value;}).join('');
        }
        function clearForgotOtpInputs(){
            if(!forgotOtpInputs) return;
            forgotOtpInputs.querySelectorAll('.forgot-otp-digit').forEach(function(i){ i.value=''; });
        }
        function startForgotResendCooldown(sec){
            sec = sec || 60;
            const btn=document.getElementById('forgotResendBtn');
            const label=document.getElementById('forgotTimerLabel');
            if(forgotResendInterval) clearInterval(forgotResendInterval);
            forgotResendRemaining=sec;
            if(btn) btn.disabled=true;
            if(label) label.textContent='('+Math.floor(sec/60)+':'+String(sec%60).padStart(2,'0')+')';
            forgotResendInterval=setInterval(function(){
                forgotResendRemaining--;
                if(label) label.textContent=forgotResendRemaining>0?'('+Math.floor(forgotResendRemaining/60)+':'+String(forgotResendRemaining%60).padStart(2,'0')+')':'';
                if(forgotResendRemaining<=0){
                    clearInterval(forgotResendInterval); forgotResendInterval=null;
                    if(btn) btn.disabled=false;
                    if(label) label.textContent='';
                }
            },1000);
        }
        function setForgotPhase(phase){
            const titleEl=document.getElementById('forgotTitle');
            const subEl=document.getElementById('forgotSubtitle');
            const p1=document.getElementById('forgotRequestForm');
            const p2=document.getElementById('forgotVerifyForm');
            const p3=document.getElementById('forgotResetForm');
            if(!p1||!p2||!p3) return;
            if(phase===1){
                if(titleEl) titleEl.textContent='Forgot Your Password?';
                if(subEl) subEl.textContent='Enter the email associated with your account to reset your password.';
                p1.hidden=false; p2.hidden=true; p3.hidden=true;
                showForgotAlert('', '');
                clearForgotOtpFeedback();
                if(forgotVerifiedBanner) forgotVerifiedBanner.hidden=true;
            } else if(phase===2){
                if(titleEl) titleEl.textContent='Verification';
                if(subEl) subEl.textContent='Thank you for verifying. Kindly check your email for the code.';
                p1.hidden=true; p2.hidden=false; p3.hidden=true;
            } else if(phase===3){
                if(titleEl) titleEl.textContent='Reset Password';
                if(subEl) subEl.textContent='Please choose a new password that is different from your old one.';
                p1.hidden=true; p2.hidden=true; p3.hidden=false;
                if(forgotVerifiedBanner){ forgotVerifiedBanner.hidden=false; forgotVerifiedBanner.textContent='Code verified. Set your new password.'; }
            }
        }
        function resetForgotModal(){
            forgotEmail=''; verifiedForgotCode='';
            const em=document.getElementById('forgotEmail');
            if(em) em.value='';
            const eErr=document.getElementById('forgotEmail-error');
            if(eErr){ eErr.textContent=''; eErr.classList.remove('show'); }
            const inp=document.getElementById('forgotEmail');
            if(inp){ inp.classList.remove('error','success'); const c=inp.closest('.input-container'); if(c) c.classList.remove('error'); }
            clearForgotOtpInputs();
            clearForgotOtpFeedback();
            showForgotAlert('','');
            const n=document.getElementById('forgotNewPassword');
            const c2=document.getElementById('forgotConfirmPassword');
            if(n) n.value=''; if(c2) c2.value='';
            const nErr=document.getElementById('forgotNewPassword-error');
            if(nErr){ nErr.textContent=''; nErr.classList.remove('show'); }
            const c2Err=document.getElementById('forgotConfirmPassword-error');
            if(c2Err){ c2Err.textContent=''; c2Err.classList.remove('show'); }
            if(n){ n.classList.remove('error'); const c=n.closest('.input-container'); if(c) c.classList.remove('error'); }
            if(c2){ c2.classList.remove('error'); const c=c2.closest('.input-container'); if(c) c.classList.remove('error'); }
            const pwLive=document.getElementById('forgotPwLive');
            if(pwLive) pwLive.hidden=true;
            const commonErr=document.getElementById('forgotPw-common-error');
            if(commonErr){ commonErr.textContent=''; commonErr.classList.remove('show'); }
            const rb=document.getElementById('forgotResetBtn');
            if(rb) rb.disabled=true;
            if(forgotResendInterval){ clearInterval(forgotResendInterval); forgotResendInterval=null; }
            const rBtn=document.getElementById('forgotResendBtn');
            if(rBtn) rBtn.disabled=false;
            const tl=document.getElementById('forgotTimerLabel');
            if(tl) tl.textContent='';
            setForgotPhase(1);
        }
        // Toggle password visibility for forgot forms
        (function(){
            [['forgotToggleNew','forgotNewPassword'],['forgotToggleConfirm','forgotConfirmPassword']].forEach(function(pair){
                const tog=document.getElementById(pair[0]);
                const inp=document.getElementById(pair[1]);
                if(!tog||!inp) return;
                tog.addEventListener('click', function(){
                    const isPwd=inp.type==='password';
                    inp.type=isPwd?'text':'password';
                    tog.classList.toggle('fa-eye-slash');
                    tog.classList.toggle('fa-eye');
                });
            });
        })();
        // OTP digit behavior
        (function(){
            if(!forgotOtpInputs) return;
            const digits=forgotOtpInputs.querySelectorAll('.forgot-otp-digit');
            digits.forEach(function(inp,idx){
                inp.addEventListener('input', function(){
                    let v=inp.value.replace(/\D/g,'');
                    inp.value=v.slice(-1);
                    clearForgotOtpFeedback();
                    if(v && idx<digits.length-1) digits[idx+1].focus();
                    if(getForgotOtpCode().length===6){
                        setTimeout(function(){
                            const form=document.getElementById('forgotVerifyForm');
                            if(form) form.requestSubmit();
                        },220);
                    }
                });
                inp.addEventListener('keydown', function(e){
                    if(e.key==='Backspace' && !inp.value && idx>0){
                        e.preventDefault();
                        digits[idx-1].focus();
                        digits[idx-1].value='';
                    }
                });
            });
            forgotOtpInputs.addEventListener('paste', function(e){
                e.preventDefault();
                const txt=(e.clipboardData||window.clipboardData).getData('text').replace(/\D/g,'').slice(0,6);
                txt.split('').forEach(function(ch,i){ if(digits[i]) digits[i].value=ch; });
                clearForgotOtpFeedback();
                if(txt.length===6){
                    setTimeout(function(){ const form=document.getElementById('forgotVerifyForm'); if(form) form.requestSubmit(); },220);
                } else if(txt.length>0){
                    const next=Math.min(txt.length, digits.length-1);
                    digits[next].focus();
                }
            });
        })();
        const forgotOtpFeedbackClose=document.getElementById('forgotOtpFeedbackClose');
        if(forgotOtpFeedbackClose) forgotOtpFeedbackClose.addEventListener('click', clearForgotOtpFeedback);
        // Phase 1: Request OTP
        const forgotRequestFormEl=document.getElementById('forgotRequestForm');
        if(forgotRequestFormEl){
            forgotRequestFormEl.addEventListener('submit', function(e){
                e.preventDefault();
                const emailEl=document.getElementById('forgotEmail');
                const email=(emailEl?emailEl.value.trim():'');
                const errEl=document.getElementById('forgotEmail-error');
                showForgotAlert('','');
                if(!email){
                    if(errEl){ errEl.textContent='Email is required.'; errEl.classList.add('show'); }
                    if(emailEl){ emailEl.classList.add('error'); const c=emailEl.closest('.input-container'); if(c) c.classList.add('error'); }
                    return;
                }
                if(!isValidEmailBond(email)){
                    if(errEl){ errEl.textContent='Please enter a valid email.'; errEl.classList.add('show'); }
                    if(emailEl){ emailEl.classList.add('error'); const c=emailEl.closest('.input-container'); if(c) c.classList.add('error'); }
                    return;
                }
                if(errEl){ errEl.textContent=''; errEl.classList.remove('show'); }
                if(emailEl){ emailEl.classList.remove('error'); const c=emailEl.closest('.input-container'); if(c) c.classList.remove('error'); }
                const btn=document.getElementById('forgotRequestBtn');
                const orig=btn?btn.textContent:'';
                if(btn){ btn.disabled=true; btn.textContent='Sending...'; }
                fetch('index.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'forgot_request=1&email='+encodeURIComponent(email) })
                .then(function(r){ return r.json().then(function(d){ return {ok:r.ok,d:d}; }); })
                .then(function(obj){
                    if(!obj.ok || !obj.d.success){
                        const msg=(obj.d && obj.d.error) || 'Failed to send reset code. Please try again.';
                        if(errEl){ errEl.textContent=msg; errEl.classList.add('show'); }
                        if(emailEl){ emailEl.classList.add('error'); const c=emailEl.closest('.input-container'); if(c) c.classList.add('error'); }
                        showForgotAlert('','');
                        return;
                    }
                    forgotEmail=email;
                    showForgotAlert('','');
                    setForgotPhase(2);
                    clearForgotOtpInputs();
                    clearForgotOtpFeedback();
                    startForgotResendCooldown(60);
                    const firstDigit=forgotOtpInputs?forgotOtpInputs.querySelector('.forgot-otp-digit'):null;
                    if(firstDigit) setTimeout(function(){ firstDigit.focus(); },100);
                })
                .catch(function(){
                    if(errEl){ errEl.textContent='An error occurred. Please try again.'; errEl.classList.add('show'); }
                    if(emailEl){ emailEl.classList.add('error'); const c=emailEl.closest('.input-container'); if(c) c.classList.add('error'); }
                    showForgotAlert('','');
                })
                .finally(function(){ if(btn){ btn.disabled=false; btn.textContent=orig; } });
            });
            const fe=document.getElementById('forgotEmail');
            if(fe) fe.addEventListener('input', function(){
                const errEl=document.getElementById('forgotEmail-error');
                const cc=fe.closest('.input-container');
                const value=fe.value.trim();
                if(value && !isValidEmailBond(value)){
                    if(errEl){ errEl.textContent='Please enter a valid email.'; errEl.classList.add('show'); }
                    fe.classList.add('error');
                    if(cc) cc.classList.add('error');
                } else {
                    if(errEl){ errEl.textContent=''; errEl.classList.remove('show'); }
                    fe.classList.remove('error');
                    if(cc) cc.classList.remove('error');
                }
                showForgotAlert('','');
            });
        }
        // Phase 2: Verify OTP
        const forgotVerifyFormEl=document.getElementById('forgotVerifyForm');
        if(forgotVerifyFormEl){
            forgotVerifyFormEl.addEventListener('submit', function(e){
                e.preventDefault();
                const code=getForgotOtpCode();
                if(code.length!==6){
                    showForgotOtpFeedback('Please enter the 6-digit verification code.','error');
                    return;
                }
                clearForgotOtpFeedback();
                const btn=document.getElementById('forgotVerifyBtn');
                const orig=btn?btn.textContent:'';
                if(btn){ btn.disabled=true; btn.textContent='Verifying...'; }
                fetch('index.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'forgot_verify=1&email='+encodeURIComponent(forgotEmail)+'&code='+encodeURIComponent(code) })
                .then(function(r){ return r.json().then(function(d){ return {ok:r.ok,d:d}; }); })
                .then(function(obj){
                    if(!obj.ok || !obj.d.success){
                        showForgotOtpFeedback((obj.d && obj.d.error) || 'Invalid or expired reset code.','error');
                        return;
                    }
                    verifiedForgotCode=code;
                    if(forgotResendInterval){ clearInterval(forgotResendInterval); forgotResendInterval=null; const lb=document.getElementById('forgotTimerLabel'); if(lb) lb.textContent=''; const rb=document.getElementById('forgotResendBtn'); if(rb) rb.disabled=false; }
                    setForgotPhase(3);
                    const np=document.getElementById('forgotNewPassword');
                    if(np) setTimeout(function(){ np.focus(); },100);
                })
                .catch(function(){ showForgotOtpFeedback('An error occurred. Please try again.','error'); })
                .finally(function(){ if(btn){ btn.disabled=false; btn.textContent=orig; } });
            });
        }
        // Resend OTP
        const forgotResendBtn=document.getElementById('forgotResendBtn');
        if(forgotResendBtn){
            forgotResendBtn.addEventListener('click', function(){
                if(!forgotEmail){ showForgotOtpFeedback('Please start again from the email step.','error'); return; }
                if(forgotResendBtn.disabled) return;
                forgotResendBtn.disabled=true;
                fetch('index.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'forgot_resend=1&email='+encodeURIComponent(forgotEmail) })
                .then(function(r){ return r.json().then(function(d){ return {ok:r.ok,d:d}; }); })
                .then(function(obj){
                    if(!obj.ok || !obj.d.success){
                        showForgotOtpFeedback((obj.d && obj.d.error) || 'Failed to resend code. Please try again.','error');
                        forgotResendBtn.disabled=false;
                        return;
                    }
                    clearForgotOtpInputs();
                    clearForgotOtpFeedback();
                    showForgotOtpFeedback('Code resent. Check your email.','success');
                    startForgotResendCooldown(60);
                    const first=forgotOtpInputs?forgotOtpInputs.querySelector('.forgot-otp-digit'):null;
                    if(first) first.focus();
                })
                .catch(function(){ showForgotOtpFeedback('An error occurred. Please try again.','error'); forgotResendBtn.disabled=false; });
            });
        }
        // Phase 3: Reset password (with inline policy)
        (function(){
            const np=document.getElementById('forgotNewPassword');
            const cp=document.getElementById('forgotConfirmPassword');
            const liveWrap=document.getElementById('forgotPwLive');
            const commonErr=document.getElementById('forgotPw-common-error');
            const submitBtn=document.getElementById('forgotResetBtn');
            if(!np||!cp) return;
            // Ensure DiariPasswordPolicy exists (fallback inline if not)
            function getPolicyReady(){
                const pw=np.value, cval=cp.value;
                const personal={ email: forgotEmail };
                if(window.DiariPasswordPolicy && window.DiariPasswordPolicy.isPasswordSubmitReady){
                    return window.DiariPasswordPolicy.isPasswordSubmitReady(pw,cval,personal);
                }
                if(pw.length<12) return false;
                if(!/[A-Z]/.test(pw)) return false;
                if(!/[a-z]/.test(pw)) return false;
                if(!/[0-9]/.test(pw)) return false;
                if(!/[!@#$%^&*()_+\-=\[\]{}|;:'",.<>?\/`~\\]/.test(pw)) return false;
                if(pw.indexOf(' ')!==-1) return false;
                if(pw!==cval || !cval) return false;
                return true;
            }
            function refreshLive(){
                const pw=np.value;
                if(!liveWrap) return;
                if(pw.length===0){ liveWrap.hidden=true; if(commonErr){ commonErr.textContent=''; commonErr.classList.remove('show'); } if(submitBtn) submitBtn.disabled=true; return; }
                liveWrap.hidden=false;
                let score=0;
                if(pw.length>=12) score++;
                if(/[A-Z]/.test(pw)) score++;
                if(/[a-z]/.test(pw)) score++;
                if(/[0-9]/.test(pw)) score++;
                if(/[!@#$%^&*()_+\-=\[\]{}|;:'",.<>?\/`~\\]/.test(pw)) score++;
                if(pw.indexOf(' ')===-1) score++;
                if(pw===cp.value && cp.value) score++;
                if(!liveWrap.querySelector('.pwd-strength')){
                    liveWrap.innerHTML='<div class="pwd-strength"><div class="pwd-strength__track" role="progressbar" aria-valuemin="0" aria-valuemax="7" aria-valuenow="0"><div class="pwd-strength__fill"></div></div><span class="pwd-strength__label">Weak</span></div>';
                }
                const fill=liveWrap.querySelector('.pwd-strength__fill');
                const label=liveWrap.querySelector('.pwd-strength__label');
                const track=liveWrap.querySelector('.pwd-strength__track');
                let band={label:'Weak',color:'#c75c5c'};
                if(score<=2) band={label:'Weak',color:'#c75c5c'};
                else if(score<=4) band={label:'Fair',color:'#d4a017'};
                else if(score<=5) band={label:'Good',color:'#9db85a'};
                else band={label:'Strong',color:'#4a7c59'};
                if(fill){ fill.style.width=Math.min(100,(score/7)*100)+'%'; fill.style.backgroundColor=band.color; }
                if(label){ label.textContent=band.label; label.style.color=band.color; }
                if(track){ track.setAttribute('aria-valuenow',String(score)); }
                // policy message
                if(commonErr){
                    let msg='';
                    if(window.DiariPasswordPolicy && window.DiariPasswordPolicy.getPasswordBlockMessage){
                        msg=window.DiariPasswordPolicy.getPasswordBlockMessage(pw,cp.value,{email:forgotEmail});
                    } else {
                        if(pw.length>64) msg='Password must be 64 characters or fewer.';
                        else if(pw.length<12) msg='Password must be at least 12 characters.';
                        else if(!/[A-Z]/.test(pw)) msg='Password must include at least one uppercase letter (A-Z).';
                        else if(!/[a-z]/.test(pw)) msg='Password must include at least one lowercase letter (a-z).';
                        else if(!/[0-9]/.test(pw)) msg='Password must include at least one number.';
                        else if(!/[!@#$%^&*()_+\-=\[\]{}|;:'",.<>?\/`~\\]/.test(pw)) msg='Password must include at least one special character (!@#$...).';
                        else if(pw.indexOf(' ')!==-1) msg='Password must not contain spaces.';
                        else if(pw!==cp.value && cp.value) msg='Passwords do not match.';
                    }
                    if(msg && !getPolicyReady()){ commonErr.textContent=msg; commonErr.classList.add('show'); } else { commonErr.textContent=''; commonErr.classList.remove('show'); }
                }
                if(submitBtn) submitBtn.disabled=!getPolicyReady();
            }
            np.addEventListener('input', function(){
                const e=document.getElementById('forgotNewPassword-error');
                if(e){ e.textContent=''; e.classList.remove('show'); }
                np.classList.remove('error');
                const c=np.closest('.input-container');
                if(c) c.classList.remove('error');
                refreshLive();
            });
            cp.addEventListener('input', function(){
                const e=document.getElementById('forgotConfirmPassword-error');
                if(e){ e.textContent=''; e.classList.remove('show'); }
                cp.classList.remove('error');
                const c=cp.closest('.input-container');
                if(c) c.classList.remove('error');
                refreshLive();
            });
        })();
        const forgotResetFormEl=document.getElementById('forgotResetForm');
        if(forgotResetFormEl){
            forgotResetFormEl.addEventListener('submit', function(e){
                e.preventDefault();
                const np=document.getElementById('forgotNewPassword');
                const cp=document.getElementById('forgotConfirmPassword');
                const pw=np?np.value:'';
                const cval=cp?cp.value:'';
                let hasErr=false;
                if(!pw){
                    const el=document.getElementById('forgotNewPassword-error');
                    if(el){ el.textContent='New password is required.'; el.classList.add('show'); }
                    if(np){ np.classList.add('error'); const c=np.closest('.input-container'); if(c) c.classList.add('error'); }
                    hasErr=true;
                }
                if(!cval){
                    const el=document.getElementById('forgotConfirmPassword-error');
                    if(el){ el.textContent='Please confirm your new password.'; el.classList.add('show'); }
                    if(cp){ cp.classList.add('error'); const c=cp.closest('.input-container'); if(c) c.classList.add('error'); }
                    hasErr=true;
                }
                if(hasErr) return;
                if(!forgotEmail || !verifiedForgotCode){
                    showForgotAlert('Session expired. Please start again.','error');
                    setForgotPhase(1);
                    return;
                }
                // Clear any individual input errors before submitting
                const nErr=document.getElementById('forgotNewPassword-error');
                if(nErr){ nErr.textContent=''; nErr.classList.remove('show'); }
                const cErr=document.getElementById('forgotConfirmPassword-error');
                if(cErr){ cErr.textContent=''; cErr.classList.remove('show'); }

                const btn=document.getElementById('forgotResetBtn');
                const orig=btn?btn.textContent:'';
                if(btn){ btn.disabled=true; btn.textContent='Updating...'; }
                fetch('index.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'forgot_reset=1&email='+encodeURIComponent(forgotEmail)+'&code='+encodeURIComponent(verifiedForgotCode)+'&new_password='+encodeURIComponent(pw)+'&confirm_password='+encodeURIComponent(cval) })
                .then(function(r){ return r.json().then(function(d){ return {ok:r.ok,d:d}; }); })
                .then(function(obj){
                    if(!obj.ok || !obj.d.success){
                        const field=obj.d && obj.d.field;
                        const msg=(obj.d && obj.d.error) || 'Password reset failed. Please try again.';
                        
                        // Clear field errors to make sure error message only appears below the strength indicator
                        const nErr2=document.getElementById('forgotNewPassword-error');
                        if(nErr2){ nErr2.textContent=''; nErr2.classList.remove('show'); }
                        const cErr2=document.getElementById('forgotConfirmPassword-error');
                        if(cErr2){ cErr2.textContent=''; cErr2.classList.remove('show'); }

                        if(field==='forgotNewPassword' || field==='password' || !field){
                            const inp=document.getElementById('forgotNewPassword');
                            if(inp){ inp.classList.add('error'); const c=inp.closest('.input-container'); if(c) c.classList.add('error'); }
                            const commonErr=document.getElementById('forgotPw-common-error');
                            if(commonErr){ commonErr.textContent=msg; commonErr.classList.add('show'); }
                        } else {
                            showForgotAlert(msg,'error');
                        }
                        return;
                    }
                    showBondToast('Password updated successfully. You can now sign in.', 'success');
                    const container=document.getElementById('forgotPasswordContainer');
                    if(container) container.style.display='none';
                    const lw=document.querySelector('.login-wrapper');
                    if(lw) lw.style.display='flex';
                    resetForgotModal();
                })
                .catch(function(){
                    const commonErr=document.getElementById('forgotPw-common-error');
                    if(commonErr){ commonErr.textContent='An error occurred. Please try again.'; commonErr.classList.add('show'); }
                    else { showForgotAlert('An error occurred. Please try again.','error'); }
                })
                .finally(function(){ if(btn){ btn.disabled=false; btn.textContent=orig; const personal={email:forgotEmail}; const ready=window.DiariPasswordPolicy?window.DiariPasswordPolicy.isPasswordSubmitReady(np.value,cp.value,personal):false; btn.disabled=!ready; } });
            });
        }

        // Inline login error helpers
        function showLoginError(msg) {
            if (!loginInlineError) return;
            loginInlineError.textContent = msg;
            loginInlineError.style.display = 'block';
            const pg = document.getElementById('loginPasswordGroup');
            if (pg) pg.classList.add('login-form-group--error');
        }
        function hideLoginError() {
            if (loginInlineError) {
                loginInlineError.textContent = '';
                loginInlineError.style.display = 'none';
            }
            const pg = document.getElementById('loginPasswordGroup');
            if (pg) pg.classList.remove('login-form-group--error');
        }

        // Login form submission - MODIFIED SECTION
        if (loginForm) {
            const loginUsernameInput = document.getElementById('email');
            const loginPasswordInput = document.getElementById('password');

            loginUsernameInput.addEventListener('input', hideLoginError);
            loginPasswordInput.addEventListener('input', hideLoginError);

            loginForm.addEventListener('submit', function(e) {
                e.preventDefault();
                hideLoginError();

                const formData = new FormData(this);
                const submitBtn = this.querySelector('button[type="submit"]');
                const originalBtnText = submitBtn.textContent;
                submitBtn.disabled = true;
                submitBtn.textContent = 'Signing In...';

                fetch('index.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `username=${encodeURIComponent(loginUsernameInput.value)}&password=${encodeURIComponent(loginPasswordInput.value)}`
                })
                .then(response => {
                    if (!response.ok) throw new Error('Network response was not ok');
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        showBondToast('Login successful! Redirecting...', 'success');
                        setTimeout(() => {
                            window.location.href = data.redirect || 'homepage.php';
                        }, 1500);
                    } else {
                        showLoginError(data.error || 'Incorrect username or password.');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showLoginError('An error occurred. Please try again.');
                })
                .finally(() => {
                    submitBtn.textContent = originalBtnText;
                    submitBtn.disabled = false;
                });
            });
        }

        // Forgot password open/close
        if (forgotPasswordButton && forgotPasswordContainer) {
            forgotPasswordButton.addEventListener('click', function(e) {
                e.preventDefault();
                resetForgotModal();
                loginWrapper.style.display = 'none';
                forgotPasswordContainer.style.display = 'flex';
                hideLoginError();
                const em=document.getElementById('forgotEmail');
                if(em) setTimeout(function(){ em.focus(); },100);
            });
            if(hideForgotPassword){
                hideForgotPassword.addEventListener('click', function(e) {
                    e.preventDefault();
                    forgotPasswordContainer.style.display = 'none';
                    loginWrapper.style.display = 'flex';
                    resetForgotModal();
                    resetLoginForm();
                });
            }
            // Click backdrop to close (optional)
            forgotPasswordContainer.addEventListener('click', function(e){
                if(e.target===forgotPasswordContainer){
                    forgotPasswordContainer.style.display='none';
                    loginWrapper.style.display='flex';
                    resetForgotModal();
                    resetLoginForm();
                }
            });
        }
    });

    // Password visibility toggle functionality
    function setupPasswordToggles() {
        // Select all password input containers
        const passwordContainers = document.querySelectorAll('.login-form-group, .input-container');

        passwordContainers.forEach(container => {
            const passwordInput = container.querySelector('input[type="password"]');
            if (!passwordInput) return;
            // Reset forms already provide their own in-container eye toggles.
            if (container.querySelector('.toggle-password')) return;

            // Create toggle element
            const toggle = document.createElement('div');
            toggle.className = 'password-toggle';
            toggle.innerHTML = '<i class="fas fa-eye"></i>';

            // Insert after the input
            const inputWrapper = passwordInput.parentElement;
            inputWrapper.classList.add('password-input-wrapper');

            const newContainer = document.createElement('div');
            newContainer.className = 'password-input-container';

            // Wrap input and toggle in new container
            inputWrapper.parentNode.insertBefore(newContainer, inputWrapper);
            newContainer.appendChild(inputWrapper);
            newContainer.appendChild(toggle);

            // Add click event
            toggle.addEventListener('click', function() {
                if (passwordInput.type === 'password') {
                    passwordInput.type = 'text';
                    this.innerHTML = '<i class="fas fa-eye-slash"></i>';
                } else {
                    passwordInput.type = 'password';
                    this.innerHTML = '<i class="fas fa-eye"></i>';
                }
            });

            // Show/hide based on input
            passwordInput.addEventListener('input', function() {
                if (this.value.length > 0) {
                    toggle.classList.add('visible');
                } else {
                    toggle.classList.remove('visible');
                }
            });

            // Also check on focus/blur for better UX
            passwordInput.addEventListener('focus', function() {
                if (this.value.length > 0) {
                    toggle.classList.add('visible');
                }
            });

            passwordInput.addEventListener('blur', function() {
                if (this.value.length === 0) {
                    toggle.classList.remove('visible');
                }
            });
        });
    }

    // Call the function when DOM is loaded
    document.addEventListener('DOMContentLoaded', function() {
        setupPasswordToggles();
    });
</script>
    
    
</body>
</html>
