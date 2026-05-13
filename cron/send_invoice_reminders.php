<?php
/**
 * Cron: Send invoice reminder emails (2nd, 3rd, 4th).
 * Schedule: Run daily (e.g. 09:00 USA Eastern) on 1st–8th of each month only.
 * Logic: 2nd reminder after 2 days, 3rd after 4 days, 4th on day 7 / due date.
 *
 * CLI: php cron/send_invoice_reminders.php (e.g. from Windows Task Scheduler).
 * HTTP: GET or POST http://localhost/sinegu-api/cron/send_invoice_reminders.php for testing.
 */

$isCli = php_sapi_name() === 'cli';
date_default_timezone_set('America/New_York');
// Log that script was executed (for Task Scheduler / cron debugging)
file_put_contents(
    __DIR__ . '/../cron_reminders.log',
    date('Y-m-d H:i:s') . ' — send_invoice_reminders.php run_mode=' . ($isCli ? 'cli' : 'http') . "\n",
    FILE_APPEND
);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$dayOfMonth = (int) date('j');
if ($dayOfMonth < 1 || $dayOfMonth > 8) {
    echo json_encode([
        'success' => true,
        'message' => 'Reminder run skipped: only runs on 1st–8th of month. Today is day ' . $dayOfMonth . '.',
        'run_mode' => $isCli ? 'cli' : 'http',
        'reminders_sent' => 0,
        'rows_checked' => 0,
        'rows_skipped_reason' => [],
        'errors' => [],
        'details' => [],
    ]);
    exit;
}

require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/../includes/send_invoice_email.php';

if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$today = date('Y-m-d');
$results = ['reminders_sent' => 0, 'errors' => [], 'details' => [], 'rows_checked' => 0, 'rows_skipped_reason' => []];

$sql = "SELECT bp.id, bp.user_id, bp.month_year, bp.total_fee, bp.reminder_count, bp.invoice_sent_at, bp.created_at, bp.due_date, a.currency_type
        FROM billing_periods bp
        INNER JOIN accounts a ON bp.account_id = a.id
        WHERE bp.status = 'pending' AND bp.total_fee > 0 AND (bp.due_date IS NULL OR bp.due_date >= CURDATE())";
$res = mysqli_query($conn, $sql);
if (!$res) {
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    exit;
}

while ($row = mysqli_fetch_assoc($res)) {
    $results['rows_checked']++;
    $bpId = (int) $row['id'];
    $uniId = $row['user_id'];
    $sentAt = $row['invoice_sent_at'] ?: $row['created_at'];
    if (!$sentAt) {
        $results['rows_skipped_reason']['no_sent_date'] = ($results['rows_skipped_reason']['no_sent_date'] ?? 0) + 1;
        continue;
    }
    $sentDate = date('Y-m-d', strtotime($sentAt));
    $daysSince = (strtotime($today) - strtotime($sentDate)) / 86400;
    $reminderCount = (int) $row['reminder_count'];
    $dueDate = $row['due_date'];

    $invoiceData = [
        'month_year' => $row['month_year'],
        'total_fee' => (float) $row['total_fee'],
        'currency' => $row['currency_type'] ?? 'USD',
    ];

    $sendReminder = null; // 2, 3, or 4
    if ($reminderCount === 0 && $daysSince >= 2) {
        $sendReminder = 2;
    } elseif ($reminderCount === 1 && $daysSince >= 4) {
        $sendReminder = 3;
    } elseif ($reminderCount === 2 && ($daysSince >= 7 || ($dueDate && $today >= $dueDate))) {
        $sendReminder = 4;
    }

    if ($sendReminder === null) {
        $key = 'schedule_not_met_bp' . $bpId;
        $results['rows_skipped_reason'][$key] = "reminder_count=$reminderCount, days_since_sent=" . round($daysSince, 1) . " (need 2/4/7 for 2nd/3rd/4th)";
        continue;
    }

    $emailResult = sendInvoiceReminderEmail($conn, $uniId, $invoiceData, $sendReminder);
    if ($emailResult['success']) {
        $newCount = $sendReminder === 2 ? 1 : ($sendReminder === 3 ? 2 : 3);
        $up = "UPDATE billing_periods SET reminder_count = $newCount, last_reminder_sent_at = NOW() WHERE id = $bpId";
        mysqli_query($conn, $up);
        $results['reminders_sent']++;
        $results['details'][] = ['billing_period_id' => $bpId, 'reminder' => $sendReminder, 'status' => 'sent'];
    } else {
        $results['errors'][] = ['billing_period_id' => $bpId, 'reminder' => $sendReminder, 'error' => $emailResult['message']];
    }
}

$message = $results['reminders_sent'] > 0
    ? 'Reminder run complete. ' . $results['reminders_sent'] . ' reminder(s) sent.'
    : 'Reminder run complete. No reminders sent. Check: invoice_sent_at must be 2+ days ago for 2nd reminder, reminder_count must be 0 for 2nd (1 for 3rd, 2 for 4th). See rows_skipped_reason for why each row was skipped.';

echo json_encode([
    'success' => true,
    'message' => $message,
    'run_mode' => $isCli ? 'cli' : 'http',
    'reminders_sent' => $results['reminders_sent'],
    'rows_checked' => $results['rows_checked'],
    'rows_skipped_reason' => $results['rows_skipped_reason'],
    'errors' => $results['errors'],
    'details' => $results['details'],
]);
