<?php
/**
 * Update account balance from IG.com API data → ig_accounts.
 *
 * Writable columns: balance, unrealized_pnl, initial_deposit, currency_type, broker_accountID.
 * equity is accepted in JSON but ignored (no column on ig_accounts).
 *
 * initial_deposit: set from deposit_amount ONLY when current value is NULL, empty, or numeric zero
 * (0, 0.00, 0.00000000, etc.). Once set to a non-zero value, it is never overwritten.
 * If deposit_amount is 0 but balance is non-zero, balance is used as the seed (IG margin "deposit" is often 0).
 *
 * POST JSON: {"account_id": 1, "balance": 10000.50, "unrealized_pnl": 50.00, "equity": 10050.50,
 *             "deposit_amount": 5000.00, "broker_accountID": "123456", "currency_type": "USD"}
 * OR: {"email": "user@example.com", "balance": 10000.50, ...}
 * Returns JSON: {"success": true, "message": "Balance updated"}
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);
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
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use POST.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON input']);
    exit;
}

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$account_id = isset($input['account_id']) ? intval($input['account_id']) : null;
$email = isset($input['email']) ? mysqli_real_escape_string($conn, $input['email']) : null;
$balance = isset($input['balance']) ? floatval($input['balance']) : null;
$unrealized_pnl = isset($input['unrealized_pnl']) ? floatval($input['unrealized_pnl']) : null;
$deposit_amount = isset($input['deposit_amount']) ? floatval($input['deposit_amount']) : null;
$broker_accountID = isset($input['broker_accountID']) ? mysqli_real_escape_string($conn, $input['broker_accountID']) : null;
$currency_type = isset($input['currency_type']) ? mysqli_real_escape_string($conn, $input['currency_type']) : null;

if ($currency_type !== null) {
    $currency_type = rtrim($currency_type, 'd');
}

if (!$account_id && !$email) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required field: account_id or email']);
    exit;
}

$updates = [];
if ($balance !== null) {
    $updates[] = "balance = {$balance}";
}
if ($unrealized_pnl !== null) {
    $updates[] = "unrealized_pnl = {$unrealized_pnl}";
}
if ($deposit_amount !== null) {
    if ($account_id) {
        $check_sql = "SELECT initial_deposit FROM ig_accounts WHERE id = {$account_id} AND (deleted_at IS NULL) LIMIT 1";
    } else {
        $check_sql = "SELECT initial_deposit FROM ig_accounts WHERE email = '{$email}' AND (deleted_at IS NULL) LIMIT 1";
    }
    $check_result = mysqli_query($conn, $check_sql);
    if ($check_result && $row = mysqli_fetch_assoc($check_result)) {
        $current_initial_deposit = $row['initial_deposit'];
        // Only when unset: NULL, '', or any DECIMAL that evaluates to 0 (0, 0.00, 0.00000000).
        $is_empty_or_zero = (
            $current_initial_deposit === null
            || $current_initial_deposit === ''
            || trim((string) $current_initial_deposit) === ''
            || floatval($current_initial_deposit) == 0.0
        );
        if ($is_empty_or_zero) {
            $seed = floatval($deposit_amount);
            if ($seed == 0.0 && $balance !== null && floatval($balance) != 0.0) {
                $seed = floatval($balance);
            }
            $updates[] = "initial_deposit = {$seed}";
        }
    }
}
if ($broker_accountID !== null) {
    $updates[] = "broker_accountID = '{$broker_accountID}'";
}
if ($currency_type !== null) {
    $updates[] = "currency_type = '{$currency_type}'";
}

if (empty($updates)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No fields to update']);
    exit;
}

$set_clause = implode(', ', $updates);
$set_clause .= ', updated_at = NOW()';

if ($account_id) {
    $sql = "UPDATE ig_accounts SET {$set_clause} WHERE id = {$account_id} AND (deleted_at IS NULL)";
} else {
    $sql = "UPDATE ig_accounts SET {$set_clause} WHERE email = '{$email}' AND (deleted_at IS NULL)";
}

$result = mysqli_query($conn, $sql);

if (!$result) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    exit;
}

ob_clean();
echo json_encode(['success' => true, 'message' => 'Balance updated successfully']);

mysqli_close($conn);
exit;
