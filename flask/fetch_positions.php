<?php
/**
 * Fetch all positions grouped by account_id and ticker
 * Returns JSON: {"success": true, "positions": [{"account_id": 1, "ticker": "BTCUSD", "position_count": 3}, ...]}
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

$sql = "SELECT account_id, ticker, COUNT(*) AS position_count FROM positions GROUP BY account_id, ticker ORDER BY account_id, ticker";
$result = mysqli_query($conn, $sql);

if (!$result) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => mysqli_error($conn)
    ]);
    exit;
}

$positions = [];
while ($row = mysqli_fetch_assoc($result)) {
    $positions[] = $row;
}

echo json_encode([
    'success' => true,
    'positions' => $positions,
    'count' => count($positions)
]);

mysqli_close($conn);
?>

