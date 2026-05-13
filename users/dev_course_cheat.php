<?php
/**
 * DEV-ONLY: course cheats. Gated by REMOTE_ADDR == localhost.
 * Do NOT expose on production hosts.
 *
 * Body: { "uni_id": "...", "level": "second", "action": "...", ...action-specific fields }
 *
 * Actions:
 *  - bump_elapsed       { module_index }                 → elapsed_seconds = 3700 (passes 1h gate)
 *  - age_started        { module_index, days?: 8 }       → started_at = NOW() - INTERVAL days DAY
 *  - finish_module      { module_index }                 → finished_at = NOW(), elapsed_seconds = 3700
 *  - reset_level        {}                               → delete all module_progress + section_inputs for level
 *  - fill_reflections   { required_keys_by_section }     → upsert placeholder values for every key
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }

// Localhost gate.
$remote = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($remote, ['127.0.0.1', '::1', 'localhost'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Dev cheats are localhost-only']);
    exit;
}

require_once __DIR__ . '/../conn.php';
if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
$uni_id = isset($input['uni_id']) ? trim((string) $input['uni_id']) : '';
$level = isset($input['level']) ? trim((string) $input['level']) : '';
$action = isset($input['action']) ? trim((string) $input['action']) : '';

$allowed_levels = ['first', 'second', 'third', 'fourth', 'fifth', 'sixth'];
if ($uni_id === '' || !in_array($level, $allowed_levels, true) || $action === '') {
    echo json_encode(['success' => false, 'message' => 'uni_id, level, action required']);
    exit;
}

function upsert_module_row($conn, $uni_id, $level, $module_index) {
    $stmt = mysqli_prepare($conn, "INSERT IGNORE INTO course_module_progress (uni_id, level, module_index, started_at) VALUES (?, ?, ?, NOW())");
    mysqli_stmt_bind_param($stmt, "ssi", $uni_id, $level, $module_index);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

switch ($action) {
    case 'bump_elapsed': {
        $mi = isset($input['module_index']) ? (int) $input['module_index'] : 0;
        if ($mi < 1) { echo json_encode(['success' => false, 'message' => 'module_index required']); exit; }
        upsert_module_row($conn, $uni_id, $level, $mi);
        $stmt = mysqli_prepare($conn, "UPDATE course_module_progress SET elapsed_seconds = 3700 WHERE uni_id=? AND level=? AND module_index=?");
        mysqli_stmt_bind_param($stmt, "ssi", $uni_id, $level, $mi);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        echo json_encode(['success' => true, 'action' => $action, 'module_index' => $mi]);
        exit;
    }
    case 'age_started': {
        $mi = isset($input['module_index']) ? (int) $input['module_index'] : 0;
        $days = isset($input['days']) ? max(1, (int) $input['days']) : 8;
        if ($mi < 1) { echo json_encode(['success' => false, 'message' => 'module_index required']); exit; }
        upsert_module_row($conn, $uni_id, $level, $mi);
        $stmt = mysqli_prepare($conn, "UPDATE course_module_progress SET started_at = NOW() - INTERVAL ? DAY WHERE uni_id=? AND level=? AND module_index=?");
        mysqli_stmt_bind_param($stmt, "issi", $days, $uni_id, $level, $mi);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        echo json_encode(['success' => true, 'action' => $action, 'module_index' => $mi, 'days' => $days]);
        exit;
    }
    case 'finish_module': {
        $mi = isset($input['module_index']) ? (int) $input['module_index'] : 0;
        if ($mi < 1) { echo json_encode(['success' => false, 'message' => 'module_index required']); exit; }
        upsert_module_row($conn, $uni_id, $level, $mi);
        $stmt = mysqli_prepare($conn, "UPDATE course_module_progress SET elapsed_seconds = 3700, finished_at = NOW() WHERE uni_id=? AND level=? AND module_index=?");
        mysqli_stmt_bind_param($stmt, "ssi", $uni_id, $level, $mi);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        echo json_encode(['success' => true, 'action' => $action, 'module_index' => $mi]);
        exit;
    }
    case 'reset_level': {
        $stmt = mysqli_prepare($conn, "DELETE FROM course_module_progress WHERE uni_id=? AND level=?");
        mysqli_stmt_bind_param($stmt, "ss", $uni_id, $level);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $stmt = mysqli_prepare($conn, "DELETE FROM course_section_inputs WHERE uni_id=? AND level=?");
        mysqli_stmt_bind_param($stmt, "ss", $uni_id, $level);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        echo json_encode(['success' => true, 'action' => $action]);
        exit;
    }
    case 'fill_reflections': {
        $map = isset($input['required_keys_by_section']) && is_array($input['required_keys_by_section'])
            ? $input['required_keys_by_section'] : [];
        $filled_sections = 0;
        foreach ($map as $section_id => $keys) {
            if (!is_string($section_id) || $section_id === '' || !is_array($keys)) continue;
            $payload = [];
            foreach ($keys as $k) {
                if (!is_string($k) || $k === '') continue;
                // Provide a generous placeholder so textarea min-lengths (50) also pass.
                $payload[$k] = "[devmode] placeholder for $k — long-enough text to clear the minimum length gate so the next module unlocks.";
            }
            if (!$payload) continue;
            $json = json_encode($payload);
            $stmt = mysqli_prepare(
                $conn,
                "INSERT INTO course_section_inputs (uni_id, level, section_id, inputs) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE inputs = VALUES(inputs)"
            );
            mysqli_stmt_bind_param($stmt, "ssss", $uni_id, $level, $section_id, $json);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $filled_sections++;
        }
        echo json_encode(['success' => true, 'action' => $action, 'filled_sections' => $filled_sections]);
        exit;
    }
    default:
        echo json_encode(['success' => false, 'message' => "Unknown action: $action"]);
        exit;
}
