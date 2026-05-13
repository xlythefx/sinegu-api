<?php
/**
 * Get position count for specific account and ticker
 * GET params: account_id (required), ticker (required)
 * Returns JSON: {"success": true, "count": 5}
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$account_id = isset($_GET['account_id']) ? intval($_GET['account_id']) : null;
$ticker = isset($_GET['ticker']) ? trim(strtoupper($_GET['ticker'])) : null;

if (!$account_id || !$ticker) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Missing required parameters: account_id and ticker'
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

$account_id = mysqli_real_escape_string($conn, $account_id);
$ticker = mysqli_real_escape_string($conn, $ticker);
$sql = "SELECT COUNT(*) AS position_count FROM positions WHERE account_id = {$account_id} AND ticker = '{$ticker}'";
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
$count = intval($row['position_count'] ?? 0);

echo json_encode([
    'success' => true,
    'count' => $count
]);

mysqli_close($conn);
?>

