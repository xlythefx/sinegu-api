<?php
/**
 * Update IG.com session tokens in ig_accounts.
 * Used by ig-flask accounts_listener to persist session for reuse (skip relogin within 6h).
 *
 * POST JSON: {"account_id": 1, "cst": "...", "x_security_token": "..."}
 * OR: {"email": "user@example.com", "cst": "...", "x_security_token": "..."}
 * Returns JSON: {"success": true, "message": "Session updated"}
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);
ob_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use POST.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON input']);
    exit;
}

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$account_id = isset($input['account_id']) ? (int) $input['account_id'] : null;
$email = isset($input['email']) ? trim((string) $input['email']) : null;
$cst = isset($input['cst']) ? (string) $input['cst'] : null;
$x_security_token = isset($input['x_security_token']) ? (string) $input['x_security_token'] : null;

if (!$account_id && !$email) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required field: account_id or email']);
    exit;
}

if ($cst === null && $x_security_token === null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'At least one of cst or x_security_token is required']);
    exit;
}

if ($account_id) {
    $stmt = mysqli_prepare($conn, "UPDATE ig_accounts SET ig_cst = ?, ig_x_security_token = ?, ig_session_last_used_at = NOW(), updated_at = NOW() WHERE id = ?");
    if (!$stmt) {
        ob_clean();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
        mysqli_close($conn);
        exit;
    }
    mysqli_stmt_bind_param($stmt, "ssi", $cst, $x_security_token, $account_id);
} else {
    $stmt = mysqli_prepare($conn, "UPDATE ig_accounts SET ig_cst = ?, ig_x_security_token = ?, ig_session_last_used_at = NOW(), updated_at = NOW() WHERE email = ?");
    if (!$stmt) {
        ob_clean();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
        mysqli_close($conn);
        exit;
    }
    mysqli_stmt_bind_param($stmt, "sss", $cst, $x_security_token, $email);
}

$result = mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

if (!$result) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    exit;
}

ob_clean();
echo json_encode(['success' => true, 'message' => 'IG session updated successfully']);

mysqli_close($conn);
exit;
