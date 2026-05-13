<?php

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['uni_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'User ID (uni_id) is required']);
    exit;
}

$uniId = mysqli_real_escape_string($conn, $input['uni_id']);

/**
 * Shared: compute date fields and days_remaining from a month_year + optional stored due_date.
 */
function computeDates($monthYear, $storedDueDate) {
    $invoiceDate = '';
    $dueDate = '';
    $invoiceMonth = '';

    if (!empty($storedDueDate)) {
        $dueDate = $storedDueDate;
    }

    if ($monthYear) {
        try {
            $monthDate = DateTime::createFromFormat('Y-m', $monthYear);
            if ($monthDate) {
                $invoiceDateObj = clone $monthDate;
                $invoiceDateObj->modify('+1 month');
                $invoiceDateObj->setDate((int)$invoiceDateObj->format('Y'), (int)$invoiceDateObj->format('m'), 1);
                $invoiceDate = $invoiceDateObj->format('Y-m-d');

                if (empty($dueDate)) {
                    $dueDateObj = clone $invoiceDateObj;
                    $dueDateObj->modify('+7 days');
                    $dueDate = $dueDateObj->format('Y-m-d');
                }

                $invoiceMonth = $monthDate->format('F Y');
            }
        } catch (Exception $e) {
            $invoiceDate = date('Y-m-01');
            if (empty($dueDate)) {
                $dueDate = date('Y-m-d', strtotime('+7 days'));
            }
            $invoiceMonth = (new DateTime())->format('F Y');
        }
    }

    $daysRemaining = 0;
    $isOverdue = false;
    if ($dueDate) {
        try {
            $dueDateObj = new DateTime($dueDate);
            $dueDateObj->setTime(23, 59, 59);
            $now = new DateTime();
            $now->setTime(0, 0, 0);

            if ($dueDateObj > $now) {
                $interval = $now->diff($dueDateObj);
                $daysRemaining = max(1, (int)$interval->days);
                $isOverdue = false;
            } else {
                $daysRemaining = 0;
                $isOverdue = true;
            }
        } catch (Exception $e) {}
    }

    return [$invoiceDate, $dueDate, $invoiceMonth, $daysRemaining, $isOverdue];
}

/**
 * Build a normalised invoice entry shared by all brokers.
 */
function buildEntry($broker, $billingPeriodId, $accountId, $accountName, $accountEmail,
                    $monthYear, $currencyType, $initialDeposit,
                    $hwmBefore, $hwmAfter, $equityEnd, $adjustedEquity,
                    $realizedPnl, $unrealizedPnl, $newRealizedProfit, $newUnrealizedProfit,
                    $realizedFee, $unrealizedFee, $totalFee,
                    $bpStatus, $storedDueDate, $createdAt, $updatedAt, $paidAt) {

    $isFirstInvoice = ($hwmBefore == 0.0);

    $performanceGainPercent = 0.0;
    $referenceValue = 0.0;
    $referenceLabel = 'Deposit';

    if ($isFirstInvoice) {
        if ($initialDeposit > 0) {
            $periodGain = $equityEnd - $initialDeposit;
            $performanceGainPercent = ($periodGain / $initialDeposit) * 100;
            $referenceValue = $initialDeposit;
            $referenceLabel = 'Initial Deposit';
        }
    } else {
        if ($hwmBefore > 0) {
            $periodGain = $equityEnd - $hwmBefore;
            $performanceGainPercent = ($periodGain / $hwmBefore) * 100;
        }
        $referenceValue = $hwmBefore;
        $referenceLabel = 'Previous HWM';
    }

    [$invoiceDate, $dueDate, $invoiceMonth, $daysRemaining, $isOverdue] = computeDates($monthYear, $storedDueDate);

    $isPaid = ($bpStatus === 'paid');
    $paidDate = null;
    if ($isPaid && !empty($paidAt)) {
        $paidDate = $paidAt;
    }
    if ($isPaid && empty($paidDate) && !empty($updatedAt)) {
        $paidDate = $updatedAt;
    }

    $formattedInvoiceId = $billingPeriodId ? 'INV-' . str_pad($billingPeriodId, 3, '0', STR_PAD_LEFT) : null;

    return [
        'broker'               => $broker,
        'billing_period_id'    => $billingPeriodId,
        'invoice_id'           => null,
        'formatted_invoice_id' => $formattedInvoiceId,
        'account_id'           => $accountId,
        'account_name'         => $accountName ?: 'Account',
        'account_email'        => $accountEmail ?: '',
        'amount'               => round($totalFee, 2),
        'currency_type'        => $currencyType ?: 'USD',
        'performance_gain'     => round($performanceGainPercent, 2),
        'hwm'                  => $hwmAfter > 0 ? $hwmAfter : null,
        'previous_hwm'         => $hwmBefore > 0 ? $hwmBefore : null,
        'current_balance'      => round($adjustedEquity > 0 ? $adjustedEquity : $equityEnd, 2),
        'deposit_amount'       => round($equityEnd - $realizedPnl, 2),
        'realized_pnl'         => $realizedPnl,
        'unrealized_pnl'       => $unrealizedPnl,
        'new_realized_profit'  => round($newRealizedProfit, 2),
        'new_unrealized_profit' => round($newUnrealizedProfit, 2),
        'realized_fee'         => round($realizedFee, 2),
        'unrealized_fee'       => round($unrealizedFee, 2),
        'reference_value'      => round($referenceValue, 2),
        'reference_label'      => $referenceLabel,
        'invoice_date'         => $invoiceDate,
        'due_date'             => $dueDate,
        'invoice_month'        => $invoiceMonth,
        'month_year'           => $monthYear,
        'is_first_invoice'     => $isFirstInvoice,
        'days_remaining'       => max(0, $daysRemaining),
        'is_overdue'           => $isOverdue,
        'is_paid'              => $isPaid,
        'status'               => $bpStatus,
        'paid_date'            => $paidDate,
    ];
}

