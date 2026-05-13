<?php
// /sinegu-api/v1/user-analytics/capital[/<endpoint>]
// Endpoints: summary | daily | weekly | monthly | open_trades | closed_trades

require_once __DIR__ . '/../_shared.php';

ua_send_cors_headers();
ua_handle_options();
ua_require_method();

$endpoint = isset($_GET['endpoint']) ? strtolower(trim((string)$_GET['endpoint'])) : 'summary';
$valid = ['summary', 'daily', 'weekly', 'monthly', 'open_trades', 'closed_trades'];
if (!in_array($endpoint, $valid, true)) {
    ua_json_err(404, 'Unknown endpoint. Valid: ' . implode(', ', $valid));
}

$api_key = ua_require_api_key();
$range   = ua_parse_date_range();

require_once __DIR__ . '/../../../conn.php';
if (!$conn) ua_json_err(500, 'Database connection failed');

// Resolve capital account(s) by api_key
$accounts = [];
$accountSql = 'SELECT id, COALESCE(initial_deposit, deposit_amount, 0) AS initial_deposit,
                      COALESCE(currency_type, "USD") AS currency_type,
                      COALESCE(balance, 0) AS balance,
                      COALESCE(unrealized_pnl, 0) AS account_unrealized
               FROM accounts
               WHERE api_key = ? AND deleted_at IS NULL';
if ($stmt = mysqli_prepare($conn, $accountSql)) {
    mysqli_stmt_bind_param($stmt, 's', $api_key);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($r = mysqli_fetch_assoc($res)) $accounts[] = $r;
    mysqli_stmt_close($stmt);
} else {
    ua_json_err(500, 'Failed to prepare account lookup');
}
if (empty($accounts)) ua_json_err(403, 'API key not recognized');

$account_ids = array_map(fn($a) => (int)$a['id'], $accounts);
$placeholders = implode(',', array_fill(0, count($account_ids), '?'));
$idTypes = str_repeat('i', count($account_ids));

// Helper: bind dynamic params
$bind = function ($stmt, string $types, array $vals) {
    $refs = [];
    foreach ($vals as $k => $v) $refs[$k] = &$vals[$k];
    array_unshift($refs, $types);
    return call_user_func_array([$stmt, 'bind_param'], $refs);
};

// ----- open_trades -----
if ($endpoint === 'open_trades') {
    $sql = "SELECT id, account_id, ticker, side, deal_id, increments, price, unrealized_pnl, strategy, updated_at
            FROM positions WHERE account_id IN ($placeholders) ORDER BY updated_at DESC";
    $stmt = mysqli_prepare($conn, $sql);
    $bind($stmt, $idTypes, $account_ids);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $positions = [];
    $unreal = 0.0;
    while ($r = mysqli_fetch_assoc($res)) {
        $r['account_id'] = (int)$r['account_id'];
        $r['price'] = isset($r['price']) ? (float)$r['price'] : null;
        $r['unrealized_pnl'] = isset($r['unrealized_pnl']) ? (float)$r['unrealized_pnl'] : 0.0;
        $unreal += $r['unrealized_pnl'];
        $positions[] = $r;
    }
    mysqli_stmt_close($stmt);
    ua_json_ok(ua_response_envelope('capital', 'open_trades', $range, [
        'positions' => $positions,
        'open_count' => count($positions),
        'unrealized_pnl' => $unreal,
    ], ['account_ids' => $account_ids, 'currency' => $accounts[0]['currency_type']]));
}

// ----- closed_trades -----
if ($endpoint === 'closed_trades') {
    $where = "account_id IN ($placeholders)";
    $params = $account_ids;
    $types  = $idTypes;
    if ($range['has_range']) {
        $where .= " AND COALESCE(date_closed, created_at) BETWEEN ? AND ?";
        $params[] = $range['from']; $params[] = $range['to']; $types .= 'ss';
    }
    $sql = "SELECT id, account_id, ticker, side, pnl, pnl_percentage, closed_positions, price,
                   date_closed, time_closed, strategy, created_at
            FROM trades
            WHERE $where
            ORDER BY COALESCE(date_closed, created_at) DESC";
    $stmt = mysqli_prepare($conn, $sql);
    $bind($stmt, $types, $params);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $trades = [];
    $realized = 0.0;
    while ($r = mysqli_fetch_assoc($res)) {
        $r['account_id'] = (int)$r['account_id'];
        $r['pnl'] = isset($r['pnl']) ? (float)$r['pnl'] : 0.0;
        $realized += $r['pnl'];
        $trades[] = $r;
    }
    mysqli_stmt_close($stmt);
    ua_json_ok(ua_response_envelope('capital', 'closed_trades', $range, [
        'trades' => $trades,
        'closed_count' => count($trades),
        'realized_pnl' => $realized,
    ], ['account_ids' => $account_ids, 'currency' => $accounts[0]['currency_type']]));
}

