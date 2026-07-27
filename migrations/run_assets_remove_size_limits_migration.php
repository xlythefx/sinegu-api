<?php
/**
 * Migration: Remove all size limits on assets (base_size / max_increments).
 *
 * Drops the CHECK constraints added by 037 (if still present) and widens both
 * columns so large values like base_size = 8500 / max_increments = 25500 save.
 *
 * Usage: php run_assets_remove_size_limits_migration.php
 */
header('Content-Type: application/json');

if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_OFF);
}

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$done = [];

// 1) Drop the 9999 CHECK constraints from 037 if they exist
foreach (['chk_assets_base_size_max', 'chk_assets_max_increments_max'] as $name) {
    $escaped = mysqli_real_escape_string($conn, $name);
    $check = mysqli_query($conn, "SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assets' AND CONSTRAINT_NAME = '$escaped'");

    if ($check && mysqli_num_rows($check) > 0) {
        if (!mysqli_query($conn, "ALTER TABLE `assets` DROP CONSTRAINT `$name`")) {
            echo json_encode(['success' => false, 'error' => "Failed to drop $name: " . mysqli_error($conn)]);
            mysqli_close($conn);
            exit;
        }
        $done[] = "dropped constraint $name";
    } else {
        $done[] = "constraint $name not present";
    }
}

// 2) Widen the columns so large sizes are stored exactly
$alters = [
    'base_size'      => "ALTER TABLE `assets` MODIFY COLUMN `base_size` DECIMAL(20,6) NOT NULL DEFAULT 0.001000",
    'max_increments' => "ALTER TABLE `assets` MODIFY COLUMN `max_increments` DECIMAL(20,3) NOT NULL DEFAULT 10.000",
];

foreach ($alters as $column => $sql) {
    if (!mysqli_query($conn, $sql)) {
        echo json_encode(['success' => false, 'error' => "Failed to widen $column: " . mysqli_error($conn)]);
        mysqli_close($conn);
        exit;
    }
    $done[] = "widened $column";
}

echo json_encode([
    'success' => true,
    'message' => 'Asset size limits removed',
    'steps'   => $done,
]);

mysqli_close($conn);
