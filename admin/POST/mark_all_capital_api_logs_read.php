<?php
/**
 * Admin: Mark all (currently-unread) capital_api_logs rows as read.
 * Optional body: { "q": "search text" } — restrict to entries matching the same
 * search filter used by get_capital_api_logs.php. Without q, marks ALL unread.
 */

ob_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$input = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
if (!is_array($input)) {
    $input = [];
}
$searchQ = isset($input['q']) && is_string($input['q']) && trim($input['q']) !== '' ? trim($input['q']) : null;

require_once __DIR__ . '/../../conn.php';

if (!$conn) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

if ($searchQ !== null) {
    $esc = mysqli_real_escape_string($conn, $searchQ);
    $sql = "UPDATE capital_api_logs l
        LEFT JOIN accounts a ON l.account_id = a.id
        LEFT JOIN user_credentials uc ON a.uni_id = uc.uni_id
        SET l.is_read = 1
        WHERE l.is_read = 0 AND (
            l.event_type LIKE '%$esc%'
            OR l.ticker LIKE '%$esc%'
            OR l.message LIKE '%$esc%'
            OR l.source LIKE '%$esc%'
            OR l.account_name LIKE '%$esc%'
            OR l.action LIKE '%$esc%'
            OR (l.http_status IS NOT NULL AND CAST(l.http_status AS CHAR) LIKE '%$esc%')
            OR (l.account_id IS NOT NULL AND CAST(l.account_id AS CHAR) LIKE '%$esc%')
            OR uc.name LIKE '%$esc%'
            OR uc.email LIKE '%$esc%'
        )";
} else {
    $sql = "UPDATE capital_api_logs SET is_read = 1 WHERE is_read = 0";
}

$ok = mysqli_query($conn, $sql);
if (!$ok) {
    $err = mysqli_error($conn);
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $err]);
    mysqli_close($conn);
    exit;
}

$affected = mysqli_affected_rows($conn);
ob_end_clean();
echo json_encode(['success' => true, 'updated' => (int) $affected]);
mysqli_close($conn);
