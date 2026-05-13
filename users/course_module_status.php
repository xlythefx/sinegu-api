<?php
/**
 * GET: All module rows for (uni_id, level) + computed unlock list.
 * Query: uni_id=...&level=second
 * Response: {
 *   "success": true,
 *   "modules": [
 *     { "module_index": 1, "started_at": "...", "started_at_ts": 0, "finished_at": null,
 *       "elapsed_seconds": 120, "soft_unlock_at": "...", "soft_unlock_at_ts": 0 },
 *     ...
 *   ],
 *   "unlocked_modules": [1, 2, ...],
 *   "required_seconds": 3600,
 *   "soft_unlock_seconds": 604800
 * }
 *
 * Unlock rule: module 1 is always unlocked. Module N+1 is unlocked iff
 *   module N has finished_at != NULL  OR  NOW() - module N.started_at >= 7 days.
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

const MIN_SECONDS_PER_MODULE = 3600;
const SOFT_UNLOCK_SECONDS = 604800; // 7 days

$uni_id = isset($_GET['uni_id']) ? trim((string) $_GET['uni_id']) : '';
$level = isset($_GET['level']) ? trim((string) $_GET['level']) : '';

$allowed_levels = ['first', 'second', 'third', 'fourth', 'fifth', 'sixth'];
if ($uni_id === '' || !in_array($level, $allowed_levels, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid uni_id or level']);
    exit;
}

$stmt = mysqli_prepare(
    $conn,
    "SELECT module_index, started_at, finished_at, elapsed_seconds
     FROM course_module_progress WHERE uni_id = ? AND level = ? ORDER BY module_index ASC"
);
mysqli_stmt_bind_param($stmt, "ss", $uni_id, $level);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$rows = [];
while ($r = mysqli_fetch_assoc($result)) {
    $rows[] = $r;
}
mysqli_stmt_close($stmt);

$now_ts = time();
$by_index = [];
$modules = [];
foreach ($rows as $r) {
    $mi = (int) $r['module_index'];
    $started_ts = strtotime($r['started_at']);
    if ($started_ts === false) $started_ts = $now_ts;
    $aged = ($now_ts - $started_ts) >= SOFT_UNLOCK_SECONDS;
    $finished_at = $r['finished_at'];

    // Auto-finish safety valve: if 7 days have passed and the user never manually
    // finished the module, treat it as finished anyway (anchored to the 7-day mark).
    if ($finished_at === null && $aged) {
        $finished_at = date('Y-m-d H:i:s', $started_ts + SOFT_UNLOCK_SECONDS);
        $stmt = mysqli_prepare(
            $conn,
            "UPDATE course_module_progress SET finished_at = ?
             WHERE uni_id = ? AND level = ? AND module_index = ? AND finished_at IS NULL"
        );
        mysqli_stmt_bind_param($stmt, "sssi", $finished_at, $uni_id, $level, $mi);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }

    $soft_ts = $started_ts + SOFT_UNLOCK_SECONDS;
    $by_index[$mi] = [
        'module_index' => $mi,
        'started_at' => $r['started_at'],
        'started_at_ts' => $started_ts,
        'finished_at' => $finished_at,
        'elapsed_seconds' => (int) $r['elapsed_seconds'],
        'soft_unlock_at' => date('Y-m-d H:i:s', $soft_ts),
        'soft_unlock_at_ts' => $soft_ts,
    ];
    $modules[] = $by_index[$mi];
}

// Unlock rule: module 1 is always unlocked. Module N+1 unlocks iff module N has
// existed for >= 7 days. Finishing module N early (1h timer + reflections) does
// NOT accelerate this — it just marks the module as user-completed in the DB.
$unlocked = [1];
$cursor = 1;
while (true) {
    $row = isset($by_index[$cursor]) ? $by_index[$cursor] : null;
    if (!$row) break;
    $aged = ($now_ts - $row['started_at_ts']) >= SOFT_UNLOCK_SECONDS;
    if (!$aged) break;
    $cursor++;
    $unlocked[] = $cursor;
}

echo json_encode([
    'success' => true,
    'modules' => $modules,
    'unlocked_modules' => $unlocked,
    'required_seconds' => MIN_SECONDS_PER_MODULE,
    'soft_unlock_seconds' => SOFT_UNLOCK_SECONDS,
]);
