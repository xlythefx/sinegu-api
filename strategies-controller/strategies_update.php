<?php
/**
 * Admin: set global enabled flag and/or description for a strategy (UPSERT).
 * POST JSON: { "strategy_key": "VWAP-Deviation", "enabled": 0 | 1, "description": "…" }
 * Both `enabled` and `description` are optional; only the keys present are written,
 * so a description save cannot flip the toggle and a toggle cannot wipe the text.
 * Sending "description": "" (or null) clears it.
 */

const DESCRIPTION_MAX_LEN = 4000;

ob_start();

error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_clean();
    http_response_code(204);
    ob_end_flush();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_clean();
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    ob_end_flush();
    exit;
}

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    ob_end_flush();
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!is_array($data) || !isset($data['strategy_key'])) {
    ob_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'strategy_key is required']);
    ob_end_flush();
    exit;
}

$key = trim((string) $data['strategy_key']);

if ($key === '') {
    ob_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'strategy_key cannot be empty']);
    ob_end_flush();
    exit;
}

// Both fields are optional and updated independently — a description-only save must not
// flip a paused strategy back on, and a toggle must not wipe the description.
$hasEnabled     = array_key_exists('enabled', $data);
$hasDescription = array_key_exists('description', $data);

if (!$hasEnabled && !$hasDescription) {
    ob_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Provide at least one of: enabled, description']);
    ob_end_flush();
    exit;
}

// New rows inherit the table default (enabled = 1) when only a description is sent.
$enabled = $hasEnabled ? (int) (bool) $data['enabled'] : 1;

$description = null;
if ($hasDescription) {
    if ($data['description'] !== null) {
        $description = trim((string) $data['description']);
        if (mb_strlen($description) > DESCRIPTION_MAX_LEN) {
            ob_clean();
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Description is too long (max ' . DESCRIPTION_MAX_LEN . ' characters)',
            ]);
            ob_end_flush();
            exit;
        }
        // An empty box clears the description rather than storing "".
        if ($description === '') {
            $description = null;
        }
    }

    // Guard the column so an unmigrated box says so instead of failing with a raw SQL error.
    $colCheck = mysqli_query(
        $conn,
        "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'strategies' AND COLUMN_NAME = 'description'"
    );
    $colRow = $colCheck ? mysqli_fetch_assoc($colCheck) : null;
    if ($colCheck) {
        mysqli_free_result($colCheck);
    }

    if (!$colRow || (int) $colRow['c'] === 0) {
        mysqli_close($conn);
        ob_clean();
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'strategies.description column is missing — run migration 086_add_strategies_description.sql',
            'error_code' => 'STRATEGIES_DESCRIPTION_COLUMN_MISSING',
        ]);
        ob_end_flush();
        exit;
    }
}

$stmt = mysqli_prepare(
    $conn,
    'INSERT INTO strategies (strategy_key, enabled, description) VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE
       enabled     = IF(?, VALUES(enabled), enabled),
       description = IF(?, VALUES(description), description),
       updated_at  = NOW()'
);

if (!$stmt) {
    $err = mysqli_error($conn);
    mysqli_close($conn);
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $err ?: 'Update failed']);
    ob_end_flush();
    exit;
}

$enabledFlag = $hasEnabled ? 1 : 0;
$descFlag    = $hasDescription ? 1 : 0;

mysqli_stmt_bind_param($stmt, 'sisii', $key, $enabled, $description, $enabledFlag, $descFlag);
$ok = mysqli_stmt_execute($stmt);
$err = mysqli_stmt_error($stmt);
mysqli_stmt_close($stmt);
mysqli_close($conn);

if (!$ok) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $err ?: 'Update failed']);
    ob_end_flush();
    exit;
}

ob_clean();
echo json_encode(['success' => true]);
ob_end_flush();
