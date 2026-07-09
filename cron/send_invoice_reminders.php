<?php
/**
 * Cron: Send invoice reminder emails (2nd, 3rd, 4th) — all brokers.
 * Schedule: daily (e.g. 09:00 America/New_York), fired by the Telegram billing cron listener.
 * Reminder cadence (keyed off invoice_sent_at + reminder_count, not day of month):
 *   2nd after 2 days, 3rd after 4 days, 4th on day 7 / due date.
 * The 1st–8th month window is retained as a safety bound for the reminder phase; the
 * overdue/disconnect step (mark_overdue_and_disconnect.php) takes over from day 9.
 *
 * Covers capital.com (billing_periods), binance (binance_invoices), ig (ig_invoices).
 * Secret-guarded (BILLING_CRON_SECRET).
 *
 * CLI:  php cron/send_invoice_reminders.php
 * HTTP: GET/POST http://localhost/sinegu-api/cron/send_invoice_reminders.php (header X-Billing-Secret)
 */

$isCli = php_sapi_name() === 'cli';
date_default_timezone_set('America/New_York');
@file_put_contents(
    __DIR__ . '/../cron_reminders.log',
    date('Y-m-d H:i:s') . ' — send_invoice_reminders.php run_mode=' . ($isCli ? 'cli' : 'http') . "\n",
    FILE_APPEND
);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Billing-Secret');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../includes/cron_auth.php';
require_billing_cron_secret();

$dayOfMonth = (int) date('j');
if ($dayOfMonth < 1 || $dayOfMonth > 8) {
    echo json_encode([
        'success' => true,
        'message' => 'Reminder run skipped: only runs on 1st–8th of month. Today is day ' . $dayOfMonth . '.',
        'run_mode' => $isCli ? 'cli' : 'http',
        'reminders_sent' => 0,
        'rows_checked' => 0,
        'errors' => [],
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

// Per-broker descriptors. Each SELECT returns:
//   id, user_id, month_year, total_fee, reminder_count, invoice_sent_at, created_at, due_date, currency_type
$brokers = [
    'capital.com' => [
        'invoice_table' => 'billing_periods',
        'select' => "SELECT bp.id, bp.user_id, bp.month_year, bp.total_fee, bp.reminder_count,
                            bp.invoice_sent_at, bp.created_at, bp.due_date, a.currency_type
                     FROM billing_periods bp
                     INNER JOIN accounts a ON bp.account_id = a.id
                     WHERE bp.status = 'pending' AND bp.total_fee > 0
                       AND (bp.due_date IS NULL OR bp.due_date >= CURDATE())",
    ],
    'binance' => [
        'invoice_table' => 'binance_invoices',
        'select' => "SELECT bp.id, bp.user_id, bp.month_year, bp.total_fee, bp.reminder_count,
                            bp.invoice_sent_at, bp.created_at, bp.due_date, a.currency_type
                     FROM binance_invoices bp
                     INNER JOIN binance_accounts a ON bp.api_key = a.api_key
                     WHERE bp.status = 'pending' AND bp.total_fee > 0
                       AND (bp.due_date IS NULL OR bp.due_date >= CURDATE())",
    ],
    'ig' => [
        'invoice_table' => 'ig_invoices',
        'select' => "SELECT bp.id, bp.user_id, bp.month_year, bp.total_fee, bp.reminder_count,
                            bp.invoice_sent_at, bp.created_at, bp.due_date, a.currency_type
                     FROM ig_invoices bp
                     INNER JOIN ig_accounts a ON bp.api_key = a.api_key
                     WHERE bp.status = 'pending' AND bp.total_fee > 0
                       AND (bp.due_date IS NULL OR bp.due_date >= CURDATE())",
    ],
];

$results = ['reminders_sent' => 0, 'rows_checked' => 0, 'by_broker' => [], 'errors' => [], 'details' => []];

foreach ($brokers as $brokerName => $cfg) {
    $sent = 0;
    $res = mysqli_query($conn, $cfg['select']);
    if (!$res) {
        $results['errors'][] = ['broker' => $brokerName, 'error' => mysqli_error($conn)];
        $results['by_broker'][$brokerName] = 0;
        continue;
    }

    while ($row = mysqli_fetch_assoc($res)) {
        $results['rows_checked']++;
        $invId = (int) $row['id'];
        $uniId = $row['user_id'];
        $sentAt = $row['invoice_sent_at'] ?: $row['created_at'];
        if (!$sentAt) {
            continue;
        }
        $sentDate = date('Y-m-d', strtotime($sentAt));
        $daysSince = (strtotime($today) - strtotime($sentDate)) / 86400;
        $reminderCount = (int) $row['reminder_count'];
        $dueDate = $row['due_date'];

        $sendReminder = null; // 2, 3, or 4
        if ($reminderCount === 0 && $daysSince >= 2) {
            $sendReminder = 2;
        } elseif ($reminderCount === 1 && $daysSince >= 4) {
            $sendReminder = 3;
        } elseif ($reminderCount === 2 && ($daysSince >= 7 || ($dueDate && $today >= $dueDate))) {
            $sendReminder = 4;
        }
        if ($sendReminder === null) {
            continue;
        }

        $invoiceData = [
            'month_year' => $row['month_year'],
            'total_fee'  => (float) $row['total_fee'],
            'currency'   => $row['currency_type'] ?? 'USD',
        ];

        $emailResult = sendInvoiceReminderEmail($conn, $uniId, $invoiceData, $sendReminder);
        if (!empty($emailResult['success'])) {
            $newCount = $sendReminder === 2 ? 1 : ($sendReminder === 3 ? 2 : 3);
            mysqli_query($conn, "UPDATE {$cfg['invoice_table']} SET reminder_count = $newCount, last_reminder_sent_at = NOW() WHERE id = $invId");
            $sent++;
            $results['reminders_sent']++;
            $results['details'][] = ['broker' => $brokerName, 'invoice_id' => $invId, 'reminder' => $sendReminder, 'status' => 'sent'];
        } else {
            $results['errors'][] = ['broker' => $brokerName, 'invoice_id' => $invId, 'reminder' => $sendReminder, 'error' => $emailResult['message'] ?? 'send failed'];
        }
    }
    mysqli_free_result($res);
    $results['by_broker'][$brokerName] = $sent;
}

echo json_encode([
    'success'        => count($results['errors']) === 0,
    'message'        => 'Reminder run complete (capital.com, binance, ig). ' . $results['reminders_sent'] . ' sent.',
    'run_mode'       => $isCli ? 'cli' : 'http',
    'reminders_sent' => $results['reminders_sent'],
    'rows_checked'   => $results['rows_checked'],
    'by_broker'      => $results['by_broker'],
    'errors'         => $results['errors'],
    'details'        => $results['details'],
]);
