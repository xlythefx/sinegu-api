<?php
/**
 * POST: Start (or no-op) a module within a course.
 * Body: { "uni_id": "...", "level": "second", "module_index": 1 }
 * Response: { "success": true, "module": { ... } } or { "success": false, "message": "..." }
 *
 * If a row already exists for (uni_id, level, module_index), returns the existing row unchanged.
 * Otherwise inserts a new row with started_at = NOW(). This timestamp anchors the 7-day soft-unlock clock.
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

$allowed_levels = ['first', 'second', 'third', 'fourth', 'fifth', 'sixth'];
if ($uni_id === '' || !in_array($level, $allowed_levels, true) || $module_index < 1) {
    echo json_encode(['success' => false, 'message' => 'Invalid uni_id, level, or module_index']);
    exit;
}

$stmt = mysqli_prepare($conn, "SELECT id, started_at, finished_at, elapsed_seconds FROM course_module_progress WHERE uni_id = ? AND level = ? AND module_index = ?");
mysqli_stmt_bind_param($stmt, "ssi", $uni_id, $level, $module_index);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$existing = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if ($existing) {
    echo json_encode([
        'success' => true,
        'module' => [
            'id' => (int) $existing['id'],
            'module_index' => $module_index,
            'started_at' => $existing['started_at'],
            'finished_at' => $existing['finished_at'],
            'elapsed_seconds' => (int) $existing['elapsed_seconds'],
        ],
    ]);
    exit;
}

$started_at = date('Y-m-d H:i:s');
$stmt = mysqli_prepare($conn, "INSERT INTO course_module_progress (uni_id, level, module_index, started_at) VALUES (?, ?, ?, ?)");
mysqli_stmt_bind_param($stmt, "ssis", $uni_id, $level, $module_index, $started_at);
if (!mysqli_stmt_execute($stmt)) {
    echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    exit;
}
$row_id = (int) mysqli_insert_id($conn);
mysqli_stmt_close($stmt);

echo json_encode([
    'success' => true,
    'module' => [
        'id' => $row_id,
        'module_index' => $module_index,
        'started_at' => $started_at,
        'finished_at' => null,
        'elapsed_seconds' => 0,
    ],
]);
