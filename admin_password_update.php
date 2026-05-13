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
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed',
    ]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (
    !is_array($input) ||
    empty($input['email']) ||
    !isset($input['current_password']) ||
    !isset($input['new_password'])
) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Email, current_password, and new_password are required',
    ]);
    exit;
}

$email = trim($input['email']);
$currentPassword = $input['current_password'];
$newPassword = $input['new_password'];

require_once __DIR__ . '/conn.php';

$stmt = mysqli_prepare(
    $conn,
    'SELECT admin_id, password FROM admin_credentials WHERE email = ? LIMIT 1'
);

if (!$stmt) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to prepare statement',
    ]);
    exit;
}

mysqli_stmt_bind_param($stmt, 's', $email);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$admin = $result ? mysqli_fetch_assoc($result) : null;
mysqli_stmt_close($stmt);

if (!$admin || !password_verify($currentPassword, $admin['password'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Current password is incorrect',
    ]);
    mysqli_close($conn);
    exit;
}

$newHash = password_hash($newPassword, PASSWORD_BCRYPT);

$updateStmt = mysqli_prepare(
    $conn,
    'UPDATE admin_credentials SET password = ? WHERE admin_id = ?'
);

if (!$updateStmt) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to prepare update statement',
    ]);
    mysqli_close($conn);
    exit;
}

mysqli_stmt_bind_param($updateStmt, 'si', $newHash, $admin['admin_id']);
$success = mysqli_stmt_execute($updateStmt);
mysqli_stmt_close($updateStmt);

if (!$success) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to update password',
    ]);
    mysqli_close($conn);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'Password updated successfully',
]);

mysqli_close($conn);

