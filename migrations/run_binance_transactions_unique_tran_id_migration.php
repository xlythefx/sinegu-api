<?php
/**
 * Migration: Add unique (api_key, tran_id) to binance_transactions.
 * Usage: php run_binance_transactions_unique_tran_id_migration.php
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$idxName = 'uq_binance_transactions_api_key_tran_id';
$check = "SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS 
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'binance_transactions' AND INDEX_NAME = '" . mysqli_real_escape_string($conn, $idxName) . "'";
$res = mysqli_query($conn, $check);
if ($res && mysqli_num_rows($res) > 0) {
    echo json_encode(['success' => true, 'message' => 'Unique key uq_binance_transactions_api_key_tran_id already exists']);
    mysqli_close($conn);
    exit;
}

$sql = "ALTER TABLE binance_transactions ADD UNIQUE KEY uq_binance_transactions_api_key_tran_id (api_key, tran_id)";
if (!mysqli_query($conn, $sql)) {
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

echo json_encode(['success' => true, 'message' => 'Unique key added']);
mysqli_close($conn);
