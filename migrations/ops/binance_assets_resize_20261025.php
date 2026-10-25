<?php
/**
 * ONE-OFF for nexastaging (182) only, 2026-10-25.
 *
 * Every Binance asset: base size cut by 30%, max position set to 3 x the new base
 * size, leverage 20x. Run it on the box, from this folder:
 *
 *   php binance_assets_resize_20261025.php status   # read-only: what each row holds now
 *   php binance_assets_resize_20261025.php apply
 *   php binance_assets_resize_20261025.php undo     # put back the values in $ORIGINAL
 *
 * $ORIGINAL is the 56 rows as they stood on 182 right before the change (one typo
 * corrected, see #72), so undo needs nothing but this file. A row is only written
 * while it still holds what that direction expects (apply: the original, undo: the
 * resized value). A row edited by hand in between is listed and left alone; add
 * `--force` to write it anyway, and `--only=72,80` to touch just those asset_ids.
 * Every write gets an asset_audit row, so it shows in the dashboard's change log.
 *
 * This ships with deploy-api to every box, hence the CLI-only and box checks.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../conn.php';
require_once __DIR__ . '/../../includes/asset_audit.php';

const STAGING_FRONTEND = 'nexastaging.com';
const BASE_FACTOR = 0.7;   // -30%
const MAX_MULTIPLE = 3;    // max position = 3 x base size
const LEVERAGE = 20;

// asset_id => [ticker, base_size, max_increments, leverage], read off 182 on 2026-10-25.
$ORIGINAL = [
     4 => ['ALGOUSDT',      '10000.000000',   '30000.000',    null],
     7 => ['AVAXUSDT',      '100.000000',     '300.000',      null],
    10 => ['BTCUSDT',       '0.010000',       '0.050',        null],
    13 => ['DASHUSDT',      '0.440000',       '1.000',        null],
    14 => ['DOGEUSDT',      '8000.000000',    '24000.000',    null],
    16 => ['ETHUSDT',       '0.050000',       '1.000',        null],
    19 => ['FETUSDT',       '3000.000000',    '9000.000',     10],
    20 => ['FLOWUSDT',      '100.000000',     '300.000',      null],
    27 => ['LINKUSDT',      '15.000000',      '45.000',       null],
    29 => ['LTCUSDT',       '18.000000',      '54.000',       null],
    34 => ['SUIUSDT',       '50.000000',      '150.000',      null],
    35 => ['TAOUSDT',       '0.300000',       '0.900',        null],
    37 => ['ZECUSDT',       '2.000000',       '6.000',        null],
    38 => ['XLMUSDT',       '655.000000',     '1965.000',     null],
    39 => ['NEARUSDT',      '400.000000',     '1200.000',     null],
    41 => ['HBARUSDT',      '1300.000000',    '3900.000',     null],
    42 => ['RENDERUSDT',    '200.000000',     '600.000',      null],
    43 => ['ICPUSDT',       '20.000000',      '60.000',       null],
    44 => ['SOLUSDT',       '1.700000',       '5.100',        null],
    45 => ['ICPUSDT',       '7.000000',       '21.000',       null],
    46 => ['ADAUSDT',       '5000.000000',    '15000.000',    null],
    47 => ['AAVEUSDT',      '8.000000',       '24.000',       null],
    48 => ['SHIBUSDT',      '35000.000000',   '105000.000',   null],
    49 => ['DOGEUSDT',      '1300.000000',    '6500.000',     null],
    50 => ['DOTUSDT',       '150.000000',     '750.000',      null],
    51 => ['ATOMUSDT',      '100.000000',     '300.000',      null],
    52 => ['BICOUSDT',      '5000.000000',    '15000.000',    null],
    53 => ['HOTUSDT',       '300000.000000',  '900000.000',   null],
    54 => ['VETUSDT',       '15000.000000',   '45000.000',    null],
    55 => ['XMRUSDT',       '1.000000',       '3.000',        null],
    56 => ['QNTUSDT',       '5.000000',       '15.000',       10],
    59 => ['UMAUSDT',       '1000.000000',    '3000.000',     10],
    60 => ['VTHOUSDT',      '800000.000000',  '2400000.000',  null],
    61 => ['HIVEUSDT',      '7000.000000',    '21000.000',    null],
    62 => ['RAREUSDT',      '50000.000000',   '150000.000',   null],
    63 => ['TWTUSDT',       '1000.000000',    '3000.000',     null],
    64 => ['DEXEUSDT',      '300.000000',     '900.000',      null],
    65 => ['PAXGUSDT',      '0.200000',       '0.600',        null],
    66 => ['ROSEUSDT',      '80000.000000',   '240000.000',   null],
    67 => ['POWRUSDT',      '8000.000000',    '24000.000',    null],
    68 => ['ASRUSDT',       '700.000000',     '2100.000',     null],
    69 => ['OGUSDT',        '200.000000',     '600.000',      null],
    70 => ['LRCUSDT',       '60000.000000',   '180000.000',   null],
    71 => ['FLUXUSDT',      '10000.000000',   '30000.000',    10],
    // 182 held base 70000 here, a typo for 7000 (its max was already 21000 = 3 x 7000),
    // confirmed 2026-10-26. Undo restores 7000; the literal row is kept in
    // /root/backups/2026-10-25-binance-assets/assets_binance_before.sql.
    72 => ['ACHUSDT',       '7000.000000',    '21000.000',    null],
    73 => ['RVNUSDT',       '200000.000000',  '600000.000',   null],
    74 => ['ARUSDT',        '50.000000',      '150.000',      null],
    75 => ['SANTOSUSDT',    '1000.000000',    '3000.000',     null],
    76 => ['1000SHIBUSDT',  '100000.000000',  '300000.000',   null],
    77 => ['EGLDUSDT',      '150.000000',     '450.000',      25],
    78 => ['KAVAUSDT',      '10000.000000',   '30000.000',    null],
    79 => ['INJUSDT',       '60.000000',      '180.000',      null],
    80 => ['BATUSDT',       '3000.000000',    '9000.000',     null],
    81 => ['IOTXUSDT',      '100000.000000',  '300000.000',   null],
    82 => ['IOTAUSDT',      '10000.000000',   '30000.000',    null],
    83 => ['COTIUSDT',      '50000.000000',   '150000.000',   null],
];

$mode = $argv[1] ?? 'status';
$force = in_array('--force', $argv, true);
$only = null;
foreach ($argv as $arg) {
    if (strpos($arg, '--only=') === 0) {
        $only = array_map('intval', explode(',', substr($arg, 7)));
    }
}
if (!in_array($mode, ['status', 'apply', 'undo'], true)) {
    fwrite(STDERR, "Usage: php " . basename(__FILE__) . " status|apply|undo [--force] [--only=ID,ID]\n");
    exit(1);
}

$frontend = (string) env('FRONTEND_BASE_URL', '');
if (strpos($frontend, STAGING_FRONTEND) === false) {
    fwrite(STDERR, "Refusing: this is for " . STAGING_FRONTEND . " only, and FRONTEND_BASE_URL here is '$frontend'.\n");
    exit(1);
}
if (!$conn) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/** [base_size, max_increments, leverage] after the change, as the DB would store them. */
function resized(array $orig) {
    $base = round((float) $orig[1] * BASE_FACTOR, 6);
    return [
        number_format($base, 6, '.', ''),
        number_format(round($base * MAX_MULTIPLE, 3), 3, '.', ''),
        LEVERAGE,
    ];
}

