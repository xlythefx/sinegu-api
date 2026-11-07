<?php
/**
 * Shared helpers for the Trade Incidents feature (table `trade_incidents`, migration 093).
 *
 * Used by:
 *   flask/log_trade_incident.php        bots -> API (op=open|resolve)
 *   flask/reconcile_trade_incidents.php bots' positions fetchers -> API (sweep)
 *   admin/GET/get_trade_incidents.php, admin/GET/trade_incidents_count.php,
 *   admin/POST/update_trade_incident.php, admin/POST/retry_trade_incident.php
 *
 * Production MySQL runs STRICT_TRANS_TABLES, so an over-long string or invalid UTF-8
 * kills the whole INSERT instead of being truncated. Every value that reaches the
 * table goes through ti_str() first, which scrubs and cuts it to the column length.
 */

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit;
}

const TI_BROKERS    = ['capital', 'binance', 'ig', 'mexc', 'bybit']; // 'bybit': migration 102
const TI_SEVERITIES = ['critical', 'error', 'warning'];
const TI_STATUSES   = ['open', 'retrying', 'resolved', 'ignored'];

/** Kinds the positions sweep (and a default op=resolve) can close. */
const TI_SWEEP_KINDS = [
    'exit_failed', 'exit_partial', 'retry_gave_up', 'retry_scheduled',
    'exit_pending', 'orphan_position', 'status_unknown', 'waiting_liquidity',
];

/** Kinds whose rows carry live exposure; the sweep refreshes their qty/upnl. */
const TI_EXPOSURE_KINDS = ['exit_failed', 'retry_gave_up', 'exit_partial', 'orphan_position', 'waiting_liquidity'];

/** Default severity per kind when the bot did not send a valid one (contract guidance). */
const TI_DEFAULT_SEVERITY = [
    'exit_failed'        => 'critical',
    'exit_partial'       => 'critical',
    'retry_gave_up'      => 'critical',
    'orphan_position'    => 'critical',
    'entry_failed'       => 'error',
    'status_unknown'     => 'error',
    'error_log'          => 'error',
    'retry_scheduled'    => 'warning',
    'exit_pending'       => 'warning',
    'skipped_exit_open'  => 'warning',
    'stale_position_row' => 'warning',
    // Binance -4131: no counterparty inside the exchange's price band (empty book).
    // Not retried; the sweep resolves it once the position is gone.
    'waiting_liquidity'  => 'warning',
];

/** Max chars of a dedupe key before it is hashed — leaves room for the "|r<id>" resolve suffix in VARCHAR(191). */
const TI_DEDUPE_MAX = 160;

