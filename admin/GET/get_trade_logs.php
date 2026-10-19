<?php
/**
 * Admin: read the signal log (trade_logs) — one row per signal per broker.
 *
 * GET /admin/GET/get_trade_logs.php
 *   ?broker=capital|binance|ig      exact
 *   &action=BUY|SELL|EXIT_LONG|...  exact, upper-cased
 *   &ticker=BTCUSD                  exact, upper-cased
 *   &category=signal|rejected|manual|retry
 *   &feed=main|test                 binance test feed (migration 096); omitted = both
 *   &strategy=<key>
 *   &result=success|problem         success -> success=1, problem -> success=0
 *   &from=YYYY-MM-DD                inclusive, from 00:00:00
 *   &to=YYYY-MM-DD                  inclusive, to 23:59:59
 *   &limit=50                       1..200
 *   &offset=0
 *
 * -> { success, logs[], summary, pagination, filters }
 *
 * Read-only: the three Flask engines are the only writers, through
 * flask/log_trade_signal.php.
 *
 * Two things worth knowing before changing this:
 *
 *  - `summary` is scoped to the FILTERS but not to the page window. It answers
 *    "how did these signals go" for the whole filtered set, so paging must not
 *    change the stat cards. summary.signals therefore equals pagination.total.
 *
 *  - user names are resolved in ONE query. The uni_ids live inside the details
 *    JSON, so the obvious implementation is a lookup per account per row, which
 *    on a 50-row page with a wide fan-out is hundreds of queries. Instead every
 *    uni_id on the page is collected first and resolved in a single IN().
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../../conn.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

/**
 * This endpoint answers "trade_logs doesn't exist yet — run migration 084" with
 * a JSON hint, which requires a failed query to RETURN FALSE.
 *
 * Since PHP 8.1 mysqli defaults to MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT and
 * throws mysqli_sql_exception instead; `@` does not suppress exceptions. Without
 * this the pre-migration case is an empty 500 with no explanation at all.
 */
mysqli_report(MYSQLI_REPORT_OFF);

// --- Filters -------------------------------------------------------------
$limit  = isset($_GET['limit']) ? min(200, max(1, (int) $_GET['limit'])) : 50;
$offset = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;

$where = [];

$broker = isset($_GET['broker']) ? strtolower(trim((string) $_GET['broker'])) : '';
if (in_array($broker, ['capital', 'binance', 'ig', 'mexc'], true)) {
    $where[] = "broker = '" . mysqli_real_escape_string($conn, $broker) . "'";
}

$action = isset($_GET['action']) ? strtoupper(trim((string) $_GET['action'])) : '';
if ($action !== '' && $action !== 'ALL') {
    $where[] = "action = '" . mysqli_real_escape_string($conn, $action) . "'";
}

$ticker = isset($_GET['ticker']) ? strtoupper(trim((string) $_GET['ticker'])) : '';
if ($ticker !== '' && $ticker !== 'ALL') {
    $where[] = "ticker = '" . mysqli_real_escape_string($conn, $ticker) . "'";
}

$category = isset($_GET['category']) ? strtolower(trim((string) $_GET['category'])) : '';
if ($category !== '' && $category !== 'all') {
    $where[] = "category = '" . mysqli_real_escape_string($conn, $category) . "'";
}

// trade_logs.feed arrives with migration 096; before it every row is main.
$hasFeed = false;
$feedRes = @mysqli_query(
    $conn,
    "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trade_logs' AND COLUMN_NAME = 'feed'"
);
if ($feedRes) {
    $feedRow = mysqli_fetch_assoc($feedRes);
    $hasFeed = $feedRow && (int) $feedRow['c'] > 0;
    mysqli_free_result($feedRes);
}

$feed = isset($_GET['feed']) ? strtolower(trim((string) $_GET['feed'])) : '';
if (in_array($feed, ['main', 'test'], true)) {
    if ($hasFeed) {
        $where[] = "feed = '" . $feed . "'";
    } elseif ($feed === 'test') {
        $where[] = '1 = 0';
    }
}

