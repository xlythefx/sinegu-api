<?php
/**
 * Sync transactions from Capital.com API to database
 * POST JSON: {
 *   "account_id": 1,
 *   "transactions": [
 *     {
 *       "date": "2021-08-10T00:00:00",
 *       "dateUTC": "2021-08-10T00:00:00",
 *       "instrumentName": "USD",
 *       "type": "DEPOSIT",
 *       "size": 1000.00,
 *       "balance": 1000.00,
 *       "openLevel": null,
 *       "limitLevel": null,
 *       "stopLevel": null,
 *       "currency": "USD",
 *       "cashTransaction": true,
 *       "reference": "REF123"
 *     }
 *   ]
 * }
 * Returns JSON: {
 *   "success": true,
 *   "synced": 5,
 *   "skipped": 2,
 *   "errors": 0
 * }
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
$transactions = isset($input['transactions']) && is_array($input['transactions']) ? $input['transactions'] : [];

if (!$account_id) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Missing required field: account_id'
    ]);
    exit;
}

if (empty($transactions)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Missing or empty transactions array'
    ]);
    exit;
}

// Get uni_id from accounts table if not provided
$uni_id = null;
$uni_query = "SELECT uni_id FROM accounts WHERE id = {$account_id} LIMIT 1";
$uni_result = mysqli_query($conn, $uni_query);
if ($uni_result && $uni_row = mysqli_fetch_assoc($uni_result)) {
    $uni_id = mysqli_real_escape_string($conn, $uni_row['uni_id']);
}

$synced = 0;
$skipped = 0;
$errors = 0;

// HWM-relevant transaction types (for deposit/withdrawal tracking)
// WITHDRAWAL_MONEY_BACK = withdrawal reversal/refund (when withdrawal is cancelled and money returned)
$hwm_types = ['DEPOSIT', 'WITHDRAWAL', 'REFUND', 'WITHDRAWAL_MONEY_BACK'];

foreach ($transactions as $tx) {
    try {
        // Accept normalized format from Python: type, amount, date, reference, currency
        // Also support legacy format: transactionType, size, dateUTC for backward compatibility
        $type = isset($tx['type']) ? strtoupper(trim($tx['type'])) : 
                (isset($tx['transactionType']) ? strtoupper(trim($tx['transactionType'])) : null);
        
        // Normalized format uses 'amount', legacy uses 'size'
        $amount = isset($tx['amount']) ? floatval($tx['amount']) : 
                  (isset($tx['size']) ? floatval($tx['size']) : null);
        
        $balance_after = isset($tx['balance']) ? floatval($tx['balance']) : 
                         (isset($tx['balance_after']) ? floatval($tx['balance_after']) : null);
        
        // Normalized format uses 'date', legacy uses 'dateUTC' or 'dateUtc'
        $date_utc = isset($tx['date']) ? $tx['date'] : 
                    (isset($tx['dateUTC']) ? $tx['dateUTC'] : 
                     (isset($tx['dateUtc']) ? $tx['dateUtc'] : null));
        
        $reference = isset($tx['reference']) ? mysqli_real_escape_string($conn, $tx['reference']) : 
                     (isset($tx['referenceId']) ? mysqli_real_escape_string($conn, $tx['referenceId']) : null);
        
        $currency = isset($tx['currency']) ? mysqli_real_escape_string($conn, $tx['currency']) : null;
        
        // Validation - only process HWM-relevant transactions
        if (!$type || !in_array($type, $hwm_types)) {
            $skipped++; // Skip non-HWM transactions silently
            continue;
        }
        
        if ($amount === null) {
            $errors++;
            error_log("Transaction missing amount/size: " . json_encode($tx));
            continue;
        }
        
        // Check if transaction already exists (by broker_reference if available, otherwise by type, amount, and date)
        $exists = false;
        if ($reference) {
            // First check by broker_reference (most reliable - unique identifier from Capital.com)
            $ref_escaped = mysqli_real_escape_string($conn, $reference);
            $check_sql = "SELECT id FROM capital_transactions 
                         WHERE account_id = {$account_id} 
                         AND broker_reference = '{$ref_escaped}'
                         LIMIT 1";
            $check_result = mysqli_query($conn, $check_sql);
            if ($check_result && mysqli_fetch_assoc($check_result)) {
                $exists = true;
            } else {
                // Fallback: check by type, amount, and date (in case reference format changed)
                $type_escaped = mysqli_real_escape_string($conn, $type);
                $check_sql = "SELECT id FROM capital_transactions 
                             WHERE account_id = {$account_id} 
                             AND type = '{$type_escaped}'
                             AND amount = {$amount}";
                if ($date_utc) {
                    // Extract date part for comparison
                    $date_part = substr($date_utc, 0, 10); // YYYY-MM-DD
                    $check_sql .= " AND DATE(created_at) = '{$date_part}'";
                }
                $check_sql .= " LIMIT 1";
                
                $check_result = mysqli_query($conn, $check_sql);
                if ($check_result && mysqli_fetch_assoc($check_result)) {
                    $exists = true;
                }
            }
        } else {
            // No reference, check by type, amount, and date only
            $type_escaped = mysqli_real_escape_string($conn, $type);
            $check_sql = "SELECT id FROM capital_transactions 
                         WHERE account_id = {$account_id} 
                         AND type = '{$type_escaped}'
                         AND amount = {$amount}";
            if ($date_utc) {
                $date_part = substr($date_utc, 0, 10);
                $check_sql .= " AND DATE(created_at) = '{$date_part}'";
            }
            $check_sql .= " LIMIT 1";
            
            $check_result = mysqli_query($conn, $check_sql);
            if ($check_result && mysqli_fetch_assoc($check_result)) {
                $exists = true;
            }
        }
        
        if ($exists) {
            $skipped++;
            continue;
        }
        
        // Insert transaction
        $type_escaped = mysqli_real_escape_string($conn, $type);
        
        // Format date_utc for MySQL (handle various formats)
        $date_for_db = "NOW()";
        if ($date_utc) {
            // Try to parse and format the date
            $date_str = trim($date_utc);
            // Remove milliseconds if present (e.g., 2025-12-14T22:01:36.317)
            if (strpos($date_str, '.') !== false) {
                $date_str = substr($date_str, 0, strpos($date_str, '.'));
            }
            // Ensure it's in format YYYY-MM-DD HH:MM:SS (replace T with space)
            if (strpos($date_str, 'T') !== false) {
                $date_str = str_replace('T', ' ', $date_str);
            }
            // Validate it's a proper datetime format before using
            if (preg_match('/^\d{4}-\d{2}-\d{2}[\sT]\d{2}:\d{2}:\d{2}$/', $date_str)) {
                $date_for_db = "'" . mysqli_real_escape_string($conn, $date_str) . "'";
            } else {
                // Invalid date format, use NOW() as fallback
                error_log("Invalid date format: {$date_utc}, using NOW()");
                $date_for_db = "NOW()";
            }
        }
        
        $sql = "INSERT INTO capital_transactions 
                (account_id, uni_id, type, amount, balance_after, broker_reference, currency, created_at) 
                VALUES (
                    {$account_id}, 
                    " . ($uni_id ? "'{$uni_id}'" : "NULL") . ", 
                    '{$type_escaped}', 
                    {$amount}, 
                    " . ($balance_after !== null ? $balance_after : "NULL") . ",
                    " . ($reference ? "'{$reference}'" : "NULL") . ",
                    " . ($currency ? "'{$currency}'" : "NULL") . ",
                    {$date_for_db}
                )";
        
        $result = mysqli_query($conn, $sql);
        
        if ($result) {
            $synced++;
        } else {
            $errors++;
            $error_msg = mysqli_error($conn);
            error_log("Failed to insert transaction: " . $error_msg);
            error_log("SQL: " . $sql);
            error_log("Transaction data: " . json_encode($tx));
        }
    } catch (Exception $e) {
        $errors++;
        error_log("Error processing transaction: " . $e->getMessage());
    }
}

echo json_encode([
    'success' => true,
    'synced' => $synced,
    'skipped' => $skipped,
    'errors' => $errors,
    'total' => count($transactions)
]);

mysqli_close($conn);
?>

