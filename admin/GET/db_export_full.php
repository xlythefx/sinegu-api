<?php
/**
 * Stream a full-database SQL dump (admin Database page → "Download database").
 * GET /admin/GET/db_export_full.php
 *      [&data=1|0]        1 (default) = schema + rows, 0 = schema only
 *      [&skip_logs=1]     exclude high-volume *_logs tables
 *      [&exclude=a,b,c]   exclude named tables
 *      [&gzip=1|0]        1 (default) = gzip the stream (.sql.gz)
 *
 * Why this exists alongside export_database.php: that one builds the whole dump
 * in a temp file with a buffered mysqli result, so a table like api_logs (7.8M
 * rows) exhausts PHP's memory long before it finishes. This streams instead:
 *   - MYSQLI_USE_RESULT      -> rows arrive one at a time, never all in memory
 *   - output straight to the client, flushed per batch, no temp file
 *   - extended INSERTs (batched by byte size) instead of one INSERT per row
 *   - optional gzip, which typically shrinks a SQL dump ~8-10x on the wire
 *
 * On failure BEFORE streaming starts, returns JSON { success:false, message, detail }.
 * Once bytes are flowing an error can only be reported as a SQL comment in-stream,
 * so the tail of the file is the place to look if a dump looks short.
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);
set_time_limit(0);
ignore_user_abort(false);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function jsonError($message, $detail = null, $code = 400) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message, 'detail' => $detail]);
    exit;
}

require_once __DIR__ . '/../../conn.php';
if (!$conn) jsonError('Database connection failed', isset($conn_error) ? $conn_error : 'Check conn.php', 500);

$withData = !isset($_GET['data']) || $_GET['data'] !== '0';
$skipLogs = isset($_GET['skip_logs']) && $_GET['skip_logs'] === '1';
$useGzip  = !isset($_GET['gzip']) || $_GET['gzip'] !== '0';
$excludeParam = isset($_GET['exclude']) ? trim($_GET['exclude']) : '';

$excluded = [];
foreach (array_filter(array_map('trim', explode(',', $excludeParam))) as $t) {
    $excluded[$t] = true;
}

// Enumerate tables first — this is also the last point at which we can still
// return a JSON error instead of a half-written download.
$tables = [];
$res = mysqli_query($conn, 'SHOW TABLES');
if (!$res) jsonError('Could not list tables', mysqli_error($conn), 500);
while ($row = mysqli_fetch_array($res)) $tables[] = $row[0];
mysqli_free_result($res);
if (!$tables) jsonError('Database has no tables', null, 500);

if ($skipLogs) {
    foreach ($tables as $t) {
        // api_logs, capital_api_logs, ig_api_logs, … anything ending in _logs/logs.
        if (preg_match('/_logs$/i', $t) || strcasecmp($t, 'logs') === 0) $excluded[$t] = true;
    }
}
$selected = array_values(array_filter($tables, function ($t) use ($excluded) {
    return !isset($excluded[$t]);
}));
if (!$selected) jsonError('Every table was excluded', null, 400);

$dbName = mysqli_fetch_row(mysqli_query($conn, 'SELECT DATABASE()'))[0];
$stamp = date('Y-m-d_His');
$filename = ($dbName ?: 'database') . "_{$stamp}.sql" . ($useGzip ? '.gz' : '');

// Drop any buffering the stack put in place so bytes leave as they're produced.
while (ob_get_level() > 0) ob_end_clean();

header('Content-Type: ' . ($useGzip ? 'application/gzip' : 'application/sql') . '; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('X-Accel-Buffering: no');   // nginx: don't buffer this response
header('Cache-Control: no-store');

// NOTE: deliberately NOT Content-Encoding: gzip. That would make the browser
// silently inflate the body, and the saved .sql.gz would actually be plain text.
// Serving it as an application/gzip *body* keeps the downloaded file valid.
$deflate = $useGzip ? deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]) : null;

// Flush on accumulated volume, NOT per table/statement. Buffering is off (so a
// multi-GB dump streams instead of piling up in nginx), which means every flush()
// becomes its own TCP write — and on a long-haul link a flush-per-table turns a
// small dump into dozens of latency-bound round trips. Measured on the Ubuntu box:
// schema-only went from >120s to well under a second once flushing was batched.
$FLUSH_EVERY = 256 * 1024;
$sinceFlush = 0;

$emit = function ($chunk) use ($deflate, &$sinceFlush, $FLUSH_EVERY) {
    echo $deflate ? deflate_add($deflate, $chunk, ZLIB_NO_FLUSH) : $chunk;
    $sinceFlush += strlen($chunk);
    if ($sinceFlush >= $FLUSH_EVERY) {
        flush();
        $sinceFlush = 0;
    }
};
$finish = function () use ($deflate) {
    if ($deflate) echo deflate_add($deflate, '', ZLIB_FINISH);
    flush();
};

$emit("-- Sinegu database dump\n");
$emit("-- database : {$dbName}\n");
$emit('-- generated: ' . date('Y-m-d H:i:s') . "\n");
$emit('-- tables   : ' . count($selected) . ' of ' . count($tables) . "\n");
if ($excluded) $emit('-- excluded : ' . implode(', ', array_keys($excluded)) . "\n");
if (!$withData) $emit("-- SCHEMA ONLY (no rows)\n");
$emit("\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");

// Roughly one MySQL packet per statement; well under the default 64MB max_allowed_packet.
$MAX_STATEMENT_BYTES = 512 * 1024;

foreach ($selected as $table) {
    $safe = str_replace('`', '``', $table);

    $createRes = mysqli_query($conn, "SHOW CREATE TABLE `$safe`");
    if (!$createRes) {
        $emit("-- !! skipped `$table`: " . mysqli_error($conn) . "\n\n");
        continue;
    }
    $createRow = mysqli_fetch_row($createRes);
    mysqli_free_result($createRes);

    $emit("--\n-- Table: `$table`\n--\n");
    $emit("DROP TABLE IF EXISTS `$safe`;\n");
    $emit($createRow[1] . ";\n\n");

    if (!$withData) continue;

    // Unbuffered: rows stream from MySQL one at a time. With the default buffered
    // query the whole table would be materialised in PHP first — fatal on api_logs.
    $sel = mysqli_query($conn, "SELECT * FROM `$safe`", MYSQLI_USE_RESULT);
    if (!$sel) {
        $emit("-- !! no data for `$table`: " . mysqli_error($conn) . "\n\n");
        continue;
    }

    $cols = [];
    while ($f = mysqli_fetch_field($sel)) $cols[] = '`' . str_replace('`', '``', $f->name) . '`';
    $colList = implode(',', $cols);

    $prefix = "INSERT INTO `$safe` ($colList) VALUES\n";
    $buffer = '';
    $pending = 0;
    $rows = 0;

    while ($row = mysqli_fetch_row($sel)) {
        $vals = [];
        foreach ($row as $v) {
            $vals[] = $v === null ? 'NULL' : "'" . mysqli_real_escape_string($conn, $v) . "'";
        }
        $tuple = '(' . implode(',', $vals) . ')';

        if ($pending === 0) {
            $buffer = $prefix . $tuple;
        } else {
            $buffer .= ",\n" . $tuple;
        }
        $pending++;
        $rows++;

        if (strlen($buffer) >= $MAX_STATEMENT_BYTES) {
            $emit($buffer . ";\n");
            flush();
            $buffer = '';
            $pending = 0;
        }
    }
    if ($pending > 0) $emit($buffer . ";\n");
    mysqli_free_result($sel);

    $emit("-- rows: $rows\n\n");
    flush();
}

$emit("SET FOREIGN_KEY_CHECKS = 1;\n");
$emit("-- dump complete " . date('Y-m-d H:i:s') . "\n");
$finish();
exit;
