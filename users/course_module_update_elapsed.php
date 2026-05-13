<?php
/**
 * POST: Update elapsed_seconds for a module row.
 * Body: { "uni_id": "...", "level": "second", "module_index": 1, "elapsed_seconds": 90 }
 * Response: { "success": true, "elapsed_seconds": 90 } or { "success": false, "message": "..." }
 *
 * Stores monotonic max — never overwrites a higher value with a lower one
 * (protects against stale flushes from a backgrounded tab).
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }

require_once __DIR__ . '/../conn.php';
if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
$uni_id = isset($input['uni_id']) ? trim((string) $input['uni_id']) : '';
$level = isset($input['level']) ? trim((string) $input['level']) : '';
$module_index = isset($input['module_index']) ? (int) $input['module_index'] : 0;
$elapsed_seconds = isset($input['elapsed_seconds']) ? (int) $input['elapsed_seconds'] : 0;

$allowed_levels = ['first', 'second', 'third', 'fourth', 'fifth', 'sixth'];
if ($uni_id === '' || !in_array($level, $allowed_levels, true) || $module_index < 1) {
    echo json_encode(['success' => false, 'message' => 'Invalid uni_id, level, or module_index']);
    exit;
}
if ($elapsed_seconds < 0) {
    $elapsed_seconds = 0;
}

$stmt = mysqli_prepare(
    $conn,
    "UPDATE course_module_progress SET elapsed_seconds = GREATEST(elapsed_seconds, ?)
     WHERE uni_id = ? AND level = ? AND module_index = ?"
);
mysqli_stmt_bind_param($stmt, "issi", $elapsed_seconds, $uni_id, $level, $module_index);
mysqli_stmt_execute($stmt);
$affected = mysqli_affected_rows($conn);
mysqli_stmt_close($stmt);

if ($affected === -1) {
    echo json_encode(['success' => false, 'message' => 'Failed to update elapsed_seconds']);
    exit;
}

// Read back the current value so the client can sync.
$stmt = mysqli_prepare($conn, "SELECT elapsed_seconds FROM course_module_progress WHERE uni_id = ? AND level = ? AND module_index = ?");
mysqli_stmt_bind_param($stmt, "ssi", $uni_id, $level, $module_index);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$row = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$row) {
    echo json_encode(['success' => false, 'message' => 'Module not started']);
    exit;
}

echo json_encode(['success' => true, 'elapsed_seconds' => (int) $row['elapsed_seconds']]);
