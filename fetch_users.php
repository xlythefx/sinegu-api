<?php
header('Content-Type: application/json');
require_once 'conn.php';

$data = [];
$sql = "SELECT id, name, api_key, email, password, demo, enabled, broker_accountID, uni_id, cst, x_security_token, session_last_used_at,
        (SELECT COUNT(*) FROM billing_periods bp WHERE bp.account_id = accounts.id AND bp.status = 'overdue') AS has_overdue_billing FROM accounts";
$result = mysqli_query($conn, $sql);

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $row['demo'] = (int)$row['demo'];
        $row['enabled'] = (int)$row['enabled'];
        $row['balance'] = isset($row['balance']) ? (float)$row['balance'] : null;
        $row['has_overdue_billing'] = ((int)($row['has_overdue_billing'] ?? 0)) > 0 ? 1 : 0;
        $data[] = $row;
    }

    echo json_encode([
        'success' => true,
        'count' => count($data),
        'users' => $data
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => mysqli_error($conn)
    ]);
}

mysqli_close($conn);
?>
