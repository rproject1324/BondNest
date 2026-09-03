<?php
require_once 'db_connection.php';
require_once 'migrate.php';
runMigration($pdo);

function generateOtp() {
    return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function sendOtpEmail($email, $otpCode, $username) {
    $apiKey = getenv('BREVO_API_KEY');
    $senderEmail = getenv('BREVO_SENDER_EMAIL');
    $senderName = getenv('BREVO_SENDER_NAME') ?: 'BondNest';

    if (!$apiKey || !$senderEmail) {
        error_log("[OTP DEV MODE] $email -> $otpCode");
        return true;
    }

    $payload = [
        'sender' => ['name' => $senderName, 'email' => $senderEmail],
        'to' => [['email' => $email, 'name' => $username ?: $email]],
        'subject' => 'BondNest verification code',
        'htmlContent' => "<html><body style='font-family: Arial, sans-serif; color: #2F3E36;'>
            <h2>Verify your BondNest account</h2>
            <p>Hello " . htmlspecialchars($username) . ",</p>
            <p>Your verification code is:</p>
            <p style='font-size: 28px; font-weight: bold; letter-spacing: 6px;'>$otpCode</p>
            <p>This code expires in 10 minutes.</p>
        </body></html>",
        'textContent' => "Your BondNest verification code is $otpCode. It expires in 10 minutes.",
    ];

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'api-key: ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ($httpCode >= 200 && $httpCode < 300);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    if (isset($input['action']) && $input['action'] === 'resend') {
        $email = strtolower(trim($input['email'] ?? ''));
        if (!$email) {
            echo json_encode(['success' => false, 'error' => 'Email is required.']);
            exit;
        }
        try {
            $stmt = $pdo->prepare("SELECT username FROM pending_registrations WHERE email = ?");
            $stmt->execute([$email]);
            $pending = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$pending) {
                echo json_encode(['success' => false, 'error' => 'No pending registration found. Please sign up again.']);
                exit;
            }
            $otpCode = generateOtp();
            $otpExpires = gmdate('Y-m-d H:i:s', time() + 600);
            $update = $pdo->prepare("UPDATE pending_registrations SET otp_code = ?, otp_expires_at = ? WHERE email = ?");
            $update->execute([$otpCode, $otpExpires, $email]);
            sendOtpEmail($email, $otpCode, $pending['username']);
            echo json_encode(['success' => true, 'message' => 'Verification code resent.']);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Database error.']);
            exit;
        }
    }

    $email = strtolower(trim($input['email'] ?? ''));
    $otpCode = trim($input['otpCode'] ?? '');

    if (!$email || !$otpCode) {
        echo json_encode(['success' => false, 'error' => 'Email and verification code are required.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM pending_registrations WHERE email = ?");
        $stmt->execute([$email]);
        $pending = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$pending) {
            echo json_encode(['success' => false, 'error' => 'No pending registration found. Please sign up again.']);
            exit;
        }

        $expiresAt = new DateTime($pending['otp_expires_at'], new DateTimeZone('UTC'));
        $now = new DateTime('now', new DateTimeZone('UTC'));
        if ($now > $expiresAt) {
            echo json_encode(['success' => false, 'error' => 'Invalid or expired verification code. Please try again.']);
            exit;
        }

        if ($pending['otp_code'] !== $otpCode) {
            echo json_encode(['success' => false, 'error' => 'Invalid or expired verification code. Please try again.']);
            exit;
        }

        $computedAge = null;
        if (!empty($pending['birthday'])) {
            try {
                $b = new DateTime($pending['birthday']);
                $computedAge = (new DateTime())->diff($b)->y;
            } catch (Exception $e) {}
        }

        $pdo->beginTransaction();

        $isAdmin = isConfiguredAdminEmail($email) ? 1 : 0;

        $insert = $pdo->prepare("INSERT INTO users 
            (first_name, last_name, username, email, age, birthday, gender, password, profile_picture, recovery_code, is_admin) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $recoveryCode = bin2hex(random_bytes(5));
        $insert->execute([
            $pending['first_name'],
            $pending['last_name'],
            $pending['username'],
            $email,
            $computedAge,
            $pending['birthday'],
            $pending['gender'],
            $pending['password_hash'],
            null,
            $recoveryCode,
            $isAdmin
        ]);

        $userId = $pdo->lastInsertId();

        $delete = $pdo->prepare("DELETE FROM pending_registrations WHERE email = ?");
        $delete->execute([$email]);

        $pdo->commit();

        session_start();
        $_SESSION['user_id'] = $userId;
        $_SESSION['username'] = $pending['username'];
        $_SESSION['is_admin'] = ($isAdmin === 1);

        $redirect = ($isAdmin === 1) ? 'admin.php' : 'homepage.php';
        echo json_encode(['success' => true, 'redirect' => $redirect]);
        exit;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("Verify registration error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Database error. Please try again.']);
        exit;
    }
}

$emailParam = $_GET['email'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Registration - BondNest</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="verify-registration.css">
</head>
<body>
    <div class="page">
        <div class="verify-shell">
            <aside class="verify-side">
                <div class="verify-side-brand">
                    <div class="verify-side-text">
                        <h2>Almost There!</h2>
                        <p>Verify your account to continue your BondNest journey.</p>
                    </div>
                </div>
            </aside>

            <div class="card">
                <div class="header">
                    <div class="icon">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <h1>Account Verification</h1>
                    <p>Enter the 6-digit code sent to your email</p>
                </div>

                <div class="email" id="emailDisplay"></div>

                <div class="otp" id="otpInputs">
                    <input class="digit" inputmode="numeric" maxlength="1" aria-label="Digit 1">
                    <input class="digit" inputmode="numeric" maxlength="1" aria-label="Digit 2">
                    <input class="digit" inputmode="numeric" maxlength="1" aria-label="Digit 3">
                    <input class="digit" inputmode="numeric" maxlength="1" aria-label="Digit 4">
                    <input class="digit" inputmode="numeric" maxlength="1" aria-label="Digit 5">
                    <input class="digit" inputmode="numeric" maxlength="1" aria-label="Digit 6">
                </div>

                <div class="alert alert-error" id="otpError" hidden aria-live="polite">
                    <div class="alert__left">
                        <i class="fas fa-exclamation-circle"></i>
                        <span class="alert__text"></span>
                    </div>
                    <button class="alert__close" id="otpErrorClose" aria-label="Close">&times;</button>
                </div>
                <div class="alert alert-success" id="otpSuccess" hidden aria-live="polite">
                    <div class="alert__left">
                        <i class="fas fa-check-circle"></i>
                        <span class="alert__text"></span>
                    </div>
                    <button class="alert__close" id="otpSuccessClose" aria-label="Close">&times;</button>
                </div>

                <button class="btn" id="verifyBtn" disabled>
                    <i class="fas fa-check-circle" id="verifyBtnIcon"></i>
                    <span id="verifyBtnText">Verify Code</span>
                </button>

                <div class="meta">
                    <span class="resend-prefix">Didn't receive the code?</span>
                    <button class="link" id="resendBtn">
                        <span class="resend-spinner" aria-hidden="true"></span>
                        <span class="resend-label">Resend Code</span>
                    </button>
                    <span class="resend-countdown" id="resendCountdown" aria-live="polite"></span>
                    <div class="timer" id="timerLabel">Code expires in 10:00</div>
                </div>

                <div class="footer">
                    <a class="back" href="signup.php">
                        <i class="fas fa-arrow-left"></i>
                        Back to registration
                    </a>
                </div>
            </div>
        </div>
    </div>

<script>
(function() {
    function getQueryParam(name) {
        const url = new URL(window.location.href);
        return url.searchParams.get(name) || '';
    }

    function setError(message) {
        const box = document.getElementById('otpError');
        if (!box) return;
        const successBox = document.getElementById('otpSuccess');
        if (successBox) { successBox.classList.remove('show'); successBox.hidden = true; }
        const text = box.querySelector('.alert__text');
        if (text) text.textContent = message || '';
        if (!message) { box.classList.remove('show'); box.hidden = true; return; }
        box.hidden = false;
        requestAnimationFrame(() => box.classList.add('show'));
    }

    function setSuccess(message) {
        const box = document.getElementById('otpSuccess');
        if (!box) return;
        const errorBox = document.getElementById('otpError');
        if (errorBox) { errorBox.classList.remove('show'); errorBox.hidden = true; }
        const text = box.querySelector('.alert__text');
        if (text) text.textContent = message || '';
        if (!message) { box.classList.remove('show'); box.hidden = true; return; }
        box.hidden = false;
        requestAnimationFrame(() => box.classList.add('show'));
    }

    function maskEmail(email) {
        const [user, domain] = (email || '').split('@');
        if (!user || !domain) return email;
        const head = user.slice(0, 2);
        const tail = user.slice(-1);
        return head + '*'.repeat(Math.max(1, user.length - 3)) + tail + '@' + domain;
    }

    const email = getQueryParam('email') || sessionStorage.getItem('pendingRegistrationEmail') || '';
    const emailDisplay = document.getElementById('emailDisplay');
    const inputs = Array.from(document.querySelectorAll('.digit'));
    const verifyBtn = document.getElementById('verifyBtn');
    const resendBtn = document.getElementById('resendBtn');
    const resendCountdown = document.getElementById('resendCountdown');
    const timerLabel = document.getElementById('timerLabel');
    const errorClose = document.getElementById('otpErrorClose');
    const successClose = document.getElementById('otpSuccessClose');

    if (!email) {
        setError('No pending registration found. Please sign up again.');
        verifyBtn.disabled = true;
        resendBtn.disabled = true;
        return;
    }

    emailDisplay.textContent = maskEmail(email);
    if (errorClose) errorClose.addEventListener('click', () => setError(''));
    if (successClose) successClose.addEventListener('click', () => setSuccess(''));

    let seconds = 10 * 60;
    let resendCooldown = 0;
    let resendCooldownInterval = null;
    const renderTimer = () => {
        const m = Math.floor(seconds / 60);
        const s = seconds % 60;
        timerLabel.textContent = seconds > 0 ? 'Code expires in ' + m + ':' + String(s).padStart(2, '0') : 'Code expired. Resend a new one.';
    };
    renderTimer();
    const interval = setInterval(() => {
        seconds -= 1;
        if (seconds <= 0) { seconds = 0; clearInterval(interval); }
        renderTimer();
    }, 1000);

    const code = () => inputs.map(i => i.value).join('');
    let autoVerifyTimeout = null;
    const updateBtn = () => {
        const filled = code().length === 6;
        verifyBtn.disabled = !filled;
        if (filled) {
            if (autoVerifyTimeout) clearTimeout(autoVerifyTimeout);
            autoVerifyTimeout = setTimeout(() => { if (!verifyBtn.disabled) verifyBtn.click(); }, 350);
        }
    };

    const clearErrors = () => { setError(''); setSuccess(''); inputs.forEach(i => i.classList.remove('error')); };

    const setResendLoading = (isLoading) => {
        const label = resendBtn.querySelector('.resend-label');
        resendBtn.classList.toggle('is-loading', isLoading);
        if (label) label.textContent = isLoading ? 'Sending...' : 'Resend Code';
    };

    const startResendCooldown = () => {
        resendCooldown = 60;
        setResendLoading(false);
        resendBtn.disabled = true;
        if (resendCooldownInterval) clearInterval(resendCooldownInterval);
        const renderCooldown = () => {
            const m = Math.floor(resendCooldown / 60);
            const s = resendCooldown % 60;
            if (resendCountdown) resendCountdown.textContent = ' (' + m + ':' + String(s).padStart(2, '0') + ')';
        };
        renderCooldown();
        resendCooldownInterval = setInterval(() => {
            resendCooldown -= 1;
            if (resendCooldown <= 0) {
                clearInterval(resendCooldownInterval);
                resendBtn.disabled = false;
                setResendLoading(false);
                if (resendCountdown) resendCountdown.textContent = '';
                return;
            }
            renderCooldown();
        }, 1000);
    };
    startResendCooldown();

    inputs.forEach((input, idx) => {
        input.addEventListener('input', (e) => {
            const v = (e.target.value || '').replace(/\D/g, '').slice(-1);
            e.target.value = v;
            clearErrors();
            if (v && idx < inputs.length - 1) inputs[idx + 1].focus();
            updateBtn();
        });
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Backspace' && !input.value && idx > 0) inputs[idx - 1].focus();
        });
        input.addEventListener('paste', (e) => {
            e.preventDefault();
            const digits = (e.clipboardData.getData('text') || '').replace(/\D/g, '').slice(0, 6).split('');
            inputs.forEach((d, i) => { d.value = digits[i] || ''; });
            clearErrors();
            updateBtn();
        });
    });

    verifyBtn.addEventListener('click', () => {
        const otpCode = code();
        if (otpCode.length !== 6) {
            setError('Please enter the 6-digit code.');
            inputs.forEach(i => i.classList.add('error'));
            return;
        }
        verifyBtn.disabled = true;
        verifyBtn.classList.add('is-loading');
        document.getElementById('verifyBtnIcon').outerHTML = '<span class="spinner" aria-hidden="true" id="verifyBtnIcon"></span>';
        document.getElementById('verifyBtnText').textContent = 'Verifying...';

        fetch('verify-registration.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email: email, otpCode: otpCode })
        })
        .then(res => res.json().then(data => ({ ok: res.ok, data })))
        .then(({ ok, data }) => {
            if (!ok || !data.success) {
                setError(data.error || 'Invalid or expired verification code. Please try again.');
                inputs.forEach(i => { i.value = ''; i.classList.add('error'); });
                inputs[0] && inputs[0].focus();
                verifyBtn.disabled = false;
                verifyBtn.classList.remove('is-loading');
                verifyBtn.innerHTML = '<i class="fas fa-check-circle" id="verifyBtnIcon"></i> <span id="verifyBtnText">Verify Code</span>';
                return;
            }
            sessionStorage.removeItem('pendingRegistrationEmail');
            window.location.href = data.redirect || 'homepage.php';
        })
        .catch(() => {
            setError('Could not verify right now. Please try again.');
            verifyBtn.disabled = false;
            verifyBtn.classList.remove('is-loading');
            verifyBtn.innerHTML = '<i class="fas fa-check-circle" id="verifyBtnIcon"></i> <span id="verifyBtnText">Verify Code</span>';
        });
    });

    resendBtn.addEventListener('click', () => {
        if (resendBtn.disabled) return;
        resendBtn.disabled = true;
        setResendLoading(true);
        clearErrors();

        fetch('verify-registration.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'resend', email: email })
        })
        .then(res => res.json().then(data => ({ ok: res.ok, data })))
        .then(({ ok, data }) => {
            if (!ok || !data.success) {
                setError(data.error || 'Failed to resend code.');
                resendBtn.disabled = false;
                setResendLoading(false);
                return;
            }
            seconds = 10 * 60;
            renderTimer();
            inputs.forEach(i => i.value = '');
            inputs[0] && inputs[0].focus();
            updateBtn();
            setSuccess('Verification code has been resent to your email.');
            startResendCooldown();
        })
        .catch(() => {
            setError('Failed to resend code.');
            resendBtn.disabled = false;
            setResendLoading(false);
        });
    });

    inputs[0] && inputs[0].focus();
    updateBtn();
})();
</script>
</body>
</html>
