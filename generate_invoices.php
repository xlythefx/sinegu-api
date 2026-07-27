<?php
/**
 * Generate Invoices API
 * Implements HWM-based billing logic (Option 1: Performance-Only HWM):
 * - HWM never adjusts for capital flows (deposits/withdrawals)
 * - HWM only increases when equity > previous HWM (performance-based)
 * - HWM decreases on withdrawals only when: withdrawal in billing month AND both realized and unrealized P&L positive (prevents fee avoidance)
 * - Computes fees based on performance above High Water Mark
 * - Creates billing_periods for each account
 * - Aggregates invoices per user
 * - Updates HWM values in accounts table
 * 
 * Capital Flow Calculation:
 * - Uses capital_transactions table to track deposits and withdrawals explicitly
 * - Adjusted deposit = initial_deposit + sum(DEPOSIT transactions) - sum(WITHDRAWAL transactions)
 * - Capital flow for billing month = sum(deposits in month) - sum(withdrawals in month)
 * - This provides accurate tracking without relying on Capital.com's deposit_amount which includes realized gains
 *
 * CLI: Run via cron (e.g. 12:01 AM on 1st of month USA). Sets America/New_York so "last month" is correct.
 *   php generate_invoices.php
 * CLI always excludes demo accounts (invoice_demo_accounts forced false). Frontend toggle applies to HTTP only.
 */

 file_put_contents(
    __DIR__ . '/cron_proof.log',
    date('Y-m-d H:i:s') . " — generate_invoices.php executed\n",
    FILE_APPEND
);


// CLI support: treat as POST and set USA timezone so "first day of last month" is correct at 00:01 on the 1st
$isCli = php_sapi_name() === 'cli';

if ($isCli) {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    date_default_timezone_set('America/New_York');

    // FORCE: cron NEVER invoices demo accounts
    $invoiceDemoAccounts = false;
}


header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

// Parse optional JSON body: invoice_demo_accounts, skip_account_ids (HTTP only; CLI always excludes demos)
$skipAccountIds = [1, 2]; // Default: skip internal/master accounts
if ($isCli) {
    $invoiceDemoAccounts = false; // FORCE: cron NEVER invoices demo accounts
} else {
    $invoiceDemoAccounts = false;
    $rawInput = file_get_contents('php://input');
    $input = ($rawInput !== false && $rawInput !== '') ? json_decode($rawInput, true) : null;
    if (is_array($input)) {
        if (isset($input['invoice_demo_accounts'])) {
            $invoiceDemoAccounts = (bool)$input['invoice_demo_accounts'];
        }
        if (isset($input['skip_account_ids']) && is_array($input['skip_account_ids'])) {
            $skipAccountIds = array_map('intval', $input['skip_account_ids']);
        }
    }
    // HTTP callers (the admin UI) must supply the admin password. The CLI branch
    // is deliberately exempt so the monthly cron keeps running unattended.
    require_once __DIR__ . '/includes/admin_action_auth.php';
    requireAdminActionPassword(is_array($input) ? $input : []);
}

ob_start();
require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/includes/send_invoice_email.php';

if (!$conn) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    ob_end_flush();
    exit();
}

/**
 * Fetch balances from Python bot
 * Updates: accounts.balance, accounts.unrealized_pnl, accounts.deposit_amount
 * @return array Response from Python bot
 * @throws Exception If fetch fails
 */
function fetchBalancesFromBot() {
    $ch = curl_init('http://127.0.0.1:5000/admin/balances/refresh');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');
    
    $responseBody = curl_exec($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        throw new Exception("Failed to fetch balances: " . $curlError);
    }
    
    if ($statusCode < 200 || $statusCode >= 300) {
        throw new Exception("Failed to fetch balances from Python bot: HTTP $statusCode");
    }
    
    $decoded = json_decode($responseBody, true);
    if ($decoded === null) {
        throw new Exception("Invalid response from balance service: " . $responseBody);
    }
    
    return $decoded;
}

/**
 * Calculate adjusted deposit amount from capital_transactions table
 * Formula: initial_deposit + sum(DEPOSIT) + sum(WITHDRAWAL)
 * Note: WITHDRAWAL amounts are already negative in the database, so we ADD them (not subtract)
 * 
 * @param mysqli $conn Database connection
 * @param int $accountId Account ID
 * @param float $initialDeposit Initial deposit from accounts table
 * @param string|null $upToDate Optional date (Y-m-d format) to calculate up to. If null, calculates all transactions.
 * @return float Adjusted deposit amount
 */
