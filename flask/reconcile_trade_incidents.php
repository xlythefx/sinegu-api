<?php
/**
 * Positions sweep for trade incidents (Phase 2 of the Trade Incidents plan).
 *
 * Each bot's positions fetcher calls this once per account per loop, right after a
 * SUCCESSFUL read of that account's live positions. A failed read must not call it:
 * an empty list here means "the account is flat" and resolves things.
 *
 * POST JSON:
 * {
 *   "secret": "...", "broker": "binance", "env_label": "...",
 *   "uni_id": "...", "account": "Dima",
 *   "positions": [ {"symbol":"FLUXUSDT","side":"SHORT","qty":222687,"upnl":-12.3,"entry_price":0.08}, ... ]
 * }
 * -> { success: true, resolved, orphans, refreshed }
 *
 * For that broker + uni_id, over open/retrying incidents of kind
 * exit_failed | exit_partial | retry_gave_up | retry_scheduled | exit_pending |
 * orphan_position | status_unknown:
 *   a) position gone          -> resolve (resolved_by='sweep').
 *   b) exit_pending > 2 min and position still there
 *                             -> upsert orphan_position (critical) and resolve the
 *                                exit_pending (resolved_by='superseded').
 *   c) exit_failed / retry_gave_up / exit_partial / orphan_position still there
 *                             -> refresh details.qty/upnl/entry_price + last_seen,
 *                                so the admin page shows live exposure.
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

$broker = isset($input['broker']) ? strtolower(trim((string) $input['broker'])) : '';
if (!in_array($broker, TI_BROKERS, true)) {
    ti_json(400, ['success' => false, 'error' => 'broker must be one of: ' . implode(', ', TI_BROKERS)]);
}
$uniId = ti_str($input['uni_id'] ?? null, 36, null);
if ($uniId === null) {
    ti_json(400, ['success' => false, 'error' => 'Missing uni_id']);
}
// positions must be an explicit list: a missing key is a caller bug, and treating
// it as "flat" would resolve every open incident for the account.
if (!array_key_exists('positions', $input) || !is_array($input['positions'])) {
    ti_json(400, ['success' => false, 'error' => 'positions must be a list (empty list = flat)']);
}

$envLabel = ti_str($input['env_label'] ?? null, 64, null);
$account  = ti_str($input['account'] ?? null, 128, null);

$positions = [];
foreach ($input['positions'] as $p) {
    if (!is_array($p)) {
        continue;
    }
    $sym = ti_symbol($p['symbol'] ?? ($p['ticker'] ?? ($p['epic'] ?? null)));
    if ($sym === '') {
        continue;
    }
    $num = function ($v) {
        return (isset($v) && $v !== '' && is_numeric($v)) ? (float) $v : null;
    };
    $positions[] = [
        'symbol'      => $sym,
        'side'        => ti_side($p['side'] ?? null),
        'qty'         => $num($p['qty'] ?? null),
        'upnl'        => $num($p['upnl'] ?? null),
        'entry_price' => $num($p['entry_price'] ?? null),
    ];
}

mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . '/../conn.php';
if (!$conn) {
    ti_json(500, ['success' => false, 'error' => 'Database connection failed']);
}
ti_require_table($conn);

$rows = ti_fetch_active($conn, $broker, $uniId, TI_SWEEP_KINDS);
if ($rows === null) {
    $err = mysqli_error($conn);
    mysqli_close($conn);
    ti_json(500, ['success' => false, 'error' => $err]);
}

/** First live position matching this incident's symbol/side, or null. */
$findPosition = function (array $inc) use ($positions, $broker) {
    foreach ($positions as $p) {
        if (ti_symbols_match($broker, $inc['symbol'], $p['symbol']) && ti_sides_match($inc['side'], $p['side'])) {
            return $p;
        }
    }
    return null;
};

$goneIds = [];
$supersededIds = [];
$orphans = 0;
$refreshed = 0;

$refreshStmt = mysqli_prepare($conn, 'UPDATE trade_incidents SET details = ?, last_seen = NOW() WHERE id = ?');

