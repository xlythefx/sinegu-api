<?php
/**
 * Coinsbuy Webhook Handler
 * Handles callbacks from Coinsbuy for deposit/payment status updates
 * Verifies HMAC-SHA256 signature for security
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

/**
 * Append-only insert into coinsbuy_transactions. Does not affect webhook flow on failure.
 * @param mysqli $conn
 * @param array $data Keys: deposit_id, transfer_id, tracking_id, billing_period_id, account_id, user_id,
 *   outcome, deposit_status, transfer_status, amount, amount_currency, expected_amount, crypto_currency,
 *   crypto_amount, transaction_hash, error_message
 */
function insertCoinsbuyTransaction($conn, $data) {
    static $tableExists = null;
    if ($tableExists === null) {
        $check = mysqli_query($conn, "SELECT 1 FROM coinsbuy_transactions LIMIT 0");
        $tableExists = ($check !== false);
    }
    if (!$tableExists) return;
    $depositId = $data['deposit_id'] ?? 'unknown';
    if ($depositId === '') {
        $depositId = 'unknown';
    }
    $stmt = mysqli_prepare($conn, "INSERT INTO coinsbuy_transactions (
        deposit_id, transfer_id, tracking_id, billing_period_id, account_id, user_id,
        outcome, deposit_status, transfer_status, amount, amount_currency, expected_amount,
        crypto_currency, crypto_amount, transaction_hash, error_message
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) return;
    $transferId = $data['transfer_id'] ?? null;
    $trackingId = $data['tracking_id'] ?? null;
    $billingPeriodId = isset($data['billing_period_id']) ? (int)$data['billing_period_id'] : null;
    $accountId = isset($data['account_id']) ? (int)$data['account_id'] : null;
    $userId = $data['user_id'] ?? null;
    $outcome = $data['outcome'] ?? 'unknown';
    $depositStatus = isset($data['deposit_status']) ? (int)$data['deposit_status'] : null;
    $transferStatus = isset($data['transfer_status']) ? (int)$data['transfer_status'] : null;
    $amount = isset($data['amount']) ? (float)$data['amount'] : null;
    $amountCurrency = $data['amount_currency'] ?? null;
    if ($amountCurrency === 0 || $amountCurrency === '0') $amountCurrency = null;
    $expectedAmount = isset($data['expected_amount']) ? (float)$data['expected_amount'] : null;
    $cryptoCurrency = $data['crypto_currency'] ?? null;
    if ($cryptoCurrency === 0 || $cryptoCurrency === '0') $cryptoCurrency = null;
    $cryptoAmount = isset($data['crypto_amount']) ? (float)$data['crypto_amount'] : null;
    $transactionHash = $data['transaction_hash'] ?? null;
    $errorMessage = isset($data['error_message']) ? substr((string)$data['error_message'], 0, 512) : null;
    // Types: deposit_id(s) transfer_id(s) tracking_id(s) billing_period_id(i) account_id(i) user_id(s)
    // outcome(s) deposit_status(i) transfer_status(i) amount(d) amount_currency(s) expected_amount(d)
    // crypto_currency(s) crypto_amount(d) transaction_hash(s) error_message(s) = 16 params
    $bindOk = mysqli_stmt_bind_param($stmt, 'sssiissiidsdsdss',
        $depositId, $transferId, $trackingId, $billingPeriodId, $accountId, $userId,
        $outcome, $depositStatus, $transferStatus, $amount, $amountCurrency, $expectedAmount,
        $cryptoCurrency, $cryptoAmount, $transactionHash, $errorMessage
    );
    if (!$bindOk) {
        error_log("Coinsbuy insertCoinsbuyTransaction: bind_param failed: " . mysqli_stmt_error($stmt));
        mysqli_stmt_close($stmt);
        return;
    }
    if (!mysqli_stmt_execute($stmt)) {
        error_log("Coinsbuy insertCoinsbuyTransaction: execute failed: " . mysqli_stmt_error($stmt));
    }
    mysqli_stmt_close($stmt);
}

