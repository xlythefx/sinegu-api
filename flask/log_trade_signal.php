<?php
/**
 * Record one trading signal (and its per-account fan-out) into trade_logs.
 *
 * Called by all three engines — CapitalFlask, ig-flask and binance-flask — once
 * per webhook, after the fan-out has finished. This is the row that makes
 * /admin/trade-logs able to answer "the engine got the alert; what did it do?",
 * which nothing in this system could answer before: capital_api_logs and
 * ig_api_logs only record API *errors*, capital_retry_queue only records
 * deferred signals, and Binance wrote nothing to the DB at all.
 *
 * POST JSON:
 * {
 *   "secret": "...",              // required; TRADE_LOG_SECRET, else CAPITAL_API_LOG_SECRET
 *   "broker": "capital|binance|ig",
 *   "action": "BUY",              // BUY | SELL | EXIT | EXIT_LONG | EXIT_SHORT
 *   "ticker": "BTCUSD",
 *   "success": true,              // optional; defaults to "no detail failed"
 *   "price": 64000.5,             // optional
 *   "strategy": "VWAP-Deviation", // optional
 *   "leverage": 25,               // optional
 *   "category": "signal",         // optional: signal | rejected | manual | retry
 *   "feed": "main",               // optional: main | test (binance test feed); default main
 *   "ts": "2026-08-13 09:12:00",  // optional UTC; defaults to now
 *   "details": [ { account, uni_id, status, reason, error, retryable,
 *                  quantity, fill_price, closed_quantity, sizing }, ... ]
 * }
 *
 * -> { success, log_id, filled, skipped, failed } or { success: false, error }
 *
 * DESIGN RULE: this endpoint must never be able to break a trade. The engines
 * call it fire-and-forget on a background thread, and every failure here is
 * reported as JSON rather than thrown — a logging outage must cost a log line,
 * never an order.
 *
 * Append-only. Nothing updates or deletes these rows; the admin page is
 * read-only (admin/GET/get_trade_logs.php).
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use POST.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON body']);
    exit;
}

/**
 * Shared secret. Prefers a dedicated $TRADE_LOG_SECRET but falls back to
 * $CAPITAL_API_LOG_SECRET, which every box already has — so this works on a
 * fresh deploy without hand-editing config on each server first.
 *
 * config_bot_listener.php is on the deploy-api exclude list (it holds per-box
 * secrets), and every caller in this codebase wraps it in file_exists() because
 * a missing copy is a real possibility on a newly provisioned box. If this
 * endpoint reports "Config not found", that file is what to check.
 */
$configPath = __DIR__ . '/config_bot_listener.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Config not found (flask/config_bot_listener.php)']);
    exit;
}
require_once $configPath;

