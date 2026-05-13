<?php
/**
 * Flask: load global strategy enabled flags (same idea as fetch_assets).
 * GET — JSON: { "success": true, "strategies": [ { "strategy_key", "enabled" }, ... ] }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$sql = 'SELECT strategy_key, enabled FROM strategies ORDER BY strategy_key ASC';
$result = mysqli_query($conn, $sql);

if (!$result) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

$strategies = [];
while ($row = mysqli_fetch_assoc($result)) {
    $row['enabled'] = (int) $row['enabled'];
    $strategies[] = $row;
}

mysqli_free_result($result);
mysqli_close($conn);

echo json_encode([
    'success' => true,
    'strategies' => $strategies,
    'count' => count($strategies),
]);
