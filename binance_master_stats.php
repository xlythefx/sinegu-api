<?php
/**
 * Binance master-bot stats for the public "Bot Performance Analytics" section.
 *
 * Mirrors the response shape of account_stats_filtered.php (Capital.com) so the
 * landing page can merge the two brokers into one series.
 *
 * The account is resolved by name (default "master") on binance_accounts, which
 * is the Binance twin of how flask/master_performance_report.php resolves the
 * Capital master via accounts.name = 'master'.
 *
 * POST (or GET) body: all optional
 *   { "name": "master", "date_from": "YYYY-MM-DD", "date_to": "YYYY-MM-DD",
 *     "excluded_tickers": ["BTCUSDT"] }
 *
 * Returns { success, found, account, totals, breakdown, daily_series,
 *           deposit_amount, adjusted_initial_deposit }.
 * When no master account exists this returns success:true / found:false with
 * zeroed aggregates so the caller can degrade instead of erroring.
 *
 * NOTE: dates are attributed on binance_pastpositions.closed_at (the trade close),
 * matching users/get_binance_daily_pnl.php.
 */

ob_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_clean();
    http_response_code(204);
    ob_end_flush();
    exit;
}

if (!in_array($_SERVER['REQUEST_METHOD'], ['POST', 'GET'], true)) {
    ob_clean();
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    ob_end_flush();
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}
$input = array_merge($_GET, $input);

$masterName = trim((string) ($input['name'] ?? 'master'));
if ($masterName === '') {
    $masterName = 'master';
}
$dateFrom = isset($input['date_from']) ? trim((string) $input['date_from']) : null;
$dateTo = isset($input['date_to']) ? trim((string) $input['date_to']) : null;
$excludedTickers = isset($input['excluded_tickers']) && is_array($input['excluded_tickers'])
    ? array_values(array_filter(array_map('trim', $input['excluded_tickers']), function ($t) { return $t !== ''; }))
    : [];

require_once __DIR__ . '/conn.php';

if (!$conn) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    ob_end_flush();
    exit;
}

/** Empty payload used when there is no Binance master account configured. */
function binance_master_empty($message = null)
{
    $breakdown = [
        'daily' => ['pnl' => 0.0, 'trades' => 0, 'from' => null, 'to' => null],
        'weekly' => ['pnl' => 0.0, 'trades' => 0, 'from' => null, 'to' => null],
        'monthly' => ['pnl' => 0.0, 'trades' => 0, 'from' => null, 'to' => null],
    ];
    return [
        'success' => true,
        'found' => false,
        'message' => $message,
        'account' => null,
        'totals' => [
            'total_pnl' => 0.0,
            'trade_count' => 0,
            'closed_positions' => 0,
            'active_positions' => 0,
            'last_trade_at' => null,
        ],
        'breakdown' => $breakdown,
        'daily_series' => [],
        'deposit_amount' => 0.0,
        'adjusted_initial_deposit' => 0.0,
    ];
}

// ---------------------------------------------------------------- master account
// name is UNIQUE on binance_accounts; LOWER() keeps "Master"/"MASTER" working.
$accountSql = "SELECT api_key, name, uni_id,
                      COALESCE(balance, 0) AS balance,
                      COALESCE(initial_deposit, 0) AS initial_deposit,
                      COALESCE(unrealized_pnl, 0) AS unrealized_pnl,
                      COALESCE(currency_type, 'USDT') AS currency_type
               FROM binance_accounts
               WHERE LOWER(name) = LOWER(?) AND deleted_at IS NULL
               ORDER BY created_at ASC
               LIMIT 1";
$stmt = mysqli_prepare($conn, $accountSql);
if (!$stmt) {
    mysqli_close($conn);
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to prepare account query']);
    ob_end_flush();
    exit;
}
mysqli_stmt_bind_param($stmt, 's', $masterName);
mysqli_stmt_execute($stmt);
$account = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$account) {
    mysqli_close($conn);
    ob_clean();
    echo json_encode(binance_master_empty("No Binance account named '{$masterName}'"));
    ob_end_flush();
    exit;
}

$apiKey = $account['api_key'];

// ---------------------------------------------------------------- filters
$whereConditions = ['api_key = ?'];
$params = [$apiKey];
$types = 's';

if ($dateFrom) {
    $whereConditions[] = 'DATE(closed_at) >= ?';
    $params[] = $dateFrom;
    $types .= 's';
}
if ($dateTo) {
    $whereConditions[] = 'DATE(closed_at) <= ?';
    $params[] = $dateTo;
    $types .= 's';
}
if (!empty($excludedTickers)) {
    $placeholders = implode(',', array_fill(0, count($excludedTickers), '?'));
    $whereConditions[] = "symbol NOT IN ($placeholders)";
    foreach ($excludedTickers as $ticker) {
        $params[] = $ticker;
        $types .= 's';
    }
}
$whereClause = implode(' AND ', $whereConditions);

