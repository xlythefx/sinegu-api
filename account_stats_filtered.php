<?php

// Start output buffering
ob_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_clean();
    http_response_code(204);
    ob_end_flush();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_clean();
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed',
    ]);
    ob_end_flush();
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input) || empty($input['account_id'])) {
    ob_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Account id is required',
    ]);
    ob_end_flush();
    exit;
}

$accountId = (int) $input['account_id'];
$dateFrom = isset($input['date_from']) ? trim($input['date_from']) : null;
$dateTo = isset($input['date_to']) ? trim($input['date_to']) : null;
$excludedTickers = isset($input['excluded_tickers']) && is_array($input['excluded_tickers']) 
    ? array_map('trim', $input['excluded_tickers']) 
    : [];

require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/combined_master.php';

if (!$conn) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed',
    ]);
    ob_end_flush();
    exit;
}

// Combined Master (temporary, prod-only via .env): splice the legacy account's
// pre-cutoff trades with the current master's post-cutoff trades. Null otherwise.
$combined = combined_master_for_account($accountId);

// Build WHERE clause for date range
if ($combined) {
    $whereConditions = ['((account_id = ? AND DATE(created_at) < ?) OR (account_id = ? AND DATE(created_at) >= ?))'];
    $params = [$combined['legacy_id'], $combined['cutoff'], $combined['current_id'], $combined['cutoff']];
    $types = 'isis';
} else {
    $whereConditions = ['account_id = ?'];
    $params = [$accountId];
    $types = 'i';
}

if ($dateFrom) {
    $whereConditions[] = 'DATE(created_at) >= ?';
    $params[] = $dateFrom;
    $types .= 's';
}

if ($dateTo) {
    $whereConditions[] = 'DATE(created_at) <= ?';
    $params[] = $dateTo;
    $types .= 's';
}

// Build WHERE clause for excluded tickers
if (!empty($excludedTickers)) {
    $placeholders = implode(',', array_fill(0, count($excludedTickers), '?'));
    $whereConditions[] = "ticker NOT IN ($placeholders)";
    foreach ($excludedTickers as $ticker) {
        $params[] = $ticker;
        $types .= 's';
    }
}

$whereClause = implode(' AND ', $whereConditions);

// Get initial_deposit from accounts table as starting point (use as reference first value)
$depositSql = "SELECT COALESCE(initial_deposit, deposit_amount, 0) AS deposit_amount FROM accounts WHERE id = ?";
$depositStmt = mysqli_prepare($conn, $depositSql);
$depositAmount = 0.0;
// In combined mode the timeline starts with the legacy account, so its deposit is the baseline.
$depositAccountId = $combined ? $combined['legacy_id'] : $accountId;
if ($depositStmt) {
    mysqli_stmt_bind_param($depositStmt, 'i', $depositAccountId);
    mysqli_stmt_execute($depositStmt);
    $depositResult = mysqli_stmt_get_result($depositStmt);
    if ($depositRow = mysqli_fetch_assoc($depositResult)) {
        $depositAmount = (float) ($depositRow['deposit_amount'] ?? 0.0);
    }
    mysqli_stmt_close($depositStmt);
}

// Get daily series for the date range
$dailySeriesSql = "SELECT DATE(created_at) AS trade_date, COALESCE(SUM(pnl), 0) AS pnl
    FROM trades
    WHERE $whereClause
    GROUP BY DATE(created_at)
    ORDER BY trade_date ASC";

$stmt = mysqli_prepare($conn, $dailySeriesSql);
if (!$stmt) {
    mysqli_close($conn);
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to prepare statement: ' . mysqli_error($conn),
    ]);
    ob_end_flush();
    exit;
}

mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$dailySeries = [];
$cumulativePnl = $depositAmount; // Start from deposit_amount instead of 0
while ($row = mysqli_fetch_assoc($result)) {
    $dailyPnl = (float) $row['pnl'];
    $cumulativePnl += $dailyPnl; // Add daily P&L to cumulative
    $dailySeries[] = [
        'date' => $row['trade_date'],
        'pnl' => $dailyPnl, // Daily P&L for calendar
        'cumulative_pnl' => $cumulativePnl, // Cumulative value starting from deposit for chart
    ];
}
mysqli_stmt_close($stmt);

// Get totals
$totalsSql = "SELECT 
    COALESCE(SUM(pnl), 0) AS total_pnl,
    COALESCE(SUM(closed_positions), 0) AS closed_positions,
    COUNT(*) AS trade_count,
    MAX(created_at) AS last_trade_at
    FROM trades
    WHERE $whereClause";

$stmt = mysqli_prepare($conn, $totalsSql);
if (!$stmt) {
    mysqli_close($conn);
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to prepare statement (totals): ' . mysqli_error($conn),
    ]);
    ob_end_flush();
    exit;
}

mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$totalsRow = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

// Get active positions (not filtered by date/ticker as they're current)
$activeSql = "SELECT COUNT(*) AS active_positions FROM positions WHERE account_id = ?";
$stmt = mysqli_prepare($conn, $activeSql);
$activePositions = 0;
if ($stmt) {
    mysqli_stmt_bind_param($stmt, 'i', $accountId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $activeRow = mysqli_fetch_assoc($result);
    $activePositions = (int) ($activeRow['active_positions'] ?? 0);
    mysqli_stmt_close($stmt);
}

// Get period breakdowns (daily, weekly, monthly)
$periods = [
    'daily' => '-1 day',
    'weekly' => '-7 day',
    'monthly' => '-30 day',
];

$breakdown = [
    'daily' => ['pnl' => 0.0, 'trades' => 0, 'from' => null, 'to' => null],
    'weekly' => ['pnl' => 0.0, 'trades' => 0, 'from' => null, 'to' => null],
    'monthly' => ['pnl' => 0.0, 'trades' => 0, 'from' => null, 'to' => null],
];

$periodSql = "SELECT COALESCE(SUM(pnl), 0) AS pnl, COUNT(*) AS trades
    FROM trades
    WHERE $whereClause AND created_at >= ?";

foreach ($periods as $key => $modifier) {
    $start = date('Y-m-d H:i:s', strtotime($modifier));
    $now = date('Y-m-d H:i:s');
    
    // Build params for period query (account_id + date filters + excluded tickers + period start)
    $periodParams = $params;
    $periodParams[] = $start;
    $periodTypes = $types . 's';
    
    $stmt = mysqli_prepare($conn, $periodSql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, $periodTypes, ...$periodParams);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $periodPnl, $periodTrades);
        if (mysqli_stmt_fetch($stmt)) {
            $breakdown[$key]['pnl'] = (float) $periodPnl;
            $breakdown[$key]['trades'] = (int) $periodTrades;
            $breakdown[$key]['from'] = $start;
            $breakdown[$key]['to'] = $now;
        }
        mysqli_stmt_close($stmt);
    }
}

mysqli_close($conn);

ob_clean();
echo json_encode([
    'success' => true,
    'totals' => [
        'total_pnl' => (float) ($totalsRow['total_pnl'] ?? 0),
        'trade_count' => (int) ($totalsRow['trade_count'] ?? 0),
        'closed_positions' => (int) ($totalsRow['closed_positions'] ?? 0),
        'active_positions' => $activePositions,
        'last_trade_at' => $totalsRow['last_trade_at'] ?? null,
    ],
    'breakdown' => $breakdown,
    'daily_series' => $dailySeries,
    'deposit_amount' => $depositAmount, // Include initial_deposit (or deposit_amount fallback) in response
    'currency' => $combined ? $combined['currency'] : null, // USD hint when combined (no FX conversion)
]);

ob_end_flush();
?>

