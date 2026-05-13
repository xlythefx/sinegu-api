<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed',
    ]);
    exit;
}

require_once __DIR__ . '/conn.php';

$sql = 'SELECT p.id, p.account_id, p.ticker, p.price, p.increments, p.updated_at, p.unrealized_pnl, p.strategy, p.side, a.name AS account_name, a.email AS account_email FROM positions p LEFT JOIN accounts a ON p.account_id = a.id ORDER BY p.updated_at DESC';
$result = mysqli_query($conn, $sql);

if (!$result) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to fetch positions',
    ]);
    exit;
}

$positions = [];

while ($row = mysqli_fetch_assoc($result)) {
    $positions[] = $row;
}

mysqli_free_result($result);
mysqli_close($conn);

echo json_encode([
    'success' => true,
    'positions' => $positions,
]);

