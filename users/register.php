<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../conn.php';

if (file_exists(__DIR__ . '/../smtp.php')) {
    require_once __DIR__ . '/../smtp.php';
}

// Check connection before any mysqli_* calls
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['name']) || !isset($data['email']) || !isset($data['password'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

$name = trim($data['name']);
$email = trim($data['email']);
$password = $data['password'];
$referralCode = isset($data['referral_code']) ? trim($data['referral_code']) : null;
// referral_source is collected for UX purposes but not stored in database

// Validation
if (empty($name) || empty($email) || empty($password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'All required fields must be filled']);
    exit;
}

if (strlen($password) < 6) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid email format']);
    exit;
}

if (strlen($email) > 255 || strlen($name) > 255) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Name or email too long']);
    exit;
}

// Check if email already exists
$check_email = mysqli_prepare($conn, "SELECT uni_id FROM user_credentials WHERE email = ?");
mysqli_stmt_bind_param($check_email, "s", $email);
mysqli_stmt_execute($check_email);
$result = mysqli_stmt_get_result($check_email);

if (mysqli_num_rows($result) > 0) {
    mysqli_stmt_close($check_email);
    if (file_exists(__DIR__ . '/../includes/api_log.php')) {
        require_once __DIR__ . '/../includes/api_log.php';
        api_log($conn, 'users/register', 'Registration failed - email already registered', 'create', false);
    }
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'Email already registered']);
    exit;
}
mysqli_stmt_close($check_email);

