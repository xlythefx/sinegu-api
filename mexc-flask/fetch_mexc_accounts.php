<?php
/**
 * Fetch all MEXC accounts where enabled=1 for the bot.
 * Returns api_key, secret_key, name, demo, uni_id, … for each. Bot-only (internal use).
 * demo=1 → mexc-flask uses https://futures.testnet.mexc.com; demo=0 → https://api.mexc.com.
 * GET: no params
 * Returns: {"success": true, "accounts": [{api_key, secret_key, name, ...}]}
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

$sql = "SELECT api_key, secret_key, name, uni_id, balance, initial_deposit, currency_type, demo, enabled 
        FROM mexc_accounts 
        WHERE enabled = 1 AND (deleted_at IS NULL) 
        ORDER BY created_at ASC";

$result = mysqli_query($conn, $sql);

if (!$result) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to fetch: ' . mysqli_error($conn)]);
    exit;
}

$accounts = [];
while ($row = mysqli_fetch_assoc($result)) {
    $accounts[] = $row;
}

mysqli_free_result($result);
mysqli_close($conn);

echo json_encode(['success' => true, 'accounts' => $accounts]);
