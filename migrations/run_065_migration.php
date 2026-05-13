<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../conn.php';
if (!$conn) { echo json_encode(['success' => false, 'error' => 'DB connection failed']); exit; }
$sql = file_get_contents(__DIR__ . '/065_create_bank_wire_accounts_table.sql');
if (mysqli_query($conn, $sql)) {
    echo json_encode(['success' => true, 'message' => 'bank_wire_accounts table created']);
} else {
    $e = mysqli_error($conn);
    echo json_encode(['success' => strpos($e, 'already exists') !== false, 'message' => strpos($e, 'already exists') !== false ? 'Table already exists' : $e]);
}
mysqli_close($conn);
