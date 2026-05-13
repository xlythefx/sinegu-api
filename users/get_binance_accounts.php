<?php
/**
 * Fetch Binance accounts for a user by uni_id.
 * GET: ?uni_id=xxx
 * Returns: {"success": true, "accounts": [...]} or {"success": false, "message": "..."}
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

$uni_id = mysqli_real_escape_string($conn, $uni_id);

$sql = "SELECT api_key, secret_key, name, balance, initial_deposit, currency_type, created_at, deleted_at, demo, enabled FROM binance_accounts WHERE uni_id = '{$uni_id}' AND (deleted_at IS NULL) ORDER BY created_at DESC";

$result = mysqli_query($conn, $sql);

if (!$result) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to fetch: ' . mysqli_error($conn)]);
    exit;
}

$accounts = [];
while ($row = mysqli_fetch_assoc($result)) {
    $row['balance'] = isset($row['balance']) ? (float) $row['balance'] : 0;
    $row['initial_deposit'] = isset($row['initial_deposit']) ? (float) $row['initial_deposit'] : 0;
    $row['demo'] = isset($row['demo']) ? (int) $row['demo'] : 0;
    $row['enabled'] = isset($row['enabled']) ? (int) $row['enabled'] : 1;
    // Do not return secret_key to frontend
    unset($row['secret_key']);
    $accounts[] = $row;
}

mysqli_free_result($result);
mysqli_close($conn);

echo json_encode(['success' => true, 'accounts' => $accounts]);
