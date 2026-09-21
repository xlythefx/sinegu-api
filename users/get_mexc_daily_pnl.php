<?php
/**
 * MEXC twin of get_binance_daily_pnl.php (the mexc_* tables are column-for-column Binance).
 * Get daily P&L series for MEXC (mirrors get_account_daily_pnl.php).
 * POST body: { "uni_id": "..." }
 * Returns: adjusted_initial_deposit, daily_series (date, pnl, cumulative).
 */

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
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) || empty(trim($input['uni_id'] ?? ''))) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'uni_id is required']);
    exit;
}

$uni_id = trim($input['uni_id']);
require_once __DIR__ . '/../conn.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$uni_id = mysqli_real_escape_string($conn, $uni_id);

// Get MEXC accounts and initial_deposit
$accSql = "SELECT api_key, COALESCE(initial_deposit, 0) AS initial_deposit FROM mexc_accounts WHERE uni_id = '{$uni_id}' AND (deleted_at IS NULL)";
$accRes = mysqli_query($conn, $accSql);
$accounts = [];
while ($accRes && $row = mysqli_fetch_assoc($accRes)) {
    $accounts[] = ['api_key' => $row['api_key'], 'initial_deposit' => (float) $row['initial_deposit']];
}
if ($accRes) mysqli_free_result($accRes);

$adjusted_initial_deposit = 0.0;
foreach ($accounts as $a) {
    $adjusted_initial_deposit += $a['initial_deposit'];
    $api_key = mysqli_real_escape_string($conn, $a['api_key']);
    // mexc_transactions.amount is stored ABSOLUTE with type = DEPOSIT|WITHDRAWAL
    // (migration 039), so withdrawals must be negated — a plain SUM(amount) would
    // inflate the baseline.
    $flowSql = "SELECT COALESCE(SUM(CASE WHEN UPPER(type) = 'WITHDRAWAL' THEN -amount ELSE amount END), 0) AS net_flow FROM mexc_transactions WHERE api_key = '{$api_key}'";
    $flowRes = mysqli_query($conn, $flowSql);
    if ($flowRes && $fRow = mysqli_fetch_assoc($flowRes)) {
        $adjusted_initial_deposit += (float) $fRow['net_flow'];
    }
    if ($flowRes) mysqli_free_result($flowRes);
}

$days_back = 365;
$calendar_start = date('Y-m-d 00:00:00', strtotime("-{$days_back} days"));

$dailySql = "SELECT DATE(closed_at) AS trade_date, COALESCE(SUM(realized_pnl), 0) AS pnl
    FROM mexc_pastpositions
    WHERE uni_id = '{$uni_id}' AND closed_at >= '{$calendar_start}'
    GROUP BY DATE(closed_at)
    ORDER BY trade_date ASC";
$dailyRes = mysqli_query($conn, $dailySql);

$daily_series = [];
$cumulative = $adjusted_initial_deposit;
while ($dailyRes && $row = mysqli_fetch_assoc($dailyRes)) {
    $trade_date = $row['trade_date'] ?? null;
    if ($trade_date === null) continue;
    $daily_pnl = (float) ($row['pnl'] ?? 0);
    $cumulative += $daily_pnl;
    $daily_series[] = [
        'date' => $trade_date,
        'pnl' => $daily_pnl,
        'cumulative' => $cumulative,
    ];
}
if ($dailyRes) mysqli_free_result($dailyRes);

mysqli_close($conn);

echo json_encode([
    'success' => true,
    'adjusted_initial_deposit' => $adjusted_initial_deposit,
    'daily_series' => $daily_series,
]);
