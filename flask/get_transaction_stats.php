<?php
/**
 * Get transaction statistics for an account
 * GET parameters:
 *   - account_id: Account ID (required)
 *   - from_date: Start date (YYYY-MM-DD, optional)
 *   - to_date: End date (YYYY-MM-DD, optional)
 * 
 * Returns JSON: {
 *   "success": true,
 *   "stats": {
 *     "total_transactions": 50,
 *     "total_deposits": 5000.00,
 *     "total_withdrawals": -2000.00,
 *     "net_amount": 3000.00,
 *     "by_type": {...}
 *   }
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

// Get parameters
$account_id = isset($_GET['account_id']) ? intval($_GET['account_id']) : null;
$from_date = isset($_GET['from_date']) ? mysqli_real_escape_string($conn, $_GET['from_date']) : null;
$to_date = isset($_GET['to_date']) ? mysqli_real_escape_string($conn, $_GET['to_date']) : null;

if (!$account_id) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Missing required parameter: account_id'
    ]);
    exit;
}

// Build WHERE conditions
$where_conditions = ["account_id = {$account_id}"];

if ($from_date) {
    $where_conditions[] = "DATE(created_at) >= '{$from_date}'";
}

if ($to_date) {
    $where_conditions[] = "DATE(created_at) <= '{$to_date}'";
}

$where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

// Get total count
$count_sql = "SELECT COUNT(*) as total FROM capital_transactions {$where_clause}";
$count_result = mysqli_query($conn, $count_sql);
$total_count = 0;
if ($count_result) {
    $count_row = mysqli_fetch_assoc($count_result);
    $total_count = intval($count_row['total']);
}

// Get sum by type
$stats_sql = "SELECT 
                type,
                COUNT(*) as count,
                SUM(amount) as total_amount
              FROM capital_transactions 
              {$where_clause}
              GROUP BY type";

$stats_result = mysqli_query($conn, $stats_sql);

if (!$stats_result) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => mysqli_error($conn)
    ]);
    exit;
}

$by_type = [];
$total_deposits = 0.0;
$total_withdrawals = 0.0;

while ($row = mysqli_fetch_assoc($stats_result)) {
    $type = $row['type'];
    $count = intval($row['count']);
    $total = floatval($row['total_amount']);
    
    $by_type[$type] = [
        'count' => $count,
        'total_amount' => $total
    ];
    
    // Categorize as deposit or withdrawal
    if (in_array(strtolower($type), ['deposit', 'funding', 'transfer_in']) || $total > 0) {
        $total_deposits += $total;
    } else {
        $total_withdrawals += $total;
    }
}

$net_amount = $total_deposits + $total_withdrawals; // withdrawals are negative

echo json_encode([
    'success' => true,
    'stats' => [
        'total_transactions' => $total_count,
        'total_deposits' => $total_deposits,
        'total_withdrawals' => $total_withdrawals,
        'net_amount' => $net_amount,
        'by_type' => $by_type
    ]
]);

mysqli_close($conn);
?>

