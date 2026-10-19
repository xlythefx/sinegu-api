<?php
/**
 * Admin: Recent user sign-ups (for User Management notifications).
 * Uses user_credentials.created_at + optional referral_tracking.referral_code.
 * GET /admin/GET/get_recent_registrations.php?limit=10&offset=0
 *
 * Note: api_logs also records users/register with description "User registration successful" (no PII);
 * this endpoint returns richer data for the admin UI.
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

require_once __DIR__ . '/../../conn.php';
require_once __DIR__ . '/../../includes/user_types.php';

if (!$conn) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$limit = isset($_GET['limit']) ? min(100, max(1, (int) $_GET['limit'])) : 10;
$offset = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;

// Optional status filter (pending | active | suspended)
$allowedStatuses = ['pending', 'active', 'suspended'];
$statusFilter = isset($_GET['status']) && in_array($_GET['status'], $allowedStatuses, true)
    ? $_GET['status']
    : null;
// Customers only — admin / developer / collaborator / tester accounts are internal
// (user_credentials.type, migration 095), so they never show as sign-ups.
$where = [];
if (user_credentials_has_type($conn)) {
    $where[] = "uc.`type` = 'user'";
}
if ($statusFilter !== null) {
    $where[] = "uc.status = '" . mysqli_real_escape_string($conn, $statusFilter) . "'";
}
$whereClause = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$countSql = 'SELECT COUNT(*) AS cnt FROM user_credentials uc' . $whereClause;
$count_res = mysqli_query($conn, $countSql);
$total = ($count_res && ($cr = mysqli_fetch_assoc($count_res))) ? (int) $cr['cnt'] : 0;
if ($count_res) {
    mysqli_free_result($count_res);
}

$hasRt = false;
$chk = mysqli_query($conn, "SHOW TABLES LIKE 'referral_tracking'");
if ($chk && mysqli_num_rows($chk) > 0) {
    $hasRt = true;
}
if ($chk) {
    mysqli_free_result($chk);
}

if ($hasRt) {
    $sql = "
        SELECT uc.uni_id, uc.name, uc.email, uc.status, uc.created_at,
            (SELECT rt.referral_code FROM referral_tracking rt
             WHERE rt.referred_user_uni_id = uc.uni_id
             ORDER BY rt.created_at DESC LIMIT 1) AS referral_code
        FROM user_credentials uc" . $whereClause . "
        ORDER BY uc.created_at DESC
        LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset;
} else {
    $sql = "
        SELECT uc.uni_id, uc.name, uc.email, uc.status, uc.created_at, NULL AS referral_code
        FROM user_credentials uc" . $whereClause . "
        ORDER BY uc.created_at DESC
        LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset;
}

$result = mysqli_query($conn, $sql);
if (!$result) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

$registrations = [];
while ($row = mysqli_fetch_assoc($result)) {
    $registrations[] = [
        'uni_id' => $row['uni_id'],
        'name' => $row['name'],
        'email' => $row['email'],
        'status' => $row['status'],
        'created_at' => $row['created_at'],
        'referral_code' => $row['referral_code'] !== null && $row['referral_code'] !== '' ? $row['referral_code'] : null,
    ];
}
mysqli_free_result($result);
mysqli_close($conn);

ob_end_clean();
echo json_encode([
    'success' => true,
    'registrations' => $registrations,
    'total' => $total,
    'limit' => $limit,
    'offset' => $offset,
]);
