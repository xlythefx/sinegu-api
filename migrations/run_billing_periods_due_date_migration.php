<?php
/**
 * Migration: Add due_date to billing_periods and backfill
 *
 * - Adds column due_date DATE NULL to billing_periods
 * - Backfills: due_date = 1st of next month after month_year + 7 days
 *
 * Usage: Run once (e.g. php run_billing_periods_due_date_migration.php or via browser)
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

$errors = [];
$success = true;

// 1. Check if due_date column already exists
$checkColumn = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'billing_periods' AND COLUMN_NAME = 'due_date'";
$colResult = mysqli_query($conn, $checkColumn);
$columnExists = ($colResult && mysqli_num_rows($colResult) > 0);

if (!$columnExists) {
    $alterSql = "ALTER TABLE billing_periods ADD COLUMN due_date DATE NULL 
        COMMENT 'Payment due date (1st of next month after month_year + 7 days)' AFTER status";
    if (!mysqli_query($conn, $alterSql)) {
        $errors[] = 'Add column: ' . mysqli_error($conn);
        $success = false;
    }
}

// 2. Backfill due_date for rows where due_date IS NULL
if ($success) {
    $rows = mysqli_query($conn, "SELECT id, month_year FROM billing_periods WHERE due_date IS NULL AND month_year IS NOT NULL AND month_year != ''");
    if ($rows) {
        while ($row = mysqli_fetch_assoc($rows)) {
            $monthYear = $row['month_year'];
            $id = (int) $row['id'];
            // month_year = YYYY-MM -> 1st of next month + 7 days
            $d = DateTime::createFromFormat('Y-m', $monthYear);
            if ($d) {
                $d->modify('+1 month');
                $d->setDate((int) $d->format('Y'), (int) $d->format('m'), 1);
                $d->modify('+7 days');
                $dueDate = $d->format('Y-m-d');
                $escaped = mysqli_real_escape_string($conn, $dueDate);
                $update = "UPDATE billing_periods SET due_date = '$escaped' WHERE id = $id";
                if (!mysqli_query($conn, $update)) {
                    $errors[] = "Backfill id $id: " . mysqli_error($conn);
                    $success = false;
                }
            }
        }
    }
}

echo json_encode([
    'success' => $success,
    'message' => $success ? 'due_date column added and backfilled successfully' : 'Migration had errors',
    'errors' => $errors,
    'column_already_existed' => $columnExists
]);
