<?php
/**
 * Admin: re-send a TARGETED close for one trade incident to its broker's receiver.
 *
 * POST /admin/POST/retry_trade_incident.php   { "id": 123 }   (admin password required)
 * -> { success, message, bot_status, bot_response }
 *
 * The close goes to the bot's loopback webhook on this same box, with
 * target_uni_ids = [incident.uni_id] so only that one account is touched:
 *
 *   broker   URL (override in api/.env)                                   action
 *   capital  http://127.0.0.1:5000/webhook        TRADE_RETRY_CAPITAL_URL  EXIT  (Capital flattens the ticker in both directions on every exit)
 *   ig       http://127.0.0.1:5002/ig_webhook     TRADE_RETRY_IG_URL       EXIT_LONG / EXIT_SHORT / EXIT (side empty)
 *   binance  http://127.0.0.1:5001/binance_webhook TRADE_RETRY_BINANCE_URL EXIT_LONG / EXIT_SHORT (side empty -> both, sequentially)
 *   mexc     http://127.0.0.1:5003/mexc_webhook   TRADE_RETRY_MEXC_URL     EXIT_LONG / EXIT_SHORT (side empty -> both)
 *
 * Webhook secrets (each bot checks the body "secret"):
 *   capital  CAPITAL_WEBHOOK_SECRET in api/.env, else bot_config.webhook_secret (the
 *            value the dashboard's manual-trade page already sends to Capital)
 *   ig       IG_WEBHOOK_SECRET, default 'ig_bot'      (matches AdminManualTrade.tsx)
 *   binance  BINANCE_WEBHOOK_SECRET, default 'binance_bot' (binance-flask src/hooks.py)
 *   mexc     MEXC_WEBHOOK_SECRET, default 'mexc_bot'  (binance-flask mexc/src/hooks.py)
 *
 * SAFETY: every receiver treats an empty/missing target_uni_ids as "all accounts".
 * This endpoint therefore refuses (success:false) whenever it cannot name the
 * account — it NEVER sends an untargeted exit.
 *
 * No `strategy` is sent: Capital checks an explicit strategy against the global
 * enable list and would skip the close if that strategy is disabled. Each bot
 * defaults an exit's strategy itself.
 *
 * On a 2xx from the bot the incident becomes status='retrying', attempts+1. The bot's
 * own incident reporting then resolves it (or re-opens it) when the close finishes.
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Admin-Password');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../../includes/trade_incidents_lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ti_json(405, ['success' => false, 'error' => 'Method not allowed']);
}

$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input)) {
    ti_json(400, ['success' => false, 'error' => 'Invalid JSON body']);
}

require_once __DIR__ . '/../../includes/admin_action_auth.php'; // also loads env.php
requireAdminActionPassword($input); // exits 401 { auth_required: true } on failure

$id = isset($input['id']) ? (int) $input['id'] : 0;
if ($id <= 0) {
    ti_json(400, ['success' => false, 'error' => 'id is required']);
}

mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . '/../../conn.php';
if (!$conn) {
    ti_json(500, ['success' => false, 'error' => 'Database connection failed']);
}
ti_require_table($conn);

$st = mysqli_prepare($conn, 'SELECT * FROM trade_incidents WHERE id = ? LIMIT 1');
mysqli_stmt_bind_param($st, 'i', $id);
mysqli_stmt_execute($st);
$inc = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
mysqli_stmt_close($st);
if (!$inc) {
    mysqli_close($conn);
    ti_json(404, ['success' => false, 'error' => 'Incident not found']);
}

/** Refuse with a clear reason (200 + success:false, so the UI shows the message). */
function retry_refuse($conn, $msg) {
    mysqli_close($conn);
    ti_json(200, ['success' => false, 'error' => $msg, 'bot_status' => null, 'bot_response' => null]);
}

if (!in_array($inc['status'], ['open', 'retrying'], true)) {
    retry_refuse($conn, "Incident is {$inc['status']}; reopen it before retrying the close.");
}
if ($inc['kind'] === 'entry_failed' || $inc['kind'] === 'error_log') {
    retry_refuse($conn, "Retry close is not available for {$inc['kind']} incidents (no position to close).");
}