$invoices = [];

// ── 1. Capital.com invoices (billing_periods + accounts) ────────────────────
$capitalQuery = "SELECT
    bp.id              AS billing_period_id,
    bp.account_id,
    bp.month_year      AS bp_month_year,
    bp.equity_end,
    bp.realized_pnl,
    bp.unrealized_pnl,
    bp.adjusted_equity,
    bp.hwm_before,
    bp.hwm_after,
    bp.new_realized_profit,
    bp.new_unrealized_profit,
    bp.fee_realized,
    bp.fee_unrealized,
    bp.total_fee,
    bp.status          AS bp_status,
    bp.due_date        AS bp_due_date,
    bp.created_at,
    bp.updated_at,
    a.name             AS account_name,
    a.email            AS account_email,
    a.currency_type,
    a.initial_deposit
FROM billing_periods bp
INNER JOIN accounts a ON bp.account_id = a.id
WHERE bp.user_id = '$uniId'
ORDER BY bp.month_year DESC, bp.created_at DESC";

$capitalResult = mysqli_query($conn, $capitalQuery);
if ($capitalResult) {
    while ($row = mysqli_fetch_assoc($capitalResult)) {
        $invoices[] = buildEntry(
            'capital.com',
            (int)$row['billing_period_id'],
            (int)$row['account_id'],
            $row['account_name'],
            $row['account_email'],
            $row['bp_month_year'],
            $row['currency_type'],
            (float)($row['initial_deposit'] ?? 0),
            (float)($row['hwm_before'] ?? 0),
            (float)($row['hwm_after'] ?? 0),
            (float)($row['equity_end'] ?? 0),
            (float)($row['adjusted_equity'] ?? 0),
            (float)($row['realized_pnl'] ?? 0),
            (float)($row['unrealized_pnl'] ?? 0),
            (float)($row['new_realized_profit'] ?? 0),
            (float)($row['new_unrealized_profit'] ?? 0),
            (float)($row['fee_realized'] ?? 0),
            (float)($row['fee_unrealized'] ?? 0),
            (float)($row['total_fee'] ?? 0),
            $row['bp_status'] ?? 'pending',
            $row['bp_due_date'] ?? '',
            $row['created_at'] ?? '',
            $row['updated_at'] ?? '',
            null
        );
    }
    mysqli_free_result($capitalResult);
}

// ── 2. Binance invoices ──────────────────────────────────────────────────────
$binanceQuery = "SELECT
    bi.id              AS billing_period_id,
    bi.api_key,
    bi.month_year,
    bi.equity_end,
    bi.realized_pnl,
    bi.unrealized_pnl,
    bi.adjusted_equity,
    bi.hwm_before,
    bi.hwm_after,
    bi.new_realized_profit,
    bi.new_unrealized_profit,
    bi.fee_realized,
    bi.fee_unrealized,
    bi.total_fee,
    bi.status,
    bi.due_date,
    bi.created_at,
    bi.updated_at,
    ba.name            AS account_name,
    ba.currency_type,
    ba.initial_deposit
