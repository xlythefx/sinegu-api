<?php
/**
 * Admin: Get daily P&L series for a user (by uni_id in query string).
 * GET /admin/GET/get_user_daily_pnl.php?uni_id=...
 * Returns: daily_series (date, pnl, cumulative), adjusted_initial_deposit
 */

ob_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ob_end_clean();
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$uni_id = isset($_GET['uni_id']) ? trim($_GET['uni_id']) : '';

if (empty($uni_id)) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'uni_id is required']);
    exit;
}

require_once __DIR__ . '/../../conn.php';

if (!$conn) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$accountSql = 'SELECT id, COALESCE(initial_deposit, deposit_amount, 0) AS initial_deposit
               FROM accounts
               WHERE uni_id = ? AND (deleted_at IS NULL)
               ORDER BY id ASC';

if (!($stmt = mysqli_prepare($conn, $accountSql))) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to prepare query: ' . mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

mysqli_stmt_bind_param($stmt, 's', $uni_id);
if (!mysqli_stmt_execute($stmt)) {
    mysqli_stmt_close($stmt);
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database query failed']);
    mysqli_close($conn);
    exit;
}

$result = mysqli_stmt_get_result($stmt);
$accounts = [];
while ($row = mysqli_fetch_assoc($result)) {
    $accounts[] = [
        'id' => (int) $row['id'],
        'initial_deposit' => (float) $row['initial_deposit'],
    ];
}
mysqli_stmt_close($stmt);

if (empty($accounts)) {
    ob_end_clean();
    echo json_encode([
        'success' => true,
        'adjusted_initial_deposit' => 0.0,
        'daily_series' => [],
    ]);
    mysqli_close($conn);
    exit;
}

$account_ids = array_column($accounts, 'id');
$adjusted_initial_deposit = 0.0;

foreach ($accounts as $a) {
    $capSql = 'SELECT COALESCE(SUM(amount), 0) AS net_flow FROM capital_transactions WHERE account_id = ?';
    if ($stmt = mysqli_prepare($conn, $capSql)) {
        mysqli_stmt_bind_param($stmt, 'i', $a['id']);
        if (mysqli_stmt_execute($stmt)) {
            $r = mysqli_stmt_get_result($stmt);
            $capRow = mysqli_fetch_assoc($r);
            $net = (float) ($capRow['net_flow'] ?? 0);
            $adjusted_initial_deposit += $a['initial_deposit'] + $net;
        }
        mysqli_stmt_close($stmt);
    } else {
        $adjusted_initial_deposit += $a['initial_deposit'];
    }
}

$days_back = 365;
$calendar_start = (new DateTime('today'))->modify('-' . ($days_back - 1) . ' days')->format('Y-m-d 00:00:00');

$placeholders = implode(',', array_fill(0, count($account_ids), '?'));
$types = str_repeat('i', count($account_ids)) . 's';

$dailySql = "SELECT COALESCE(DATE(date_closed), DATE(created_at)) AS trade_date, COALESCE(SUM(pnl), 0) AS pnl
             FROM trades
             WHERE account_id IN ($placeholders) AND COALESCE(date_closed, created_at) >= ?
             GROUP BY COALESCE(DATE(date_closed), DATE(created_at))
             ORDER BY trade_date ASC";

if (!($stmt = mysqli_prepare($conn, $dailySql))) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to prepare daily PnL query']);
    mysqli_close($conn);
    exit;
}

$bind_params = array_merge($account_ids, [$calendar_start]);
$refs = [];
foreach ($bind_params as $k => $v) {
    $refs[$k] = &$bind_params[$k];
}
call_user_func_array([$stmt, 'bind_param'], array_merge([$types], $refs));

if (!mysqli_stmt_execute($stmt)) {
    mysqli_stmt_close($stmt);
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database query failed']);
    mysqli_close($conn);
    exit;
}

$result = mysqli_stmt_get_result($stmt);
$daily_series = [];
$cumulative = $adjusted_initial_deposit;

while ($row = mysqli_fetch_assoc($result)) {
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
mysqli_stmt_close($stmt);
mysqli_close($conn);

ob_end_clean();
echo json_encode([
    'success' => true,
    'adjusted_initial_deposit' => $adjusted_initial_deposit,
    'daily_series' => $daily_series,
]);
