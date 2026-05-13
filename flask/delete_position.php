<?php
/**
 * Delete position row(s).
 *
 * By deal id (one row):
 *   POST JSON: {"account_id": 1, "deal_id": "006011e7-0001-54c4-0000-000080560043"}
 *
 * By ticker (all rows for that symbol — legacy / full exit cleanup):
 *   POST JSON: {"account_id": 1, "ticker": "BTCUSD"}
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed. Use POST.'
    ]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['account_id'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Missing required field: account_id'
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

$account_id = intval($input['account_id']);
$deal_id_raw = isset($input['deal_id']) ? trim((string) $input['deal_id']) : '';

if ($deal_id_raw !== '') {
    $deal_esc = mysqli_real_escape_string($conn, $deal_id_raw);
    $sql = "DELETE FROM positions WHERE account_id = {$account_id} AND deal_id = '{$deal_esc}'";
} else {
    if (!isset($input['ticker'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Provide deal_id or ticker'
        ]);
        exit;
    }
    $ticker = mysqli_real_escape_string($conn, strtoupper(trim($input['ticker'])));
    $sql = "DELETE FROM positions WHERE account_id = {$account_id} AND ticker = '{$ticker}'";
}

$result = mysqli_query($conn, $sql);

if (!$result) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => mysqli_error($conn)
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'Positions deleted successfully',
    'deleted_rows' => mysqli_affected_rows($conn),
]);

mysqli_close($conn);
