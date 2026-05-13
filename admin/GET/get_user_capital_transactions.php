<?php
/**
 * Admin: Get all capital transactions (deposits/withdrawals) for a user's Capital.com accounts.
 * GET /admin/GET/get_user_capital_transactions.php?uni_id=...
 * Returns same structure as users/get_capital_transactions.php for consistency with user view.
 */

ob_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ob_end_clean();
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$uni_id = isset($_GET['uni_id']) ? trim($_GET['uni_id']) : '';

if (empty($uni_id)) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'uni_id is required']);
    exit;
}

require_once __DIR__ . '/../../conn.php';

if (!$conn) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

// Get account IDs for this user (Capital.com accounts only)
$accSql = "SELECT id FROM accounts WHERE uni_id = ? AND (deleted_at IS NULL)";
$accountIds = [];
if ($stmt = mysqli_prepare($conn, $accSql)) {
    mysqli_stmt_bind_param($stmt, 's', $uni_id);
    mysqli_stmt_execute($stmt);
    $r = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($r)) {
        $accountIds[] = (int) $row['id'];
    }
    mysqli_stmt_close($stmt);
}

if (empty($accountIds)) {
    ob_end_clean();
    echo json_encode([
        'success' => true,
        'transactions' => [],
        'total' => 0,
        'count' => 0,
    ]);
    mysqli_close($conn);
    exit;
}

$placeholders = implode(',', array_fill(0, count($accountIds), '?'));
$types = str_repeat('i', count($accountIds));

$sql = "SELECT id, account_id, uni_id, type, amount, balance_after, broker_reference, currency, created_at
        FROM capital_transactions
        WHERE account_id IN ($placeholders)
        ORDER BY created_at DESC";

if (!($stmt = mysqli_prepare($conn, $sql))) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Query failed']);
    mysqli_close($conn);
    exit;
}

$refs = [];
foreach ($accountIds as $i => $v) {
    $refs[$i] = &$accountIds[$i];
}
call_user_func_array([$stmt, 'bind_param'], array_merge([$types], $refs));

if (!mysqli_stmt_execute($stmt)) {
    mysqli_stmt_close($stmt);
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Query failed']);
    mysqli_close($conn);
    exit;
}

$result = mysqli_stmt_get_result($stmt);
$transactions = [];
while ($row = mysqli_fetch_assoc($result)) {
    $transactions[] = [
        'id' => (int) $row['id'],
        'account_id' => (int) $row['account_id'],
        'uni_id' => $row['uni_id'],
        'type' => $row['type'],
        'amount' => (float) ($row['amount'] ?? 0),
        'balance_after' => isset($row['balance_after']) && $row['balance_after'] !== null ? (float) $row['balance_after'] : null,
        'broker_reference' => $row['broker_reference'] ?? null,
        'currency' => $row['currency'] ?? 'USD',
        'created_at' => $row['created_at'],
    ];
}
mysqli_stmt_close($stmt);
mysqli_close($conn);

ob_end_clean();
echo json_encode([
    'success' => true,
    'transactions' => $transactions,
    'total' => count($transactions),
    'count' => count($transactions),
]);
