<?php
/**
 * Get capital transactions (deposits/withdrawals) for a specific account
 * GET: ?account_id=xxx
 * Returns: {"success": true, "transactions": [...]} or {"success": false, "error": "..."}
 */

// Turn off error display to prevent HTML output
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Start output buffering
ob_start();

// CORS headers - MUST be set before any output
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Max-Age: 86400'); // Cache preflight for 24 hours

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_clean();
    http_response_code(200);
    exit;
}

require_once '../conn.php';

$account_id = isset($_GET['account_id']) ? intval($_GET['account_id']) : null;
$limit = isset($_GET['limit']) ? max(1, min(100, intval($_GET['limit']))) : 100;
$offset = isset($_GET['offset']) ? max(0, intval($_GET['offset'])) : 0;

if (!$account_id || $account_id <= 0) {
    ob_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Missing or invalid required parameter: account_id'
    ]);
    exit;
}

if (!$conn) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database connection failed'
    ]);
    exit;
}

// Get total count first
$count_sql = "SELECT COUNT(*) as total FROM capital_transactions WHERE account_id = {$account_id}";
$count_result = mysqli_query($conn, $count_sql);
$total_count = 0;
if ($count_result) {
    $count_row = mysqli_fetch_assoc($count_result);
    $total_count = intval($count_row['total']);
}

// Query capital_transactions table for this account with pagination
// Order by created_at DESC (newest first)
$sql = "SELECT 
            id,
            account_id,
            uni_id,
            type,
            amount,
            balance_after,
            broker_reference,
            currency,
            created_at
        FROM capital_transactions
        WHERE account_id = {$account_id}
        ORDER BY created_at DESC
        LIMIT {$limit} OFFSET {$offset}";

$result = mysqli_query($conn, $sql);

if (!$result) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => mysqli_error($conn)
    ]);
    exit;
}

$transactions = [];
while ($row = mysqli_fetch_assoc($result)) {
    $transactions[] = [
        'id' => intval($row['id']),
        'account_id' => intval($row['account_id']),
        'uni_id' => $row['uni_id'],
        'type' => $row['type'],
        'amount' => floatval($row['amount']),
        'balance_after' => isset($row['balance_after']) && $row['balance_after'] !== null ? floatval($row['balance_after']) : null,
        'broker_reference' => isset($row['broker_reference']) ? $row['broker_reference'] : null,
        'currency' => isset($row['currency']) ? $row['currency'] : 'USD',
        'created_at' => $row['created_at']
    ];
}

// Clear output buffer before sending JSON
ob_clean();

echo json_encode([
    'success' => true,
    'transactions' => $transactions,
    'total' => $total_count,
    'count' => count($transactions),
    'limit' => $limit,
    'offset' => $offset,
    'has_more' => ($offset + count($transactions)) < $total_count
]);

mysqli_close($conn);
exit;
?>
