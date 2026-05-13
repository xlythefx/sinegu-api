<?php
/**
 * Admin: set global enabled flag for a strategy (UPSERT).
 * POST JSON: { "strategy_key": "VWAP-Deviation", "enabled": 0 | 1 }
 */

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

if (!is_array($data) || !isset($data['strategy_key']) || !isset($data['enabled'])) {
    ob_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'strategy_key and enabled are required']);
    ob_end_flush();
    exit;
}

$key = trim((string) $data['strategy_key']);
$enabled = (int) (bool) $data['enabled'];

if ($key === '') {
    ob_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'strategy_key cannot be empty']);
    ob_end_flush();
    exit;
}

$stmt = mysqli_prepare(
    $conn,
    'INSERT INTO strategies (strategy_key, enabled) VALUES (?, ?)
     ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), updated_at = NOW()'
);
mysqli_stmt_bind_param($stmt, 'si', $key, $enabled);
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
