<?php

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
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed',
    ]);
    exit;
}

require_once __DIR__ . '/conn.php';

// `uni_id` + `account_balance` let admin views resolve the owner of a trade and express
// its P&L as a share of that owner's account balance.
$sql = 'SELECT t.id, t.account_id, t.ticker, t.price, t.pnl, t.pnl_percentage, t.closed_positions, t.date_closed, t.time_closed, t.created_at, t.strategy, a.name AS account_name, a.email AS account_email, a.uni_id, a.balance AS account_balance, a.currency_type AS account_currency FROM trades t LEFT JOIN accounts a ON t.account_id = a.id ORDER BY t.created_at DESC';
$result = mysqli_query($conn, $sql);

if (!$result) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to fetch trades',
    ]);
    exit;
}

$trades = [];

while ($row = mysqli_fetch_assoc($result)) {
    $row['pnl'] = (float) $row['pnl'];
    $row['pnl_percentage'] = (float) $row['pnl_percentage'];
    $row['closed_positions'] = (int) $row['closed_positions'];
    $row['account_balance'] = $row['account_balance'] === null ? null : (float) $row['account_balance'];
    $trades[] = $row;
}

mysqli_free_result($result);
mysqli_close($conn);

echo json_encode([
    'success' => true,
    'trades' => $trades,
]);

