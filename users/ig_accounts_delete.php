<?php
/**
 * POST /users/ig_accounts_delete.php
 * Soft-delete an ig.com account.
 * Required: id, uni_id
 */

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
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    ob_end_flush();
    exit;
}

require_once '../conn.php';

if (!$conn) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    ob_end_flush();
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    ob_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
    ob_end_flush();
    exit;
}

$id     = isset($data['id'])     ? (int) $data['id']               : 0;
$uni_id = isset($data['uni_id']) ? trim((string) $data['uni_id'])  : '';

if (!$id || empty($uni_id)) {
    ob_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'id and uni_id are required']);
    ob_end_flush();
    exit;
}

$stmt = mysqli_prepare($conn,
    "UPDATE ig_accounts SET deleted_at = NOW() WHERE id = ? AND uni_id = ? AND (deleted_at IS NULL) LIMIT 1"
);

if (!$stmt) {
    $err = mysqli_error($conn);
    mysqli_close($conn);
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $err]);
    ob_end_flush();
    exit;
}

mysqli_stmt_bind_param($stmt, 'is', $id, $uni_id);

if (mysqli_stmt_execute($stmt)) {
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    mysqli_close($conn);
    ob_clean();
    if ($affected === 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Account not found or already disconnected']);
    } else {
        echo json_encode(['success' => true, 'message' => 'ig.com account disconnected']);
    }
    ob_end_flush();
} else {
    $err = mysqli_error($conn);
    mysqli_stmt_close($stmt);
    mysqli_close($conn);
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to disconnect: ' . $err]);
    ob_end_flush();
}