function calculateAdjustedDepositFromTransactions($conn, $accountId, $initialDeposit, $upToDate = null) {
    $accountId = (int)$accountId;
    
    // Build date filter if provided
    $dateFilter = '';
    if ($upToDate !== null) {
        $escapedDate = mysqli_real_escape_string($conn, $upToDate);
        $dateFilter = " AND DATE(created_at) <= '$escapedDate'";
    }
    
    // Sum all DEPOSIT transactions for this account (up to specified date if provided)
    $depositSql = "SELECT COALESCE(SUM(amount), 0) AS total_deposits 
                   FROM capital_transactions 
                   WHERE account_id = $accountId 
                   AND type = 'DEPOSIT'
                   $dateFilter";
    $depositResult = mysqli_query($conn, $depositSql);
    $totalDeposits = 0.0;
    if ($depositResult) {
        $depositRow = mysqli_fetch_assoc($depositResult);
        $totalDeposits = isset($depositRow['total_deposits']) ? (float)$depositRow['total_deposits'] : 0.0;
        mysqli_free_result($depositResult);
    }
    
    // Sum all WITHDRAWAL transactions for this account (up to specified date if provided)
    // Note: WITHDRAWAL amounts are stored as negative values in the database
    $withdrawalSql = "SELECT COALESCE(SUM(amount), 0) AS total_withdrawals 
                      FROM capital_transactions 
                      WHERE account_id = $accountId 
                      AND type = 'WITHDRAWAL'
                      $dateFilter";
    $withdrawalResult = mysqli_query($conn, $withdrawalSql);
    $totalWithdrawals = 0.0;
    if ($withdrawalResult) {
        $withdrawalRow = mysqli_fetch_assoc($withdrawalResult);
        $totalWithdrawals = isset($withdrawalRow['total_withdrawals']) ? (float)$withdrawalRow['total_withdrawals'] : 0.0;
        mysqli_free_result($withdrawalResult);
    }
    
    // Adjusted deposit = initial_deposit + deposits + withdrawals
    // Since withdrawals are already negative, adding them effectively subtracts
    // Example: 20000 + 60 + (-10) = 20050
    $adjustedDeposit = $initialDeposit + $totalDeposits + $totalWithdrawals;
    
    return $adjustedDeposit;
}

