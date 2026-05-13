<?php
/**
 * Payment Gateway - Process Payment API
 * Simulates a payment gateway like Stripe
 * Processes payment and updates HWM based on billing_periods
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

ob_start();
require_once __DIR__ . '/../conn.php';

if (!$conn) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    ob_end_flush();
    exit();
}

try {
    // Get payment data from request
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    // Accept either account_id + month_year OR user_id + month_year
    if (!isset($data['account_id']) && !isset($data['user_id'])) {
        throw new Exception("account_id or user_id is required");
    }
    
    if (!isset($data['month_year']) || empty($data['month_year'])) {
        throw new Exception("month_year is required (format: YYYY-MM)");
    }
    
    if (!isset($data['amount']) || $data['amount'] <= 0) {
        throw new Exception("Valid amount is required");
    }
    
    $accountId = isset($data['account_id']) ? (int)$data['account_id'] : null;
    $userId = isset($data['user_id']) ? $data['user_id'] : null;
    $monthYear = mysqli_real_escape_string($conn, $data['month_year']);
    $amount = (float)$data['amount'];
    $currency = $data['currency'] ?? 'USD';
    $paymentMethod = $data['payment_method'] ?? 'crypto'; // crypto, card, etc.
    
    // Start transaction
    mysqli_begin_transaction($conn);
    
    // Step 1: Get billing_periods for this payment
    // Verify billing period exists and get total fee
    $accountIdEscaped = $accountId ? (int)$accountId : 0;
    $userIdEscaped = mysqli_real_escape_string($conn, $userId ?? '');
    
    if ($accountId) {
        $billingPeriodQuery = "SELECT 
                                    bp.account_id,
                                    bp.user_id,
                                    bp.month_year,
                                    bp.total_fee,
                                    bp.hwm_after,
                                    bp.status,
                                    a.uni_id,
                                    a.deposit_amount,
                                    a.unrealized_pnl
                                 FROM billing_periods bp
                                 INNER JOIN accounts a ON bp.account_id = a.id
                                 WHERE bp.account_id = $accountIdEscaped AND bp.month_year = '$monthYear'
                                 LIMIT 1";
    } else {
        $billingPeriodQuery = "SELECT 
                                    bp.account_id,
                                    bp.user_id,
                                    bp.month_year,
                                    bp.total_fee,
                                    bp.hwm_after,
                                    bp.status,
                                    a.uni_id,
                                    a.deposit_amount,
                                    a.unrealized_pnl
                                 FROM billing_periods bp
                                 INNER JOIN accounts a ON bp.account_id = a.id
                                 WHERE bp.user_id = '$userIdEscaped' AND bp.month_year = '$monthYear'
                                 LIMIT 1";
    }
    
    $billingResult = mysqli_query($conn, $billingPeriodQuery);
    
    if (!$billingResult || mysqli_num_rows($billingResult) === 0) {
        throw new Exception("Billing period not found for the specified account/user and month");
    }
    
    $billingPeriod = mysqli_fetch_assoc($billingResult);
    mysqli_free_result($billingResult);
    
    $actualUserId = $billingPeriod['user_id'];
    $actualAccountId = (int)$billingPeriod['account_id'];
    $totalFee = (float)$billingPeriod['total_fee'];
    $billingStatus = $billingPeriod['status'] ?? 'pending';
    
    // Check if billing period is already paid
    if ($billingStatus === 'paid') {
        mysqli_rollback($conn);
        ob_clean();
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Billing period is already paid',
            'account_id' => $actualAccountId,
            'user_id' => $actualUserId,
            'month_year' => $monthYear,
            'status' => 'paid',
            'already_paid' => true
        ]);
        ob_end_flush();
        exit();
    }
    
    // Verify payment amount matches total fee (with small tolerance for rounding)
    $amountDifference = abs($amount - $totalFee);
    
    if ($amountDifference > 0.01) { // Allow 1 cent difference for rounding
        throw new Exception("Payment amount does not match billing period total fee. Expected: " . $totalFee . ", Received: " . $amount);
    }
    
    // Step 2: Simulate payment gateway processing
    // In a real scenario, this would:
    // - Validate payment method
    // - Process with Stripe/PayPal/etc.
    // - Handle webhooks
    // - Verify payment confirmation
    
    // For simulation, we'll just log the payment attempt
    $paymentId = 'pay_' . uniqid() . '_' . time();
    $paymentStatus = 'succeeded'; // Simulated success
    
    // Step 3: Update billing_periods status to 'paid'
    $actualUserIdEscaped = mysqli_real_escape_string($conn, $actualUserId);
    
    $updateBillingPeriodQuery = "UPDATE billing_periods 
                                  SET status = 'paid',
                                      updated_at = NOW()
                                  WHERE user_id = '$actualUserIdEscaped' AND month_year = '$monthYear'";
    
    if (!mysqli_query($conn, $updateBillingPeriodQuery)) {
        throw new Exception("Failed to update billing period status: " . mysqli_error($conn));
    }
    
    // Step 4: Update accounts HWM after payment
    // Get all billing periods for this user and month (in case multiple accounts)
    $actualUserIdEscaped = mysqli_real_escape_string($conn, $actualUserId);
    
    $allBillingPeriodsQuery = "SELECT DISTINCT
                                    bp.account_id,
                                    bp.hwm_after,
                                    a.uni_id,
                                    a.deposit_amount,
                                    a.unrealized_pnl
                                 FROM billing_periods bp
                                 INNER JOIN accounts a ON bp.account_id = a.id
                                 WHERE bp.user_id = '$actualUserIdEscaped' AND bp.month_year = '$monthYear'";
    
    $billingResult = mysqli_query($conn, $allBillingPeriodsQuery);
    
    if (!$billingResult) {
        throw new Exception("Failed to query billing periods: " . mysqli_error($conn));
    }
    
    $accountsUpdated = 0;
    
    while ($accountRow = mysqli_fetch_assoc($billingResult)) {
        $accountId = (int)$accountRow['account_id'];
        $depositAmount = (float)($accountRow['deposit_amount'] ?? 0);
        $unrealizedPnl = (float)($accountRow['unrealized_pnl'] ?? 0);
        
        // Calculate realized_pnl from trades table
        $realizedPnlSql = "SELECT COALESCE(SUM(pnl), 0) AS total_pnl FROM trades WHERE account_id = $accountId";
        $realizedPnlResult = mysqli_query($conn, $realizedPnlSql);
        $realizedPnl = 0.0;
        if ($realizedPnlResult) {
            $realizedPnlRow = mysqli_fetch_assoc($realizedPnlResult);
            $realizedPnl = isset($realizedPnlRow['total_pnl']) ? (float) $realizedPnlRow['total_pnl'] : 0.0;
            mysqli_free_result($realizedPnlResult);
        }
        
        // Calculate equity: deposit_amount + unrealized_pnl + realized_pnl
        // We disregard balance column for HWM calculations
        $currentEquity = $depositAmount + $unrealizedPnl + $realizedPnl;
        
        // Use hwm_after from billing_periods as the new HWM (this was calculated during invoice generation)
        // Or use current equity if hwm_after is not set
        $newHwmEquity = isset($accountRow['hwm_after']) && $accountRow['hwm_after'] > 0 
            ? (float)$accountRow['hwm_after'] 
            : $currentEquity;
        
        // Update account HWM using GREATEST to ensure it only moves upward
        // hwm_equity = GREATEST(hwm_equity, new_hwm_equity)
        // hwm_realized = GREATEST(hwm_realized, realized_pnl)
        // hwm_unrealized = GREATEST(hwm_unrealized, unrealized_pnl) - but only if positive
        $unrealizedForHwm = $unrealizedPnl > 0 ? $unrealizedPnl : 0;
        
        $newHwmEquityEscaped = mysqli_real_escape_string($conn, (string)$newHwmEquity);
        $realizedPnlEscaped = mysqli_real_escape_string($conn, (string)$realizedPnl);
        $unrealizedForHwmEscaped = mysqli_real_escape_string($conn, (string)$unrealizedForHwm);
        $monthYearEscaped = mysqli_real_escape_string($conn, $monthYear);
        $actualUserIdEscaped2 = mysqli_real_escape_string($conn, $actualUserId);
        
        $updateAccountQuery = "UPDATE accounts 
                              SET 
                                  hwm_equity = GREATEST(COALESCE(hwm_equity, 0), $newHwmEquityEscaped),
                                  hwm_realized = GREATEST(COALESCE(hwm_realized, 0), $realizedPnlEscaped),
                                  hwm_unrealized = GREATEST(COALESCE(hwm_unrealized, 0), $unrealizedForHwmEscaped),
                                  last_billed_month = '$monthYearEscaped',
                                  last_billed_at = NOW()
                              WHERE id = $accountId AND uni_id = '$actualUserIdEscaped2'";
        
        if (mysqli_query($conn, $updateAccountQuery)) {
            $accountsUpdated++;
        }
    }
    
    mysqli_free_result($billingResult);
    
    // Commit transaction
    mysqli_commit($conn);
    
    ob_clean();
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Payment processed successfully',
        'payment_id' => $paymentId,
        'account_id' => $actualAccountId,
        'user_id' => $actualUserId,
        'month_year' => $monthYear,
        'amount' => $amount,
        'currency' => $currency,
        'payment_method' => $paymentMethod,
        'status' => $paymentStatus,
        'paid_at' => date('Y-m-d H:i:s'),
        'accounts_updated' => $accountsUpdated
    ]);
    ob_end_flush();
    
} catch (Exception $e) {
    // Rollback transaction on error
    if (isset($conn)) {
        mysqli_rollback($conn);
    }
    
    ob_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
    ob_end_flush();
} finally {
    if (isset($conn) && $conn) {
        mysqli_close($conn);
    }
}
?>