// ---------------------------------------------------------------- capital baseline
// binance_transactions.amount is stored ABSOLUTE with type = DEPOSIT|WITHDRAWAL
// (migration 039), so withdrawals must be negated — a plain SUM(amount) would
// inflate the baseline.
$depositAmount = (float) $account['initial_deposit'];
$netFlow = 0.0;
$flowSql = "SELECT COALESCE(SUM(CASE WHEN UPPER(type) = 'WITHDRAWAL' THEN -amount ELSE amount END), 0) AS net_flow
            FROM binance_transactions
            WHERE api_key = ?";
if ($flowStmt = mysqli_prepare($conn, $flowSql)) {
    mysqli_stmt_bind_param($flowStmt, 's', $apiKey);
    if (mysqli_stmt_execute($flowStmt)) {
        mysqli_stmt_bind_result($flowStmt, $netFlow);
        mysqli_stmt_fetch($flowStmt);
    }
    mysqli_stmt_close($flowStmt);
}
$adjustedInitialDeposit = $depositAmount + (float) $netFlow;

// ---------------------------------------------------------------- daily series
$dailySql = "SELECT DATE(closed_at) AS trade_date, COALESCE(SUM(realized_pnl), 0) AS pnl
             FROM binance_pastpositions
             WHERE $whereClause AND closed_at IS NOT NULL
             GROUP BY DATE(closed_at)
             ORDER BY trade_date ASC";
$stmt = mysqli_prepare($conn, $dailySql);
if (!$stmt) {
    mysqli_close($conn);
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to prepare daily series query']);
    ob_end_flush();
    exit;
}
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$dailySeries = [];
$cumulative = $adjustedInitialDeposit;
while ($row = mysqli_fetch_assoc($result)) {
    if (($row['trade_date'] ?? null) === null) {
        continue;
    }
    $dailyPnl = (float) $row['pnl'];
    $cumulative += $dailyPnl;
    $dailySeries[] = [
        'date' => $row['trade_date'],
        'pnl' => $dailyPnl,
        'cumulative_pnl' => $cumulative,
    ];
}
mysqli_stmt_close($stmt);

// ---------------------------------------------------------------- totals
$totalsSql = "SELECT COALESCE(SUM(realized_pnl), 0) AS total_pnl,
                     COUNT(*) AS trade_count,
                     MAX(closed_at) AS last_trade_at
              FROM binance_pastpositions
              WHERE $whereClause";
$stmt = mysqli_prepare($conn, $totalsSql);
if (!$stmt) {
    mysqli_close($conn);
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to prepare totals query']);
    ob_end_flush();
    exit;
}
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$totalsRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

// ---------------------------------------------------------------- active positions
$activePositions = 0;
if ($activeStmt = mysqli_prepare($conn, 'SELECT COUNT(*) FROM binance_positions WHERE api_key = ?')) {
    mysqli_stmt_bind_param($activeStmt, 's', $apiKey);
    if (mysqli_stmt_execute($activeStmt)) {
        mysqli_stmt_bind_result($activeStmt, $activePositions);
        mysqli_stmt_fetch($activeStmt);
    }
    mysqli_stmt_close($activeStmt);
}

// ---------------------------------------------------------------- rolling breakdown
$periods = ['daily' => '-1 day', 'weekly' => '-7 day', 'monthly' => '-30 day'];
$breakdown = [
    'daily' => ['pnl' => 0.0, 'trades' => 0, 'from' => null, 'to' => null],
    'weekly' => ['pnl' => 0.0, 'trades' => 0, 'from' => null, 'to' => null],
    'monthly' => ['pnl' => 0.0, 'trades' => 0, 'from' => null, 'to' => null],
];
$periodSql = "SELECT COALESCE(SUM(realized_pnl), 0) AS pnl, COUNT(*) AS trades
              FROM binance_pastpositions
              WHERE $whereClause AND closed_at >= ?";
foreach ($periods as $key => $modifier) {
    $start = date('Y-m-d H:i:s', strtotime($modifier));
    $now = date('Y-m-d H:i:s');
    $periodParams = $params;
    $periodParams[] = $start;
    $periodTypes = $types . 's';

    if ($stmt = mysqli_prepare($conn, $periodSql)) {
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
    'found' => true,
    'account' => [
        'name' => $account['name'],
        'currency_type' => $account['currency_type'],
        'balance' => (float) $account['balance'],
        'unrealized_pnl' => (float) $account['unrealized_pnl'],
    ],
    'totals' => [
        'total_pnl' => (float) ($totalsRow['total_pnl'] ?? 0),
        'trade_count' => (int) ($totalsRow['trade_count'] ?? 0),
        'closed_positions' => (int) ($totalsRow['trade_count'] ?? 0),
        'active_positions' => (int) $activePositions,
        'last_trade_at' => $totalsRow['last_trade_at'] ?? null,
    ],
    'breakdown' => $breakdown,
    'daily_series' => $dailySeries,
    'deposit_amount' => $depositAmount,
    'adjusted_initial_deposit' => $adjustedInitialDeposit,
]);

ob_end_flush();
