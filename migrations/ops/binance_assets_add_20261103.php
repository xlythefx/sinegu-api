<?php
/**
 * ONE-OFF for nexastaging (182) only, 2026-11-03.
 *
 * Adds the Binance assets in $NEW. The base size is the minimum order (about $5),
 * the max position is 3 x the base size, and leverage is 20x like every other
 * Binance row on 182. Run it on the box, from this folder:
 *
 *   php binance_assets_add_20261103.php status   # read-only: which tickers exist already
 *   php binance_assets_add_20261103.php apply
 *   php binance_assets_add_20261103.php undo     # delete the rows apply created
 *
 * A ticker that already has a Binance row is skipped and its row is left as it is.
 * ZECUSDT was on the list too, but 182 already holds it (#37), so it is not in $NEW.
 * Undo only deletes a row that still holds exactly what apply wrote, so a row edited
 * since then is kept. Every write gets an asset_audit row, so it shows in the
 * dashboard's change log.
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
const MAX_MULTIPLE = 3;    // max position = 3 x base size
const LEVERAGE = 20;

// ticker => base size (the minimum order, about $5 each).
$NEW = [
    'ARPAUSDT'   => '507',
    'MINAUSDT'   => '91',
    'STXUSDT'    => '16',
    'DIAUSDT'    => '36',
    'CTSIUSDT'   => '189',
    'NMRUSDT'    => '0.6',
    'CHZUSDT'    => '429',
    'XTZUSDT'    => '20.7',
    'STEEMUSDT'  => '108',
    'SPELLUSDT'  => '66667',
    'ALPINEUSDT' => '16.7',
    'ENJUSDT'    => '215',
];

$mode = $argv[1] ?? 'status';
if (!in_array($mode, ['status', 'apply', 'undo'], true)) {
    fwrite(STDERR, "Usage: php " . basename(__FILE__) . " status|apply|undo\n");
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

/** [base_size, max_increments] as the DB stores them. */
function sizes($base) {
    return [
        number_format((float) $base, 6, '.', ''),
        number_format(round((float) $base * MAX_MULTIPLE, 3), 3, '.', ''),
    ];
}

function holds(array $row, $base, $max) {
    return number_format((float) $row['base_size'], 6, '.', '') === $base
        && number_format((float) $row['max_increments'], 3, '.', '') === $max
        && (int) $row['leverage'] === LEVERAGE;
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
$select = mysqli_prepare($conn, "SELECT asset_id, base_size, max_increments, leverage, enabled FROM assets WHERE ticker = ? AND LOWER(TRIM(COALESCE(broker, ''))) = 'binance' ORDER BY asset_id FOR UPDATE");
$insert = mysqli_prepare($conn, "INSERT INTO assets (ticker, type, broker, side, asset_image, max_increments, base_size, leverage, enabled, created_at, updated_at) VALUES (?, 'Cryptocurrency', 'Binance', 'ALL', NULL, ?, ?, ?, 1, NOW(), NOW())");
$delete = mysqli_prepare($conn, 'DELETE FROM assets WHERE asset_id = ?');

foreach ($NEW as $ticker => $base_in) {
    [$base, $max] = sizes($base_in);
    $label = sprintf('%-11s', $ticker);

    mysqli_stmt_bind_param($select, 's', $ticker);
    mysqli_stmt_execute($select);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($select), MYSQLI_ASSOC);
    $now = implode('; ', array_map(function ($r) {
        return sprintf('#%d base %s, max %s, lev %s, %s', $r['asset_id'], fmt($r['base_size']), fmt($r['max_increments']), fmt($r['leverage']), $r['enabled'] ? 'on' : 'off');
    }, $rows));
    $ours = array_values(array_filter($rows, function ($r) use ($base, $max) {
        return holds($r, $base, $max);
    }));

    if ($mode === 'status') {
        echo "$label " . ($rows ? ($ours ? 'ADDED   ' : 'EXISTS  ') . "  $now" : "MISSING   would add base " . fmt($base) . ", max " . fmt($max) . ", lev " . LEVERAGE) . "\n";
        continue;
    }

    if ($mode === 'apply') {
        if ($rows) {
            echo "$label " . ($ours ? 'ok    already' : 'SKIP  exists, left alone:') . " $now\n";
            $counts[$ours ? 'already' : 'skipped']++;
            continue;
        }
        $lev = LEVERAGE;
        mysqli_stmt_bind_param($insert, 'sssi', $ticker, $max, $base, $lev);
        mysqli_stmt_execute($insert);
        $id = mysqli_insert_id($conn);
        asset_audit_log($conn, $id, $ticker, 'create', null, snapshot($conn, $id));
        printf("%s ADD   #%d base %s, max %s, lev %d\n", $label, $id, fmt($base), fmt($max), LEVERAGE);
        $counts['changed']++;
        continue;
    }

    // undo
    if (!$rows) {
        echo "$label ok    not there\n";
        $counts['already']++;
        continue;
    }
    if (!$ours) {
        echo "$label SKIP  not what apply wrote, left alone: $now\n";
        $counts['skipped']++;
        continue;
    }
    foreach ($ours as $r) {
        $id = (int) $r['asset_id'];
        $old = snapshot($conn, $id);
        mysqli_stmt_bind_param($delete, 'i', $id);
        mysqli_stmt_execute($delete);
        asset_audit_log($conn, $id, $ticker, 'delete', $old, null);
        printf("%s DEL   #%d\n", $label, $id);
    }
    $counts['changed']++;
}

mysqli_commit($conn);
if ($mode !== 'status') {
    printf("\n%s: %d changed, %d already done, %d skipped (of %d)\n", $mode, $counts['changed'], $counts['already'], $counts['skipped'], count($NEW));
}
