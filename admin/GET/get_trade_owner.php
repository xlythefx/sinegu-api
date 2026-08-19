<?php
/**
 * Admin: Owner of a trade — the user plus every broker account they hold.
 * GET /admin/GET/get_trade_owner.php?uni_id=<uni_id>[&strategy=<name>]
 *
 * Powers the "who owns this trade" modal on the admin strategy pages. Unlike
 * get_user_details.php (Capital.com only) this walks all three brokers, so the modal can
 * show the full capital picture behind a single trade. Pass `strategy` to also get each
 * account's trade count / realized P&L for just that strategy.
 *
 * Broker secrets (api_key, secret_key, passwords, session tokens) are never returned.
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
$strategy = isset($_GET['strategy']) ? trim($_GET['strategy']) : '';

if ($uni_id === '') {
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

/**
 * Runs a prepared SELECT and returns all rows (empty array on any failure).
 * The try/catch matters: mysqli throws by default since PHP 8.1, and the broker tables
 * drift between boxes (a column added by a migration one box has not run yet). A missing
 * column should blank out one field, not 500 the whole modal.
 */
function q_all($conn, $sql, $types = '', array $params = []) {
    $rows = [];
    try {
        if (!($stmt = mysqli_prepare($conn, $sql))) return $rows;
        if ($types !== '') {
            $refs = [];
            foreach ($params as $k => $v) { $refs[$k] = &$params[$k]; }
            call_user_func_array([$stmt, 'bind_param'], array_merge([$types], $refs));
        }
        if (mysqli_stmt_execute($stmt)) {
            $res = mysqli_stmt_get_result($stmt);
            while ($row = mysqli_fetch_assoc($res)) $rows[] = $row;
        }
        mysqli_stmt_close($stmt);
    } catch (Throwable $e) {
        return [];
    }
    return $rows;
}

/** Single scalar from a prepared SELECT, or $default. */
function q_scalar($conn, $sql, $types, array $params, $default = 0.0) {
    $rows = q_all($conn, $sql, $types, $params);
    if (empty($rows)) return $default;
    $val = reset($rows[0]);
    return $val === null ? $default : $val;
}

function num($v) { return $v === null ? null : (float) $v; }

/** Reads an optional column off a `SELECT *` row — tables differ per box, see q_all(). */
function col($row, $key, $default = null) {
    return array_key_exists($key, $row) && $row[$key] !== null ? $row[$key] : $default;
}

/** Live accounts first, then oldest-created — done in PHP so no column is assumed. */
function sort_accounts(array $rows) {
    usort($rows, function ($a, $b) {
        $ad = col($a, 'deleted_at') === null ? 0 : 1;
        $bd = col($b, 'deleted_at') === null ? 0 : 1;
        if ($ad !== $bd) return $ad - $bd;
        return strcmp((string) col($a, 'created_at', ''), (string) col($b, 'created_at', ''));
    });
    return $rows;
}

// ── User ──────────────────────────────────────────────────────────────────────
$userRows = q_all(
    $conn,
    'SELECT uni_id, name, email, status, created_at, realized_percentage, unrealized_percentage
     FROM user_credentials WHERE uni_id = ? LIMIT 1',
    's',
    [$uni_id]
);

if (empty($userRows)) {
    ob_end_clean();
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'User not found']);
    mysqli_close($conn);
    exit;
}

$u = $userRows[0];
$user = [
    'uni_id' => $u['uni_id'],
    'name' => $u['name'],
    'email' => $u['email'],
    'status' => $u['status'],
    'created_at' => $u['created_at'],
    'realized_percentage' => num($u['realized_percentage']),
    'unrealized_percentage' => num($u['unrealized_percentage']),
];

$accounts = [];

// ── Capital.com ───────────────────────────────────────────────────────────────
foreach (sort_accounts(q_all(
    $conn,
    'SELECT * FROM accounts WHERE uni_id = ?',
    's',
    [$uni_id]
)) as $row) {
    $id = (int) $row['id'];
    $entry = [
        'broker' => 'Capital.com',
        'id' => $id,
        'name' => col($row, 'name'),
        'email' => col($row, 'email'),
        'balance' => num(col($row, 'balance')),
        'initial_deposit' => num(col($row, 'initial_deposit', col($row, 'deposit_amount'))),
        'unrealized_pnl' => num(col($row, 'unrealized_pnl')),
        'equity' => num(col($row, 'equity')),
        'currency_type' => col($row, 'currency_type', 'USD'),
        'demo' => (int) col($row, 'demo', 0),
        'enabled' => (int) col($row, 'enabled', 1),
        'created_at' => col($row, 'created_at'),
        'deleted_at' => col($row, 'deleted_at'),
        'realized_pnl' => (float) q_scalar($conn, 'SELECT COALESCE(SUM(pnl), 0) FROM trades WHERE account_id = ?', 'i', [$id]),
        'trade_count' => (int) q_scalar($conn, 'SELECT COUNT(*) FROM trades WHERE account_id = ?', 'i', [$id], 0),
        'strategy_pnl' => null,
        'strategy_trades' => null,
    ];
    if ($strategy !== '') {
        $entry['strategy_pnl'] = (float) q_scalar($conn, 'SELECT COALESCE(SUM(pnl), 0) FROM trades WHERE account_id = ? AND strategy = ?', 'is', [$id, $strategy]);
        $entry['strategy_trades'] = (int) q_scalar($conn, 'SELECT COUNT(*) FROM trades WHERE account_id = ? AND strategy = ?', 'is', [$id, $strategy], 0);
    }
    $accounts[] = $entry;
}

