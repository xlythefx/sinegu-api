<?php
/**
 * Admin: list trade incidents (table trade_incidents, migration 093).
 *
 * GET /admin/GET/get_trade_incidents.php
 *   ?status=open,retrying         comma list of open|retrying|resolved|ignored, or "all" (default open,retrying)
 *   &broker=capital|binance|ig|mexc
 *   &kind=exit_failed             comma list allowed
 *   &severity=critical            comma list allowed
 *   &q=flux                       search symbol/account/uni_id/message/error_code/env_label + user name/email
 *   &from=YYYY-MM-DD&to=YYYY-MM-DD   on last_seen, whole days
 *   &limit=50&offset=0            limit 1..200
 *
 * -> { success, incidents[], summary:{open, retrying, critical, orphan_open, resolved_24h,
 *      by_broker:{capital:n,...}}, pagination:{total, limit, offset}, hint? }
 *
 * `summary` is the global state of the queue (open/retrying across all brokers), NOT
 * scoped to the filters: the stat cards answer "what needs attention right now" and
 * must not change as the admin narrows the table. pagination.total is filtered.
 *
 * If the table is missing this answers 200 with an empty list and
 * hint "run migration 093" so the page renders instead of erroring.
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Admin-Password');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../includes/trade_incidents_lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ti_json(405, ['success' => false, 'error' => 'Method not allowed']);
}

mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . '/../../conn.php';
if (!$conn) {
    ti_json(500, ['success' => false, 'error' => 'Database connection failed']);
}

$limit  = isset($_GET['limit']) ? min(200, max(1, (int) $_GET['limit'])) : 50;
$offset = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;

$emptySummary = [
    'open' => 0, 'retrying' => 0, 'critical' => 0, 'orphan_open' => 0, 'resolved_24h' => 0,
    'by_broker' => array_fill_keys(TI_BROKERS, 0),
];

if (!ti_table_exists($conn)) {
    mysqli_close($conn);
    echo json_encode([
        'success'    => true,
        'incidents'  => [],
        'summary'    => $emptySummary,
        'pagination' => ['total' => 0, 'limit' => $limit, 'offset' => $offset],
        'hint'       => 'run migration 093',
    ]);
    exit;
}

/** Comma list param -> values from $allowed (lowercased). */
function ti_list_param($name, ?array $allowed = null) {
    $raw = isset($_GET[$name]) ? strtolower(trim((string) $_GET[$name])) : '';
    if ($raw === '' || $raw === 'all') {
        return [];
    }
    $out = [];
    foreach (explode(',', $raw) as $v) {
        $v = trim($v);
        if ($v === '') {
            continue;
        }
        if ($allowed !== null && !in_array($v, $allowed, true)) {
            continue;
        }
        $out[] = $v;
    }
    return array_values(array_unique($out));
}

$esc = function ($v) use ($conn) {
    return "'" . mysqli_real_escape_string($conn, (string) $v) . "'";
};

$where = [];

$statusRaw = isset($_GET['status']) ? strtolower(trim((string) $_GET['status'])) : '';
if ($statusRaw === '') {
    $statuses = ['open', 'retrying'];
} else {
    $statuses = ti_list_param('status', TI_STATUSES);
}
if ($statuses) {
    $where[] = 'ti.status IN (' . implode(',', array_map($esc, $statuses)) . ')';
}

$brokers = ti_list_param('broker', TI_BROKERS);
if ($brokers) {
    $where[] = 'ti.broker IN (' . implode(',', array_map($esc, $brokers)) . ')';
}

$kinds = array_values(array_filter(array_map('ti_kind', ti_list_param('kind')), 'strlen'));
if ($kinds) {
    $where[] = 'ti.kind IN (' . implode(',', array_map($esc, $kinds)) . ')';
}

$severities = ti_list_param('severity', TI_SEVERITIES);
if ($severities) {
    $where[] = 'ti.severity IN (' . implode(',', array_map($esc, $severities)) . ')';
}

$q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
if ($q !== '') {
    $like = "'%" . mysqli_real_escape_string($conn, addcslashes($q, '%_\\')) . "%'";
    $where[] = "(ti.symbol LIKE $like OR ti.account LIKE $like OR ti.uni_id LIKE $like
                 OR ti.message LIKE $like OR ti.error_code LIKE $like OR ti.env_label LIKE $like
                 OR ti.kind LIKE $like OR ti.strategy LIKE $like
                 OR ti.uni_id IN (SELECT uc.uni_id FROM user_credentials uc
                                   WHERE uc.name LIKE $like OR uc.email LIKE $like))";
}

