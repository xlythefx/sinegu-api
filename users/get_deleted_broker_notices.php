<?php
/**
 * Get and clear deleted broker notices for the current user (for toast on brokers page).
 * GET ?uni_id=xxx
 * Returns: {"success": true, "notices": [{"accountName": "...", "reason": "invalid_api", "message": "..."}]}
 * Notices are deleted after this fetch (one-time show).
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../conn.php';
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$uni_id = isset($_GET['uni_id']) ? trim($_GET['uni_id']) : '';
if ($uni_id === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'uni_id required']);
    exit;
}

$uni_id = mysqli_real_escape_string($conn, $uni_id);

// Table may not exist yet
$tbl = mysqli_query($conn, "SHOW TABLES LIKE 'deleted_broker_notices'");
if (!$tbl || mysqli_num_rows($tbl) === 0) {
    echo json_encode(['success' => true, 'notices' => []]);
    mysqli_close($conn);
    exit;
}

$res = mysqli_query($conn, "SELECT account_name, reason FROM deleted_broker_notices WHERE uni_id = '$uni_id' ORDER BY created_at ASC");
$notices = [];
$toastMessage = 'API might be invalid or password may be wrong. Account was removed.';
while ($r = mysqli_fetch_assoc($res)) {
    $notices[] = [
        'accountName' => $r['account_name'],
        'reason' => $r['reason'] ?? 'invalid_api',
        'message' => $toastMessage,
    ];
}
mysqli_free_result($res);
mysqli_query($conn, "DELETE FROM deleted_broker_notices WHERE uni_id = '$uni_id'");
mysqli_close($conn);
echo json_encode(['success' => true, 'notices' => $notices]);
exit;
