<?php
/**
 * Get daily P&L series for the current (logged-in) user with cumulative =
 * adjustedInitialDeposit + daily PnL so the chart starts at the deposit line.
 *
 * POST body: { "uni_id": "..." }  (uni_id from localStorage / session)
 * Optional: { "uni_id": "...", "account_id": 123 }  to target a single account.
 * If account_id omitted, aggregates all accounts for this user.
 *
 * Returns: daily_series with date, pnl (daily), cumulative (= adjusted initial + running sum),
 *          and adjusted_initial_deposit for the reference line.
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
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed',
    ]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input) || empty(trim($input['uni_id'] ?? ''))) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'uni_id is required (e.g. from localStorage)',
    ]);
    exit;
}

$uni_id = trim($input['uni_id']);
$requested_account_id = isset($input['account_id']) ? (int) $input['account_id'] : null;

require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/../combined_master.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed',
    ]);
    exit;
}

// Combined Master (temporary, prod-only via .env): when the dashboard requests the
// current master account, splice the legacy account's pre-cutoff daily PnL with the
// current account's post-cutoff daily PnL into one continuous series (USD, no FX).
// Runs before the normal per-user logic and fully handles the response.
$combined = ($requested_account_id !== null) ? combined_master_for_account($requested_account_id) : null;
if ($combined) {
    // Baseline = legacy account's initial deposit (start of the combined timeline).
    $baseline = 0.0;
    if ($depStmt = mysqli_prepare($conn, 'SELECT COALESCE(initial_deposit, deposit_amount, 0) FROM accounts WHERE id = ?')) {
        mysqli_stmt_bind_param($depStmt, 'i', $combined['legacy_id']);
        if (mysqli_stmt_execute($depStmt)) {
            mysqli_stmt_bind_result($depStmt, $baseline);
            mysqli_stmt_fetch($depStmt);
        }
        mysqli_stmt_close($depStmt);
    }
    $baseline = (float) $baseline;

    $dailySql = "SELECT COALESCE(DATE(date_closed), DATE(created_at)) AS trade_date, COALESCE(SUM(pnl), 0) AS pnl
                 FROM trades
                 WHERE ((account_id = ? AND COALESCE(DATE(date_closed), DATE(created_at)) < ?)
                     OR (account_id = ? AND COALESCE(DATE(date_closed), DATE(created_at)) >= ?))
                 GROUP BY COALESCE(DATE(date_closed), DATE(created_at))
                 ORDER BY trade_date ASC";
    if (!($stmt = mysqli_prepare($conn, $dailySql))) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to prepare combined daily PnL query: ' . mysqli_error($conn)]);
        mysqli_close($conn);
        exit;
    }
    mysqli_stmt_bind_param($stmt, 'isis', $combined['legacy_id'], $combined['cutoff'], $combined['current_id'], $combined['cutoff']);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Combined daily PnL query failed: ' . mysqli_error($conn)]);
        mysqli_close($conn);
        exit;
    }
    $result = mysqli_stmt_get_result($stmt);
    $daily_series = [];
    $cumulative = $baseline;
    while ($row = mysqli_fetch_assoc($result)) {
        if (($row['trade_date'] ?? null) === null) {
            continue;
        }
        $daily_pnl = (float) ($row['pnl'] ?? 0);
        $cumulative += $daily_pnl;
        $daily_series[] = [
            'date' => $row['trade_date'],
            'pnl' => $daily_pnl,
            'cumulative' => $cumulative,
        ];
    }
    mysqli_stmt_close($stmt);
    mysqli_close($conn);

    echo json_encode([
        'success' => true,
        'adjusted_initial_deposit' => $baseline,
        'daily_series' => $daily_series,
        'account_id' => $combined['current_id'],
        'currency' => $combined['currency'],
    ]);
    exit;
}

// Get all accounts for this user
$accountSql = 'SELECT id, COALESCE(initial_deposit, deposit_amount, 0) AS initial_deposit
               FROM accounts
               WHERE uni_id = ? AND (deleted_at IS NULL)
               ORDER BY id ASC';
if (!($stmt = mysqli_prepare($conn, $accountSql))) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to prepare query: ' . mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}
mysqli_stmt_bind_param($stmt, 's', $uni_id);
if (!mysqli_stmt_execute($stmt)) {
    mysqli_stmt_close($stmt);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database query failed: ' . mysqli_error($conn)]);
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
    echo json_encode([
        'success' => true,
        'message' => 'No broker accounts found for this user.',
        'adjusted_initial_deposit' => 0.0,
        'daily_series' => [],
        'account_id' => null,
    ]);
    mysqli_close($conn);
    exit;
}

// Filter to one account if requested
$account_ids = [];
$adjusted_initial_deposit = 0.0;

if ($requested_account_id !== null) {
    $found = false;
    foreach ($accounts as $a) {
        if ($a['id'] === $requested_account_id) {
            $account_ids = [$a['id']];
            $found = true;
            break;
        }
    }
    if (!$found) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'account_id does not belong to this user',
        ]);
        mysqli_close($conn);
        exit;
    }
} else {
    $account_ids = array_column($accounts, 'id');
}

// Per-account adjusted initial = initial_deposit + sum(capital_transactions.amount)
foreach ($accounts as $a) {
    if (!in_array($a['id'], $account_ids, true)) {
        continue;
    }
    $capSql = 'SELECT COALESCE(SUM(amount), 0) AS net_flow FROM capital_transactions WHERE account_id = ?';
    if ($stmt = mysqli_prepare($conn, $capSql)) {
        mysqli_stmt_bind_param($stmt, 'i', $a['id']);
        $net = 0.0;
        if (mysqli_stmt_execute($stmt)) {
            mysqli_stmt_bind_result($stmt, $net);
            mysqli_stmt_fetch($stmt);
        }
        mysqli_stmt_close($stmt);
        $adjusted_initial_deposit += $a['initial_deposit'] + (float) $net;
    }
}

// Daily PnL: one row per date, sum across selected accounts (match users/account_stats date attribution)
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
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to prepare daily PnL query: ' . mysqli_error($conn)]);
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
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database query failed: ' . mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

$result = mysqli_stmt_get_result($stmt);
$daily_series = [];
$cumulative = $adjusted_initial_deposit;

while ($row = mysqli_fetch_assoc($result)) {
    $trade_date = $row['trade_date'] ?? null;
    if ($trade_date === null) {
        continue;
    }
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

echo json_encode([
    'success' => true,
    'adjusted_initial_deposit' => $adjusted_initial_deposit,
    'daily_series' => $daily_series,
    'account_id' => count($account_ids) === 1 ? $account_ids[0] : null,
]);
