<?php
/**
 * GET single billing period by id (admin invoice edit)
 * GET /admin/GET/get_billing_period.php?id=184
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Valid id is required']);
    exit();
}

require_once __DIR__ . '/../../conn.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit();
}

$stmt = mysqli_prepare($conn, "
    SELECT 
        bp.id,
        bp.user_id,
        uc.name AS user_name,
        uc.email AS user_email,
        bp.account_id,
        acc.name AS account_name,
        acc.email AS account_email,
        acc.currency_type,
        bp.month_year,
        bp.total_fee,
        bp.realized_pnl,
        bp.unrealized_pnl,
        bp.fee_realized,
        bp.fee_unrealized,
        bp.status,
        bp.created_at,
        bp.updated_at
    FROM billing_periods bp
    INNER JOIN user_credentials uc ON bp.user_id = uc.uni_id
    INNER JOIN accounts acc ON bp.account_id = acc.id
    WHERE bp.id = ?
");
if (!$stmt) {
    mysqli_close($conn);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Query failed: ' . mysqli_error($conn)]);
    exit();
}

mysqli_stmt_bind_param($stmt, 'i', $id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$row = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);
mysqli_close($conn);

if (!$row) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Billing period not found']);
    exit();
}

$invoice = [
    'id' => (int) $row['id'],
    'user_id' => $row['user_id'],
    'user_name' => $row['user_name'],
    'user_email' => $row['user_email'],
    'account_id' => (int) $row['account_id'],
    'account_name' => $row['account_name'],
    'account_email' => $row['account_email'],
    'currency_type' => $row['currency_type'] ?? 'USD',
    'month_year' => $row['month_year'],
    'total_fee' => (float) $row['total_fee'],
    'realized_pnl' => (float) ($row['realized_pnl'] ?? 0),
    'unrealized_pnl' => (float) ($row['unrealized_pnl'] ?? 0),
    'fee_realized' => (float) ($row['fee_realized'] ?? 0),
    'fee_unrealized' => (float) ($row['fee_unrealized'] ?? 0),
    'status' => $row['status'] ?? 'pending',
    'created_at' => $row['created_at'],
    'updated_at' => $row['updated_at'],
];

echo json_encode([
    'success' => true,
    'invoice' => $invoice,
]);
