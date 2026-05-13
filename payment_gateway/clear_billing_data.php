<?php
/**
 * Clear Billing Data
 * 
 * This script:
 * 1. Resets HWM (High Water Mark) columns in accounts table
 * 2. Clears all rows from billing_periods table
 * 
 * WARNING: This will permanently delete billing data. Use with caution!
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database connection failed'
    ]);
    exit();
}

try {
    // Start transaction
    mysqli_begin_transaction($conn);
    
    // 1. Reset HWM columns in accounts table
    $resetAccountsQuery = "UPDATE accounts SET 
        hwm_equity = 0,
        hwm_realized = 0,
        hwm_unrealized = 0,
        last_billed_month = NULL,
        last_billed_at = NULL";
    
    if (!mysqli_query($conn, $resetAccountsQuery)) {
        throw new Exception("Failed to reset accounts HWM: " . mysqli_error($conn));
    }
    
    $accountsAffected = mysqli_affected_rows($conn);
    
    // 2. Delete all rows from billing_periods table
    $clearBillingQuery = "DELETE FROM billing_periods";
    
    if (!mysqli_query($conn, $clearBillingQuery)) {
        throw new Exception("Failed to clear billing_periods: " . mysqli_error($conn));
    }
    
    $billingPeriodsDeleted = mysqli_affected_rows($conn);
    
    // Commit transaction
    mysqli_commit($conn);
    
    echo json_encode([
        'success' => true,
        'message' => 'Billing data cleared successfully',
        'accounts_updated' => $accountsAffected,
        'billing_periods_deleted' => $billingPeriodsDeleted
    ]);
    
} catch (Exception $e) {
    // Rollback transaction on error
    mysqli_rollback($conn);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

mysqli_close($conn);
?>

