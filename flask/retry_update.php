<?php
/**
 * Report the outcome of a claimed capital_retry_queue row.
 * Bot-only. Secret shared with src/config.py CAPITAL_API_LOG_SECRET.
 *
 * POST JSON:
 * {
 *   "secret": "...",            // required
 *   "id": 123,                  // required (a row currently 'processing')
 *   "outcome": "reschedule",    // required: "executed" | "reschedule" | "failed"
 *   "delay_sec": 300,           // reschedule only: seconds until next attempt (default 300)
 *   "target_uni_ids": ["..."],  // reschedule only: accounts still pending (narrows the retry)
 *   "last_error": "..."         // optional
 * }
 *
 * On "reschedule": attempt_count++ and, if that reaches max_attempts, the row is
 * marked 'failed' (terminal) instead of 'pending' — never retried again.
 * Returns: { success, status } or { success:false, error }.
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

$id      = isset($input['id']) ? (int) $input['id'] : 0;
$outcome = isset($input['outcome']) ? strtolower(trim((string) $input['outcome'])) : '';
if ($id < 1 || !in_array($outcome, ['executed', 'reschedule', 'failed'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing/invalid id or outcome']);
    exit;
}
$last_error = isset($input['last_error']) ? (string) $input['last_error'] : null;

require_once __DIR__ . '/../conn.php';
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

if ($outcome === 'executed') {
    $stmt = mysqli_prepare(
        $conn,
        "UPDATE capital_retry_queue
            SET status='executed', last_error=?
          WHERE id=? AND status='processing'"
    );
    mysqli_stmt_bind_param($stmt, 'si', $last_error, $id);
} elseif ($outcome === 'failed') {
    $stmt = mysqli_prepare(
        $conn,
        "UPDATE capital_retry_queue
            SET status='failed', last_error=?
          WHERE id=? AND status='processing'"
    );
    mysqli_stmt_bind_param($stmt, 'si', $last_error, $id);
} else { // reschedule
    $delay = isset($input['delay_sec']) ? max(0, (int) $input['delay_sec']) : 300;
    $target_uni_ids = null;
    if (isset($input['target_uni_ids']) && is_array($input['target_uni_ids']) && count($input['target_uni_ids']) > 0) {
        $target_uni_ids = json_encode(array_values($input['target_uni_ids']));
    }
    // status set FIRST using (attempt_count + 1) so it reflects the post-increment count;
    // hitting max_attempts becomes terminal 'failed'.
    $stmt = mysqli_prepare(
        $conn,
        "UPDATE capital_retry_queue
            SET status = IF(attempt_count + 1 >= max_attempts, 'failed', 'pending'),
                attempt_count = attempt_count + 1,
                next_attempt_at = DATE_ADD(NOW(), INTERVAL ? SECOND),
                target_uni_ids = ?,
                last_error = ?
          WHERE id=? AND status='processing'"
    );
    mysqli_stmt_bind_param($stmt, 'issi', $delay, $target_uni_ids, $last_error, $id);
}

if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}
$ok = mysqli_stmt_execute($stmt);
$affected = mysqli_stmt_affected_rows($stmt);
mysqli_stmt_close($stmt);

if (!$ok) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

// Read back the resulting status (handles reschedule -> failed transition).
$status = null;
$q = mysqli_prepare($conn, "SELECT status FROM capital_retry_queue WHERE id=?");
if ($q) {
    mysqli_stmt_bind_param($q, 'i', $id);
    if (mysqli_stmt_execute($q)) {
        $sr = mysqli_fetch_assoc(mysqli_stmt_get_result($q));
        if ($sr) { $status = $sr['status']; }
    }
    mysqli_stmt_close($q);
}

echo json_encode(['success' => true, 'status' => $status, 'updated' => $affected]);
mysqli_close($conn);
