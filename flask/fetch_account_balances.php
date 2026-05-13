<?php
/**
 * Fetch all account balances
 * Returns JSON: {"success": true, "balances": [{"id": 1, "balance": 10000.50}, ...]}
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../conn.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database connection failed'
    ]);
    exit;
}

$sql = "SELECT id, balance, deposit_amount, email FROM accounts";
$result = mysqli_query($conn, $sql);

if (!$result) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => mysqli_error($conn)
    ]);
    exit;
}

$balances = [];
while ($row = mysqli_fetch_assoc($result)) {
    $balances[] = [
        'id' => intval($row['id']),
        'balance' => floatval($row['balance'] ?? 0.0),
        'deposit_amount' => $row['deposit_amount'] !== null ? floatval($row['deposit_amount']) : null,
        'email' => $row['email'] ?? null
    ];
}

echo json_encode([
    'success' => true,
    'balances' => $balances,
    'count' => count($balances)
]);

mysqli_close($conn);
?>