$broker = (string) $inc['broker'];
$uniId  = trim((string) ($inc['uni_id'] ?? ''));
$symbol = strtoupper(trim((string) ($inc['symbol'] ?? '')));
$side   = ti_side($inc['side'] ?? '');

if ($uniId === '') {
    retry_refuse($conn, 'Incident has no uni_id, so the close cannot be targeted at one account. Refusing to send an untargeted EXIT (it would close this symbol for every account).');
}
if ($symbol === '') {
    retry_refuse($conn, 'Incident has no symbol; nothing to close.');
}

$details = [];
if ($inc['details'] !== null && $inc['details'] !== '') {
    $d = json_decode($inc['details'], true);
    if (is_array($d)) {
        $details = $d;
    }
}

// --- Per-broker target ----------------------------------------------------
$tickers = [];   // symbol(s) to send
$actions = [];   // one request per action
$url = '';
$secret = '';

switch ($broker) {
    case 'binance':
    case 'mexc':
        $isMexc = $broker === 'mexc';
        $url = (string) env($isMexc ? 'TRADE_RETRY_MEXC_URL' : 'TRADE_RETRY_BINANCE_URL',
            $isMexc ? 'http://127.0.0.1:5003/mexc_webhook' : 'http://127.0.0.1:5001/binance_webhook');
        $secret = (string) env($isMexc ? 'MEXC_WEBHOOK_SECRET' : 'BINANCE_WEBHOOK_SECRET',
            $isMexc ? 'mexc_bot' : 'binance_bot');
        // Hedge-mode exits name a side. An incident with no side gets both: closing a
        // side that is already flat is a harmless "already flat" on these bots.
        $actions = $side === 'LONG' ? ['EXIT_LONG'] : ($side === 'SHORT' ? ['EXIT_SHORT'] : ['EXIT_LONG', 'EXIT_SHORT']);
        $tickers = [$symbol];
        break;

    case 'capital':
        $url = (string) env('TRADE_RETRY_CAPITAL_URL', 'http://127.0.0.1:5000/webhook');
        $secret = (string) env('CAPITAL_WEBHOOK_SECRET', '');
        if ($secret === '') {
            $r = mysqli_query($conn, 'SELECT webhook_secret FROM bot_config WHERE id = 5163321 LIMIT 1');
            $row = $r ? mysqli_fetch_assoc($r) : null;
            if (!$row) {
                $r = mysqli_query($conn, "SELECT webhook_secret FROM bot_config WHERE webhook_secret IS NOT NULL AND webhook_secret <> '' ORDER BY id LIMIT 1");
                $row = $r ? mysqli_fetch_assoc($r) : null;
            }
            $secret = $row ? trim((string) $row['webhook_secret']) : '';
        }
        // Capital's handler flattens the whole ticker on EXIT/EXIT_LONG/EXIT_SHORT alike
        // (direction_filter=None), so plain EXIT is the honest action name.
        $actions = ['EXIT'];
        $t = isset($details['ticker']) ? strtoupper(trim((string) $details['ticker'])) : '';
        $tickers = [$t !== '' ? $t : $symbol];
        break;

    case 'ig':
        $url = (string) env('TRADE_RETRY_IG_URL', 'http://127.0.0.1:5002/ig_webhook');
        $secret = (string) env('IG_WEBHOOK_SECRET', 'ig_bot');
        $actions = [$side === 'LONG' ? 'EXIT_LONG' : ($side === 'SHORT' ? 'EXIT_SHORT' : 'EXIT')];
        // The IG webhook only accepts a ticker configured in `assets` (it rejects epics),
        // but the incident may carry the epic from the position snapshot. Resolve it.
        $ticker = '';
        $cands = [];
        if (isset($details['ticker'])) {
            $cands[] = strtoupper(trim((string) $details['ticker']));
        }
        $cands[] = $symbol;
        $q = mysqli_prepare($conn, "SELECT ticker FROM assets WHERE broker = 'ig.com' AND UPPER(ticker) = ? LIMIT 1");
        if ($q) {
            foreach ($cands as $c) {
                if ($c === '') {
                    continue;
                }
                mysqli_stmt_bind_param($q, 's', $c);
                mysqli_stmt_execute($q);
                $row = mysqli_fetch_assoc(mysqli_stmt_get_result($q));
                if ($row) {
                    $ticker = strtoupper((string) $row['ticker']);
                    break;
                }
            }
            mysqli_stmt_close($q);
        }
        if ($ticker === '') {
            $q = mysqli_prepare($conn, 'SELECT ticker FROM ig_positions WHERE UPPER(epic) = ? AND ticker <> \'\' ORDER BY updated_at DESC LIMIT 1');
            if ($q) {
                mysqli_stmt_bind_param($q, 's', $symbol);
                mysqli_stmt_execute($q);
                $row = mysqli_fetch_assoc(mysqli_stmt_get_result($q));
                mysqli_stmt_close($q);
                if ($row) {
                    $ticker = strtoupper((string) $row['ticker']);
                }
            }
        }
        if ($ticker === '') {
            retry_refuse($conn, "Could not map IG symbol '$symbol' to a configured ig.com asset ticker; close it manually.");
        }
        $tickers = [$ticker];
        break;

    default:
        retry_refuse($conn, "Unsupported broker '$broker'.");
}