/** Emit a JSON response and stop. */
function ti_json($code, array $body) {
    http_response_code($code);
    echo json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** Valid UTF-8, trimmed, cut to $max characters. null/'' -> $emptyAs. */
function ti_str($v, $max, $emptyAs = null) {
    if ($v === null || is_array($v) || is_object($v)) {
        return $emptyAs;
    }
    if (is_bool($v)) {
        $v = $v ? '1' : '0';
    }
    $s = trim((string) $v);
    if ($s === '') {
        return $emptyAs;
    }
    if (function_exists('mb_scrub')) {
        $s = mb_scrub($s, 'UTF-8');
    } else {
        $s = (string) iconv('UTF-8', 'UTF-8//IGNORE', $s);
    }
    if (function_exists('mb_substr')) {
        if (mb_strlen($s, 'UTF-8') > $max) {
            $s = mb_substr($s, 0, $max, 'UTF-8');
        }
    } elseif (strlen($s) > $max) {
        $s = substr($s, 0, $max);
    }
    return $s;
}

/** BUY/LONG -> LONG, SELL/SHORT -> SHORT, anything else (BOTH, '', null) -> '' (any side). */
function ti_side($v) {
    $s = strtoupper(trim((string) ($v ?? '')));
    if ($s === 'LONG' || $s === 'BUY') {
        return 'LONG';
    }
    if ($s === 'SHORT' || $s === 'SELL') {
        return 'SHORT';
    }
    return '';
}

function ti_symbol($v) {
    $s = ti_str($v, 64, '');
    return strtoupper($s);
}

/** kind: lowercase [a-z0-9_], max 32; '' if nothing usable. */
function ti_kind($v) {
    $s = strtolower(trim((string) ($v ?? '')));
    $s = preg_replace('/[^a-z0-9_]/', '', $s);
    return substr($s, 0, 32);
}

/**
 * Symbol match used for resolve and the sweep.
 *
 * Case-insensitive always. The "A contains B or B contains A" rule from the contract
 * exists for Capital/IG, where an incident may carry an epic and the position a
 * ticker (or the reverse). It is NOT applied to Binance/MEXC: their symbols are
 * exact pair names and containment gives real false positives (OPUSDT is a
 * substring of TOPUSDT), which would let the sweep resolve the wrong incident.
 */
function ti_symbols_match($broker, $a, $b) {
    $a = strtoupper(trim((string) $a));
    $b = strtoupper(trim((string) $b));
    if ($a === '' || $b === '') {
        return $a === $b;
    }
    if ($a === $b) {
        return true;
    }
    if ($broker === 'capital' || $broker === 'ig') {
        return strpos($a, $b) !== false || strpos($b, $a) !== false;
    }
    return false;
}

/** '' on either side means "any side". */
function ti_sides_match($a, $b) {
    $a = (string) $a;
    $b = (string) $b;
    return $a === '' || $b === '' || $a === $b;
}

/** Server-side dedupe key; hashed when long so the resolve suffix always fits VARCHAR(191). */
function ti_dedupe_key($broker, $kind, $uniId, $symbol, $side, $signature = null) {
    if ($kind === 'error_log') {
        $tail = (string) ($signature ?? '');
        if ($tail === '') {
            $tail = (string) ($symbol ?? '');
        }
        $key = $broker . '|error_log|' . $tail;
    } else {
        $key = $broker . '|' . $kind . '|' . ($uniId ?? '') . '|' . ($symbol ?? '') . '|' . ($side ?? '');
    }
    if (strlen($key) > TI_DEDUPE_MAX) {
        $key = $broker . '|' . $kind . '|h:' . sha1($key);
    }
    return $key;
}

/** JSON-encode details for the JSON column; oversize payloads are replaced by a truncated preview. */
function ti_details_json($details, $maxBytes = 60000) {
    if ($details === null || $details === '' || $details === []) {
        return null;
    }
    if (is_string($details)) {
        $decoded = json_decode($details, true);
        $details = (json_last_error() === JSON_ERROR_NONE) ? $decoded : ['text' => $details];
    }
    if (!is_array($details)) {
        $details = ['value' => $details];
    }
    $json = json_encode($details, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    if ($json === false) {
        return null;
    }
    if (strlen($json) > $maxBytes) {
        $json = json_encode([
            'truncated'      => true,
            'original_bytes' => strlen($json),
            'preview'        => ti_str($json, 2000, ''),
        ], JSON_INVALID_UTF8_SUBSTITUTE);
    }
    return $json;
}

/** Bot-facing shared secret check, cloned from flask/log_trade_signal.php. Exits on failure. */
function ti_require_bot_secret(array $input, $flaskDir) {
    $configPath = $flaskDir . '/config_bot_listener.php';
    if (!file_exists($configPath)) {
        ti_json(500, ['success' => false, 'error' => 'Config not found (flask/config_bot_listener.php)']);
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
        ti_json(403, ['success' => false, 'error' => 'Unauthorized']);
    }
}

/** True when the trade_incidents table exists. */
function ti_table_exists($conn) {
    $r = mysqli_query(
        $conn,
        "SELECT COUNT(*) AS n FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trade_incidents'"
    );
    if (!$r) {
        return false;
    }
    $row = mysqli_fetch_assoc($r);
    mysqli_free_result($r);
    return $row && (int) $row['n'] > 0;
}

/** 500 with the migration hint unless the table exists. */
function ti_require_table($conn) {
    if (!ti_table_exists($conn)) {
        mysqli_close($conn);
        ti_json(500, [
            'success' => false,
            'error'   => "trade_incidents table doesn't exist",
            'hint'    => 'run migration 093',
        ]);
    }
}

/**
 * Close incidents by id. The dedupe_key gets "|r<id>" so a later recurrence of the
 * same problem opens a fresh row rather than colliding with this closed one.
 * Only rows still open/retrying are touched. Returns the number closed.
 */
function ti_close_ids($conn, array $ids, $status, $resolvedBy, $note) {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($i) { return $i > 0; })));
    if (!$ids || !in_array($status, ['resolved', 'ignored'], true)) {
        return 0;
    }
    $resolvedBy = ti_str($resolvedBy, 64, 'bot');
    $note = ti_str($note, 4000, null);
    $sql = "UPDATE trade_incidents
               SET status = ?, resolved_at = NOW(), resolved_by = ?, resolution_note = COALESCE(?, resolution_note),
                   dedupe_key = CONCAT(LEFT(dedupe_key, 191 - CHAR_LENGTH(CONCAT('|r', id))), '|r', id)
             WHERE id IN (" . implode(',', $ids) . ") AND status IN ('open','retrying')";
    $st = mysqli_prepare($conn, $sql);
    if (!$st) {
        return 0;
    }
    mysqli_stmt_bind_param($st, 'sss', $status, $resolvedBy, $note);
    $n = mysqli_stmt_execute($st) ? mysqli_stmt_affected_rows($st) : 0;
    mysqli_stmt_close($st);
    return max(0, (int) $n);
}