try {
    // Step 1: Fetch fresh balances from Python bot
    // COMMENTED OUT: Balance fetching disabled for now
    // This updates: accounts.balance, accounts.unrealized_pnl, accounts.deposit_amount
    // $balanceResult = fetchBalancesFromBot();
    // if (!isset($balanceResult['success']) || !$balanceResult['success']) {
    //     throw new Exception("Balance fetch failed: " . ($balanceResult['message'] ?? 'Unknown error'));
    // }
    
    // Get billing month (previous month) in YYYY-MM format - AUTO-DETECTED
    // Dynamically calculates previous month: Feb 1st → bills for January, Jan 1st → bills for December, etc.
    // Example: If run on Feb 1, 2026 → bills for January 2026 (2026-01)
    // Example: If run on Jan 1, 2026 → bills for December 2025 (2025-12)
    $currentMonth = date('Y-m', strtotime('first day of last month'));
    
    // Due date: 1st of next month after month_year + 7 days (same rule as users/get_invoices.php)
    $dueDate = null;
    $monthDate = DateTime::createFromFormat('Y-m', $currentMonth);
    if ($monthDate) {
        $monthDate->modify('+1 month');
        $monthDate->setDate((int) $monthDate->format('Y'), (int) $monthDate->format('m'), 1);
        $monthDate->modify('+7 days');
        $dueDate = $monthDate->format('Y-m-d');
    }
    
    // Start transaction
    mysqli_begin_transaction($conn);
    
    $results = [
        'accounts_processed' => 0,
        'accounts_billed' => 0,
        'accounts_skipped' => 0,
        'billing_periods_created' => 0,
        'emails_sent' => 0,
        'emails_failed' => 0,
        'errors' => [],
        'details' => []
    ];
    
    // Step 1: Get all enabled accounts (optionally exclude demo accounts)
    // Note: balance column is needed for equity calculation (balance + unrealized_pnl)
    // When invoice_demo_accounts is false, exclude accounts where demo = 1
    $demoFilter = $invoiceDemoAccounts ? '' : " AND (COALESCE(demo, 0) = 0)";
    error_log('INVOICE MODE: ' . ($isCli ? 'CRON' : 'HTTP') . ', invoiceDemoAccounts=' . ($invoiceDemoAccounts ? 'true' : 'false'));
    $accountsQuery = "SELECT 
                        id, 
                        uni_id, 
                        name, 
                        balance,
                        unrealized_pnl, 
                        deposit_amount,
                        initial_deposit,
                        hwm_equity,
                        hwm_realized,
                        hwm_unrealized,
                        last_billed_month,
                        currency_type
                      FROM accounts 
                      WHERE enabled = 1 
                      $demoFilter
                      ORDER BY id";
    
    $accountsResult = mysqli_query($conn, $accountsQuery);
    
    if (!$accountsResult) {
        throw new Exception("Failed to fetch accounts: " . mysqli_error($conn));
    }
    
    $accounts = [];
    while ($row = mysqli_fetch_assoc($accountsResult)) {
        $accounts[] = $row;
    }
    
    $results['accounts_processed'] = count($accounts);
    
    // Step 2: Process each account
    foreach ($accounts as $account) {
        $accountId = (int)$account['id'];
        $uniId = $account['uni_id'];
        
        // Skip internal accounts (e.g. ID 1 = master, ID 2 = internal)
        if (in_array($accountId, $skipAccountIds, true)) {
            $results['accounts_skipped']++;
            $results['details'][] = [
                'account_id' => $accountId,
                'account_name' => $account['name'],
                'status' => 'skipped',
                'reason' => 'Skipped (internal account – ID ' . $accountId . ' excluded from billing)'
            ];
            continue;
        }
        
        // Skip if already billed this month
        if ($account['last_billed_month'] === $currentMonth) {
            $results['accounts_skipped']++;
            $results['details'][] = [
                'account_id' => $accountId,
                'account_name' => $account['name'],
                'status' => 'skipped',
                'reason' => 'Skipped (already billed for ' . $currentMonth . ')'
            ];
            continue;
        }
        
        // Skip if no user_id (uni_id)
        if (empty($uniId)) {
            $results['accounts_skipped']++;
            $results['details'][] = [
                'account_id' => $accountId,
                'account_name' => $account['name'],
                'status' => 'skipped',
                'reason' => 'Skipped (no user linked – uni_id is empty)'
            ];
            continue;
        }
        
        // Get user fee percentages from user_credentials table based on uni_id
        $escapedUniId = mysqli_real_escape_string($conn, $uniId);
        $userQuery = "SELECT email, name, realized_percentage, unrealized_percentage FROM user_credentials WHERE uni_id = '$escapedUniId' LIMIT 1";
        $userResult = mysqli_query($conn, $userQuery);
        if (!$userResult) {
            $results['accounts_skipped']++;
            $results['details'][] = [
                'account_id' => $accountId,
                'account_name' => $account['name'],
                'status' => 'skipped',
                'reason' => 'Skipped (database error fetching user: ' . mysqli_error($conn) . ')'
            ];
            continue;
        }
        if (mysqli_num_rows($userResult) === 0) {
            $results['accounts_skipped']++;
            $results['details'][] = [
                'account_id' => $accountId,
                'account_name' => $account['name'],
                'status' => 'skipped',
                'reason' => 'Skipped (user not found – uni_id not in user_credentials)'
            ];
            continue;
        }
        $userData = mysqli_fetch_assoc($userResult);
        
        // Get fee percentages from user_credentials (per-user basis)
        $realizedFeePercent = isset($userData['realized_percentage']) ? (float)$userData['realized_percentage'] : 20.0;
        $unrealizedFeePercent = isset($userData['unrealized_percentage']) ? (float)$userData['unrealized_percentage'] : 30.0;
        
        // Convert percentages to decimals for calculation
        $realizedFeeRate = $realizedFeePercent / 100.0;
        $unrealizedFeeRate = $unrealizedFeePercent / 100.0;
        
        // Check if this is first invoice (no previous billing periods)
        // NOTE: We no longer read deposit_amount from billing_periods - we calculate it from capital_transactions instead
        $previousBillingQuery = "SELECT hwm_after, equity_end, month_year FROM billing_periods 
                                WHERE account_id = $accountId 
                                ORDER BY month_year DESC, created_at DESC 
                                LIMIT 1";
        $previousBillingResult = mysqli_query($conn, $previousBillingQuery);
        $isFirstInvoice = !$previousBillingResult || mysqli_num_rows($previousBillingResult) === 0;
        
        // Get current values (ensure they're numeric)
        $depositAmount = (float)($account['deposit_amount'] ?? 0);
        $initialDeposit = (float)($account['initial_deposit'] ?? 0);
        $unrealizedPnl = (float)($account['unrealized_pnl'] ?? 0);
        
        // Calculate realized_pnl from trades table
        // IMPORTANT: Only sum trades closed in the BILLING month (previous month) to avoid double-charging
        // For equity calculation: use ALL trades (cumulative total)
        // For billing period realized_pnl: use only billing month trades
        // $currentMonth is already set to billing month (previous month) at line 85 - DO NOT OVERWRITE
        
        // Get total realized_pnl from ALL trades (for equity calculation)
        $totalRealizedPnlSql = "SELECT COALESCE(SUM(pnl), 0) AS total_pnl FROM trades WHERE account_id = $accountId";
        $totalRealizedPnlResult = mysqli_query($conn, $totalRealizedPnlSql);
        $totalRealizedPnl = 0.0;
        if ($totalRealizedPnlResult) {
            $totalRealizedPnlRow = mysqli_fetch_assoc($totalRealizedPnlResult);
            $totalRealizedPnl = isset($totalRealizedPnlRow['total_pnl']) ? (float) $totalRealizedPnlRow['total_pnl'] : 0.0;
            mysqli_free_result($totalRealizedPnlResult);
        }
        
        // Calculate adjusted deposit amount from capital_transactions table
        // Formula: initial_deposit + sum(DEPOSIT) - sum(WITHDRAWAL)
        // This directly uses transaction records instead of complex calculation
        $adjustedDepositAmount = calculateAdjustedDepositFromTransactions($conn, $accountId, $initialDeposit);
        
        // Get realized_pnl from trades closed in the BILLING month (previous month) only - AUTO-DETECTED
        // Dynamically filters trades: Feb 1st → January trades, Jan 1st → December trades, etc.
        // This prevents double-charging (e.g., January trades won't be charged again in February)
        // $currentMonth is the billing month (previous month), automatically calculated above
        $monthStart = $currentMonth . '-01';
        $monthEnd = date('Y-m-t', strtotime($monthStart)); // Last day of billing month
        $escapedMonthStart = mysqli_real_escape_string($conn, $monthStart);
        $escapedMonthEnd = mysqli_real_escape_string($conn, $monthEnd);
        
        // IMPORTANT: Filter trades by billing month only (e.g., November 2025)
        // This query should ONLY include trades closed in the billing month
        // Example: If billing for November (2025-11), only trades with date_closed between 2025-11-01 and 2025-11-30
        // Use YEAR() and MONTH() functions for more reliable month filtering
        $yearMonth = explode('-', $currentMonth);
        $billingYear = (int)$yearMonth[0];
        $billingMonth = (int)$yearMonth[1];
        
        // IMPORTANT: Filter trades by billing month only (e.g., November 2025)
        // Use DATE() function for more reliable month filtering
        // Example: If billing for November (2025-11), only trades with date_closed between 2025-11-01 and 2025-11-30
        $monthStart = $currentMonth . '-01';
        $monthEnd = date('Y-m-t', strtotime($monthStart)); // Last day of billing month
        $escapedMonthStart = mysqli_real_escape_string($conn, $monthStart);
        $escapedMonthEnd = mysqli_real_escape_string($conn, $monthEnd);
        
        $realizedPnlSql = "SELECT COALESCE(SUM(pnl), 0) AS total_pnl 
                          FROM trades 
                          WHERE account_id = $accountId 
                          AND DATE(date_closed) >= '$escapedMonthStart' 
                          AND DATE(date_closed) <= '$escapedMonthEnd'";
        $realizedPnlResult = mysqli_query($conn, $realizedPnlSql);
        $realizedPnl = 0.0;
        if ($realizedPnlResult) {
            $realizedPnlRow = mysqli_fetch_assoc($realizedPnlResult);
            $realizedPnl = isset($realizedPnlRow['total_pnl']) ? (float) $realizedPnlRow['total_pnl'] : 0.0;
            mysqli_free_result($realizedPnlResult);
            
            // Debug logging to verify correct filtering
            error_log("Billing month: $currentMonth (Start: $monthStart, End: $monthEnd), Realized P&L for account $accountId: $realizedPnl");
        } else {
            error_log("Error querying realized P&L for account $accountId: " . mysqli_error($conn));
        }
        
        // Calculate equity: adjusted_deposit_amount + unrealized_pnl + total_pnl from ALL trades
        // Adjusted deposit amount is calculated from initial_deposit + capital_transactions (deposits - withdrawals)
        // This gives us the true current equity (cumulative) without relying on Capital.com's deposit_amount
        $equity = $adjustedDepositAmount + $unrealizedPnl + $totalRealizedPnl;
        
        // Determine previous HWM and check if we should charge fees
        $shouldChargeFees = false;
        $previousHWM = 0.0;
        $hwmBefore = 0.0;
        $hwmAfter = 0.0;
        $newRealizedProfit = 0.0;
        $newUnrealizedProfit = 0.0;
        $hwmRealized = 0.0;
        $hwmUnrealized = 0.0;
        $depositChange = 0.0; // Track deposit amount changes
        $previousDepositAmount = 0.0; // Previous month's deposit amount
        
        if ($isFirstInvoice) {
            // First invoice: no previous deposit to compare
            $previousDepositAmount = 0.0;
            $depositChange = 0.0;
            $adjustedDepositChange = 0.0; // No previous period, so no change
            
            // Calculate capital flow for billing month (needed for withdrawal override)
            $depositFlowSql = "SELECT COALESCE(SUM(amount), 0) AS deposits 
                               FROM capital_transactions 
                               WHERE account_id = $accountId 
                               AND type = 'DEPOSIT'
                               AND DATE(created_at) >= '$escapedMonthStart' 
                               AND DATE(created_at) <= '$escapedMonthEnd'";
            $depositFlowResult = mysqli_query($conn, $depositFlowSql);
            $monthDeposits = 0.0;
            if ($depositFlowResult) {
                $depositFlowRow = mysqli_fetch_assoc($depositFlowResult);
                $monthDeposits = isset($depositFlowRow['deposits']) ? (float)$depositFlowRow['deposits'] : 0.0;
                mysqli_free_result($depositFlowResult);
            }
            $withdrawalFlowSql = "SELECT COALESCE(SUM(amount), 0) AS withdrawals 
                                  FROM capital_transactions 
                                  WHERE account_id = $accountId 
                                  AND type = 'WITHDRAWAL'
                                  AND DATE(created_at) >= '$escapedMonthStart' 
                                  AND DATE(created_at) <= '$escapedMonthEnd'";
            $withdrawalFlowResult = mysqli_query($conn, $withdrawalFlowSql);
            $monthWithdrawals = 0.0;
            if ($withdrawalFlowResult) {
                $withdrawalFlowRow = mysqli_fetch_assoc($withdrawalFlowResult);
                $monthWithdrawals = isset($withdrawalFlowRow['withdrawals']) ? (float)$withdrawalFlowRow['withdrawals'] : 0.0;
                mysqli_free_result($withdrawalFlowResult);
            }
            $capitalFlow = $monthDeposits + $monthWithdrawals;
            
            // First invoice: use initial_deposit as initial HWM (true starting capital)
            // Only use initial_deposit if it exists and is greater than 0
            // Never use adjusted deposit_amount as it may be incorrect if deposit_amount includes realized gains
            if ($initialDeposit > 0) {
                $hwm = $initialDeposit;
            } else {
                // Fallback to adjusted deposit_amount only if initial_deposit is not available
                $hwm = $adjustedDepositAmount;
            }
            $hwmBefore = $hwm;
            $hwmRealized = 0.0; // No previous HWM for first invoice
            $hwmUnrealized = 0.0; // No previous HWM for first invoice
            
            // For first invoice, performance equity = raw equity (no previous period to normalize)
            $performanceEquity = $equity;
            
            // Charge only when current balance (equity) > HWM; no fee if balance <= HWM
            if ($equity > $hwm) {
                $shouldChargeFees = true;
                $profit = $performanceEquity - $hwm;
                
                // Calculate new_realized_profit and new_unrealized_profit
                // IMPORTANT: Even for first invoice, only charge for the BILLING MONTH's trades
                // Use $realizedPnl (filtered by billing month), NOT $totalRealizedPnl (all trades)
                // Example: If billing for November, only charge for November trades (350), not all trades (810)
                $newRealizedProfit = max(0, $realizedPnl); // Current month's realized P&L only
                $newUnrealizedProfit = max(0, $unrealizedPnl); // Current unrealized P&L
                
                // When in profit, set new HWM to current balance (equity)
                $hwmAfter = max($hwm, $equity);
            } else {
                // Balance (equity) <= HWM: no fees, unless withdrawal override applies
                // Withdrawal override: user withdrew funds (capital_flow < 0) but both profits positive
                if ($capitalFlow < 0 && $realizedPnl > 0 && $unrealizedPnl > 0) {
                    $shouldChargeFees = true;
                    $newRealizedProfit = max(0, $realizedPnl);
                    $newUnrealizedProfit = max(0, $unrealizedPnl);
                    $hwmAfter = $equity;
                    error_log("Account $accountId - First invoice: withdrawal override (capitalFlow=$capitalFlow, realized=$realizedPnl, unrealized=$unrealizedPnl)");
                } else {
                    $shouldChargeFees = false;
                    $profit = 0;
                    $newRealizedProfit = 0;
                    $newUnrealizedProfit = 0;
                    $hwmAfter = $hwmBefore;
                }
            }
        } else {
            // Subsequent invoice: get previous month's HWM and equity_end from billing_periods
            // NOTE: We no longer read deposit_amount from billing_periods - calculate it from capital_transactions instead
            $previousBillingRow = mysqli_fetch_assoc($previousBillingResult);
            $previousHWM = (float)($previousBillingRow['hwm_after'] ?? 0);
            $previousEquity = (float)($previousBillingRow['equity_end'] ?? 0); // Previous month's equity for performance calculation
            $previousMonthYear = $previousBillingRow['month_year'] ?? null; // Previous billing month for date calculation
            mysqli_free_result($previousBillingResult);
            
            // Calculate previous month's adjusted deposit amount from capital_transactions
            // Calculate up to the end of the previous billing month
            $previousMonthEnd = null;
            if ($previousMonthYear) {
                $previousMonthEnd = date('Y-m-t', strtotime($previousMonthYear . '-01')); // Last day of previous billing month
            }
            $previousDepositAmount = calculateAdjustedDepositFromTransactions($conn, $accountId, $initialDeposit, $previousMonthEnd);
            
            // Get current deposit_amount (raw from Capital.com, includes realized gains)
            $currentDepositAmount = $depositAmount;
            
            // Calculate capital flow (deposit/withdrawal) from capital_transactions for the billing month
            // Sum transactions in the billing period (previous month)
            $monthStart = $currentMonth . '-01';
            $monthEnd = date('Y-m-t', strtotime($monthStart));
            $escapedMonthStart = mysqli_real_escape_string($conn, $monthStart);
            $escapedMonthEnd = mysqli_real_escape_string($conn, $monthEnd);
            
            // Sum deposits in billing month
            $depositFlowSql = "SELECT COALESCE(SUM(amount), 0) AS deposits 
                               FROM capital_transactions 
                               WHERE account_id = $accountId 
                               AND type = 'DEPOSIT'
                               AND DATE(created_at) >= '$escapedMonthStart' 
                               AND DATE(created_at) <= '$escapedMonthEnd'";
            $depositFlowResult = mysqli_query($conn, $depositFlowSql);
            $monthDeposits = 0.0;
            if ($depositFlowResult) {
                $depositFlowRow = mysqli_fetch_assoc($depositFlowResult);
                $monthDeposits = isset($depositFlowRow['deposits']) ? (float)$depositFlowRow['deposits'] : 0.0;
                mysqli_free_result($depositFlowResult);
            }
            
            // Sum withdrawals in billing month
            $withdrawalFlowSql = "SELECT COALESCE(SUM(amount), 0) AS withdrawals 
                                  FROM capital_transactions 
                                  WHERE account_id = $accountId 
                                  AND type = 'WITHDRAWAL'
                                  AND DATE(created_at) >= '$escapedMonthStart' 
                                  AND DATE(created_at) <= '$escapedMonthEnd'";
            $withdrawalFlowResult = mysqli_query($conn, $withdrawalFlowSql);
            $monthWithdrawals = 0.0;
            if ($withdrawalFlowResult) {
                $withdrawalFlowRow = mysqli_fetch_assoc($withdrawalFlowResult);
                $monthWithdrawals = isset($withdrawalFlowRow['withdrawals']) ? (float)$withdrawalFlowRow['withdrawals'] : 0.0;
                mysqli_free_result($withdrawalFlowResult);
            }
            
            // Capital flow = deposits + withdrawals
            // Note: WITHDRAWAL amounts are already negative in the database, so we ADD them (not subtract)
            // Example: 60 + (-10) = 50 (net deposit of 50)
            // Positive result = net deposit, negative result = net withdrawal
            $capitalFlow = $monthDeposits + $monthWithdrawals;
            
            // Also calculate adjusted deposit change for reference
            $adjustedDepositChange = $adjustedDepositAmount - $previousDepositAmount;
            
            // Also calculate raw deposit change for reference (using Capital.com values)
            $depositChange = $currentDepositAmount - $previousDepositAmount;
            
            // FIXED: Calculate performance equity based on previous equity + trading profit
            // This correctly reflects trading performance, independent of capital flows
            // Performance equity = Previous equity + Trading profit (realized + unrealized for billing month)
            // This way: deposits don't reduce performance equity, withdrawals don't increase it
            // Example: Previous equity $20,100 + Trading profit $200 = Performance equity $20,300
            // If trading profit is $200 (realized $100 + unrealized $100), performance equity increases by $200
            $tradingProfit = $realizedPnl + $unrealizedPnl; // Current billing month's trading profit
            $performanceEquity = $previousEquity + $tradingProfit;
            
            // HWM never adjusts for capital flows (deposits/withdrawals)
            // HWM only increases when performance equity > previous HWM (performance-based)
            // HWM never decreases (even on withdrawals)
            // This prevents: withdrawals from lowering HWM (avoiding fees) and deposits from raising HWM (charging on deposits)
            $hwmBefore = $previousHWM; // Don't adjust HWM for deposits/withdrawals
            
            // Get previous HWM values for realized/unrealized from accounts table
            $hwmRealized = (float)($account['hwm_realized'] ?? 0);
            $hwmUnrealized = (float)($account['hwm_unrealized'] ?? 0);
            
            // Charge only when current balance (equity) > previous HWM; no fee if balance <= HWM
            if ($equity > $hwmBefore) {
                $shouldChargeFees = true;
                $profit = $performanceEquity - $hwmBefore;
                
                // Calculate profits above HWM for realized and unrealized separately
                // For realized: current month's realizedPnl is the new profit (HWM already accounts for previous months)
                // For unrealized: charge on FULL unrealized P&L (they're still holding positions, charge again)
                // Example: last month unrealized = 100, this month = 120, charge on 120 (not just 20)
                // IMPORTANT: Use $realizedPnl (filtered by billing month), NOT $totalRealizedPnl (all trades)
                $newRealizedProfit = max(0, $realizedPnl); // Current month's realized P&L is all new profit
                $newUnrealizedProfit = max(0, $unrealizedPnl); // Charge on full unrealized (positions still open)
                
                // Debug logging to verify correct values
                error_log("Account $accountId - Subsequent invoice: equity=$equity, capitalFlow=$capitalFlow, performanceEquity=$performanceEquity, hwmBefore=$hwmBefore, realizedPnl=$realizedPnl, newRealizedProfit=$newRealizedProfit, totalRealizedPnl=$totalRealizedPnl");
                
                // When in profit, set new HWM to current balance (equity)
                $hwmAfter = $equity;
            } else {
                // Balance (equity) <= previous HWM: no fees, unless withdrawal override applies
                // Withdrawal override: user withdrew funds (capital_flow < 0) but both profits positive
                if ($capitalFlow < 0 && $realizedPnl > 0 && $unrealizedPnl > 0) {
                    $shouldChargeFees = true;
                    $profit = $performanceEquity - $hwmBefore;
                    $newRealizedProfit = max(0, $realizedPnl);
                    $newUnrealizedProfit = max(0, $unrealizedPnl);
                    $hwmAfter = $equity;
                    error_log("Account $accountId - Subsequent invoice: withdrawal override (capitalFlow=$capitalFlow, realized=$realizedPnl, unrealized=$unrealizedPnl), equity=$equity <= hwmBefore=$hwmBefore");
                } else {
                    $shouldChargeFees = false;
                    $profit = 0;
                    $newRealizedProfit = 0;
                    $newUnrealizedProfit = 0;
                    error_log("Account $accountId - No fees: equity=$equity <= hwmBefore=$hwmBefore (balance below HWM)");
                    $hwmAfter = $hwmBefore;
                }
            }
        }
        
        // Calculate fees using user-specific percentages from user_credentials
        // Note: Fees will be 0 when equity <= HWM (shouldChargeFees = false)
        $feeRealized = $newRealizedProfit * $realizedFeeRate;
        $feeUnrealized = $newUnrealizedProfit * $unrealizedFeeRate;
        $totalFee = $feeRealized + $feeUnrealized;
        
        // Determine new HWM values for accounts table update
        // Store HWM directly (no conversion needed)
        // For drawdowns (equity <= HWM), HWM stays unchanged, so use current account values
        if ($shouldChargeFees) {
            $hwmEquityAfter = $hwmAfter;
            // HWM realized: 
            // - First invoice: use totalRealizedPnl (all trades)
            // - Subsequent invoices: add current month's realizedPnl to previous cumulative HWM
            // This ensures HWM tracks cumulative realized P&L across all months
            if ($isFirstInvoice) {
                $hwmRealizedAfter = $totalRealizedPnl; // First invoice: all trades
            } else {
                $hwmRealizedAfter = $hwmRealized + $realizedPnl; // Subsequent: add current month to previous
            }
            $hwmUnrealizedAfter = max($hwmUnrealized, $unrealizedPnl);
        } else {
            // For drawdowns, HWM values remain unchanged (use current account values)
            $hwmEquityAfter = $hwmBefore; // HWM stays the same
            $hwmRealizedAfter = $hwmRealized; // Keep previous HWM realized
            $hwmUnrealizedAfter = $hwmUnrealized; // Keep previous HWM unrealized
        }
        
        // Store equity at start and end of period
        $equityStart = $hwmBefore; // Use HWM directly
        $equityEnd = $equity; // Current equity: balance + unrealized_pnl
        $adjustedEquity = $equity; // Store equity (no adjustment needed)
        
        // Ensure capitalFlow and performanceEquity are initialized (for first invoice case)
        if (!isset($capitalFlow)) {
            $capitalFlow = 0.0;
        }
        if (!isset($performanceEquity)) {
            $performanceEquity = $equity; // For first invoice, performance equity = raw equity
        }
        
        // Determine billing status: 'paid' for zero-fee periods (drawdowns), 'pending' for periods with fees
        $billingStatus = ($totalFee > 0) ? 'pending' : 'paid';
        $escapedStatus = mysqli_real_escape_string($conn, $billingStatus);
        
        // Insert into billing_periods (always, even for drawdowns)
        $escapedMonth = mysqli_real_escape_string($conn, $currentMonth);
        
        // NOTE: deposit_amount column is kept for historical snapshot but is DEPRECATED
        // We now calculate adjusted deposit from initial_deposit + capital_transactions instead
        // Do not use deposit_amount from billing_periods as reference - always calculate from capital_transactions
        $escapedDueDate = $dueDate !== null ? "'" . mysqli_real_escape_string($conn, $dueDate) . "'" : 'NULL';
        $billingPeriodQuery = "INSERT INTO billing_periods (
                                user_id,
                                account_id,
                                month_year,
                                equity_start,
                                equity_end,
                                realized_pnl,
                                unrealized_pnl,
                                deposit_amount,
                                adjusted_equity,
                                capital_flow,
                                performance_equity,
                                hwm_before,
                                hwm_after,
                                new_realized_profit,
                                new_unrealized_profit,
                                fee_realized,
                                fee_unrealized,
                                total_fee,
                                status,
                                due_date,
                                created_at
                              ) VALUES (
                                '" . $escapedUniId . "',
                                " . (int)$accountId . ",
                                '" . $escapedMonth . "',
                                " . (float)$equityStart . ",
                                " . (float)$equityEnd . ",
                                " . (float)$realizedPnl . ",
                                " . (float)$unrealizedPnl . ",
                                " . (float)$adjustedDepositAmount . ",
                                " . (float)$adjustedEquity . ",
                                " . (float)$capitalFlow . ",
                                " . (float)$performanceEquity . ",
                                " . (float)$hwmBefore . ",
                                " . (float)$hwmAfter . ",
                                " . (float)$newRealizedProfit . ",
                                " . (float)$newUnrealizedProfit . ",
                                " . (float)$feeRealized . ",
                                " . (float)$feeUnrealized . ",
                                " . (float)$totalFee . ",
                                '" . $escapedStatus . "',
                                " . $escapedDueDate . ",
                                NOW()
                              )";
        
        if (!mysqli_query($conn, $billingPeriodQuery)) {
            throw new Exception("Failed to insert billing_period: " . mysqli_error($conn));
        }
        
        $billingPeriodId = mysqli_insert_id($conn);
        $results['billing_periods_created']++;
        $results['accounts_billed']++;
        
        // Update HWM values in accounts table (only when equity > HWM, i.e., when fees are charged)
        // For drawdowns (equity <= HWM), keep HWM unchanged
        if ($shouldChargeFees) {
            $escapedMonthForUpdate = mysqli_real_escape_string($conn, $currentMonth);
            $updateHWMQuery = "UPDATE accounts 
                              SET hwm_equity = " . (float)$hwmEquityAfter . ",
                                  hwm_realized = " . (float)$hwmRealizedAfter . ",
                                  hwm_unrealized = " . (float)$hwmUnrealizedAfter . ",
                                  last_billed_month = '" . $escapedMonthForUpdate . "',
                                  last_billed_at = NOW()
                              WHERE id = " . (int)$accountId;
            
            if (!mysqli_query($conn, $updateHWMQuery)) {
                throw new Exception("Failed to update HWM: " . mysqli_error($conn));
            }
        } else {
            // For drawdowns, still update last_billed_month and last_billed_at for tracking
            $escapedMonthForUpdate = mysqli_real_escape_string($conn, $currentMonth);
            $updateBillingDateQuery = "UPDATE accounts 
                                      SET last_billed_month = '" . $escapedMonthForUpdate . "',
                                          last_billed_at = NOW()
                                      WHERE id = " . (int)$accountId;
            
            if (!mysqli_query($conn, $updateBillingDateQuery)) {
                throw new Exception("Failed to update billing date: " . mysqli_error($conn));
            }
        }
        
        // Send invoice email to user
        // Only send if there's a fee to pay and email_sending is enabled
        $emailSent = false;
        $emailError = null;
        
        // Check if email sending is enabled in bot_config
        $emailSendingEnabled = false; // Default to false for safety
        $emailSendingValue = 'not found';
        $emailSendingQuery = "SELECT email_sending FROM bot_config WHERE id = 5163321 LIMIT 1";
        $emailSendingResult = mysqli_query($conn, $emailSendingQuery);
        if ($emailSendingResult && mysqli_num_rows($emailSendingResult) > 0) {
            $emailSendingRow = mysqli_fetch_assoc($emailSendingResult);
            $emailSendingValue = $emailSendingRow['email_sending'] ?? '';
            $emailSendingValueUpper = strtoupper(trim($emailSendingValue));
            $emailSendingEnabled = ($emailSendingValueUpper === 'ON');
            error_log("Email sending check for account_id $accountId: email_sending = '$emailSendingValue' (enabled: " . ($emailSendingEnabled ? 'true' : 'false') . ")");
        } else {
            error_log("Email sending check for account_id $accountId: bot_config not found, defaulting to disabled");
        }
        
        if ($totalFee > 0 && $emailSendingEnabled) {
            $invoiceEmailData = [
                'month_year' => $currentMonth,
                'total_fee' => $totalFee,
                'fee_realized' => $feeRealized,
                'fee_unrealized' => $feeUnrealized,
                'currency' => $account['currency_type'] ?? 'USD',
                'hwm_before' => $hwmBefore,
                'equity_end' => $equityEnd,
                'deposit_amount' => $depositAmount,
                // Per-user percentages from user_credentials (used for calculation above)
                'realized_fee_percent' => $realizedFeePercent,
                'unrealized_fee_percent' => $unrealizedFeePercent,
            ];
            
            error_log("Attempting to send invoice email for account_id $accountId, uni_id: $uniId, total_fee: $totalFee");
            $emailResult = sendInvoiceEmail($conn, $uniId, $invoiceEmailData);
            
            if ($emailResult['success']) {
                $results['emails_sent']++;
                $emailSent = true;
                // Record when first invoice email was sent (for reminder cron: 2nd at day 2, 3rd at day 4, 4th at day 7)
                $updateSent = "UPDATE billing_periods SET invoice_sent_at = NOW() WHERE id = " . (int)$billingPeriodId;
                mysqli_query($conn, $updateSent);
                error_log("Email sent successfully for account_id $accountId (uni_id: $uniId)");
            } else {
                $results['emails_failed']++;
                // Log email error but don't fail the invoice generation
                $emailError = $emailResult['message'] ?? 'Unknown error';
                error_log("Failed to send invoice email for account_id $accountId (uni_id: $uniId): $emailError");
                // Add error to results for debugging
                if (!isset($results['email_errors'])) {
                    $results['email_errors'] = [];
                }
                $results['email_errors'][] = [
                    'account_id' => $accountId,
                    'account_name' => $account['name'],
                    'uni_id' => $uniId,
                    'error' => $emailError
                ];
            }
        } elseif ($totalFee > 0 && !$emailSendingEnabled) {
            // Email sending is disabled
            $emailError = 'Email sending is disabled in bot configuration';
            error_log("Email sending skipped for account_id $accountId: Email sending is disabled (email_sending = '$emailSendingValue')");
            // Don't increment emails_failed since it's intentionally disabled, not a failure
            // Just log it for information
        }
        
        // For subsequent invoices, use adjusted deposit change (true deposits/withdrawals, excludes realized gains)
        // For first invoice, depositChange is 0, so use that
        $displayDepositChange = $isFirstInvoice ? $depositChange : $adjustedDepositChange;
        
        $results['details'][] = [
            'account_id' => $accountId,
            'account_name' => $account['name'],
            'status' => 'billed',
            'billing_status' => $billingStatus, // 'paid' for drawdowns, 'pending' for fees
            'total_fee' => $totalFee,
            'fee_realized' => $feeRealized,
            'fee_unrealized' => $feeUnrealized,
            'equity' => $equity,
            'performance_equity' => $performanceEquity, // Normalized equity (excludes capital flows)
            'capital_flow' => $capitalFlow, // Deposit/withdrawal change (positive = deposit, negative = withdrawal)
            'hwm_equity_before' => $hwmBefore,
            'hwm_equity_after' => $hwmEquityAfter,
            'deposit_amount' => $depositAmount, // Raw Capital.com value (includes realized gains) - for reference
            'adjusted_deposit_amount' => $adjustedDepositAmount, // True deposits (excludes realized gains)
            'previous_deposit' => $previousDepositAmount, // Already adjusted (from billing_periods for subsequent, 0 for first)
            'deposit_change' => $displayDepositChange, // Adjusted deposit change (true deposits/withdrawals, excludes realized gains)
            'should_charge_fees' => $shouldChargeFees,
            'email_sent' => $emailSent,
            'email_error' => $emailError
        ];
    }
    
    // Commit transaction
    mysqli_commit($conn);
    
    ob_clean();
    http_response_code(200);
    
    // Build response with email_errors if any
    $response = [
        'success' => true,
        'message' => 'Invoices generated successfully',
        'month' => $currentMonth,
        'results' => $results
    ];
    
    // Include email_errors in response if any
    if (isset($results['email_errors']) && count($results['email_errors']) > 0) {
        $response['results']['email_errors'] = $results['email_errors'];
    }
    
    echo json_encode($response);
    ob_end_flush();
    
} catch (Exception $e) {
    // Rollback transaction on error
    mysqli_rollback($conn);
    
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

