<?php
/**
 * Flask: check if a user has disabled trading for a ticker (user_disabled_assets row exists).
 * GET params: ticker (required), and one of: uni_id OR account_id (resolves uni_id from accounts.id)
 * Returns JSON: {"success": true, "disabled": true|false}
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$uni_id = isset($_GET['uni_id']) ? trim($_GET['uni_id']) : '';
$account_id = isset($_GET['account_id']) ? (int) $_GET['account_id'] : 0;
$ticker = isset($_GET['ticker']) ? trim(strtoupper($_GET['ticker'])) : '';

if ($ticker === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Missing required parameter: ticker',
    ]);
    exit;
}

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

if ($uni_id === '' && $account_id > 0) {
    $stmtAcc = mysqli_prepare($conn, 'SELECT uni_id FROM accounts WHERE id = ? AND (deleted_at IS NULL) LIMIT 1');
    mysqli_stmt_bind_param($stmtAcc, 'i', $account_id);
    mysqli_stmt_execute($stmtAcc);
    $resAcc = mysqli_stmt_get_result($stmtAcc);
    $rowAcc = $resAcc ? mysqli_fetch_assoc($resAcc) : null;
    mysqli_stmt_close($stmtAcc);
    if ($rowAcc && !empty($rowAcc['uni_id'])) {
        $uni_id = trim((string) $rowAcc['uni_id']);
    }
}

if ($uni_id === '') {
    mysqli_close($conn);
    echo json_encode([
        'success' => true,
        'disabled' => false,
    ]);
    exit;
}

$stmt = mysqli_prepare(
    $conn,
    'SELECT 1 FROM user_disabled_assets WHERE uni_id = ? AND ticker = ? LIMIT 1'
);
mysqli_stmt_bind_param($stmt, 'ss', $uni_id, $ticker);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$disabled = $res && mysqli_fetch_assoc($res) !== null;
mysqli_stmt_close($stmt);
mysqli_close($conn);

echo json_encode([
    'success' => true,
    'disabled' => $disabled,
]);
