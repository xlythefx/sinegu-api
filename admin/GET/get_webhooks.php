<?php
/**
 * Admin → Webhooks: every address the receivers take signals on, for THIS box.
 *
 * GET /admin/GET/get_webhooks.php        header X-Admin-Password (admin action password)
 *
 * -> { success, environment, environment_label, webhooks[] }
 *    webhooks[]: { key, broker, label, feed, path, url, secret, port,
 *                  engine: { reachable, test_feed_enabled }, receivers: { live, demo } }
 *
 * Ported from Pixel Alpha's Admin → Webhooks, with two Sinegu differences:
 *  - four receivers (Capital :5000, Binance :5001, IG :5002, MEXC :5003), each with its
 *    OWN secret, so every card carries its own alert message;
 *  - the secret is returned IN CLEAR so the alert can be copied ready to paste (owner's
 *    call, 2026-10-20). That is why this endpoint sits behind the admin action password:
 *    a secret plus its URL is enough to trade every account on that feed.
 *
 * Secrets are the API's copies, resolved exactly as admin/POST/retry_trade_incident.php
 * does (env override, else the receivers' code defaults; Capital from bot_config), so
 * the page shows the same value the API itself signs with.
 *
 * Feeds: Binance has two (migration 095 + binance-flask "Tester type + test feed"):
 * main = /binance_webhook (everyone but testers), test = /binance_test_webhook
 * (tester-owned accounts only). Every other receiver is main only.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Admin-Password');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../../includes/admin_action_auth.php'; // also loads env.php
requireAdminActionPassword(null);

require_once __DIR__ . '/../../conn.php';
require_once __DIR__ . '/../../includes/user_types.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

// A missing table or column must cost one figure, not the page (mysqli throws by
// default since PHP 8.1, and `@` does not suppress exceptions).
mysqli_report(MYSQLI_REPORT_OFF);

/**
 * Which box this is, and the public address its receivers sit behind. Decided from the
 * request's own host rather than env.php: that file is preserve-listed per box, and its
 * prod mapping still names api.sinegualfamily.com, which now answers 410 to everything.
 */
function wh_environment(): array {
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $ip   = (string) ($_SERVER['SERVER_ADDR'] ?? '');
    if (strpos($host, 'nexastaging') !== false || strpos($ip, '191.218.162.182') === 0) {
        return ['staging', 'Staging', 'https://api.nexastaging.com'];
    }
    if (strpos($host, 'nexafamily') !== false || strpos($ip, '191.218.162.171') === 0) {
        return ['prod', 'Production', 'https://api.nexafamily.com'];
    }
    // Local WAMP: the receivers are not behind nginx, so each URL names its own port.
    return ['local', 'Local', null];
}

/** GET 127.0.0.1:<port>/health. Any HTTP answer = reachable; the JSON body when there is one. */
function wh_probe(int $port): array {
    if (!function_exists('curl_init')) {
        return [false, null];
    }
    $ch = curl_init("http://127.0.0.1:{$port}/health");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 1,
        CURLOPT_TIMEOUT        => 2,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = is_string($body) ? json_decode($body, true) : null;
    return [$code > 0, is_array($json) ? $json : null];
}

/**
 * Enabled, connected accounts on one broker, split real-money / demo. `$owner` narrows
 * by account type for the Binance feeds: 'tester', 'not_tester', or null (everyone).
 */
