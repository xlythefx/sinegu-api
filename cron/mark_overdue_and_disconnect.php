<?php
/**
 * Cron: Mark overdue billing periods and send disconnection email.
 * Schedule: Run daily (e.g. 09:15 USA Eastern) on 1st–8th of each month only.
 * Logic: status = 'pending' AND due_date < today → set status = 'overdue', send overdue/disconnect email.
 *
 * CLI: php cron/mark_overdue_and_disconnect.php (e.g. from Windows Task Scheduler).
 * HTTP: GET or POST http://localhost/sinegu-api/cron/mark_overdue_and_disconnect.php for testing.
 */

$isCli = php_sapi_name() === 'cli';
date_default_timezone_set('America/New_York');
// Log that script was executed (for Task Scheduler / cron debugging)
file_put_contents(
    __DIR__ . '/../cron_overdue.log',
    date('Y-m-d H:i:s') . ' — mark_overdue_and_disconnect.php run_mode=' . ($isCli ? 'cli' : 'http') . "\n",
    FILE_APPEND
);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$dayOfMonth = (int) date('j');
if ($dayOfMonth < 1 || $dayOfMonth > 8) {
    echo json_encode([
        'success' => true,
        'message' => 'Overdue run skipped: only runs on 1st–8th of month. Today is day ' . $dayOfMonth . '.',
        'run_mode' => $isCli ? 'cli' : 'http',
        'marked_overdue' => 0,
        'emails_sent' => 0,
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

$results = ['marked_overdue' => 0, 'emails_sent' => 0, 'errors' => []];

$sql = "SELECT bp.id, bp.user_id, bp.month_year, bp.total_fee, a.currency_type
        FROM billing_periods bp
        INNER JOIN accounts a ON bp.account_id = a.id
        WHERE bp.status = 'pending' AND bp.total_fee > 0 AND bp.due_date IS NOT NULL AND bp.due_date < CURDATE()";
$res = mysqli_query($conn, $sql);
if (!$res) {
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    exit;
}

while ($row = mysqli_fetch_assoc($res)) {
    $bpId = (int) $row['id'];
    $uniId = $row['user_id'];
    $invoiceData = [
        'month_year' => $row['month_year'],
        'total_fee' => (float) $row['total_fee'],
        'currency' => $row['currency_type'] ?? 'USD',
    ];

    $up = "UPDATE billing_periods SET status = 'overdue', updated_at = NOW() WHERE id = $bpId";
    if (!mysqli_query($conn, $up)) {
        $results['errors'][] = ['billing_period_id' => $bpId, 'error' => mysqli_error($conn)];
        continue;
    }
    $results['marked_overdue']++;

    $emailResult = sendOverdueDisconnectEmail($conn, $uniId, $invoiceData);
    if ($emailResult['success']) {
        $results['emails_sent']++;
    } else {
        $results['errors'][] = ['billing_period_id' => $bpId, 'email_error' => $emailResult['message']];
    }
}

echo json_encode([
    'success' => true,
    'message' => 'Overdue run complete',
    'run_mode' => $isCli ? 'cli' : 'http',
    'marked_overdue' => $results['marked_overdue'],
    'emails_sent' => $results['emails_sent'],
    'errors' => $results['errors'],
]);
