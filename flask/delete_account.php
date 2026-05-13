<?php
/**
 * Delete an account by id (bot-only). Used by login_loop when Capital.com
 * credentials are invalid. Stores a notice so the dashboard can show a toast.
 * POST JSON: {"account_id": 1, "secret": "..."}
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
require_once __DIR__ . '/../conn.php';
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

// Ensure table exists (static DDL, no user input)
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `deleted_broker_notices` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uni_id` VARCHAR(36) NOT NULL,
  `account_name` VARCHAR(255) NOT NULL,
  `reason` VARCHAR(64) NOT NULL DEFAULT 'invalid_api',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_uni_id` (`uni_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Bind types match DB: accounts.id INT; deleted_broker_notices: uni_id VARCHAR(36), account_name VARCHAR(255), reason VARCHAR(64)
$row = null;
$sel = mysqli_prepare($conn, "SELECT uni_id, name FROM accounts WHERE id = ? LIMIT 1");
if ($sel) {
    mysqli_stmt_bind_param($sel, "i", $account_id);
    mysqli_stmt_execute($sel);
    $res = mysqli_stmt_get_result($sel);
    $row = mysqli_fetch_assoc($res);
    mysqli_stmt_close($sel);
}

if ($row) {
    $uni_id = (string) $row['uni_id'];
    $account_name = (string) $row['name'];
    $reason = 'invalid_api';
    $ins = mysqli_prepare($conn, "INSERT INTO deleted_broker_notices (uni_id, account_name, reason) VALUES (?, ?, ?)");
    if ($ins) {
        mysqli_stmt_bind_param($ins, "sss", $uni_id, $account_name, $reason);
        mysqli_stmt_execute($ins);
        mysqli_stmt_close($ins);
    }
}

$del = mysqli_prepare($conn, "DELETE FROM accounts WHERE id = ? LIMIT 1");
if (!$del) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to prepare delete']);
    mysqli_close($conn);
    exit;
}
mysqli_stmt_bind_param($del, "i", $account_id);
mysqli_stmt_execute($del);
$affected = mysqli_affected_rows($conn);
mysqli_stmt_close($del);

if ($affected > 0 && file_exists(__DIR__ . '/../includes/audit_log.php')) {
    require_once __DIR__ . '/../includes/audit_log.php';
    audit_log('account_deleted_bot', [
        'account_id' => $account_id,
        'uni_id' => $row ? $row['uni_id'] : null,
        'account_name' => $row ? $row['name'] : null
    ]);
}
if (file_exists(__DIR__ . '/../includes/api_log.php')) {
    require_once __DIR__ . '/../includes/api_log.php';
    api_log($conn, 'flask/delete_account', 'Bot delete account (invalid API)', 'delete', $affected > 0);
}

mysqli_close($conn);
echo json_encode(['success' => true, 'affected' => $affected]);
exit;
