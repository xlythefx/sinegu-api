<?php
/**
 * Migration runner: 071_create_referrer_payout_items_table.sql + 072_referrer_payouts_partial_releases.sql
 * Usage: php run_referrer_payout_items_migration.php
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'DB connection failed: ' . mysqli_connect_error()]);
    exit;
}

$files = [
    __DIR__ . '/071_create_referrer_payout_items_table.sql',
    __DIR__ . '/072_referrer_payouts_partial_releases.sql',
];

$results = [];
$hadError = false;

foreach ($files as $sqlFile) {
    if (!file_exists($sqlFile)) {
        $results[] = ['file' => basename($sqlFile), 'success' => false, 'error' => 'file not found'];
        $hadError = true;
        continue;
    }
    $sql = file_get_contents($sqlFile);
    $errors = [];
    if (mysqli_multi_query($conn, $sql)) {
        do {
            if ($r = mysqli_store_result($conn)) mysqli_free_result($r);
            if (mysqli_error($conn)) $errors[] = mysqli_error($conn);
        } while (mysqli_more_results($conn) && mysqli_next_result($conn));
    } else {
        $errors[] = mysqli_error($conn);
    }
    if ($errors) {
        $results[] = ['file' => basename($sqlFile), 'success' => false, 'errors' => $errors];
        $hadError = true;
    } else {
        $results[] = ['file' => basename($sqlFile), 'success' => true];
    }
}

mysqli_close($conn);

echo json_encode(['success' => !$hadError, 'results' => $results], JSON_PRETTY_PRINT);
