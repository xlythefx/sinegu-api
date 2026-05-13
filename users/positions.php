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
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

require_once '../conn.php';

// Get uni_id from query parameter or header
$uni_id = isset($_GET['uni_id']) ? trim($_GET['uni_id']) : null;

if (empty($uni_id)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'uni_id parameter is required']);
    exit;
}

// Query positions by joining through accounts table using uni_id
$sql = "SELECT p.id, p.account_id, p.ticker, p.price, p.increments, p.updated_at, p.unrealized_pnl,
               a.name AS account_name, a.email AS account_email, a.uni_id AS account_uni_id
        FROM positions p
        INNER JOIN accounts a ON p.account_id = a.id
        WHERE a.uni_id = ?
        ORDER BY p.updated_at DESC";

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "s", $uni_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if (!$result) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to fetch positions']);
    mysqli_stmt_close($stmt);
    mysqli_close($conn);
    exit;
}

$positions = [];

while ($row = mysqli_fetch_assoc($result)) {
    $positions[] = $row;
}

mysqli_stmt_close($stmt);
mysqli_free_result($result);
mysqli_close($conn);

echo json_encode([
    'success' => true,
    'positions' => $positions,
    'count' => count($positions)
]);

?>

