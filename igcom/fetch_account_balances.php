<?php
/**
 * Fetch balances for all IG accounts (ig_accounts).
 * Returns JSON: {"success": true, "balances": [{"id": 1, "balance": 10000.50, "email": "..."}, ...]}
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$sql = "SELECT id, balance, unrealized_pnl, email FROM ig_accounts WHERE deleted_at IS NULL";
$result = mysqli_query($conn, $sql);

if (!$result) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    exit;
}

$balances = [];
while ($row = mysqli_fetch_assoc($result)) {
    $balances[] = [
        'id' => (int) $row['id'],
        'balance' => $row['balance'] !== null ? (float) $row['balance'] : 0.0,
        'unrealized_pnl' => $row['unrealized_pnl'] !== null ? (float) $row['unrealized_pnl'] : 0.0,
        'email' => $row['email'] ?? null,
    ];
}

echo json_encode([
    'success' => true,
    'balances' => $balances,
    'count' => count($balances),
]);

mysqli_close($conn);
