<?php
/**
 * Delete IG open position row(s) from ig_positions.
 *
 * By deal id (one row):
 *   POST JSON: {"account_id": 1, "deal_id": "DIAAA..."}
 *
 * By ticker (all rows for that symbol):
 *   POST JSON: {"account_id": 1, "ticker": "XAUUSD"}
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
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use POST.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['account_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required field: account_id']);
    exit;
}

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$account_id = (int) $input['account_id'];
$deal_id_raw = isset($input['deal_id']) ? trim((string) $input['deal_id']) : '';

if ($deal_id_raw !== '') {
    $stmt = mysqli_prepare($conn, 'DELETE FROM ig_positions WHERE account_id = ? AND deal_id = ? LIMIT 1');
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
        mysqli_close($conn);
        exit;
    }
    mysqli_stmt_bind_param($stmt, 'is', $account_id, $deal_id_raw);
    $ok = mysqli_stmt_execute($stmt);
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    if (!$ok) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
        mysqli_close($conn);
        exit;
    }
} else {
    if (!isset($input['ticker'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Provide deal_id or ticker']);
        mysqli_close($conn);
        exit;
    }
    $ticker = strtoupper(trim((string) $input['ticker']));
    $stmt = mysqli_prepare($conn, 'DELETE FROM ig_positions WHERE account_id = ? AND ticker = ?');
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
        mysqli_close($conn);
        exit;
    }
    mysqli_stmt_bind_param($stmt, 'is', $account_id, $ticker);
    $ok = mysqli_stmt_execute($stmt);
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    if (!$ok) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
        mysqli_close($conn);
        exit;
    }
}

echo json_encode([
    'success' => true,
    'message' => 'IG positions deleted successfully',
    'deleted_rows' => (int) $affected,
]);

mysqli_close($conn);
