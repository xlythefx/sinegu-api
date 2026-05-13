<?php
/**
 * GET /users/ig_accounts_list.php?uni_id=xxx
 * Returns all non-deleted ig.com accounts for a user.
 */

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

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$uni_id = isset($_GET['uni_id']) ? trim($_GET['uni_id']) : '';

if (empty($uni_id)) {
    mysqli_close($conn);
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'uni_id is required']);
    exit;
}

$stmt = mysqli_prepare($conn,
    "SELECT id, uni_id, name, api_key, email, password, balance, initial_deposit, currency_type, demo, enabled, created_at
     FROM ig_accounts
     WHERE uni_id = ? AND (deleted_at IS NULL)
     ORDER BY created_at DESC"
);

if (!$stmt) {
    $err = mysqli_error($conn);
    mysqli_close($conn);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to prepare: ' . $err]);
    exit;
}

mysqli_stmt_bind_param($stmt, 's', $uni_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$accounts = [];
while ($row = mysqli_fetch_assoc($result)) {
    $accounts[] = [
        'id'             => (int) $row['id'],
        'uni_id'         => $row['uni_id'],
        'name'           => $row['name'],
        'api_key'        => $row['api_key'],
        'email'          => $row['email'],
        'has_password'   => !empty($row['password']) ? 1 : 0,
        'balance'        => $row['balance'] !== null ? (float) $row['balance'] : null,
        'initial_deposit'=> $row['initial_deposit'] !== null ? (float) $row['initial_deposit'] : null,
        'currency_type'  => $row['currency_type'],
        'demo'           => (int) $row['demo'],
        'enabled'        => (int) $row['enabled'],
        'created_at'     => $row['created_at'],
    ];
}

mysqli_stmt_close($stmt);
mysqli_close($conn);

echo json_encode(['success' => true, 'accounts' => $accounts]);
