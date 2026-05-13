<?php
/**
 * Migration 062: Add asset_id as AUTO_INCREMENT primary key to assets table.
 * Demotes ticker from PRIMARY KEY to a plain indexed column, allowing duplicate tickers across brokers.
 *
 * Run via CLI:
 *   cd c:/wamp64/www/sinegu-api
 *   php migrations/062_run_asset_id_primary_key_migration.php
 */

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo "ERROR: Database connection failed.\n";
    exit(1);
}

$sql = file_get_contents(__DIR__ . '/062_add_asset_id_primary_key.sql');

if (!mysqli_query($conn, $sql)) {
    echo "ERROR: Migration failed: " . mysqli_error($conn) . "\n";
    mysqli_close($conn);
    exit(1);
}

echo "OK: Migration 062 applied successfully.\n";
echo "     - asset_id column added as AUTO_INCREMENT PRIMARY KEY\n";
echo "     - ticker demoted to indexed column (duplicates now allowed)\n";

mysqli_close($conn);
