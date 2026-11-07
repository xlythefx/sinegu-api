<?php
/**
 * Admin → Add New Asset: what Binance and our smallest trading account say about a
 * coin's size, so the form can show it in dollars before the asset is saved.
 *
 * GET /admin/GET/binance_sizing_check.php?ticker=LTCUSDT     header X-Admin-Password
 *
 * -> { success, ticker, symbol,
 *      binance: { listed, status, price, step_size, min_qty, min_notional } | null,
 *      binance_error: string | null,
 *      accounts: { count, smallest_balance, smallest_name, demo_only },
 *      checked_at }
 *
 * The dollar maths (minimum order, full-stack margin, the suggested base size) is done
 * by the form (src/lib/binance-sizing.ts), so changing the base size or the 1×/2×/3×
 * multiple never costs another request.
 *
 * Where each figure comes from:
 *  - symbol: the same mapping binance-flask uses (assets_api._to_binance_symbol);
 *  - filters: GET /fapi/v1/exchangeInfo (weight 1, ~1.2 MB), cached on disk for an hour
 *    and served stale if Binance cannot be reached;
 *  - price: GET /fapi/v1/premiumIndex?symbol= (weight 1), the mark price;
 *  - accounts: the ones binance-flask would open a position on — enabled, not deleted,
 *    USDT, initial_deposit >= 1000 (its deposit gate), balance > 0. Live accounts
 *    only, unless there are none (staging), then the demo ones, flagged demo_only.
 *
 * Behind the admin check because it names the smallest account and its balance.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Admin-Password');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../../includes/admin_action_auth.php';
requireAdminActionPassword(null);

require_once __DIR__ . '/../../conn.php';

const BSC_FAPI_BASE = 'https://fapi.binance.com';
const BSC_EXINFO_TTL = 3600;
/** binance-flask's deposit gate (routes/webhook.py: initial_deposit < 1000 -> skipped). */
const BSC_MIN_DEPOSIT = 1000;

/** Mirrors binance-flask assets_api.TICKER_TO_BINANCE + _to_binance_symbol. */
function bsc_symbol(string $ticker): string {
    $t = strtoupper(trim($ticker));
    $aliases = ['BTCUSD' => 'BTCUSDT', 'ETHUSD' => 'ETHUSDT', 'GOLD' => 'XAUUSDT', 'TAOUSD' => 'TAOUSDT', 'AAPL' => 'AAPLUSDT'];
    if ($t === '') return '';
    if (isset($aliases[$t])) return $aliases[$t];
    if (substr($t, -4) === 'USDT') return $t;
    if (substr($t, -3) === 'USD') return substr($t, 0, -3) . 'USDT';
    return $t . 'USDT';
}

/** GET a Binance public endpoint; decoded JSON or null. TLS is always verified. */
function bsc_get(string $path, array $query = []): ?array {
    $url = BSC_FAPI_BASE . $path . ($query ? '?' . http_build_query($query) : '');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    // WAMP's PHP ships without a CA bundle; the boxes have the system one.
    foreach ([
        __DIR__ . '/../../vendor/stripe/stripe-php/lib/data/ca-certificates.crt',
        '/etc/ssl/certs/ca-certificates.crt',
        '/etc/pki/tls/certs/ca-bundle.crt',
    ] as $ca) {
        if (is_file($ca)) {
            curl_setopt($ch, CURLOPT_CAINFO, $ca);
            break;
        }
    }
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $status !== 200) return null;
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

/**
 * {symbol: {status, step_size, min_qty, min_notional}} for every USDⓈ-M symbol.
 * Fresh for an hour; a failed refresh serves the last good copy, however old.
 */
