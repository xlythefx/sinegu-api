<?php
/**
 * Read closed Bybit positions for one account over a time window.
 * Used by flask/bybit src/performance_report.py to compute period PnL from the
 * DB (bybit_pastpositions) instead of Bybit's closed-pnl API.
 *
 * GET params:
 *   api_key  (required) — the account's Bybit API key (row identifier)
 *   start    (optional) — inclusive lower bound on closed_at, "YYYY-MM-DD HH:MM:SS" (UTC)
 *   end      (optional) — exclusive upper bound on closed_at, "YYYY-MM-DD HH:MM:SS" (UTC)
 *
 * Returns: {
 *   "success": true,
 *   "total_pnl": 123.45,
 *   "rows": [ { "symbol": "BTCUSDT", "realized_pnl": 34.2, "closed_at": "2026-05-24 11:30:06" } ]
 * }
 * or { "success": false, "error": "..." }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use GET.']);
    exit;
}

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$apiKey = isset($_GET['api_key']) ? trim((string) $_GET['api_key']) : '';
$start = isset($_GET['start']) ? trim((string) $_GET['start']) : '';
$end = isset($_GET['end']) ? trim((string) $_GET['end']) : '';

if ($apiKey === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required param: api_key']);
    mysqli_close($conn);
    exit;
}

$sql = "SELECT symbol, realized_pnl, closed_at
        FROM bybit_pastpositions
        WHERE api_key = ?";
$types = 's';
$params = [$apiKey];
if ($start !== '') {
    $sql .= " AND closed_at >= ?";
    $types .= 's';
    $params[] = $start;
}
if ($end !== '') {
    $sql .= " AND closed_at < ?";
    $types .= 's';
    $params[] = $end;
}
$sql .= " ORDER BY closed_at ASC";

$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

mysqli_stmt_bind_param($stmt, $types, ...$params);
if (!mysqli_stmt_execute($stmt)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Execute failed: ' . mysqli_stmt_error($stmt)]);
    mysqli_stmt_close($stmt);
    mysqli_close($conn);
    exit;
}

$result = mysqli_stmt_get_result($stmt);
$rows = [];
$totalPnl = 0.0;
while ($row = mysqli_fetch_assoc($result)) {
    $pnl = (float) $row['realized_pnl'];
    $totalPnl += $pnl;
    $rows[] = [
        'symbol' => $row['symbol'],
        'realized_pnl' => $pnl,
        'closed_at' => $row['closed_at'],
    ];
}

mysqli_stmt_close($stmt);
mysqli_close($conn);

echo json_encode(['success' => true, 'total_pnl' => $totalPnl, 'rows' => $rows]);
