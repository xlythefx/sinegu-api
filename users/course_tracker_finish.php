<?php
/**
 * POST: Finish a course – set course_tracker row to finished, advance user_credentials.course_level.
 * Body: { "uni_id": "...", "tracker_id": 123 } or { "uni_id": "...", "level": "first" }
 * Response: { "success": true, "next_level": "second" } or { "success": false, "message": "..." }
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
$tracker_id = isset($input['tracker_id']) ? (int) $input['tracker_id'] : 0;
$level = isset($input['level']) ? trim((string) $input['level']) : '';

$allowed_levels = ['first', 'second', 'third', 'fourth', 'fifth', 'sixth'];

if ($uni_id === '') {
    echo json_encode(['success' => false, 'message' => 'uni_id required']);
    exit;
}

mysqli_begin_transaction($conn);

if ($tracker_id > 0) {
    $stmt = mysqli_prepare($conn, "SELECT id, level FROM course_tracker WHERE id = ? AND uni_id = ? AND status = 'in_progress'");
    mysqli_stmt_bind_param($stmt, "is", $tracker_id, $uni_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
} elseif (in_array($level, $allowed_levels, true)) {
    $stmt = mysqli_prepare($conn, "SELECT id, level FROM course_tracker WHERE uni_id = ? AND level = ? AND status = 'in_progress' ORDER BY started_at DESC LIMIT 1");
    mysqli_stmt_bind_param($stmt, "ss", $uni_id, $level);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
} else {
    mysqli_rollback($conn);
    echo json_encode(['success' => false, 'message' => 'Provide tracker_id or level']);
    exit;
}

if (!$row) {
    mysqli_rollback($conn);
    echo json_encode(['success' => false, 'message' => 'No in-progress course found']);
    exit;
}

$finished_level = $row['level'];
$finish_id = (int) $row['id'];
$finished_at = date('Y-m-d H:i:s');

$stmt = mysqli_prepare($conn, "UPDATE course_tracker SET status = 'finished', finished_at = ? WHERE id = ?");
mysqli_stmt_bind_param($stmt, "si", $finished_at, $finish_id);
mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

$order = ['first' => 'second', 'second' => 'third', 'third' => 'fourth', 'fourth' => 'fifth', 'fifth' => 'sixth', 'sixth' => 'sixth'];
$next_level = $order[$finished_level] ?? 'sixth';

$stmt = mysqli_prepare($conn, "UPDATE user_credentials SET course_level = ? WHERE uni_id = ?");
mysqli_stmt_bind_param($stmt, "ss", $next_level, $uni_id);
mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

mysqli_commit($conn);
echo json_encode(['success' => true, 'next_level' => $next_level]);
