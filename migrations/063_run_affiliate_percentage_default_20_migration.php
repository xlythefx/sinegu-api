<?php
/**
 * Migration 063:
 * - Set user_credentials.affiliate_percentage default to 20.00
 * - Backfill existing rows with 4.00 -> 20.00
 *
 * Run via CLI:
 *   cd C:/wamp64/www/sinegu-api
 *   php migrations/063_run_affiliate_percentage_default_20_migration.php
 */

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo "ERROR: Database connection failed.\n";
    exit(1);
}

$sql = file_get_contents(__DIR__ . '/063_set_affiliate_percentage_default_20.sql');
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

echo "OK: Migration 063 applied successfully.\n";
echo "     - affiliate_percentage default is now 20.00\n";
echo "     - existing 4.00 rows were backfilled to 20.00\n";

mysqli_close($conn);