// Generate unique ID (UUID v4)
function generateUUID() {
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

$uni_id = generateUUID();

// Hash password
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

// Email verification: 6-digit code, expire in 15 minutes
$verification_code = str_pad((string) mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
$verification_code_expires_at = date('Y-m-d H:i:s', strtotime('+15 minutes'));

$status = 'active';

// Insert user (email_verified = 0 until they verify). Bind types match user_credentials: uni_id varchar(36), name/email/password varchar(255), status enum, verification_code varchar(6), verification_code_expires_at datetime -> all "s"
$stmt = mysqli_prepare($conn, "INSERT INTO user_credentials (uni_id, name, email, password, status, email_verified, verification_code, verification_code_expires_at, verification_code_attempts) VALUES (?, ?, ?, ?, ?, 0, ?, ?, 0)");
mysqli_stmt_bind_param($stmt, "sssssss", $uni_id, $name, $email, $hashed_password, $status, $verification_code, $verification_code_expires_at);

if (mysqli_stmt_execute($stmt)) {
    mysqli_stmt_close($stmt);
    if (file_exists(__DIR__ . '/../includes/api_log.php')) {
        require_once __DIR__ . '/../includes/api_log.php';
        api_log($conn, 'users/register', 'User registration successful', 'create', true);
    }
    if (file_exists(__DIR__ . '/../includes/audit_log.php')) {
        require_once __DIR__ . '/../includes/audit_log.php';
        audit_log('account_created', ['uni_id' => $uni_id, 'email_length' => strlen($email)]);
    }

    $registeredAt = date('c');
    $referrerNotifyName = null;
    $referrerNotifyEmail = null;

    // Handle referral code tracking if provided
    if ($referralCode) {
        // Get referrer's uni_id from referral code
        $refSql = "SELECT user_uni_id FROM referral_codes WHERE code = ? LIMIT 1";
        if ($refStmt = mysqli_prepare($conn, $refSql)) {
            mysqli_stmt_bind_param($refStmt, "s", $referralCode);
            mysqli_stmt_execute($refStmt);
            $refResult = mysqli_stmt_get_result($refStmt);
            $refRow = mysqli_fetch_assoc($refResult);
            
            if ($refRow) {
                $referrerUniId = $refRow['user_uni_id'];
                
                // Prevent self-referral
                if ($referrerUniId !== $uni_id) {
                    // Track the referral
                    $trackSql = "INSERT INTO referral_tracking (referral_code, referrer_uni_id, referred_user_uni_id) VALUES (?, ?, ?)";
                    if ($trackStmt = mysqli_prepare($conn, $trackSql)) {
                        mysqli_stmt_bind_param($trackStmt, "sss", $referralCode, $referrerUniId, $uni_id);
                        if (mysqli_stmt_execute($trackStmt)) {
                            mysqli_stmt_close($trackStmt);
                            $credSql = "SELECT name, email FROM user_credentials WHERE uni_id = ? LIMIT 1";
                            if ($credStmt = mysqli_prepare($conn, $credSql)) {
                                mysqli_stmt_bind_param($credStmt, "s", $referrerUniId);
                                mysqli_stmt_execute($credStmt);
                                $credRes = mysqli_stmt_get_result($credStmt);
                                if ($credRow = mysqli_fetch_assoc($credRes)) {
                                    $referrerNotifyName = $credRow['name'];
                                    $referrerNotifyEmail = $credRow['email'];
                                }
                                mysqli_stmt_close($credStmt);
                            }
                        } else {
                            mysqli_stmt_close($trackStmt);
                        }
                    }
                }
            }
            mysqli_stmt_close($refStmt);
        }
    }

    // Send verification email
    if (function_exists('sendEmail')) {
        $subject = "Verify your email - SineguAlerts";
        $message = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
                .content { background: #f9fafb; padding: 30px; border-radius: 0 0 8px 8px; }
                .code-box { background: white; border: 2px dashed #f59e0b; padding: 20px; text-align: center; margin: 20px 0; border-radius: 8px; }
                .code { font-size: 32px; font-weight: bold; letter-spacing: 8px; color: #f59e0b; }
                .footer { text-align: center; margin-top: 20px; color: #6b7280; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>SineguAlerts</h1>
                </div>
                <div class='content'>
                    <h2>Verify your email</h2>
                    <p>Hello " . htmlspecialchars($name) . ",</p>
                    <p>Please enter the code below to verify your email address:</p>
                    <div class='code-box'>
                        <div class='code'>" . htmlspecialchars($verification_code) . "</div>
                    </div>
                    <p><strong>This code will expire in 15 minutes.</strong></p>
                    <p>If you didn't create an account, please ignore this email.</p>
                    <div class='footer'>
                        <p>© " . date('Y') . " SineguAlerts. All rights reserved.</p>
                    </div>
                </div>
            </div>
        </body>
        </html>
        ";
        sendEmail($email, $name, $subject, $message);

        $adminTo = 'info@sinegualfamily.com';
        $adminSubject = 'New registration — ' . $name;
        $codeUsedLine = ($referralCode !== null && $referralCode !== '')
            ? htmlspecialchars($referralCode, ENT_QUOTES, 'UTF-8')
            : '—';
        if ($referrerNotifyName !== null || $referrerNotifyEmail !== null) {
            $referrerBlock = '<p><strong>Referrer name:</strong> ' . htmlspecialchars((string) $referrerNotifyName, ENT_QUOTES, 'UTF-8') . '</p>'
                . '<p><strong>Referrer email:</strong> ' . htmlspecialchars((string) $referrerNotifyEmail, ENT_QUOTES, 'UTF-8') . '</p>';
        } else {
            $referrerBlock = '<p><strong>Referrer:</strong> —</p>';
        }
        $adminBody = '
        <html><head><meta charset="UTF-8"></head><body style="font-family: Arial, sans-serif; line-height: 1.6; color: #111;">
        <p>A user just registered.</p>
        <p><strong>Time (server):</strong> ' . htmlspecialchars($registeredAt, ENT_QUOTES, 'UTF-8') . '</p>
        <p><strong>New user name:</strong> ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</p>
        <p><strong>New user email:</strong> ' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '</p>
        <p><strong>Referral code used:</strong> ' . $codeUsedLine . '</p>
        ' . $referrerBlock . '
        </body></html>';
        sendEmail($adminTo, 'Admin', $adminSubject, $adminBody);
    }
    
    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Account created. Please check your email for the verification code.',
        'requires_verification' => true,
        'user' => [
            'uni_id' => $uni_id,
            'name' => $name,
            'email' => $email,
            'status' => $status
        ]
    ]);
} else {
    mysqli_stmt_close($stmt);
    if (file_exists(__DIR__ . '/../includes/api_log.php')) {
        require_once __DIR__ . '/../includes/api_log.php';
        api_log($conn, 'users/register', 'Registration failed - database error', 'create', false);
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to create account. Please try again.']);
}

mysqli_close($conn);
?>

