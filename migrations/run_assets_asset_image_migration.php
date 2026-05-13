<?php
/**
 * Runner for 068_add_assets_asset_image.sql
 * Visit this URL in the browser once to apply the migration.
 */

header('Content-Type: text/plain');
require_once __DIR__ . '/../conn.php';

if (!$conn) {
    http_response_code(500);
    echo "DB connection failed\n";
    exit;
}

mysqli_report(MYSQLI_REPORT_OFF);

$check = mysqli_query($conn, "SHOW COLUMNS FROM `assets` LIKE 'asset_image'");
if ($check && mysqli_num_rows($check) > 0) {
    echo "OK: asset_image column already exists - skipping.\n";
} else {
    $alter = "ALTER TABLE `assets` ADD COLUMN `asset_image` VARCHAR(500) DEFAULT NULL COMMENT 'Path/URL to custom ticker logo' AFTER `broker`";
    if (mysqli_query($conn, $alter)) {
        echo "OK: added asset_image column.\n";
    } else {
        echo "ERR: " . mysqli_error($conn) . "\n";
    }
}

mysqli_close($conn);
echo "\nDone.\n";
