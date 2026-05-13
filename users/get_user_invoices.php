<?php
/**
 * Get User Invoices API
 * Fetches invoices from the new invoices and billing_periods tables
 * Returns invoices with breakdown per account
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
    // Get uni_id from request
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!isset($data['uni_id']) || empty($data['uni_id'])) {
        throw new Exception("uni_id is required");
    }
    
    $uni_id = trim($data['uni_id']);
    
    // Get all invoices for this user
    $invoicesQuery = "SELECT 
                        i.id as invoice_id,
                        i.user_id,
                        i.month_year,
                        i.total_fee,
                        i.status,
                        i.generated_at,
                        i.due_date,
                        i.paid_at
                      FROM invoices i
                      WHERE i.user_id = ?
                      ORDER BY i.month_year DESC, i.generated_at DESC";
    
    $stmt = mysqli_prepare($conn, $invoicesQuery);
    if (!$stmt) {
        throw new Exception("Failed to prepare invoices query: " . mysqli_error($conn));
    }
    
    mysqli_stmt_bind_param($stmt, "s", $uni_id);
    
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        throw new Exception("Failed to execute invoices query: " . mysqli_stmt_error($stmt));
    }
    
    $result = mysqli_stmt_get_result($stmt);
    $invoices = [];
    
    while ($invoiceRow = mysqli_fetch_assoc($result)) {
        $invoiceId = $invoiceRow['invoice_id'];
        $monthYear = $invoiceRow['month_year'];
        
        // Get billing periods (account breakdown) for this invoice
        $breakdownQuery = "SELECT 
                            bp.account_id,
                            bp.month_year,
                            bp.equity_start,
                            bp.equity_end,
                            bp.realized_pnl,
                            bp.unrealized_pnl,
                            bp.deposit_amount,
                            bp.adjusted_equity,
                            bp.hwm_before,
                            bp.hwm_after,
                            bp.new_realized_profit,
                            bp.new_unrealized_profit,
                            bp.fee_realized,
                            bp.fee_unrealized,
                            bp.total_fee,
                            bp.created_at,
                            a.name as account_name,
                            a.email as account_email,
                            a.currency_type
                          FROM billing_periods bp
                          INNER JOIN accounts a ON bp.account_id = a.id
                          WHERE bp.user_id = ? AND bp.month_year = ?
                          ORDER BY bp.account_id";
        
        $breakdownStmt = mysqli_prepare($conn, $breakdownQuery);
        if (!$breakdownStmt) {
            continue; // Skip this invoice if breakdown query fails
        }
        
        mysqli_stmt_bind_param($breakdownStmt, "ss", $uni_id, $monthYear);
        mysqli_stmt_execute($breakdownStmt);
        $breakdownResult = mysqli_stmt_get_result($breakdownStmt);
        
        $accounts = [];
        while ($accountRow = mysqli_fetch_assoc($breakdownResult)) {
            $accounts[] = [
                'account_id' => (int)$accountRow['account_id'],
                'account_name' => $accountRow['account_name'],
                'account_email' => $accountRow['account_email'],
                'currency_type' => $accountRow['currency_type'] ?? 'USD',
                'realized_pnl' => (float)$accountRow['realized_pnl'],
                'unrealized_pnl' => (float)$accountRow['unrealized_pnl'],
                'fee_realized' => (float)$accountRow['fee_realized'],
                'fee_unrealized' => (float)$accountRow['fee_unrealized'],
                'total_fee' => (float)$accountRow['total_fee'],
                'hwm_before' => (float)$accountRow['hwm_before'],
                'hwm_after' => (float)$accountRow['hwm_after'],
                'equity_start' => (float)$accountRow['equity_start'],
                'equity_end' => (float)$accountRow['equity_end'],
                'adjusted_equity' => (float)$accountRow['adjusted_equity'],
                'new_realized_profit' => (float)($accountRow['new_realized_profit'] ?? 0),
                'new_unrealized_profit' => (float)($accountRow['new_unrealized_profit'] ?? 0),
            ];
        }
        
        mysqli_stmt_close($breakdownStmt);
        
        // Calculate days remaining and check if overdue
        $dueDate = new DateTime($invoiceRow['due_date']);
        $now = new DateTime();
        $daysRemaining = $now->diff($dueDate)->days;
        if ($dueDate < $now) {
            $daysRemaining = -$daysRemaining;
        }
        
        $isOverdue = $invoiceRow['status'] === 'overdue' || ($daysRemaining < 0 && $invoiceRow['status'] === 'pending');
        $isPaid = $invoiceRow['status'] === 'paid' || !empty($invoiceRow['paid_at']);
        
        // Format month_year to readable format (e.g., "December 2024")
        $monthDate = DateTime::createFromFormat('Y-m', $monthYear);
        $invoiceMonth = $monthDate ? $monthDate->format('F Y') : $monthYear;
        
        $invoices[] = [
            'invoice_id' => (int)$invoiceId,
            'month_year' => $monthYear,
            'invoice_month' => $invoiceMonth,
            'total_fee' => (float)$invoiceRow['total_fee'],
            'status' => $invoiceRow['status'],
            'generated_at' => $invoiceRow['generated_at'],
            'due_date' => $invoiceRow['due_date'],
            'paid_at' => $invoiceRow['paid_at'],
            'days_remaining' => $daysRemaining,
            'is_overdue' => $isOverdue,
            'is_paid' => $isPaid,
            'accounts' => $accounts
        ];
    }
    
    mysqli_stmt_close($stmt);
    
    ob_clean();
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'invoices' => $invoices
    ]);
    ob_end_flush();
    
} catch (Exception $e) {
    ob_clean();
    http_response_code(500);
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

