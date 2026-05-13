<?php
/**
 * Generate Invoices – Binance
 * Mirrors generate_invoices.php logic but reads from Binance broker tables:
 *   binance_accounts, binance_pastpositions, binance_positions, binance_transactions
 * Inserts rows into binance_invoices.
 *
 * HWM: derived from the most recent binance_invoices row for each api_key.
 * Skip check: UNIQUE(api_key, month_year) prevents duplicate runs.
 *
 * POST body (optional JSON):
 *   invoice_demo_accounts  bool     – include demo accounts (default false)
 *   skip_api_keys          string[] – api_keys to skip
 *
 * Binance transactions note: amounts stored as ABSOLUTE (always positive).
 * Direction determined by type field: DEPOSIT = positive, WITHDRAWAL = negative.
 */

$isCli = php_sapi_name() === 'cli';

if ($isCli) {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    date_default_timezone_set('America/New_York');
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

$invoiceDemoAccounts = false;
$skipApiKeys = [];

if ($isCli) {
    $invoiceDemoAccounts = false;
} else {
    $rawInput = file_get_contents('php://input');
    if ($rawInput !== false && $rawInput !== '') {
        $input = json_decode($rawInput, true);
        if (is_array($input)) {
            if (isset($input['invoice_demo_accounts'])) {
                $invoiceDemoAccounts = (bool)$input['invoice_demo_accounts'];
            }
            if (isset($input['skip_api_keys']) && is_array($input['skip_api_keys'])) {
                $skipApiKeys = array_filter(array_map('strval', $input['skip_api_keys']));
            }
        }
    }
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
 * Calculate Binance adjusted deposit up to an optional date.
 * DEPOSIT amounts stored as absolute positive; WITHDRAWAL stored as absolute positive.
 * adjusted = initial_deposit + SUM(DEPOSIT) - SUM(WITHDRAWAL)
 */
function binanceAdjustedDeposit($conn, $apiKey, $initialDeposit, $upToDate = null) {
    $escapedKey = mysqli_real_escape_string($conn, $apiKey);
    $dateFilter = '';
    if ($upToDate !== null) {
        $escapedDate = mysqli_real_escape_string($conn, $upToDate);
        $dateFilter = " AND DATE(created_at) <= '$escapedDate'";
    }

    $depSql = "SELECT COALESCE(SUM(ABS(amount)), 0) AS total
               FROM binance_transactions
               WHERE api_key = '$escapedKey' AND type = 'DEPOSIT' $dateFilter";
    $depRow = mysqli_fetch_assoc(mysqli_query($conn, $depSql));
    $totalDeposits = isset($depRow['total']) ? (float)$depRow['total'] : 0.0;

    $wdSql = "SELECT COALESCE(SUM(ABS(amount)), 0) AS total
              FROM binance_transactions
              WHERE api_key = '$escapedKey' AND type = 'WITHDRAWAL' $dateFilter";
    $wdRow = mysqli_fetch_assoc(mysqli_query($conn, $wdSql));
    $totalWithdrawals = isset($wdRow['total']) ? (float)$wdRow['total'] : 0.0;

    // Deposits add, withdrawals subtract (amounts are absolute so we subtract)
    return $initialDeposit + $totalDeposits - $totalWithdrawals;
}

try {
    $currentMonth = date('Y-m', strtotime('first day of last month'));

    $dueDate = null;
    $monthDate = DateTime::createFromFormat('Y-m', $currentMonth);
    if ($monthDate) {
        $monthDate->modify('+1 month');
        $monthDate->setDate((int)$monthDate->format('Y'), (int)$monthDate->format('m'), 1);
        $monthDate->modify('+7 days');
        $dueDate = $monthDate->format('Y-m-d');
    }

    $monthStart = $currentMonth . '-01';
    $monthEnd   = date('Y-m-t', strtotime($monthStart));

    mysqli_begin_transaction($conn);

    $results = [
        'accounts_processed'    => 0,
        'accounts_billed'       => 0,
        'accounts_skipped'      => 0,
        'billing_periods_created' => 0,
        'emails_sent'           => 0,
        'emails_failed'         => 0,
        'errors'                => [],
        'details'               => [],
    ];

    $demoFilter = $invoiceDemoAccounts ? '' : " AND (COALESCE(demo, 0) = 0)";

    $accountsQuery = "SELECT api_key, uni_id, name, balance, initial_deposit, unrealized_pnl, currency_type
                      FROM binance_accounts
                      WHERE enabled = 1
                      AND deleted_at IS NULL
                      $demoFilter
                      ORDER BY name";

    $accountsResult = mysqli_query($conn, $accountsQuery);
    if (!$accountsResult) {
        throw new Exception("Failed to fetch binance_accounts: " . mysqli_error($conn));
    }

    $accounts = [];
    while ($row = mysqli_fetch_assoc($accountsResult)) {
        $accounts[] = $row;
    }
    mysqli_free_result($accountsResult);

    $results['accounts_processed'] = count($accounts);

    foreach ($accounts as $account) {
        $apiKey = $account['api_key'];
        $uniId  = $account['uni_id'];
        $escapedKey = mysqli_real_escape_string($conn, $apiKey);

        // Skip if in skip list
        if (in_array($apiKey, $skipApiKeys, true)) {
            $results['accounts_skipped']++;
            $results['details'][] = [
                'api_key'      => $apiKey,
                'account_name' => $account['name'],
                'status'       => 'skipped',
                'reason'       => 'Skipped (api_key in skip list)',
            ];
            continue;
        }

        // Skip if no user linked
        if (empty($uniId)) {
            $results['accounts_skipped']++;
            $results['details'][] = [
                'api_key'      => $apiKey,
                'account_name' => $account['name'],
                'status'       => 'skipped',
                'reason'       => 'Skipped (no user linked – uni_id is empty)',
            ];
            continue;
        }

        // Skip if already invoiced this month (UNIQUE constraint also prevents dups)
        $existsResult = mysqli_query($conn, "SELECT id FROM binance_invoices WHERE api_key = '$escapedKey' AND month_year = '$currentMonth' LIMIT 1");
        if ($existsResult && mysqli_num_rows($existsResult) > 0) {
            mysqli_free_result($existsResult);
            $results['accounts_skipped']++;
            $results['details'][] = [
                'api_key'      => $apiKey,
                'account_name' => $account['name'],
                'status'       => 'skipped',
                'reason'       => "Skipped (already invoiced for $currentMonth)",
            ];
            continue;
        }
        if ($existsResult) mysqli_free_result($existsResult);

        // Get user fee percentages
        $escapedUniId = mysqli_real_escape_string($conn, $uniId);
        $userResult = mysqli_query($conn, "SELECT email, name, realized_percentage, unrealized_percentage FROM user_credentials WHERE uni_id = '$escapedUniId' LIMIT 1");
        if (!$userResult || mysqli_num_rows($userResult) === 0) {
            $results['accounts_skipped']++;
            $results['details'][] = [
                'api_key'      => $apiKey,
                'account_name' => $account['name'],
                'status'       => 'skipped',
                'reason'       => 'Skipped (user not found in user_credentials)',
            ];
            if ($userResult) mysqli_free_result($userResult);
            continue;
        }
        $userData = mysqli_fetch_assoc($userResult);
        mysqli_free_result($userResult);

        $realizedFeePercent   = isset($userData['realized_percentage'])   ? (float)$userData['realized_percentage']   : 20.0;
        $unrealizedFeePercent = isset($userData['unrealized_percentage']) ? (float)$userData['unrealized_percentage'] : 30.0;
        $realizedFeeRate   = $realizedFeePercent   / 100.0;
        $unrealizedFeeRate = $unrealizedFeePercent / 100.0;

        // Check for previous invoice (HWM source)
        $prevResult = mysqli_query($conn, "SELECT hwm_after, equity_end, month_year FROM binance_invoices WHERE api_key = '$escapedKey' ORDER BY month_year DESC, created_at DESC LIMIT 1");
        $isFirstInvoice = !$prevResult || mysqli_num_rows($prevResult) === 0;

        // Financials
        $initialDeposit = (float)($account['initial_deposit'] ?? 0);
        $balance        = (float)($account['balance'] ?? 0);

        // Unrealized PnL: read from binance_accounts (broker-reported totalUnrealizedProfit)
        $unrealizedPnl = (float)($account['unrealized_pnl'] ?? 0);

        // Total realized PnL (all time) – for equity calculation
        $totalRlzRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(realized_pnl), 0) AS total FROM binance_pastpositions WHERE api_key = '$escapedKey'"));
        $totalRealizedPnl = isset($totalRlzRow['total']) ? (float)$totalRlzRow['total'] : 0.0;

        // Billing-month realized PnL
        $escapedMonthStart = mysqli_real_escape_string($conn, $monthStart);
        $escapedMonthEnd   = mysqli_real_escape_string($conn, $monthEnd);
        $monthRlzRow = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COALESCE(SUM(realized_pnl), 0) AS total
             FROM binance_pastpositions
             WHERE api_key = '$escapedKey'
             AND DATE(closed_at) >= '$escapedMonthStart'
             AND DATE(closed_at) <= '$escapedMonthEnd'"
        ));
        $realizedPnl = isset($monthRlzRow['total']) ? (float)$monthRlzRow['total'] : 0.0;

        // Adjusted deposit (cumulative)
        $adjustedDepositAmount = binanceAdjustedDeposit($conn, $apiKey, $initialDeposit);

        // Equity = adjusted_deposit + unrealized_pnl + total_realized_pnl
        $equity = $adjustedDepositAmount + $unrealizedPnl + $totalRealizedPnl;

        // Billing-month capital flow (deposits positive, withdrawals negative – amounts stored absolute)
        $depFlowRow = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COALESCE(SUM(ABS(amount)), 0) AS total
             FROM binance_transactions
             WHERE api_key = '$escapedKey' AND type = 'DEPOSIT'
             AND DATE(created_at) >= '$escapedMonthStart' AND DATE(created_at) <= '$escapedMonthEnd'"
        ));
        $monthDeposits = isset($depFlowRow['total']) ? (float)$depFlowRow['total'] : 0.0;

        $wdFlowRow = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COALESCE(SUM(ABS(amount)), 0) AS total
             FROM binance_transactions
             WHERE api_key = '$escapedKey' AND type = 'WITHDRAWAL'
             AND DATE(created_at) >= '$escapedMonthStart' AND DATE(created_at) <= '$escapedMonthEnd'"
        ));
        $monthWithdrawals = isset($wdFlowRow['total']) ? (float)$wdFlowRow['total'] : 0.0;

        // Capital flow: net (deposits positive, withdrawals as negative because stored absolute)
        $capitalFlow = $monthDeposits - $monthWithdrawals;

        // HWM logic (same algorithm as generate_invoices.php)
        $shouldChargeFees    = false;
        $hwmBefore           = 0.0;
        $hwmAfter            = 0.0;
        $newRealizedProfit   = 0.0;
        $newUnrealizedProfit = 0.0;
        $performanceEquity   = $equity;

        if ($isFirstInvoice) {
            $hwm = ($initialDeposit > 0) ? $initialDeposit : $adjustedDepositAmount;
            $hwmBefore = $hwm;
            $performanceEquity = $equity;

            if ($equity > $hwm) {
                $shouldChargeFees    = true;
                $newRealizedProfit   = max(0, $realizedPnl);
                $newUnrealizedProfit = max(0, $unrealizedPnl);
                $hwmAfter = max($hwm, $equity);
            } elseif ($capitalFlow < 0 && $realizedPnl > 0 && $unrealizedPnl > 0) {
                $shouldChargeFees    = true;
                $newRealizedProfit   = max(0, $realizedPnl);
                $newUnrealizedProfit = max(0, $unrealizedPnl);
                $hwmAfter = $equity;
            } else {
                $hwmAfter = $hwmBefore;
            }
        } else {
            $prevRow       = mysqli_fetch_assoc($prevResult);
            $previousHWM   = (float)($prevRow['hwm_after']  ?? 0);
            $previousEquity = (float)($prevRow['equity_end'] ?? 0);
            $previousMonthYear = $prevRow['month_year'] ?? null;
            mysqli_free_result($prevResult);

            $hwmBefore = $previousHWM;

            $tradingProfit     = $realizedPnl + $unrealizedPnl;
            $performanceEquity = $previousEquity + $tradingProfit;

            if ($equity > $hwmBefore) {
                $shouldChargeFees    = true;
                $newRealizedProfit   = max(0, $realizedPnl);
                $newUnrealizedProfit = max(0, $unrealizedPnl);
                $hwmAfter = $equity;
            } elseif ($capitalFlow < 0 && $realizedPnl > 0 && $unrealizedPnl > 0) {
                $shouldChargeFees    = true;
                $newRealizedProfit   = max(0, $realizedPnl);
                $newUnrealizedProfit = max(0, $unrealizedPnl);
                $hwmAfter = $equity;
            } else {
                $hwmAfter = $hwmBefore;
            }
        }

        if ($prevResult && is_resource($prevResult)) {
            mysqli_free_result($prevResult);
        }

        $feeRealized    = $newRealizedProfit   * $realizedFeeRate;
        $feeUnrealized  = $newUnrealizedProfit * $unrealizedFeeRate;
        $totalFee       = $feeRealized + $feeUnrealized;

        $equityStart    = $hwmBefore;
        $equityEnd      = $equity;
        $adjustedEquity = $equity;
        $billingStatus  = ($totalFee > 0) ? 'pending' : 'paid';

        $escapedMonth   = mysqli_real_escape_string($conn, $currentMonth);
        $escapedStatus  = mysqli_real_escape_string($conn, $billingStatus);
        $escapedDueDate = $dueDate !== null ? "'" . mysqli_real_escape_string($conn, $dueDate) . "'" : 'NULL';

        $insertSql = "INSERT INTO binance_invoices (
                        user_id, api_key, month_year,
                        equity_start, equity_end,
                        realized_pnl, unrealized_pnl, deposit_amount,
                        adjusted_equity, capital_flow, performance_equity,
                        hwm_before, hwm_after,
                        new_realized_profit, new_unrealized_profit,
                        fee_realized, fee_unrealized, total_fee,
                        status, due_date, created_at
                      ) VALUES (
                        '$escapedUniId', '$escapedKey', '$escapedMonth',
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
                        '$escapedStatus', $escapedDueDate, NOW()
                      )";

        if (!mysqli_query($conn, $insertSql)) {
            $err = mysqli_error($conn);
            // Unique constraint hit = already billed; treat as skip
            if (strpos($err, 'Duplicate') !== false) {
                $results['accounts_skipped']++;
                $results['details'][] = [
                    'api_key'      => $apiKey,
                    'account_name' => $account['name'],
                    'status'       => 'skipped',
                    'reason'       => "Skipped (duplicate – already invoiced for $currentMonth)",
                ];
                continue;
            }
            throw new Exception("Failed to insert binance_invoice for $apiKey: $err");
        }

        $billingPeriodId = mysqli_insert_id($conn);
        $results['billing_periods_created']++;
        $results['accounts_billed']++;

        // Email (same flow as generate_invoices.php)
        $emailSent  = false;
        $emailError = null;
        $emailSendingEnabled = false;
        $emailSendingQuery = "SELECT email_sending FROM bot_config WHERE id = 5163321 LIMIT 1";
        $emailSendingResult = mysqli_query($conn, $emailSendingQuery);
        if ($emailSendingResult && mysqli_num_rows($emailSendingResult) > 0) {
            $emailRow = mysqli_fetch_assoc($emailSendingResult);
            $emailSendingEnabled = (strtoupper(trim($emailRow['email_sending'] ?? '')) === 'ON');
        }

        if ($totalFee > 0 && $emailSendingEnabled) {
            $invoiceEmailData = [
                'month_year'            => $currentMonth,
                'total_fee'             => $totalFee,
                'fee_realized'          => $feeRealized,
                'fee_unrealized'        => $feeUnrealized,
                'currency'              => $account['currency_type'] ?? 'USDT',
                'hwm_before'            => $hwmBefore,
                'equity_end'            => $equityEnd,
                'deposit_amount'        => $adjustedDepositAmount,
                'realized_fee_percent'  => $realizedFeePercent,
                'unrealized_fee_percent' => $unrealizedFeePercent,
            ];
            $emailResult = sendInvoiceEmail($conn, $uniId, $invoiceEmailData);
            if ($emailResult['success']) {
                $results['emails_sent']++;
                $emailSent = true;
                mysqli_query($conn, "UPDATE binance_invoices SET invoice_sent_at = NOW() WHERE id = " . (int)$billingPeriodId);
            } else {
                $results['emails_failed']++;
                $emailError = $emailResult['message'] ?? 'Unknown error';
            }
        }

        $results['details'][] = [
            'api_key'          => $apiKey,
            'account_name'     => $account['name'],
            'status'           => 'billed',
            'billing_status'   => $billingStatus,
            'total_fee'        => $totalFee,
            'fee_realized'     => $feeRealized,
            'fee_unrealized'   => $feeUnrealized,
            'equity'           => $equity,
            'performance_equity' => $performanceEquity,
            'capital_flow'     => $capitalFlow,
            'hwm_before'       => $hwmBefore,
            'hwm_after'        => $hwmAfter,
            'should_charge_fees' => $shouldChargeFees,
            'email_sent'       => $emailSent,
            'email_error'      => $emailError,
        ];
    }

    mysqli_commit($conn);

    ob_clean();
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Binance invoices generated successfully',
        'month'   => $currentMonth,
        'results' => $results,
    ]);
    ob_end_flush();

} catch (Exception $e) {
    mysqli_rollback($conn);
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
        'results' => $results ?? [],
    ]);
    ob_end_flush();
} finally {
    if (isset($conn) && $conn) {
        mysqli_close($conn);
    }
}
?>
