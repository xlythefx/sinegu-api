<?php
/**
 * Sinegu migration runner.
 *
 * Usage (CLI):
 *   php migrate.php status     # list pending vs applied
 *   php migrate.php run        # apply all pending NNN_*.sql files in order
 *   php migrate.php baseline   # mark every existing .sql file as applied WITHOUT running it
 *                              # (use once on a DB that already has the schema)
 *   php migrate.php apply <file.sql>   # force-apply a single file (also records it)
 *
 * Tracks applied files in table `schema_migrations(filename, applied_at)`.
 * Only files matching ^NNN_.*\.sql$ are considered migrations.
 */

require_once __DIR__ . '/../conn.php';

if (PHP_SAPI === 'cli' && !$conn) {
    fwrite(STDERR, "DB connection failed: " . ($conn_error ?? 'unknown') . PHP_EOL);
    exit(1);
}

function ensure_table($conn) {
    $sql = "CREATE TABLE IF NOT EXISTS schema_migrations (
        filename VARCHAR(255) NOT NULL PRIMARY KEY,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!mysqli_query($conn, $sql)) {
        fwrite(STDERR, "Failed to create schema_migrations: " . mysqli_error($conn) . PHP_EOL);
        exit(1);
    }
}

function list_files() {
    $files = glob(__DIR__ . '/*.sql');
    $out = [];
    foreach ($files as $path) {
        $name = basename($path);
        if (preg_match('/^\d{3}_.*\.sql$/', $name)) {
            $out[] = $name;
        }
    }
    sort($out, SORT_STRING);
    return $out;
}

function applied_set($conn) {
    $set = [];
    $r = mysqli_query($conn, "SELECT filename FROM schema_migrations");
    while ($row = mysqli_fetch_assoc($r)) {
        $set[$row['filename']] = true;
    }
    return $set;
}

function run_file($conn, $name) {
    $path = __DIR__ . '/' . $name;
    $sql = file_get_contents($path);
    if ($sql === false) {
        return [false, "cannot read $path"];
    }
    if (trim($sql) === '') {
        return [true, 'empty file, skipped execution'];
    }
    if (!mysqli_multi_query($conn, $sql)) {
        return [false, mysqli_error($conn)];
    }
    // drain results to keep the connection clean
    do {
        if ($res = mysqli_store_result($conn)) {
            mysqli_free_result($res);
        }
        if (!mysqli_more_results($conn)) break;
        if (!mysqli_next_result($conn)) {
            return [false, mysqli_error($conn)];
        }
    } while (true);
    return [true, 'ok'];
}

function record($conn, $name) {
    $stmt = mysqli_prepare($conn, "INSERT IGNORE INTO schema_migrations (filename) VALUES (?)");
    mysqli_stmt_bind_param($stmt, 's', $name);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

if (PHP_SAPI !== 'cli') {
    // included from a web endpoint — just expose the helpers, don't run anything
    return;
}

header('Content-Type: text/plain');

$cmd = $argv[1] ?? 'status';
$arg = $argv[2] ?? null;

ensure_table($conn);
$all = list_files();
$applied = applied_set($conn);

switch ($cmd) {
    case 'status': {
        $pending = [];
        foreach ($all as $f) if (!isset($applied[$f])) $pending[] = $f;
        echo "Total migration files: " . count($all) . PHP_EOL;
        echo "Applied: " . count($applied) . PHP_EOL;
        echo "Pending: " . count($pending) . PHP_EOL;
        if ($pending) {
            echo PHP_EOL . "Pending files:" . PHP_EOL;
            foreach ($pending as $f) echo "  - $f" . PHP_EOL;
        }
        break;
    }
    case 'baseline': {
        $marked = 0;
        foreach ($all as $f) {
            if (!isset($applied[$f])) { record($conn, $f); $marked++; }
        }
        echo "Baselined $marked file(s) as applied (no SQL executed)." . PHP_EOL;
        break;
    }
    case 'run': {
        $ran = 0; $failed = null;
        foreach ($all as $f) {
            if (isset($applied[$f])) continue;
            echo "[run] $f ... ";
            [$ok, $msg] = run_file($conn, $f);
            if ($ok) {
                record($conn, $f);
                echo "OK" . PHP_EOL;
                $ran++;
            } else {
                echo "FAILED: $msg" . PHP_EOL;
                $failed = $f;
                break;
            }
        }
        echo PHP_EOL . "Applied: $ran" . PHP_EOL;
        if ($failed) {
            echo "Halted at: $failed (fix and re-run)" . PHP_EOL;
            exit(2);
        }
        break;
    }
    case 'apply': {
        if (!$arg) { fwrite(STDERR, "usage: php migrate.php apply <file.sql>" . PHP_EOL); exit(1); }
        if (!in_array($arg, $all, true) && !file_exists(__DIR__ . '/' . $arg)) {
            fwrite(STDERR, "file not found: $arg" . PHP_EOL); exit(1);
        }
        echo "[apply] $arg ... ";
        [$ok, $msg] = run_file($conn, $arg);
        if ($ok) { record($conn, $arg); echo "OK" . PHP_EOL; }
        else { echo "FAILED: $msg" . PHP_EOL; exit(2); }
        break;
    }
    default:
        fwrite(STDERR, "unknown command: $cmd" . PHP_EOL);
        fwrite(STDERR, "usage: php migrate.php [status|run|baseline|apply <file>]" . PHP_EOL);
        exit(1);
}

mysqli_close($conn);
