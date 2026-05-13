<?php
/**
 * POST: Update notes for an in-progress course tracker.
 * Body: { "uni_id": "...", "tracker_id": 123, "notes": "user notes text..." }
 * Response: { "success": true } or { "success": false, "message": "..." }
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
$notes = isset($input['notes']) ? (string) $input['notes'] : '';

if ($uni_id === '' || $tracker_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'uni_id and tracker_id required']);
    exit;
}

$stmt = mysqli_prepare($conn, "UPDATE course_tracker SET notes = ? WHERE id = ? AND uni_id = ? AND status = 'in_progress'");
mysqli_stmt_bind_param($stmt, "sis", $notes, $tracker_id, $uni_id);
mysqli_stmt_execute($stmt);
$affected = mysqli_affected_rows($conn);
mysqli_stmt_close($stmt);

if ($affected === 0) {
    echo json_encode(['success' => false, 'message' => 'Tracker not found or not in progress']);
    exit;
}

echo json_encode(['success' => true]);
