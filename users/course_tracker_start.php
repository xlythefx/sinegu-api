<?php
/**
 * POST: Start a course – insert row into course_tracker, duration from course_level_defaults.
 * Body: { "uni_id": "...", "level": "first" }
 * Response: { "success": true, "tracker_id": 1, "duration_minutes": 60, "started_at": "..." } or { "success": false, "message": "..." }
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

$allowed_levels = ['first', 'second', 'third', 'fourth', 'fifth', 'sixth'];
if ($uni_id === '' || !in_array($level, $allowed_levels, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid uni_id or level']);
    exit;
}

$stmt = mysqli_prepare($conn, "SELECT duration_minutes FROM course_level_defaults WHERE level = ?");
mysqli_stmt_bind_param($stmt, "s", $level);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$row = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);
if (!$row) {
    echo json_encode(['success' => false, 'message' => 'Level not found in course_level_defaults']);
    exit;
}
$duration_minutes = (int) $row['duration_minutes'];

$started_at = date('Y-m-d H:i:s');
$stmt = mysqli_prepare($conn, "INSERT INTO course_tracker (uni_id, level, status, duration_minutes, started_at) VALUES (?, ?, 'in_progress', ?, ?)");
mysqli_stmt_bind_param($stmt, "ssis", $uni_id, $level, $duration_minutes, $started_at);
if (!mysqli_stmt_execute($stmt)) {
    echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    exit;
}
$tracker_id = (int) mysqli_insert_id($conn);
mysqli_stmt_close($stmt);

$started_at_ts = strtotime($started_at);
if ($started_at_ts === false) {
    $started_at_ts = time();
}

echo json_encode([
    'success' => true,
    'tracker_id' => $tracker_id,
    'duration_minutes' => $duration_minutes,
    'started_at' => $started_at,
    'started_at_ts' => (int) $started_at_ts,
]);
