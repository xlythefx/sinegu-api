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

// Optional ticker filter (mirrors the strategy page's include/exclude chips).
// Only accounts with >=1 surviving strategy trade after the filter are counted,
// so excluding every ticker an account traded drops that whole account's capital.
$tickersRaw = isset($_GET['tickers']) ? trim($_GET['tickers']) : '';
$mode = (isset($_GET['mode']) && strtolower(trim($_GET['mode'])) === 'exclude') ? 'exclude' : 'include';
$tickers = array_values(array_filter(array_map('trim', explode(',', $tickersRaw)), function ($t) {
    return $t !== '';
}));

/**
 * Build an "AND <column> IN/NOT IN (...)" fragment for the ticker filter on a
 * specific column (trades use `ticker`, binance_pastpositions uses `symbol`).
 * Returns [sqlFragment, params[], types].
 */
function ticker_clause($column, array $tickers, $mode) {
    if (empty($tickers)) {
        return ['', [], ''];
    }
    $ph = implode(',', array_fill(0, count($tickers), '?'));
    $op = $mode === 'exclude' ? 'NOT IN' : 'IN';
    return [" AND $column $op ($ph)", $tickers, str_repeat('s', count($tickers))];
}

require_once __DIR__ . '/../../conn.php';
require_once __DIR__ . '/../../includes/bybit_schema.php';
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

function fetch_distinct_ids($conn, $sql, $strategy, array $extraParams = [], $extraTypes = '') {
    $ids = [];
    if (!($stmt = mysqli_prepare($conn, $sql))) return $ids;
    $types = 's' . $extraTypes;
    $params = array_merge([$strategy], $extraParams);
    $refs = [];
    foreach ($params as $k => $v) { $refs[$k] = &$params[$k]; }
    call_user_func_array([$stmt, 'bind_param'], array_merge([$types], $refs));
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
list($capTickClause, $capTickParams, $capTickTypes) = ticker_clause('ticker', $tickers, $mode);
$capIds = fetch_distinct_ids(
    $conn,
    'SELECT DISTINCT account_id FROM trades WHERE strategy = ? AND account_id IS NOT NULL' . $capTickClause,
    $strategy,
    $capTickParams,
    $capTickTypes
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
// Binance stores the instrument in `symbol` (the strategy page's Binance ticker chips map to it).
list($binTickClause, $binTickParams, $binTickTypes) = ticker_clause('symbol', $tickers, $mode);
$binKeys = fetch_distinct_ids(
    $conn,
    'SELECT DISTINCT api_key FROM binance_pastpositions WHERE strategy = ? AND api_key IS NOT NULL' . $binTickClause,
    $strategy,
    $binTickParams,
    $binTickTypes
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

// ── MEXC ───────────────────────────────────────────────────────────────────
$mexc = ['accounts' => 0, 'balance' => 0.0, 'initial_deposit' => 0.0, 'net_flow' => 0.0];
// Same shape as the Binance block: MEXC stores the instrument in `symbol` too.
list($mexcTickClause, $mexcTickParams, $mexcTickTypes) = ticker_clause('symbol', $tickers, $mode);
$mexcKeys = fetch_distinct_ids(
    $conn,
    'SELECT DISTINCT api_key FROM mexc_pastpositions WHERE strategy = ? AND api_key IS NOT NULL' . $mexcTickClause,
    $strategy,
    $mexcTickParams,
    $mexcTickTypes
);
if (!empty($mexcKeys)) {
    $ph = implode(',', array_fill(0, count($mexcKeys), '?'));
    $types = str_repeat('s', count($mexcKeys));
    $sql = "SELECT api_key, COALESCE(balance, 0) AS balance,
                   COALESCE(initial_deposit, 0) AS initial_deposit
            FROM mexc_accounts WHERE api_key IN ($ph)";
    if (!($stmt = mysqli_prepare($conn, $sql))) fail_out($conn, 'mexc accounts query failed');
    $refs = []; foreach ($mexcKeys as $k => $v) { $refs[$k] = &$mexcKeys[$k]; }
    call_user_func_array([$stmt, 'bind_param'], array_merge([$types], $refs));
    mysqli_stmt_execute($stmt);
    $r = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($r)) {
        $mexc['accounts']++;
        $mexc['balance'] += (float) $row['balance'];
        $mexc['initial_deposit'] += (float) $row['initial_deposit'];

        $apiKey = $row['api_key'];
        $txSql = "SELECT COALESCE(SUM(CASE WHEN UPPER(type) = 'DEPOSIT' THEN amount
                                           WHEN UPPER(type) = 'WITHDRAWAL' THEN -amount
                                           ELSE 0 END), 0) AS net_flow
                  FROM mexc_transactions WHERE api_key = ?";
        if ($txStmt = mysqli_prepare($conn, $txSql)) {
            mysqli_stmt_bind_param($txStmt, 's', $apiKey);
            if (mysqli_stmt_execute($txStmt)) {
                $tr = mysqli_stmt_get_result($txStmt);
                $tx = mysqli_fetch_assoc($tr);
                $mexc['net_flow'] += (float) ($tx['net_flow'] ?? 0);
            }
            mysqli_stmt_close($txStmt);
        }
    }
    mysqli_stmt_close($stmt);
}