$strategy = isset($_GET['strategy']) ? trim((string) $_GET['strategy']) : '';
if ($strategy !== '' && strtolower($strategy) !== 'all') {
    $where[] = "strategy = '" . mysqli_real_escape_string($conn, $strategy) . "'";
}

$result = isset($_GET['result']) ? strtolower(trim((string) $_GET['result'])) : '';
if ($result === 'success') {
    $where[] = 'success = 1';
} elseif ($result === 'problem') {
    $where[] = 'success = 0';
}

// Dates are whole days: `to=2026-08-13` must include everything logged that day,
// so it becomes 23:59:59 rather than midnight.
$from = isset($_GET['from']) ? trim((string) $_GET['from']) : '';
if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $where[] = "ts >= '" . mysqli_real_escape_string($conn, $from) . " 00:00:00'";
}
$to = isset($_GET['to']) ? trim((string) $_GET['to']) : '';
if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $where[] = "ts <= '" . mysqli_real_escape_string($conn, $to) . " 23:59:59'";
}

$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

// --- Summary (filtered, not paged) ---------------------------------------
$summary = [
    'signals'       => 0,
    'clean_signals' => 0,
    'filled'        => 0,
    'failed'        => 0,
    'skipped'       => 0,
];
$sumSql = "SELECT COUNT(*) AS signals,
                  COALESCE(SUM(success = 1), 0) AS clean_signals,
                  COALESCE(SUM(filled), 0)  AS filled,
                  COALESCE(SUM(failed), 0)  AS failed,
                  COALESCE(SUM(skipped), 0) AS skipped
             FROM trade_logs" . $whereSql;
$sumRes = @mysqli_query($conn, $sumSql);
if (!$sumRes) {
    // Almost always "table doesn't exist" — migration 084 not applied on this box.
    $err = mysqli_error($conn);
    mysqli_close($conn);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => $err,
        'hint'    => "If this says trade_logs doesn't exist, run migration 084_create_trade_logs.sql from Admin -> Settings.",
    ]);
    exit;
}
$sumRow = mysqli_fetch_assoc($sumRes);
mysqli_free_result($sumRes);
if ($sumRow) {
    $summary = [
        'signals'       => (int) $sumRow['signals'],
        'clean_signals' => (int) $sumRow['clean_signals'],
        'filled'        => (int) $sumRow['filled'],
        'failed'        => (int) $sumRow['failed'],
        'skipped'       => (int) $sumRow['skipped'],
    ];
}
$total = $summary['signals'];

// --- Page ----------------------------------------------------------------
$sql = "SELECT id, broker, action, ticker, success, price, strategy, leverage, category,
               " . ($hasFeed ? 'feed' : "'main' AS feed") . ",
               target_count, filled, failed, skipped, details, ts, created_at
          FROM trade_logs" . $whereSql . "
         ORDER BY ts DESC, id DESC
         LIMIT " . (int) $limit . " OFFSET " . (int) $offset;

$res = @mysqli_query($conn, $sql);
if (!$res) {
    $err = mysqli_error($conn);
    mysqli_close($conn);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $err]);
    exit;
}

$logs = [];
$uniIds = [];

