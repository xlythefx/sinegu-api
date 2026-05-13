<?php
/**
 * Migration: Add reminder_count, last_reminder_sent_at, invoice_sent_at to billing_periods
 *
 * Usage: Run once (e.g. php run_billing_periods_reminder_migration.php or via browser)
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

$columns = [
    'reminder_count' => "ADD COLUMN reminder_count TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of reminder emails sent (0=initial only, 1=2nd, 2=3rd, 3=4th)' AFTER due_date",
    'last_reminder_sent_at' => "ADD COLUMN last_reminder_sent_at DATETIME NULL COMMENT 'When the last reminder (2nd/3rd/4th) was sent' AFTER reminder_count",
    'invoice_sent_at' => "ADD COLUMN invoice_sent_at DATETIME NULL COMMENT 'When the first invoice email was sent' AFTER last_reminder_sent_at",
];

foreach ($columns as $col => $addSql) {
    $check = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'billing_periods' AND COLUMN_NAME = '" . mysqli_real_escape_string($conn, $col) . "'";
    $res = mysqli_query($conn, $check);
    if ($res && mysqli_num_rows($res) > 0) {
        continue; // already exists
    }
    $alter = "ALTER TABLE billing_periods " . $addSql;
    if (!mysqli_query($conn, $alter)) {
        $errors[] = $col . ': ' . mysqli_error($conn);
        $success = false;
    }
}

echo json_encode([
    'success' => $success,
    'message' => $success ? 'Reminder columns added successfully' : 'Migration had errors',
    'errors' => $errors
]);
