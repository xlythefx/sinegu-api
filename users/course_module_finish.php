<?php
/**
 * POST: Finalize a module. Validates timer + reflection inputs, then sets finished_at.
 * Body: {
 *   "uni_id": "...",
 *   "level": "second",
 *   "module_index": 1,
 *   "required_keys_by_section": { "m1-intro": ["word","emotion","dream"], "m1-conclusion": ["reflection"], ... }
 * }
 * Response: { "success": true, "unlocked_next": true }
 *        or { "success": false, "message": "...", "reason": "elapsed_short" | "missing_inputs" | "not_started" }
 *
 * Gate (a): elapsed_seconds >= MIN_SECONDS_PER_MODULE (3600).
 * Gate (b): for every section_id listed in required_keys_by_section, course_section_inputs has a row
 *           with non-empty values for every named key.
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

const MIN_SECONDS_PER_MODULE = 3600;

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
$uni_id = isset($input['uni_id']) ? trim((string) $input['uni_id']) : '';
$level = isset($input['level']) ? trim((string) $input['level']) : '';
$module_index = isset($input['module_index']) ? (int) $input['module_index'] : 0;
$required = isset($input['required_keys_by_section']) && is_array($input['required_keys_by_section'])
    ? $input['required_keys_by_section']
    : [];

$allowed_levels = ['first', 'second', 'third', 'fourth', 'fifth', 'sixth'];
if ($uni_id === '' || !in_array($level, $allowed_levels, true) || $module_index < 1) {
    echo json_encode(['success' => false, 'message' => 'Invalid uni_id, level, or module_index']);
    exit;
}

// Load module row.
$stmt = mysqli_prepare($conn, "SELECT id, elapsed_seconds, finished_at FROM course_module_progress WHERE uni_id = ? AND level = ? AND module_index = ?");
mysqli_stmt_bind_param($stmt, "ssi", $uni_id, $level, $module_index);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$module = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$module) {
    echo json_encode(['success' => false, 'message' => 'Module not started', 'reason' => 'not_started']);
    exit;
}

if ($module['finished_at'] !== null) {
    // Idempotent: already finished.
    echo json_encode(['success' => true, 'unlocked_next' => true, 'already_finished' => true]);
    exit;
}

if ((int) $module['elapsed_seconds'] < MIN_SECONDS_PER_MODULE) {
    echo json_encode([
        'success' => false,
        'message' => 'Spend more time on this module',
        'reason' => 'elapsed_short',
        'elapsed_seconds' => (int) $module['elapsed_seconds'],
        'required_seconds' => MIN_SECONDS_PER_MODULE,
    ]);
    exit;
}

// Validate reflection inputs.
$missing = [];
foreach ($required as $section_id => $keys) {
    if (!is_string($section_id) || $section_id === '' || !is_array($keys) || count($keys) === 0) {
        continue;
    }
    $stmt = mysqli_prepare($conn, "SELECT inputs FROM course_section_inputs WHERE uni_id = ? AND level = ? AND section_id = ?");
    mysqli_stmt_bind_param($stmt, "sss", $uni_id, $level, $section_id);
    mysqli_stmt_execute($stmt);
    $r = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($r);
    mysqli_stmt_close($stmt);

    $stored = $row && isset($row['inputs']) ? json_decode($row['inputs'], true) : null;
    if (!is_array($stored)) {
        $missing[] = ['section_id' => $section_id, 'keys' => array_values($keys)];
        continue;
    }
    $missing_keys = [];
    foreach ($keys as $key) {
        if (!is_string($key) || $key === '') continue;
        $val = isset($stored[$key]) ? $stored[$key] : null;
        if ($val === null || (is_string($val) && trim($val) === '')) {
            $missing_keys[] = $key;
        }
    }
    if (count($missing_keys) > 0) {
        $missing[] = ['section_id' => $section_id, 'keys' => $missing_keys];
    }
}

if (count($missing) > 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Fill in all reflections before finishing the module',
        'reason' => 'missing_inputs',
        'missing' => $missing,
    ]);
    exit;
}

// Pass — mark finished.
$finished_at = date('Y-m-d H:i:s');
$module_id = (int) $module['id'];
$stmt = mysqli_prepare($conn, "UPDATE course_module_progress SET finished_at = ? WHERE id = ?");
mysqli_stmt_bind_param($stmt, "si", $finished_at, $module_id);
mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

echo json_encode(['success' => true, 'unlocked_next' => true, 'finished_at' => $finished_at]);