foreach ($rows as $inc) {
    // No symbol = nothing to check against the positions list; leave it for a human.
    if ($inc['symbol'] === null || $inc['symbol'] === '') {
        continue;
    }
    $pos = $findPosition($inc);

    // a) position closed -> resolve
    if ($pos === null) {
        $goneIds[] = (int) $inc['id'];
        continue;
    }

    // b) EXIT was sent > 2 min ago and the position is still open -> orphan
    if ($inc['kind'] === 'exit_pending' && (int) $inc['age_sec'] > 120) {
        $orphanSide = $inc['side'] !== '' ? $inc['side'] : $pos['side'];
        $orphanSymbol = $inc['symbol'];
        $qtyTxt = $pos['qty'] !== null ? rtrim(rtrim(number_format($pos['qty'], 8, '.', ''), '0'), '.') : '?';
        $msg = 'EXIT sent at ' . $inc['first_seen'] . ' but position still open: qty ' . $qtyTxt
            . ($pos['upnl'] !== null ? ', uPnL ' . round($pos['upnl'], 2) : '');
        [$oid, $oerr] = ti_upsert($conn, [
            'broker'     => $broker,
            'env_label'  => $envLabel,
            'kind'       => 'orphan_position',
            'severity'   => 'critical',
            'uni_id'     => $uniId,
            'account'    => $account ?? $inc['account'],
            'symbol'     => $orphanSymbol,
            'side'       => $orphanSide,
            'action'     => $inc['action'],
            'strategy'   => $inc['strategy'],
            'error_code' => null,
            'message'    => ti_str($msg, 4000, ''),
            'details'    => ti_details_json([
                'qty'                => $pos['qty'],
                'upnl'               => $pos['upnl'],
                'entry_price'        => $pos['entry_price'],
                'position_side'      => $pos['side'],
                'exit_sent_at'       => $inc['first_seen'],
                'exit_pending_id'    => (int) $inc['id'],
                'live_checked_at'    => gmdate('Y-m-d H:i:s') . 'Z',
            ]),
            'attempts'   => 0,
            'dedupe_key' => ti_dedupe_key($broker, 'orphan_position', $uniId, $orphanSymbol, $orphanSide),
        ]);
        if ($oid !== null) {
            $orphans++;
            $supersededIds[] = (int) $inc['id'];
        }
        continue;
    }

    // c) still exposed -> refresh live qty/upnl so the dashboard shows current risk
    if (in_array($inc['kind'], TI_EXPOSURE_KINDS, true) && $refreshStmt) {
        $d = null;
        if ($inc['details'] !== null && $inc['details'] !== '') {
            $d = json_decode($inc['details'], true);
        }
        if (!is_array($d) || (array_keys($d) === range(0, count($d) - 1) && $d)) {
            // non-object (or list) details: keep it, nest it, add live fields beside it
            $d = $d === null ? [] : ['original' => $d];
        }
        $d['qty'] = $pos['qty'];
        $d['upnl'] = $pos['upnl'];
        $d['entry_price'] = $pos['entry_price'];
        $d['live_checked_at'] = gmdate('Y-m-d H:i:s') . 'Z';
        $json = ti_details_json($d);
        $iid = (int) $inc['id'];
        mysqli_stmt_bind_param($refreshStmt, 'si', $json, $iid);
        if (mysqli_stmt_execute($refreshStmt)) {
            $refreshed++;
        }
    }
}
if ($refreshStmt) {
    mysqli_stmt_close($refreshStmt);
}

$resolved = ti_close_ids($conn, $goneIds, 'resolved', 'sweep', 'position closed (detected by sweep)');
$resolved += ti_close_ids($conn, $supersededIds, 'resolved', 'superseded', 'position still open after EXIT -> orphan_position');

mysqli_close($conn);
echo json_encode([
    'success'   => true,
    'resolved'  => $resolved,
    'orphans'   => $orphans,
    'refreshed' => $refreshed,
]);