// Log all webhook requests for debugging
error_log("Coinsbuy webhook called: " . date('Y-m-d H:i:s'));
error_log("Request method: " . $_SERVER['REQUEST_METHOD']);
error_log("Headers: " . json_encode(getallheaders()));

try {
    // ---------- 1. Load raw Coinsbuy payload ----------
    $payload = @file_get_contents('php://input');
    $signature = $_SERVER['HTTP_X_COINSBUY_SIGNATURE'] ?? $_SERVER['HTTP_SIGNATURE'] ?? '';
    
    // Log payload for debugging (first 500 chars)
    error_log("Coinsbuy webhook payload (first 500 chars): " . substr($payload, 0, 500));
    
    if (empty($payload)) {
        error_log("Coinsbuy webhook: Empty payload received");
        ob_clean();
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Empty payload']);
        ob_end_flush();
        exit();
    }
    
    // ---------- 2. Parse webhook payload first (needed for signature verification) ----------
    // Coinsbuy sends callbacks in JSON API format
    $event = json_decode($payload, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        ob_clean();
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON payload']);
        ob_end_flush();
        exit();
    }
    
    // Coinsbuy webhook structure follows JSON API format:
    // { "data": { "id": "...", "type": "deposit", "attributes": {...} }, "included": [...], "meta": {...} }
    $eventData = $event['data'] ?? $event;
    $eventType = $eventData['type'] ?? null;
    $eventAttributes = $eventData['attributes'] ?? [];
    $included = $event['included'] ?? [];
    $meta = $event['meta'] ?? [];
    
    // ---------- 3. Verify HMAC-SHA256 signature ----------
    // According to Coinsbuy documentation, signature is calculated from:
    // transfer.status + transfer.amount + deposit.tracking_id + meta.time
    $webhookSecret = getCoinsbuyWebhookSecret();
    if (empty($webhookSecret)) {
        error_log('Coinsbuy webhook secret not configured');
        ob_clean();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Webhook secret not configured']);
        ob_end_flush();
        exit();
    }
    
    // Extract transfer data from included array
    $transfer = null;
    foreach ($included as $item) {
        if (isset($item['type']) && $item['type'] === 'transfer') {
            $transfer = $item;
            break;
        }
    }
    
    // Build signature message according to Coinsbuy spec
    $transferStatus = $transfer['attributes']['status'] ?? '';
    $transferAmount = $transfer['attributes']['amount'] ?? '';
    $depositTrackingId = $eventAttributes['tracking_id'] ?? '';
    $metaTime = $meta['time'] ?? '';
    
    // Concatenate: transfer.status + transfer.amount + deposit.tracking_id + meta.time
    $signatureMessage = $transferStatus . $transferAmount . $depositTrackingId . $metaTime;
    
    // Get signature from meta.sign (Coinsbuy sends it in meta.sign, not HTTP header)
    $receivedSignature = $meta['sign'] ?? $signature;
    
    // Calculate expected signature
    $expectedSignature = hash_hmac('sha256', $signatureMessage, $webhookSecret);
    
    // Compare signatures (use hash_equals for timing attack prevention)
    if (!hash_equals($expectedSignature, $receivedSignature)) {
        error_log('Coinsbuy webhook signature verification failed');
        error_log('Signature message: ' . $signatureMessage);
        error_log('Expected: ' . $expectedSignature);
        error_log('Received: ' . $receivedSignature);
        ob_clean();
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid signature']);
        ob_end_flush();
        exit();
    }
    
    // ---------- 4. Extract deposit information ----------
    $depositId = $eventData['id'] ?? null;
    $status = $eventAttributes['status'] ?? null;
    
    // Get amount for verification: prefer target_paid (in wallet currency, e.g. USD for Merchant)
    // over transfer.amount (in crypto). Our billing_periods.total_fee is in USD, so we must
    // compare against target_paid for Merchant wallets. Stripe uses metadata amount (USD) similarly.
    $amount = null;
    if (isset($eventAttributes['target_paid']) && (float)$eventAttributes['target_paid'] > 0) {
        $amount = (float)$eventAttributes['target_paid'];  // USD for Merchant wallets
    } elseif ($transfer && isset($transfer['attributes']['amount'])) {
        $amount = (float)$transfer['attributes']['amount'];  // Crypto for Enterprise wallets (skip verification)
    }
    
    // Get crypto currency from deposit or transfer. Use (string) for id comparison (JSON may return int/string).
    $cryptocurrency = null;
    $resolveCurrencyAlpha = function ($currencyId) use ($included) {
        if ($currencyId === null) return null;
        $cid = (string)$currencyId;
        foreach ($included as $item) {
            if (isset($item['type']) && $item['type'] === 'currency' && (string)($item['id'] ?? '') === $cid) {
                $alpha = $item['attributes']['alpha'] ?? null;
                return is_string($alpha) ? $alpha : null;
            }
        }
        return null;
    };
    if (isset($eventData['relationships']['currency']['data']['id'])) {
        $cryptocurrency = $resolveCurrencyAlpha($eventData['relationships']['currency']['data']['id']);
    }
    if (!$cryptocurrency && $transfer && isset($transfer['relationships']['currency']['data']['id'])) {
        $cryptocurrency = $resolveCurrencyAlpha($transfer['relationships']['currency']['data']['id']);
    }
    
    $destination = $eventAttributes['destination'] ?? null;
    $destinationAddress = null;
    if (is_array($destination) && isset($destination['address'])) {
        $destinationAddress = $destination['address'];
    } elseif (is_string($destination)) {
        $destinationAddress = $destination;
    } elseif (isset($eventAttributes['address'])) {
        $destinationAddress = $eventAttributes['address'];
    }
    
    // Get transaction hash from transfer
    $transactionHash = null;
    if ($transfer && isset($transfer['attributes']['txid'])) {
        $transactionHash = $transfer['attributes']['txid'];
    }
    
    // Crypto amount sent (e.g. 0.0154763 BTC) - from transfer.attributes.amount
    $cryptoAmount = null;
    if ($transfer && isset($transfer['attributes']['amount']) && (float)$transfer['attributes']['amount'] > 0) {
        $cryptoAmount = (float)$transfer['attributes']['amount'];
    }
    
    $trackingId = $eventAttributes['tracking_id'] ?? null;
    
    // Extract billing_period_id and broker from tracking_id
    // Formats: billing_{id}_{ts} (capital.com) | billing_binance_{id}_{ts} | billing_ig_{id}_{ts}
    $billingPeriodId = null;
    $broker = 'capital.com';
    if ($trackingId) {
        if (preg_match('/^billing_binance_(\d+)_/', $trackingId, $matches)) {
            $billingPeriodId = (int)$matches[1];
            $broker = 'binance';
        } elseif (preg_match('/^billing_ig_(\d+)_/', $trackingId, $matches)) {
            $billingPeriodId = (int)$matches[1];
            $broker = 'ig';
        } elseif (preg_match('/^billing_(\d+)_/', $trackingId, $matches)) {
            $billingPeriodId = (int)$matches[1];
            $broker = 'capital.com';
        }
    }
    
    // If tracking_id doesn't match our format, this might be from another system
    if (!$billingPeriodId || $billingPeriodId <= 0) {
        $transferId = ($transfer && isset($transfer['id'])) ? $transfer['id'] : null;
        $ts = ($transfer && isset($transfer['attributes']['status'])) ? (int)$transfer['attributes']['status'] : null;
        insertCoinsbuyTransaction($conn, [
            'deposit_id' => $depositId ?? 'unknown',
            'transfer_id' => $transferId,
            'tracking_id' => $trackingId,
            'billing_period_id' => null,
            'outcome' => 'tracking_unknown',
            'deposit_status' => $status !== null ? (int)$status : null,
            'transfer_status' => $ts,
            'amount' => $amount,
            'amount_currency' => isset($eventAttributes['target_paid']) ? 'USD' : $cryptocurrency,
            'crypto_currency' => $cryptocurrency,
            'crypto_amount' => $cryptoAmount,
            'transaction_hash' => $transactionHash,
        ]);
        ob_clean();
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Deposit tracking_id does not match expected format (may be from another system)',
            'tracking_id' => $trackingId,
            'deposit_id' => $depositId
        ]);
        ob_end_flush();
        exit();
    }
    
    // Get billing period — broker-aware table lookup
    if ($broker === 'binance') {
        $billingPeriodQuery = "SELECT bp.id, NULL AS account_id, bp.user_id, bp.month_year,
            bp.total_fee, bp.status, COALESCE(a.currency_type, 'USD') AS currency_type
            FROM binance_invoices bp
            LEFT JOIN binance_accounts a ON bp.api_key = a.api_key
            WHERE bp.id = ? LIMIT 1";
    } elseif ($broker === 'ig') {
        $billingPeriodQuery = "SELECT bp.id, bp.account_id, bp.user_id, bp.month_year,
            bp.total_fee, bp.status, COALESCE(a.currency_type, 'USD') AS currency_type
            FROM ig_invoices bp
            LEFT JOIN ig_accounts a ON bp.api_key = a.api_key
            WHERE bp.id = ? LIMIT 1";
    } else {
        $billingPeriodQuery = "SELECT bp.id, bp.account_id, bp.user_id, bp.month_year,
            bp.total_fee, bp.status, COALESCE(a.currency_type, 'USD') AS currency_type
            FROM billing_periods bp
            LEFT JOIN accounts a ON bp.account_id = a.id
            WHERE bp.id = ? LIMIT 1";
    }
    
    $billingStmt = mysqli_prepare($conn, $billingPeriodQuery);
    if (!$billingStmt) {
        throw new Exception("Failed to prepare billing period lookup: " . mysqli_error($conn));
    }
    
    mysqli_stmt_bind_param($billingStmt, 'i', $billingPeriodId);
    if (!mysqli_stmt_execute($billingStmt)) {
        mysqli_stmt_close($billingStmt);
        throw new Exception("Failed to execute billing period lookup: " . mysqli_error($conn));
    }
    
    $billingResult = mysqli_stmt_get_result($billingStmt);
    if (mysqli_num_rows($billingResult) === 0) {
        mysqli_stmt_close($billingStmt);
        $transferId = ($transfer && isset($transfer['id'])) ? $transfer['id'] : null;
        $ts = ($transfer && isset($transfer['attributes']['status'])) ? (int)$transfer['attributes']['status'] : null;
        insertCoinsbuyTransaction($conn, [
            'deposit_id' => $depositId ?? 'unknown',
            'transfer_id' => $transferId,
            'tracking_id' => $trackingId,
            'billing_period_id' => $billingPeriodId,
            'outcome' => 'billing_period_not_found',
            'deposit_status' => $status !== null ? (int)$status : null,
            'transfer_status' => $ts,
            'amount' => $amount,
            'amount_currency' => isset($eventAttributes['target_paid']) ? 'USD' : $cryptocurrency,
            'crypto_currency' => $cryptocurrency,
            'crypto_amount' => $cryptoAmount,
            'transaction_hash' => $transactionHash,
        ]);
        ob_clean();
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Billing period not found (may have been deleted)',
            'billing_period_id' => $billingPeriodId,
            'deposit_id' => $depositId
        ]);
        ob_end_flush();
        exit();
    }
    
    $billingPeriod = mysqli_fetch_assoc($billingResult);
    mysqli_stmt_close($billingStmt);
    
    // Extract billing period data
    $accountId = (int)$billingPeriod['account_id'];
    $userId = $billingPeriod['user_id'];
    $monthYear = $billingPeriod['month_year'];
    $billingStatus = $billingPeriod['status'] ?? 'pending';
    
    // ---------- 5. Handle deposit status updates ----------
    // Coinsbuy deposit status values: 2=Created, 3=Paid, 4=Canceled, 5=Unresolved
    // Transfer status values: -3=Canceled, -2=Blocked, -1=Failed, 0=Created, 1=Unconfirmed, 2=Confirmed
    // We process when:
    // - Deposit status is 3 (Paid) - payment completed successfully
    // - Transfer status is 2 (Confirmed) - payment confirmed on blockchain (process even if deposit is Unresolved)
    // Unresolved (5) can happen with overpayment or timing, but if transfer is confirmed, payment is valid
    $shouldProcess = false;
    $transferStatus = ($transfer && isset($transfer['attributes']['status'])) ? (int)$transfer['attributes']['status'] : null;
    $transferConfirmed = ($transferStatus === 2);
    
    // Log for debugging
    error_log("Coinsbuy webhook: deposit_id=$depositId, deposit_status=$status, transfer_status=$transferStatus, tracking_id=$trackingId");
    
    if ($status == 3) {
        // Deposit marked as Paid - definitely process
        $shouldProcess = true;
        error_log("Coinsbuy webhook: Processing - Deposit status is Paid (3)");
    } elseif ($transferConfirmed) {
        // Transfer is confirmed on blockchain - process even if deposit status is Unresolved
        // This handles cases where payment is confirmed but deposit status hasn't updated yet
        $shouldProcess = true;
        error_log("Coinsbuy webhook: Processing - Transfer is Confirmed (2), deposit_status=$status");
    } else {
        // Not processing - log why
        error_log("Coinsbuy webhook: NOT processing - deposit_status=$status, transfer_status=$transferStatus");
    }
    
    if ($shouldProcess) {
        // Check if already paid (idempotent webhook - same as Stripe)
        // Do this BEFORE starting transaction. Do NOT insert here - avoids duplicate rows
        // when Coinsbuy sends multiple callbacks (e.g. transfer confirmed + deposit Paid).
        if ($billingStatus === 'paid') {
            ob_clean();
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Billing period already paid (idempotent webhook)',
                'account_id' => $accountId,
                'user_id' => $userId,
                'month_year' => $monthYear,
                'status' => 'paid',
                'already_paid' => true
            ]);
            ob_end_flush();
            exit();
        }
        
        // Verify payment amount matches stored total_fee (same as Stripe)
        // target_paid is in USD for Merchant wallets; transfer.amount is in crypto
        $expectedTotalFee = (float)($billingPeriod['total_fee'] ?? 0);
        
        // Only verify when amount is in USD (target_paid). Skip verification for crypto amount
        // (Enterprise wallets) - we trust Coinsbuy processed correctly.
        if ($amount !== null && $amount > 0 && isset($eventAttributes['target_paid'])) {
            $amountDifference = abs($amount - $expectedTotalFee);
            if ($amountDifference > 0.01) {
                $transferId = ($transfer && isset($transfer['id'])) ? $transfer['id'] : null;
                $errMsg = "Payment amount mismatch. Expected {$expectedTotalFee}, received {$amount}. Difference: {$amountDifference}.";
                insertCoinsbuyTransaction($conn, [
                    'deposit_id' => $depositId ?? 'unknown',
                    'transfer_id' => $transferId,
                    'tracking_id' => $trackingId,
                    'billing_period_id' => $billingPeriodId,
                    'account_id' => $accountId,
                    'user_id' => $userId,
                    'outcome' => 'amount_mismatch',
                    'deposit_status' => $status !== null ? (int)$status : null,
                    'transfer_status' => $transferStatus,
                    'amount' => $amount,
                    'amount_currency' => $billingPeriod['currency_type'] ?? 'USD',
                    'expected_amount' => $expectedTotalFee,
                    'crypto_currency' => $cryptocurrency,
                    'crypto_amount' => $cryptoAmount,
                    'transaction_hash' => $transactionHash,
                    'error_message' => $errMsg,
                ]);
                throw new Exception(
                    "Coinsbuy webhook: Payment amount mismatch. Expected {$expectedTotalFee} (from billing_periods.total_fee), received {$amount}. Difference: {$amountDifference}."
                );
            }
        }
        
        // ---------- 5.1 Start DB transaction ----------
        mysqli_begin_transaction($conn);
        
        $billingPeriodIdEscaped = (int)$billingPeriodId;
        
        // ---------- 5.2 Update invoice table directly (same as Stripe) ----------
        // CRITICAL: Use table id to ensure ONLY ONE row is updated
        $invoiceTable = ($broker === 'binance') ? 'binance_invoices' : (($broker === 'ig') ? 'ig_invoices' : 'billing_periods');
        $updateBillingPeriodQuery = "
            UPDATE $invoiceTable
            SET status = 'paid',
                updated_at = NOW()
            WHERE id = $billingPeriodIdEscaped";
        
        if (!mysqli_query($conn, $updateBillingPeriodQuery)) {
            $dbError = mysqli_error($conn);
            mysqli_rollback($conn);
            throw new Exception("Coinsbuy webhook: Failed to update billing_periods: " . $dbError);
        }
        
        $rowsAffected = mysqli_affected_rows($conn);
        
        // Verify exactly one row was affected
        if ($rowsAffected === 0) {
            mysqli_rollback($conn);
            throw new Exception("Coinsbuy webhook: No billing period updated. ID: $billingPeriodIdEscaped");
        }
        
        // Get full billing period data for HWM update (same as Stripe)
        $billingPeriodQuery = "
            SELECT 
                bp.id,
                bp.account_id,
                bp.user_id,
                bp.month_year,
                bp.total_fee,
                bp.realized_pnl,
                bp.unrealized_pnl,
                bp.hwm_before,
                bp.hwm_after,
                bp.status,
                a.uni_id,
                a.deposit_amount,
                a.unrealized_pnl,
                a.hwm_realized,
                a.hwm_unrealized
            FROM billing_periods bp
            INNER JOIN accounts a ON bp.account_id = a.id
            WHERE bp.id = $billingPeriodIdEscaped
            LIMIT 1";
        
        $billingResult = mysqli_query($conn, $billingPeriodQuery);
        if (!$billingResult || mysqli_num_rows($billingResult) === 0) {
            mysqli_rollback($conn);
            throw new Exception("Coinsbuy webhook: Billing period not found after update. ID: " . $billingPeriodId);
        }
        
        $billingPeriod = mysqli_fetch_assoc($billingResult);
        mysqli_free_result($billingResult);
        
        $actualUserId = $billingPeriod['user_id'];
        $actualAccountId = (int)$billingPeriod['account_id'];
        
        // ---------- 5.3 Update HWM in accounts (capital.com only) ----------
        // For binance/ig, HWM is stored in the invoice row itself — no accounts table to update.
        if ($broker !== 'capital.com') {
            // skip HWM update, fall through to audit log + commit
            goto coinsbuy_after_hwm;
        }

        $monthYearEscaped = mysqli_real_escape_string($conn, $monthYear);
        $actualUserIdEscaped = mysqli_real_escape_string($conn, $actualUserId);
        
        $allBillingPeriodsQuery = "
            SELECT DISTINCT
                bp.account_id,
                bp.hwm_after,
                a.uni_id,
                a.deposit_amount,
                a.unrealized_pnl
            FROM billing_periods bp
            INNER JOIN accounts a ON bp.account_id = a.id
            WHERE bp.user_id = '$actualUserIdEscaped'
              AND bp.month_year = '$monthYearEscaped'";
        
        $billingResult = mysqli_query($conn, $allBillingPeriodsQuery);
        if (!$billingResult) {
            throw new Exception("Coinsbuy webhook: Failed to query billing periods for HWM: " . mysqli_error($conn));
        }
        
        $accountsUpdated = 0;
        
        while ($accountRow = mysqli_fetch_assoc($billingResult)) {
            $accId = (int)$accountRow['account_id'];
            $depositAmount = (float)($accountRow['deposit_amount'] ?? 0);
            $unrealizedPnl = (float)($accountRow['unrealized_pnl'] ?? 0);
            
            // Realized PnL from trades
            $realizedPnlSql = "SELECT COALESCE(SUM(pnl), 0) AS total_pnl FROM trades WHERE account_id = $accId";
            $realizedPnlResult = mysqli_query($conn, $realizedPnlSql);
            
            $realizedPnl = 0.0;
            if ($realizedPnlResult) {
                $realizedPnlRow = mysqli_fetch_assoc($realizedPnlResult);
                $realizedPnl = isset($realizedPnlRow['total_pnl']) ? (float)$realizedPnlRow['total_pnl'] : 0.0;
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
            
            $newHwmEquityEscaped = mysqli_real_escape_string($conn, (string)$newHwmEquity);
            $realizedPnlEscaped = mysqli_real_escape_string($conn, (string)$realizedPnl);
            $unrealizedForHwmEscaped = mysqli_real_escape_string($conn, (string)$unrealizedForHwm);
            
            $updateAccountQuery = "
                UPDATE accounts
                SET
                    hwm_equity = GREATEST(COALESCE(hwm_equity, 0), $newHwmEquityEscaped),
                    hwm_realized = GREATEST(COALESCE(hwm_realized, 0), $realizedPnlEscaped),
                    hwm_unrealized = GREATEST(COALESCE(hwm_unrealized, 0), $unrealizedForHwmEscaped),
                    last_billed_month = '$monthYearEscaped',
                    last_billed_at = NOW()
                WHERE id = $accId
                  AND uni_id = '$actualUserIdEscaped'";
            
            if (mysqli_query($conn, $updateAccountQuery)) {
                $accountsUpdated++;
            }
        }
        
        mysqli_free_result($billingResult);
        
        coinsbuy_after_hwm:

        // Insert audit log INSIDE transaction (before commit) so billing update and log are atomic
        $transferId = ($transfer && isset($transfer['id'])) ? $transfer['id'] : null;
        insertCoinsbuyTransaction($conn, [
            'deposit_id' => $depositId ?? 'unknown',
            'transfer_id' => $transferId,
            'tracking_id' => $trackingId,
            'billing_period_id' => $billingPeriodIdEscaped,
            'account_id' => $actualAccountId,
            'user_id' => $actualUserId,
            'outcome' => 'paid',
            'deposit_status' => $status !== null ? (int)$status : null,
            'transfer_status' => $transferStatus,
            'amount' => $amount !== null ? $amount : $expectedTotalFee,
            'amount_currency' => isset($eventAttributes['target_paid']) ? ($billingPeriod['currency_type'] ?? 'USD') : $cryptocurrency,
            'expected_amount' => $expectedTotalFee,
            'crypto_currency' => $cryptocurrency,
            'crypto_amount' => $cryptoAmount,
            'transaction_hash' => $transactionHash,
        ]);
        
        // ---------- 6.7 Commit transaction ----------
        mysqli_commit($conn);
        
        ob_clean();
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Coinsbuy payment processed successfully',
            'deposit_id' => $depositId,
            'account_id' => $actualAccountId,
            'user_id' => $actualUserId,
            'month_year' => $monthYear,
            'amount' => $amount !== null ? $amount : $expectedTotalFee,
            'currency' => $currency,
            'payment_method' => 'coinsbuy',
            'status' => 'paid',
            'accounts_updated' => $accountsUpdated
        ]);
        ob_end_flush();
        exit();
    }
    
    // ---------- 7. Unhandled event types ----------
    // Skip insert for intermediate "Created" state (transfer_status=0, deposit_status=2) - Coinsbuy
    // sends a follow-up webhook when transfer confirms. Inserting here would create duplicate rows.
    $isIntermediateCreated = ($transferStatus === 0 && $status == 2);
    
    $outcome = 'not_processed';
    if ($status == 5) $outcome = 'overpaid';
    elseif ($status == 4) $outcome = 'canceled';
    elseif ($transferStatus === -1) $outcome = 'failed';
    elseif ($transferStatus === -2) $outcome = 'blocked';
    elseif ($transferStatus === -3) $outcome = 'canceled';
    $transferId = ($transfer && isset($transfer['id'])) ? $transfer['id'] : null;
    $expectedTotalFee = isset($billingPeriod['total_fee']) ? (float)$billingPeriod['total_fee'] : null;
    
    if (!$isIntermediateCreated) {
        insertCoinsbuyTransaction($conn, [
            'deposit_id' => $depositId ?? 'unknown',
            'transfer_id' => $transferId,
            'tracking_id' => $trackingId,
            'billing_period_id' => $billingPeriodId,
            'account_id' => $accountId,
            'user_id' => $userId,
            'outcome' => $outcome,
            'deposit_status' => $status !== null ? (int)$status : null,
            'transfer_status' => $transferStatus,
            'amount' => $amount,
            'amount_currency' => isset($eventAttributes['target_paid']) ? ($billingPeriod['currency_type'] ?? 'USD') : $cryptocurrency,
            'expected_amount' => $expectedTotalFee,
            'crypto_currency' => $cryptocurrency,
            'crypto_amount' => $cryptoAmount,
            'transaction_hash' => $transactionHash,
        ]);
    }
    ob_clean();
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'received' => true,
        'event_type' => $eventType,
        'status' => $status,
        'message' => 'Webhook received but status not processed'
    ]);
    ob_end_flush();
    
} catch (Exception $e) {
    if (isset($conn)) {
        mysqli_rollback($conn);
    }
    
    $errorMessage = $e->getMessage();
    error_log('Coinsbuy webhook error: ' . $errorMessage);
    
    $catchOutcome = (strpos($errorMessage, 'amount mismatch') !== false || strpos($errorMessage, 'Amount mismatch') !== false) ? 'amount_mismatch' : 'error';
    if (isset($depositId) && $depositId) {
        insertCoinsbuyTransaction($conn, [
            'deposit_id' => $depositId,
            'transfer_id' => isset($transfer) && $transfer && isset($transfer['id']) ? $transfer['id'] : null,
            'tracking_id' => isset($trackingId) ? $trackingId : null,
            'billing_period_id' => isset($billingPeriodId) ? $billingPeriodId : null,
            'account_id' => isset($accountId) ? $accountId : null,
            'user_id' => isset($userId) ? $userId : null,
            'outcome' => $catchOutcome,
            'deposit_status' => isset($status) && $status !== null ? (int)$status : null,
            'transfer_status' => isset($transferStatus) ? $transferStatus : null,
            'amount' => isset($amount) ? $amount : null,
            'amount_currency' => isset($eventAttributes['target_paid']) ? (isset($billingPeriod, $billingPeriod['currency_type']) ? $billingPeriod['currency_type'] : 'USD') : (isset($cryptocurrency) ? $cryptocurrency : null),
            'expected_amount' => isset($expectedTotalFee) ? $expectedTotalFee : (isset($billingPeriod['total_fee']) ? (float)$billingPeriod['total_fee'] : null),
            'crypto_currency' => isset($cryptocurrency) ? $cryptocurrency : null,
            'crypto_amount' => isset($cryptoAmount) ? $cryptoAmount : null,
            'transaction_hash' => isset($transactionHash) ? $transactionHash : null,
            'error_message' => substr($errorMessage, 0, 512),
        ]);
    }
    
    ob_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $errorMessage,
        'deposit_id' => isset($depositId) ? $depositId : 'unknown',
        'event_type' => isset($eventType) ? $eventType : 'unknown'
    ]);
    ob_end_flush();
} finally {
    if (isset($conn) && $conn) {
        mysqli_close($conn);
    }
}

