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

if (!isset($data['id']) || !isset($data['uni_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Account id and uni_id are required']);
    exit;
}

$id = (int)$data['id'];
$uni_id = trim($data['uni_id']);

// Verify that the account belongs to this user
$check_account = mysqli_prepare($conn, "SELECT id FROM accounts WHERE id = ? AND uni_id = ?");
mysqli_stmt_bind_param($check_account, "is", $id, $uni_id);
mysqli_stmt_execute($check_account);
$result = mysqli_stmt_get_result($check_account);

if (mysqli_num_rows($result) === 0) {
    mysqli_stmt_close($check_account);
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Account not found or access denied']);
    exit;
}
mysqli_stmt_close($check_account);

// Soft delete (disconnect): set deleted_at and enabled=0 so account is hidden and inactive; trades stay linked
$stmt = mysqli_prepare($conn, 'UPDATE accounts SET deleted_at = NOW(), enabled = 0 WHERE id = ? AND uni_id = ?');
mysqli_stmt_bind_param($stmt, 'is', $id, $uni_id);

if (!mysqli_stmt_execute($stmt)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to disconnect broker']);
    mysqli_stmt_close($stmt);
    mysqli_close($conn);
    exit;
}

mysqli_stmt_close($stmt);
mysqli_close($conn);

echo json_encode([
    'success' => true,
    'message' => 'Broker disconnected successfully',
]);

?>

