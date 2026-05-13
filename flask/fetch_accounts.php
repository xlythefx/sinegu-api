<?php
/**
 * Fetch all accounts for the bot (refresh / load accounts).
 * Same data as fetch_users.php but under flask so the bot uses one API base.
 * Returns JSON: {"success": true, "count": N, "users": [{id, name, api_key, ...}, ...]}
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
    echo json_encode([
        'success' => false,
        'error' => 'Database connection failed'
    ]);
    exit;
}

$data = [];
$total_active_accounts = 0;
$count_sql = "SELECT COUNT(*) AS total FROM accounts WHERE deleted_at IS NULL";
$count_result = mysqli_query($conn, $count_sql);
if ($count_result) {
    $count_row = mysqli_fetch_assoc($count_result);
    $total_active_accounts = isset($count_row['total']) ? (int)$count_row['total'] : 0;
}

$sql = "SELECT id, name, api_key, email, password, demo, enabled, broker_accountID, uni_id, cst, x_security_token, session_last_used_at, initial_deposit,
        (SELECT COUNT(*) FROM billing_periods bp WHERE bp.account_id = accounts.id AND bp.status = 'overdue') AS has_overdue_billing
        FROM accounts
        WHERE deleted_at IS NULL
          AND COALESCE(initial_deposit, 0) >= 1000";
$result = mysqli_query($conn, $sql);

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $row['demo'] = (int)$row['demo'];
        $row['enabled'] = (int)$row['enabled'];
        $row['balance'] = isset($row['balance']) ? (float)$row['balance'] : null;
        $row['initial_deposit'] = isset($row['initial_deposit']) ? (float)$row['initial_deposit'] : null;
        $row['has_overdue_billing'] = ((int)($row['has_overdue_billing'] ?? 0)) > 0 ? 1 : 0;
        $data[] = $row;
    }

    if (file_exists(__DIR__ . '/../includes/api_log.php')) {
        require_once __DIR__ . '/../includes/api_log.php';
        api_log($conn, 'flask/fetch_accounts', 'Fetch all accounts for bot', 'fetch', true);
    }
    ob_clean();
    echo json_encode([
        'success' => true,
        'count' => count($data),
        'total_active_accounts' => $total_active_accounts,
        'skipped_below_initial_deposit_count' => max(0, $total_active_accounts - count($data)),
        'users' => $data
    ]);
} else {
    if (file_exists(__DIR__ . '/../includes/api_log.php')) {
        require_once __DIR__ . '/../includes/api_log.php';
        api_log($conn, 'flask/fetch_accounts', 'Fetch accounts failed', 'fetch', false);
    }
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => mysqli_error($conn)
    ]);
}

mysqli_close($conn);
