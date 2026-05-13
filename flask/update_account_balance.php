<?php
/**
 * Update account balance, unrealized_pnl, equity, deposit_amount, broker_accountID, and/or currency_type
 * 
 * NOTE: realized_pnl is NOT updated here - it's always calculated from trades table (SUM of pnl column)
 * Never use realized_pnl as fallback - always calculate from trades table
 * 
 * currency_type: Always updates if provided (no conditions) - overwrites existing value
 * 
 * POST JSON: {"account_id": 1, "balance": 10000.50, "unrealized_pnl": 50.00, "equity": 10300.50, "deposit_amount": 5000.00, "broker_accountID": "301171542112687262", "currency_type": "EURd"}
 * OR: {"email": "user@example.com", "balance": 10000.50, "unrealized_pnl": 50.00, "equity": 10050.50, "broker_accountID": "301171542112687262", "currency_type": "EURd"}
 * Returns JSON: {"success": true, "message": "Balance updated"}
 */

// Turn off error display to prevent HTML output in JSON response
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Start output buffering to catch any unexpected output
ob_start();

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
$email = isset($input['email']) ? mysqli_real_escape_string($conn, $input['email']) : null;
$balance = isset($input['balance']) ? floatval($input['balance']) : null;
// realized_pnl is NOT accepted - always calculated from trades table (SUM of pnl column)
$unrealized_pnl = isset($input['unrealized_pnl']) ? floatval($input['unrealized_pnl']) : null;
$equity = isset($input['equity']) ? floatval($input['equity']) : null;
$deposit_amount = isset($input['deposit_amount']) ? floatval($input['deposit_amount']) : null;
$broker_accountID = isset($input['broker_accountID']) ? mysqli_real_escape_string($conn, $input['broker_accountID']) : null;
$currency_type = isset($input['currency_type']) ? mysqli_real_escape_string($conn, $input['currency_type']) : null;

// Format currency_type: Remove 'd' suffix if present (e.g., "EURd" -> "EUR")
if ($currency_type !== null) {
    $currency_type = rtrim($currency_type, 'd');
}

if (!$account_id && !$email) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Missing required field: account_id or email'
    ]);
    exit;
}

$updates = [];
if ($balance !== null) {
    $updates[] = "balance = {$balance}";
}
// realized_pnl is NOT updated - always calculated from trades table (SUM of pnl column)
if ($unrealized_pnl !== null) {
    $updates[] = "unrealized_pnl = {$unrealized_pnl}";
}
if ($equity !== null) {
    $updates[] = "equity = {$equity}";
}
if ($deposit_amount !== null) {
    // Always update deposit_amount with latest balance from Capital.com
    $updates[] = "deposit_amount = {$deposit_amount}";
    
    // Also set initial_deposit to the same value, but ONLY if it's null or empty
    // If initial_deposit already has a value, never update it
    if ($account_id) {
        $check_sql = "SELECT initial_deposit FROM accounts WHERE id = {$account_id}";
    } else {
        $check_sql = "SELECT initial_deposit FROM accounts WHERE email = '{$email}'";
    }
    $check_result = mysqli_query($conn, $check_sql);
    if ($check_result && $row = mysqli_fetch_assoc($check_result)) {
        $current_initial_deposit = $row['initial_deposit'];
        // Only update if initial_deposit is NULL, empty string, or 0 (treat 0.00, 0, "0", etc. as empty)
        // This handles database default values of 0.00 - treat them as "not set yet"
        $is_empty_or_zero = (
            $current_initial_deposit === null 
            || $current_initial_deposit === '' 
            || trim((string)$current_initial_deposit) === ''
            || floatval($current_initial_deposit) == 0
        );
        if ($is_empty_or_zero) {
            $updates[] = "initial_deposit = {$deposit_amount}";
        }
    }
}
if ($broker_accountID !== null) {
    $updates[] = "broker_accountID = '{$broker_accountID}'";
}
if ($currency_type !== null) {
    // Always update currency_type if provided (no conditions - overwrites existing value)
    $updates[] = "currency_type = '{$currency_type}'";
}

if (empty($updates)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'No fields to update'
    ]);
    exit;
}

$set_clause = implode(', ', $updates);
// Always update updated_at timestamp to trigger webhook notifications
$set_clause .= ', updated_at = NOW()';

if ($account_id) {
    $sql = "UPDATE accounts SET {$set_clause} WHERE id = {$account_id}";
} else {
    $sql = "UPDATE accounts SET {$set_clause} WHERE email = '{$email}'";
}

$result = mysqli_query($conn, $sql);

if (!$result) {
    if (file_exists(__DIR__ . '/../includes/api_log.php')) {
        require_once __DIR__ . '/../includes/api_log.php';
        api_log($conn, 'flask/update_account_balance', 'Update balance failed', 'update', false);
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => mysqli_error($conn)
    ]);
    exit;
}

if (file_exists(__DIR__ . '/../includes/api_log.php')) {
    require_once __DIR__ . '/../includes/api_log.php';
    api_log($conn, 'flask/update_account_balance', 'Account balance updated', 'update', true);
}
// Clear any output buffer before sending JSON
ob_clean();

echo json_encode([
    'success' => true,
    'message' => 'Balance updated successfully'
]);

mysqli_close($conn);
exit;
?>

