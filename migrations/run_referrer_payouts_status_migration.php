<?php
/**
 * Migration Script: Add status column to referrer_payouts table
 * Usage: php run_referrer_payouts_status_migration.php
 * Or: http://localhost/sinegu-api/migrations/run_referrer_payouts_status_migration.php
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$sql = file_get_contents(__DIR__ . '/064_add_referrer_payouts_status.sql');

if ($sql === false) {
    echo json_encode(['success' => false, 'error' => 'Migration file not found']);
    exit;
}

if (mysqli_query($conn, $sql)) {
    echo json_encode(['success' => true, 'message' => 'status column added to referrer_payouts']);
} else {
    $err = mysqli_error($conn);
    // Column already exists is not a fatal error
    if (strpos($err, 'Duplicate column') !== false) {
        echo json_encode(['success' => true, 'message' => 'Column already exists']);
    } else {
        echo json_encode(['success' => false, 'error' => $err]);
    }
}

mysqli_close($conn);