// ── Binance ───────────────────────────────────────────────────────────────────
// Keyed by api_key, which is a secret — it is used for the joins below but never emitted.
foreach (sort_accounts(q_all(
    $conn,
    'SELECT * FROM binance_accounts WHERE uni_id = ?',
    's',
    [$uni_id]
)) as $row) {
    $key = $row['api_key'];
    $entry = [
        'broker' => 'Binance',
        'id' => null,
        'name' => col($row, 'name') ?: 'Binance',
        'email' => $user['email'],
        'balance' => num(col($row, 'balance')),
        'initial_deposit' => num(col($row, 'initial_deposit')),
        'unrealized_pnl' => num(col($row, 'unrealized_pnl')),
        'equity' => null,
        'currency_type' => col($row, 'currency_type', 'USDT'),
        'demo' => (int) col($row, 'demo_enabled', 0),
        'enabled' => 1,
        'created_at' => col($row, 'created_at'),
        'deleted_at' => col($row, 'deleted_at'),
        'realized_pnl' => (float) q_scalar($conn, 'SELECT COALESCE(SUM(realized_pnl), 0) FROM binance_pastpositions WHERE api_key = ?', 's', [$key]),
        'trade_count' => (int) q_scalar($conn, 'SELECT COUNT(*) FROM binance_pastpositions WHERE api_key = ?', 's', [$key], 0),
        'strategy_pnl' => null,
        'strategy_trades' => null,
    ];
    if ($strategy !== '') {
        $entry['strategy_pnl'] = (float) q_scalar($conn, 'SELECT COALESCE(SUM(realized_pnl), 0) FROM binance_pastpositions WHERE api_key = ? AND strategy = ?', 'ss', [$key, $strategy]);
        $entry['strategy_trades'] = (int) q_scalar($conn, 'SELECT COUNT(*) FROM binance_pastpositions WHERE api_key = ? AND strategy = ?', 'ss', [$key, $strategy], 0);
    }
    $accounts[] = $entry;
}

// ── IG.com ────────────────────────────────────────────────────────────────────
foreach (sort_accounts(q_all(
    $conn,
    'SELECT * FROM ig_accounts WHERE uni_id = ?',
    's',
    [$uni_id]
)) as $row) {
    $id = (int) $row['id'];
    $entry = [
        'broker' => 'IG.com',
        'id' => $id,
        'name' => col($row, 'name') ?: 'IG.com',
        'email' => col($row, 'email'),
        'balance' => num(col($row, 'balance')),
        'initial_deposit' => num(col($row, 'initial_deposit')),
        'unrealized_pnl' => num(col($row, 'unrealized_pnl')),
        'equity' => null,
        'currency_type' => col($row, 'currency_type', 'USD'),
        'demo' => (int) col($row, 'demo', 0),
        'enabled' => (int) col($row, 'enabled', 1),
        'created_at' => col($row, 'created_at'),
        'deleted_at' => col($row, 'deleted_at'),
        'realized_pnl' => (float) q_scalar($conn, 'SELECT COALESCE(SUM(pnl), 0) FROM ig_past_positions WHERE account_id = ?', 'i', [$id]),
        'trade_count' => (int) q_scalar($conn, 'SELECT COUNT(*) FROM ig_past_positions WHERE account_id = ?', 'i', [$id], 0),
        'strategy_pnl' => null,
        'strategy_trades' => null,
    ];
    if ($strategy !== '') {
        $entry['strategy_pnl'] = (float) q_scalar($conn, 'SELECT COALESCE(SUM(pnl), 0) FROM ig_past_positions WHERE account_id = ? AND strategy = ?', 'is', [$id, $strategy]);
        $entry['strategy_trades'] = (int) q_scalar($conn, 'SELECT COUNT(*) FROM ig_past_positions WHERE account_id = ? AND strategy = ?', 'is', [$id, $strategy], 0);
    }
    $accounts[] = $entry;
}

// ── Totals (live accounts only; currencies summed naively, as elsewhere) ───────
$totals = [
    'accounts' => 0,
    'balance' => 0.0,
    'initial_deposit' => 0.0,
    'unrealized_pnl' => 0.0,
    'realized_pnl' => 0.0,
    'strategy_pnl' => $strategy !== '' ? 0.0 : null,
];
foreach ($accounts as $a) {
    if ($a['deleted_at'] !== null) continue;
    $totals['accounts']++;
    $totals['balance'] += (float) ($a['balance'] ?? 0);
    $totals['initial_deposit'] += (float) ($a['initial_deposit'] ?? 0);
    $totals['unrealized_pnl'] += (float) ($a['unrealized_pnl'] ?? 0);
    $totals['realized_pnl'] += (float) ($a['realized_pnl'] ?? 0);
    if ($strategy !== '') $totals['strategy_pnl'] += (float) ($a['strategy_pnl'] ?? 0);
}
foreach (['balance', 'initial_deposit', 'unrealized_pnl', 'realized_pnl'] as $k) {
    $totals[$k] = round($totals[$k], 8);
}
if ($strategy !== '') $totals['strategy_pnl'] = round($totals['strategy_pnl'], 8);

mysqli_close($conn);

ob_end_clean();
echo json_encode([
    'success' => true,
    'strategy' => $strategy !== '' ? $strategy : null,
    'user' => $user,
    'accounts' => $accounts,
    'totals' => $totals,
]);