function wh_receivers($conn, string $table, ?string $owner): ?array {
    $where = 'COALESCE(a.enabled, 1) = 1 AND a.deleted_at IS NULL';
    if ($owner !== null) {
        if (user_credentials_has_type($conn)) {
            $in = "a.uni_id IN (SELECT uc.uni_id FROM user_credentials uc WHERE uc.`type` = 'tester')";
            $where .= $owner === 'tester' ? " AND $in" : " AND NOT ($in)";
        } elseif ($owner === 'tester') {
            return ['live' => 0, 'demo' => 0]; // no type column = nobody is a tester
        }
    }
    $res = @mysqli_query(
        $conn,
        "SELECT COALESCE(SUM(CASE WHEN COALESCE(a.demo, 0) = 1 THEN 0 ELSE 1 END), 0) AS live,
                COALESCE(SUM(CASE WHEN COALESCE(a.demo, 0) = 1 THEN 1 ELSE 0 END), 0) AS demo
           FROM `$table` a WHERE $where"
    );
    if (!$res) {
        return null;
    }
    $row = mysqli_fetch_assoc($res);
    mysqli_free_result($res);
    return ['live' => (int) $row['live'], 'demo' => (int) $row['demo']];
}

/** Capital's secret, resolved like retry_trade_incident.php: env, else bot_config. */
function wh_capital_secret($conn): string {
    $secret = (string) env('CAPITAL_WEBHOOK_SECRET', '');
    if ($secret !== '') {
        return $secret;
    }
    $r = @mysqli_query($conn, 'SELECT webhook_secret FROM bot_config WHERE id = 5163321 LIMIT 1');
    $row = $r ? mysqli_fetch_assoc($r) : null;
    if (!$row) {
        $r = @mysqli_query($conn, "SELECT webhook_secret FROM bot_config WHERE webhook_secret IS NOT NULL AND webhook_secret <> '' ORDER BY id LIMIT 1");
        $row = $r ? mysqli_fetch_assoc($r) : null;
    }
    return $row ? trim((string) $row['webhook_secret']) : '';
}

[$envKey, $envLabel, $base] = wh_environment();

// One probe per receiver, shared by Binance's two feeds.
$probes = [];
foreach ([5000, 5001, 5002, 5003] as $port) {
    $probes[$port] = wh_probe($port);
}

$defs = [
    ['capital',      'capital', 'Capital.com', 'main', '/webhook',              5000, wh_capital_secret($conn),                                         'accounts',         null],
    ['binance',      'binance', 'Binance',     'main', '/binance_webhook',      5001, (string) env('BINANCE_WEBHOOK_SECRET', 'binance_bot'),           'binance_accounts', 'not_tester'],
    ['mexc',         'mexc',    'MEXC',        'main', '/mexc_webhook',         5003, (string) env('MEXC_WEBHOOK_SECRET', 'mexc_bot'),                 'mexc_accounts',    null],
    ['ig',           'ig',      'IG',          'main', '/ig_webhook',           5002, (string) env('IG_WEBHOOK_SECRET', 'ig_bot'),                     'ig_accounts',      null],
    ['binance_test', 'binance', 'Binance',     'test', '/binance_test_webhook', 5001, (string) env('BINANCE_TEST_WEBHOOK_SECRET', 'binance_test_bot'), 'binance_accounts', 'tester'],
];

$webhooks = [];
foreach ($defs as [$key, $broker, $label, $feed, $path, $port, $secret, $table, $owner]) {
    [$reachable, $health] = $probes[$port];
    $testEnabled = null;
    if ($feed === 'test' && is_array($health) && isset($health['test_feed']['enabled'])) {
        $testEnabled = (bool) $health['test_feed']['enabled'];
    }
    $webhooks[] = [
        'key'       => $key,
        'broker'    => $broker,
        'label'     => $label,
        'feed'      => $feed,
        'path'      => $path,
        'url'       => ($base ?? "http://127.0.0.1:{$port}") . $path,
        'secret'    => $secret,
        'port'      => $port,
        'engine'    => ['reachable' => $reachable, 'test_feed_enabled' => $testEnabled],
        'receivers' => wh_receivers($conn, $table, $owner),
    ];
}

mysqli_close($conn);

echo json_encode([
    'success'           => true,
    'environment'       => $envKey,
    'environment_label' => $envLabel,
    'webhooks'          => $webhooks,
    'generated_at'      => date('c'),
], JSON_UNESCAPED_SLASHES);
