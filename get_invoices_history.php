<?php
// Turn off error display to prevent HTML output before JSON
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

// Database configuration
require_once __DIR__ . '/conn.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database connection failed'
    ]);
    exit();
}

// Query to get all billing periods with user and account information
// Join billing_periods with user_credentials on user_id = uni_id
// Join billing_periods with accounts on account_id = accounts.id
$query = "
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
    ORDER BY bp.created_at DESC
";

$result = mysqli_query($conn, $query);

if (!$result) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database query failed: ' . mysqli_error($conn)
    ]);
    exit();
}

// Format the response
$invoices = [];
while ($row = mysqli_fetch_assoc($result)) {
    $invoices[] = [
        'id' => (int)$row['id'],
        'user_id' => $row['user_id'],
        'user_name' => $row['user_name'],
        'user_email' => $row['user_email'],
        'account_id' => (int)$row['account_id'],
        'account_name' => $row['account_name'],
        'account_email' => $row['account_email'],
        'currency_type' => $row['currency_type'] ?? 'USD',
        'month_year' => $row['month_year'],
        'total_fee' => (float)$row['total_fee'],
        'realized_pnl' => (float)($row['realized_pnl'] ?? 0),
        'unrealized_pnl' => (float)($row['unrealized_pnl'] ?? 0),
        'fee_realized' => (float)($row['fee_realized'] ?? 0),
        'fee_unrealized' => (float)($row['fee_unrealized'] ?? 0),
        'status' => $row['status'] ?? 'pending',
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at']
    ];
}

echo json_encode([
    'success' => true,
    'invoices' => $invoices
]);
?>

