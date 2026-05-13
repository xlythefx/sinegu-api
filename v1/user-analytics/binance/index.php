<?php
// /sinegu-api/v1/user-analytics/binance[/<endpoint>]
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

$account = null;
$sql = 'SELECT api_key, name, COALESCE(balance, 0) AS balance,
               COALESCE(initial_deposit, 0) AS initial_deposit,
               COALESCE(currency_type, "USDT") AS currency_type
        FROM binance_accounts WHERE api_key = ? AND deleted_at IS NULL';
if ($stmt = mysqli_prepare($conn, $sql)) {
    mysqli_stmt_bind_param($stmt, 's', $api_key);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $account = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);
}
if (!$account) ua_json_err(403, 'API key not recognized');

$currency = $account['currency_type'];
$meta = ['account_name' => $account['name'], 'currency' => $currency];

if ($endpoint === 'open_trades') {
    $sql = "SELECT id, symbol, position_side, position_amt, entry_price, mark_price,
                   unrealized_profit, notional, update_time, created_at
            FROM binance_positions WHERE api_key = ? ORDER BY created_at DESC";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 's', $api_key);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    $unreal = 0.0;
    while ($r = mysqli_fetch_assoc($res)) {
        $r['id'] = (int)$r['id'];
        $r['unrealized_profit'] = isset($r['unrealized_profit']) ? (float)$r['unrealized_profit'] : 0.0;
        $unreal += $r['unrealized_profit'];
        $rows[] = $r;
    }
    mysqli_stmt_close($stmt);
    ua_json_ok(ua_response_envelope('binance', 'open_trades', $range, [
        'positions' => $rows,
        'open_count' => count($rows),
        'unrealized_pnl' => $unreal,
    ], $meta));
}

if ($endpoint === 'closed_trades') {
    if ($range['has_range']) {
        $sql = "SELECT id, symbol, position_side, side, position_amt, entry_price, exit_price,
                       realized_pnl, order_id, closed_at
                FROM binance_pastpositions
                WHERE api_key = ? AND closed_at BETWEEN ? AND ?
                ORDER BY closed_at DESC";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, 'sss', $api_key, $range['from'], $range['to']);
    } else {
        $sql = "SELECT id, symbol, position_side, side, position_amt, entry_price, exit_price,
                       realized_pnl, order_id, closed_at
                FROM binance_pastpositions
                WHERE api_key = ?
                ORDER BY closed_at DESC";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, 's', $api_key);
    }
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    $realized = 0.0;
    while ($r = mysqli_fetch_assoc($res)) {
        $r['id'] = (int)$r['id'];
        $r['realized_pnl'] = isset($r['realized_pnl']) ? (float)$r['realized_pnl'] : 0.0;
        $realized += $r['realized_pnl'];
        $rows[] = $r;
    }
    mysqli_stmt_close($stmt);
    ua_json_ok(ua_response_envelope('binance', 'closed_trades', $range, [
        'trades' => $rows,
        'closed_count' => count($rows),
        'realized_pnl' => $realized,
    ], $meta));
}

$bucketSelect = [
    'daily'   => "DATE(closed_at)",
    'weekly'  => "DATE_FORMAT(STR_TO_DATE(CONCAT(YEARWEEK(closed_at, 3), ' Monday'), '%X%V %W'), '%Y-%m-%d')",
    'monthly' => "DATE_FORMAT(closed_at, '%Y-%m')",
];

if (in_array($endpoint, ['daily', 'weekly', 'monthly'], true)) {
    $expr = $bucketSelect[$endpoint];
    if ($range['has_range']) {
        $sql = "SELECT $expr AS bucket, COALESCE(SUM(realized_pnl), 0) AS pnl, COUNT(*) AS trades
                FROM binance_pastpositions
                WHERE api_key = ? AND closed_at BETWEEN ? AND ?
                GROUP BY bucket ORDER BY bucket ASC";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, 'sss', $api_key, $range['from'], $range['to']);
    } else {
        $sql = "SELECT $expr AS bucket, COALESCE(SUM(realized_pnl), 0) AS pnl, COUNT(*) AS trades
                FROM binance_pastpositions
                WHERE api_key = ?
                GROUP BY bucket ORDER BY bucket ASC";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, 's', $api_key);
    }
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    mysqli_stmt_close($stmt);
    ua_json_ok(ua_response_envelope('binance', $endpoint, $range, [
        'series' => ua_cumulative_series($rows, 'bucket'),
    ], $meta));
}

// summary
$realized = 0.0; $closed_count = 0; $last_at = null;
if ($range['has_range']) {
    $sql = "SELECT COALESCE(SUM(realized_pnl), 0) AS pnl, COUNT(*) AS cnt, MAX(closed_at) AS last_at
            FROM binance_pastpositions WHERE api_key = ? AND closed_at BETWEEN ? AND ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'sss', $api_key, $range['from'], $range['to']);
} else {
    $sql = "SELECT COALESCE(SUM(realized_pnl), 0) AS pnl, COUNT(*) AS cnt, MAX(closed_at) AS last_at
            FROM binance_pastpositions WHERE api_key = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 's', $api_key);
}
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
if ($r = mysqli_fetch_assoc($res)) {
    $realized = (float)$r['pnl']; $closed_count = (int)$r['cnt']; $last_at = $r['last_at'];
}
mysqli_stmt_close($stmt);

$open_count = 0; $unreal = 0.0;
$sql = "SELECT COUNT(*) AS cnt, COALESCE(SUM(unrealized_profit), 0) AS u FROM binance_positions WHERE api_key = ?";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, 's', $api_key);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
if ($r = mysqli_fetch_assoc($res)) { $open_count = (int)$r['cnt']; $unreal = (float)$r['u']; }
mysqli_stmt_close($stmt);

$breakdown = [];
foreach (['daily' => '-1 day', 'weekly' => '-7 day', 'monthly' => '-30 day'] as $key => $mod) {
    $w = ua_period_window($mod);
    $sql = "SELECT COALESCE(SUM(realized_pnl), 0) AS pnl, COUNT(*) AS cnt
            FROM binance_pastpositions WHERE api_key = ? AND closed_at BETWEEN ? AND ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'sss', $api_key, $w['from'], $w['to']);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res) ?: ['pnl' => 0, 'cnt' => 0];
    mysqli_stmt_close($stmt);
    $breakdown[$key] = [
        'pnl' => (float)$row['pnl'], 'trades' => (int)$row['cnt'],
        'from' => $w['from_date'], 'to' => $w['to_date'],
    ];
}

ua_json_ok(ua_response_envelope('binance', 'summary', $range, [
    'realized_pnl'    => $realized,
    'unrealized_pnl'  => $unreal,
    'total_pnl'       => $realized + $unreal,
    'closed_count'    => $closed_count,
    'open_count'      => $open_count,
    'trade_count'     => $closed_count,
    'last_trade_at'   => $last_at,
    'initial_deposit' => (float)$account['initial_deposit'],
    'balance'         => (float)$account['balance'],
    'breakdown'       => $breakdown,
], $meta));
