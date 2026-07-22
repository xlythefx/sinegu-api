<?php

// Start output buffering
ob_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_clean();
    http_response_code(204);
    ob_end_flush();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_clean();
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed',
    ]);
    ob_end_flush();
    exit;
}

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed',
    ]);
    ob_end_flush();
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['uni_id'])) {
    ob_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'uni_id is required',
    ]);
    ob_end_flush();
    exit;
}

$uni_id = trim($data['uni_id']);

// Verify user exists
$check_user = mysqli_prepare($conn, "SELECT uni_id FROM user_credentials WHERE uni_id = ?");
if (!$check_user) {
    mysqli_close($conn);
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to prepare statement',
    ]);
    ob_end_flush();
    exit;
}

mysqli_stmt_bind_param($check_user, "s", $uni_id);
mysqli_stmt_execute($check_user);
$result = mysqli_stmt_get_result($check_user);
$user = mysqli_fetch_assoc($result);
mysqli_stmt_close($check_user);

if (!$user) {
    mysqli_close($conn);
    ob_clean();
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => 'User not found',
    ]);
    ob_end_flush();
    exit;
}

// Soft reject: mark as suspended (reversible, keeps referral/audit data)
$stmt = mysqli_prepare($conn, "UPDATE user_credentials SET status = 'suspended', updated_at = NOW() WHERE uni_id = ?");
if (!$stmt) {
    mysqli_close($conn);
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to prepare update statement',
    ]);
    ob_end_flush();
    exit;
}

mysqli_stmt_bind_param($stmt, "s", $uni_id);

if (!mysqli_stmt_execute($stmt)) {
    mysqli_stmt_close($stmt);
    mysqli_close($conn);
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to reject user',
    ]);
    ob_end_flush();
    exit;
}

mysqli_stmt_close($stmt);
mysqli_close($conn);

ob_clean();
echo json_encode([
    'success' => true,
    'message' => 'User rejected (suspended) successfully',
]);

ob_end_flush();
?>

