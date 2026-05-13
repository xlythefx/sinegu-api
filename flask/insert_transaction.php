<?php
/**
 * Insert a capital transaction into the database
 * POST JSON: {
 *   "account_id": 1,
 *   "uni_id": "uuid-string",
 *   "type": "deposit|withdrawal|trade",
 *   "amount": 100.50,
 *   "balance_after": 1100.50
 * }
 * Returns JSON: {"success": true, "message": "Transaction inserted", "transaction_id": 123}
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

if (!$input) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid JSON input'
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

$account_id = isset($input['account_id']) ? intval($input['account_id']) : null;
$uni_id = isset($input['uni_id']) ? mysqli_real_escape_string($conn, $input['uni_id']) : null;
$type = isset($input['type']) ? mysqli_real_escape_string($conn, $input['type']) : null;
$amount = isset($input['amount']) ? floatval($input['amount']) : null;
$balance_after = isset($input['balance_after']) ? floatval($input['balance_after']) : null;
$broker_reference = isset($input['broker_reference']) ? mysqli_real_escape_string($conn, $input['broker_reference']) : null;
$currency = isset($input['currency']) ? mysqli_real_escape_string($conn, $input['currency']) : null;

// Validation
if (!$account_id) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Missing required field: account_id'
    ]);
    exit;
}

if (!$type) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Missing required field: type'
    ]);
    exit;
}

if ($amount === null) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Missing required field: amount'
    ]);
    exit;
}

// If uni_id is not provided, try to get it from accounts table
if (!$uni_id) {
    $uni_query = "SELECT uni_id FROM accounts WHERE id = {$account_id} LIMIT 1";
    $uni_result = mysqli_query($conn, $uni_query);
    if ($uni_result && $uni_row = mysqli_fetch_assoc($uni_result)) {
        $uni_id = mysqli_real_escape_string($conn, $uni_row['uni_id']);
    }
}

// Insert transaction
$type_escaped = mysqli_real_escape_string($conn, $type);
$sql = "INSERT INTO capital_transactions (account_id, uni_id, type, amount, balance_after, broker_reference, currency, created_at) 
        VALUES ({$account_id}, " . ($uni_id ? "'{$uni_id}'" : "NULL") . ", '{$type_escaped}', {$amount}, " . 
        ($balance_after !== null ? $balance_after : "NULL") . ", " .
        ($broker_reference ? "'{$broker_reference}'" : "NULL") . ", " .
        ($currency ? "'{$currency}'" : "NULL") . ", NOW())";

$result = mysqli_query($conn, $sql);

if (!$result) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => mysqli_error($conn)
    ]);
    exit;
}

$transaction_id = mysqli_insert_id($conn);

echo json_encode([
    'success' => true,
    'message' => 'Transaction inserted successfully',
    'transaction_id' => $transaction_id
]);

mysqli_close($conn);
?>

