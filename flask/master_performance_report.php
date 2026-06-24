<?php
/**
 * Master-account performance-report aggregates for the Telegram Daily/Weekly/Monthly report.
 *
 * Window aggregates over the `trades` table for ONE account (the master = account_id 1),
 * between [from, to) on trades.created_at. PnL stays in raw currency here; the bot converts
 * it to a percentage against the account balance.
 *
 * GET params:
 *   account_id  (int, default 1)
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
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$accountId = isset($_GET['account_id']) ? (int) $_GET['account_id'] : 1;
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

// --- Totals + win count for the window -----------------------------------------------------
$totalsSql = "SELECT COALESCE(SUM(pnl), 0) AS total_pnl,
                     COUNT(*) AS trade_count,
                     COALESCE(SUM(CASE WHEN pnl > 0 THEN 1 ELSE 0 END), 0) AS win_count
              FROM trades
              WHERE account_id = ? AND created_at >= ? AND created_at < ?";
$stmt = pr_prepare($conn, $totalsSql);
mysqli_stmt_bind_param($stmt, 'iss', $accountId, $from, $to);
mysqli_stmt_execute($stmt);
$totalsRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

// --- Top assets by summed PnL (best first) -------------------------------------------------
$topSql = "SELECT ticker, COALESCE(SUM(pnl), 0) AS pnl
           FROM trades
           WHERE account_id = ? AND created_at >= ? AND created_at < ?
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
$balSql = "SELECT balance FROM accounts WHERE id = ?";
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
