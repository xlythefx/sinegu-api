<?php
/**
 * Bybit twin of delete_binance_position.php (the bybit_* tables are column-for-column Binance).
 * DELETE Bybit active position by id (admin active positions)
 * DELETE /admin/DELETE/delete_bybit_position.php?id=12
 * Or POST with body: { "id": 12 }
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: DELETE, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'DELETE' && $method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

$id = null;
if ($method === 'DELETE' && isset($_GET['id'])) {
    $id = (int) $_GET['id'];
} else {
    $raw = file_get_contents('php://input');
    $input = is_string($raw) ? json_decode($raw, true) : null;
    if (is_array($input) && isset($input['id'])) {
        $id = (int) $input['id'];
    }
}

if ($id === null || $id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Valid id is required (query param id= or JSON body {"id": ...})']);
    exit();
}

require_once __DIR__ . '/../../conn.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit();
}

$stmt = mysqli_prepare($conn, 'DELETE FROM bybit_positions WHERE id = ?');
if (!$stmt) {
    $err = mysqli_error($conn);
    mysqli_close($conn);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $err]);
    exit();
}

mysqli_stmt_bind_param($stmt, 'i', $id);
$ok = mysqli_stmt_execute($stmt);
$affected = mysqli_stmt_affected_rows($stmt);
mysqli_stmt_close($stmt);
mysqli_close($conn);

if (!$ok) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Delete failed']);
    exit();
}

if ($affected === 0) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Position not found']);
    exit();
}

echo json_encode([
    'success' => true,
    'message' => 'Position deleted',
    'id' => $id,
]);