$from = isset($_GET['from']) ? trim((string) $_GET['from']) : '';
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $where[] = "ti.last_seen >= '" . $from . " 00:00:00'";
}
$to = isset($_GET['to']) ? trim((string) $_GET['to']) : '';
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $where[] = "ti.last_seen <= '" . $to . " 23:59:59'";
}

$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

// --- Total (filtered) ------------------------------------------------------
$total = 0;
$cRes = mysqli_query($conn, 'SELECT COUNT(*) AS n FROM trade_incidents ti' . $whereSql);
if (!$cRes) {
    $err = mysqli_error($conn);
    mysqli_close($conn);
    ti_json(500, ['success' => false, 'error' => $err]);
}
$total = (int) (mysqli_fetch_assoc($cRes)['n'] ?? 0);
mysqli_free_result($cRes);

// --- Page ------------------------------------------------------------------
// Severity first so criticals float to the top of an "open" view, then recency.
$sql = "SELECT ti.* FROM trade_incidents ti" . $whereSql . "
         ORDER BY FIELD(ti.status, 'open', 'retrying', 'resolved', 'ignored'),
                  FIELD(ti.severity, 'critical', 'error', 'warning'),
                  ti.last_seen DESC, ti.id DESC
         LIMIT " . (int) $limit . " OFFSET " . (int) $offset;
$res = mysqli_query($conn, $sql);
if (!$res) {
    $err = mysqli_error($conn);
    mysqli_close($conn);
    ti_json(500, ['success' => false, 'error' => $err]);
}
$rows = [];
$uniIds = [];
while ($row = mysqli_fetch_assoc($res)) {
    $rows[] = $row;
    if ($row['uni_id'] !== null && $row['uni_id'] !== '') {
        $uniIds[] = (string) $row['uni_id'];
    }
}
mysqli_free_result($res);

$users = ti_resolve_users($conn, $uniIds);
$incidents = [];
foreach ($rows as $row) {
    $incidents[] = ti_row_out($row, $users);
}

// --- Summary (global, not filtered) ---------------------------------------
$summary = $emptySummary;
$sRes = mysqli_query(
    $conn,
    "SELECT
        COALESCE(SUM(status = 'open'), 0)                                         AS open_n,
        COALESCE(SUM(status = 'retrying'), 0)                                     AS retrying_n,
        COALESCE(SUM(status IN ('open','retrying') AND severity = 'critical'), 0)  AS critical_n,
        COALESCE(SUM(status IN ('open','retrying') AND kind = 'orphan_position'), 0) AS orphan_n,
        COALESCE(SUM(status = 'resolved' AND resolved_at >= NOW() - INTERVAL 24 HOUR), 0) AS resolved_24h
       FROM trade_incidents
      WHERE status IN ('open','retrying')
         OR (status = 'resolved' AND resolved_at >= NOW() - INTERVAL 24 HOUR)"
);
if ($sRes) {
    $s = mysqli_fetch_assoc($sRes);
    mysqli_free_result($sRes);
    if ($s) {
        $summary['open']         = (int) $s['open_n'];
        $summary['retrying']     = (int) $s['retrying_n'];
        $summary['critical']     = (int) $s['critical_n'];
        $summary['orphan_open']  = (int) $s['orphan_n'];
        $summary['resolved_24h'] = (int) $s['resolved_24h'];
    }
}
$bRes = mysqli_query(
    $conn,
    "SELECT broker, COUNT(*) AS n FROM trade_incidents
      WHERE status IN ('open','retrying') GROUP BY broker"
);
if ($bRes) {
    while ($b = mysqli_fetch_assoc($bRes)) {
        $summary['by_broker'][$b['broker']] = (int) $b['n'];
    }
    mysqli_free_result($bRes);
}

mysqli_close($conn);

echo json_encode([
    'success'      => true,
    'incidents'    => $incidents,
    'summary'      => $summary,
    'pagination'   => ['total' => $total, 'limit' => $limit, 'offset' => $offset],
    'generated_at' => date('c'),
], JSON_INVALID_UTF8_SUBSTITUTE);
