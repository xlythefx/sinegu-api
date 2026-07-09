<?php
/**
 * Cron: Mark overdue invoices and disconnect the account (all brokers).
 * Schedule: daily (e.g. 09:15 America/New_York), fired by the Telegram billing cron listener.
 *
 * Logic per broker (capital.com / binance / ig):
 *   status = 'pending' AND total_fee > 0 AND due_date < today
 *     -> set invoice status = 'overdue'
 *     -> set the broker account's enabled = 0   (uniform primary disconnect gate)
 *     -> send one "you have been disconnected" email
 * Only rows still 'pending' are selected, so the email/disable happen exactly once.
 *
 * Reconnect is automatic on payment (stripe/webhook.php + auto-charge-invoices.php set enabled = 1).
 *
 * No day-of-month window: the cron listener owns the timing. Secret-guarded (BILLING_CRON_SECRET).
 *
 * CLI:  php cron/mark_overdue_and_disconnect.php
 * HTTP: GET/POST http://localhost/sinegu-api/cron/mark_overdue_and_disconnect.php (header X-Billing-Secret)
 */

$isCli = php_sapi_name() === 'cli';
date_default_timezone_set('America/New_York');

@file_put_contents(
    __DIR__ . '/../cron_overdue.log',
    date('Y-m-d H:i:s') . ' — mark_overdue_and_disconnect.php run_mode=' . ($isCli ? 'cli' : 'http') . "\n",
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

require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/../includes/send_invoice_email.php';

if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

/**
 * Per-broker descriptors. Each SELECT must return columns:
 *   id, user_id, month_year, total_fee, currency_type, disable_key
 * `disable_key_int` marks whether disable_key is an integer id or a string key.
 */
$brokers = [
    'capital.com' => [
        'invoice_table'   => 'billing_periods',
        'disable_key_int' => true,
        'select' => "SELECT bp.id, bp.user_id, bp.month_year, bp.total_fee,
                            a.currency_type, bp.account_id AS disable_key
                     FROM billing_periods bp
                     INNER JOIN accounts a ON bp.account_id = a.id
                     WHERE bp.status = 'pending' AND bp.total_fee > 0
                       AND bp.due_date IS NOT NULL AND bp.due_date < CURDATE()",
        'disable_sql' => "UPDATE accounts SET enabled = 0 WHERE id = %KEY%",
    ],
    'binance' => [
        'invoice_table'   => 'binance_invoices',
        'disable_key_int' => false,
        'select' => "SELECT bp.id, bp.user_id, bp.month_year, bp.total_fee,
                            a.currency_type, bp.api_key AS disable_key
                     FROM binance_invoices bp
                     INNER JOIN binance_accounts a ON bp.api_key = a.api_key
                     WHERE bp.status = 'pending' AND bp.total_fee > 0
                       AND bp.due_date IS NOT NULL AND bp.due_date < CURDATE()",
        'disable_sql' => "UPDATE binance_accounts SET enabled = 0 WHERE api_key = '%KEY%'",
    ],
    'ig' => [
        'invoice_table'   => 'ig_invoices',
        'disable_key_int' => true,
        'select' => "SELECT bp.id, bp.user_id, bp.month_year, bp.total_fee,
                            a.currency_type, bp.account_id AS disable_key
                     FROM ig_invoices bp
                     INNER JOIN ig_accounts a ON bp.api_key = a.api_key
                     WHERE bp.status = 'pending' AND bp.total_fee > 0
                       AND bp.due_date IS NOT NULL AND bp.due_date < CURDATE()",
        'disable_sql' => "UPDATE ig_accounts SET enabled = 0 WHERE id = %KEY%",
    ],
];

$results = [
    'marked_overdue'    => 0,
    'accounts_disabled' => 0,
    'emails_sent'       => 0,
    'by_broker'         => [],
    'errors'            => [],
];

foreach ($brokers as $brokerName => $cfg) {
    $bres = ['marked_overdue' => 0, 'accounts_disabled' => 0, 'emails_sent' => 0];

    $res = mysqli_query($conn, $cfg['select']);
    if (!$res) {
        $results['errors'][] = ['broker' => $brokerName, 'error' => mysqli_error($conn)];
        $results['by_broker'][$brokerName] = $bres;
        continue;
    }

    while ($row = mysqli_fetch_assoc($res)) {
        $invId = (int) $row['id'];
        $uniId = $row['user_id'];

        // 1) Mark invoice overdue
        $up = "UPDATE {$cfg['invoice_table']} SET status = 'overdue', updated_at = NOW() WHERE id = $invId";
        if (!mysqli_query($conn, $up)) {
            $results['errors'][] = ['broker' => $brokerName, 'invoice_id' => $invId, 'error' => mysqli_error($conn)];
            continue;
        }
        $bres['marked_overdue']++;
        $results['marked_overdue']++;

        // 2) Disconnect the broker account (enabled = 0)
        if ($cfg['disable_key_int']) {
            $key = (int) $row['disable_key'];
        } else {
            $key = mysqli_real_escape_string($conn, (string) $row['disable_key']);
        }
        $disableSql = str_replace('%KEY%', (string) $key, $cfg['disable_sql']);
        if (mysqli_query($conn, $disableSql)) {
            $affected = mysqli_affected_rows($conn);
            $bres['accounts_disabled'] += max(0, $affected);
            $results['accounts_disabled'] += max(0, $affected);
        } else {
            $results['errors'][] = ['broker' => $brokerName, 'invoice_id' => $invId, 'disable_error' => mysqli_error($conn)];
        }

        // 3) Send the disconnect email (once, since only 'pending' rows are selected)
        $invoiceData = [
            'month_year' => $row['month_year'],
            'total_fee'  => (float) ($row['total_fee'] ?? 0),
            'currency'   => $row['currency_type'] ?? 'USD',
        ];
        $emailResult = sendOverdueDisconnectEmail($conn, $uniId, $invoiceData);
        if (!empty($emailResult['success'])) {
            $bres['emails_sent']++;
            $results['emails_sent']++;
        } else {
            $results['errors'][] = ['broker' => $brokerName, 'invoice_id' => $invId, 'email_error' => $emailResult['message'] ?? 'send failed'];
        }
    }
    mysqli_free_result($res);
    $results['by_broker'][$brokerName] = $bres;
}

echo json_encode([
    'success'           => count($results['errors']) === 0,
    'message'           => 'Overdue + disconnect run complete (capital.com, binance, ig)',
    'run_mode'          => $isCli ? 'cli' : 'http',
    'marked_overdue'    => $results['marked_overdue'],
    'accounts_disabled' => $results['accounts_disabled'],
    'emails_sent'       => $results['emails_sent'],
    'by_broker'         => $results['by_broker'],
    'errors'            => $results['errors'],
]);
