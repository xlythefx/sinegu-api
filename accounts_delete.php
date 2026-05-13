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

if (!is_array($input) || empty($input['id'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Account id is required',
    ]);
    exit;
}

$id = (int) $input['id'];

require_once __DIR__ . '/conn.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

if (file_exists(__DIR__ . '/includes/audit_log.php')) {
    require_once __DIR__ . '/includes/audit_log.php';
    audit_log('account_disconnected', ['account_id' => $id]);
}

// Soft delete (disconnect): set deleted_at and enabled=0 so account is hidden and inactive; trades stay linked
$stmt = mysqli_prepare($conn, 'UPDATE accounts SET deleted_at = NOW(), enabled = 0 WHERE id = ?');

if (!$stmt) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to prepare statement',
    ]);
    exit;
}

mysqli_stmt_bind_param($stmt, 'i', $id);

if (!mysqli_stmt_execute($stmt)) {
    if (file_exists(__DIR__ . '/includes/api_log.php')) {
        require_once __DIR__ . '/includes/api_log.php';
        api_log($conn, 'accounts_delete', 'Disconnect account failed', 'delete', false);
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to disconnect account',
    ]);
    mysqli_stmt_close($stmt);
    mysqli_close($conn);
    exit;
}

mysqli_stmt_close($stmt);
if (file_exists(__DIR__ . '/includes/api_log.php')) {
    require_once __DIR__ . '/includes/api_log.php';
    api_log($conn, 'accounts_delete', 'Account disconnected successfully', 'delete', true);
}
mysqli_close($conn);

echo json_encode([
    'success' => true,
    'message' => 'Account disconnected successfully',
]);

