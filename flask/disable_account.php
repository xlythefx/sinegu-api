<?php
/**
 * Disable (or re-enable) an account by id. Used by login_loop.py when Capital.com
 * credentials are invalid so the account is excluded from future logins and trades.
 * POST JSON: {"account_id": 1, "secret": "...", "enabled": 0}
 * Returns JSON: {"success": true} or 403 if secret invalid.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use POST.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['account_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing account_id']);
    exit;
}

$configPath = __DIR__ . '/config_bot_listener.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Config not found']);
    exit;
}
require_once $configPath;
$expectedSecret = isset($DISABLE_ACCOUNT_SECRET) ? $DISABLE_ACCOUNT_SECRET : '';
$secret = $input['secret'] ?? '';
if ($secret !== $expectedSecret) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$account_id = (int) $input['account_id'];
$enabled = isset($input['enabled']) ? (int) $input['enabled'] : 0;
if ($enabled !== 0 && $enabled !== 1) {
    $enabled = 0;
}

require_once __DIR__ . '/../conn.php';
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$account_id = mysqli_real_escape_string($conn, $account_id);
$enabled = mysqli_real_escape_string($conn, $enabled);
$sql = "UPDATE accounts SET enabled = $enabled, updated_at = NOW() WHERE id = $account_id LIMIT 1";
if (!mysqli_query($conn, $sql)) {
    if (file_exists(__DIR__ . '/../includes/api_log.php')) {
        require_once __DIR__ . '/../includes/api_log.php';
        api_log($conn, 'flask/disable_account', 'Disable account failed', 'update', false);
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

$affected = mysqli_affected_rows($conn);
if (file_exists(__DIR__ . '/../includes/api_log.php')) {
    require_once __DIR__ . '/../includes/api_log.php';
    api_log($conn, 'flask/disable_account', 'Disable/enable account', 'update', true);
}
mysqli_close($conn);
echo json_encode(['success' => true, 'affected' => $affected]);
exit;
