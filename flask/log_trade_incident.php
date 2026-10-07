<?php
/**
 * Record or resolve a trade incident (table trade_incidents, migration 093).
 *
 * Called by all four engines — CapitalFlask, ig-flask, binance-flask and its mexc/
 * sibling — whenever a trade goes wrong (failed/partial exit, failed entry, unknown
 * status, retry scheduled / gave up, EXIT dispatched and not yet confirmed, ...),
 * and again with op=resolve when the bot sees the problem clear.
 *
 * POST JSON:
 * {
 *   "secret": "...",                // required; TRADE_LOG_SECRET, else CAPITAL_API_LOG_SECRET
 *   "op": "open" | "resolve",       // default "open"
 *   "broker": "capital|binance|ig|mexc",
 *   "env_label": "NEXAFAMILY PROD 191",
 *   "kind": "exit_failed", "severity": "critical",
 *   "uni_id": "...", "account": "Dima", "symbol": "FLUXUSDT", "side": "SHORT",
 *   "action": "EXIT_SHORT", "strategy": "...", "error_code": "-4005",
 *   "message": "one-liner", "details": { ... }, "attempts": 3,
 *   "signature": "...",             // error_log dedupe instead of uni_id|symbol|side
 *   // op=resolve only:
 *   "kinds": ["exit_failed", ...],  // optional; default = every kind except error_log
 *   "resolved_by": "bot" | "sweep", "note": "retry recovered"
 * }
 * -> { success: true, id, resolved }  |  { success: false, error[, hint] }
 *
 * dedupe_key = broker|kind|uni_id|symbol|side (error_log: broker|error_log|signature).
 * op=open is an UPSERT on it. An exit_failed / retry_gave_up open also closes any
 * open retry_scheduled / exit_pending for the same tuple (resolved_by='superseded'),
 * since the newer row now owns that problem.
 *
 * DESIGN RULE (same as log_trade_signal.php): this must never be able to break a
 * trade. Bots call it fire-and-forget; every failure is a JSON answer, never HTML.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../includes/trade_incidents_lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ti_json(405, ['success' => false, 'error' => 'Method not allowed. Use POST.']);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    ti_json(400, ['success' => false, 'error' => 'Invalid JSON body']);
}

ti_require_bot_secret($input, __DIR__);

// --- Validate ------------------------------------------------------------
$op = isset($input['op']) ? strtolower(trim((string) $input['op'])) : 'open';
if ($op === 'update') {
    $op = 'open'; // the plan's "update" is the same upsert
}
if (!in_array($op, ['open', 'resolve'], true)) {
    ti_json(400, ['success' => false, 'error' => 'op must be open or resolve']);
}

$broker = isset($input['broker']) ? strtolower(trim((string) $input['broker'])) : '';
if (!in_array($broker, TI_BROKERS, true)) {
    ti_json(400, ['success' => false, 'error' => 'broker must be one of: ' . implode(', ', TI_BROKERS)]);
}

$uniId  = ti_str($input['uni_id'] ?? null, 36, null);
$symbol = ti_symbol($input['symbol'] ?? null);
$side   = ti_side($input['side'] ?? null);

mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . '/../conn.php';
if (!$conn) {
    ti_json(500, ['success' => false, 'error' => 'Database connection failed']);
}
ti_require_table($conn);

// --- op=resolve ----------------------------------------------------------
if ($op === 'resolve') {
    $kinds = null;
    if (isset($input['kinds']) && is_array($input['kinds'])) {
        $kinds = array_values(array_unique(array_filter(array_map('ti_kind', $input['kinds']), 'strlen')));
        if (!$kinds) {
            $kinds = null;
        }
    } elseif (isset($input['kind']) && ti_kind($input['kind']) !== '') {
        $kinds = [ti_kind($input['kind'])];
    }

    $rows = ti_fetch_active($conn, $broker, $uniId, $kinds);
    if ($rows === null) {
        $err = mysqli_error($conn);
        mysqli_close($conn);
        ti_json(500, ['success' => false, 'error' => $err]);
    }
    $ids = [];
    foreach ($rows as $r) {
        if (ti_symbols_match($broker, $r['symbol'], $symbol) && ti_sides_match($r['side'], $side)) {
            $ids[] = (int) $r['id'];
        }
    }
    $resolvedBy = ti_str($input['resolved_by'] ?? null, 64, 'bot');
    $note = ti_str($input['note'] ?? null, 4000, null);
    $n = ti_close_ids($conn, $ids, 'resolved', $resolvedBy, $note);
    mysqli_close($conn);
    echo json_encode(['success' => true, 'id' => null, 'resolved' => $n]);
    exit;
}

// --- op=open -------------------------------------------------------------
$kind = ti_kind($input['kind'] ?? null);
if ($kind === '') {
    mysqli_close($conn);
    ti_json(400, ['success' => false, 'error' => 'Missing kind']);
}
$severity = isset($input['severity']) ? strtolower(trim((string) $input['severity'])) : '';
if (!in_array($severity, TI_SEVERITIES, true)) {
    $severity = TI_DEFAULT_SEVERITY[$kind] ?? 'error';
}

$fields = [
    'broker'     => $broker,
    'env_label'  => ti_str($input['env_label'] ?? null, 64, null),
    'kind'       => $kind,
    'severity'   => $severity,
    'uni_id'     => $uniId,
    'account'    => ti_str($input['account'] ?? null, 128, null),
    'symbol'     => $symbol === '' ? null : $symbol,
    'side'       => $side,
    'action'     => ti_str(isset($input['action']) ? strtoupper((string) $input['action']) : null, 32, null),
    'strategy'   => ti_str($input['strategy'] ?? null, 128, null),
    'error_code' => ti_str($input['error_code'] ?? null, 32, null),
    'message'    => ti_str($input['message'] ?? null, 4000, ''),
    'details'    => ti_details_json($input['details'] ?? null),
    'attempts'   => isset($input['attempts']) && is_numeric($input['attempts']) ? max(0, (int) $input['attempts']) : 0,
    'dedupe_key' => ti_dedupe_key(
        $broker,
        $kind,
        $uniId,
        $symbol,
        $side,
        ti_str($input['signature'] ?? null, 400, null)
    ),
];

[$id, $err] = ti_upsert($conn, $fields);
if ($id === null) {
    mysqli_close($conn);
    ti_json(500, ['success' => false, 'error' => $err]);
}

// A terminal failure supersedes the pending/scheduled rows for the same position.
$superseded = 0;
if (in_array($kind, ['exit_failed', 'retry_gave_up', 'waiting_liquidity'], true)) {
    $rows = ti_fetch_active($conn, $broker, $uniId, ['retry_scheduled', 'exit_pending']);
    $ids = [];
    foreach ($rows ?: [] as $r) {
        if ((int) $r['id'] !== $id
            && ti_symbols_match($broker, $r['symbol'], $symbol)
            && ti_sides_match($r['side'], $side)) {
            $ids[] = (int) $r['id'];
        }
    }
    $superseded = ti_close_ids($conn, $ids, 'resolved', 'superseded', "superseded by $kind #$id");
}

mysqli_close($conn);
echo json_encode(['success' => true, 'id' => $id, 'resolved' => $superseded]);
