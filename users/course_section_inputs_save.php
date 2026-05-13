<?php
/**
 * POST: Upsert inputs for one course section.
 * Body: { "uni_id": "...", "level": "second", "section_id": "...", "inputs": { ... } }
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
$level = isset($input['level']) ? trim((string) $input['level']) : '';
$section_id = isset($input['section_id']) ? trim((string) $input['section_id']) : '';
$inputs = isset($input['inputs']) && is_array($input['inputs']) ? $input['inputs'] : null;

$allowed_levels = ['first', 'second', 'third', 'fourth', 'fifth', 'sixth'];
if ($uni_id === '' || !in_array($level, $allowed_levels, true) || $section_id === '' || $inputs === null) {
    echo json_encode(['success' => false, 'message' => 'uni_id, level, section_id, inputs required']);
    exit;
}

$inputs_json = json_encode($inputs);
if ($inputs_json === false) {
    echo json_encode(['success' => false, 'message' => 'Failed to encode inputs']);
    exit;
}

$stmt = mysqli_prepare(
    $conn,
    "INSERT INTO course_section_inputs (uni_id, level, section_id, inputs) VALUES (?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE inputs = VALUES(inputs)"
);
mysqli_stmt_bind_param($stmt, "ssss", $uni_id, $level, $section_id, $inputs_json);
$ok = mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

if (!$ok) {
    echo json_encode(['success' => false, 'message' => 'Failed to save']);
    exit;
}

echo json_encode(['success' => true]);