FROM binance_invoices bi
INNER JOIN binance_accounts ba ON bi.api_key = ba.api_key
WHERE bi.user_id = '$uniId'
ORDER BY bi.month_year DESC, bi.created_at DESC";

$binanceResult = mysqli_query($conn, $binanceQuery);
if ($binanceResult) {
    while ($row = mysqli_fetch_assoc($binanceResult)) {
        $invoices[] = buildEntry(
            'binance',
            (int)$row['billing_period_id'],
            null,
            $row['account_name'],
            '',
            $row['month_year'],
            $row['currency_type'],
            (float)($row['initial_deposit'] ?? 0),
            (float)($row['hwm_before'] ?? 0),
            (float)($row['hwm_after'] ?? 0),
            (float)($row['equity_end'] ?? 0),
            (float)($row['adjusted_equity'] ?? 0),
            (float)($row['realized_pnl'] ?? 0),
            (float)($row['unrealized_pnl'] ?? 0),
            (float)($row['new_realized_profit'] ?? 0),
            (float)($row['new_unrealized_profit'] ?? 0),
            (float)($row['fee_realized'] ?? 0),
            (float)($row['fee_unrealized'] ?? 0),
            (float)($row['total_fee'] ?? 0),
            $row['status'] ?? 'pending',
            $row['due_date'] ?? '',
            $row['created_at'] ?? '',
            $row['updated_at'] ?? '',
            null
        );
    }
    mysqli_free_result($binanceResult);
}

// ── 3. IG invoices ───────────────────────────────────────────────────────────
$igQuery = "SELECT
    ii.id              AS billing_period_id,
    ii.account_id,
    ii.api_key,
    ii.month_year,
    ii.equity_end,
    ii.realized_pnl,
    ii.unrealized_pnl,
    ii.adjusted_equity,
    ii.hwm_before,
    ii.hwm_after,
    ii.new_realized_profit,
    ii.new_unrealized_profit,
    ii.fee_realized,
    ii.fee_unrealized,
    ii.total_fee,
    ii.status,
    ii.due_date,
    ii.created_at,
    ii.updated_at,
    ia.name            AS account_name,
    ia.email           AS account_email,
    ia.currency_type,
    ia.initial_deposit
FROM ig_invoices ii
INNER JOIN ig_accounts ia ON ii.api_key = ia.api_key
WHERE ii.user_id = '$uniId'
ORDER BY ii.month_year DESC, ii.created_at DESC";

$igResult = mysqli_query($conn, $igQuery);
if ($igResult) {
    while ($row = mysqli_fetch_assoc($igResult)) {
        $invoices[] = buildEntry(
            'ig',
            (int)$row['billing_period_id'],
            isset($row['account_id']) ? (int)$row['account_id'] : null,
            $row['account_name'],
            $row['account_email'] ?? '',
            $row['month_year'],
            $row['currency_type'],
            (float)($row['initial_deposit'] ?? 0),
            (float)($row['hwm_before'] ?? 0),
            (float)($row['hwm_after'] ?? 0),
            (float)($row['equity_end'] ?? 0),
            (float)($row['adjusted_equity'] ?? 0),
            (float)($row['realized_pnl'] ?? 0),
            (float)($row['unrealized_pnl'] ?? 0),
            (float)($row['new_realized_profit'] ?? 0),
            (float)($row['new_unrealized_profit'] ?? 0),
            (float)($row['fee_realized'] ?? 0),
            (float)($row['fee_unrealized'] ?? 0),
            (float)($row['total_fee'] ?? 0),
            $row['status'] ?? 'pending',
            $row['due_date'] ?? '',
            $row['created_at'] ?? '',
            $row['updated_at'] ?? '',
            null
        );
    }
    mysqli_free_result($igResult);
}

// Sort all invoices newest-first by month_year desc then created_at desc
usort($invoices, function($a, $b) {
    $my = strcmp($b['month_year'] ?? '', $a['month_year'] ?? '');
    if ($my !== 0) return $my;
    return strcmp($b['invoice_date'] ?? '', $a['invoice_date'] ?? '');
});

mysqli_close($conn);

echo json_encode([
    'success'  => true,
    'invoices' => $invoices,
]);
?>
