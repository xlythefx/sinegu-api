<?php
/**
 * List tickers the user has disabled (user_disabled_assets).
 * GET: uni_id (required)
 * Returns: {"success": true, "tickers": ["BTCUSD", ...]}
 */

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

$uni_id = isset($_GET['uni_id']) ? trim($_GET['uni_id']) : '';

if ($uni_id === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'uni_id is required']);
    exit;
}

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$stmt = mysqli_prepare(
    $conn,
    'SELECT ticker FROM user_disabled_assets WHERE uni_id = ? ORDER BY ticker ASC'
);
mysqli_stmt_bind_param($stmt, 's', $uni_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$tickers = [];
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $tickers[] = $row['ticker'];
    }
}

mysqli_stmt_close($stmt);
mysqli_close($conn);

echo json_encode([
    'success' => true,
    'tickers' => $tickers,
]);
