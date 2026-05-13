<?php
/**
 * Fetch IG.com accounts for ig-flask.
 * Source of truth is ig_accounts (not shared accounts table).
 */

ob_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if ($method === 'OPTIONS') {
    ob_end_clean();
    http_response_code(200);
    exit;
}
if ($method !== 'GET') {
    ob_end_clean();
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$data = [];
$sql = "SELECT id, name, api_key, email, password, demo, enabled, broker_accountID, uni_id,
        ig_cst, ig_x_security_token, ig_session_last_used_at,
        (SELECT COUNT(*) FROM ig_invoices ii WHERE ii.account_id = ig_accounts.id AND ii.status = 'overdue') AS has_overdue_billing
        FROM ig_accounts
        WHERE deleted_at IS NULL AND enabled = 1";
$result = mysqli_query($conn, $sql);

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $row['demo'] = (int)$row['demo'];
        $row['enabled'] = (int)$row['enabled'];
        $row['has_overdue_billing'] = ((int)($row['has_overdue_billing'] ?? 0)) > 0 ? 1 : 0;
        $data[] = $row;
    }

    ob_clean();
    echo json_encode([
        'success' => true,
        'count' => count($data),
        'users' => $data
    ]);
} else {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => mysqli_error($conn)
    ]);
}

mysqli_close($conn);
