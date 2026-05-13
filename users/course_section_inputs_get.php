<?php
/**
 * GET: Stored inputs for one course section.
 * Query: uni_id=...&level=first&section_id=section-slug
 * Response: { "success": true, "inputs": { ... } | null }
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
$section_id = isset($_GET['section_id']) ? trim((string) $_GET['section_id']) : '';

$allowed_levels = ['first', 'second', 'third', 'fourth', 'fifth', 'sixth'];
if ($uni_id === '' || !in_array($level, $allowed_levels, true) || $section_id === '') {
    echo json_encode(['success' => false, 'message' => 'Invalid uni_id, level, or section_id']);
    exit;
}

$stmt = mysqli_prepare($conn, "SELECT inputs FROM course_section_inputs WHERE uni_id = ? AND level = ? AND section_id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, "sss", $uni_id, $level, $section_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$row = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$row) {
    echo json_encode(['success' => true, 'inputs' => null]);
    exit;
}

$decoded = json_decode((string) $row['inputs'], true);
echo json_encode(['success' => true, 'inputs' => is_array($decoded) ? $decoded : null]);
