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

// Query trades by joining through accounts table using uni_id
$sql = "SELECT t.id, t.account_id, t.ticker, t.price, t.pnl, t.pnl_percentage, t.closed_positions, 
               t.date_closed, t.time_closed, t.created_at, t.strategy,
               a.name AS account_name, a.email AS account_email, a.uni_id AS account_uni_id
        FROM trades t
        INNER JOIN accounts a ON t.account_id = a.id
        WHERE a.uni_id = ?
        ORDER BY t.created_at DESC";

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "s", $uni_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if (!$result) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to fetch trades']);
    mysqli_stmt_close($stmt);
    mysqli_close($conn);
    exit;
}

$trades = [];

while ($row = mysqli_fetch_assoc($result)) {
    $row['pnl'] = (float) $row['pnl'];
    $row['pnl_percentage'] = (float) $row['pnl_percentage'];
    $row['closed_positions'] = (int) $row['closed_positions'];
    $trades[] = $row;
}

mysqli_stmt_close($stmt);
mysqli_free_result($result);
mysqli_close($conn);

echo json_encode([
    'success' => true,
    'trades' => $trades,
    'count' => count($trades)
]);

?>

