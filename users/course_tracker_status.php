<?php
/**
 * GET: Active timer for user+level.
 * Query: uni_id=...&level=first
 * Response: { "success": true, "tracker": { "tracker_id", "started_at", "duration_minutes" } } or { "success": true, "tracker": null }
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }

require_once __DIR__ . '/../conn.php';
if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$uni_id = isset($_GET['uni_id']) ? trim((string) $_GET['uni_id']) : '';
$level = isset($_GET['level']) ? trim((string) $_GET['level']) : '';

$allowed_levels = ['first', 'second', 'third', 'fourth', 'fifth', 'sixth'];
if ($uni_id === '' || !in_array($level, $allowed_levels, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid uni_id or level']);
    exit;
}

$stmt = mysqli_prepare($conn, "SELECT id AS tracker_id, started_at, duration_minutes, elapsed_seconds, notes FROM course_tracker WHERE uni_id = ? AND level = ? AND status = 'in_progress' ORDER BY started_at DESC LIMIT 1");
mysqli_stmt_bind_param($stmt, "ss", $uni_id, $level);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$row = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$row) {
    echo json_encode(['success' => true, 'tracker' => null]);
    exit;
}

$started_at_ts = strtotime($row['started_at']);
if ($started_at_ts === false) {
    $started_at_ts = time();
}

echo json_encode([
    'success' => true,
    'tracker' => [
        'tracker_id' => (int) $row['tracker_id'],
        'started_at' => $row['started_at'],
        'started_at_ts' => (int) $started_at_ts,
        'duration_minutes' => (int) $row['duration_minutes'],
        'elapsed_seconds' => (int) $row['elapsed_seconds'],
        'notes' => isset($row['notes']) && $row['notes'] !== null ? (string) $row['notes'] : null,
    ],
]);
