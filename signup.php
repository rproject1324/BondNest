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

    if ($httpCode >= 200 && $httpCode < 300) {
        return true;
    }
    error_log("[OTP SEND FAILED] $email HTTP $httpCode: $result");
    return false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    if (isset($_POST['check_username'])) {
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
    }

    $errors = [];
    $required = ['firstName', 'lastName', 'username', 'email', 'birthday', 'gender', 'createPassword', 'confirmPassword'];
    foreach ($required as $field) {
        if (empty($_POST[$field])) {
            $errors[] = ucfirst($field) . ' is required.';
        }
    }

    if (!empty($_POST['email']) && !filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email format.';
    }

    if (($_POST['createPassword'] ?? '') !== ($_POST['confirmPassword'] ?? '')) {
        $errors[] = 'Passwords do not match.';
    }

    $pw = $_POST['createPassword'] ?? '';
    if (strlen($pw) < 12) {
        $errors[] = 'Password must be at least 12 characters.';
    } elseif (strlen($pw) > 64) {
        $errors[] = 'Password must not exceed 64 characters.';
    } elseif (strpos($pw, ' ') !== false) {
        $errors[] = 'Password must not contain spaces.';
    } elseif (!preg_match('/[A-Z]/', $pw)) {
        $errors[] = 'Password must include at least one uppercase letter (A-Z).';
    } elseif (!preg_match('/[a-z]/', $pw)) {
        $errors[] = 'Password must include at least one lowercase letter (a-z).';
    } elseif (!preg_match('/[0-9]/', $pw)) {
        $errors[] = 'Password must include at least one digit (0-9).';
    } elseif (!preg_match('/[!@#$%^&*()_+\-=\[\]{}|;:\'",.<>?\/`~\\\\]/', $pw)) {
        $errors[] = 'Password must include at least one special character (!@#$...).';
    } else {
        $common = ['password','12345678','123456789','qwerty','qwerty123','111111','iloveyou','admin','welcome','monkey','dragon','letmein','abc123','password1'];
        if (in_array(strtolower($pw), $common)) {
            $errors[] = 'This password is too common. Choose a less predictable password.';
        } else {
            $pl = strtolower($pw);
            $personal = [strtolower($_POST['username']??''), strtolower($_POST['email']??''), strtolower($_POST['firstName']??''), strtolower($_POST['lastName']??'')];
            foreach ($personal as $idx=>$tok) {
                $t = trim($tok);
                if (strlen($t) >= 2 && strpos($pl, $t) !== false) {
                    $labels = ['username','email address','first name','last name'];
                    $errors[] = 'Password must not contain your ' . $labels[$idx] . '.';
                    break;
                }
            }
        }
    }

    if (empty($errors) && !empty($_POST['email'])) {
        try {
            $check = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $check->execute([$_POST['email']]);
            if ($check->fetch()) {
                $errors[] = 'Email is already registered.';
            }
        } catch (PDOException $e) { }
        try {
            $checkU = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
            $checkU->execute([$_POST['username']]);
            if ($checkU->fetch()) {
                $errors[] = 'Username is already taken.';
            }
        } catch (PDOException $e) { }
    }

    if (empty($errors)) {
        $otpCode = generateOtp();
        $otpExpires = gmdate('Y-m-d H:i:s', time() + 600);
        $hashedPw = password_hash($pw, PASSWORD_DEFAULT);
        $email = $_POST['email'];
        $username = $_POST['username'];

        try {
            $stmt = $pdo->prepare("INSERT INTO pending_registrations 
                (email, username, password_hash, first_name, last_name, gender, birthday, otp_code, otp_expires_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT (email) DO UPDATE SET 
                    username = EXCLUDED.username,
                    password_hash = EXCLUDED.password_hash,
                    first_name = EXCLUDED.first_name,
                    last_name = EXCLUDED.last_name,
                    gender = EXCLUDED.gender,
                    birthday = EXCLUDED.birthday,
                    otp_code = EXCLUDED.otp_code,
                    otp_expires_at = EXCLUDED.otp_expires_at,
                    created_at = CURRENT_TIMESTAMP");
            $stmt->execute([
                $email,
                $username,
                $hashedPw,
                htmlspecialchars($_POST['firstName']),
                htmlspecialchars($_POST['lastName']),
                $_POST['gender'],
                $_POST['birthday'],
                $otpCode,
                $otpExpires
            ]);

            if (!sendOtpEmail($email, $otpCode, $username)) {
                echo json_encode(['success' => false, 'errors' => ['Failed to send verification code. Please try again.']]);
                exit;
            }

            echo json_encode(['success' => true, 'email' => $email]);
            exit;
        } catch (PDOException $e) {
            error_log("Pending registration error: " . $e->getMessage());
            echo json_encode(['success' => false, 'errors' => ['Database error. Please try again.']]);
            exit;
        }
    } else {
        echo json_encode(['success' => false, 'errors' => $errors]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BondNest - Sign Up</title>
    <link rel="stylesheet" href="login-signup.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            background: linear-gradient(to top left, #4397d3, transparent 50%),
                linear-gradient(to bottom right, #7cc6c0, transparent 50%);
            background-size: 200% 200%;
            animation: flow 8s ease-in-out infinite alternate;
            overflow: hidden;
        }
        .signup-page-wrapper {
            display: flex;
            flex-direction: column;
            align-items: center;
            width: 90%;
            max-width: 720px;
            animation: fadeInUp 0.5s ease;
        }
        .signup-page-wrapper .signup-form-wrapper {
            width: 100%;
        }
    </style>
</head>
<body>
    <div class="flying-bird">
        <img src="./web-images/bird.gif" alt="Flying Bird">
    </div>

    <div class="flying-bird-secondary">
        <img src="./web-images/bird.gif" alt="Flying Bird Secondary">
    </div>

    <div class="signup-page-wrapper">
        <div class="signup-form-wrapper">
            <div class="signup-header">
                <h2 class="create-account-title">Join BondNest</h2>
                <p class="create-account-subtitle">Create your account to start connecting</p>
            </div>

            <form class="create-account-form" id="createAccountForm" novalidate>
                <div class="form-row">
                    <div class="form-group">
                        <div class="input-container">
                            <div class="icon-container"><i class="fas fa-at"></i></div>
                            <input type="text" id="username" name="username" class="form-input" placeholder="Username" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" required>
                        </div>
                        <div class="custom-error" id="username-error">Username is required.</div>
                    </div>

                    <div class="form-group">
                        <div class="input-container">
                            <div class="icon-container"><i class="fas fa-envelope"></i></div>
                            <input type="email" id="signupEmail" name="email" class="form-input" placeholder="Email" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" required>
                        </div>
                        <div class="custom-error" id="signupEmail-error">Email is required.</div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <div class="input-container">
                            <div class="icon-container"><i class="fas fa-user"></i></div>
                            <input type="text" id="firstName" name="firstName" class="form-input" placeholder="First Name" autocomplete="off" autocapitalize="words" autocorrect="off" spellcheck="false" required>
                        </div>
                        <div class="custom-error" id="firstName-error">First name is required.</div>
                    </div>

                    <div class="form-group">
                        <div class="input-container">
                            <div class="icon-container"><i class="fas fa-user"></i></div>
                            <input type="text" id="lastName" name="lastName" class="form-input" placeholder="Last Name" autocomplete="off" autocapitalize="words" autocorrect="off" spellcheck="false" required>
                        </div>
                        <div class="custom-error" id="lastName-error">Last name is required.</div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <div class="input-container" id="birthdayWrapper">
                            <div class="icon-container" id="birthdayIcon" style="cursor:pointer;"><i class="fas fa-calendar-alt"></i></div>
                            <input type="date" id="birthday" name="birthday" class="form-input form-date-input" placeholder="Birthday" autocomplete="off" required>
                        </div>
                        <div class="custom-error" id="birthday-error">Date of birth is required.</div>
                    </div>

                    <div class="form-group">
                        <div class="input-container">
                            <div class="icon-container"><i class="fas fa-venus-mars"></i></div>
                            <select id="gender" name="gender" class="form-input form-select-input" required>
                                <option value="" disabled selected>Gender</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                                <option value="Prefer not to say">Prefer not to say</option>
                            </select>
                        </div>
                        <div class="custom-error" id="gender-error">Gender is required.</div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <div class="input-container">
                            <div class="icon-container"><i class="fas fa-lock"></i></div>
                            <input type="password" id="createPassword" name="createPassword" class="form-input" placeholder="Password" autocomplete="new-password" required maxlength="64">
                            <i class="fas fa-eye-slash input-icon toggle-password" id="toggleCreatePassword" style="cursor:pointer; color:#9AA9A1;"></i>
                        </div>
                        <div class="custom-error" id="createPassword-error">Password is required.</div>
                    </div>

                    <div class="form-group">
                        <div class="input-container">
                            <div class="icon-container"><i class="fas fa-lock"></i></div>
                            <input type="password" id="confirmPassword" name="confirmPassword" class="form-input" placeholder="Confirm Password" autocomplete="new-password" required maxlength="64">
                            <i class="fas fa-eye-slash input-icon toggle-password" id="toggleConfirmPassword" style="cursor:pointer; color:#9AA9A1;"></i>
                        </div>
                        <div class="custom-error" id="confirmPassword-error">Password confirmation is required.</div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group form-group--pwd-full">
                        <div class="pwd-live" id="signUpPwLive" hidden></div>
                        <div class="custom-error" id="signUpPassword-common-error"></div>
                    </div>
                </div>

                <button type="submit" class="create-account-button" id="signUpSubmitBtn" disabled>Sign Up</button>

                <div class="form-footer">
                    <p class="terms-policy">
                        By joining, you agree to our 
                        <a href="#" class="link terms-link">Terms</a> and 
                        <a href="#" class="link terms-link">Privacy Policy</a>
                    </p>
                    <div class="back-to-login">
                        Already have an account? <a href="index.php" class="link">Back to Log In</a>
                    </div>
                </div>
            </form>
        </div>

    <!-- Terms & Privacy Modal -->
    <style>
        .terms-overlay{position:fixed;inset:0;z-index:10050;display:none;align-items:center;justify-content:center;background:rgba(30,70,80,0.50);backdrop-filter:blur(3px);opacity:0;transition:opacity .25s ease}.terms-overlay.is-open{display:flex;opacity:1}
        .terms-panel{display:flex;flex-direction:column;width:100%;max-width:34rem;max-height:min(88vh,40rem);background:#f8fafb;border-radius:14px;box-shadow:0 8px 32px rgba(0,0,0,0.18);overflow:hidden;border:1px solid rgba(43,158,158,0.10)}
        .terms-header{background:linear-gradient(135deg,#f0f7f7 0%,#e8f4f3 100%);border-bottom:1px solid rgba(43,158,158,0.12);padding:18px 22px;display:flex;align-items:center;justify-content:space-between}
        .terms-header-left{display:flex;align-items:center;gap:10px}
        .terms-header-icon{width:36px;height:36px;background:linear-gradient(135deg,#2B9E9E,#3CB5A6);border-radius:10px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:17px}
        .terms-header h2{font-size:1.05rem;font-weight:600;color:#1A3A3A;margin:0;font-family:'Poppins',sans-serif}
        .terms-close-btn{background:none;border:none;font-size:18px;color:#6B8F8F;cursor:pointer;padding:4px 8px;border-radius:6px;transition:all .2s;line-height:1}.terms-close-btn:hover{background:rgba(43,158,158,0.08);color:#2B9E9E}
        .terms-progress{padding:10px 22px 0;display:flex;align-items:center;gap:8px}
        .terms-progress-bar{flex:1;height:3px;background:rgba(43,158,158,0.10);border-radius:2px;overflow:hidden}
        .terms-progress-fill{height:100%;width:0%;background:linear-gradient(90deg,#2B9E9E,#3CB5A6);border-radius:2px;transition:width .3s ease}
        .terms-progress-text{font-size:.7rem;color:#7FA8A8;font-weight:500;white-space:nowrap}
        .terms-body{flex:1;min-height:0;overflow-y:auto;padding:14px 22px 18px;scroll-behavior:smooth}
        .terms-body::-webkit-scrollbar{width:5px}.terms-body::-webkit-scrollbar-track{background:rgba(43,158,158,0.04)}.terms-body::-webkit-scrollbar-thumb{background:rgba(43,158,158,0.2);border-radius:3px}
        .terms-intro{font-size:.88rem;color:#2F3E36;margin:0 0 14px;line-height:1.55}
        .terms-hint{font-size:.72rem;color:#2B9E9E;font-style:italic;margin-bottom:14px;display:flex;align-items:center;gap:5px}.terms-hint i{font-size:.68rem}
        .terms-section{background:#fff;border-radius:10px;padding:16px 18px;margin-bottom:12px;border-left:3px solid #2B9E9E;box-shadow:0 1px 4px rgba(43,158,158,0.06);transition:border-color .3s}
        .terms-section.scrolled-past{border-left-color:#3CB5A6}
        .terms-section:last-child{margin-bottom:0}
        .terms-section-head{display:flex;align-items:center;gap:10px;margin-bottom:10px;flex-wrap:wrap}
        .terms-section-icon{width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0}
        .terms-section-icon--teal{background:rgba(43,158,158,0.10);color:#2B9E9E}
        .terms-section-icon--amber{background:rgba(200,140,40,0.12);color:#9A7A20}
        .terms-section-title{font-size:.92rem;font-weight:600;color:#1A3A3A;flex:1;min-width:0}
        .terms-tag{font-size:.62rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;padding:2px 8px;border-radius:4px;flex-shrink:0}
        .terms-tag--required{background:rgba(43,158,158,0.10);color:#2B9E9E}
        .terms-tag--note{background:rgba(200,140,40,0.12);color:#9A7A20}
        .terms-section-body{font-size:.82rem;line-height:1.6;color:#455858;margin:0}
        .terms-footer{border-top:1px solid rgba(43,158,158,0.10);padding:16px 22px;background:#f4f8f8;display:flex;flex-direction:column;gap:14px}
        .terms-check-label{display:flex;align-items:flex-start;gap:10px;font-size:.82rem;color:#2F3E36;cursor:pointer;line-height:1.45}
        .terms-checkbox{accent-color:#2B9E9E;width:16px;height:16px;margin-top:2px;flex-shrink:0;cursor:pointer}
        .terms-actions{display:flex;gap:10px;justify-content:flex-end}
        .terms-btn{padding:9px 20px;border-radius:8px;font-size:.84rem;font-weight:500;cursor:pointer;transition:all .2s;border:none;font-family:'Poppins',sans-serif}
        .terms-btn--cancel{background:#fff;color:#6B8F8F;border:1px solid #D5DFD9}.terms-btn--cancel:hover{background:#f0f4f2;border-color:#bcc9c0}
        .terms-btn--agree{background:#2B9E9E;color:#fff}.terms-btn--agree:hover{background:#248C8C}
        .terms-btn--agree:disabled{background:#A8D4D4;color:rgba(255,255,255,0.8);cursor:not-allowed;opacity:.7}
        html.terms-modal-open,body.terms-modal-open{overflow:hidden}
        @media(max-width:480px){.terms-panel{max-height:min(92vh,100%);border-radius:10px}.terms-actions{flex-direction:column-reverse}.terms-btn{width:100%;text-align:center}}
    </style>

    <div id="termsPrivacyModal" class="terms-overlay" role="dialog" aria-modal="true" aria-labelledby="termsModalTitle" aria-hidden="true">
        <div class="terms-panel" tabindex="-1">
            <div class="terms-header">
                <div class="terms-header-left">
                    <div class="terms-header-icon"><i class="fas fa-file-contract"></i></div>
                    <h2 id="termsModalTitle">Terms & Privacy Agreement</h2>
                </div>
                <button class="terms-close-btn" id="termsCloseBtn" aria-label="Close"><i class="fas fa-times"></i></button>
            </div>

            <div class="terms-progress">
                <div class="terms-progress-bar"><div class="terms-progress-fill" id="termsProgressFill"></div></div>
                <span class="terms-progress-text" id="termsProgressText">0% read</span>
            </div>

            <div class="terms-body" id="termsScrollArea">
                <p class="terms-intro">Before creating your BondNest account, please review and agree to the following terms and policies.</p>
                <p class="terms-hint"><i class="fas fa-arrow-down"></i> Scroll to read all sections</p>

                <div class="terms-sections">

                    <section class="terms-section" data-section="1">
                        <div class="terms-section-head">
                            <div class="terms-section-icon terms-section-icon--teal"><i class="fas fa-scroll"></i></div>
                            <h3 class="terms-section-title">Terms of Service</h3>
                            <span class="terms-tag terms-tag--required">Required</span>
                        </div>
                        <p class="terms-section-body">By creating an account on BondNest, you agree to use the platform only for lawful purposes and in accordance with these Terms. You are responsible for maintaining the confidentiality of your account credentials and for all activities that occur under your account. BondNest reserves the right to suspend or terminate accounts that violate these Terms, engage in fraudulent activity, or compromise the security of the platform. You must be at least 13 years of age to use BondNest. We reserve the right to modify or discontinue any part of the service at any time with reasonable notice.</p>
                    </section>

                    <section class="terms-section" data-section="2">
                        <div class="terms-section-head">
                            <div class="terms-section-icon terms-section-icon--teal"><i class="fas fa-shield-halved"></i></div>
                            <h3 class="terms-section-title">Privacy Policy</h3>
                            <span class="terms-tag terms-tag--required">Required</span>
                        </div>
                        <p class="terms-section-body">BondNest collects personal information you provide during registration, including your name, email address, date of birth, and gender. We also collect content you create on the platform, such as posts, comments, messages, and profile information. This data is used to operate and improve the BondNest experience, personalize your content feed, and communicate important account updates. We do not sell your personal data to third parties. Your information may be shared only with service providers who assist in operating the platform, or when required by law. You have the right to access, correct, or delete your personal data at any time through your account settings.</p>
                    </section>

                    <section class="terms-section" data-section="3">
                        <div class="terms-section-head">
                            <div class="terms-section-icon terms-section-icon--amber"><i class="fas fa-users"></i></div>
                            <h3 class="terms-section-title">Community Guidelines</h3>
                            <span class="terms-tag terms-tag--note">Important</span>
                        </div>
                        <p class="terms-section-body">BondNest is built on mutual respect and meaningful connections. When using the platform, you agree not to post content that is harassing, threatening, defamatory, hateful, or sexually explicit. Impersonating another person or creating fake accounts is strictly prohibited. Spam, advertising, and unsolicited promotional content are not allowed. You are solely responsible for the content you share — BondNest does not endorse user-generated content. We employ moderation tools and community reporting to maintain a safe environment. Violations may result in content removal, temporary restrictions, or permanent account termination depending on severity.</p>
                    </section>

                    <section class="terms-section" data-section="4">
                        <div class="terms-section-head">
                            <div class="terms-section-icon terms-section-icon--teal"><i class="fas fa-lock"></i></div>
                            <h3 class="terms-section-title">Data & Security</h3>
                            <span class="terms-tag terms-tag--required">Required</span>
                        </div>
                        <p class="terms-section-body">We take the security of your data seriously. BondNest uses industry-standard encryption for data in transit and at rest. Your password is securely hashed and is never stored in plain text. In the event of a data breach that affects your personal information, we will notify you promptly via email and provide guidance on protective measures. Account sessions are managed securely and you can review active sessions through your account settings. While we implement robust security measures, no system is completely immune to threats — you are encouraged to use a strong, unique password and enable any additional security features we make available.</p>
                    </section>

                </div>
            </div>

            <div class="terms-footer">
                <label class="terms-check-label" for="termsCheckbox">
                    <input type="checkbox" id="termsCheckbox" class="terms-checkbox" autocomplete="off">
                    <span>I have read all sections above and agree to BondNest's Terms of Service and Privacy Policy.</span>
                </label>
                <div class="terms-actions">
                    <button class="terms-btn terms-btn--cancel" id="termsCancelBtn">Cancel</button>
                    <button class="terms-btn terms-btn--agree" id="termsAgreeBtn" disabled>I Agree & Continue</button>
                </div>
            </div>
        </div>
    </div>

    <div class="neggy-container">
        <div class="header">
            <div class="neg-icon">N</div>
            <h4>Neggy Says...</h4>
        </div>
        <div id="neggy-messages"></div>
    </div>

    <script>
    (function(){
        const modal = document.getElementById('termsPrivacyModal');
        const scrollArea = document.getElementById('termsScrollArea');
        const checkbox = document.getElementById('termsCheckbox');
        const agreeBtn = document.getElementById('termsAgreeBtn');
        const cancelBtn = document.getElementById('termsCancelBtn');
        const closeBtn = document.getElementById('termsCloseBtn');
        const progressFill = document.getElementById('termsProgressFill');
        const progressText = document.getElementById('termsProgressText');
        const termsLinks = document.querySelectorAll('.terms-link');
        const signUpBtn = document.getElementById('signUpSubmitBtn');
        let hasAgreedToTerms = false;
        let modalReturnFocus = null;
        const sections = modal ? modal.querySelectorAll('.terms-section') : [];

        function updateProgress(){
            if(!scrollArea || !progressFill || !progressText) return;
            const scrollTop = scrollArea.scrollTop;
            const scrollHeight = scrollArea.scrollHeight - scrollArea.clientHeight;
            if(scrollHeight <= 0){ progressFill.style.width = '100%'; progressText.textContent = '100% read'; return; }
            const pct = Math.min(100, Math.round((scrollTop / scrollHeight) * 100));
            progressFill.style.width = pct + '%';
            progressText.textContent = pct + '% read';
            sections.forEach(sec => {
                const rect = sec.getBoundingClientRect();
                const areaRect = scrollArea.getBoundingClientRect();
                if(rect.top < areaRect.bottom - 60) sec.classList.add('scrolled-past');
            });
        }

        function syncAgreeBtn(){
            if(!agreeBtn || !checkbox) return;
            agreeBtn.disabled = !checkbox.checked;
        }

        function openModal(){
            if(!modal) return;
            modalReturnFocus = document.activeElement;
            if(checkbox) checkbox.checked = false;
            syncAgreeBtn();
            if(scrollArea) scrollArea.scrollTop = 0;
            sections.forEach(sec => sec.classList.remove('scrolled-past'));
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden','false');
            document.documentElement.classList.add('terms-modal-open');
            document.body.classList.add('terms-modal-open');
            updateProgress();
            setTimeout(()=>{ if(checkbox) checkbox.focus(); }, 100);
        }

        function closeModal(){
            if(!modal || !modal.classList.contains('is-open')) return;
            modal.classList.remove('is-open');
            modal.setAttribute('aria-hidden','true');
            document.documentElement.classList.remove('terms-modal-open');
            document.body.classList.remove('terms-modal-open');
            if(checkbox) checkbox.checked = false;
            syncAgreeBtn();
            const el = modalReturnFocus;
            modalReturnFocus = null;
            if(el && typeof el.focus === 'function') try{ el.focus(); }catch(_){}
        }

        if(checkbox) checkbox.addEventListener('change', syncAgreeBtn);
        if(cancelBtn) cancelBtn.addEventListener('click', closeModal);
        if(closeBtn) closeBtn.addEventListener('click', closeModal);
        if(scrollArea) scrollArea.addEventListener('scroll', updateProgress);
        if(agreeBtn) agreeBtn.addEventListener('click', function(){
            if(!checkbox || !checkbox.checked) return;
            hasAgreedToTerms = true;
            closeModal();
            // Fallback: validate the whole form so empty/partial inputs show
            // their inline errors instead of silently exiting to a blank form.
            // Only proceeds to submit when every field is valid.
            if(typeof window.BondValidateSignupForm === 'function'){
                const valid = window.BondValidateSignupForm();
                if(!valid) return;
            }
            if(signUpBtn && !signUpBtn.disabled) signUpBtn.click();
        });

        termsLinks.forEach(link => {
            link.addEventListener('click', function(e){
                e.preventDefault();
                openModal();
            });
        });

        if(signUpBtn){
            signUpBtn.addEventListener('click', function(e){
                if(!hasAgreedToTerms){
                    e.preventDefault();
                    e.stopPropagation();
                    openModal();
                    return false;
                }
            });
        }

        document.addEventListener('keydown', function(ev){
            if(ev.key !== 'Escape') return;
            if(!modal || !modal.classList.contains('is-open')) return;
            ev.preventDefault();
            closeModal();
        });

        window.BondTermsModal = { open: openModal, close: closeModal, hasAgreed: function(){ return hasAgreedToTerms; } };
    })();
    </script>
    <script>
    const signupForm = document.getElementById('createAccountForm');
    const neggyContainer = document.querySelector('.neggy-container');
    const neggyMessages = document.getElementById('neggy-messages');

    if (neggyContainer) neggyContainer.style.display = 'none';

    function showNeggyMessage(message, isSuccess = false) {
        if (!neggyContainer || !neggyMessages) return;
        neggyContainer.classList.remove('success', 'error');
        neggyContainer.style.display = 'block';
        neggyMessages.innerHTML = '<p>' + message + '</p>';
        if (isSuccess) {
            neggyContainer.classList.add('success');
        } else {
            neggyContainer.classList.add('error');
            setTimeout(() => { neggyContainer.style.display = 'none'; }, 10000);
        }
    }

    // Floating labels + eye toggle + birthday picker
    (function(){
        const inputs = document.querySelectorAll('#createAccountForm .form-input');
        inputs.forEach(input => {
            const wrapper = input.closest('.input-wrapper');
            if (!wrapper || wrapper.classList.contains('input-wrapper--static-label')) return;
            const upd = () => wrapper.classList.toggle('has-content', input.value.trim() !== '' || (input.tagName==='SELECT' && input.value!==''));
            input.addEventListener('input', upd);
            input.addEventListener('change', upd);
            input.addEventListener('blur', upd);
            upd();
        });
        document.querySelectorAll('#createAccountForm .toggle-password').forEach(icon=>{
            icon.addEventListener('click', ()=>{
                const w = icon.closest('.input-wrapper, .input-container');
                const inp = w ? w.querySelector('.form-input') : document.getElementById(icon.id==='toggleCreatePassword'?'createPassword':'confirmPassword');
                if (!inp) return;
                const isPwd = inp.type==='password';
                inp.type = isPwd ? 'text' : 'password';
                icon.classList.toggle('fa-eye-slash');
                icon.classList.toggle('fa-eye');
            });
        });
        const bInput = document.getElementById('birthday');
        const bIcon = document.getElementById('birthdayIcon');
        const bWrapper = document.getElementById('birthdayWrapper');
        function openBirthdayPicker(e){
            if(e) e.preventDefault();
            if(!bInput) return;
            try{
                if(typeof bInput.showPicker==='function') bInput.showPicker();
                else bInput.focus();
                bInput.click();
            }catch(_){ bInput.focus(); }
        }
        if(bIcon) bIcon.addEventListener('click', openBirthdayPicker);
        if(bWrapper) bWrapper.addEventListener('click', (e)=>{
            if(e.target.closest('#birthdayIcon') || e.target.closest('.icon-container')) openBirthdayPicker(e);
        });
    })();

    // DiariCore password policy
    (function(global){
        var COMMON_PASSWORDS=['password','12345678','123456789','qwerty','qwerty123','111111','iloveyou','admin','welcome','monkey','dragon','letmein','abc123','password1'];
        var COMMON_SET={}; for(var i=0;i<COMMON_PASSWORDS.length;i++) COMMON_SET[COMMON_PASSWORDS[i].toLowerCase()]=true;
        var SPECIAL_CHARS="!@#$%^&*()_+-=[]{}|;:'\",.<>?/`~\\";
        function hasSpecialChar(p){ for(var i=0;i<SPECIAL_CHARS.length;i++) if(p.indexOf(SPECIAL_CHARS.charAt(i))!==-1) return true; return false; }
        var MIN_LEN=12, MAX_LEN=64;
        function norm(s){ return String(s||'').trim(); }
        function containsPersonal(pl, token){ var t=norm(token).toLowerCase(); if(t.length<2) return false; return pl.indexOf(t)!==-1; }
        function isCommonPassword(password){ var l=String(password||'').trim().toLowerCase(); return !!COMMON_SET[l]; }
        function getChecklistState(password, personal){
            var p=password!=null?String(password):''; var pl=p.toLowerCase(); var per=personal||{};
            return { len12:p.length>=MIN_LEN, upper:/[A-Z]/.test(p), lower:/[a-z]/.test(p), digit:/[0-9]/.test(p), special:hasSpecialChar(p), noSpace:p.indexOf(' ') === -1, noPersonal:!(containsPersonal(pl,per.nickname)||containsPersonal(pl,per.email)||containsPersonal(pl,per.firstName)||containsPersonal(pl,per.lastName)) };
        }
        function countChecklistPassed(state){ var n=0; if(state.len12)n++; if(state.upper)n++; if(state.lower)n++; if(state.digit)n++; if(state.special)n++; if(state.noSpace)n++; if(state.noPersonal)n++; return n; }
        function passwordsMatch(p,c){ return String(c||'')===String(p||'') && String(c||'').length>0; }
        function getStrengthScoreMeterOnly(p,c){
            var pp=p!=null?String(p):''; var state={ len12:pp.length>=MIN_LEN, upper:/[A-Z]/.test(pp), lower:/[a-z]/.test(pp), digit:/[0-9]/.test(pp), special:hasSpecialChar(pp), noSpace:pp.indexOf(' ') === -1 };
            var cc=0; if(state.len12)cc++; if(state.upper)cc++; if(state.lower)cc++; if(state.digit)cc++; if(state.special)cc++; if(state.noSpace)cc++; if(passwordsMatch(pp,c))cc+=1; return cc;
        }
        function getStrengthBandMeter(score){ if(score<=2) return {key:'weak',label:'Weak',color:'#c75c5c'}; if(score<=4) return {key:'fair',label:'Fair',color:'#d4a017'}; if(score<=5) return {key:'good',label:'Good',color:'#9db85a'}; return {key:'strong',label:'Strong',color:'#4a7c59'}; }
        function getPasswordBlockMessage(password, confirm, personal){
            var p=String(password||''); var c=String(confirm||''); if(!p.trim()) return 'Enter a password to continue.'; if(p.length>MAX_LEN) return 'Password must be '+MAX_LEN+' characters or fewer.'; if(isCommonPassword(p)) return 'This password is too common. Choose a less predictable password.'; var state=getChecklistState(p,personal); if(!state.len12) return 'Password must be at least '+MIN_LEN+' characters.'; if(!state.upper) return 'Password must include at least one uppercase letter (A-Z).'; if(!state.lower) return 'Password must include at least one lowercase letter (a-z).'; if(!state.digit) return 'Password must include at least one number.'; if(!state.special) return 'Password must include at least one special character (!@#$...).'; if(!state.noSpace) return 'Password must not contain spaces.'; if(!state.noPersonal){ var per=personal||{}; var hits=[]; if(containsPersonal(p.toLowerCase(),per.nickname)) hits.push('username'); if(containsPersonal(p.toLowerCase(),per.firstName)) hits.push('first name'); if(containsPersonal(p.toLowerCase(),per.lastName)) hits.push('last name'); if(containsPersonal(p.toLowerCase(),per.email)) hits.push('email'); var hint=hits.length>0?' (matched your '+hits.join(', ')+')':''; return 'Password must not contain your username, first name, last name, or email'+hint+'. Use a different password that does not include those words.'; } if(!passwordsMatch(p,c)) return 'Passwords do not match. Check confirm password.'; return '';
        }
        function isPasswordSubmitReady(p,c,personal){ var pp=String(p||''); if(pp.length>MAX_LEN) return false; var st=getChecklistState(pp,personal); var cnt=countChecklistPassed(st); if(cnt!==7) return false; if(!passwordsMatch(pp,c)) return false; if(isCommonPassword(pp)) return false; return true; }
        global.DiariPasswordPolicy={MIN_LEN:MIN_LEN, MAX_LEN:MAX_LEN, getChecklistState:getChecklistState, getStrengthScoreMeterOnly:getStrengthScoreMeterOnly, getStrengthBandMeter:getStrengthBandMeter, isCommonPassword:isCommonPassword, getPasswordBlockMessage:getPasswordBlockMessage, isPasswordSubmitReady:isPasswordSubmitReady};
    })(window);

    // Live strength meter
    (function(){
        const pwdEl=document.getElementById('createPassword');
        const confirmEl=document.getElementById('confirmPassword');
        const liveWrap=document.getElementById('signUpPwLive');
        const commonErr=document.getElementById('signUpPassword-common-error');
        if(!pwdEl||!confirmEl||!liveWrap) return;
        if(!liveWrap.querySelector('.pwd-strength')){
            liveWrap.innerHTML='<div class="pwd-strength"><div class="pwd-strength__track" role="progressbar" aria-valuemin="0" aria-valuemax="7" aria-valuenow="0"><div class="pwd-strength__fill"></div></div><span class="pwd-strength__label">Weak</span></div>';
        }
        const fillEl=liveWrap.querySelector('.pwd-strength__fill');
        const labelEl=liveWrap.querySelector('.pwd-strength__label');
        const trackEl=liveWrap.querySelector('.pwd-strength__track');
        function getPersonal(){ return { nickname: document.getElementById('username')?.value||'', email: document.getElementById('signupEmail')?.value||'', firstName: document.getElementById('firstName')?.value||'', lastName: document.getElementById('lastName')?.value||'' }; }
        function refresh(){
            const p=pwdEl.value; const c=confirmEl.value;
            if(p.length===0){ liveWrap.hidden=true; if(commonErr) commonErr.classList.remove('show'); return; }
            liveWrap.hidden=false;
            const score=window.DiariPasswordPolicy.getStrengthScoreMeterOnly(p,c);
            const band=window.DiariPasswordPolicy.getStrengthBandMeter(score);
            if(fillEl){ fillEl.style.width=Math.min(100,(score/7)*100)+'%'; fillEl.style.backgroundColor=band.color; }
            if(labelEl){ labelEl.textContent=band.label; labelEl.style.color=band.color; }
            if(trackEl){ trackEl.setAttribute('aria-valuenow',String(score)); trackEl.setAttribute('aria-valuetext',band.label); }
            const personal=getPersonal();
            const ready=window.DiariPasswordPolicy.isPasswordSubmitReady(p,c,personal);
            if(commonErr){
                if(!ready && p.length>0){
                    const msg=window.DiariPasswordPolicy.getPasswordBlockMessage(p,c,personal);
                    if(msg){ commonErr.textContent=msg; commonErr.classList.add('show'); } else commonErr.classList.remove('show');
                } else commonErr.classList.remove('show');
            }
            // Deduplicate: the shared policy message above already explains password/
            // confirm issues, so hide the per-field boxes while it is visible. This
            // stops two stacked pink boxes from pushing the meter down with a big gap.
            if(commonErr && commonErr.classList.contains('show')){
                ['createPassword-error','confirmPassword-error'].forEach(function(id){
                    const perField=document.getElementById(id);
                    if(perField) perField.classList.remove('show');
                });
            }
            if(typeof updateSignupButton==='function') updateSignupButton();
        }
        pwdEl.addEventListener('input', refresh);
        confirmEl.addEventListener('input', refresh);
        ['username','signupEmail','firstName','lastName'].forEach(id=>{ const el=document.getElementById(id); if(el) el.addEventListener('input', refresh); });
        refresh();
        window._bondRefreshPwd = refresh;
    })();

    // Inline validation
    const signupIds = ['username','signupEmail','firstName','lastName','gender','birthday','createPassword','confirmPassword'];
    let signupAvailability = { username: { val:'', ok:null, pending:null } };
    let availabilityTimers = {};

    function isValidEmailBond(email){ return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email); }

    function showErrorBond(inputEl, msg){
        inputEl.classList.add('error'); inputEl.classList.remove('success');
        const container = inputEl.closest('.input-container');
        if(container) container.classList.add('error');
        const err = document.getElementById(inputEl.id + '-error');
        if(err){ err.textContent = msg; err.classList.add('show'); }
    }
    function showSuccessBond(inputEl){
        inputEl.classList.remove('error'); inputEl.classList.add('success');
        const container = inputEl.closest('.input-container');
        if(container) container.classList.remove('error');
        const err = document.getElementById(inputEl.id + '-error');
        if(err) err.classList.remove('show');
    }
    function clearValidationBond(inputEl){
        inputEl.classList.remove('error','success');
        const container = inputEl.closest('.input-container');
        if(container) container.classList.remove('error');
        const err = document.getElementById(inputEl.id + '-error');
        if(err) err.classList.remove('show');
    }

    function checkAvailabilityBond(fieldId, value){
        const key = fieldId==='username' ? 'username' : 'signupEmail';
        const state = signupAvailability[key];
        if(!state) return Promise.resolve(true);
        if(state.val===value && state.ok!==null){
            const el=document.getElementById(fieldId);
            if(el){ if(state.ok) showSuccessBond(el); else showErrorBond(el, key==='username'?'Username already exists.':'Email already exists.'); }
            return Promise.resolve(state.ok);
        }
        if(state.val===value && state.pending) return state.pending;
        state.val=value; state.ok=null;
        const body = `check_username=1&username=${encodeURIComponent(value)}`;
        state.pending = fetch('signup.php',{method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body})
            .then(r=>r.json().then(d=>({ok:r.ok,d})))
            .then(({ok,d})=>{
                if(!ok) return true;
                if(key==='username' && d.exists){
                    state.ok=false;
                    const el=document.getElementById(fieldId);
                    if(el) showErrorBond(el,'Username already exists.');
                    return false;
                }
                state.ok=true;
                const el=document.getElementById(fieldId);
                if(el && key==='username') showSuccessBond(el);
                return true;
            }).catch(()=>true)
            .finally(()=>{ if(state.val===value) state.pending=null; });
        return state.pending;
    }

    function validateSignupField(fieldId){
        const el=document.getElementById(fieldId);
        if(!el) return true;
        const v=el.value.trim();
        if(fieldId==='username'){
            if(!v){ showErrorBond(el,'Username is required.'); return false; }
            if(v.length<4 || v.length>64){ showErrorBond(el,'Username must be 4-64 characters.'); return false; }
            if(/[<>\/]/.test(v)){ showErrorBond(el,'Invalid characters.'); return false; }
            const st=signupAvailability.username;
            if(st.val===v && st.ok===false){ showErrorBond(el,'Username already exists.'); return false; }
            if(st.val===v && st.ok===true){ showSuccessBond(el); return true; }
            if(availabilityTimers.username) clearTimeout(availabilityTimers.username);
            availabilityTimers.username=setTimeout(()=>checkAvailabilityBond('username',v),300);
            if(v.length>=4) showSuccessBond(el);
            return true;
        }
        if(fieldId==='signupEmail'){
            if(!v){ showErrorBond(el,'Email is required.'); return false; }
            if(!isValidEmailBond(v)){ showErrorBond(el,'Please enter a valid email.'); return false; }
            showSuccessBond(el); return true;
        }
        if(fieldId==='firstName'){ if(!v){ showErrorBond(el,'First name is required.'); return false; } if(/[<>\/]/.test(v)){ showErrorBond(el,'Invalid characters.'); return false; } showSuccessBond(el); return true; }
        if(fieldId==='lastName'){ if(!v){ showErrorBond(el,'Last name is required.'); return false; } if(/[<>\/]/.test(v)){ showErrorBond(el,'Invalid characters.'); return false; } showSuccessBond(el); return true; }
        if(fieldId==='gender'){ if(!v){ showErrorBond(el,'Gender is required.'); return false; } showSuccessBond(el); return true; }
        if(fieldId==='birthday'){ if(!v){ showErrorBond(el,'Date of birth is required.'); return false; } showSuccessBond(el); return true; }
        if(fieldId==='createPassword'){
            if(!v){ showErrorBond(el,'Password is required.'); if(window._bondRefreshPwd) window._bondRefreshPwd(); return false; }
            showSuccessBond(el);
            if(window._bondRefreshPwd) window._bondRefreshPwd();
            const c=document.getElementById('confirmPassword');
            if(c && c.value.trim()) validateSignupField('confirmPassword');
            return true;
        }
        if(fieldId==='confirmPassword'){
            const p=document.getElementById('createPassword')?.value||'';
            if(!v){ showErrorBond(el,'Password confirmation is required.'); return false; }
            if(window._bondRefreshPwd) window._bondRefreshPwd();
            if(v!==p){
                // If the shared policy message already covers the mismatch, skip the
                // per-field box to avoid a duplicate message + extra vertical space.
                const common=document.getElementById('signUpPassword-common-error');
                if(common && common.classList.contains('show')){ clearValidationBond(el); el.classList.add('error'); const cont=el.closest('.input-container'); if(cont) cont.classList.add('error'); return false; }
                showErrorBond(el,'Passwords do not match.'); return false;
            }
            showSuccessBond(el); return true;
        }
        return true;
    }

    const signupSubmitBtn = document.getElementById('signUpSubmitBtn');
    function updateSignupButton(){
        const otherIds = ['username','signupEmail','firstName','lastName','gender','birthday'];
        const otherValid = otherIds.every(id=>{
            const el=document.getElementById(id);
            return el && el.value.trim()!=='' && !el.classList.contains('error');
        });
        const pw=document.getElementById('createPassword')?.value||'';
        const cpw=document.getElementById('confirmPassword')?.value||'';
        const personal={ nickname: document.getElementById('username')?.value||'', email: document.getElementById('signupEmail')?.value||'', firstName: document.getElementById('firstName')?.value||'', lastName: document.getElementById('lastName')?.value||'' };
        const pwReady = window.DiariPasswordPolicy ? window.DiariPasswordPolicy.isPasswordSubmitReady(pw, cpw, personal) : (pw.length>=12 && pw===cpw);
        const noInlineErrors = !document.querySelector('#createAccountForm .custom-error.show');
        if(signupSubmitBtn) signupSubmitBtn.disabled = !(otherValid && pwReady && noInlineErrors);
    }

    // Full-form fallback used by the Terms modal's "I Agree & Continue" button:
    // agreeing with an empty/partial form must surface every inline error
    // instead of silently closing the modal back to a blank form.
    function validateAllSignupFields(){
        let ok = true;
        signupIds.forEach(id=>{ if(!validateSignupField(id)) ok=false; });
        if(window._bondRefreshPwd) window._bondRefreshPwd();
        updateSignupButton();
        if(!ok){
            const firstErr = document.querySelector('#createAccountForm .custom-error.show');
            if(firstErr) firstErr.scrollIntoView({behavior:'smooth', block:'nearest'});
        }
        return ok;
    }
    window.BondValidateSignupForm = validateAllSignupFields;

    signupIds.forEach(fid=>{
        const el=document.getElementById(fid);
        if(!el) return;
        el.addEventListener('blur', ()=>{ validateSignupField(fid); updateSignupButton(); });
        el.addEventListener('input', ()=>{ validateSignupField(fid); updateSignupButton(); });
        el.addEventListener('change', ()=>{ validateSignupField(fid); updateSignupButton(); });
    });

    // Birthday calendar icon click
    const birthdayInput = document.getElementById('birthday');
    const birthdayIcon = document.getElementById('birthdayIcon');
    if(birthdayIcon && birthdayInput){
        const openPicker = (e)=>{
            e.preventDefault();
            try{ if(typeof birthdayInput.showPicker==='function') birthdayInput.showPicker(); else birthdayInput.focus(); birthdayInput.click(); }catch(_){ birthdayInput.focus(); }
        };
        birthdayIcon.addEventListener('click', openPicker);
    }

    // Signup form submission
    if (signupForm) {
        if (neggyContainer) neggyContainer.style.display = 'none';
        signupForm.addEventListener('submit', async function(e) {
            e.preventDefault();

            if(!window.BondTermsModal || !window.BondTermsModal.hasAgreed()){
                e.stopImmediatePropagation();
                if(window.BondTermsModal) window.BondTermsModal.open();
                return;
            }

            let isValid = true;
            signupIds.forEach(id=>{ if(!validateSignupField(id)) isValid=false; });
            updateSignupButton();
            if(!isValid){
                const firstErr = document.querySelector('#createAccountForm .custom-error.show');
                if(firstErr) firstErr.scrollIntoView({behavior:'smooth', block:'nearest'});
                return;
            }
            const uname = document.getElementById('username').value.trim();
            const unameOk = await checkAvailabilityBond('username', uname);
            if(!unameOk) return;

            const formData = new FormData(this);
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalBtnText = submitBtn.textContent;
            submitBtn.disabled = true;
            submitBtn.textContent = 'Creating...';

            fetch('signup.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) throw new Error('Network response was not ok');
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    sessionStorage.setItem('pendingRegistrationEmail', data.email);
                    window.location.href = 'verify-registration.php?email=' + encodeURIComponent(data.email);
                } else {
                    if (data.errors && data.errors.length > 0) {
                        let mapped=false;
                        data.errors.forEach(msg=>{
                            const low=msg.toLowerCase();
                            if(low.includes('username')) showErrorBond(document.getElementById('username'), msg);
                            else if(low.includes('email')) showErrorBond(document.getElementById('signupEmail'), msg);
                            else if(low.includes('first name')) showErrorBond(document.getElementById('firstName'), msg);
                            else if(low.includes('last name')) showErrorBond(document.getElementById('lastName'), msg);
                            else if(low.includes('birthday')) showErrorBond(document.getElementById('birthday'), msg);
                            else if(low.includes('gender')) showErrorBond(document.getElementById('gender'), msg);
                            else if(low.includes('password') && low.includes('match')) showErrorBond(document.getElementById('confirmPassword'), msg);
                            else if(low.includes('password')) showErrorBond(document.getElementById('createPassword'), msg);
                            else {
                                const fallback = document.getElementById('confirmPassword');
                                if(fallback) showErrorBond(fallback, msg);
                            }
                            mapped=true;
                        });
                        if(!mapped){
                            showErrorBond(document.getElementById('confirmPassword'), data.errors[0]);
                        }
                        const firstErr = document.querySelector('#createAccountForm .custom-error.show');
                        if(firstErr) firstErr.scrollIntoView({behavior:'smooth', block:'nearest'});
                    } else {
                        showErrorBond(document.getElementById('confirmPassword'), 'Registration failed. Please try again.');
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showErrorBond(document.getElementById('confirmPassword'), 'An error occurred. Please try again.');
            })
            .finally(() => {
                submitBtn.textContent = originalBtnText;
                submitBtn.disabled = false;
            });
        });
    }
</script>
</body>
</html>
