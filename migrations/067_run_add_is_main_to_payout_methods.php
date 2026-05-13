<?php
/**
 * Migration 067:
 * - Add `is_main` boolean column to crypto_wallets and bank_wire_accounts
 * - Backfill: users with only a crypto wallet get is_main=1 on that wallet
 *
 * Run via CLI:
 *   cd C:/wamp64/www/sinegu-api
 *   php migrations/067_run_add_is_main_to_payout_methods.php
 */

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo "ERROR: Database connection failed.\n";
    exit(1);
}

$sql = file_get_contents(__DIR__ . '/067_add_is_main_to_payout_methods.sql');
if ($sql === false) {
    echo "ERROR: Could not read SQL file.\n";
    mysqli_close($conn);
    exit(1);
}

if (!mysqli_multi_query($conn, $sql)) {
    echo "ERROR: Migration failed: " . mysqli_error($conn) . "\n";
    mysqli_close($conn);
    exit(1);
}

do {
    if ($result = mysqli_store_result($conn)) {
        mysqli_free_result($result);
    }
} while (mysqli_more_results($conn) && mysqli_next_result($conn));

if (mysqli_errno($conn)) {
    echo "ERROR: Migration failed while processing statements: " . mysqli_error($conn) . "\n";
    mysqli_close($conn);
    exit(1);
}

echo "OK: Migration 067 applied successfully.\n";
echo "    - crypto_wallets.is_main added\n";
echo "    - bank_wire_accounts.is_main added\n";
echo "    - users with only a crypto wallet were backfilled to is_main=1\n";

mysqli_close($conn);