while ($row = mysqli_fetch_assoc($res)) {
    $details = [];
    $truncated = false;

    if ($row['details'] !== null && $row['details'] !== '') {
        $decoded = json_decode($row['details'], true);
        if (is_array($decoded)) {
            // Two shapes: a flat array (normal) or {accounts, truncated} when the
            // writer had to cut an unusually wide fan-out. Accept both.
            if (isset($decoded['accounts']) && is_array($decoded['accounts'])) {
                $details   = $decoded['accounts'];
                $truncated = !empty($decoded['truncated']);
            } else {
                $details = $decoded;
            }
        }
    }

    foreach ($details as $d) {
        if (is_array($d) && isset($d['uni_id']) && $d['uni_id'] !== '') {
            $uniIds[(string) $d['uni_id']] = true;
        }
    }

    $logs[] = [
        'id'           => (int) $row['id'],
        'broker'       => $row['broker'],
        'action'       => $row['action'],
        'ticker'       => $row['ticker'],
        'success'      => (int) $row['success'] === 1,
        'price'        => $row['price'] === null ? null : (float) $row['price'],
        'strategy'     => $row['strategy'],
        'leverage'     => $row['leverage'] === null ? null : (int) $row['leverage'],
        'category'     => $row['category'],
        'feed'         => $row['feed'],
        'target_count' => (int) $row['target_count'],
        'filled'       => (int) $row['filled'],
        'failed'       => (int) $row['failed'],
        'skipped'      => (int) $row['skipped'],
        'details'      => $details,
        'truncated'    => $truncated,
        'ts'           => $row['ts'],
        'created_at'   => $row['created_at'],
    ];
}
mysqli_free_result($res);

// --- Resolve every uni_id on this page in one query ----------------------
if ($uniIds) {
    $escaped = [];
    foreach (array_keys($uniIds) as $u) {
        $escaped[] = "'" . mysqli_real_escape_string($conn, $u) . "'";
    }
    $nameRes = @mysqli_query(
        $conn,
        'SELECT uni_id, name, email FROM user_credentials WHERE uni_id IN (' . implode(',', $escaped) . ')'
    );
    $names = [];
    if ($nameRes) {
        while ($n = mysqli_fetch_assoc($nameRes)) {
            $names[(string) $n['uni_id']] = [
                'name'  => $n['name'],
                'email' => $n['email'],
            ];
        }
        mysqli_free_result($nameRes);
    }
    foreach ($logs as &$log) {
        foreach ($log['details'] as &$d) {
            if (!is_array($d)) {
                continue;
            }
            $u = isset($d['uni_id']) ? (string) $d['uni_id'] : '';
            $d['user_name']  = isset($names[$u]) ? $names[$u]['name'] : null;
            $d['user_email'] = isset($names[$u]) ? $names[$u]['email'] : null;
        }
        unset($d);
    }
    unset($log);
}

// --- Filter option lists (whole table, so the dropdowns stay stable) ------
$tickers = [];
$tRes = @mysqli_query($conn, "SELECT DISTINCT ticker FROM trade_logs WHERE ticker <> '' ORDER BY ticker ASC");
if ($tRes) {
    while ($r = mysqli_fetch_assoc($tRes)) {
        $tickers[] = $r['ticker'];
    }
    mysqli_free_result($tRes);
}

$strategies = [];
$sRes = @mysqli_query($conn, "SELECT DISTINCT strategy FROM trade_logs WHERE strategy IS NOT NULL AND strategy <> '' ORDER BY strategy ASC");
if ($sRes) {
    while ($r = mysqli_fetch_assoc($sRes)) {
        $strategies[] = $r['strategy'];
    }
    mysqli_free_result($sRes);
}

$brokers = [];
$bRes = @mysqli_query($conn, 'SELECT DISTINCT broker FROM trade_logs ORDER BY broker ASC');
if ($bRes) {
    while ($r = mysqli_fetch_assoc($bRes)) {
        $brokers[] = $r['broker'];
    }
    mysqli_free_result($bRes);
}

mysqli_close($conn);

echo json_encode([
    'success' => true,
    'logs'    => $logs,
    'summary' => $summary,
    'pagination' => [
        'limit'  => $limit,
        'offset' => $offset,
        'total'  => $total,
    ],
    'filters' => [
        'brokers'    => $brokers,
        'tickers'    => $tickers,
        'strategies' => $strategies,
    ],
    'generated_at' => date('c'),
], JSON_INVALID_UTF8_SUBSTITUTE);
