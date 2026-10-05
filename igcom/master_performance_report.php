<?php
/**
 * Master IG-account performance-report aggregates for the Telegram Daily/Weekly/Monthly report.
 *
 * IG variant of flask/master_performance_report.php. Window aggregates over the
 * `ig_past_positions` table (realized/closed positions) for ONE account. By default this is
 * the master account, resolved by ig_accounts.name='master' (NOT a hardcoded id). An explicit
 * account_id param overrides it for ad-hoc per-account reports.
 * Range is [from, to) on ig_past_positions.closed_at. PnL stays in raw currency here; the bot
 * converts it to a percentage against the account balance.
 *
 * GET params:
 *   account_id  (int, optional — defaults to the account named 'master')
 *   from        (datetime 'YYYY-MM-DD HH:MM:SS', inclusive lower bound)  REQUIRED
 *   to          (datetime 'YYYY-MM-DD HH:MM:SS', exclusive upper bound)  REQUIRED
 *
 * Returns JSON:
 *   {
 *     "success": true,
 *     "account_id": 1,
 *     "from": "...", "to": "...",
 *     "total_pnl": 123.45,
 *     "trade_count": 12,
 *     "win_count": 9,
 *     "top_assets": [{"ticker": "GOLD", "pnl": 80.0}, ...],   // up to 3, best first
 *     "balance": 10000.00                                       // master current balance
 *   }
 * When no 'master' account exists (and no account_id was given): HTTP 200
 *   {"success": true, "data": null, "reason": "master_not_found"} — no aggregates are returned.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// null here means "no explicit id given" → resolve the master by name after conn opens.
$accountId = isset($_GET['account_id']) ? (int) $_GET['account_id'] : null;
$from = isset($_GET['from']) ? trim($_GET['from']) : '';
$to = isset($_GET['to']) ? trim($_GET['to']) : '';

if ($from === '' || $to === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => "Both 'from' and 'to' datetime params are required",
    ]);
    exit;
}

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

// Resolve master by name when no explicit account_id was requested (column-based, never id=1).
if ($accountId === null) {
    $mRes = mysqli_query($conn, "SELECT id FROM ig_accounts WHERE name = 'master' AND deleted_at IS NULL LIMIT 1");
    $mRow = $mRes ? mysqli_fetch_assoc($mRes) : null;
    if (!$mRow) {
        // 200, not 404: "this box has no IG master" is a normal configuration (staging
        // never ran IG, prod may not name one), not a server fault. A 404 here made the
        // IG performance_report loop log an HTTP error every minute. Callers check
        // `data === null` / `reason` instead.
        mysqli_close($conn);
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'data'    => null,
            'reason'  => 'master_not_found',
            'message' => "No ig_accounts row with name='master'",
        ]);
        exit;
    }
    $accountId = (int) $mRow['id'];
}

/** Run a prepared statement; on failure emit a 500 JSON error and exit. */
function pr_prepare($conn, $sql) {
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        mysqli_close($conn);
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
        exit;
    }
    return $stmt;
}

// --- Timezone bridge -----------------------------------------------------------------------
// The bot passes `from`/`to` as UTC, but ig_past_positions.closed_at is stored in the DB
// server's LOCAL time (DEFAULT CURRENT_TIMESTAMP). Convert each UTC boundary to server-local
// using the server's own current offset (NOW() - UTC_TIMESTAMP()) so the range comparison is
// timezone-consistent. Offset is read from the live clock => DST-safe. Without this the
// daily/weekly/monthly windows are shifted by the server's UTC offset. Mirrors the Capital file.
$tzShift = "INTERVAL TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW()) SECOND";

// --- Totals + win count for the window -----------------------------------------------------
$totalsSql = "SELECT COALESCE(SUM(pnl), 0) AS total_pnl,
                     COUNT(*) AS trade_count,
                     COALESCE(SUM(CASE WHEN pnl > 0 THEN 1 ELSE 0 END), 0) AS win_count
              FROM ig_past_positions
              WHERE account_id = ?
                AND closed_at >= (? + $tzShift)
                AND closed_at <  (? + $tzShift)";
$stmt = pr_prepare($conn, $totalsSql);
mysqli_stmt_bind_param($stmt, 'iss', $accountId, $from, $to);
mysqli_stmt_execute($stmt);
$totalsRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

// --- Top assets by summed PnL (best first) -------------------------------------------------
$topSql = "SELECT ticker, COALESCE(SUM(pnl), 0) AS pnl
           FROM ig_past_positions
           WHERE account_id = ?
             AND closed_at >= (? + $tzShift)
             AND closed_at <  (? + $tzShift)
           GROUP BY ticker
           ORDER BY pnl DESC
           LIMIT 3";
$stmt = pr_prepare($conn, $topSql);
mysqli_stmt_bind_param($stmt, 'iss', $accountId, $from, $to);
mysqli_stmt_execute($stmt);
$topResult = mysqli_stmt_get_result($stmt);
$topAssets = [];
while ($row = mysqli_fetch_assoc($topResult)) {
    $topAssets[] = [
        'ticker' => $row['ticker'],
        'pnl' => (float) $row['pnl'],
    ];
}
mysqli_stmt_close($stmt);

// --- Master account current balance (denominator basis) ------------------------------------
$balSql = "SELECT balance FROM ig_accounts WHERE id = ?";
$stmt = pr_prepare($conn, $balSql);
mysqli_stmt_bind_param($stmt, 'i', $accountId);
mysqli_stmt_execute($stmt);
$balRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
$balance = ($balRow && $balRow['balance'] !== null) ? (float) $balRow['balance'] : null;

mysqli_close($conn);

echo json_encode([
    'success' => true,
    'account_id' => $accountId,
    'from' => $from,
    'to' => $to,
    'total_pnl' => (float) ($totalsRow['total_pnl'] ?? 0),
    'trade_count' => (int) ($totalsRow['trade_count'] ?? 0),
    'win_count' => (int) ($totalsRow['win_count'] ?? 0),
    'top_assets' => $topAssets,
    'balance' => $balance,
]);
?>
