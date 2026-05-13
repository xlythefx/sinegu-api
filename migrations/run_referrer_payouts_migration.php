<?php
/**
 * Migration Script: Create referrer_payouts table
 *
 * One row per referrer per month when admin has released payout; stores amount, address, tx_hash.
 *
 * Usage: php run_referrer_payouts_migration.php
 * Or via browser: http://localhost/sinegu-api/migrations/run_referrer_payouts_migration.php
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo json_encode([
        'success' => false,
        'error' => 'Database connection failed: ' . mysqli_connect_error()
    ]);
    exit;
}

$sqlFile = __DIR__ . '/017_create_referrer_payouts_table.sql';

if (!file_exists($sqlFile)) {
    echo json_encode([
        'success' => false,
        'error' => 'Migration file not found: ' . $sqlFile
    ]);
    exit;
}

$sql = file_get_contents($sqlFile);

if ($sql === false) {
    echo json_encode([
        'success' => false,
        'error' => 'Failed to read migration file'
    ]);
    exit;
}

$errors = [];

if (mysqli_multi_query($conn, $sql)) {
    do {
        if ($result = mysqli_store_result($conn)) {
            mysqli_free_result($result);
        }
        if (mysqli_error($conn)) {
            $errors[] = mysqli_error($conn);
        }
    } while (mysqli_more_results($conn) && mysqli_next_result($conn));
}

mysqli_close($conn);

if (!empty($errors)) {
    echo json_encode([
        'success' => false,
        'error' => 'Migration had errors',
        'details' => $errors
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'referrer_payouts table created successfully'
]);