function holds(array $row, $base, $max, $leverage) {
    return number_format((float) $row['base_size'], 6, '.', '') === number_format((float) $base, 6, '.', '')
        && number_format((float) $row['max_increments'], 3, '.', '') === number_format((float) $max, 3, '.', '')
        && ($row['leverage'] === null ? null : (int) $row['leverage']) === $leverage;
}

/** asset_audit_fetch_snapshot() plus leverage, which that helper leaves out. */
function snapshot($conn, $asset_id) {
    $snap = asset_audit_fetch_snapshot($conn, $asset_id);
    if ($snap === null) {
        return null;
    }
    $res = mysqli_query($conn, 'SELECT leverage FROM assets WHERE asset_id = ' . (int) $asset_id);
    $lev = mysqli_fetch_row($res)[0];
    $snap['leverage'] = $lev === null ? null : (int) $lev;
    return $snap;
}

function fmt($v) {
    if ($v === null) {
        return 'none';
    }
    $s = (string) $v;
    return strpos($s, '.') === false ? $s : rtrim(rtrim($s, '0'), '.');
}

$counts = ['changed' => 0, 'already' => 0, 'skipped' => 0];
mysqli_begin_transaction($conn);
$select = mysqli_prepare($conn, 'SELECT ticker, broker, side, base_size, max_increments, leverage FROM assets WHERE asset_id = ? FOR UPDATE');
$update = mysqli_prepare($conn, 'UPDATE assets SET base_size = ?, max_increments = ?, leverage = ? WHERE asset_id = ?');

