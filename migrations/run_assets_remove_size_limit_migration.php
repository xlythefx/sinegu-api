<?php
/**
 * Migration: Remove 9999 limit on assets; widen base_size so values like 50000 work.
 * Drops CHECK constraints from 037 (if present) and changes base_size to DECIMAL(20,6).
 * Usage: php run_assets_remove_size_limit_migration.php
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

// Drop CHECK constraints from migration 037 if they exist (MySQL has no DROP CONSTRAINT IF EXISTS)
foreach (['chk_assets_base_size_max', 'chk_assets_max_increments_max'] as $name) {
    $check = mysqli_query($conn, "SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS 
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assets' AND CONSTRAINT_NAME = '" . mysqli_real_escape_string($conn, $name) . "'");
    if ($check && mysqli_num_rows($check) > 0) {
        mysqli_query($conn, "ALTER TABLE `assets` DROP CONSTRAINT `$name`");
    }
}

// Widen base_size so values > 9999 (e.g. 50000) are stored correctly
$sql = "ALTER TABLE `assets` MODIFY COLUMN `base_size` DECIMAL(20,6) NOT NULL DEFAULT 0.001000";
if (!mysqli_query($conn, $sql)) {
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

echo json_encode(['success' => true, 'message' => 'Size limit removed; base_size widened to DECIMAL(20,6)']);
mysqli_close($conn);
