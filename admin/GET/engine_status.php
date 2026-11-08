<?php
/**
 * Admin: the whole engine fleet in one call.
 *
 * Powers /admin/engines in the dashboard. Three sources are merged here because
 * none of them is reachable from a browser:
 *
 *   1. systemd (or NSSM) unit states — a shell call only this box can make.
 *   2. Each Flask engine's own /health and /admin/trading_active — the engines
 *      bind 0.0.0.0 but ufw keeps them private, so the probe has to originate
 *      on the server. /admin/trading_active carries the live webhook QUEUE
 *      DEPTH, which is the single best "is it actually working" metric we have.
 *   3. The database — account/position counts, error-log freshness, retry queue,
 *      and trade_logs recency.
 *
 * There are no heartbeats anywhere in the fleet: not one worker loop records a
 * last-tick. So "is the balances poller alive?" is answered indirectly, by the
 * unit being active and by data freshness. That is a known limitation, not an
 * oversight — see `freshness` in the response.
 *
 * GET /admin/GET/engine_status.php
 *
 * Auth: none by default, matching every other admin/GET endpoint (see
 * server_env.php's note — admin sessions are client-side only). A caller MAY
 * send X-Admin-Token, and it is validated if present so the chatbot's
 * cross-environment calls still fail closed on a misconfigured box; a caller
 * that sends nothing is allowed through.
 *
 * That is deliberate rather than an oversight: the dashboard is a static bundle
 * with no server-side session, so the only way to "require" a token here would
 * be to ship one in the JavaScript — which publishes the same secret that
 * unlocks the Assistant's tools, and is strictly worse than not having it. The
 * real fix is auth middleware on admin/*, which is already tracked as
 * outstanding and applies to this whole directory, not just this file.
 *
 * Every section is guarded individually: a dead engine, a missing table or an
 * unreadable unit yields null/`unreachable` for that piece rather than a 500.
 * A monitoring page that 500s when something is broken is useless exactly when
 * it is needed.
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
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../../env.php';

// Validate a token only when the caller actually presents one (see header note).
$expectedToken = env('CHATBOT_ADMIN_TOKEN');
$givenToken    = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? ($_GET['token'] ?? '');
if ($givenToken !== '' && $expectedToken !== null && $expectedToken !== '') {
    if (!hash_equals((string) $expectedToken, (string) $givenToken)) {
        ob_end_clean();
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
}

require_once __DIR__ . '/../../includes/service_control.php';
require_once __DIR__ . '/../../conn.php';

/**
 * This endpoint reports a missing table or column as null for that one metric —
 * `trade_logs` before migration 084, `ig_api_logs.is_read` before 085. That only
 * works if a failed query RETURNS FALSE.
 *
 * Since PHP 8.1 mysqli defaults to MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT, so
 * it throws mysqli_sql_exception instead — and `@` does not suppress exceptions.
 * Without this line the first query against a not-yet-migrated table takes the
 * whole page down with an empty 500, which is exactly the situation the page
 * exists to report on.
 */
mysqli_report(MYSQLI_REPORT_OFF);

$dbOk = (bool) $conn;

/** Single scalar, or null on any failure (missing table, bad SQL, no DB). */
function es_scalar($conn, $sql) {
    if (!$conn) {
        return null;
    }
    $res = @mysqli_query($conn, $sql);
    if (!$res) {
        return null;
    }
    $row = mysqli_fetch_row($res);
    mysqli_free_result($res);
    return $row ? $row[0] : null;
}

function es_int($conn, $sql) {
    $v = es_scalar($conn, $sql);
    return $v === null ? null : (int) $v;
}

// ---------------------------------------------------------------------------
// 1. Unit states — one shell call for the entire fleet (see sc_states()).
// ---------------------------------------------------------------------------
$registry  = sc_registry();
$allUnits  = sc_all_units();
$unitState = sc_states($allUnits);

