<?php
/**
 * Admin: lightweight stats summary for the admin Assistant (chatbot).
 *
 * Returns headline counts for THIS environment (whichever VPS/DB this API is
 * served from). The chatbot calls it once per environment (prod / staging) to
 * answer questions like "how many users does prod have vs staging".
 *
 * GET /admin/GET/stats_summary.php
 * Auth: if CHATBOT_ADMIN_TOKEN is set in .env, the caller must send a matching
 *       X-Admin-Token header. If it is not set, the endpoint is open (matches
 *       the other admin/GET endpoints).
 *
 * Every count is guarded individually: a missing table or query error yields
 * null for that metric instead of failing the whole response.
 */

ob_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Admin-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_end_clean();
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ob_end_clean();
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../../env.php';

// Optional shared-secret guard (enforced only when configured).
$expectedToken = env('CHATBOT_ADMIN_TOKEN');
if ($expectedToken !== null && $expectedToken !== '') {
    $given = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? ($_GET['token'] ?? '');
    if (!hash_equals((string) $expectedToken, (string) $given)) {
        ob_end_clean();
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
}

require_once __DIR__ . '/../../conn.php';

if (!$conn) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

/** Run a single-value count query; return int on success or null on any failure. */
function stat_count($conn, $sql) {
    $res = @mysqli_query($conn, $sql);
    if (!$res) {
        return null;
    }
    $row = mysqli_fetch_row($res);
    mysqli_free_result($res);
    return $row ? (int) $row[0] : null;
}

$stats = [
    'users' => [
        'total'     => stat_count($conn, 'SELECT COUNT(*) FROM user_credentials'),
        'active'    => stat_count($conn, "SELECT COUNT(*) FROM user_credentials WHERE status = 'active'"),
        'pending'   => stat_count($conn, "SELECT COUNT(*) FROM user_credentials WHERE status = 'pending'"),
        'suspended' => stat_count($conn, "SELECT COUNT(*) FROM user_credentials WHERE status = 'suspended'"),
    ],
    'accounts' => [
        'capital' => stat_count($conn, 'SELECT COUNT(*) FROM accounts'),
        'binance' => stat_count($conn, 'SELECT COUNT(*) FROM binance_accounts'),
        'ig'      => stat_count($conn, 'SELECT COUNT(*) FROM ig_accounts'),
    ],
    'open_positions' => [
        'capital' => stat_count($conn, 'SELECT COUNT(*) FROM positions'),
        'binance' => stat_count($conn, 'SELECT COUNT(*) FROM binance_positions'),
        'ig'      => stat_count($conn, 'SELECT COUNT(*) FROM ig_positions'),
    ],
    'signups_last_7d' => stat_count($conn, 'SELECT COUNT(*) FROM user_credentials WHERE created_at >= (NOW() - INTERVAL 7 DAY)'),
    'signups_today'   => stat_count($conn, 'SELECT COUNT(*) FROM user_credentials WHERE DATE(created_at) = CURDATE()'),
];

mysqli_close($conn);

ob_end_clean();
echo json_encode([
    'success'      => true,
    'generated_at' => date('c'),
    'stats'        => $stats,
]);
