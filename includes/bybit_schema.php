<?php
/**
 * bybit_tables_ready($conn) — true once migrations 098-101 have created the bybit_* tables.
 *
 * deploy-api lands the PHP on a box before `migrate.php run` creates the tables, and
 * conn.php leaves mysqli in exception mode (PHP 8.1+ default), so one query against a
 * missing bybit_* table is an uncaught exception that 500s the WHOLE endpoint.
 * Multi-broker endpoints (admin lists, stats, summaries) therefore skip their Bybit block
 * until this returns true, instead of taking Capital / Binance / IG / MEXC down with it.
 * Bybit-only endpoints don't need it: the dashboard's fetchers already treat a failed
 * request as "no Bybit data".
 */

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit;
}

if (!function_exists('bybit_tables_ready')) {
    function bybit_tables_ready($conn) {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        $ready = false;
        try {
            $r = mysqli_query(
                $conn,
                "SELECT COUNT(*) AS c FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME IN ('bybit_accounts', 'bybit_positions', 'bybit_pastpositions', 'bybit_transactions')"
            );
            if ($r) {
                $row = mysqli_fetch_assoc($r);
                $ready = $row && (int) $row['c'] === 4;
                mysqli_free_result($r);
            }
        } catch (Throwable $e) {
            $ready = false;
        }
        return $ready;
    }
}