// ---------------------------------------------------------------------------
// 2. Per-broker DB facts.
// ---------------------------------------------------------------------------
$dbFacts = [
    'capital' => [
        'accounts'       => es_int($conn, 'SELECT COUNT(*) FROM accounts WHERE deleted_at IS NULL'),
        'open_positions' => es_int($conn, 'SELECT COUNT(*) FROM positions'),
        'last_position'  => es_scalar($conn, 'SELECT MAX(updated_at) FROM positions'),
        'last_trade'     => es_scalar($conn, 'SELECT MAX(created_at) FROM trades'),
        'errors_unread'  => es_int($conn, 'SELECT COUNT(*) FROM capital_api_logs WHERE is_read = 0'),
        'errors_24h'     => es_int($conn, 'SELECT COUNT(*) FROM capital_api_logs WHERE created_at >= (NOW() - INTERVAL 24 HOUR)'),
        'last_error'     => es_scalar($conn, 'SELECT MAX(created_at) FROM capital_api_logs'),
    ],
    'ig' => [
        'accounts'       => es_int($conn, 'SELECT COUNT(*) FROM ig_accounts WHERE deleted_at IS NULL'),
        'open_positions' => es_int($conn, 'SELECT COUNT(*) FROM ig_positions'),
        'last_position'  => es_scalar($conn, 'SELECT MAX(updated_at) FROM ig_positions'),
        'last_trade'     => es_scalar($conn, 'SELECT MAX(created_at) FROM ig_past_positions'),
        // is_read arrives with migration 085; null here just means it is not applied yet.
        'errors_unread'  => es_int($conn, 'SELECT COUNT(*) FROM ig_api_logs WHERE is_read = 0'),
        'errors_24h'     => es_int($conn, 'SELECT COUNT(*) FROM ig_api_logs WHERE created_at >= (NOW() - INTERVAL 24 HOUR)'),
        'last_error'     => es_scalar($conn, 'SELECT MAX(created_at) FROM ig_api_logs'),
    ],
    'binance' => [
        'accounts'       => es_int($conn, 'SELECT COUNT(*) FROM binance_accounts WHERE deleted_at IS NULL'),
        'open_positions' => es_int($conn, 'SELECT COUNT(*) FROM binance_positions'),
        'last_position'  => es_scalar($conn, 'SELECT MAX(created_at) FROM binance_positions'),
        'last_trade'     => es_scalar($conn, 'SELECT MAX(created_at) FROM binance_pastpositions'),
        // Binance has no API-event log table at all — nothing writes one, so
        // trade_logs is the only per-signal record that exists for it.
        'errors_unread'  => null,
        'errors_24h'     => null,
        'last_error'     => null,
    ],
    'mexc' => [
        // Twin of the Binance block on the mexc_* tables (migrations 087-090). Nulls
        // here mean those migrations are not applied on this box yet.
        'accounts'       => es_int($conn, 'SELECT COUNT(*) FROM mexc_accounts WHERE deleted_at IS NULL'),
        'open_positions' => es_int($conn, 'SELECT COUNT(*) FROM mexc_positions'),
        'last_position'  => es_scalar($conn, 'SELECT MAX(created_at) FROM mexc_positions'),
        'last_trade'     => es_scalar($conn, 'SELECT MAX(created_at) FROM mexc_pastpositions'),
        'errors_unread'  => null,
        'errors_24h'     => null,
        'last_error'     => null,
    ],
    'bybit' => [
        // Twin of the Binance block on the bybit_* tables (migrations 098-101). Nulls
        // here mean those migrations are not applied on this box yet.
        'accounts'       => es_int($conn, 'SELECT COUNT(*) FROM bybit_accounts WHERE deleted_at IS NULL'),
        'open_positions' => es_int($conn, 'SELECT COUNT(*) FROM bybit_positions'),
        'last_position'  => es_scalar($conn, 'SELECT MAX(created_at) FROM bybit_positions'),
        'last_trade'     => es_scalar($conn, 'SELECT MAX(created_at) FROM bybit_pastpositions'),
        'errors_unread'  => null,
        'errors_24h'     => null,
        'last_error'     => null,
    ],
    'telegram' => [
        'accounts'       => null,
        'open_positions' => null,
        'last_position'  => null,
        'last_trade'     => null,
        'errors_unread'  => null,
        'errors_24h'     => null,
        'last_error'     => null,
    ],
];

// Capital's durable retry queue — a backed-up queue means signals are landing
// but not executing, which no unit state would reveal.
$retryQueue = null;
if ($conn) {
    $res = @mysqli_query($conn, "SELECT status, COUNT(*) c FROM capital_retry_queue GROUP BY status");
    if ($res) {
        $retryQueue = ['pending' => 0, 'processing' => 0, 'executed' => 0, 'failed' => 0, 'needs_review' => 0];
        while ($row = mysqli_fetch_assoc($res)) {
            $retryQueue[$row['status']] = (int) $row['c'];
        }
        mysqli_free_result($res);
    }
}

// Signal throughput from trade_logs (migration 084). Null across the board just
// means the migration has not been applied on this box yet.
$signalStats = [];
// Main feed only: binance's test feed (/binance_test_webhook, migration 096) is
// tester traffic, and must never make a silent TradingView feed look alive.
$mainFeed = es_int($conn, "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trade_logs' AND COLUMN_NAME = 'feed'")
    ? " AND feed = 'main'"
    : '';
