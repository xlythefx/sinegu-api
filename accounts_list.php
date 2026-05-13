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

if (!$conn) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed',
    ]);
    exit;
}

// Build SELECT from existing columns so we work with or without initial_deposit, equity, etc.
$describe = mysqli_query($conn, 'DESCRIBE accounts');
if (!$describe) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Table accounts not found or inaccessible',
    ]);
    exit;
}
$allowed = ['id', 'uni_id', 'name', 'api_key', 'broker_accountID', 'email', 'password', 'demo', 'enabled', 'created_at', 'balance', 'unrealized_pnl', 'currency_type', 'deposit_amount', 'initial_deposit', 'equity', 'realized_pnl', 'cst', 'x_security_token', 'session_last_used_at', 'deleted_at'];
$cols = [];
while ($dr = mysqli_fetch_assoc($describe)) {
    if (in_array($dr['Field'], $allowed, true)) {
        $cols[] = $dr['Field'];
    }
}

mysqli_free_result($describe);
if (!in_array('id', $cols, true) || !in_array('uni_id', $cols, true)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Table accounts must have at least id and uni_id',
    ]);
    exit;
}

$selectList = implode(', ', $cols);
// Admin: return all accounts (active + soft-deleted); active first, then by email
$sql = "SELECT {$selectList} FROM accounts ORDER BY (deleted_at IS NULL) DESC, email ASC";
$result = mysqli_query($conn, $sql);

if (!$result) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to fetch accounts: ' . mysqli_error($conn),
    ]);
    exit;
}

$accounts = [];

while ($row = mysqli_fetch_assoc($result)) {
    $accountId = (int) $row['id'];
    
    // Calculate realized_pnl from trades table (if not already in row)
    $realizedPnl = isset($row['realized_pnl']) ? (float) $row['realized_pnl'] : null;
    if ($realizedPnl === null) {
        $realizedPnlSql = "SELECT COALESCE(SUM(pnl), 0) AS total_pnl FROM trades WHERE account_id = $accountId";
        $realizedPnlResult = mysqli_query($conn, $realizedPnlSql);
        $realizedPnl = 0.0;
        if ($realizedPnlResult) {
            $realizedPnlRow = mysqli_fetch_assoc($realizedPnlResult);
            $realizedPnl = isset($realizedPnlRow['total_pnl']) ? (float) $realizedPnlRow['total_pnl'] : 0.0;
            mysqli_free_result($realizedPnlResult);
        }
    }
    
    $row['demo'] = (int) ($row['demo'] ?? 0);
    $row['enabled'] = (int) ($row['enabled'] ?? 1);
    $row['balance'] = isset($row['balance']) ? (float) $row['balance'] : null;
    $row['realized_pnl'] = $realizedPnl;
    $row['unrealized_pnl'] = isset($row['unrealized_pnl']) ? (float) $row['unrealized_pnl'] : null;
    $row['deposit_amount'] = isset($row['deposit_amount']) ? (float) $row['deposit_amount'] : 0.0;
    $row['initial_deposit'] = isset($row['initial_deposit']) ? (float) $row['initial_deposit'] : ($row['deposit_amount'] ?? null);
    $accounts[] = $row;
}

mysqli_free_result($result);
mysqli_close($conn);

echo json_encode([
    'success' => true,
    'accounts' => $accounts,
]);

