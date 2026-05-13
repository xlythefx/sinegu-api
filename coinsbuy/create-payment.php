<?php
/**
 * Coinsbuy Payment/Deposit Creation
 * Creates a Coinsbuy deposit/payment for invoice payment
 */

// Suppress all output and errors to prevent HTML in JSON response
if (function_exists('xdebug_disable')) {
    xdebug_disable();
}
if (function_exists('ini_set')) {
    @ini_set('xdebug.overload_var_dump', 0);
    @ini_set('xdebug.default_enable', 0);
    @ini_set('xdebug.show_exception_trace', 0);
    @ini_set('xdebug.mode', 'off');
}

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('html_errors', 0);

// Start output buffering immediately
ob_start();

// Set headers first
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_clean();
    http_response_code(200);
    exit();
}

// Helper function to send JSON error response
function sendJsonError($message, $code = 400, $details = null) {
    ob_clean();
    http_response_code($code);
    $response = [
        'success' => false,
        'error' => $message
    ];
    if ($details !== null) {
        $response['details'] = $details;
    }
    echo json_encode($response);
    ob_end_flush();
    exit();
}

// Helper function to send JSON success response
function sendJsonSuccess($data) {
    ob_clean();
    http_response_code(200);
    echo json_encode(array_merge(['success' => true], $data));
    ob_end_flush();
    exit();
}

