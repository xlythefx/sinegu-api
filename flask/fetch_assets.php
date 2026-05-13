<?php
/**
 * Fetch all assets configuration from database
 * Returns JSON: {"success": true, "assets": [...]}
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
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

$sql = "SELECT asset_id, ticker, max_increments, base_size, enabled FROM assets WHERE enabled = 1 AND LOWER(TRIM(COALESCE(broker, ''))) = 'capital' ORDER BY ticker";
$result = mysqli_query($conn, $sql);

if (!$result) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => mysqli_error($conn)
    ]);
    exit;
}

$assets = [];
while ($row = mysqli_fetch_assoc($result)) {
    $assets[] = $row;
}

if (empty($assets)) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'error' => 'No assets found in database'
    ]);
    exit;
}

// Log fetched assets
error_log("📊 Assets fetched from database: " . json_encode($assets));

echo json_encode([
    'success' => true,
    'assets' => $assets,
    'count' => count($assets)
]);

mysqli_close($conn);
?>

