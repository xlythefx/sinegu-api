<?php
/**
 * Get Binance account data for a user by uni_id (mirrors account_data.php).
 * GET: ?uni_id=xxx
 * Returns: {"success": true, "accounts": [...], "totals": {...}, "timestamp": "..."}
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
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use GET.']);
    exit;
}

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$uni_id = isset($_GET['uni_id']) ? trim($_GET['uni_id']) : null;
if (!$uni_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required parameter: uni_id']);
    exit;
}

$uni_id = mysqli_real_escape_string($conn, $uni_id);

$sql = "SELECT api_key, name, balance, unrealized_pnl, initial_deposit, currency_type FROM binance_accounts WHERE uni_id = '{$uni_id}' AND (deleted_at IS NULL) ORDER BY created_at DESC";
$result = mysqli_query($conn, $sql);

if (!$result) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    exit;
}

$user_accounts = [];
while ($row = mysqli_fetch_assoc($result)) {
    $api_key = $row['api_key'];
    $balance = (float) ($row['balance'] ?? 0);
    $initial_deposit = (float) ($row['initial_deposit'] ?? 0);

    // Realized PnL from binance_pastpositions for this api_key
    $realizedPnl = 0.0;
    $rSql = "SELECT COALESCE(SUM(realized_pnl), 0) AS total FROM binance_pastpositions WHERE api_key = '" . mysqli_real_escape_string($conn, $api_key) . "'";
    $rRes = mysqli_query($conn, $rSql);
    if ($rRes && $rRow = mysqli_fetch_assoc($rRes)) {
        $realizedPnl = (float) $rRow['total'];
    }
    if ($rRes) mysqli_free_result($rRes);

    // Unrealized PnL from binance_accounts.unrealized_pnl (totalUnrealizedProfit from /fapi/v3/account)
    $unrealizedPnl = (float) ($row['unrealized_pnl'] ?? 0);

    $equity = $balance + $unrealizedPnl;
    $user_accounts[] = [
        'api_key' => $api_key,
        'name' => $row['name'] ?? '',
        'balance' => $balance,
        'deposit_amount' => $initial_deposit,
        'initial_deposit' => $initial_deposit,
        'unrealized_pnl' => $unrealizedPnl,
        'realized_pnl' => $realizedPnl,
        'equity' => $equity,
        'currency_type' => $row['currency_type'] ?? 'USDT',
    ];
}

if (empty($user_accounts)) {
    echo json_encode([
        'success' => true,
        'accounts' => [],
        'totals' => [
            'balance' => 0.0,
            'deposit_amount' => 0.0,
            'initial_deposit' => 0.0,
            'unrealized_pnl' => 0.0,
            'realized_pnl' => 0.0,
            'equity' => 0.0,
            'currency_type' => 'USDT',
        ],
        'timestamp' => date('Y-m-d\TH:i:s\Z'),
    ]);
    mysqli_close($conn);
    exit;
}

$totals = [
    'balance' => 0.0,
    'deposit_amount' => 0.0,
    'initial_deposit' => 0.0,
    'unrealized_pnl' => 0.0,
    'realized_pnl' => 0.0,
    'equity' => 0.0,
    'currency_type' => 'USDT',
];
foreach ($user_accounts as $a) {
    $totals['balance'] += $a['balance'];
    $totals['deposit_amount'] += $a['deposit_amount'];
    $totals['initial_deposit'] += $a['initial_deposit'];
    $totals['unrealized_pnl'] += $a['unrealized_pnl'];
    $totals['realized_pnl'] += $a['realized_pnl'];
    $totals['equity'] += $a['equity'];
    if (!empty($a['currency_type'])) $totals['currency_type'] = $a['currency_type'];
}

echo json_encode([
    'success' => true,
    'accounts' => $user_accounts,
    'totals' => $totals,
    'timestamp' => date('Y-m-d\TH:i:s\Z'),
]);
mysqli_close($conn);
