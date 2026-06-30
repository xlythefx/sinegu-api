<?php
/**
 * Migration 082: Create the asset_audit table (trading-asset change history).
 *
 * Run via CLI:
 *   cd c:/wamp64/www/sinegu-api
 *   php migrations/082_run_asset_audit_migration.php
 */

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo "ERROR: Database connection failed.\n";
    exit(1);
}

$sql = file_get_contents(__DIR__ . '/082_create_asset_audit.sql');

if (!mysqli_query($conn, $sql)) {
    echo "ERROR: Migration failed: " . mysqli_error($conn) . "\n";
    mysqli_close($conn);
    exit(1);
}

echo "OK: Migration 082 applied successfully.\n";
echo "     - asset_audit table created (asset create/update/delete history)\n";

mysqli_close($conn);
