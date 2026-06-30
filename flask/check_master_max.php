<?php
/**
 * Check if the master account has reached max increments for a ticker.
 * Master is the account whose name is 'master' (NOT a hardcoded id).
 * GET params: ticker (required)
 * Returns JSON: {"success": true, "is_blocked": false, "master_count": 5, "max_increments": 10}
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

// Get master account position count. Master is resolved by name='master', joining
// positions -> accounts, so renumbered/reinserted accounts can't break the gate.
$ticker = mysqli_real_escape_string($conn, $ticker);
$sql = "SELECT COUNT(*) AS position_count
        FROM positions p
        INNER JOIN accounts a ON a.id = p.account_id
        WHERE a.name = 'master' AND p.ticker = '{$ticker}'";
$result = mysqli_query($conn, $sql);

if (!$result) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => mysqli_error($conn)
    ]);
    exit;
}

$row = mysqli_fetch_assoc($result);
$master_count = intval($row['position_count'] ?? 0);

// Get max increments from assets table
$asset_sql = "SELECT max_increments FROM assets WHERE ticker = '{$ticker}'";
$asset_result = mysqli_query($conn, $asset_sql);
$max_increments = 10; // default
if ($asset_result) {
    $asset_row = mysqli_fetch_assoc($asset_result);
    if ($asset_row) {
        $max_increments = intval($asset_row['max_increments'] ?? 10);
    }
}

$is_blocked = $master_count >= $max_increments;

echo json_encode([
    'success' => true,
    'is_blocked' => $is_blocked,
    'master_count' => $master_count,
    'max_increments' => $max_increments
]);

mysqli_close($conn);
?>

