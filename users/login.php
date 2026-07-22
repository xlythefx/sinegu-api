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

if (!isset($data['email']) || !isset($data['password'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Email and password are required']);
    exit;
}

$email = trim($data['email']);
$password = $data['password'];

if (empty($email) || empty($password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Email and password cannot be empty']);
    exit;
}

if (strlen($email) > 255) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

// Check if user exists and get credentials (include email_verified for verification gate)
$stmt = mysqli_prepare($conn, "SELECT uni_id, name, email, password, status, email_verified FROM user_credentials WHERE email = ?");
mysqli_stmt_bind_param($stmt, "s", $email);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$user) {
    if (file_exists(__DIR__ . '/../includes/audit_log.php')) {
        require_once __DIR__ . '/../includes/audit_log.php';
        audit_log('login_failed', ['reason' => 'USER_NOT_FOUND', 'email_length' => strlen($email)]);
    }
    if (file_exists(__DIR__ . '/../includes/api_log.php')) {
        require_once __DIR__ . '/../includes/api_log.php';
        api_log($conn, 'users/login', 'User login attempt - user not found', 'login', false);
    }
    http_response_code(401);
    echo json_encode([
        'success' => false, 
        'error_code' => 'USER_NOT_FOUND',
        'message' => 'No account found with this email address'
    ]);
    exit;
}

// Verify password
if (!password_verify($password, $user['password'])) {
    if (file_exists(__DIR__ . '/../includes/audit_log.php')) {
        require_once __DIR__ . '/../includes/audit_log.php';
        audit_log('login_failed', ['reason' => 'INVALID_PASSWORD', 'email_length' => strlen($email)]);
    }
    if (file_exists(__DIR__ . '/../includes/api_log.php')) {
        require_once __DIR__ . '/../includes/api_log.php';
        api_log($conn, 'users/login', 'User login attempt - invalid password', 'login', false);
    }
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error_code' => 'INVALID_PASSWORD',
        'message' => 'Incorrect password. Please try again.'
    ]);
    exit;
}

// Require email verification before login (auto-send new code when they try)
$emailVerified = isset($user['email_verified']) ? (int) $user['email_verified'] : 1;
if ($emailVerified === 0) {
    if (function_exists('sendEmail')) {
    $verification_code = str_pad((string) mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
    $verification_code_expires_at = date('Y-m-d H:i:s', strtotime('+15 minutes'));
    $upd = mysqli_prepare($conn, "UPDATE user_credentials SET verification_code = ?, verification_code_expires_at = ?, verification_code_attempts = 0, updated_at = NOW() WHERE email = ?");
    if ($upd) {
        mysqli_stmt_bind_param($upd, "sss", $verification_code, $verification_code_expires_at, $email);
        mysqli_stmt_execute($upd);
        mysqli_stmt_close($upd);
    }
    $subject = "Verify your email - SineguAlerts";
    $name = $user['name'];
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
            <div class='header'><h1>SineguAlerts</h1></div>
            <div class='content'>
                <h2>Verify your email</h2>
                <p>Hello " . htmlspecialchars($name) . ",</p>
                <p>You tried to sign in. Please enter the code below to verify your email:</p>
                <div class='code-box'><div class='code'>" . htmlspecialchars($verification_code) . "</div></div>
                <p><strong>This code will expire in 15 minutes.</strong></p>
                <div class='footer'><p>© " . date('Y') . " SineguAlerts.</p></div>
            </div>
        </div>
    </body>
    </html>
    ";
    sendEmail($email, $name, $subject, $message);
    }
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error_code' => 'EMAIL_NOT_VERIFIED',
        'message' => function_exists('sendEmail') ? 'Please verify your email first. A new verification code has been sent to your inbox.' : 'Please verify your email first. Use the verification page to request a new code.'
    ]);
    mysqli_close($conn);
    exit;
}

// Suspended (rejected) accounts are blocked entirely.
// Pending accounts CAN log in — the frontend gates the brokers page until an
// admin approves them, so they can still access the rest of the dashboard.
if ($user['status'] === 'suspended') {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error_code' => 'ACCOUNT_SUSPENDED',
        'status' => $user['status'],
        'message' => 'Your account has been suspended. Please contact support.'
    ]);
    exit;
}

// Login successful
if (file_exists(__DIR__ . '/../includes/api_log.php')) {
    require_once __DIR__ . '/../includes/api_log.php';
    api_log($conn, 'users/login', 'User login successful', 'login', true);
}
http_response_code(200);
echo json_encode([
    'success' => true,
    'message' => 'Login successful',
    'user' => [
        'uni_id' => $user['uni_id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'status' => $user['status']
    ]
]);
mysqli_close($conn);
?>


