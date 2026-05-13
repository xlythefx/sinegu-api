<?php
/**
 * Delete a trading transaction from capital_trades_transactions table
 * POST JSON: {"transaction_id": 123, "uni_id": "optional - for security validation"}
 * Returns JSON: {"success": true, "message": "Transaction deleted"}
 * 
 * Security: If uni_id is provided, verifies the transaction belongs to an account with that uni_id
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

if (!$input || !isset($input['transaction_id'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Missing required field: transaction_id'
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

$transaction_id = intval($input['transaction_id']);
$uni_id = isset($input['uni_id']) ? mysqli_real_escape_string($conn, trim($input['uni_id'])) : null;

// Check if transaction exists and belongs to the user (if uni_id provided)
if ($uni_id) {
    // Verify transaction belongs to an account with this uni_id
    $check_sql = "SELECT ctt.id 
                  FROM capital_trades_transactions ctt
                  INNER JOIN accounts a ON ctt.account_id = a.id
                  WHERE ctt.id = {$transaction_id} AND a.uni_id = '{$uni_id}'
                  LIMIT 1";
} else {
    // Just check if transaction exists (for backward compatibility)
    $check_sql = "SELECT id FROM capital_trades_transactions WHERE id = {$transaction_id} LIMIT 1";
}

$check_result = mysqli_query($conn, $check_sql);

if (!$check_result || mysqli_num_rows($check_result) === 0) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'error' => 'Transaction not found or you do not have permission to delete it'
    ]);
    exit;
}

// Delete transaction
$sql = "DELETE FROM capital_trades_transactions WHERE id = {$transaction_id}";
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
    'message' => 'Transaction deleted successfully'
]);

mysqli_close($conn);
?>


