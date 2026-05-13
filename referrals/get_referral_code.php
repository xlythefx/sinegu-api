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

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

if (empty($_GET['user_uni_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'User uni_id is required']);
    exit;
}

$userUniId = trim($_GET['user_uni_id']);

// Get referral code for user
$sql = 'SELECT code, created_at FROM referral_codes WHERE user_uni_id = ? LIMIT 1';
$code = null;
$createdAt = null;

if ($stmt = mysqli_prepare($conn, $sql)) {
    mysqli_stmt_bind_param($stmt, 's', $userUniId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    if ($row) {
        $code = $row['code'];
        $createdAt = $row['created_at'];
    }
    mysqli_stmt_close($stmt);
}

if ($code) {
    echo json_encode([
        'success' => true,
        'code' => $code,
        'created_at' => $createdAt
    ]);
} else {
    echo json_encode([
        'success' => true,
        'code' => null,
        'message' => 'No referral code found for this user'
    ]);
}

mysqli_close($conn);

?>

