<?php
/**
 * Verify Email - Submit 6-digit code to mark email as verified.
 * POST: email, code
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

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['email']) || !isset($data['code'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Email and code are required']);
    exit;
}

$email = trim($data['email']);
$code = trim($data['code']);

if (empty($email) || empty($code)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Email and code cannot be empty']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid email format']);
    exit;
}

if (!preg_match('/^\d{6}$/', $code)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Code must be 6 digits']);
    exit;
}

$stmt = mysqli_prepare($conn, "SELECT uni_id, name, email_verified, verification_code, verification_code_expires_at, verification_code_attempts FROM user_credentials WHERE email = ?");
mysqli_stmt_bind_param($stmt, "s", $email);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$user) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'No account found with this email']);
    exit;
}

if ((int) $user['email_verified'] === 1) {
    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'Email is already verified. You can log in.']);
    mysqli_close($conn);
    exit;
}

$maxAttempts = 10;
$attempts = (int) ($user['verification_code_attempts'] ?? 0);
if ($attempts >= $maxAttempts) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Too many failed attempts. Please request a new code.']);
    mysqli_close($conn);
    exit;
}

$storedCode = $user['verification_code'] ?? '';
$expiresAt = $user['verification_code_expires_at'] ?? null;

if (empty($storedCode) || empty($expiresAt)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No valid verification code. Please request a new code.']);
    mysqli_close($conn);
    exit;
}

if (strtotime($expiresAt) < time()) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Verification code has expired. Please request a new code.']);
    mysqli_close($conn);
    exit;
}

if ($code !== $storedCode) {
    $upd = mysqli_prepare($conn, "UPDATE user_credentials SET verification_code_attempts = verification_code_attempts + 1, updated_at = NOW() WHERE email = ?");
    if ($upd) {
        mysqli_stmt_bind_param($upd, "s", $email);
        mysqli_stmt_execute($upd);
        mysqli_stmt_close($upd);
    }
    $remaining = $maxAttempts - $attempts - 1;
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid code.' . ($remaining > 0 ? " $remaining attempts remaining." : '')]);
    mysqli_close($conn);
    exit;
}

// Success: mark verified and clear code
$upd = mysqli_prepare($conn, "UPDATE user_credentials SET email_verified = 1, verification_code = NULL, verification_code_expires_at = NULL, verification_code_attempts = 0, updated_at = NOW() WHERE email = ?");
if (!$upd || !mysqli_stmt_bind_param($upd, "s", $email) || !mysqli_stmt_execute($upd)) {
    mysqli_stmt_close($upd ?? null);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to update account']);
    mysqli_close($conn);
    exit;
}
mysqli_stmt_close($upd);

http_response_code(200);
echo json_encode(['success' => true, 'message' => 'Email verified successfully. You can log in now.']);
mysqli_close($conn);
