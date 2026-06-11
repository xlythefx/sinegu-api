<?php
/**
 * Enqueue a rate-limited Capital.com entry/exit into capital_retry_queue.
 * Bot-only (single producer). Secret shared with src/config.py CAPITAL_API_LOG_SECRET.
 *
 * POST JSON:
 * {
 *   "secret": "...",                 // required
 *   "kind": "exit",                  // required: "entry" | "exit"
 *   "action": "EXIT",                // required: BUY/SELL/EXIT/EXIT_LONG/EXIT_SHORT
 *   "ticker": "ETHUSD",              // required
 *   "price": 2029.5,                 // optional
 *   "strategy": "SineguAlerts",      // optional
 *   "target_uni_ids": ["..."],       // optional JSON array (accounts still pending); omit/[]=all
 *   "is_demo_only": false,           // optional
 *   "max_attempts": 12,              // required (exit=12, entry=4)
 *   "first_delay_sec": 300           // optional: seconds until first retry (default 300)
 * }
 * Returns: { success, id } or { success:false, error }.
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
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON body']);
    exit;
}

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

$kind   = isset($input['kind']) ? strtolower(trim((string) $input['kind'])) : '';
$action = isset($input['action']) ? strtoupper(trim((string) $input['action'])) : '';
$ticker = isset($input['ticker']) ? strtoupper(trim((string) $input['ticker'])) : '';
if (!in_array($kind, ['entry', 'exit'], true) || $action === '' || $ticker === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing/invalid kind, action or ticker']);
    exit;
}
$max_attempts = isset($input['max_attempts']) ? (int) $input['max_attempts'] : 0;
if ($max_attempts < 1) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'max_attempts must be >= 1']);
    exit;
}

$price        = (isset($input['price']) && $input['price'] !== '' && $input['price'] !== null) ? (float) $input['price'] : null;
$strategy     = isset($input['strategy']) ? substr((string) $input['strategy'], 0, 128) : null;
$is_demo_only = !empty($input['is_demo_only']) ? 1 : 0;
$first_delay  = isset($input['first_delay_sec']) ? max(0, (int) $input['first_delay_sec']) : 300;

$target_uni_ids = null;
if (isset($input['target_uni_ids']) && is_array($input['target_uni_ids']) && count($input['target_uni_ids']) > 0) {
    $target_uni_ids = json_encode(array_values($input['target_uni_ids']));
}

require_once __DIR__ . '/../conn.php';
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$sql = "INSERT INTO capital_retry_queue
        (kind, action, ticker, price, strategy, target_uni_ids, is_demo_only,
         attempt_count, max_attempts, next_attempt_at, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), 'pending')";
$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}
mysqli_stmt_bind_param(
    $stmt,
    'sssdssiii',
    $kind, $action, $ticker, $price, $strategy, $target_uni_ids, $is_demo_only,
    $max_attempts, $first_delay
);
$ok = mysqli_stmt_execute($stmt);
$id = $ok ? mysqli_insert_id($conn) : 0;
mysqli_stmt_close($stmt);

if (!$ok) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

echo json_encode(['success' => true, 'id' => $id]);
mysqli_close($conn);