foreach ($ORIGINAL as $id => $orig) {
    if ($only !== null && !in_array($id, $only, true)) {
        continue;
    }
    [$ticker, $base0, $max0, $lev0] = $orig;
    [$base1, $max1, $lev1] = resized($orig);

    mysqli_stmt_bind_param($select, 'i', $id);
    mysqli_stmt_execute($select);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($select));
    $label = sprintf('#%-3d %-13s', $id, $ticker);

    if (!$row || $row['ticker'] !== $ticker || strtolower(trim((string) $row['broker'])) !== 'binance') {
        echo "$label SKIP  row is gone or no longer this Binance asset\n";
        $counts['skipped']++;
        continue;
    }
    $now = sprintf('base %s, max %s, lev %s', fmt($row['base_size']), fmt($row['max_increments']), fmt($row['leverage']));
    $isOrig = holds($row, $base0, $max0, $lev0);
    $isNew = holds($row, $base1, $max1, $lev1);

    if ($mode === 'status') {
        echo "$label " . ($isNew ? 'RESIZED ' : ($isOrig ? 'ORIGINAL' : 'EDITED  ')) . "  $now\n";
        continue;
    }

    if ($mode === 'apply') {
        $from_ok = $isOrig || $force;
        $done = $isNew;
        $to = [$base1, $max1, $lev1];
    } else {
        $from_ok = $isNew || $force;
        $done = $isOrig;
        $to = [$base0, $max0, $lev0];
    }
    if ($done) {
        echo "$label ok    already $now\n";
        $counts['already']++;
        continue;
    }
    if (!$from_ok) {
        echo "$label SKIP  edited by hand since ($now) -- add --force to write it anyway\n";
        $counts['skipped']++;
        continue;
    }

    $old = snapshot($conn, $id);
    [$base, $max, $lev] = $to;
    mysqli_stmt_bind_param($update, 'ssii', $base, $max, $lev, $id);
    mysqli_stmt_execute($update);
    asset_audit_log($conn, $id, $ticker, 'update', $old, snapshot($conn, $id));
    printf("%s %-5s %s  ->  base %s, max %s, lev %s\n", $label, strtoupper($mode), $now, fmt($base), fmt($max), fmt($lev));
    $counts['changed']++;
}

mysqli_commit($conn);
if ($mode !== 'status') {
    printf("\n%s: %d changed, %d already done, %d skipped (of %d)\n", $mode, $counts['changed'], $counts['already'], $counts['skipped'], $only === null ? count($ORIGINAL) : count($only));
}