function bsc_filters(): ?array {
    $file = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'sinegu_binance_exinfo.json';
    $cached = null;
    if (is_file($file)) {
        $cached = json_decode((string) @file_get_contents($file), true);
        if (is_array($cached) && (time() - (int) @filemtime($file)) < BSC_EXINFO_TTL) {
            return $cached;
        }
    }
    $info = bsc_get('/fapi/v1/exchangeInfo');
    if (!$info || empty($info['symbols'])) {
        return is_array($cached) ? $cached : null;
    }
    $out = [];
    foreach ($info['symbols'] as $s) {
        $row = ['status' => $s['status'] ?? null, 'step_size' => null, 'min_qty' => null, 'min_notional' => null];
        $lotMin = null;
        $marketMin = null;
        foreach (($s['filters'] ?? []) as $f) {
            $type = $f['filterType'] ?? '';
            if ($type === 'LOT_SIZE') {
                $row['step_size'] = $f['stepSize'] ?? null;
                $lotMin = isset($f['minQty']) ? (float) $f['minQty'] : null;
            } elseif ($type === 'MARKET_LOT_SIZE') {
                $marketMin = isset($f['minQty']) ? (float) $f['minQty'] : null;
            } elseif ($type === 'MIN_NOTIONAL') {
                $row['min_notional'] = isset($f['notional']) ? (float) $f['notional'] : null;
            }
        }
        // The bot sends MARKET orders, so the stricter of the two minimums applies.
        $row['min_qty'] = max((float) $lotMin, (float) $marketMin) ?: null;
        if (!empty($s['symbol'])) $out[$s['symbol']] = $row;
    }
    @file_put_contents($file, json_encode($out), LOCK_EX);
    return $out;
}

$ticker = strtoupper(trim((string) ($_GET['ticker'] ?? '')));
if ($ticker === '' || !preg_match('/^[A-Z0-9._-]{1,32}$/', $ticker)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Give a ticker, e.g. LTCUSDT.']);
    exit;
}
$symbol = bsc_symbol($ticker);

// ── Binance: is it listed, its order minimums, its price ────────────────────────
$binance = null;
$binanceError = null;
$filters = bsc_filters();
if ($filters === null) {
    $binanceError = 'Could not reach Binance to read the coin’s order rules.';
} elseif (!isset($filters[$symbol])) {
    $binance = ['listed' => false, 'status' => null, 'price' => null, 'step_size' => null, 'min_qty' => null, 'min_notional' => null];
} else {
    $f = $filters[$symbol];
    $mark = bsc_get('/fapi/v1/premiumIndex', ['symbol' => $symbol]);
    $price = isset($mark['markPrice']) ? (float) $mark['markPrice'] : 0.0;
    if ($price <= 0) $binanceError = 'Could not read the coin’s price from Binance.';
    $binance = [
        'listed' => true,
        'status' => $f['status'],
        'price' => $price > 0 ? $price : null,
        'step_size' => $f['step_size'],
        'min_qty' => $f['min_qty'],
        'min_notional' => $f['min_notional'],
    ];
}

// ── Our accounts: the smallest one binance-flask would open a position on ───────
$accounts = ['count' => 0, 'smallest_balance' => null, 'smallest_name' => null, 'demo_only' => false];
if ($conn) {
    mysqli_report(MYSQLI_REPORT_OFF);
    $sql = "SELECT name, balance, demo FROM binance_accounts
            WHERE enabled = 1 AND deleted_at IS NULL
              AND UPPER(TRIM(currency_type)) = 'USDT'
              AND initial_deposit >= " . BSC_MIN_DEPOSIT . "
              AND balance > 0
            ORDER BY balance ASC";
    $res = mysqli_query($conn, $sql);
    if ($res) {
        $live = [];
        $demo = [];
        while ($r = mysqli_fetch_assoc($res)) {
            if ((int) $r['demo'] === 1) $demo[] = $r; else $live[] = $r;
        }
        mysqli_free_result($res);
        $pool = $live ?: $demo;
        if ($pool) {
            $accounts = [
                'count' => count($pool),
                'smallest_balance' => round((float) $pool[0]['balance'], 2),
                'smallest_name' => (string) $pool[0]['name'],
                'demo_only' => !$live,
            ];
        }
    }
    mysqli_close($conn);
}

echo json_encode([
    'success' => true,
    'ticker' => $ticker,
    'symbol' => $symbol,
    'binance' => $binance,
    'binance_error' => $binanceError,
    'accounts' => $accounts,
    'checked_at' => gmdate('c'),
]);
