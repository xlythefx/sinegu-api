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

sinegu_load_env();