/**
 * Open/retrying incidents for one broker + uni_id (uni_id null = rows with no uni_id),
 * optionally limited to $kinds (null = every kind except error_log).
 */
function ti_fetch_active($conn, $broker, $uniId, $kinds = null) {
    $sql = "SELECT id, kind, severity, status, uni_id, account, symbol, side, action, strategy,
                   details, first_seen, TIMESTAMPDIFF(SECOND, first_seen, NOW()) AS age_sec
              FROM trade_incidents
             WHERE status IN ('open','retrying') AND broker = ? AND uni_id <=> ?";
    $types = 'ss';
    $params = [$broker, $uniId];
    if (is_array($kinds) && $kinds) {
        $sql .= ' AND kind IN (' . implode(',', array_fill(0, count($kinds), '?')) . ')';
        $types .= str_repeat('s', count($kinds));
        foreach ($kinds as $k) {
            $params[] = $k;
        }
    } else {
        $sql .= " AND kind <> 'error_log'";
    }
    $st = mysqli_prepare($conn, $sql);
    if (!$st) {
        return null;
    }
    mysqli_stmt_bind_param($st, $types, ...$params);
    if (!mysqli_stmt_execute($st)) {
        mysqli_stmt_close($st);
        return null;
    }
    $res = mysqli_stmt_get_result($st);
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $rows[] = $r;
    }
    mysqli_stmt_close($st);
    return $rows;
}

/**
 * INSERT ... ON DUPLICATE KEY UPDATE one incident. $f keys: broker, env_label, kind,
 * severity, uni_id, account, symbol, side, action, strategy, error_code, message,
 * details (JSON string|null), attempts, dedupe_key. Returns [id|null, error|null].
 *
 * A repeat bumps occurrences/last_seen, keeps the highest attempts count, takes the
 * new message/details/severity, and never downgrades 'retrying' back to 'open'.
 * Resolved rows can't be hit: their dedupe_key carries the "|r<id>" suffix.
 */
