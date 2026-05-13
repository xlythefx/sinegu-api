<?php
/**
 * Check if a transaction already exists to prevent duplicates
 * GET parameters:
 *   - account_id: Account ID (required)
 *   - type: Transaction type (required)
 *   - amount: Transaction amount (required)
 *   - date: Transaction date (YYYY-MM-DD, optional - checks if exists on that date)
 * 
 * Returns JSON: {
 *   "success": true,
 *   "exists": true/false,
 *   "transaction_id": 123 (if exists)
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
$type = isset($_GET['type']) ? mysqli_real_escape_string($conn, trim($_GET['type'])) : null;
$amount = isset($_GET['amount']) ? floatval($_GET['amount']) : null;
$date = isset($_GET['date']) ? mysqli_real_escape_string($conn, $_GET['date']) : null;
$broker_reference = isset($_GET['broker_reference']) ? mysqli_real_escape_string($conn, $_GET['broker_reference']) : null;

// Validation - can check by broker_reference OR by (account_id + type + amount)
if ($broker_reference) {
    // Check by broker_reference (preferred method)
    $where_conditions = ["broker_reference = '{$broker_reference}'"];
    if ($account_id) {
        $where_conditions[] = "account_id = {$account_id}";
    }
} elseif ($account_id && $type && $amount !== null) {
    // Legacy check by account_id + type + amount
    $where_conditions = [
        "account_id = {$account_id}",
        "type = '{$type}'",
        "amount = {$amount}"
    ];
    // Optional: Check by date (within same day)
    if ($date) {
        $where_conditions[] = "DATE(created_at) = '{$date}'";
    }
} else {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Missing required parameters: either broker_reference, or (account_id, type, and amount)'
    ]);
    exit;
}

$where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

// Check if transaction exists
$sql = "SELECT id FROM capital_transactions {$where_clause} LIMIT 1";
$result = mysqli_query($conn, $sql);

if (!$result) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => mysqli_error($conn)
    ]);
    exit;
}

$exists = false;
$transaction_id = null;

if ($row = mysqli_fetch_assoc($result)) {
    $exists = true;
    $transaction_id = intval($row['id']);
}

echo json_encode([
    'success' => true,
    'exists' => $exists,
    'transaction_id' => $transaction_id
]);

mysqli_close($conn);
?>

