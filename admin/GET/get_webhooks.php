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
 *  - the receivers (Capital :5000, Binance :5001, IG :5002, MEXC :5003, Bybit :5004 on staging), each with its
 *    OWN secret, so every card carries its own alert message;
 *  - the secret is returned IN CLEAR so the alert can be copied ready to paste (owner's
 *    call, 2026-10-20). That is why this endpoint sits behind the admin action password:
 *    a secret plus its URL is enough to trade every account on that feed.
 *
 * Secrets are the API's copies, resolved exactly as admin/POST/retry_trade_incident.php
 * does (env override, else the receivers' code defaults; Capital from bot_config), so
 * the page shows the same value the API itself signs with.
 *
 * Feeds: Binance and Bybit have three each (FEED_BY_USER_TYPE in includes/user_types.php):
 * main = /binance_webhook (everyone on no other feed), test = /binance_test_webhook
 * (tester-owned accounts only), quantlab = /binance_quantlab_webhook (quantlab_tester-
 * owned only, posted to by QuantLab's alerts) — and the /bybit_* twins on staging.
 * Every other receiver is main only.
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
require_once __DIR__ . '/../../includes/service_control.php'; // sc_expectation(): Bybit is staging-only

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
 * Enabled, connected accounts on one broker, split real-money / demo. `$feed` narrows
 * by the owner's account type for brokers with feeds (Binance, Bybit): 'main' = owners
 * whose type maps to no other feed, 'test' / 'quantlab' = owners whose type maps to it
 * (FEED_BY_USER_TYPE). null = everyone (a broker without feeds).
 */
function wh_receivers($conn, string $table, ?string $feed): ?array {
    $where = 'COALESCE(a.enabled, 1) = 1 AND a.deleted_at IS NULL';
    if ($feed !== null) {
        $feedTypes = $feed === MAIN_FEED ? array_keys(FEED_BY_USER_TYPE) : feed_user_types($feed);
        if (user_credentials_has_type($conn) && $feedTypes) {
            $list = "'" . implode("','", $feedTypes) . "'";
            $in = "a.uni_id IN (SELECT uc.uni_id FROM user_credentials uc WHERE uc.`type` IN ($list))";
            $where .= $feed === MAIN_FEED ? " AND NOT ($in)" : " AND $in";
        } elseif ($feed !== MAIN_FEED) {
            return ['live' => 0, 'demo' => 0]; // no type column = nobody is on this feed
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
foreach ([5000, 5001, 5002, 5003, 5004] as $port) {
    $probes[$port] = wh_probe($port);
}

$defs = [
    ['capital',      'capital', 'Capital.com', 'main', '/webhook',              5000, wh_capital_secret($conn),                                         'accounts',         null],
    ['binance',      'binance', 'Binance',     'main', '/binance_webhook',      5001, (string) env('BINANCE_WEBHOOK_SECRET', 'binance_bot'),           'binance_accounts', 'main'],
    ['mexc',         'mexc',    'MEXC',        'main', '/mexc_webhook',         5003, (string) env('MEXC_WEBHOOK_SECRET', 'mexc_bot'),                 'mexc_accounts',    null],
    ['ig',           'ig',      'IG',          'main', '/ig_webhook',           5002, (string) env('IG_WEBHOOK_SECRET', 'ig_bot'),                     'ig_accounts',      null],
    ['binance_test', 'binance', 'Binance',     'test', '/binance_test_webhook', 5001, (string) env('BINANCE_TEST_WEBHOOK_SECRET', 'binance_test_bot'), 'binance_accounts', 'test'],
    ['binance_quantlab', 'binance', 'Binance', 'quantlab', '/binance_quantlab_webhook', 5001, (string) env('BINANCE_QUANTLAB_WEBHOOK_SECRET', 'binance_quantlab_bot'), 'binance_accounts', 'quantlab'],
];
// Bybit (flask/bybit, :5004) only where it is deployed — sc_expectation() says ubuntu-staging.
// Its secrets are the bot's in-code defaults (hooks.py), overridable like the others.
if (sc_expectation('bybit')['expected']) {
    $defs[] = ['bybit',      'bybit',   'Bybit',       'main', '/bybit_webhook',        5004, (string) env('BYBIT_WEBHOOK_SECRET', 'bybit_bot'),           'bybit_accounts',   'main'];
    $defs[] = ['bybit_test', 'bybit',   'Bybit',       'test', '/bybit_test_webhook',   5004, (string) env('BYBIT_TEST_WEBHOOK_SECRET', 'bybit_test_bot'), 'bybit_accounts',   'test'];
    $defs[] = ['bybit_quantlab', 'bybit', 'Bybit',     'quantlab', '/bybit_quantlab_webhook', 5004, (string) env('BYBIT_QUANTLAB_WEBHOOK_SECRET', 'bybit_quantlab_bot'), 'bybit_accounts', 'quantlab'];
}

$webhooks = [];
foreach ($defs as [$key, $broker, $label, $feed, $path, $port, $secret, $table, $owner]) {
    [$reachable, $health] = $probes[$port];
    // A non-main feed's engine reports it as /health "<feed>_feed": {enabled, path}.
    // Kept under the old key name — the dashboard reads test_feed_enabled for any feed.
    $testEnabled = null;
    if ($feed !== MAIN_FEED && is_array($health) && isset($health[$feed . '_feed']['enabled'])) {
        $testEnabled = (bool) $health[$feed . '_feed']['enabled'];
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
