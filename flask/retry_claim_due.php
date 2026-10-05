<?php
/**
 * Claim due rows from capital_retry_queue for the bot's single retry worker.
 * Bot-only. Secret shared with src/config.py CAPITAL_API_LOG_SECRET.
 *
 * Behaviour each call:
 *  1. Reclaim stale 'processing' rows (worker crashed mid-attempt):
 *       - EXIT  -> back to 'pending' (safe to re-run; exits are idempotent).
 *       - ENTRY -> 'needs_review' (NOT auto re-run; avoids a duplicate entry).
 *  2. Atomically claim due pending rows (next_attempt_at <= NOW()) -> 'processing'.
 *
 * POST JSON: { "secret": "...", "limit": 50, "stale_seconds": 300, "broker": "capital" }
 * Returns: { success, claimed: [row,...], needs_review: [row,...] }
 *
 * broker (optional, default 'capital'; migration 094) scopes EVERY step - reclaim and
 * claim - to that broker's rows, so CapitalFlask never picks up a Binance/MEXC job
 * and vice versa. Without the broker column only 'capital' is accepted (409
 * otherwise) and the SQL is the original capital-only version.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use POST.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) { $input = []; }

$configPath = __DIR__ . '/config_bot_listener.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Config not found']);
    exit;
}
require_once $configPath;
$expectedSecret = isset($CAPITAL_API_LOG_SECRET) ? $CAPITAL_API_LOG_SECRET : '';
$secret = isset($input['secret']) ? (string) $input['secret'] : '';
if ($expectedSecret === '' || $secret !== $expectedSecret) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../includes/retry_queue_schema.php';
$broker = retry_queue_broker($input['broker'] ?? null);
if ($broker === null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'broker must be one of: ' . implode(', ', RETRY_QUEUE_BROKERS)]);
    exit;
}

$limit = isset($input['limit']) ? max(1, min(200, (int) $input['limit'])) : 50;
$stale = isset($input['stale_seconds']) ? max(60, (int) $input['stale_seconds']) : 300;

mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . '/../conn.php';
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$schema = retry_queue_columns($conn);
retry_queue_require_broker_column($conn, $schema, $broker);

$cols = 'id, kind, action, ticker, price, strategy, target_uni_ids, is_demo_only,
         attempt_count, max_attempts, created_at'
    . ($schema['broker'] ? ', broker' : '')
    . ($schema['payload'] ? ', payload' : '');

// Every query below is scoped to this broker once the column exists. $broker is a
// whitelisted literal (retry_queue_broker), so inlining it is safe and keeps the
// existing bind_param signatures unchanged.
$brokerSql = $schema['broker'] ? " AND broker='" . $broker . "'" : '';

function row_to_out($row) {
    $out = [
        'id'             => (int) $row['id'],
        'kind'           => $row['kind'],
        'action'         => $row['action'],
        'ticker'         => $row['ticker'],
        'price'          => $row['price'] !== null ? (float) $row['price'] : null,
        'strategy'       => $row['strategy'],
        'target_uni_ids' => ($row['target_uni_ids'] !== null && $row['target_uni_ids'] !== '')
            ? json_decode($row['target_uni_ids'], true) : null,
        'is_demo_only'   => (int) $row['is_demo_only'],
        'attempt_count'  => (int) $row['attempt_count'],
        'max_attempts'   => (int) $row['max_attempts'],
        'created_at'     => $row['created_at'],
    ];
    if (array_key_exists('broker', $row)) {
        $out['broker'] = $row['broker'];
    }
    if (array_key_exists('payload', $row)) {
        $out['payload'] = ($row['payload'] !== null && $row['payload'] !== '')
            ? json_decode($row['payload'], true) : null;
    }
    return $out;
}

// 1a. Stale ENTRY processing rows -> needs_review (collect to return for alerting).
$needs_review = [];
$selStaleEntry = mysqli_prepare(
    $conn,
    "SELECT $cols FROM capital_retry_queue
      WHERE status='processing' AND kind='entry'
        AND updated_at < DATE_SUB(NOW(), INTERVAL ? SECOND)$brokerSql"
);
if ($selStaleEntry) {
    mysqli_stmt_bind_param($selStaleEntry, 'i', $stale);
    if (mysqli_stmt_execute($selStaleEntry)) {
        $r = mysqli_stmt_get_result($selStaleEntry);
        while ($row = mysqli_fetch_assoc($r)) { $needs_review[] = row_to_out($row); }
    }
    mysqli_stmt_close($selStaleEntry);
}
if (count($needs_review) > 0) {
    $upd = mysqli_prepare(
        $conn,
        "UPDATE capital_retry_queue SET status='needs_review'
          WHERE status='processing' AND kind='entry'
            AND updated_at < DATE_SUB(NOW(), INTERVAL ? SECOND)$brokerSql"
    );
    if ($upd) {
        mysqli_stmt_bind_param($upd, 'i', $stale);
        mysqli_stmt_execute($upd);
        mysqli_stmt_close($upd);
    }
}

// 1b. Stale EXIT processing rows -> pending (re-runnable; exits are idempotent).
$updExit = mysqli_prepare(
    $conn,
    "UPDATE capital_retry_queue SET status='pending'
      WHERE status='processing' AND kind='exit'
        AND updated_at < DATE_SUB(NOW(), INTERVAL ? SECOND)$brokerSql"
);
if ($updExit) {
    mysqli_stmt_bind_param($updExit, 'i', $stale);
    mysqli_stmt_execute($updExit);
    mysqli_stmt_close($updExit);
}

// 2. Claim due pending rows. Per-row conditional UPDATE makes the claim atomic
//    even if more than one consumer ever runs.
$claimed = [];
$sel = mysqli_prepare(
    $conn,
    "SELECT $cols FROM capital_retry_queue
      WHERE status='pending' AND next_attempt_at <= NOW()$brokerSql
      ORDER BY next_attempt_at ASC
      LIMIT ?"
);
if (!$sel) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}
mysqli_stmt_bind_param($sel, 'i', $limit);
mysqli_stmt_execute($sel);
$res = mysqli_stmt_get_result($sel);
$candidates = [];
while ($row = mysqli_fetch_assoc($res)) { $candidates[] = $row; }
mysqli_stmt_close($sel);

$claimStmt = mysqli_prepare(
    $conn,
    "UPDATE capital_retry_queue SET status='processing', updated_at=NOW()
      WHERE id=? AND status='pending'"
);
foreach ($candidates as $row) {
    if (!$claimStmt) break;
    $id = (int) $row['id'];
    mysqli_stmt_bind_param($claimStmt, 'i', $id);
    if (mysqli_stmt_execute($claimStmt) && mysqli_stmt_affected_rows($claimStmt) === 1) {
        $claimed[] = row_to_out($row);
    }
}
if ($claimStmt) { mysqli_stmt_close($claimStmt); }

echo json_encode(['success' => true, 'broker' => $broker, 'claimed' => $claimed, 'needs_review' => $needs_review]);
mysqli_close($conn);
