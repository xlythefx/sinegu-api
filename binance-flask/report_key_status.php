<?php
/**
 * POST /binance-flask/report_key_status.php — binance-flask reports an account's
 * API-key health, and this is where a key Binance keeps refusing gets turned OFF.
 *
 * Body: {
 *   "secret":  "...",                 // bot secret (TRADE_LOG_SECRET / CAPITAL_API_LOG_SECRET)
 *   "api_key": "...",
 *   "status":  "ok" | "blocked",
 *   "scope":   "read" | "trade",      // what was being attempted (signed GET / signed POST)
 *   "code":    "-2015",               // blocked only: Binance's code
 *   "message": "Invalid API-key, …"   // blocked only
 * }
 * -> { success, updated, status, scope, blocked_since, grace_ends_at, transitioned,
 *      turned_off, account, uni_id }
 *
 * Rules (ported from Pixel Alpha's EngineSyncController::keyStatus + its daily
 * exchange:disconnect-blocked-keys sweep, minus the cron — 191 has none):
 *
 *  - key_blocked_at is set only on the TRANSITION into blocked. The engine re-reports a
 *    standing refusal every ~15 min; a repeat must never restart the clock.
 *  - Once the streak is KEY_GRACE_HOURS old the account is turned off here
 *    (enabled = 0, key_disabled_at = NOW()) — on the report that crosses the line, so
 *    no scheduler is needed. Reversible: an admin re-enables it, and a re-enabled
 *    account gets a FRESH grace period (the old streak is not held against it).
 *  - A read success never clears a refusal an ORDER earned (scope 'trade'): reads and
 *    trading are separate permissions, and a key that can read but not trade would
 *    otherwise flap — blocked by every order, cleared by the balance poller minutes
 *    later — and never age out. Only a trade-scope success clears it.
 *  - A 'read' refusal outranks a 'trade' one while the streak lasts: reads failing too
 *    means the key itself (or our IP) is the problem.
 *
 * Bot-only. Same shared-secret check as flask/log_trade_signal.php.
 */

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use POST.']);
    exit;
}

/** Hours a key may be refused, without a single success, before the account is turned off. */
const KEY_GRACE_HOURS = 72;

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON body']);
    exit;
}