$expectedSecret = '';
if (isset($TRADE_LOG_SECRET) && $TRADE_LOG_SECRET !== '') {
    $expectedSecret = (string) $TRADE_LOG_SECRET;
} elseif (isset($CAPITAL_API_LOG_SECRET)) {
    $expectedSecret = (string) $CAPITAL_API_LOG_SECRET;
}
$secret = isset($input['secret']) ? (string) $input['secret'] : '';
if ($expectedSecret === '' || !hash_equals($expectedSecret, $secret)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// --- Validate ------------------------------------------------------------
$broker = isset($input['broker']) ? strtolower(trim((string) $input['broker'])) : '';
// 'mexc' joined with migration 091 (trade_logs.broker ENUM) for the mexc-flask bot,
// 'bybit' with migration 102 for flask/bybit.
if (!in_array($broker, ['capital', 'binance', 'ig', 'mexc', 'bybit'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => "broker must be one of: capital, binance, ig, mexc, bybit"]);
    exit;
}

$action = isset($input['action']) ? strtoupper(trim((string) $input['action'])) : '';
if ($action === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing action']);
    exit;
}
$action = substr($action, 0, 16);

$ticker = isset($input['ticker']) ? substr(strtoupper(trim((string) $input['ticker'])), 0, 64) : '';
if ($ticker === '') {
    $ticker = 'UNKNOWN';
}

$category = isset($input['category']) ? strtolower(trim((string) $input['category'])) : 'signal';
if (!in_array($category, ['signal', 'rejected', 'manual', 'retry'], true)) {
    $category = 'signal';
}

// Which binance-flask webhook produced the signal. Anything unknown is main, so a
// typo can never hide a real signal behind the Signal Log's test-feed filter.
$feed = isset($input['feed']) ? strtolower(trim((string) $input['feed'])) : 'main';
if (!in_array($feed, ['main', 'test'], true)) {
    $feed = 'main';
}

$strategy = isset($input['strategy']) && $input['strategy'] !== ''
    ? substr(trim((string) $input['strategy']), 0, 128)
    : null;
$price = isset($input['price']) && $input['price'] !== '' && is_numeric($input['price'])
    ? (float) $input['price']
    : null;
$leverage = isset($input['leverage']) && $input['leverage'] !== '' && is_numeric($input['leverage'])
    ? (int) $input['leverage']
    : null;

// ts is the moment the ENGINE handled the signal, which is not the moment this
// row reached the API — a retried POST can arrive much later. Keep both.
$ts = null;
if (isset($input['ts']) && trim((string) $input['ts']) !== '') {
    $parsed = strtotime((string) $input['ts']);
    if ($parsed !== false) {
        $ts = gmdate('Y-m-d H:i:s', $parsed);
    }
}
if ($ts === null) {
    $ts = gmdate('Y-m-d H:i:s');
}

// --- Fan-out details -----------------------------------------------------
$details = isset($input['details']) && is_array($input['details']) ? array_values($input['details']) : [];

// A single signal can fan out to every account on the platform. Cap what we
// store so one pathological payload cannot bloat the table or the admin page;
// the counts below stay accurate because they are taken before truncation.
$MAX_DETAILS = 500;

$filled = 0;
$skipped = 0;
$failed = 0;
foreach ($details as $d) {
    if (!is_array($d)) {
        continue;
    }
    $status = isset($d['status']) ? strtolower((string) $d['status']) : '';
    if ($status === 'filled') {
        $filled++;
    } elseif ($status === 'skipped') {
        $skipped++;
    } elseif ($status === 'failed') {
        $failed++;
    }
}
$targetCount = isset($input['target_count']) && is_numeric($input['target_count'])
    ? (int) $input['target_count']
    : count($details);

$truncated = false;
if (count($details) > $MAX_DETAILS) {
    $details = array_slice($details, 0, $MAX_DETAILS);
    $truncated = true;
}

// The engine may state success explicitly (a rejected signal is a failure even
// with zero details); otherwise derive it from the fan-out.
if (array_key_exists('success', $input)) {
    $success = $input['success'] ? 1 : 0;
} else {
    $success = ($failed === 0 && $category !== 'rejected') ? 1 : 0;
}

$detailsPayload = ['accounts' => $details];
if ($truncated) {
    $detailsPayload['truncated'] = true;
    $detailsPayload['truncated_at'] = $MAX_DETAILS;
}
// Keep the flat array shape the reader expects when nothing was truncated, so
// the common case stays trivially decodable.
$detailsJson = $truncated
    ? json_encode($detailsPayload, JSON_INVALID_UTF8_SUBSTITUTE)
    : json_encode($details, JSON_INVALID_UTF8_SUBSTITUTE);

if ($detailsJson === false) {
    $detailsJson = null;
}

// --- Insert --------------------------------------------------------------
require_once __DIR__ . '/../conn.php';
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

/**
 * The prepare below reports a missing trade_logs table as a JSON hint naming
 * migration 084, which needs mysqli_prepare to RETURN FALSE. Since PHP 8.1 it
 * throws mysqli_sql_exception by default instead, which would make this endpoint
 * answer a bot with an empty 500 rather than a readable reason.
 */
mysqli_report(MYSQLI_REPORT_OFF);

// trade_logs.feed arrives with migration 096; until then the row is written
// without it (and reads back as main), so the API can land first.
$hasFeed = false;
$feedRes = mysqli_query(
    $conn,
    "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trade_logs' AND COLUMN_NAME = 'feed'"
);
if ($feedRes) {
    $feedRow = mysqli_fetch_assoc($feedRes);
    $hasFeed = $feedRow && (int) $feedRow['c'] > 0;
    mysqli_free_result($feedRes);
}

$sql = $hasFeed
    ? 'INSERT INTO trade_logs
        (broker, action, ticker, success, price, strategy, leverage, category, feed,
         target_count, filled, failed, skipped, details, ts)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    : 'INSERT INTO trade_logs
        (broker, action, ticker, success, price, strategy, leverage, category,
         target_count, filled, failed, skipped, details, ts)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    $err = mysqli_error($conn);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => $err,
        'hint'    => "If this says trade_logs doesn't exist, run migration 084_create_trade_logs.sql.",
    ]);
    mysqli_close($conn);
    exit;
}

if ($hasFeed) {
    mysqli_stmt_bind_param(
        $stmt,
        'sssidsissiiiiss',
        $broker,
        $action,
        $ticker,
        $success,
        $price,
        $strategy,
        $leverage,
        $category,
        $feed,
        $targetCount,
        $filled,
        $failed,
        $skipped,
        $detailsJson,
        $ts
    );
} else {
    mysqli_stmt_bind_param(
        $stmt,
        'sssidsisiiiiss',
        $broker,
        $action,
        $ticker,
        $success,
        $price,
        $strategy,
        $leverage,
        $category,
        $targetCount,
        $filled,
        $failed,
        $skipped,
        $detailsJson,
        $ts
    );
}

$ok = mysqli_stmt_execute($stmt);
$logId = $ok ? mysqli_insert_id($conn) : 0;
$error = $ok ? null : mysqli_error($conn);
mysqli_stmt_close($stmt);
mysqli_close($conn);

if (!$ok) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $error]);
    exit;
}

echo json_encode([
    'success'   => true,
    'log_id'    => $logId,
    'broker'    => $broker,
    'feed'      => $feed,
    'filled'    => $filled,
    'skipped'   => $skipped,
    'failed'    => $failed,
    'truncated' => $truncated,
]);
