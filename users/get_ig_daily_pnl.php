<?php
/**
 * Get daily P&L series for IG.com (mirrors get_binance_daily_pnl.php).
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

// Get IG accounts and their initial_deposit
$accSql = "SELECT id, COALESCE(initial_deposit, 0) AS initial_deposit FROM ig_accounts WHERE uni_id = '{$uni_id}' AND (deleted_at IS NULL)";
$accRes = mysqli_query($conn, $accSql);
$account_ids = [];
$adjusted_initial_deposit = 0.0;
while ($accRes && $row = mysqli_fetch_assoc($accRes)) {
    $account_ids[] = (int) $row['id'];
    $adjusted_initial_deposit += (float) $row['initial_deposit'];

    // Add net flow from ig_transactions for this account
    $aid = (int) $row['id'];
    $flowSql = "SELECT COALESCE(SUM(amount), 0) AS net_flow FROM ig_transactions WHERE account_id = {$aid}";
    $flowRes = mysqli_query($conn, $flowSql);
    if ($flowRes && $fRow = mysqli_fetch_assoc($flowRes)) {
        $adjusted_initial_deposit += (float) $fRow['net_flow'];
    }
    if ($flowRes) mysqli_free_result($flowRes);
}
if ($accRes) mysqli_free_result($accRes);

if (empty($account_ids)) {
    mysqli_close($conn);
    echo json_encode(['success' => true, 'adjusted_initial_deposit' => 0.0, 'daily_series' => []]);
    exit;
}

$ids_str = implode(',', $account_ids);
$days_back = 365;
$calendar_start = date('Y-m-d 00:00:00', strtotime("-{$days_back} days"));

$dailySql = "SELECT DATE(closed_at) AS trade_date, COALESCE(SUM(pnl), 0) AS pnl
    FROM ig_past_positions
    WHERE account_id IN ({$ids_str}) AND closed_at >= '{$calendar_start}'
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
        'date'       => $trade_date,
        'pnl'        => $daily_pnl,
        'cumulative' => $cumulative,
    ];
}
if ($dailyRes) mysqli_free_result($dailyRes);

mysqli_close($conn);

echo json_encode([
    'success'                  => true,
    'adjusted_initial_deposit' => $adjusted_initial_deposit,
    'daily_series'             => $daily_series,
]);
