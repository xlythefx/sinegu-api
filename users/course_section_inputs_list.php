<?php
/**
 * GET: All saved section inputs for a user+level (used by Mind Freedom aggregation page).
 * Query: uni_id=...&level=second
 * Response: { "success": true, "items": { "<section_id>": { ... } } }
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

$stmt = mysqli_prepare($conn, "SELECT section_id, inputs FROM course_section_inputs WHERE uni_id = ? AND level = ?");
mysqli_stmt_bind_param($stmt, "ss", $uni_id, $level);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$items = [];
while ($row = mysqli_fetch_assoc($result)) {
    $decoded = json_decode((string) $row['inputs'], true);
    if (is_array($decoded)) {
        $items[(string) $row['section_id']] = $decoded;
    }
}
mysqli_stmt_close($stmt);

echo json_encode(['success' => true, 'items' => (object) $items]);
