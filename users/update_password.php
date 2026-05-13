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

require_once '../conn.php';

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['uni_id']) || !isset($data['current_password']) || !isset($data['new_password'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

$uni_id = trim($data['uni_id']);
$currentPassword = $data['current_password'];
$newPassword = $data['new_password'];

// Validation
if (empty($uni_id) || empty($currentPassword) || empty($newPassword)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'All fields are required']);
    exit;
}

if (strlen($newPassword) < 6) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'New password must be at least 6 characters']);
    exit;
}

// Get user and verify current password
$stmt = mysqli_prepare($conn, "SELECT uni_id, password FROM user_credentials WHERE uni_id = ?");
mysqli_stmt_bind_param($stmt, "s", $uni_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$user) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'User not found']);
    exit;
}

// Verify current password
if (!password_verify($currentPassword, $user['password'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Current password is incorrect']);
    exit;
}

// Hash new password
$hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

// Update password
$stmt = mysqli_prepare($conn, "UPDATE user_credentials SET password = ? WHERE uni_id = ?");
mysqli_stmt_bind_param($stmt, "ss", $hashedPassword, $uni_id);

if (mysqli_stmt_execute($stmt)) {
    mysqli_stmt_close($stmt);
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Password updated successfully'
    ]);
} else {
    mysqli_stmt_close($stmt);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to update password']);
}

mysqli_close($conn);
?>

