<?php
// Shared helpers for /v1/user-analytics endpoints.
// Auth (X-API-Key | Authorization: Bearer | ?api_key= | JSON body),
// date range parsing, JSON envelope responses.

if (!function_exists('ua_send_cors_headers')) {
    function ua_send_cors_headers(): void
    {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization');
    }
}

if (!function_exists('ua_handle_options')) {
    function ua_handle_options(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }
}

if (!function_exists('ua_require_method')) {
    function ua_require_method(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
            ua_json_err(405, 'Method not allowed (GET only)');
        }
    }
}

if (!function_exists('ua_json_ok')) {
    function ua_json_ok(array $payload): void
    {
        echo json_encode(array_merge(['success' => true], $payload));
        exit;
    }
}

if (!function_exists('ua_json_err')) {
    function ua_json_err(int $code, string $msg): void
    {
        http_response_code($code);
        echo json_encode(['success' => false, 'message' => $msg]);
        exit;
    }
}

if (!function_exists('ua_json_body')) {
    function ua_json_body(): array
    {
        static $cached = null;
        if ($cached !== null) return $cached;
        $raw = file_get_contents('php://input');
        if (!$raw) return ($cached = []);
        $j = json_decode($raw, true);
        return ($cached = is_array($j) ? $j : []);
    }
}

if (!function_exists('ua_require_api_key')) {
    function ua_require_api_key(): string
    {
        // 1) X-API-Key header
        $headers = function_exists('getallheaders') ? array_change_key_case(getallheaders() ?: [], CASE_LOWER) : [];
        if (!empty($headers['x-api-key'])) {
            $k = trim($headers['x-api-key']);
            if ($k !== '') return $k;
        }
        // 2) Authorization: Bearer
        $auth = $headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if ($auth && stripos($auth, 'bearer ') === 0) {
            $k = trim(substr($auth, 7));
            if ($k !== '') return $k;
        }
        // 3) Query string
        if (!empty($_GET['api_key'])) {
            $k = trim((string)$_GET['api_key']);
            if ($k !== '') return $k;
        }
        ua_json_err(401, 'API key is required (X-API-Key header, Authorization: Bearer, or ?api_key=)');
        return ''; // unreachable
    }
}

if (!function_exists('ua_parse_date_range')) {
    /**
     * Returns ['from' => ?string, 'to' => ?string, 'from_date' => ?string, 'to_date' => ?string,
     *          'has_range' => bool]. When neither from nor to is supplied, all bounds are null
     * and callers must skip date filtering (return all rows).
     */
    function ua_parse_date_range(): array
    {
        $from_in = isset($_GET['from']) ? trim((string)$_GET['from']) : '';
        $to_in   = isset($_GET['to'])   ? trim((string)$_GET['to'])   : '';

        if ($from_in === '' && $to_in === '') {
            return ['from' => null, 'to' => null, 'from_date' => null, 'to_date' => null, 'has_range' => false];
        }

        $today = new DateTime('today');
        $from  = $from_in !== '' ? DateTime::createFromFormat('Y-m-d', $from_in) : null;
        $to    = $to_in   !== '' ? DateTime::createFromFormat('Y-m-d', $to_in)   : null;

        if (($from_in !== '' && !$from) || ($to_in !== '' && !$to)) {
            ua_json_err(400, 'Invalid date format; expected YYYY-MM-DD for from/to');
        }
        // If only one bound supplied, anchor the other.
        if (!$from) $from = (clone $to)->modify('-29 days');
        if (!$to)   $to   = clone $today;

        if ($from > $to) {
            ua_json_err(400, 'from must be on or before to');
        }
        return [
            'from'      => $from->format('Y-m-d') . ' 00:00:00',
            'to'        => $to->format('Y-m-d')   . ' 23:59:59',
            'from_date' => $from->format('Y-m-d'),
            'to_date'   => $to->format('Y-m-d'),
            'has_range' => true,
        ];
    }
}

if (!function_exists('ua_filter_row')) {
    function ua_filter_row(array $row, array $allowed): array
    {
        $out = [];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $row)) $out[$col] = $row[$col];
        }
        return $out;
    }
}

if (!function_exists('ua_response_envelope')) {
    function ua_response_envelope(string $broker, string $endpoint, array $range, array $data, array $meta = []): array
    {
        return [
            'broker'   => $broker,
            'endpoint' => $endpoint,
            'range'    => $range['has_range']
                ? ['from' => $range['from_date'], 'to' => $range['to_date']]
                : null,
            'data'     => $data,
            'meta'     => $meta,
        ];
    }
}

if (!function_exists('ua_cumulative_series')) {
    function ua_cumulative_series(array $rows, string $bucketKey): array
    {
        $cum = 0.0;
        $out = [];
        foreach ($rows as $r) {
            $pnl = (float) ($r['pnl'] ?? 0);
            $cum += $pnl;
            $out[] = [
                'bucket'     => (string) $r[$bucketKey],
                'pnl'        => $pnl,
                'trades'     => (int) ($r['trades'] ?? 0),
                'cumulative' => $cum,
            ];
        }
        return $out;
    }
}

if (!function_exists('ua_period_pnl')) {
    /**
     * Returns ['pnl' => float, 'trades' => int, 'from' => YYYY-MM-DD, 'to' => YYYY-MM-DD]
     * for the last $modifier window (e.g. '-1 day', '-7 day', '-30 day').
     * Caller-supplied SQL must take exactly (?, ?) for from/to + whatever id binding.
     */
    function ua_period_window(string $modifier): array
    {
        $now  = new DateTime('now');
        $from = (clone $now)->modify($modifier);
        return [
            'from' => $from->format('Y-m-d H:i:s'),
            'to'   => $now->format('Y-m-d H:i:s'),
            'from_date' => $from->format('Y-m-d'),
            'to_date'   => $now->format('Y-m-d'),
        ];
    }
}
