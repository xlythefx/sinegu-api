<?php
/**
 * TEST SEED: insert sample capital_api_logs rows so the admin Reason / Operation
 * filters and the weekly rate-limit chart have data to show.
 *
 * Usage:
 *   php seed_capital_api_logs_test.php          # insert sample rows
 *   php seed_capital_api_logs_test.php cleanup  # delete previously seeded rows
 *
 * Every seeded row's message ends with " [seed-test]" so cleanup can target only
 * test data without touching real events. is_read is left at its column default (0).
 */

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    fwrite(STDERR, "Database connection failed\n");
    exit(1);
}

$mode = isset($argv[1]) ? strtolower(trim($argv[1])) : 'seed';

if ($mode === 'cleanup') {
    $ok = mysqli_query($conn, "DELETE FROM capital_api_logs WHERE message LIKE '%[seed-test]%'");
    if (!$ok) {
        fwrite(STDERR, 'Cleanup failed: ' . mysqli_error($conn) . "\n");
        exit(1);
    }
    echo 'Removed ' . mysqli_affected_rows($conn) . " seeded row(s).\n";
    mysqli_close($conn);
    exit(0);
}

// account_id 1 = master; it joins to accounts -> user_credentials for the display name/email.
// Spread across the last 8 days (multiple rate_limited per day) so the weekly chart fills in.
// Columns: account_id, account_name, ticker, action, event_type, http_status, source, message, days_ago, hour
$rows = [
    [1, 'master', 'BTCUSD',  'BUY',  'rate_limited',         429, 'fetch_balances',        'Balance refresh skipped: rate_limited',          0, 9],
    [1, 'master', 'ETHUSD',  'SELL', 'rate_limited',         429, 'fetch_active_positions', 'Positions sync skipped: rate_limited',           0, 14],
    [1, 'master', 'XAUUSD',  'BUY',  'rate_limited',         429, 'webhook_queue',          'Webhook delivery throttled: rate_limited',       1, 10],
    [1, 'master', 'BTCUSD',  'EXIT', 'rate_limited',         429, 'trading_handler',        'Order placement throttled: rate_limited',        1, 16],
    [1, 'master', 'NATGAS',  'BUY',  'rate_limited',         429, 'fetch_balances',         'Balance refresh skipped: rate_limited',          1, 20],
    [1, 'master', 'ETHUSD',  'SELL', 'rate_limited',         429, 'fetch_active_positions', 'Positions sync skipped: rate_limited',           2, 8],
    [1, 'master', 'GBPUSD',  'BUY',  'rate_limited',         429, 'webhook_queue',          'Webhook delivery throttled: rate_limited',       2, 13],
    [1, 'master', 'BTCUSD',  'BUY',  'rate_limited_dropped', 429, 'webhook_queue',          'Webhook dropped after retries: rate_limited_dropped', 2, 21],
    [1, 'master', 'XAUUSD',  'SELL', 'rate_limited',         429, 'fetch_active_positions', 'Positions sync skipped: rate_limited',           3, 11],
    [1, 'master', 'NATGAS',  'BUY',  'rate_limited',         429, 'fetch_balances',         'Balance refresh skipped: rate_limited',          3, 17],
    [1, 'master', 'ETHUSD',  'EXIT', 'rate_limited',         429, 'trading_handler',        'Order placement throttled: rate_limited',        4, 9],
    [1, 'master', 'BTCUSD',  'BUY',  'rate_limited',         429, 'fetch_active_positions', 'Positions sync skipped: rate_limited',           4, 15],
    [1, 'master', 'GBPUSD',  'SELL', 'rate_limited',         429, 'webhook_queue',          'Webhook delivery throttled: rate_limited',       5, 12],
    [1, 'master', 'XAUUSD',  'BUY',  'rate_limited_dropped', 429, 'trading_handler',        'Order dropped after retries: rate_limited_dropped', 5, 19],
    [1, 'master', 'BTCUSD',  'BUY',  'rate_limited',         429, 'fetch_balances',         'Balance refresh skipped: rate_limited',          6, 10],
    [1, 'master', 'ETHUSD',  'SELL', 'rate_limited',         429, 'fetch_active_positions', 'Positions sync skipped: rate_limited',           7, 14],
    // A couple of credential-event rows so the Reason filter has non-rate-limit options too.
    [1, 'master', null,      null,   'invalid_api_key',      401, 'fetch_balances',         'Invalid API key while refreshing balances',      2, 7],
    [1, 'master', null,      null,   'login_failed',         401, 'trading_handler',        'Login failed: session rejected by Capital.com',  4, 22],
];

$sql = 'INSERT INTO capital_api_logs
        (account_id, account_name, ticker, action, event_type, http_status, source, message, email_sent, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?)';
$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    fwrite(STDERR, 'Prepare failed: ' . mysqli_error($conn) . "\n");
    exit(1);
}

$inserted = 0;
foreach ($rows as $r) {
    [$accId, $accName, $ticker, $action, $eventType, $httpStatus, $source, $message, $daysAgo, $hour] = $r;
    $message .= ' [seed-test]';
    $createdAt = date('Y-m-d H:i:s', time() - ($daysAgo * 86400) - ((23 - $hour) * 3600));
    mysqli_stmt_bind_param(
        $stmt,
        'issssisss',
        $accId,
        $accName,
        $ticker,
        $action,
        $eventType,
        $httpStatus,
        $source,
        $message,
        $createdAt
    );
    if (mysqli_stmt_execute($stmt)) {
        $inserted++;
    } else {
        fwrite(STDERR, 'Insert failed: ' . mysqli_error($conn) . "\n");
    }
}
mysqli_stmt_close($stmt);

echo "Inserted {$inserted} seeded capital_api_logs row(s).\n";
echo "Distinct reasons: rate_limited, rate_limited_dropped, invalid_api_key, login_failed\n";
echo "Distinct operations: fetch_balances, fetch_active_positions, webhook_queue, trading_handler\n";
echo "Run with 'cleanup' to remove them.\n";

mysqli_close($conn);
