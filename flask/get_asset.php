<?php
/**
 * Get single asset configuration by ticker
 * GET parameter: ticker (required)
 * Returns JSON: {"success": true, "asset": {...}}
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$ticker = isset($_GET['ticker']) ? trim(strtoupper($_GET['ticker'])) : null;

if (!$ticker) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Missing required parameter: ticker'
    ]);
    exit;
}

require_once '../conn.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database connection failed'
    ]);
    exit;
}

$ticker = mysqli_real_escape_string($conn, $ticker);
$sql = "SELECT asset_id, ticker, max_increments, base_size, enabled FROM assets WHERE ticker = '{$ticker}'";
$result = mysqli_query($conn, $sql);

if (!$result) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => mysqli_error($conn)
    ]);
    exit;
}

$asset = mysqli_fetch_assoc($result);

if (!$asset) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'error' => "Asset '{$ticker}' not found"
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'asset' => $asset
]);

mysqli_close($conn);
?>