if ($secret === '') {
    retry_refuse($conn, "No webhook secret configured for $broker (set it in api/.env); nothing was sent.");
}

// --- Send -----------------------------------------------------------------
function retry_post($url, array $payload) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = is_string($body) ? json_decode($body, true) : null;
    return [
        'status'   => $code ?: null,
        'response' => is_array($decoded) ? $decoded : (is_string($body) ? ti_str($body, 1000, '') : null),
        'error'    => $err !== '' ? $err : null,
        'ok'       => $err === '' && $code >= 200 && $code < 300,
    ];
}

$results = [];
$allOk = true;
foreach ($actions as $action) {
    $payload = [
        'secret'         => $secret,
        'action'         => $action,
        'symbol'         => $tickers[0],
        'close'          => null,
        'target_uni_ids' => [$uniId], // never empty — see SAFETY above
        'source'         => 'trade_incident_retry',
        'incident_id'    => $id,
    ];
    $r = retry_post($url, $payload);
    $results[] = ['action' => $action] + $r;
    if (!$r['ok']) {
        $allOk = false;
        break; // don't fire the second side after a failed first request
    }
}

$last = end($results);
$botStatus = $last['status'];
$botResponse = count($results) === 1 ? $last['response'] : array_map(function ($r) {
    return ['action' => $r['action'], 'status' => $r['status'], 'response' => $r['response']];
}, $results);

if (!$allOk) {
    mysqli_close($conn);
    $why = $last['error'] ? "bot unreachable ({$last['error']})" : "bot answered HTTP {$last['status']}";
    echo json_encode([
        'success'      => false,
        'error'        => "Retry close for $broker {$tickers[0]} not accepted: $why",
        'bot_status'   => $botStatus,
        'bot_response' => $botResponse,
    ], JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$up = mysqli_prepare(
    $conn,
    "UPDATE trade_incidents
        SET status = 'retrying', attempts = attempts + 1, last_seen = NOW()
      WHERE id = ? AND status IN ('open','retrying')"
);
if ($up) {
    mysqli_stmt_bind_param($up, 'i', $id);
    mysqli_stmt_execute($up);
    mysqli_stmt_close($up);
}
mysqli_close($conn);

echo json_encode([
    'success'      => true,
    'message'      => 'Sent ' . implode(' + ', $actions) . " {$tickers[0]} to $broker for uni_id $uniId",
    'bot_status'   => $botStatus,
    'bot_response' => $botResponse,
], JSON_INVALID_UTF8_SUBSTITUTE);
