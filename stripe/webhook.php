<?php
/**
 * Stripe Webhook Handler
 * Mirrors logic from process_payment.php:
 * - Validates billing period (account_id/user_id + month_year)
 * - Verifies paid amount matches total_fee
 * - Marks billing_periods as 'paid'
 * - Updates HWM in accounts table
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
require_once __DIR__ . '/config.php';

if (!$conn) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    ob_end_flush();
    exit();
}

try {
    // ---------- 1. Load raw Stripe payload ----------
    $payload    = @file_get_contents('php://input');
    $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

    // ---------- 2. Load Stripe PHP SDK ----------
    $stripeAutoloadPath = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($stripeAutoloadPath)) {
        ob_clean();
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error'   => 'Stripe PHP library not found. Run: composer require stripe/stripe-php'
        ]);
        ob_end_flush();
        exit();
    }
    require_once $stripeAutoloadPath;

    // ---------- 3. Verify Stripe signature ----------
    $webhookSecret = getStripeWebhookSecret();
    \Stripe\Stripe::setApiKey(getStripeSecretKey());

    try {
        $event = \Stripe\Webhook::constructEvent(
            $payload,
            $sig_header,
            $webhookSecret
        );
    } catch (\Stripe\Exception\SignatureVerificationException $e) {
        ob_clean();
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid Stripe signature']);
        ob_end_flush();
        exit();
    }

    // ---------- 4. Handle supported events ----------
    if ($event->type === 'checkout.session.completed') {
        $session = $event->data->object;

        // Only proceed if Stripe confirms payment
        // (for subscriptions you might also see 'no_payment_required')
        if (!in_array($session->payment_status, ['paid', 'no_payment_required'], true)) {
            ob_clean();
            http_response_code(200);
            echo json_encode([
                'success' => false,
                'message' => 'Checkout session not in paid state, skipping.'
            ]);
            ob_end_flush();
            exit();
        }

        $metadata = $session->metadata ?? (object)[];

        // ---------- 4.1 Extract metadata (must match your create-checkout-session.php) ----------
        // Extract billing_period_id first (most efficient if available)
        // Handle both object and array metadata formats
        $billingPeriodIdValue = null;
        if (is_object($metadata) && isset($metadata->billing_period_id)) {
            $billingPeriodIdValue = $metadata->billing_period_id;
        } elseif (is_array($metadata) && isset($metadata['billing_period_id'])) {
            $billingPeriodIdValue = $metadata['billing_period_id'];
        }
        
        $billingPeriodId = ($billingPeriodIdValue !== null && $billingPeriodIdValue !== '')
            ? (int)$billingPeriodIdValue
            : null;

        $accountId  = isset($metadata->account_id) && $metadata->account_id !== ''
            ? (int)$metadata->account_id
            : null;

        $userId     = isset($metadata->user_id) && $metadata->user_id !== ''
            ? (string)$metadata->user_id
            : null;

        $monthYear  = isset($metadata->month_year) ? (string)$metadata->month_year : null;

        // Broker MUST come from session metadata. Falling back to capital.com would
        // route a binance invoice's status update to the wrong table.
        $brokerRaw = isset($metadata->broker) ? (string)$metadata->broker : '';
        if (!in_array($brokerRaw, ['capital.com', 'binance', 'ig'], true)) {
            throw new Exception("Stripe webhook: metadata.broker is missing or invalid. Session ID: " . ($session->id ?? 'unknown') . ", broker: " . var_export($brokerRaw, true));
        }
        $broker = $brokerRaw;

        // Amount / currency – prefer metadata if present, otherwise use Stripe values
        if (isset($metadata->amount)) {
            $amount = (float)$metadata->amount;
        } else {
            // Stripe amount_total is in cents
            $amount = isset($session->amount_total) ? ((float)$session->amount_total / 100.0) : 0.0;
        }

        $currency = isset($metadata->currency)
            ? (string)$metadata->currency
            : (string)($session->currency ?? 'USD');

        $paymentMethod = 'stripe_checkout';

        // ---------- 4.2 Validate required fields (copying process_payment.php logic) ----------
        // For test triggers (stripe trigger), metadata might be missing - handle gracefully
        if ($accountId === null && $userId === null) {
            // Check if this is a test trigger (no metadata) vs real payment
            $metadataArray = (array)$metadata;
            if (empty($metadataArray)) {
                // This is likely a test trigger - return 200 but don't process
                ob_clean();
                http_response_code(200);
                echo json_encode([
                    'success' => true,
                    'message' => 'Test webhook received (no metadata). This is expected for stripe trigger commands.',
                    'session_id' => $session->id,
                    'note' => 'Real payments include metadata (account_id, user_id, month_year)'
                ]);
                ob_end_flush();
                exit();
            }
            throw new Exception("Stripe webhook: account_id or user_id metadata is required. Session ID: " . ($session->id ?? 'unknown'));
        }

        if (!$monthYear) {
            throw new Exception("Stripe webhook: month_year metadata is required (format: YYYY-MM). Session ID: " . ($session->id ?? 'unknown') . ", Metadata: " . json_encode($metadata));
        }

        if ($amount <= 0) {
            throw new Exception("Stripe webhook: Valid amount is required. Session ID: " . ($session->id ?? 'unknown') . ", Amount: " . $amount);
        }

        // ---------- 4.3 Start DB transaction ----------
        mysqli_begin_transaction($conn);

        $monthYearEscaped = mysqli_real_escape_string($conn, $monthYear);
        $accountIdEscaped = $accountId ? (int)$accountId : 0;
        $userIdEscaped    = mysqli_real_escape_string($conn, $userId ?? '');

        // ---------- 4.4 Find billing period (same as process_payment.php) ----------
        // CRITICAL: billing_period_id is REQUIRED - no fallbacks to prevent multi-row updates
        if (!$billingPeriodId || $billingPeriodId <= 0) {
            throw new Exception("Stripe webhook: billing_period_id is required in metadata. Received: " . ($billingPeriodId ?? 'null') . ". This prevents accidental multi-row updates.");
        }

        // Use billing_period_id directly (most efficient, guarantees single row)
        $billingPeriodIdEscaped = (int)$billingPeriodId;

        // SELECT ... FOR UPDATE locks the invoice row inside the transaction so that
        // a concurrent duplicate Stripe webhook delivery cannot both pass the
        // status==='paid' idempotency check below.
        if ($broker === 'binance') {
            $billingPeriodQuery = "SELECT bp.id, NULL AS account_id, bp.user_id, bp.month_year,
                bp.total_fee, bp.realized_pnl, bp.unrealized_pnl, bp.hwm_before, bp.hwm_after, bp.status,
                NULL AS uni_id, 0 AS deposit_amount, 0 AS unrealized_pnl, 0 AS hwm_realized, 0 AS hwm_unrealized
                FROM binance_invoices bp WHERE bp.id = $billingPeriodIdEscaped LIMIT 1 FOR UPDATE";
        } elseif ($broker === 'ig') {
            $billingPeriodQuery = "SELECT bp.id, bp.account_id, bp.user_id, bp.month_year,
                bp.total_fee, bp.realized_pnl, bp.unrealized_pnl, bp.hwm_before, bp.hwm_after, bp.status,
                NULL AS uni_id, 0 AS deposit_amount, 0 AS unrealized_pnl, 0 AS hwm_realized, 0 AS hwm_unrealized
                FROM ig_invoices bp WHERE bp.id = $billingPeriodIdEscaped LIMIT 1 FOR UPDATE";
        } else {
            $billingPeriodQuery = "
                SELECT bp.id, bp.account_id, bp.user_id, bp.month_year, bp.total_fee,
                    bp.realized_pnl, bp.unrealized_pnl, bp.hwm_before, bp.hwm_after, bp.status,
                    a.uni_id, a.deposit_amount, a.unrealized_pnl, a.hwm_realized, a.hwm_unrealized
                FROM billing_periods bp
                INNER JOIN accounts a ON bp.account_id = a.id
                WHERE bp.id = $billingPeriodIdEscaped LIMIT 1 FOR UPDATE";
        }

        $billingResult = mysqli_query($conn, $billingPeriodQuery);
        if (!$billingResult || mysqli_num_rows($billingResult) === 0) {
            throw new Exception("Stripe webhook: Billing period not found for the specified account/user and month");
        }

        $billingPeriod   = mysqli_fetch_assoc($billingResult);
        mysqli_free_result($billingResult);

        $actualUserId    = $billingPeriod['user_id'];
        $actualAccountId = (int)$billingPeriod['account_id'];
        $billingStatus   = $billingPeriod['status'] ?? 'pending';
        
        // Escape actualUserId for use in payment method saving section
        $actualUserIdEscaped = mysqli_real_escape_string($conn, $actualUserId ?? '');

        // ---------- 4.5 If already paid, exit idempotently ----------
        if ($billingStatus === 'paid') {
            mysqli_rollback($conn);
            ob_clean();
            http_response_code(200);
            echo json_encode([
                'success'      => true,
                'message'      => 'Billing period already paid (idempotent webhook)',
                'account_id'   => $actualAccountId,
                'user_id'      => $actualUserId,
                'month_year'   => $monthYear,
                'status'       => 'paid',
                'already_paid' => true
            ]);
            ob_end_flush();
            exit();
        }

        // ---------- 4.6 Verify payment amount matches stored total_fee ----------
        // Use the stored total_fee from billing_periods (calculated during invoice generation)
        // This is more reliable than recalculating, as it uses the exact values from generation time
        $expectedTotalFee = (float)($billingPeriod['total_fee'] ?? 0);
        
        // Verify amount matches at integer-cent precision (no floating point tolerance).
        $expectedCents = (int)round($expectedTotalFee * 100);
        $receivedCents = (int)round($amount * 100);
        $amountDifference = abs($amount - $expectedTotalFee);
        if ($expectedCents !== $receivedCents) {
            // Log detailed information for debugging
            $debugInfo = [
                'billing_period_id' => $billingPeriodId,
                'expected_total_fee' => $expectedTotalFee,
                'received_amount' => $amount,
                'difference' => $amountDifference,
                'billing_period_data' => [
                    'id' => $billingPeriod['id'],
                    'total_fee' => $billingPeriod['total_fee'],
                    'fee_realized' => $billingPeriod['fee_realized'] ?? 'N/A',
                    'fee_unrealized' => $billingPeriod['fee_unrealized'] ?? 'N/A',
                    'status' => $billingPeriod['status']
                ],
                'session_amount_total' => $session->amount_total ?? 'N/A',
                'session_currency' => $session->currency ?? 'N/A'
            ];
            
            throw new Exception(
                "Stripe webhook: Payment amount mismatch. Expected {$expectedTotalFee} (from billing_periods.total_fee), received {$amount}. Difference: {$amountDifference}. Debug: " . json_encode($debugInfo)
            );
        }

        // ---------- 4.7 Mark billing_periods as 'paid' (same as process_payment.php) ----------
        // CRITICAL: Use billing_periods.id to ensure ONLY ONE row is updated
        // This prevents accidental updates of multiple rows even if duplicates exist
        $billingPeriodIdForUpdate = (int)$billingPeriod['id'];
        
        if ($billingPeriodIdForUpdate <= 0) {
            throw new Exception("Stripe webhook: billing_periods.id is required for update. Received: " . ($billingPeriod['id'] ?? 'null'));
        }

        // Use the specific ID to guarantee only ONE row is updated
        $invoiceTable = ($broker === 'binance') ? 'binance_invoices' : (($broker === 'ig') ? 'ig_invoices' : 'billing_periods');
        $updateBillingPeriodQuery = "
            UPDATE $invoiceTable
            SET status = 'paid',
                updated_at = NOW()
            WHERE id = $billingPeriodIdForUpdate";

        if (!mysqli_query($conn, $updateBillingPeriodQuery)) {
            $dbError = mysqli_error($conn);
            throw new Exception("Stripe webhook: Failed to update $invoiceTable: " . $dbError);
        }

        $rowsAffected = mysqli_affected_rows($conn);

        // Verify exactly one row was affected
        if ($rowsAffected === 0) {
            // Check if record exists and is already paid (idempotent case)
            $checkQuery = "
                SELECT id, status
                FROM $invoiceTable
                WHERE id = $billingPeriodIdForUpdate
                LIMIT 1";
            $checkResult = mysqli_query($conn, $checkQuery);
            
            if ($checkResult && mysqli_num_rows($checkResult) > 0) {
                $checkRow = mysqli_fetch_assoc($checkResult);
                mysqli_free_result($checkResult);
                
                if ($checkRow['status'] === 'paid') {
                    // Already paid - this is fine (idempotent webhook)
                    // Return success for idempotent case
                } else {
                    // Record exists but update failed - this is a critical error
                    throw new Exception("Billing period exists (id: " . $checkRow['id'] . ") but update failed. Current status: " . ($checkRow['status'] ?? 'null') . ". This should not happen.");
                }
            } else {
                // No record found - this should not happen as we found it earlier
                throw new Exception("No billing period found with id: $billingPeriodIdForUpdate. This should not happen as we found it earlier.");
            }
        } elseif ($rowsAffected > 1) {
            // This should never happen with ID (primary key)
            // Multiple rows updated indicates a database integrity issue
        }

        // ---------- 4.8 Update HWM in accounts (capital.com only) ----------
        if ($broker !== 'capital.com') {
            // For binance/ig, HWM is stored directly in the invoice table — no accounts table to update.
            // Reconnect symmetry: re-enable this user's accounts for the paid broker so a
            // non-payment disconnect is lifted once the invoice is paid.
            if ($broker === 'binance') {
                mysqli_query($conn, "UPDATE binance_accounts SET enabled = 1 WHERE uni_id = '$actualUserIdEscaped'");
            } elseif ($broker === 'ig') {
                mysqli_query($conn, "UPDATE ig_accounts SET enabled = 1 WHERE uni_id = '$actualUserIdEscaped'");
            }
            mysqli_commit($conn);
            goto stripe_webhook_success;
        }

        $allBillingPeriodsQuery = "
            SELECT DISTINCT
                bp.account_id,
                bp.hwm_after,
                a.uni_id,
                a.deposit_amount,
                a.unrealized_pnl
            FROM billing_periods bp
            INNER JOIN accounts a ON bp.account_id = a.id
            WHERE bp.user_id   = '$actualUserIdEscaped'
              AND bp.month_year = '$monthYearEscaped'";

        $billingResult = mysqli_query($conn, $allBillingPeriodsQuery);
        if (!$billingResult) {
            throw new Exception("Stripe webhook: Failed to query billing periods for HWM: " . mysqli_error($conn));
        }

        $accountsUpdated = 0;

        while ($accountRow = mysqli_fetch_assoc($billingResult)) {
            $accId         = (int)$accountRow['account_id'];
            $depositAmount = (float)($accountRow['deposit_amount'] ?? 0);
            $unrealizedPnl = (float)($accountRow['unrealized_pnl'] ?? 0);

            // Realized PnL from trades
            $realizedPnlSql    = "SELECT COALESCE(SUM(pnl), 0) AS total_pnl FROM trades WHERE account_id = $accId";
            $realizedPnlResult = mysqli_query($conn, $realizedPnlSql);

            $realizedPnl = 0.0;
            if ($realizedPnlResult) {
                $realizedPnlRow = mysqli_fetch_assoc($realizedPnlResult);
                $realizedPnl    = isset($realizedPnlRow['total_pnl']) ? (float)$realizedPnlRow['total_pnl'] : 0.0;
                mysqli_free_result($realizedPnlResult);
            }

            // Equity = deposit + unrealized + realized
            $currentEquity = $depositAmount + $unrealizedPnl + $realizedPnl;

            // Use hwm_after if present, else current equity
            $newHwmEquity = isset($accountRow['hwm_after']) && $accountRow['hwm_after'] > 0
                ? (float)$accountRow['hwm_after']
                : $currentEquity;

            // Only positive unrealized counts towards HWM
            $unrealizedForHwm = $unrealizedPnl > 0 ? $unrealizedPnl : 0;

            $newHwmEquityEscaped      = mysqli_real_escape_string($conn, (string)$newHwmEquity);
            $realizedPnlEscaped       = mysqli_real_escape_string($conn, (string)$realizedPnl);
            $unrealizedForHwmEscaped  = mysqli_real_escape_string($conn, (string)$unrealizedForHwm);
            $actualUserIdEscaped2     = mysqli_real_escape_string($conn, $actualUserId);

            $updateAccountQuery = "
                UPDATE accounts
                SET
                    hwm_equity     = GREATEST(COALESCE(hwm_equity, 0), $newHwmEquityEscaped),
                    hwm_realized   = GREATEST(COALESCE(hwm_realized, 0), $realizedPnlEscaped),
                    hwm_unrealized = GREATEST(COALESCE(hwm_unrealized, 0), $unrealizedForHwmEscaped),
                    last_billed_month = '$monthYearEscaped',
                    last_billed_at    = NOW(),
                    enabled          = 1
                WHERE id = $accId
                  AND uni_id = '$actualUserIdEscaped2'";

            if (mysqli_query($conn, $updateAccountQuery)) {
                $accountsUpdated++;
            }
        }

        mysqli_free_result($billingResult);

        // ---------- 4.9 Commit transaction ----------
        mysqli_commit($conn);

        stripe_webhook_success:

        // ---------- 4.10 Save payment method for auto-charging ----------
        $paymentMethodSaved = false;
        $paymentMethodError = null;
        
        // Debug logging function
        $debugLog = function($message, $data = null) use ($billingPeriodId, $actualUserId) {
            $logFile = __DIR__ . '/../log/invoice-debug.txt';
            $timestamp = date('Y-m-d H:i:s');
            $logEntry = "[$timestamp] [BillingPeriod:$billingPeriodId] [User:$actualUserId] $message";
            if ($data !== null) {
                $logEntry .= " | Data: " . json_encode($data, JSON_UNESCAPED_SLASHES);
            }
            $logEntry .= "\n";
            file_put_contents($logFile, $logEntry, FILE_APPEND);
            error_log("Invoice Debug: $message");
        };
        
        $debugLog("=== Starting payment method save process ===");
        $debugLog("Session ID: " . ($session->id ?? 'null'));
        $debugLog("Session payment_status: " . ($session->payment_status ?? 'null'));
        
        try {
            // Check if checkout session already has a customer
            $sessionCustomerId = $session->customer ?? null;
            $debugLog("Customer ID from checkout session", ['customer_id' => $sessionCustomerId]);
            
            // Retrieve the payment intent from the checkout session
            $paymentIntentId = $session->payment_intent ?? null;
            $debugLog("Payment Intent ID from session", ['payment_intent' => $paymentIntentId]);
            
            if ($paymentIntentId) {
                // Retrieve the payment intent to get the payment method
                $paymentIntent = \Stripe\PaymentIntent::retrieve($paymentIntentId);
                $paymentMethodId = $paymentIntent->payment_method ?? null;
                $paymentIntentCustomerId = $paymentIntent->customer ?? null;
                $debugLog("Payment Method ID from PaymentIntent", [
                    'payment_method_id' => $paymentMethodId, 
                    'payment_intent_status' => $paymentIntent->status ?? 'null',
                    'payment_intent_customer_id' => $paymentIntentCustomerId
                ]);
                
                if ($paymentMethodId) {
                    $debugLog("Payment method ID found, proceeding to save", ['payment_method_id' => $paymentMethodId]);
                    
                    // Use customer from session or payment intent if available
                    $stripeCustomerId = $sessionCustomerId ?? $paymentIntentCustomerId ?? null;
                    
                    // Get user email for customer creation (if needed)
                    $userEmail = null;
                    if (!$stripeCustomerId) {
                        $userEmailQuery = "SELECT email FROM user_credentials WHERE uni_id = '$actualUserIdEscaped' LIMIT 1";
                        $userEmailResult = mysqli_query($conn, $userEmailQuery);
                        if ($userEmailResult && mysqli_num_rows($userEmailResult) > 0) {
                            $userEmailRow = mysqli_fetch_assoc($userEmailResult);
                            $userEmail = $userEmailRow['email'] ?? null;
                            mysqli_free_result($userEmailResult);
                            $debugLog("User email from user_credentials", ['email' => $userEmail]);
                        }
                        
                        // If no email from user_credentials, try accounts table
                        if (!$userEmail) {
                            $accountEmailQuery = "SELECT email FROM accounts WHERE id = $actualAccountId LIMIT 1";
                            $accountEmailResult = mysqli_query($conn, $accountEmailQuery);
                            if ($accountEmailResult && mysqli_num_rows($accountEmailResult) > 0) {
                                $accountEmailRow = mysqli_fetch_assoc($accountEmailResult);
                                $userEmail = $accountEmailRow['email'] ?? null;
                                mysqli_free_result($accountEmailResult);
                                $debugLog("User email from accounts", ['email' => $userEmail]);
                            }
                        }
                    }
                    
                    // Check if customer already exists in our database for this user
                    if (!$stripeCustomerId) {
                        $existingCustomerQuery = "SELECT stripe_customer_id FROM stripe_payment_methods WHERE uni_id = '$actualUserIdEscaped' AND is_active = 1 LIMIT 1";
                        $existingCustomerResult = mysqli_query($conn, $existingCustomerQuery);
                        
                        if ($existingCustomerResult && mysqli_num_rows($existingCustomerResult) > 0) {
                            $existingRow = mysqli_fetch_assoc($existingCustomerResult);
                            $stripeCustomerId = $existingRow['stripe_customer_id'] ?? null;
                            mysqli_free_result($existingCustomerResult);
                            $debugLog("Existing Stripe customer found in database", ['stripe_customer_id' => $stripeCustomerId]);
                        } else {
                            $debugLog("No existing Stripe customer found for user");
                        }
                    } else {
                        $debugLog("Using customer from checkout session/payment intent", ['stripe_customer_id' => $stripeCustomerId]);
                    }
                    
                    // Create Stripe Customer only if we don't have one
                    if (!$stripeCustomerId) {
                        $debugLog("Creating new Stripe customer");
                        $customerParams = [
                            'metadata' => [
                                'uni_id' => $actualUserId
                            ]
                        ];
                        
                        if ($userEmail) {
                            $customerParams['email'] = $userEmail;
                        }
                        
                        $stripeCustomer = \Stripe\Customer::create($customerParams);
                        $stripeCustomerId = $stripeCustomer->id;
                        $debugLog("Stripe customer created", ['stripe_customer_id' => $stripeCustomerId]);
                    } else {
                        // Verify customer still exists in Stripe
                        try {
                            $stripeCustomer = \Stripe\Customer::retrieve($stripeCustomerId);
                            $debugLog("Stripe customer verified", ['stripe_customer_id' => $stripeCustomerId]);
                        } catch (\Stripe\Exception\InvalidRequestException $e) {
                            // Customer doesn't exist, create new one
                            $debugLog("Existing customer ID invalid, creating new one", ['error' => $e->getMessage()]);
                            $customerParams = [
                                'metadata' => [
                                    'uni_id' => $actualUserId
                                ]
                            ];
                            
                            if ($userEmail) {
                                $customerParams['email'] = $userEmail;
                            }
                            
                            $stripeCustomer = \Stripe\Customer::create($customerParams);
                            $stripeCustomerId = $stripeCustomer->id;
                            $debugLog("New Stripe customer created after invalid ID", ['stripe_customer_id' => $stripeCustomerId]);
                        }
                    }
                    
                    // Track if this PM cannot be attached to the customer (single-use / previously used without Customer).
                    // If so, we save with is_default=0 so auto-charge won't use it; user must add a new PM via checkout.
                    $pmNotUsableForAutoCharge = false;
                    
                    // Check if payment method is already attached to a customer
                    try {
                        $paymentMethodObj = \Stripe\PaymentMethod::retrieve($paymentMethodId);
                        $existingCustomerOnPm = $paymentMethodObj->customer ?? null;
                        $debugLog("Payment method details retrieved", [
                            'type' => $paymentMethodObj->type ?? 'null',
                            'existing_customer' => $existingCustomerOnPm,
                            'card_brand' => $paymentMethodObj->card->brand ?? 'null',
                            'card_last4' => $paymentMethodObj->card->last4 ?? 'null'
                        ]);
                        
                        // If payment method is already attached to a customer, use that customer
                        if ($existingCustomerOnPm) {
                            if ($existingCustomerOnPm !== $stripeCustomerId) {
                                $debugLog("Payment method already attached to different customer, using existing customer", [
                                    'existing_customer' => $existingCustomerOnPm,
                                    'our_customer' => $stripeCustomerId
                                ]);
                                $stripeCustomerId = $existingCustomerOnPm;
                            } else {
                                $debugLog("Payment method already attached to our customer, no need to attach");
                            }
                        } else {
                            // Payment method is not attached, try to attach it
                            try {
                                \Stripe\PaymentMethod::retrieve($paymentMethodId)->attach(['customer' => $stripeCustomerId]);
                                $debugLog("Payment method attached to customer", ['payment_method_id' => $paymentMethodId, 'customer_id' => $stripeCustomerId]);
                            } catch (\Stripe\Exception\ApiErrorException $e) {
                                // If attachment fails because it was already used without Customer, PM is single-use
                                if (strpos($e->getMessage(), 'previously used') !== false && $paymentIntentCustomerId) {
                                    $pmNotUsableForAutoCharge = true;
                                    $debugLog("Payment method cannot be attached (Stripe: previously used without Customer attachment). This PM cannot be used for auto-charge. Will save with is_default=0.", [
                                        'error' => $e->getMessage(),
                                        'payment_intent_customer' => $paymentIntentCustomerId,
                                        'context' => 'User must add a new payment method by paying an invoice through the checkout link.'
                                    ]);
                                    $stripeCustomerId = $paymentIntentCustomerId;
                                } else {
                                    $debugLog("ERROR: Failed to attach payment method", ['error' => $e->getMessage()]);
                                    throw $e;
                                }
                            }
                        }
                    } catch (\Stripe\Exception\ApiErrorException $e) {
                        $debugLog("ERROR: Failed to retrieve payment method", ['error' => $e->getMessage()]);
                        throw $e;
                    }
                    
                    // Set as default payment method for customer (only works if PM is attached to customer on Stripe)
                    try {
                        \Stripe\Customer::update($stripeCustomerId, [
                            'invoice_settings' => [
                                'default_payment_method' => $paymentMethodId
                            ]
                        ]);
                        $debugLog("Payment method set as default for customer");
                    } catch (\Stripe\Exception\ApiErrorException $e) {
                        $errMsg = $e->getMessage();
                        $isNotAttached = (strpos($errMsg, 'must be attached') !== false || strpos($errMsg, 'does not have a payment method') !== false);
                        if ($isNotAttached) {
                            $pmNotUsableForAutoCharge = true;
                            $debugLog("Stripe: Customer does not have this payment method attached (PM was used without Customer attachment). Saving with is_default=0 so auto-charge will not use it.", [
                                'error' => $errMsg,
                                'payment_method_id' => $paymentMethodId,
                                'customer_id' => $stripeCustomerId,
                                'context' => 'User must add a new payment method by paying an invoice through the checkout link.'
                            ]);
                        } else {
                            $debugLog("WARNING: Failed to set default payment method", ['error' => $errMsg]);
                        }
                    }
                    
                    // Get card details (already retrieved above)
                    $cardDetails = $paymentMethodObj->card ?? null;
                    
                    // Verify payment method is attached to our customer
                    $paymentMethodCustomer = $paymentMethodObj->customer ?? null;
                    if ($paymentMethodCustomer && $paymentMethodCustomer !== $stripeCustomerId) {
                        $debugLog("WARNING: Payment method attached to different customer, updating our customer ID", [
                            'payment_method_customer' => $paymentMethodCustomer,
                            'our_customer' => $stripeCustomerId
                        ]);
                        $stripeCustomerId = $paymentMethodCustomer;
                    }
                    
                    // One PM per user: same stripe_payment_method_id -> update in place; new PM -> replace (delete others + insert)
                    $cardBrand = $cardDetails ? ($cardDetails->brand ?? null) : null;
                    $cardLast4 = $cardDetails ? ($cardDetails->last4 ?? null) : null;
                    $cardExpMonth = $cardDetails ? ($cardDetails->exp_month ?? null) : null;
                    $cardExpYear = $cardDetails ? ($cardDetails->exp_year ?? null) : null;
                    $paymentMethodType = $paymentMethodObj->type ?? 'card';
                    
                    if (empty($actualUserIdEscaped)) {
                        $debugLog("ERROR: actualUserIdEscaped is empty!", [
                            'actualUserId' => $actualUserId,
                            'billing_period_user_id' => $billingPeriod['user_id'] ?? 'null'
                        ]);
                        throw new Exception("Cannot save payment method: uni_id is empty. actualUserId: " . ($actualUserId ?? 'null'));
                    }
                    
                    $paymentMethodIdEscaped = mysqli_real_escape_string($conn, $paymentMethodId);
                    $isDefault = $pmNotUsableForAutoCharge ? 0 : 1;
                    
                    // Check if we already have this exact PaymentMethod for this user (same card / same Stripe PM)
                    $checkExistingQuery = "SELECT id FROM stripe_payment_methods 
                        WHERE uni_id = '$actualUserIdEscaped' AND stripe_payment_method_id = '$paymentMethodIdEscaped' LIMIT 1";
                    $checkResult = mysqli_query($conn, $checkExistingQuery);
                    $existingRow = ($checkResult && mysqli_num_rows($checkResult) > 0) ? mysqli_fetch_assoc($checkResult) : null;
                    if ($checkResult) {
                        mysqli_free_result($checkResult);
                    }
                    
                    if ($existingRow) {
                        // Same PM (same card): update in place, do not delete
                        $existingId = (int)$existingRow['id'];
                        $debugLog("Same payment method already stored, updating in place", ['id' => $existingId, 'stripe_payment_method_id' => $paymentMethodId, 'is_default' => $isDefault]);
                        $updateQuery = "UPDATE stripe_payment_methods SET 
                            stripe_customer_id = '" . mysqli_real_escape_string($conn, $stripeCustomerId) . "',
                            payment_method_type = '" . mysqli_real_escape_string($conn, $paymentMethodType) . "',
                            card_brand = " . ($cardBrand ? "'" . mysqli_real_escape_string($conn, $cardBrand) . "'" : "NULL") . ",
                            card_last4 = " . ($cardLast4 ? "'" . mysqli_real_escape_string($conn, $cardLast4) . "'" : "NULL") . ",
                            card_exp_month = " . ($cardExpMonth !== null ? (int)$cardExpMonth : "NULL") . ",
                            card_exp_year = " . ($cardExpYear !== null ? (int)$cardExpYear : "NULL") . ",
                            is_default = " . (int)$isDefault . ",
                            is_active = 1,
                            updated_at = NOW()
                            WHERE id = $existingId";
                        if (mysqli_query($conn, $updateQuery)) {
                            $paymentMethodSaved = true;
                            $debugLog("SUCCESS: Payment method updated (same PM)", ['id' => $existingId, 'is_default' => $isDefault]);
                            if ($pmNotUsableForAutoCharge) {
                                $debugLog("NOTE: This PM is not usable for auto-charge (could not be attached to customer). User should add a new payment method via checkout.", []);
                            }
                        } else {
                            $dbError = mysqli_error($conn);
                            $paymentMethodError = "Failed to update payment method: " . $dbError;
                            $debugLog("ERROR: Update failed", ['error' => $dbError]);
                            error_log("Stripe webhook: Payment method update error - " . $paymentMethodError);
                        }
                    } else {
                        // New PM: replace (delete all for this user, insert the new one)
                        $debugLog("New payment method: replacing existing row(s) for user", ['stripe_payment_method_id' => $paymentMethodId, 'is_default' => $isDefault]);
                        $deleteOldQuery = "DELETE FROM stripe_payment_methods WHERE uni_id = '$actualUserIdEscaped'";
                        $deleteResult = mysqli_query($conn, $deleteOldQuery);
                        $deletedCount = $deleteResult ? mysqli_affected_rows($conn) : 0;
                        $debugLog("Deleted existing payment methods", ['deleted_count' => $deletedCount]);
                        
                        $isActive = 1;
                        $insertPmQuery = "INSERT INTO stripe_payment_methods (
                            uni_id,
                            stripe_customer_id,
                            stripe_payment_method_id,
                            payment_method_type,
                            card_brand,
                            card_last4,
                            card_exp_month,
                            card_exp_year,
                            is_default,
                            is_active,
                            created_at,
                            updated_at
                        ) VALUES (
                            '$actualUserIdEscaped',
                            '" . mysqli_real_escape_string($conn, $stripeCustomerId) . "',
                            '$paymentMethodIdEscaped',
                            '" . mysqli_real_escape_string($conn, $paymentMethodType) . "',
                            " . ($cardBrand ? "'" . mysqli_real_escape_string($conn, $cardBrand) . "'" : "NULL") . ",
                            " . ($cardLast4 ? "'" . mysqli_real_escape_string($conn, $cardLast4) . "'" : "NULL") . ",
                            " . ($cardExpMonth !== null ? (int)$cardExpMonth : "NULL") . ",
                            " . ($cardExpYear !== null ? (int)$cardExpYear : "NULL") . ",
                            " . (int)$isDefault . ",
                            " . (int)$isActive . ",
                            NOW(),
                            NOW()
                        )";
                        $debugLog("Executing INSERT (new PM)", [
                            'uni_id' => $actualUserIdEscaped,
                            'stripe_customer_id' => $stripeCustomerId,
                            'stripe_payment_method_id' => $paymentMethodId,
                            'is_default' => $isDefault
                        ]);
                        if (mysqli_query($conn, $insertPmQuery)) {
                            $paymentMethodSaved = true;
                            $insertedId = mysqli_insert_id($conn);
                            $debugLog("SUCCESS: Payment method saved to database", ['inserted_id' => $insertedId, 'is_default' => $isDefault]);
                            if ($pmNotUsableForAutoCharge) {
                                $debugLog("NOTE: This PM is not usable for auto-charge (could not be attached to customer). User should add a new payment method via checkout.", []);
                            }
                        } else {
                            $dbError = mysqli_error($conn);
                            $paymentMethodError = "Failed to save payment method to database: " . $dbError;
                            $debugLog("ERROR: Database insert failed", ['error' => $dbError]);
                            error_log("Stripe webhook: Payment method save error - " . $paymentMethodError);
                        }
                    }
                } else {
                    $debugLog("WARNING: Payment method ID is null or empty", ['payment_intent_id' => $paymentIntentId]);
                }
            } else {
                $debugLog("WARNING: Payment Intent ID is null or empty from session", ['session_id' => $session->id ?? 'null']);
            }
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $paymentMethodError = "Stripe API error while saving payment method: " . $e->getMessage();
            $debugLog("EXCEPTION: Stripe API error", ['error' => $e->getMessage(), 'type' => get_class($e)]);
            error_log("Stripe webhook: Payment method save error - " . $paymentMethodError);
            // Don't fail the webhook if payment method saving fails
        } catch (Exception $e) {
            $paymentMethodError = "Error while saving payment method: " . $e->getMessage();
            $debugLog("EXCEPTION: General error", ['error' => $e->getMessage(), 'type' => get_class($e), 'trace' => $e->getTraceAsString()]);
            error_log("Stripe webhook: Payment method save error - " . $paymentMethodError);
            // Don't fail the webhook if payment method saving fails
        }
        
        $debugLog("=== Payment method save process completed ===", [
            'saved' => $paymentMethodSaved,
            'error' => $paymentMethodError
        ]);

        ob_clean();
        http_response_code(200);
        $response = [
            'success'          => true,
            'message'          => 'Stripe payment processed successfully',
            'stripe_session'   => $session->id,
            'account_id'       => $actualAccountId,
            'user_id'          => $actualUserId,
            'month_year'       => $monthYear,
            'amount'           => $amount,
            'currency'         => $currency,
            'payment_method'   => $paymentMethod,
            'status'           => 'paid',
            'accounts_updated' => $accountsUpdated
        ];
        
        // Add payment method save status to response
        if ($paymentMethodSaved) {
            $response['payment_method_saved'] = true;
        } elseif ($paymentMethodError) {
            $response['payment_method_saved'] = false;
            $response['payment_method_error'] = $paymentMethodError;
        } else {
            $response['payment_method_saved'] = false;
            $response['payment_method_error'] = 'Payment method ID not found in checkout session';
        }
        
        echo json_encode($response);
        ob_end_flush();
        exit();
    }

    // ---------- 5. Unhandled event types ----------
    ob_clean();
    http_response_code(200);
    echo json_encode(['success' => true, 'received' => true, 'event_type' => $event->type]);
    ob_end_flush();

} catch (Exception $e) {
    if (isset($conn)) {
        mysqli_rollback($conn);
    }

    $errorMessage = $e->getMessage();
    ob_clean();
    http_response_code(400);
    $errorResponse = [
        'success' => false,
        'error'   => $errorMessage,
        'session_id' => isset($session) ? ($session->id ?? 'unknown') : 'unknown',
        'event_type' => isset($event) ? ($event->type ?? 'unknown') : 'unknown'
    ];
    
    // Include metadata in error response for debugging
    if (isset($session) && isset($session->metadata)) {
        $errorResponse['metadata'] = $session->metadata;
    }
    
    echo json_encode($errorResponse, JSON_PRETTY_PRINT);
    ob_end_flush();
} finally {
    if (isset($conn) && $conn) {
        mysqli_close($conn);
    }
}