try {
    // Include required files
    $connPath = __DIR__ . '/../conn.php';
    $configPath = __DIR__ . '/config.php';
    
    $connPath = str_replace('\\', '/', $connPath);
    $configPath = str_replace('\\', '/', $configPath);
    
    if (!file_exists($connPath)) {
        sendJsonError('Database configuration file not found', 500);
    }
    
    if (!file_exists($configPath)) {
        sendJsonError('Coinsbuy configuration file not found', 500);
    }
    
    @require_once $connPath;
    @require_once $configPath;
    
    if (!$conn) {
        $errorMsg = isset($conn_error) ? $conn_error : 'Unknown database error';
        error_log("Database connection failed: " . $errorMsg);
        sendJsonError('Database connection failed', 500);
    }
    
    // Get payment data from request
    $input = @file_get_contents('php://input');
    if ($input === false || empty($input)) {
        sendJsonError('No request data received');
    }
    
    $data = json_decode($input, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        sendJsonError('Invalid JSON data: ' . json_last_error_msg());
    }
    
    if (!is_array($data)) {
        sendJsonError('Request data must be a JSON object');
    }
    
    // Validate required fields
    $errors = [];
    
    $billingPeriodId = isset($data['billing_period_id']) && !empty($data['billing_period_id'])
        ? filter_var($data['billing_period_id'], FILTER_VALIDATE_INT)
        : null;
    
    if (!$billingPeriodId || $billingPeriodId <= 0) {
        sendJsonError('billing_period_id is required. The billing period ID is missing from the request.', 400);
    }
    
    if (!isset($data['amount']) || !is_numeric($data['amount']) || $data['amount'] <= 0) {
        $errors[] = 'Valid amount is required (must be a positive number)';
    }
    
    if (!isset($data['currency']) || empty($data['currency'])) {
        $errors[] = 'currency is required';
    }
    
    // Coinsbuy specific: cryptocurrency type (optional - uses default from config)
    
    if (!empty($errors)) {
        sendJsonError('Validation failed', 400, $errors);
    }
    
    $amount = filter_var($data['amount'], FILTER_VALIDATE_FLOAT);
    if ($amount === false || $amount <= 0) {
        sendJsonError('Invalid amount: must be a positive number');
    }
    
    $currency = strtoupper(trim($data['currency']));
    $cryptocurrency = isset($data['cryptocurrency']) && !empty(trim($data['cryptocurrency']))
        ? strtoupper(trim($data['cryptocurrency']))
        : getDefaultCryptocurrency();
    
    if (!in_array($cryptocurrency, SUPPORTED_CRYPTOCURRENCIES)) {
        sendJsonError('Cryptocurrency not supported. Supported: ' . implode(', ', SUPPORTED_CRYPTOCURRENCIES));
    }
    
    // Verify billing period exists and is unpaid
    $billingPeriodIdEscaped = (int)$billingPeriodId;

    // Determine broker
    $broker = isset($data['broker']) && in_array($data['broker'], ['capital.com', 'binance', 'ig'])
        ? $data['broker'] : 'capital.com';

    if ($broker === 'binance') {
        $checkQuery = "SELECT bp.id, NULL AS account_id, bp.user_id, bp.month_year, bp.total_fee, bp.status,
                        a.name AS account_name, '' AS account_email
                       FROM binance_invoices bp
                       INNER JOIN binance_accounts a ON bp.api_key = a.api_key
                       WHERE bp.id = $billingPeriodIdEscaped LIMIT 1";
    } elseif ($broker === 'ig') {
        $checkQuery = "SELECT bp.id, bp.account_id, bp.user_id, bp.month_year, bp.total_fee, bp.status,
                        a.name AS account_name, a.email AS account_email
                       FROM ig_invoices bp
                       INNER JOIN ig_accounts a ON bp.api_key = a.api_key
                       WHERE bp.id = $billingPeriodIdEscaped LIMIT 1";
    } else {
        $checkQuery = "SELECT bp.id, bp.account_id, bp.user_id, bp.month_year, bp.total_fee, bp.status,
                        a.name AS account_name, a.email AS account_email
                       FROM billing_periods bp
                       INNER JOIN accounts a ON bp.account_id = a.id
                       WHERE bp.id = $billingPeriodIdEscaped LIMIT 1";
    }

    $checkResult = @mysqli_query($conn, $checkQuery);
    if (!$checkResult) {
        $dbError = mysqli_error($conn);
        error_log("Database query error: " . $dbError);
        sendJsonError('Database error while verifying billing period', 500);
    }
    
    if (mysqli_num_rows($checkResult) === 0) {
        sendJsonError('Billing period not found. The billing period with ID ' . $billingPeriodId . ' does not exist.', 404);
    }
    
    $billingPeriod = mysqli_fetch_assoc($checkResult);
    if (!$billingPeriod) {
        sendJsonError('Failed to retrieve billing period data', 500);
    }
    
    $accountId = (int)$billingPeriod['account_id'];
    $userId = $billingPeriod['user_id'];
    $monthYear = $billingPeriod['month_year'];
    
    // Check if already paid
    if (isset($billingPeriod['status']) && $billingPeriod['status'] === 'paid') {
        sendJsonError('This billing period has already been paid', 400);
    }
    
    // Verify amount matches
    $expectedAmount = (float)($billingPeriod['total_fee'] ?? 0);
    if (abs($expectedAmount - $amount) > 0.01) {
        sendJsonError('Amount mismatch', 400, [
            'expected' => $expectedAmount,
            'received' => $amount,
        ]);
    }
    
    // Format month_year for display
    $formattedMonthYear = $monthYear;
    try {
        $date = DateTime::createFromFormat('Y-m', $monthYear);
        if ($date) {
            $formattedMonthYear = $date->format('F Y');
        }
    } catch (Exception $e) {
        error_log("Failed to format month_year: " . $monthYear);
    }
    
    // Prepare Coinsbuy deposit request for Merchant wallet
    // According to Coinsbuy API v3 JSON API format:
    // - All requests must follow JSON API specification
    // - Data structure: { "data": { "type": "deposit", "attributes": {...}, "relationships": {...} } }
    // - Wallet relationship is REQUIRED (Merchant wallet in USD/fiat currency)
    // - target_amount_requested: amount in wallet currency (USD) - required for Merchant wallets
    // - Callback URL is specified in attributes
    // - Currency relationship: optional - if specified, restricts payment to that crypto; if omitted, user can choose any
    
    $callbackUrl = getWebhookUrl();
    
    // Get Merchant wallet for the fiat currency (USD)
    // Merchant wallets accept payments in multiple cryptocurrencies
    $walletId = null;
    $isMerchantWallet = false;
    if (defined('COINSBUY_USD_WALLET_ID_OVERRIDE') && COINSBUY_USD_WALLET_ID_OVERRIDE !== '' && COINSBUY_USD_WALLET_ID_OVERRIDE !== null) {
        $walletId = (string) COINSBUY_USD_WALLET_ID_OVERRIDE;
        $isMerchantWallet = true;
    }
    if (!$walletId) {
        $walletId = getWalletForCurrency('USD');
        if ($walletId) {
            $isMerchantWallet = true;
        }
    }
    
    // If no USD wallet, fall back to the specified cryptocurrency wallet (Enterprise wallet)
    // This allows flexibility if Merchant wallets aren't configured
    if (!$walletId) {
        // Fallback: use wallet for the specified cryptocurrency (Enterprise wallet)
        $walletId = getWalletForCurrency($cryptocurrency);
        if (!$walletId) {
            sendJsonError(
                'No active wallet found. Ensure: (1) API Key and Secret in config.php are valid (regenerate in Coinsbuy if needed), ' .
                '(2) Sandbox/Production mode matches your Coinsbuy environment, (3) a Merchant wallet for USD (or wallet for ' . $cryptocurrency . ') exists and is Active.',
                500
            );
        }
    } else {
        // If we found a USD wallet, assume it's a Merchant wallet
        $isMerchantWallet = true;
    }
    
    // Coinsbuy API expects data in strict JSON API format
    // Type must be 'deposit' (singular), not 'deposits'
    // Wallet relationship is REQUIRED
    // Encode broker in tracking_id so webhook knows which table to update
    // Format: billing_{id}_{ts} (capital.com, backward-compat) | billing_binance_{id}_{ts} | billing_ig_{id}_{ts}
    if ($broker === 'binance') {
        $trackingId = 'billing_binance_' . $billingPeriodId . '_' . time();
    } elseif ($broker === 'ig') {
        $trackingId = 'billing_ig_' . $billingPeriodId . '_' . time();
    } else {
        $trackingId = 'billing_' . $billingPeriodId . '_' . time();
    }
    
    // Build deposit attributes
    // Coinsbuy label: max 32 chars, no special symbols like dashes (-)
    $accountName = $billingPeriod['account_name'] ?? 'Account ' . $accountId;
    // Sanitize label: remove special characters, keep only alphanumeric, spaces, and underscores
    $sanitizedAccountName = preg_replace('/[^a-zA-Z0-9 _]/', '', $accountName);
    // Create label without dashes, max 32 characters
    $label = 'Invoice Payment ' . $sanitizedAccountName;
    if (strlen($label) > 32) {
        $label = substr($label, 0, 32);
    }
    
    $depositAttributes = [
        'label' => $label,
        'tracking_id' => $trackingId,
        'confirmations_needed' => 1,  // Optional: number of confirmations before callback
        'callback_url' => $callbackUrl,
    ];
    
    // For Merchant wallets, add target_amount_requested (amount in wallet currency - USD)
    // This tells Coinsbuy how much we expect to receive and enables payment page functionality
    // target_amount_requested is ONLY for Merchant wallets, not Enterprise wallets
    if ($isMerchantWallet && ($currency === 'USD' || $currency === 'EUR')) {
        // Format amount as string with appropriate precision for Merchant wallet
        $depositAttributes['target_amount_requested'] = number_format($amount, 2, '.', '');
        // Optional: add inaccuracy to allow for rate fluctuations (e.g., 1% tolerance)
        $depositAttributes['inaccuracy'] = number_format($amount * 0.01, 2, '.', '');  // 1% tolerance
        // Set time limit for payment (optional, in seconds) - e.g., 1 hour
        $depositAttributes['time_limit'] = 3600;  // 1 hour
        // Payment page settings (only for Merchant wallets)
        $depositAttributes['payment_page_redirect_url'] = getSuccessUrl($trackingId);  // Where to redirect after payment
        $depositAttributes['payment_page_button_text'] = 'Return to Dashboard';  // Button text on payment page
    }
    
    // Build relationships
    $relationships = [
        'wallet' => [
            'data' => [
                'type' => 'wallet',
                'id' => $walletId,
            ],
        ],
    ];
    
    // Add currency relationship to pre-select specific cryptocurrency on payment page
    // Default: BTC in sandbox (isDevelopment), USDT in production
    if ($cryptocurrency && $cryptocurrency !== 'USD' && $cryptocurrency !== 'EUR') {
        $currencyId = getCurrencyId($cryptocurrency);
        if ($currencyId) {
            $relationships['currency'] = [
                'data' => [
                    'type' => 'currency',
                    'id' => $currencyId,
                ],
            ];
        } else {
            $defaultCrypto = getDefaultCryptocurrency();
            $fallbackId = getCurrencyId($defaultCrypto);
            if ($fallbackId) {
                $relationships['currency'] = [
                    'data' => [
                        'type' => 'currency',
                        'id' => $fallbackId,
                    ],
                ];
            }
        }
    } else {
        $defaultCrypto = getDefaultCryptocurrency();
        $defaultCurrencyId = getCurrencyId($defaultCrypto);
        if ($defaultCurrencyId) {
            $relationships['currency'] = [
                'data' => [
                    'type' => 'currency',
                    'id' => $defaultCurrencyId,
                ],
            ];
        }
    }
    
    $depositData = [
        'data' => [
            'type' => 'deposit',  // SINGULAR, not 'deposits'
            'attributes' => $depositAttributes,
            'relationships' => $relationships,
        ],
    ];
    
    // Store metadata separately for webhook processing
    // Coinsbuy uses tracking_id to link deposits to our system
    
    // Create deposit via Coinsbuy API
    // Endpoint: POST /deposit/ (singular, with trailing slash)
    $apiResponse = coinsbuyApiRequest('deposit/', 'POST', $depositData);
    
    if ($apiResponse === false) {
        sendJsonError('Failed to create Coinsbuy deposit. Please check API configuration.', 500);
    }
    
    if (isset($apiResponse['error']) && $apiResponse['error']) {
        $errorMsg = isset($apiResponse['response']['errors'][0]['detail']) 
            ? $apiResponse['response']['errors'][0]['detail']
            : 'Unknown API error';
        sendJsonError('Coinsbuy API error: ' . $errorMsg, $apiResponse['http_code'] ?? 500);
    }
    
    // Extract deposit information from response
    // Coinsbuy API returns data in JSON API format:
    // { "data": { "id": "...", "type": "deposit", "attributes": {...} } }
    if (!isset($apiResponse['data'])) {
        error_log('Unexpected Coinsbuy API response: ' . json_encode($apiResponse));
        sendJsonError('Invalid response from Coinsbuy API', 500);
    }
    
    $deposit = $apiResponse['data'];
    $depositId = isset($deposit['id']) ? $deposit['id'] : null;
    $attributes = $deposit['attributes'] ?? [];
    
    // Coinsbuy returns destination address(es) for the deposit
    // The user needs to send cryptocurrency to this address
    // Destination can be an object or array (for XRP)
    $destination = $attributes['destination'] ?? null;
    $destinationAddress = null;
    if (is_array($destination)) {
        // For XRP, destination is an array
        if (isset($destination[0]['address'])) {
            $destinationAddress = $destination[0]['address'];
        } elseif (isset($destination['address'])) {
            $destinationAddress = $destination['address'];
        }
    } elseif (is_string($destination)) {
        $destinationAddress = $destination;
    } elseif (isset($attributes['address'])) {
        // Fallback to address field
        $destinationAddress = $attributes['address'];
    }
    
    $status = $attributes['status'] ?? null;
    $targetPaid = isset($attributes['target_paid']) ? (float)$attributes['target_paid'] : 0;
    $paymentPage = $attributes['payment_page'] ?? null;
    
    // No need to store in separate table - tracking_id contains billing_period_id
    // Webhook will extract billing_period_id from tracking_id format: "billing_{id}_{timestamp}"
    // This matches Stripe's approach of using metadata to link payments to billing_periods
    
    if (!$depositId) {
        error_log('Coinsbuy deposit response missing deposit ID: ' . json_encode($apiResponse));
        sendJsonError('Invalid deposit response from Coinsbuy', 500);
    }
    
    // For Merchant wallets, payment_page is the primary URL users should visit
    // For Enterprise wallets, destination address is provided for direct crypto transfer
    $paymentUrl = $paymentPage ?? $destinationAddress;
    $paymentType = $paymentPage ? 'payment_page' : 'destination_address';
    
    // Build response
    $responseData = [
        'deposit_id' => $depositId,
        'status' => $status,
        'amount' => $amount,
        'currency' => $currency,
        'cryptocurrency' => $cryptocurrency,
        'tracking_id' => $trackingId,
        'payment_url' => $paymentUrl,  // Primary URL to redirect user to (payment_page for Merchant, destination for Enterprise)
        'payment_type' => $paymentType,  // 'payment_page' or 'destination_address'
    ];
    
    // Include both payment_page and destination for flexibility
    if ($paymentPage) {
        $responseData['payment_page'] = $paymentPage;  // Hosted payment page URL (Merchant wallets)
        $responseData['message'] = 'Payment page created. Visit the payment page to complete payment with cryptocurrency.';
    }
    
    if ($destinationAddress) {
        $responseData['destination'] = $destinationAddress;  // Crypto address for direct transfer
        $responseData['destination_object'] = $destination;  // Full destination object for reference
        if (!$paymentPage) {
            $responseData['message'] = 'Deposit created. Send ' . $cryptocurrency . ' to the provided address.';
        }
    }
    
    // Add target amount info if available
    if (isset($attributes['target_amount_requested'])) {
        $responseData['target_amount_requested'] = $attributes['target_amount_requested'];
    }
    if (isset($attributes['rate_requested'])) {
        $responseData['rate_requested'] = $attributes['rate_requested'];
    }
    if (isset($attributes['rate_expired_at'])) {
        $responseData['rate_expires_at'] = $attributes['rate_expired_at'];
    }
    
    sendJsonSuccess($responseData);
    
} catch (Exception $e) {
    error_log("Error in create-payment.php: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
    sendJsonError('An error occurred: ' . $e->getMessage(), 500);
} catch (Error $e) {
    error_log("PHP Fatal Error in create-payment.php: " . $e->getMessage());
    sendJsonError('A system error occurred', 500);
}

// Final safety net
if (ob_get_level() > 0) {
    $remainingOutput = ob_get_contents();
    if (!empty($remainingOutput)) {
        ob_clean();
        error_log("Unexpected output at end of create-payment.php: " . substr($remainingOutput, 0, 500));
    }
    ob_end_clean();
}

header('Content-Type: application/json');
http_response_code(500);
echo json_encode(['success' => false, 'error' => 'An unexpected error occurred']);
exit();

