<?php
/**
 * Admin: Capital summary for a strategy across all brokers (Capital.com + Binance + IG.com).
 * GET /admin/GET/get_strategy_capital_summary.php?strategy=<name>
 *
 * Sums balance and (initial_deposit + net deposit/withdraw flow) for every account that
 * has ever closed a trade tagged with the given strategy. Currency is summed naively
 * (USD-equivalent assumption).
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

$strategy = isset($_GET['strategy']) ? trim($_GET['strategy']) : '';
if ($strategy === '') {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'strategy is required']);
    exit;
}

require_once __DIR__ . '/../../conn.php';
if (!$conn) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

function fail_out($conn, $msg) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $msg]);
    mysqli_close($conn);
    exit;
}

function fetch_distinct_ids($conn, $sql, $strategy) {
    $ids = [];
    if (!($stmt = mysqli_prepare($conn, $sql))) return $ids;
    mysqli_stmt_bind_param($stmt, 's', $strategy);
    if (mysqli_stmt_execute($stmt)) {
        $r = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($r)) {
            $val = reset($row);
            if ($val !== null && $val !== '') $ids[] = $val;
        }
    }
    mysqli_stmt_close($stmt);
    return $ids;
}

// ── Capital.com ───────────────────────────────────────────────────────────────
$capital = ['accounts' => 0, 'balance' => 0.0, 'initial_deposit' => 0.0, 'net_flow' => 0.0];
$capIds = fetch_distinct_ids(
    $conn,
    'SELECT DISTINCT account_id FROM trades WHERE strategy = ? AND account_id IS NOT NULL',
    $strategy
);
if (!empty($capIds)) {
    $intIds = array_map('intval', $capIds);
    $ph = implode(',', array_fill(0, count($intIds), '?'));
    $types = str_repeat('i', count($intIds));
    $sql = "SELECT id, COALESCE(balance, 0) AS balance,
                   COALESCE(initial_deposit, deposit_amount, 0) AS initial_deposit
            FROM accounts WHERE id IN ($ph) AND deleted_at IS NULL";
    if (!($stmt = mysqli_prepare($conn, $sql))) fail_out($conn, 'capital accounts query failed');
    $refs = []; foreach ($intIds as $k => $v) { $refs[$k] = &$intIds[$k]; }
    call_user_func_array([$stmt, 'bind_param'], array_merge([$types], $refs));
    mysqli_stmt_execute($stmt);
    $r = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($r)) {
        $capital['accounts']++;
        $capital['balance'] += (float) $row['balance'];
        $capital['initial_deposit'] += (float) $row['initial_deposit'];

        $aid = (int) $row['id'];
        if ($txStmt = mysqli_prepare($conn, 'SELECT COALESCE(SUM(amount), 0) AS net_flow FROM capital_transactions WHERE account_id = ?')) {
            mysqli_stmt_bind_param($txStmt, 'i', $aid);
            if (mysqli_stmt_execute($txStmt)) {
                $tr = mysqli_stmt_get_result($txStmt);
                $tx = mysqli_fetch_assoc($tr);
                $capital['net_flow'] += (float) ($tx['net_flow'] ?? 0);
            }
            mysqli_stmt_close($txStmt);
        }
    }
    mysqli_stmt_close($stmt);
}

// ── Binance ───────────────────────────────────────────────────────────────────
$binance = ['accounts' => 0, 'balance' => 0.0, 'initial_deposit' => 0.0, 'net_flow' => 0.0];
$binKeys = fetch_distinct_ids(
    $conn,
    'SELECT DISTINCT api_key FROM binance_pastpositions WHERE strategy = ? AND api_key IS NOT NULL',
    $strategy
);
if (!empty($binKeys)) {
    $ph = implode(',', array_fill(0, count($binKeys), '?'));
    $types = str_repeat('s', count($binKeys));
    $sql = "SELECT api_key, COALESCE(balance, 0) AS balance,
                   COALESCE(initial_deposit, 0) AS initial_deposit
            FROM binance_accounts WHERE api_key IN ($ph)";
    if (!($stmt = mysqli_prepare($conn, $sql))) fail_out($conn, 'binance accounts query failed');
    $refs = []; foreach ($binKeys as $k => $v) { $refs[$k] = &$binKeys[$k]; }
    call_user_func_array([$stmt, 'bind_param'], array_merge([$types], $refs));
    mysqli_stmt_execute($stmt);
    $r = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($r)) {
        $binance['accounts']++;
        $binance['balance'] += (float) $row['balance'];
        $binance['initial_deposit'] += (float) $row['initial_deposit'];

        $apiKey = $row['api_key'];
        $txSql = "SELECT COALESCE(SUM(CASE WHEN UPPER(type) = 'DEPOSIT' THEN amount
                                           WHEN UPPER(type) = 'WITHDRAWAL' THEN -amount
                                           ELSE 0 END), 0) AS net_flow
                  FROM binance_transactions WHERE api_key = ?";
        if ($txStmt = mysqli_prepare($conn, $txSql)) {
            mysqli_stmt_bind_param($txStmt, 's', $apiKey);
            if (mysqli_stmt_execute($txStmt)) {
                $tr = mysqli_stmt_get_result($txStmt);
                $tx = mysqli_fetch_assoc($tr);
                $binance['net_flow'] += (float) ($tx['net_flow'] ?? 0);
            }
            mysqli_stmt_close($txStmt);
        }
    }
    mysqli_stmt_close($stmt);
}

// ── IG.com ────────────────────────────────────────────────────────────────────
$ig = ['accounts' => 0, 'balance' => 0.0, 'initial_deposit' => 0.0, 'net_flow' => 0.0];
$igIds = fetch_distinct_ids(
    $conn,
    'SELECT DISTINCT account_id FROM ig_past_positions WHERE strategy = ? AND account_id IS NOT NULL',
    $strategy
);
if (!empty($igIds)) {
    $intIds = array_map('intval', $igIds);
    $ph = implode(',', array_fill(0, count($intIds), '?'));
    $types = str_repeat('i', count($intIds));
    $sql = "SELECT id, COALESCE(balance, 0) AS balance,
                   COALESCE(initial_deposit, 0) AS initial_deposit
            FROM ig_accounts WHERE id IN ($ph) AND deleted_at IS NULL";
    if (!($stmt = mysqli_prepare($conn, $sql))) fail_out($conn, 'ig accounts query failed');
    $refs = []; foreach ($intIds as $k => $v) { $refs[$k] = &$intIds[$k]; }
    call_user_func_array([$stmt, 'bind_param'], array_merge([$types], $refs));
    mysqli_stmt_execute($stmt);
    $r = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($r)) {
        $ig['accounts']++;
        $ig['balance'] += (float) $row['balance'];
        $ig['initial_deposit'] += (float) $row['initial_deposit'];

        $aid = (int) $row['id'];
        $txSql = "SELECT COALESCE(SUM(amount), 0) AS net_flow
                  FROM ig_transactions
                  WHERE account_id = ? AND UPPER(type) IN ('DEPOSIT','WITHDRAWAL')";
        if ($txStmt = mysqli_prepare($conn, $txSql)) {
            mysqli_stmt_bind_param($txStmt, 'i', $aid);
            if (mysqli_stmt_execute($txStmt)) {
                $tr = mysqli_stmt_get_result($txStmt);
                $tx = mysqli_fetch_assoc($tr);
                $ig['net_flow'] += (float) ($tx['net_flow'] ?? 0);
            }
            mysqli_stmt_close($txStmt);
        }
    }
    mysqli_stmt_close($stmt);
}

mysqli_close($conn);

$total_balance = $capital['balance'] + $binance['balance'] + $ig['balance'];
$total_initial_deposit = $capital['initial_deposit'] + $binance['initial_deposit'] + $ig['initial_deposit'];
$total_net_flow = $capital['net_flow'] + $binance['net_flow'] + $ig['net_flow'];
$adjusted = $total_initial_deposit + $total_net_flow;
$pct_change = $adjusted != 0 ? (($total_balance - $adjusted) / $adjusted) * 100 : 0.0;
$account_count = $capital['accounts'] + $binance['accounts'] + $ig['accounts'];

$broker_block = function ($b) {
    return [
        'accounts' => $b['accounts'],
        'balance' => round($b['balance'], 8),
        'initial_deposit' => round($b['initial_deposit'], 8),
        'net_flow' => round($b['net_flow'], 8),
        'adjusted_initial_deposit' => round($b['initial_deposit'] + $b['net_flow'], 8),
    ];
};

ob_end_clean();
echo json_encode([
    'success' => true,
    'strategy' => $strategy,
    'account_count' => $account_count,
    'total_balance' => round($total_balance, 8),
    'total_initial_deposit' => round($total_initial_deposit, 8),
    'total_net_flow' => round($total_net_flow, 8),
    'adjusted_initial_deposit' => round($adjusted, 8),
    'pct_change' => round($pct_change, 4),
    'by_broker' => [
        'Capital.com' => $broker_block($capital),
        'Binance' => $broker_block($binance),
        'IG.com' => $broker_block($ig),
    ],
]);
