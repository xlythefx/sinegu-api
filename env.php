<?php
/**
 * Minimal .env loader for sinegu-api.
 *
 * Parses a simple KEY=VALUE file (no quoting/escaping, no interpolation)
 * and exposes values via env() with optional defaults.
 *
 * Usage:
 *   require_once __DIR__ . '/env.php';   // or appropriate relative path
 *   $key = env('STRIPE_SECRET_KEY');
 */

if (!function_exists('sinegu_load_env')) {
    function sinegu_load_env($path = null) {
        static $loaded = false;
        if ($loaded) return;
        if ($path === null) {
            $path = __DIR__ . '/.env';
        }
        if (!is_file($path) || !is_readable($path)) {
            $loaded = true;
            return;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            $loaded = true;
            return;
        }
        foreach ($lines as $line) {
            $line = ltrim($line);
            if ($line === '' || $line[0] === '#') continue;
            $eq = strpos($line, '=');
            if ($eq === false) continue;
            $key = trim(substr($line, 0, $eq));
            $val = substr($line, $eq + 1);
            // strip surrounding quotes if present
            $valTrim = trim($val);
            if (strlen($valTrim) >= 2) {
                $first = $valTrim[0];
                $last = $valTrim[strlen($valTrim) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $valTrim = substr($valTrim, 1, -1);
                }
            }
            if ($key === '') continue;
            if (getenv($key) === false) {
                putenv("$key=$valTrim");
            }
            if (!array_key_exists($key, $_ENV)) {
                $_ENV[$key] = $valTrim;
            }
        }
        $loaded = true;
    }
}

if (!function_exists('env')) {
    function env($key, $default = null) {
        sinegu_load_env();
        if (array_key_exists($key, $_ENV)) {
            return $_ENV[$key];
        }
        $v = getenv($key);
        return ($v === false) ? $default : $v;
    }
}

if (!function_exists('app_server_env')) {
    /**
     * Detect which deployment this request is running on, by server IP first,
     * then request host. Mirrors the frontend resolver in
     * src/hooks/api.ts (`hostToTarget`) so backend-generated URLs — Stripe /
     * Coinsbuy success, cancel and webhook links — always match the environment
     * the code is actually running on, even if a .env was copied from another
     * server.
     *
     * @return string 'prod' | 'staging' | 'dev'
     */
    function app_server_env() {
        $ip   = (string) ($_SERVER['SERVER_ADDR'] ?? '');
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        // staging = nexastaging (ubuntu 182). andrea-orcelinvest is retired and now
        // returns 410 at the origin, so it is deliberately not matched any more.
        if (strpos($ip, '191.218.162.182') === 0 || strpos($host, 'nexastaging') !== false) {
            return 'staging';
        }
        // prod = sinegualerts. Left matching on the old names on purpose — 167 and
        // api.sinegualfamily.com are both still served, and app_base_url()'s prod
        // entry still emits sinegualfamily URLs.
        if (strpos($ip, '167.86.109.159') === 0 || strpos($host, 'sinegualfamily') !== false) {
            return 'prod';
        }
        return 'dev';
    }
}

if (!function_exists('app_base_url')) {
    /**
     * Environment-correct base URL for backend-generated links.
     *
     * @param string $which   'frontend' | 'api'
     * @param string $envKey  .env key to fall back to on dev / unknown hosts
     * @param string $default default when the .env key is also absent
     * @return string
     */
    function app_base_url($which, $envKey, $default) {
        $map = [
            'staging' => [
                'frontend' => 'https://nexastaging.com',
                'api'      => 'https://api.nexastaging.com',
            ],
            // Left on sinegualfamily deliberately: api.sinegualfamily.com is still
            // served on 191 and these URLs are baked into live Stripe/Coinsbuy
            // redirects. Changing them is a prod concern, not part of the staging
            // switch to nexastaging.
            'prod' => [
                'frontend' => 'https://sinegualfamily.com',
                'api'      => 'https://api.sinegualfamily.com',
            ],
        ];
        $envName = app_server_env();
        if (isset($map[$envName][$which])) {
            return $map[$envName][$which];
        }
        // dev / unknown host -> honor whatever the local .env says
        return env($envKey, $default);
    }
}

sinegu_load_env();