// ----- daily / weekly / monthly bucketing helper -----
$bucketSelect = [
    'daily'   => "DATE(COALESCE(date_closed, created_at))",
    'weekly'  => "DATE_FORMAT(STR_TO_DATE(CONCAT(YEARWEEK(COALESCE(date_closed, created_at), 3), ' Monday'), '%X%V %W'), '%Y-%m-%d')",
    'monthly' => "DATE_FORMAT(COALESCE(date_closed, created_at), '%Y-%m')",
];

if (in_array($endpoint, ['daily', 'weekly', 'monthly'], true)) {
    $expr = $bucketSelect[$endpoint];
    $where = "account_id IN ($placeholders)";
    $params = $account_ids;
    $types  = $idTypes;
    if ($range['has_range']) {
        $where .= " AND COALESCE(date_closed, created_at) BETWEEN ? AND ?";
        $params[] = $range['from']; $params[] = $range['to']; $types .= 'ss';
    }
    $sql = "SELECT $expr AS bucket, COALESCE(SUM(pnl), 0) AS pnl, COUNT(*) AS trades
            FROM trades
            WHERE $where
            GROUP BY bucket ORDER BY bucket ASC";
    $stmt = mysqli_prepare($conn, $sql);
    $bind($stmt, $types, $params);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    mysqli_stmt_close($stmt);
    ua_json_ok(ua_response_envelope('capital', $endpoint, $range, [
        'series' => ua_cumulative_series($rows, 'bucket'),
    ], ['account_ids' => $account_ids, 'currency' => $accounts[0]['currency_type']]));
}

// ----- summary -----
$realized = 0.0; $closed_count = 0; $last_trade_at = null;
$where = "account_id IN ($placeholders)";
$params = $account_ids; $types = $idTypes;
if ($range['has_range']) {
    $where .= " AND COALESCE(date_closed, created_at) BETWEEN ? AND ?";
    $params[] = $range['from']; $params[] = $range['to']; $types .= 'ss';
}
$sql = "SELECT COALESCE(SUM(pnl), 0) AS pnl, COUNT(*) AS cnt, MAX(COALESCE(date_closed, created_at)) AS last_at
        FROM trades WHERE $where";
$stmt = mysqli_prepare($conn, $sql);
$bind($stmt, $types, $params);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
if ($r = mysqli_fetch_assoc($res)) {
    $realized = (float)$r['pnl'];
    $closed_count = (int)$r['cnt'];
    $last_trade_at = $r['last_at'];
}
mysqli_stmt_close($stmt);

$open_count = 0; $unreal = 0.0;
$sql = "SELECT COUNT(*) AS cnt, COALESCE(SUM(unrealized_pnl), 0) AS u FROM positions WHERE account_id IN ($placeholders)";
$stmt = mysqli_prepare($conn, $sql);
$bind($stmt, $idTypes, $account_ids);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
if ($r = mysqli_fetch_assoc($res)) {
    $open_count = (int)$r['cnt'];
    $unreal = (float)$r['u'];
}
mysqli_stmt_close($stmt);

// breakdown windows
$breakdown = [];
foreach (['daily' => '-1 day', 'weekly' => '-7 day', 'monthly' => '-30 day'] as $key => $mod) {
    $w = ua_period_window($mod);
    $sql = "SELECT COALESCE(SUM(pnl), 0) AS pnl, COUNT(*) AS cnt FROM trades
            WHERE account_id IN ($placeholders)
              AND COALESCE(date_closed, created_at) BETWEEN ? AND ?";
    $stmt = mysqli_prepare($conn, $sql);
    $bind($stmt, $idTypes . 'ss', array_merge($account_ids, [$w['from'], $w['to']]));
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res) ?: ['pnl' => 0, 'cnt' => 0];
    mysqli_stmt_close($stmt);
    $breakdown[$key] = [
        'pnl' => (float)$row['pnl'],
        'trades' => (int)$row['cnt'],
        'from' => $w['from_date'],
        'to' => $w['to_date'],
    ];
}

$initial_deposit_total = 0.0;
$balance_total = 0.0;
foreach ($accounts as $a) {
    $initial_deposit_total += (float)$a['initial_deposit'];
    $balance_total += (float)$a['balance'];
}

ua_json_ok(ua_response_envelope('capital', 'summary', $range, [
    'realized_pnl'    => $realized,
    'unrealized_pnl'  => $unreal,
    'total_pnl'       => $realized + $unreal,
    'closed_count'    => $closed_count,
    'open_count'      => $open_count,
    'trade_count'     => $closed_count,
    'last_trade_at'   => $last_trade_at,
    'initial_deposit' => $initial_deposit_total,
    'balance'         => $balance_total,
    'breakdown'       => $breakdown,
], ['account_ids' => $account_ids, 'currency' => $accounts[0]['currency_type']]));