function ti_upsert($conn, array $f) {
    $sql = "INSERT INTO trade_incidents
              (broker, env_label, kind, severity, status, uni_id, account, symbol, side, action,
               strategy, error_code, message, details, attempts, occurrences, dedupe_key,
               first_seen, last_seen)
            VALUES (?, ?, ?, ?, 'open', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW(), NOW()) AS new
            ON DUPLICATE KEY UPDATE
              id          = LAST_INSERT_ID(trade_incidents.id),
              occurrences = trade_incidents.occurrences + 1,
              last_seen   = NOW(),
              attempts    = GREATEST(trade_incidents.attempts, new.attempts),
              message     = new.message,
              details     = COALESCE(new.details, trade_incidents.details),
              severity    = new.severity,
              status      = IF(trade_incidents.status = 'retrying', 'retrying', 'open'),
              env_label   = COALESCE(new.env_label, trade_incidents.env_label),
              account     = COALESCE(new.account, trade_incidents.account),
              action      = COALESCE(new.action, trade_incidents.action),
              strategy    = COALESCE(new.strategy, trade_incidents.strategy),
              error_code  = COALESCE(new.error_code, trade_incidents.error_code)";
    $st = mysqli_prepare($conn, $sql);
    if (!$st) {
        return [null, mysqli_error($conn)];
    }
    $attempts = (int) ($f['attempts'] ?? 0);
    mysqli_stmt_bind_param(
        $st,
        'sssssssssssssis',
        $f['broker'], $f['env_label'], $f['kind'], $f['severity'], $f['uni_id'], $f['account'],
        $f['symbol'], $f['side'], $f['action'], $f['strategy'], $f['error_code'], $f['message'],
        $f['details'], $attempts, $f['dedupe_key']
    );
    $ok = mysqli_stmt_execute($st);
    $err = $ok ? null : mysqli_stmt_error($st);
    $id = $ok ? (int) mysqli_insert_id($conn) : null;
    mysqli_stmt_close($st);
    return [$id, $err];
}

/** uni_id => [name, email] for every uni_id given, in ONE query. */
function ti_resolve_users($conn, array $uniIds) {
    $uniIds = array_values(array_unique(array_filter(array_map('strval', $uniIds), 'strlen')));
    if (!$uniIds) {
        return [];
    }
    $escaped = [];
    foreach ($uniIds as $u) {
        $escaped[] = "'" . mysqli_real_escape_string($conn, $u) . "'";
    }
    $out = [];
    $r = mysqli_query($conn, 'SELECT uni_id, name, email FROM user_credentials WHERE uni_id IN (' . implode(',', $escaped) . ')');
    if ($r) {
        while ($n = mysqli_fetch_assoc($r)) {
            $out[(string) $n['uni_id']] = ['name' => $n['name'], 'email' => $n['email']];
        }
        mysqli_free_result($r);
    }
    return $out;
}

/** Shape one DB row for the admin API. */
function ti_row_out(array $row, array $users = []) {
    $details = null;
    if (isset($row['details']) && $row['details'] !== null && $row['details'] !== '') {
        $d = json_decode($row['details'], true);
        $details = (json_last_error() === JSON_ERROR_NONE) ? $d : $row['details'];
    }
    $u = (string) ($row['uni_id'] ?? '');
    return [
        'id'              => (int) $row['id'],
        'broker'          => $row['broker'],
        'env_label'       => $row['env_label'],
        'kind'            => $row['kind'],
        'severity'        => $row['severity'],
        'status'          => $row['status'],
        'uni_id'          => $row['uni_id'],
        'user_name'       => isset($users[$u]) ? $users[$u]['name'] : null,
        'user_email'      => isset($users[$u]) ? $users[$u]['email'] : null,
        'account'         => $row['account'],
        'symbol'          => $row['symbol'],
        'side'            => $row['side'],
        'action'          => $row['action'],
        'strategy'        => $row['strategy'],
        'error_code'      => $row['error_code'],
        'message'         => $row['message'],
        'details'         => $details,
        'attempts'        => (int) $row['attempts'],
        'occurrences'     => (int) $row['occurrences'],
        'first_seen'      => $row['first_seen'],
        'last_seen'       => $row['last_seen'],
        'resolved_at'     => $row['resolved_at'],
        'resolved_by'     => $row['resolved_by'],
        'resolution_note' => $row['resolution_note'],
        'is_read'         => (int) $row['is_read'] === 1,
    ];
}

/** Load one incident by id, shaped for the admin API (null if missing). */
function ti_load_one($conn, $id) {
    $st = mysqli_prepare($conn, 'SELECT * FROM trade_incidents WHERE id = ? LIMIT 1');
    if (!$st) {
        return null;
    }
    mysqli_stmt_bind_param($st, 'i', $id);
    mysqli_stmt_execute($st);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
    mysqli_stmt_close($st);
    if (!$row) {
        return null;
    }
    $users = ti_resolve_users($conn, [(string) ($row['uni_id'] ?? '')]);
    return ti_row_out($row, $users);
}
