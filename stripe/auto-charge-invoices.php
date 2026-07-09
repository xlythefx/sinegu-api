<?php
/**
 * Auto-Charge Invoices Script
 * Manually triggered script that charges pending invoices using stored payment methods
 * 
 * This script:
 * 1. Queries billing_periods where status = 'pending' and total_fee > 0
 * 2. For each billing period:
 *    - Gets user's default payment method
 *    - Skips DEMO accounts
 *    - Creates Stripe PaymentIntent with off_session: true
 *    - Updates billing_periods.status = 'paid' on success
 * 3. Returns summary of charges attempted/succeeded/failed
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
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

// Load Stripe PHP library
$stripeAutoloadPath = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($stripeAutoloadPath)) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Stripe PHP library not found. Run: composer require stripe/stripe-php'
    ]);
    ob_end_flush();
    exit();
}

require_once $stripeAutoloadPath;

try {
    // Initialize Stripe
    $stripeKey = getStripeSecretKey();
    if (empty($stripeKey)) {
        throw new Exception('Stripe secret key not configured');
    }
    \Stripe\Stripe::setApiKey($stripeKey);
    
    // Get optional parameters
    $input = @file_get_contents('php://input');
    $data = [];
    if ($input) {
        $data = json_decode($input, true) ?? [];
    }
    
    $dryRun = isset($data['dry_run']) && $data['dry_run'] === true;
    $limit = isset($data['limit']) ? (int)$data['limit'] : null;
    $skipDemo = isset($data['skip_demo']) ? (bool)$data['skip_demo'] : true; // Default to true (skip demos)
    
    // Results tracking
    $results = [
        'total_processed' => 0,
        'charges_attempted' => 0,
        'charges_succeeded' => 0,
        'charges_failed' => 0,
        'skipped_no_payment_method' => 0,
        'skipped_demo' => 0,
        'skipped_zero_fee' => 0,
        'errors' => [],
        'details' => []
    ];
    
    // Pending invoices with fees > 0, across all three brokers.
    // binance_accounts has no `demo`/`email` columns, so we synthesize them.
    // Each row carries a `broker` tag so the success branch knows which table to update.
    $brokerQueries = [
        'capital.com' => "SELECT
                bp.id AS billing_period_id,
                bp.user_id,
                bp.account_id,
                bp.month_year,
                bp.total_fee,
                a.currency_type,
                a.demo,
                a.email AS account_email,
                a.name AS account_name,
                'capital.com' AS broker,
                bp.created_at
            FROM billing_periods bp
            INNER JOIN accounts a ON bp.account_id = a.id
            WHERE bp.status = 'pending' AND bp.total_fee > 0",
        'binance' => "SELECT
                bp.id AS billing_period_id,
                bp.user_id,
                NULL AS account_id,
                bp.month_year,
                bp.total_fee,
                a.currency_type,
                0 AS demo,
                '' AS account_email,
                a.name AS account_name,
                'binance' AS broker,
                bp.created_at
            FROM binance_invoices bp
            INNER JOIN binance_accounts a ON bp.api_key = a.api_key
            WHERE bp.status = 'pending' AND bp.total_fee > 0",
        'ig' => "SELECT
                bp.id AS billing_period_id,
                bp.user_id,
                bp.account_id,
                bp.month_year,
                bp.total_fee,
                a.currency_type,
                a.demo,
                a.email AS account_email,
                a.name AS account_name,
                'ig' AS broker,
                bp.created_at
            FROM ig_invoices bp
            INNER JOIN ig_accounts a ON bp.api_key = a.api_key
            WHERE bp.status = 'pending' AND bp.total_fee > 0",
    ];

    $billingPeriods = [];
    foreach ($brokerQueries as $brokerName => $brokerQuery) {
        $brokerResult = mysqli_query($conn, $brokerQuery);
        if (!$brokerResult) {
            throw new Exception("Failed to query pending invoices for $brokerName: " . mysqli_error($conn));
        }
        while ($row = mysqli_fetch_assoc($brokerResult)) {
            $billingPeriods[] = $row;
        }
        mysqli_free_result($brokerResult);
    }

    // Sort merged rows by created_at (oldest first), matching the previous ordering.
    usort($billingPeriods, function ($a, $b) {
        return strcmp($a['created_at'] ?? '', $b['created_at'] ?? '');
    });

    if ($limit && $limit > 0) {
        $billingPeriods = array_slice($billingPeriods, 0, (int)$limit);
    }
    
    $results['total_processed'] = count($billingPeriods);
    
    // Process each billing period
    foreach ($billingPeriods as $billingPeriod) {
        $billingPeriodId = (int)$billingPeriod['billing_period_id'];
        $userId = $billingPeriod['user_id'];
        $accountId = (int)$billingPeriod['account_id'];
        $totalFee = (float)$billingPeriod['total_fee'];
        $currency = strtolower($billingPeriod['currency_type'] ?? 'usd');
        $isDemo = (int)$billingPeriod['demo'] === 1;
        $broker = $billingPeriod['broker'] ?? 'capital.com';
        $invoiceTable = ($broker === 'binance') ? 'binance_invoices' : (($broker === 'ig') ? 'ig_invoices' : 'billing_periods');
        
        $detail = [
            'billing_period_id' => $billingPeriodId,
            'broker' => $broker,
            'account_id' => $accountId,
            'account_name' => $billingPeriod['account_name'],
            'user_id' => $userId,
            'month_year' => $billingPeriod['month_year'],
            'total_fee' => $totalFee,
            'currency' => $currency,
            'status' => 'pending'
        ];
        
        // Skip DEMO accounts if skip_demo is true
        if ($isDemo && $skipDemo) {
            $results['skipped_demo']++;
            $detail['status'] = 'skipped';
            $detail['reason'] = 'DEMO account';
            $results['details'][] = $detail;
            continue;
        }
        
        // Skip zero fee (shouldn't happen due to query, but double-check)
        if ($totalFee <= 0) {
            $results['skipped_zero_fee']++;
            $detail['status'] = 'skipped';
            $detail['reason'] = 'Zero fee';
            $results['details'][] = $detail;
            continue;
        }
        
        // Get user's default payment method
        $userIdEscaped = mysqli_real_escape_string($conn, $userId);
        $paymentMethodQuery = "SELECT 
                                id,
                                uni_id,
                                stripe_customer_id,
                                stripe_payment_method_id,
                                card_brand,
                                card_last4,
                                is_default
                              FROM stripe_payment_methods
                              WHERE uni_id = '$userIdEscaped'
                                AND is_active = 1
                                AND is_default = 1
                              LIMIT 1";
        
        $paymentMethodResult = mysqli_query($conn, $paymentMethodQuery);
        
        if (!$paymentMethodResult || mysqli_num_rows($paymentMethodResult) === 0) {
            $results['skipped_no_payment_method']++;
            $detail['status'] = 'skipped';
            $detail['reason'] = 'No default payment method found';
            $results['details'][] = $detail;
            continue;
        }
        
        $paymentMethod = mysqli_fetch_assoc($paymentMethodResult);
        mysqli_free_result($paymentMethodResult);
        
        $stripeCustomerId = $paymentMethod['stripe_customer_id'];
        $stripePaymentMethodId = $paymentMethod['stripe_payment_method_id'];
        
        error_log("[AutoCharge] BP#$billingPeriodId user_id=$userId stripe_customer_id=$stripeCustomerId stripe_payment_method_id=$stripePaymentMethodId");
        
        $detail['payment_method_id'] = (int)$paymentMethod['id'];
        $detail['card_brand'] = $paymentMethod['card_brand'];
        $detail['card_last4'] = $paymentMethod['card_last4'];
        
        // Attempt to charge
        $results['charges_attempted']++;
        
        try {
            if ($dryRun) {
                // Dry run: don't actually charge
                $detail['status'] = 'dry_run';
                $detail['message'] = 'Dry run - charge would be attempted';
                $results['details'][] = $detail;
                continue;
            }
            
            // Ensure payment method is attached to customer before using it
            // This is required for off-session payments
            try {
                error_log("[AutoCharge] BP#$billingPeriodId Retrieving PaymentMethod " . $stripePaymentMethodId);
                $stripePaymentMethod = \Stripe\PaymentMethod::retrieve($stripePaymentMethodId);
                $pmCustomer = $stripePaymentMethod->customer ?? null;
                error_log("[AutoCharge] BP#$billingPeriodId PaymentMethod->customer=" . ($pmCustomer ?: 'null') . " expected=" . $stripeCustomerId);
                if (!$pmCustomer || $pmCustomer !== $stripeCustomerId) {
                    error_log("[AutoCharge] BP#$billingPeriodId Attaching PaymentMethod to Customer");
                    $stripePaymentMethod->attach(['customer' => $stripeCustomerId]);
                    error_log("[AutoCharge] BP#$billingPeriodId Attach OK");
                }
            } catch (\Stripe\Exception\ApiErrorException $attachError) {
                error_log("[AutoCharge] BP#$billingPeriodId Attach failed: " . $attachError->getMessage());
                if (method_exists($attachError, 'getJsonBody')) {
                    error_log("[AutoCharge] BP#$billingPeriodId Attach json: " . json_encode($attachError->getJsonBody()));
                }
            }
            
            error_log("[AutoCharge] BP#$billingPeriodId Creating PaymentIntent amount=" . round($totalFee * 100) . " currency=$currency customer=$stripeCustomerId payment_method=$stripePaymentMethodId");
            // Create PaymentIntent with off_session: true
            $paymentIntentParams = [
                'amount' => (int)round($totalFee * 100), // Convert to cents
                'currency' => $currency,
                'customer' => $stripeCustomerId,
                'payment_method' => $stripePaymentMethodId,
                'off_session' => true,
                'confirm' => true,
                'metadata' => [
                    'billing_period_id' => (string)$billingPeriodId,
                    'account_id' => (string)$accountId,
                    'user_id' => $userId,
                    'month_year' => $billingPeriod['month_year'],
                    'broker' => $broker,
                    'auto_charge' => 'true'
                ]
            ];
            
            $paymentIntent = \Stripe\PaymentIntent::create($paymentIntentParams);
            
            // Check payment intent status
            if ($paymentIntent->status === 'succeeded') {
                // Payment succeeded - update the broker's invoice table and re-enable user's accounts
                mysqli_begin_transaction($conn);

                $updateQuery = "UPDATE $invoiceTable
                               SET status = 'paid',
                                   updated_at = NOW()
                               WHERE id = $billingPeriodId";

                if (mysqli_query($conn, $updateQuery)) {
                    $userIdEscaped = mysqli_real_escape_string($conn, $billingPeriod['user_id']);
                    // Reconnect symmetry: re-enable this user's accounts on the paid broker's
                    // table so a non-payment disconnect (enabled = 0) is lifted after payment.
                    // All three brokers now have an `enabled` column.
                    if ($broker === 'binance') {
                        mysqli_query($conn, "UPDATE binance_accounts SET enabled = 1 WHERE uni_id = '$userIdEscaped'");
                    } elseif ($broker === 'ig') {
                        mysqli_query($conn, "UPDATE ig_accounts SET enabled = 1 WHERE uni_id = '$userIdEscaped'");
                    } else {
                        mysqli_query($conn, "UPDATE accounts SET enabled = 1 WHERE uni_id = '$userIdEscaped'");
                    }
                    mysqli_commit($conn);
                    $results['charges_succeeded']++;
                    $detail['status'] = 'succeeded';
                    $detail['payment_intent_id'] = $paymentIntent->id;
                    $detail['message'] = 'Charge successful';
                } else {
                    mysqli_rollback($conn);
                    $results['charges_failed']++;
                    $detail['status'] = 'failed';
                    $detail['error'] = 'Database update failed: ' . mysqli_error($conn);
                    $results['errors'][] = [
                        'billing_period_id' => $billingPeriodId,
                        'error' => $detail['error']
                    ];
                }
            } elseif ($paymentIntent->status === 'requires_action') {
                // 3D Secure or other action required
                // For off-session charges, this is a problem
                $results['charges_failed']++;
                $detail['status'] = 'failed';
                $detail['error'] = 'Payment requires authentication (3D Secure). Cannot complete off-session.';
                $detail['payment_intent_id'] = $paymentIntent->id;
                $results['errors'][] = [
                    'billing_period_id' => $billingPeriodId,
                    'error' => $detail['error'],
                    'payment_intent_id' => $paymentIntent->id
                ];
            } else {
                // Payment failed
                $results['charges_failed']++;
                $detail['status'] = 'failed';
                $detail['error'] = 'Payment intent status: ' . $paymentIntent->status;
                $detail['payment_intent_id'] = $paymentIntent->id;
                if (isset($paymentIntent->last_payment_error)) {
                    $detail['error'] .= ' - ' . ($paymentIntent->last_payment_error->message ?? 'Unknown error');
                }
                $results['errors'][] = [
                    'billing_period_id' => $billingPeriodId,
                    'error' => $detail['error'],
                    'payment_intent_id' => $paymentIntent->id
                ];
            }
            
        } catch (\Stripe\Exception\CardException $e) {
            // Card was declined
            $results['charges_failed']++;
            $detail['status'] = 'failed';
            $detail['error'] = 'Card declined: ' . $e->getMessage();
            $results['errors'][] = [
                'billing_period_id' => $billingPeriodId,
                'error' => $detail['error']
            ];
        } catch (\Stripe\Exception\RateLimitException $e) {
            // Too many requests
            $results['charges_failed']++;
            $detail['status'] = 'failed';
            $detail['error'] = 'Rate limit error: ' . $e->getMessage();
            $results['errors'][] = [
                'billing_period_id' => $billingPeriodId,
                'error' => $detail['error']
            ];
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            // Invalid request
            error_log("[AutoCharge] BP#$billingPeriodId InvalidRequestException: " . $e->getMessage());
            $results['charges_failed']++;
            $detail['status'] = 'failed';
            $errMsg = $e->getMessage();
            if (strpos($errMsg, 'previously used') !== false || strpos($errMsg, 'without Customer attachment') !== false || strpos($errMsg, 'detached from a Customer') !== false) {
                $detail['error'] = 'This payment method cannot be used for automatic charges. Please add a new payment method by paying an invoice through the checkout link.';
                // Mark PM as not default so we don't keep retrying this card
                $pmId = (int)$paymentMethod['id'];
                @mysqli_query($conn, "UPDATE stripe_payment_methods SET is_default = 0 WHERE id = " . $pmId);
            } else {
                $detail['error'] = 'Invalid request: ' . $errMsg;
            }
            $results['errors'][] = [
                'billing_period_id' => $billingPeriodId,
                'error' => $detail['error']
            ];
        } catch (\Stripe\Exception\AuthenticationException $e) {
            // Authentication failed
            throw new Exception('Stripe authentication failed: ' . $e->getMessage());
        } catch (\Stripe\Exception\ApiConnectionException $e) {
            // Network error
            $results['charges_failed']++;
            $detail['status'] = 'failed';
            $detail['error'] = 'Network error: ' . $e->getMessage();
            $results['errors'][] = [
                'billing_period_id' => $billingPeriodId,
                'error' => $detail['error']
            ];
        } catch (\Stripe\Exception\ApiErrorException $e) {
            // Generic Stripe API error
            error_log("[AutoCharge] BP#$billingPeriodId ApiErrorException: " . $e->getMessage());
            $results['charges_failed']++;
            $detail['status'] = 'failed';
            $errMsg = $e->getMessage();
            if (strpos($errMsg, 'previously used') !== false || strpos($errMsg, 'without Customer attachment') !== false || strpos($errMsg, 'detached from a Customer') !== false) {
                $detail['error'] = 'This payment method cannot be used for automatic charges. Please add a new payment method by paying an invoice through the checkout link.';
                $pmId = (int)$paymentMethod['id'];
                @mysqli_query($conn, "UPDATE stripe_payment_methods SET is_default = 0 WHERE id = " . $pmId);
            } else {
                $detail['error'] = 'Stripe API error: ' . $errMsg;
            }
            $results['errors'][] = [
                'billing_period_id' => $billingPeriodId,
                'error' => $detail['error']
            ];
        } catch (Exception $e) {
            // Other errors
            error_log("[AutoCharge] BP#$billingPeriodId Exception: " . $e->getMessage());
            $results['charges_failed']++;
            $detail['status'] = 'failed';
            $detail['error'] = 'Error: ' . $e->getMessage();
            $results['errors'][] = [
                'billing_period_id' => $billingPeriodId,
                'error' => $detail['error']
            ];
        }
        
        $results['details'][] = $detail;
    }
    
    ob_clean();
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Auto-charge process completed',
        'dry_run' => $dryRun,
        'results' => $results
    ]);
    ob_end_flush();
    
} catch (Exception $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'results' => $results ?? []
    ]);
    ob_end_flush();
} finally {
    if (isset($conn) && $conn) {
        mysqli_close($conn);
    }
}
?>