$configPath = __DIR__ . '/../flask/config_bot_listener.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Config not found (flask/config_bot_listener.php)']);
    exit;
}
require $configPath;
$expected = '';
if (isset($TRADE_LOG_SECRET) && $TRADE_LOG_SECRET !== '') {
    $expected = (string) $TRADE_LOG_SECRET;
} elseif (isset($CAPITAL_API_LOG_SECRET)) {
    $expected = (string) $CAPITAL_API_LOG_SECRET;
}
$secret = isset($input['secret']) ? (string) $input['secret'] : '';
if ($expected === '' || !hash_equals($expected, $secret)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$apiKey = trim((string) ($input['api_key'] ?? ''));
$status = strtolower(trim((string) ($input['status'] ?? '')));
$scope  = strtolower(trim((string) ($input['scope'] ?? 'read')));
if ($apiKey === '' || strlen($apiKey) > 128 || !in_array($status, ['ok', 'blocked'], true)
        || !in_array($scope, ['read', 'trade'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'api_key, status (ok|blocked) and scope (read|trade) are required']);
    exit;
}
// STRICT_TRANS_TABLES on 191/182: an over-long value would kill the UPDATE, so cut first.
$code = substr(preg_replace('/[^0-9\-]/', '', (string) ($input['code'] ?? '')), 0, 16);
$message = (string) ($input['message'] ?? '');
$message = function_exists('mb_substr') ? mb_substr($message, 0, 255, 'UTF-8') : substr($message, 0, 255);
if (function_exists('mb_convert_encoding')) {
    $message = mb_convert_encoding($message, 'UTF-8', 'UTF-8');
}

require_once __DIR__ . '/../conn.php';
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

// Before migration 104 the columns do not exist: say so without failing, so an engine
// that lands first just logs a quiet warning instead of an error per account.
$col = mysqli_query($conn, "SHOW COLUMNS FROM `binance_accounts` LIKE 'key_disabled_at'");
if (!$col || mysqli_num_rows($col) === 0) {
    echo json_encode(['success' => true, 'updated' => false, 'reason' => 'migration 104 not applied']);
    exit;
}

$sel = mysqli_prepare($conn,
    "SELECT name, uni_id, enabled, key_status, key_blocked_scope, key_blocked_at, key_disabled_at
     FROM binance_accounts WHERE api_key = ? AND deleted_at IS NULL LIMIT 1");
mysqli_stmt_bind_param($sel, 's', $apiKey);
mysqli_stmt_execute($sel);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($sel));
mysqli_stmt_close($sel);
if (!$row) {
    echo json_encode(['success' => true, 'updated' => false, 'reason' => 'no such account']);
    exit;
}

$wasBlocked = $row['key_status'] === 'blocked';
$enabled = (int) $row['enabled'] === 1;
$turnedOff = false;

if ($status === 'blocked') {
    // A fresh streak on the transition — and also when the account was turned off
    // earlier and an admin has since switched it back on (key_disabled_at still set,
    // enabled = 1): that admin decision earns it a full grace period again.
    $restart = !$wasBlocked || $row['key_blocked_at'] === null
        || ($row['key_disabled_at'] !== null && $enabled);
    $newScope = ($scope === 'read' || (!$restart && $row['key_blocked_scope'] === 'read')) ? 'read' : 'trade';

    $upd = mysqli_prepare($conn,
        "UPDATE binance_accounts SET key_status = 'blocked', key_blocked_scope = ?,
             key_blocked_at = IF(?, NOW(), key_blocked_at),
             key_disabled_at = IF(?, NULL, key_disabled_at),
             key_error_code = ?, key_error_message = ?, key_checked_at = NOW()
         WHERE api_key = ? AND deleted_at IS NULL");
    $r = $restart ? 1 : 0;
    mysqli_stmt_bind_param($upd, 'siisss', $newScope, $r, $r, $code, $message, $apiKey);
    mysqli_stmt_execute($upd);
    mysqli_stmt_close($upd);

    // Turn it off once the streak has lasted the whole grace period.
    if ($enabled) {
        $off = mysqli_prepare($conn,
            "UPDATE binance_accounts SET enabled = 0, key_disabled_at = NOW()
             WHERE api_key = ? AND deleted_at IS NULL AND enabled = 1 AND key_status = 'blocked'
               AND key_blocked_at IS NOT NULL AND key_blocked_at <= NOW() - INTERVAL " . KEY_GRACE_HOURS . " HOUR");
        mysqli_stmt_bind_param($off, 's', $apiKey);
        mysqli_stmt_execute($off);
        $turnedOff = mysqli_stmt_affected_rows($off) > 0;
        mysqli_stmt_close($off);
    }
    $outScope = $newScope;
} else {
    if ($wasBlocked && $row['key_blocked_scope'] === 'trade' && $scope === 'read') {
        // Reading works, but that was never in doubt — an order was refused. Keep it.
        $upd = mysqli_prepare($conn, "UPDATE binance_accounts SET key_checked_at = NOW() WHERE api_key = ? AND deleted_at IS NULL");
        mysqli_stmt_bind_param($upd, 's', $apiKey);
        mysqli_stmt_execute($upd);
        mysqli_stmt_close($upd);
        $status = 'blocked';
        $outScope = 'trade';
    } else {
        $upd = mysqli_prepare($conn,
            "UPDATE binance_accounts SET key_status = 'ok', key_blocked_scope = NULL, key_blocked_at = NULL,
                 key_error_code = NULL, key_error_message = NULL, key_disabled_at = NULL, key_checked_at = NOW()
             WHERE api_key = ? AND deleted_at IS NULL");
        mysqli_stmt_bind_param($upd, 's', $apiKey);
        mysqli_stmt_execute($upd);
        mysqli_stmt_close($upd);
        $outScope = null;
    }
}

$after = mysqli_prepare($conn,
    "SELECT key_blocked_at, enabled FROM binance_accounts WHERE api_key = ? AND deleted_at IS NULL LIMIT 1");
mysqli_stmt_bind_param($after, 's', $apiKey);
mysqli_stmt_execute($after);
$now = mysqli_fetch_assoc(mysqli_stmt_get_result($after)) ?: [];
mysqli_stmt_close($after);
mysqli_close($conn);

$since = $now['key_blocked_at'] ?? null;
echo json_encode([
    'success'       => true,
    'updated'       => true,
    'status'        => $status,
    'scope'         => $outScope,
    'blocked_since' => $since,
    'grace_ends_at' => $since ? date('Y-m-d H:i:s', strtotime($since) + KEY_GRACE_HOURS * 3600) : null,
    'grace_hours'   => KEY_GRACE_HOURS,
    'transitioned'  => $wasBlocked !== ($status === 'blocked'),
    'turned_off'    => $turnedOff,
    'enabled'       => isset($now['enabled']) ? (int) $now['enabled'] : null,
    'account'       => $row['name'],
    'uni_id'        => $row['uni_id'],
]);
