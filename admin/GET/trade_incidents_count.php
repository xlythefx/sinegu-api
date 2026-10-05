<?php
/**
 * Admin: lightweight open/critical trade-incident counts for the sidebar badge.
 *
 * GET /admin/GET/trade_incidents_count.php
 * -> { success, open, critical }
 *    open     = status open + retrying (every kind, error_log included)
 *    critical = severity critical AND status open/retrying
 *
 * Polled every 60s by the admin shell, so it must stay one cheap query. A box
 * without migration 093 answers zeros plus hint "run migration 093" (200), so the
 * badge just stays hidden rather than spamming errors.
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

require_once __DIR__ . '/../../includes/trade_incidents_lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ti_json(405, ['success' => false, 'error' => 'Method not allowed']);
}

mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . '/../../conn.php';
if (!$conn) {
    ti_json(500, ['success' => false, 'error' => 'Database connection failed']);
}

$res = mysqli_query(
    $conn,
    "SELECT COUNT(*) AS open_n, COALESCE(SUM(severity = 'critical'), 0) AS critical_n
       FROM trade_incidents
      WHERE status IN ('open','retrying')"
);
if (!$res) {
    $err = mysqli_error($conn);
    $missing = !ti_table_exists($conn);
    mysqli_close($conn);
    if ($missing) {
        echo json_encode(['success' => true, 'open' => 0, 'critical' => 0, 'hint' => 'run migration 093']);
        exit;
    }
    ti_json(500, ['success' => false, 'error' => $err]);
}
$row = mysqli_fetch_assoc($res) ?: ['open_n' => 0, 'critical_n' => 0];
mysqli_free_result($res);
mysqli_close($conn);

echo json_encode([
    'success'  => true,
    'open'     => (int) $row['open_n'],
    'critical' => (int) $row['critical_n'],
]);
