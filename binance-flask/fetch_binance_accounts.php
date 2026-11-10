<?php
/**
 * Fetch all Binance accounts where enabled=1 for the bot.
 * Returns api_key, secret_key, name, demo, uni_id, … for each. Bot-only (internal use).
 * demo=1 → binance-flask uses https://demo-fapi.binance.com; demo=0 → https://fapi.binance.com.
 * GET: no params
 * Returns: {"success": true, "accounts": [{api_key, secret_key, name, ..., feed}]}
 *
 * `feed` decides which webhook an account trades, from the owner's
 * user_credentials.type via FEED_BY_USER_TYPE (includes/user_types.php): "test" for a
 * tester (only /binance_test_webhook), "quantlab" for a quantlab_tester (only
 * /binance_quantlab_webhook), otherwise "main" (only /binance_webhook). The API
 * decides; the engine only filters. The type lookup is a separate query, never a
 * JOIN, and any failure in it leaves every account on "main" — this list is the
 * trading path, and losing a feed tag must never cost the main feed its accounts.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use GET.']);
    exit;
}

require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/../includes/user_types.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$sql = "SELECT api_key, secret_key, name, uni_id, balance, initial_deposit, currency_type, demo, enabled 
        FROM binance_accounts 
        WHERE enabled = 1 AND (deleted_at IS NULL) 
        ORDER BY created_at ASC";

$result = mysqli_query($conn, $sql);

if (!$result) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to fetch: ' . mysqli_error($conn)]);
    exit;
}

$feedByUniId = account_feed_by_uni_id($conn);

$accounts = [];
while ($row = mysqli_fetch_assoc($result)) {
    $row['feed'] = $feedByUniId[(string) $row['uni_id']] ?? MAIN_FEED;
    $accounts[] = $row;
}

mysqli_free_result($result);
mysqli_close($conn);

echo json_encode(['success' => true, 'accounts' => $accounts]);
