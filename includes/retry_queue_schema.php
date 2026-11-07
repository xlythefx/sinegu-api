<?php
/**
 * capital_retry_queue schema detection for the flask/retry_*.php endpoints.
 *
 * Migration 094 adds `broker` (default 'capital') and `payload` (JSON) so Binance and
 * MEXC can share the queue. The API can land on a box before that migration runs, and
 * CapitalFlask must keep working when it does — so each endpoint asks once which
 * columns exist and falls back to the original capital-only SQL without them.
 *
 * Without the broker column a non-capital broker is REFUSED (never silently written
 * as/claimed from capital rows): CapitalFlask's worker would otherwise execute a
 * Binance job against Capital.com accounts.
 */

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit;
}

const RETRY_QUEUE_BROKERS = ['capital', 'binance', 'mexc', 'ig', 'bybit'];

/** ['broker' => bool, 'payload' => bool] for capital_retry_queue (one information_schema query). */
function retry_queue_columns($conn) {
    $out = ['broker' => false, 'payload' => false];
    $r = mysqli_query(
        $conn,
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'capital_retry_queue'
            AND COLUMN_NAME IN ('broker', 'payload')"
    );
    if ($r) {
        while ($row = mysqli_fetch_assoc($r)) {
            $out[strtolower($row['COLUMN_NAME'])] = true;
        }
        mysqli_free_result($r);
    }
    return $out;
}

/** Normalized broker from input/query; default 'capital'. Returns null when invalid. */
function retry_queue_broker($raw) {
    if ($raw === null || $raw === '') {
        return 'capital';
    }
    $b = strtolower(trim((string) $raw));
    if ($b === '') {
        return 'capital';
    }
    return in_array($b, RETRY_QUEUE_BROKERS, true) ? $b : null;
}

/** JSON error for "non-capital broker but migration 094 not applied", then exit. */
function retry_queue_require_broker_column($conn, array $cols, $broker) {
    if ($broker !== 'capital' && !$cols['broker']) {
        if ($conn) {
            mysqli_close($conn);
        }
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'error'   => "capital_retry_queue has no broker column; refusing to queue/claim '$broker' rows",
            'hint'    => 'run migration 094_retry_queue_broker.sql',
        ]);
        exit;
    }
}
