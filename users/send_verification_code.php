<?php
/**
 * Send (resend) email verification code.
 * POST: email
 * Only sends if user exists and email_verified = 0. Generic response to avoid enumeration.
 */

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

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['email']) || empty(trim($data['email'] ?? ''))) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Email is required']);
    exit;
}

$email = trim($data['email']);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid email format']);
    exit;
}

$stmt = mysqli_prepare($conn, "SELECT uni_id, name, email_verified FROM user_credentials WHERE email = ?");
mysqli_stmt_bind_param($stmt, "s", $email);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

// Generic success to avoid revealing whether account exists
$genericSuccess = ['success' => true, 'message' => 'If an account exists with this email, a verification code has been sent.'];

if (!$user || (int) ($user['email_verified'] ?? 1) === 1) {
    http_response_code(200);
    echo json_encode($genericSuccess);
    mysqli_close($conn);
    exit;
}

$verification_code = str_pad((string) mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
$verification_code_expires_at = date('Y-m-d H:i:s', strtotime('+15 minutes'));

$upd = mysqli_prepare($conn, "UPDATE user_credentials SET verification_code = ?, verification_code_expires_at = ?, verification_code_attempts = 0, updated_at = NOW() WHERE email = ?");
if ($upd) {
    mysqli_stmt_bind_param($upd, "sss", $verification_code, $verification_code_expires_at, $email);
    mysqli_stmt_execute($upd);
    mysqli_stmt_close($upd);
}

if (function_exists('sendEmail')) {
    $name = $user['name'];
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
            <div class='header'><h1>SineguAlerts</h1></div>
            <div class='content'>
                <h2>Verify your email</h2>
                <p>Hello " . htmlspecialchars($name) . ",</p>
                <p>You requested a new verification code. Use the code below:</p>
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

http_response_code(200);
echo json_encode($genericSuccess);
mysqli_close($conn);