// ── Bybit ── skipped until migrations 098-101 create its tables (includes/bybit_schema.php)
$bybit = ['accounts' => 0, 'balance' => 0.0, 'initial_deposit' => 0.0, 'net_flow' => 0.0];
if (bybit_tables_ready($conn)) {
    // Same shape as the Binance block: Bybit stores the instrument in `symbol` too.
    list($bybitTickClause, $bybitTickParams, $bybitTickTypes) = ticker_clause('symbol', $tickers, $mode);
    $bybitKeys = fetch_distinct_ids(
        $conn,
        'SELECT DISTINCT api_key FROM bybit_pastpositions WHERE strategy = ? AND api_key IS NOT NULL' . $bybitTickClause,
        $strategy,
        $bybitTickParams,
        $bybitTickTypes
    );
    if (!empty($bybitKeys)) {
        $ph = implode(',', array_fill(0, count($bybitKeys), '?'));
        $types = str_repeat('s', count($bybitKeys));
        $sql = "SELECT api_key, COALESCE(balance, 0) AS balance,
                       COALESCE(initial_deposit, 0) AS initial_deposit
                FROM bybit_accounts WHERE api_key IN ($ph)";
        if (!($stmt = mysqli_prepare($conn, $sql))) fail_out($conn, 'bybit accounts query failed');
        $refs = []; foreach ($bybitKeys as $k => $v) { $refs[$k] = &$bybitKeys[$k]; }
        call_user_func_array([$stmt, 'bind_param'], array_merge([$types], $refs));
        mysqli_stmt_execute($stmt);
        $r = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($r)) {
            $bybit['accounts']++;
            $bybit['balance'] += (float) $row['balance'];
            $bybit['initial_deposit'] += (float) $row['initial_deposit'];

            $apiKey = $row['api_key'];
            $txSql = "SELECT COALESCE(SUM(CASE WHEN UPPER(type) = 'DEPOSIT' THEN amount
                                               WHEN UPPER(type) = 'WITHDRAWAL' THEN -amount
                                               ELSE 0 END), 0) AS net_flow
                      FROM bybit_transactions WHERE api_key = ?";
            if ($txStmt = mysqli_prepare($conn, $txSql)) {
                mysqli_stmt_bind_param($txStmt, 's', $apiKey);
                if (mysqli_stmt_execute($txStmt)) {
                    $tr = mysqli_stmt_get_result($txStmt);
                    $tx = mysqli_fetch_assoc($tr);
                    $bybit['net_flow'] += (float) ($tx['net_flow'] ?? 0);
                }
                mysqli_stmt_close($txStmt);
            }
        }
        mysqli_stmt_close($stmt);
    }
}

// ── IG.com ────────────────────────────────────────────────────────────────────
$ig = ['accounts' => 0, 'balance' => 0.0, 'initial_deposit' => 0.0, 'net_flow' => 0.0];
list($igTickClause, $igTickParams, $igTickTypes) = ticker_clause('ticker', $tickers, $mode);
$igIds = fetch_distinct_ids(
    $conn,
    'SELECT DISTINCT account_id FROM ig_past_positions WHERE strategy = ? AND account_id IS NOT NULL' . $igTickClause,
    $strategy,
    $igTickParams,
    $igTickTypes
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

$total_balance = $capital['balance'] + $binance['balance'] + $ig['balance'] + $mexc['balance'] + $bybit['balance'];
$total_initial_deposit = $capital['initial_deposit'] + $binance['initial_deposit'] + $ig['initial_deposit'] + $mexc['initial_deposit'] + $bybit['initial_deposit'];
$total_net_flow = $capital['net_flow'] + $binance['net_flow'] + $ig['net_flow'] + $mexc['net_flow'] + $bybit['net_flow'];
$adjusted = $total_initial_deposit + $total_net_flow;
$pct_change = $adjusted != 0 ? (($total_balance - $adjusted) / $adjusted) * 100 : 0.0;
$account_count = $capital['accounts'] + $binance['accounts'] + $ig['accounts'] + $mexc['accounts'] + $bybit['accounts'];

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
        'MEXC' => $broker_block($mexc),
        'Bybit' => $broker_block($bybit),
    ],
]);
