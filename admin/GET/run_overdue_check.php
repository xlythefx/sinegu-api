<?php
/**
 * Admin: Run overdue check – mark billing_periods overdue, disable accounts, send disconnection email once.
 * GET /admin/GET/run_overdue_check.php
 *
 * Logic (same design as cron/mark_overdue_and_disconnect.php):
 * 1. Find billing_periods where due_date < CURDATE() and status = 'pending' only (do not touch already 'overdue').
 * 2. For each: set status = 'overdue', send one "you have been disconnected" email, collect account_id.
 * 3. Set accounts.enabled = 0 for those account_ids.
 * We only email when we first mark as overdue; if status is already overdue we do nothing for that row (no email).
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../../conn.php';
require_once __DIR__ . '/../../includes/send_invoice_email.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$markedOverdue = 0;
$emailsSent = 0;
$accountsDisabled = 0;
$errors = [];

// 1) Find only PENDING billing_periods where due_date < today (never select already 'overdue' – no email for those)
$sql = "SELECT bp.id, bp.user_id, bp.account_id, bp.month_year, bp.total_fee, a.currency_type
        FROM billing_periods bp
        INNER JOIN accounts a ON bp.account_id = a.id
        WHERE bp.due_date IS NOT NULL AND bp.due_date < CURDATE() AND bp.status = 'pending'";
$res = mysqli_query($conn, $sql);
if (!$res) {
    echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

$accountIds = [];
while ($row = mysqli_fetch_assoc($res)) {
    $bpId = (int) $row['id'];
    $uniId = $row['user_id'];
    $accountIds[(int) $row['account_id']] = true;
    $invoiceData = [
        'month_year' => $row['month_year'],
        'total_fee' => (float) ($row['total_fee'] ?? 0),
        'currency' => $row['currency_type'] ?? 'USD',
    ];

    $up = "UPDATE billing_periods SET status = 'overdue', updated_at = NOW() WHERE id = $bpId";
    if (!mysqli_query($conn, $up)) {
        $errors[] = ['billing_period_id' => $bpId, 'error' => mysqli_error($conn)];
        continue;
    }
    $markedOverdue++;

    $emailResult = sendOverdueDisconnectEmail($conn, $uniId, $invoiceData);
    if ($emailResult['success']) {
        $emailsSent++;
    } else {
        $errors[] = ['billing_period_id' => $bpId, 'email_error' => $emailResult['message'] ?? 'Send failed'];
    }
}

$accountIds = array_keys($accountIds);

// 2) Set accounts.enabled = 0 for those account_ids
if (count($accountIds) > 0) {
    $idList = implode(',', array_map('intval', $accountIds));
    $upAcc = "UPDATE accounts SET enabled = 0 WHERE id IN ($idList)";
    if (mysqli_query($conn, $upAcc)) {
        $accountsDisabled = mysqli_affected_rows($conn);
    } else {
        $errors[] = ['step' => 'accounts', 'error' => mysqli_error($conn)];
    }
}

mysqli_close($conn);

echo json_encode([
    'success' => count($errors) === 0,
    'marked_overdue' => $markedOverdue,
    'emails_sent' => $emailsSent,
    'accounts_disabled' => $accountsDisabled,
    'errors' => $errors,
]);
