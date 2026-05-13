<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

require_once '../conn.php';

$uni_id = isset($_GET['uni_id']) ? $_GET['uni_id'] : '';

if (empty($uni_id)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing uni_id parameter']);
    exit;
}

// Get user profile information
$stmt = mysqli_prepare($conn, "SELECT uni_id, name, email, status, created_at, user_profile, user_banner FROM user_credentials WHERE uni_id = ?");
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

echo json_encode([
    'success' => true,
    'user' => [
        'uni_id' => $user['uni_id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'status' => $user['status'],
        'created_at' => $user['created_at'],
        'user_profile' => $user['user_profile'],
        'user_banner' => $user['user_banner'],
    ]
]);

mysqli_close($conn);
?>

