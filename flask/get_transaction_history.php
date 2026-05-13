<?php
/**
 * Get transaction history for a user or account
 * GET parameters:
 *   - uni_id: UUID to filter by user_credentials.uni_id
 *   - account_id: Account ID to filter transactions for a specific account
 *   - email: Email to filter by user_credentials.email
 *   - limit: Maximum number of transactions to return (default: 100)
 *   - offset: Number of transactions to skip for pagination (default: 0)
 *   - order: Sort order - 'asc' or 'desc' (default: 'desc')
 * 
 * Returns JSON: {
 *   "success": true,
 *   "transactions": [...],
 *   "count": 10,
 *   "total": 25
 * }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
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

// Get query parameters
$uni_id = isset($_GET['uni_id']) ? mysqli_real_escape_string($conn, $_GET['uni_id']) : null;
$account_id = isset($_GET['account_id']) ? intval($_GET['account_id']) : null;
$email = isset($_GET['email']) ? mysqli_real_escape_string($conn, $_GET['email']) : null;
$type = isset($_GET['type']) ? mysqli_real_escape_string($conn, strtoupper(trim($_GET['type']))) : null;
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 100;
$offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
$order = isset($_GET['order']) && strtolower($_GET['order']) === 'asc' ? 'ASC' : 'DESC';

// Validate limit and offset
$limit = max(1, min(1000, $limit)); // Limit between 1 and 1000
$offset = max(0, $offset);

// Build WHERE clause based on provided filters
$where_conditions = [];

if ($account_id) {
    // Direct account_id filter - simplest case
    $where_conditions[] = "ct.account_id = {$account_id}";
} elseif ($uni_id) {
    // Filter by uni_id - join with accounts table
    $where_conditions[] = "a.uni_id = '{$uni_id}'";
} elseif ($email) {
    // Filter by email - join with user_credentials and accounts
    $where_conditions[] = "uc.email = '{$email}'";
} else {
    // No filter provided - return error
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Missing required parameter: uni_id, account_id, or email'
    ]);
    exit;
}

// Add type filter if provided
if ($type) {
    $where_conditions[] = "ct.type = '{$type}'";
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Build JOIN clauses if needed
$join_clauses = '';
if ($uni_id || $email) {
    $join_clauses = 'INNER JOIN accounts a ON ct.account_id = a.id';
    if ($email) {
        $join_clauses .= ' INNER JOIN user_credentials uc ON a.uni_id = uc.uni_id';
    }
}

// First, get total count
$count_sql = "SELECT COUNT(*) as total 
              FROM capital_transactions ct 
              {$join_clauses} 
              {$where_clause}";

$count_result = mysqli_query($conn, $count_sql);
$total_count = 0;
if ($count_result) {
    $count_row = mysqli_fetch_assoc($count_result);
    $total_count = intval($count_row['total']);
}

// Get transaction data with pagination
$sql = "SELECT 
            ct.id,
            ct.account_id,
            ct.uni_id,
            ct.type,
            ct.amount,
            ct.balance_after,
            ct.broker_reference,
            ct.currency,
            ct.created_at,
            a.name as account_name,
            a.email as account_email
        FROM capital_transactions ct
        {$join_clauses}
        {$where_clause}
        ORDER BY ct.created_at {$order}
        LIMIT {$limit} OFFSET {$offset}";

$result = mysqli_query($conn, $sql);

if (!$result) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => mysqli_error($conn)
    ]);
    exit;
}

$transactions = [];
while ($row = mysqli_fetch_assoc($result)) {
    // Format the transaction data
    $transactions[] = [
        'id' => intval($row['id']),
        'account_id' => intval($row['account_id']),
        'uni_id' => $row['uni_id'],
        'type' => $row['type'],
        'amount' => floatval($row['amount']),
        'balance_after' => isset($row['balance_after']) ? floatval($row['balance_after']) : null,
        'broker_reference' => isset($row['broker_reference']) ? $row['broker_reference'] : null,
        'currency' => isset($row['currency']) ? $row['currency'] : null,
        'created_at' => $row['created_at'],
        'account_name' => $row['account_name'],
        'account_email' => $row['account_email']
    ];
}

echo json_encode([
    'success' => true,
    'transactions' => $transactions,
    'count' => count($transactions),
    'total' => $total_count,
    'limit' => $limit,
    'offset' => $offset,
    'has_more' => ($offset + count($transactions)) < $total_count
]);

mysqli_close($conn);
?>