// $b is a literal from this array, never request input — no escaping needed.
foreach (['capital', 'ig', 'binance', 'mexc', 'bybit'] as $b) {
    $signalStats[$b] = [
        'last_signal'  => es_scalar($conn, "SELECT MAX(ts) FROM trade_logs WHERE broker = '$b'$mainFeed"),
        'last_24h'     => es_int($conn, "SELECT COUNT(*) FROM trade_logs WHERE broker = '$b'$mainFeed AND ts >= (NOW() - INTERVAL 24 HOUR)"),
        'problems_24h' => es_int($conn, "SELECT COUNT(*) FROM trade_logs WHERE broker = '$b'$mainFeed AND success = 0 AND ts >= (NOW() - INTERVAL 24 HOUR)"),
    ];
}
$signalStats['telegram'] = ['last_signal' => null, 'last_24h' => null, 'problems_24h' => null];
$tradeLogsReady = ($signalStats['capital']['last_24h'] !== null);

// ---------------------------------------------------------------------------
// 3. Live HTTP probes + assembly.
// ---------------------------------------------------------------------------
$brokers = [];

foreach ($registry as $key => $cfg) {
    $expectation = sc_expectation($key);

    $units = [];
    $up = 0;
    $countable = 0;
    foreach ($cfg['units'] as $u) {
        $st = $unitState[$u['unit']];
        $units[] = [
            'unit'         => $u['unit'],
            'label'        => $u['label'],
            'role'         => $u['role'],
            'state'        => $st['state'],
            'sub_state'    => $st['sub_state'],
            'active_since' => $st['active_since'],
            'n_restarts'   => $st['n_restarts'],
            'load_state'   => $st['load_state'],
            'enabled'      => $st['enabled'],
            'controllable' => sc_is_linux() || $u['nssm'] !== null,
        ];
        if ($st['state'] === 'n/a') {
            continue; // no counterpart on this OS — not a unit that can be "down"
        }
        $countable++;
        if ($st['state'] === 'active') {
            $up++;
        }
    }

    // Probe only when there is something to probe. An engine whose units are all
    // down would just cost 1.5s per port waiting for a refused connection.
    $health = null;
    if (!empty($cfg['ports']) && $up > 0) {
        $health = [];
        if (isset($cfg['ports']['webhook'])) {
            $port = $cfg['ports']['webhook'];
            $health['webhook'] = ['port' => $port] + sc_http_probe($port, '/health');

            // Capital and IG expose these; Binance, MEXC and Bybit do not (their /health is minimal).
            if ($key === 'capital' || $key === 'ig') {
                $health['trading_active'] = ['port' => $port] + sc_http_probe($port, '/admin/trading_active');
                $health['webhook_status'] = ['port' => $port] + sc_http_probe($port, '/admin/webhook/status');
            }
        }
        if (isset($cfg['ports']['accounts'])) {
            $port = $cfg['ports']['accounts'];
            $health['accounts'] = ['port' => $port] + sc_http_probe($port, '/health');
        }
    }

    // Roll the pieces up into one verdict the UI can colour a pill with.
    // "degraded" is its own state on purpose: Capital's /health returns
    // status:"degraded" when the process is up but startup config never loaded —
    // the webhook then silently rejects every trade while the unit looks fine.
    $overall = 'unknown';
    if (!$expectation['expected']) {
        $overall = 'not-deployed';
    } elseif ($countable === 0) {
        $overall = 'unknown';
    } elseif ($up === 0) {
        $overall = 'down';
    } elseif ($up < $countable) {
        $overall = 'partial';
    } else {
        $overall = 'up';
        $hv = isset($health['webhook']['data']['status']) ? $health['webhook']['data']['status'] : null;
        if ($hv === 'degraded') {
            $overall = 'degraded';
        } elseif (isset($health['webhook']) && !$health['webhook']['ok']) {
            $overall = 'degraded';
        }
    }

    $brokers[$key] = [
        'key'          => $key,
        'label'        => $cfg['label'],
        'expected'     => $expectation['expected'],
        'expected_note' => $expectation['note'],
        'overall'      => $overall,
        'units_up'     => $up,
        'units_total'  => $countable,
        'units'        => $units,
        'ports'        => $cfg['ports'],
        'health'       => $health,
        'db'           => isset($dbFacts[$key]) ? $dbFacts[$key] : null,
        'signals'      => isset($signalStats[$key]) ? $signalStats[$key] : null,
        'retry_queue'  => $key === 'capital' ? $retryQueue : null,
    ];
}

if ($conn) {
    mysqli_close($conn);
}

ob_end_clean();
echo json_encode([
    'success' => true,
    'server'  => [
        'ip'  => sc_server_ip(),
        'env' => sc_env_key(),
        'os'  => PHP_OS_FAMILY,
        'php' => PHP_VERSION,
        'init' => sc_is_linux() ? 'systemd' : 'nssm',
    ],
    'db_connected'     => $dbOk,
    'trade_logs_ready' => $tradeLogsReady,
    'privilege_hint'   => sc_privilege_hint(),
    'brokers'          => $brokers,
    'generated_at'     => date('c'),
], JSON_INVALID_UTF8_SUBSTITUTE);
