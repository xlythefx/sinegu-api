<?php
/**
 * Bybit twin of binance_account_stats.php (the bybit_* tables are column-for-column Binance).
 * Bybit account stats (mirrors account_stats.php).
 * POST body: { "uni_id": "..." }
 * Returns: totals, breakdown (daily/weekly/monthly), daily_series.
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

$response = [
    'success' => true,
    'totals' => [
        'total_pnl' => 0.0,
        'trade_count' => 0,
        'closed_positions' => 0,
        'active_positions' => 0,
        'last_trade_at' => null,
    ],
    'breakdown' => [
        'daily' => ['pnl' => 0.0, 'trades' => 0, 'from' => null, 'to' => null],
        'weekly' => ['pnl' => 0.0, 'trades' => 0, 'from' => null, 'to' => null],
        'monthly' => ['pnl' => 0.0, 'trades' => 0, 'from' => null, 'to' => null],
    ],
    'daily_series' => [],
];

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$uni_id = mysqli_real_escape_string($conn, $uni_id);

// Totals from bybit_pastpositions (closed trades)
$totalSql = "SELECT COALESCE(SUM(realized_pnl), 0) AS total_pnl, COUNT(*) AS trade_count, MAX(closed_at) AS last_trade_at
    FROM bybit_pastpositions WHERE uni_id = '{$uni_id}'";
$totalRes = mysqli_query($conn, $totalSql);
if ($totalRes && $row = mysqli_fetch_assoc($totalRes)) {
    $response['totals']['total_pnl'] = (float) $row['total_pnl'];
    $response['totals']['trade_count'] = (int) $row['trade_count'];
    $response['totals']['closed_positions'] = (int) $row['trade_count'];
    $response['totals']['last_trade_at'] = $row['last_trade_at'];
}
if ($totalRes) mysqli_free_result($totalRes);

// Active positions count from bybit_positions
$activeSql = "SELECT COUNT(*) AS active_positions FROM bybit_positions WHERE uni_id = '{$uni_id}'";
$activeRes = mysqli_query($conn, $activeSql);
if ($activeRes && $row = mysqli_fetch_assoc($activeRes)) {
    $response['totals']['active_positions'] = (int) $row['active_positions'];
}
if ($activeRes) mysqli_free_result($activeRes);

$now = date('Y-m-d H:i:s');
$periods = [
    'daily' => date('Y-m-d H:i:s', strtotime('-1 day')),
    'weekly' => date('Y-m-d H:i:s', strtotime('-7 day')),
    'monthly' => date('Y-m-d H:i:s', strtotime('-30 day')),
];

foreach ($periods as $key => $start) {
    $periodSql = "SELECT COALESCE(SUM(realized_pnl), 0) AS pnl, COUNT(*) AS trades
        FROM bybit_pastpositions WHERE uni_id = '{$uni_id}' AND closed_at >= '{$start}'";
    $periodRes = mysqli_query($conn, $periodSql);
    if ($periodRes && $row = mysqli_fetch_assoc($periodRes)) {
        $response['breakdown'][$key]['pnl'] = (float) $row['pnl'];
        $response['breakdown'][$key]['trades'] = (int) $row['trades'];
        $response['breakdown'][$key]['from'] = $start;
        $response['breakdown'][$key]['to'] = $now;
    }
    if ($periodRes) mysqli_free_result($periodRes);
}

// Daily series for calendar (last 42 days)
$daysBack = 42;
$calendarStart = date('Y-m-d 00:00:00', strtotime('-' . ($daysBack - 1) . ' days'));
$dailySql = "SELECT DATE(closed_at) AS trade_date, COALESCE(SUM(realized_pnl), 0) AS pnl
    FROM bybit_pastpositions
    WHERE uni_id = '{$uni_id}' AND closed_at >= '{$calendarStart}'
    GROUP BY DATE(closed_at)
    ORDER BY trade_date";
$dailyRes = mysqli_query($conn, $dailySql);
$byDate = [];
while ($dailyRes && $row = mysqli_fetch_assoc($dailyRes)) {
    $byDate[$row['trade_date']] = (float) $row['pnl'];
}
if ($dailyRes) mysqli_free_result($dailyRes);

$calendarCursor = new DateTime('today');
$calendarCursor->modify('-' . ($daysBack - 1) . ' days');
for ($i = 0; $i < $daysBack; $i++) {
    $d = $calendarCursor->format('Y-m-d');
    $response['daily_series'][] = ['date' => $d, 'pnl' => $byDate[$d] ?? 0.0];
    $calendarCursor->modify('+1 day');
}

mysqli_close($conn);
echo json_encode($response);
