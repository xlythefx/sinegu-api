<?php
/**
 * Fetch MEXC accounts for a user by uni_id.
 * GET: ?uni_id=xxx
 * Returns: {"success": true, "accounts": [...]} or {"success": false, "message": "..."}
 * secret_key is never returned to the frontend.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use GET.']);
    exit;
}

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$uni_id = isset($_GET['uni_id']) ? trim($_GET['uni_id']) : null;
if (!$uni_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required parameter: uni_id']);
    exit;
}

$stmt = mysqli_prepare(
    $conn,
    "SELECT api_key, name, balance, unrealized_pnl, initial_deposit, currency_type, created_at, deleted_at, demo, enabled
       FROM mexc_accounts
      WHERE uni_id = ? AND deleted_at IS NULL
      ORDER BY created_at DESC"
);

if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to prepare: ' . mysqli_error($conn)]);
    exit;
}

mysqli_stmt_bind_param($stmt, "s", $uni_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if (!$result) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to fetch: ' . mysqli_error($conn)]);
    exit;
}

$accounts = [];
while ($row = mysqli_fetch_assoc($result)) {
    $row['balance'] = isset($row['balance']) ? (float) $row['balance'] : 0;
    $row['unrealized_pnl'] = isset($row['unrealized_pnl']) ? (float) $row['unrealized_pnl'] : 0;
    $row['initial_deposit'] = isset($row['initial_deposit']) ? (float) $row['initial_deposit'] : 0;
    $row['demo'] = isset($row['demo']) ? (int) $row['demo'] : 0;
    $row['enabled'] = isset($row['enabled']) ? (int) $row['enabled'] : 1;
    $accounts[] = $row;
}

mysqli_free_result($result);
mysqli_stmt_close($stmt);
mysqli_close($conn);

echo json_encode(['success' => true, 'accounts' => $accounts]);
