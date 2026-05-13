<?php
/**
 * Admin: Mark a capital_api_logs row as read/unread (hide from default list).
 * POST Body: { "id": 123, "is_read": true }
 */

ob_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$input = is_string($raw) ? json_decode($raw, true) : null;

if (!is_array($input)) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON body']);
    exit;
}

$id = isset($input['id']) ? (int) $input['id'] : 0;
$isRead = isset($input['is_read']) ? (bool) $input['is_read'] : true;

if ($id <= 0) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'id is required']);
    exit;
}

require_once __DIR__ . '/../../conn.php';

if (!$conn) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$st = mysqli_prepare($conn, "UPDATE capital_api_logs SET is_read = ? WHERE id = ? LIMIT 1");
if (!$st) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

$flag = $isRead ? 1 : 0;
mysqli_stmt_bind_param($st, 'ii', $flag, $id);
$ok = mysqli_stmt_execute($st);
$affected = mysqli_stmt_affected_rows($st);
mysqli_stmt_close($st);

if (!$ok) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Update failed']);
    mysqli_close($conn);
    exit;
}

ob_end_clean();
echo json_encode(['success' => true, 'updated' => $affected > 0, 'is_read' => $isRead]);
mysqli_close($conn);
