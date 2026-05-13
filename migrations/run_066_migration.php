<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../conn.php';
if (!$conn) { echo json_encode(['success' => false, 'error' => 'DB connection failed']); exit; }
$sql = file_get_contents(__DIR__ . '/066_add_referrer_payouts_payment_method.sql');
if (mysqli_query($conn, $sql)) {
    echo json_encode(['success' => true, 'message' => 'payment_method and proof_file columns added to referrer_payouts']);
} else {
    $e = mysqli_error($conn);
    $alreadyExists = strpos($e, 'Duplicate column') !== false;
    echo json_encode(['success' => $alreadyExists, 'message' => $alreadyExists ? 'Columns already exist' : $e]);
}
mysqli_close($conn);
