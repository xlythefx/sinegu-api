<?php
// Start output buffering to prevent any accidental output
ob_start();

// Suppress warnings
error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_clean();
    http_response_code(204);
    ob_end_flush();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
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

$sql = 'SELECT asset_id, ticker, type, broker, side, asset_image, max_increments, base_size, enabled, created_at, updated_at FROM assets ORDER BY ticker ASC';
$result = mysqli_query($conn, $sql);

if (!$result) {
    $errorMsg = mysqli_error($conn);
    mysqli_close($conn);
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to fetch assets: ' . $errorMsg]);
    ob_end_flush();
    exit;
}

$assets = [];
while ($row = mysqli_fetch_assoc($result)) {
    $row['asset_id'] = (int) $row['asset_id'];
    $row['max_increments'] = (float) $row['max_increments'];
    $row['base_size'] = (float) $row['base_size'];
    $row['enabled'] = (int) $row['enabled'];
    $assets[] = $row;
}

mysqli_free_result($result);
mysqli_close($conn);

ob_clean();
echo json_encode(['success' => true, 'assets' => $assets]);
ob_end_flush();
?>
