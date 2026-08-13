<?php
/**
 * Shared, OS-aware service registry + control helpers for the bot engines.
 *
 * This is the single source of truth for "which processes make up each broker's
 * engine". Both the Ubuntu fleet (systemd, 19 `sinegu-*` units) and the legacy
 * Windows fleet (NSSM services) are described here, so one endpoint works on
 * either box without the caller knowing which it is talking to.
 *
 * It does NOT replace bot-controller/nssm_lib.php — that stays as-is for the
 * existing restart_*_flask.php endpoints. This file is what the admin Engines
 * page (admin/GET/engine_status.php, admin/POST/service_control.php,
 * admin/GET/service_logs.php) is built on.
 *
 * SECURITY: unit names are NEVER taken from the request. A caller passes a key,
 * we look it up in the registry below, and only a registry-owned string is ever
 * handed to the shell. Anything not in the registry is a 400.
 *
 * On Linux the read path (is-active / show / journalctl) normally needs no
 * privileges — systemd answers status queries to any user. Only start/stop/
 * restart do, which is why sc_control() shells through `sudo -n`. See
 * sc_privilege_hint() for the sudoers rule that has to exist on 191/182.
 */

require_once __DIR__ . '/../env.php';

if (!function_exists('sc_is_linux')) {
    /** True when this box drives systemd rather than NSSM. */
    function sc_is_linux() {
        return PHP_OS_FAMILY === 'Linux';
    }
}

if (!function_exists('sc_server_ip')) {
    /**
     * This box's own address. Mirrors admin/GET/server_env.php — a loopback
     * SERVER_ADDR (reverse proxy) hides the real machine, so fall back to
     * resolving our own hostname.
     */
    function sc_server_ip() {
        $ip = trim((string) ($_SERVER['SERVER_ADDR'] ?? ''));
        if ($ip === '' || $ip === '127.0.0.1' || $ip === '::1') {
            $hostname = (string) php_uname('n');
            $resolved = gethostbyname($hostname);
            if (filter_var($resolved, FILTER_VALIDATE_IP) && strpos($resolved, '127.') !== 0) {
                $ip = $resolved;
            }
        }
        return $ip;
    }
}

if (!function_exists('sc_env_key')) {
    /**
     * Which machine is answering: prod | staging | ubuntu-prod | ubuntu-staging
     * | local | unknown. Same mapping as admin/GET/server_env.php, kept in step
     * with it deliberately — this answers "which box", not "which brand".
     */
    function sc_env_key() {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $boxes = [
            '167.86.109.159'  => 'prod',
            '156.67.30.81'    => 'staging',
            '191.218.162.171' => 'ubuntu-prod',
            '191.218.162.182' => 'ubuntu-staging',
        ];
        $ip   = sc_server_ip();
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));

        if (isset($boxes[$ip])) {
            $cached = $boxes[$ip];
        } elseif ($ip === '' || $ip === '127.0.0.1' || $ip === '::1' || strpos($host, 'localhost') === 0) {
            $cached = 'local';
        } else {
            $cached = 'unknown';
        }
        return $cached;
    }
}

if (!function_exists('sc_registry')) {
    /**
     * broker key -> label, ports, and the processes that back it.
     *
     * `units` mirrors PY_SERVICES in the dashboard's .claude/deploy_sinegualerts.py —
     * if a unit is added there it must be added here or the page will not see it.
     *
     * `nssm` is the pre-cutover Windows service name, or null where Windows had
     * no separate service (Binance ran as a single BinanceFlask service covering
     * every loop). Null renders as "n/a on this OS" rather than as a failure.
     */
    function sc_registry() {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $cached = [
            'capital' => [
                'label' => 'Capital.com',
                'ports' => [
                    'webhook'  => (int) env('CAPITAL_WEBHOOK_PORT', 5000),
                    // 6000, NOT 5001 — 5001 is Binance. CapitalFlask/src/config.py:138
                    // is authoritative; sinegu-api/CLAUDE.md's "5001" is stale.
                    'accounts' => (int) env('CAPITAL_ACCOUNTS_PORT', 6000),
                ],
                'units' => [
                    ['unit' => 'sinegu-capital-main',         'nssm' => env('SVC_CAPITAL', 'CapitalFlask-Main'), 'label' => 'Webhook receiver', 'role' => 'main'],
                    ['unit' => 'sinegu-capital-accounts',     'nssm' => 'CapitalFlask-Listener',                 'label' => 'Accounts listener', 'role' => 'accounts'],
                    ['unit' => 'sinegu-capital-balances',     'nssm' => 'CapitalFlask-Balances',                 'label' => 'Balances poller',   'role' => 'worker'],
                    ['unit' => 'sinegu-capital-positions',    'nssm' => 'CapitalFlask-Positions',                'label' => 'Positions poller',  'role' => 'worker'],
                    ['unit' => 'sinegu-capital-transactions', 'nssm' => 'CapitalFlask-Transactions',             'label' => 'Transactions poller', 'role' => 'worker'],
                    ['unit' => 'sinegu-capital-performance',  'nssm' => 'CapitalFlask-Report',                   'label' => 'Performance report', 'role' => 'worker'],
                ],
            ],
            'ig' => [
                'label' => 'IG.com',
                'ports' => [
                    'webhook'  => (int) env('IG_WEBHOOK_PORT', 5002),
                    'accounts' => (int) env('IG_ACCOUNTS_PORT', 6001),
                ],
                'units' => [
                    ['unit' => 'sinegu-ig-main',         'nssm' => env('SVC_IG', 'IGFlask-Main'), 'label' => 'Webhook receiver',   'role' => 'main'],
                    ['unit' => 'sinegu-ig-accounts',     'nssm' => 'IGFlask-Listener',            'label' => 'Accounts listener',  'role' => 'accounts'],
                    ['unit' => 'sinegu-ig-balances',     'nssm' => 'IGFlask-Balances',            'label' => 'Balances poller',    'role' => 'worker'],
                    ['unit' => 'sinegu-ig-positions',    'nssm' => 'IGFlask-Positions',           'label' => 'Positions poller',   'role' => 'worker'],
                    ['unit' => 'sinegu-ig-transactions', 'nssm' => 'IGFlask-Transactions',        'label' => 'Transactions poller', 'role' => 'worker'],
                    ['unit' => 'sinegu-ig-performance',  'nssm' => 'IGFlask-Report',              'label' => 'Performance report', 'role' => 'worker'],
                ],
            ],
            'binance' => [
                'label' => 'Binance',
                'ports' => [
                    'webhook' => (int) env('BINANCE_WEBHOOK_PORT', 5001),
                ],
                'units' => [
                    // Windows ran all of these inside one BinanceFlask service, so
                    // only the main unit has an NSSM counterpart.
                    ['unit' => 'sinegu-binance-main',           'nssm' => env('SVC_BINANCE', 'BinanceFlask'), 'label' => 'Webhook receiver',    'role' => 'main'],
                    ['unit' => 'sinegu-binance-balances',       'nssm' => null, 'label' => 'Balances poller',      'role' => 'worker'],
                    ['unit' => 'sinegu-binance-positions',      'nssm' => null, 'label' => 'Positions poller',     'role' => 'worker'],
                    ['unit' => 'sinegu-binance-transfers',      'nssm' => null, 'label' => 'Transfers poller',     'role' => 'worker'],
                    ['unit' => 'sinegu-binance-past-positions', 'nssm' => null, 'label' => 'Past-positions sync',  'role' => 'worker'],
                    ['unit' => 'sinegu-binance-performance',    'nssm' => null, 'label' => 'Performance report',   'role' => 'worker'],
                ],
            ],
            'telegram' => [
                'label' => 'Telegram publisher',
                'ports' => [], // polling bot — no inbound port
                'units'  => [
                    ['unit' => 'sinegu-telegram', 'nssm' => env('SVC_TELEGRAM', 'SinegualBot'), 'label' => 'Publisher', 'role' => 'main'],
                ],
            ],
        ];
        return $cached;
    }
}

if (!function_exists('sc_expectation')) {
    /**
     * Is this broker supposed to be running on THIS box?
     *
     * Without this a page on ubuntu-staging shows seven red units and looks like
     * an outage, when in fact IG and the Telegram publisher were never deployed
     * there. "Intentionally absent" and "down" must not look the same.
     *
     * @return array{expected:bool,note:string|null}
     */
    function sc_expectation($broker) {
        $envKey = sc_env_key();

        // Staging (156 and its Ubuntu replacement 182) never ran IG, and the
        // Telegram publisher is deliberately single-instance on prod only:
        // Telegram allows one getUpdates poller per token, and a second one
        // silently fights the live one rather than failing loudly.
        if ($envKey === 'ubuntu-staging' || $envKey === 'staging') {
            if ($broker === 'ig') {
                return ['expected' => false, 'note' => 'IG was never deployed on staging'];
            }
            if ($broker === 'telegram') {
                return ['expected' => false, 'note' => 'Publisher runs on prod only (one poller per bot token)'];
            }
        }

        // Both Windows boxes were left as the fallback copy of the data after the
        // 2026-08-12 cutover: every bot service is Stopped + Disabled on purpose.
        if ($envKey === 'prod' || $envKey === 'staging') {
            return ['expected' => false, 'note' => 'Stopped and disabled after the 2026-08-12 Ubuntu cutover'];
        }

        return ['expected' => true, 'note' => null];
    }
}

if (!function_exists('sc_all_units')) {
    /** Flat list of every unit name in the registry (systemd naming). */
    function sc_all_units() {
        $out = [];
        foreach (sc_registry() as $broker => $cfg) {
            foreach ($cfg['units'] as $u) {
                $out[] = $u['unit'];
            }
        }
        return $out;
    }
}

if (!function_exists('sc_find_unit')) {
    /**
     * Resolve a caller-supplied unit name against the registry.
     *
     * This is the whitelist. Returns null for anything unknown — callers must
     * treat null as a 400 and must never fall back to using the raw input.
     *
     * @return array{broker:string,unit:string,nssm:string|null,label:string,role:string}|null
     */
    function sc_find_unit($unit) {
        $unit = trim((string) $unit);
        if ($unit === '') {
            return null;
        }
        foreach (sc_registry() as $broker => $cfg) {
            foreach ($cfg['units'] as $u) {
                if ($u['unit'] === $unit) {
                    return $u + ['broker' => $broker];
                }
            }
        }
        return null;
    }
}

if (!function_exists('sc_states')) {
    /**
     * Current state of many units in as few shell calls as possible.
     *
     * On Linux this is ONE `systemctl show` for the whole fleet: given several
     * units systemd emits one property block per unit separated by a blank line,
     * so 19 units cost one exec instead of 19. That matters because the Engines
     * page polls every 10s.
     *
     * @param string[] $units registry-owned unit names
     * @return array<string,array> unit => {state, sub_state, active_since, n_restarts, load_state, enabled}
     */
    function sc_states(array $units) {
        $result = [];
        foreach ($units as $u) {
            $result[$u] = [
                'state'        => 'unknown',
                'sub_state'    => null,
                'active_since' => null,
                'n_restarts'   => null,
                'load_state'   => null,
                'enabled'      => null,
            ];
        }
        if (!$units) {
            return $result;
        }

        if (sc_is_linux()) {
            $args = implode(' ', array_map('escapeshellarg', $units));
            $cmd  = 'systemctl show ' . $args
                  . ' -p Id -p ActiveState -p SubState -p ActiveEnterTimestamp'
                  . ' -p NRestarts -p LoadState -p UnitFileState 2>/dev/null';
            $out = [];
            $rc  = null;
            @exec($cmd, $out, $rc);

            $block = [];
            $flush = function () use (&$block, &$result) {
                if (!$block || !isset($block['Id'])) {
                    $block = [];
                    return;
                }
                $id = preg_replace('/\.service$/', '', $block['Id']);
                if (!array_key_exists($id, $result)) {
                    $block = [];
                    return;
                }
                $ts = isset($block['ActiveEnterTimestamp']) ? trim($block['ActiveEnterTimestamp']) : '';
                $result[$id] = [
                    'state'        => $block['ActiveState'] !== '' ? $block['ActiveState'] : 'unknown',
                    'sub_state'    => isset($block['SubState']) && $block['SubState'] !== '' ? $block['SubState'] : null,
                    'active_since' => $ts !== '' ? $ts : null,
                    'n_restarts'   => isset($block['NRestarts']) && $block['NRestarts'] !== '' ? (int) $block['NRestarts'] : null,
                    'load_state'   => isset($block['LoadState']) && $block['LoadState'] !== '' ? $block['LoadState'] : null,
                    'enabled'      => isset($block['UnitFileState']) && $block['UnitFileState'] !== '' ? $block['UnitFileState'] : null,
                ];
                $block = [];
            };

            foreach ($out as $line) {
                if (trim($line) === '') {
                    $flush();
                    continue;
                }
                $pos = strpos($line, '=');
                if ($pos === false) {
                    continue;
                }
                $block[substr($line, 0, $pos)] = substr($line, $pos + 1);
            }
            $flush();

            return $result;
        }

        // --- Windows / NSSM fallback (dev + the decommissioned boxes) ---
        require_once __DIR__ . '/../bot-controller/nssm_lib.php';
        $nssm = nssm_path();
        $map  = [
            'SERVICE_RUNNING'       => 'active',
            'SERVICE_START_PENDING' => 'activating',
            'SERVICE_STOP_PENDING'  => 'deactivating',
            'SERVICE_STOPPED'       => 'inactive',
            'SERVICE_PAUSED'        => 'inactive',
        ];
        foreach ($units as $unitName) {
            $entry = sc_find_unit($unitName);
            if ($entry === null || $entry['nssm'] === null) {
                // No Windows counterpart (e.g. the Binance worker loops all lived
                // inside one service) — report it as not applicable, not as down.
                $result[$unitName]['state'] = 'n/a';
                continue;
            }
            $raw = nssm_service_state($nssm, $entry['nssm']);
            $result[$unitName]['state']     = isset($map[$raw]) ? $map[$raw] : 'unknown';
            $result[$unitName]['sub_state'] = strtolower(str_replace('SERVICE_', '', $raw));
        }
        return $result;
    }
}

if (!function_exists('sc_control')) {
    /**
     * start | stop | restart one unit, then report the state it actually reached.
     *
     * Never trusts the command's exit code — on Windows that lesson is written up
     * in nssm_lib.php, and on Linux `systemctl restart` returns before a Flask app
     * has bound its port. The truth is always the state read back afterwards.
     *
     * @return array{unit:string,action:string,success:bool,state:string,exit_code:int|null,output:string,seconds:float}
     */
    function sc_control($unit, $action) {
        $entry = sc_find_unit($unit);
        if ($entry === null) {
            return [
                'unit' => $unit, 'action' => $action, 'success' => false, 'state' => 'unknown',
                'exit_code' => null, 'output' => 'Unknown unit', 'seconds' => 0.0,
            ];
        }
        if (!in_array($action, ['start', 'stop', 'restart'], true)) {
            return [
                'unit' => $unit, 'action' => $action, 'success' => false, 'state' => 'unknown',
                'exit_code' => null, 'output' => 'Unknown action', 'seconds' => 0.0,
            ];
        }

        $t0 = microtime(true);

        if (sc_is_linux()) {
            $cmd = 'sudo -n systemctl ' . $action . ' ' . escapeshellarg($entry['unit']) . ' 2>&1';
            $out = [];
            $rc  = null;
            @exec($cmd, $out, $rc);
            $output = trim(implode("\n", $out));

            // Give the process a moment to bind/exit before reading the verdict.
            $want     = ($action === 'stop') ? 'inactive' : 'active';
            $deadline = time() + 15;
            do {
                usleep(600000);
                $state = sc_states([$entry['unit']])[$entry['unit']]['state'];
                if ($state === $want || $state === 'failed') {
                    break;
                }
            } while (time() < $deadline);

            $success = ($state === $want);
            if (!$success && $output === '' && $rc !== 0) {
                $output = 'systemctl exited ' . (int) $rc . ' with no message — check the sudoers rule (see sc_privilege_hint()).';
            }

            return [
                'unit' => $entry['unit'], 'action' => $action, 'success' => $success,
                'state' => $state, 'exit_code' => $rc, 'output' => $output,
                'seconds' => round(microtime(true) - $t0, 2),
            ];
        }

        // --- Windows / NSSM ---
        require_once __DIR__ . '/../bot-controller/nssm_lib.php';
        if ($entry['nssm'] === null) {
            return [
                'unit' => $entry['unit'], 'action' => $action, 'success' => false, 'state' => 'n/a',
                'exit_code' => null, 'output' => 'This unit has no Windows service counterpart.',
                'seconds' => 0.0,
            ];
        }
        $nssm = nssm_path();
        if ($action === 'restart') {
            $r = nssm_restart_service($nssm, $entry['nssm']);
            return [
                'unit' => $entry['unit'], 'action' => $action, 'success' => $r['success'],
                'state' => $r['state'] === 'SERVICE_RUNNING' ? 'active' : 'inactive',
                'exit_code' => $r['exit_code'], 'output' => $r['output'], 'seconds' => $r['seconds'],
            ];
        }
        $out = [];
        $rc  = null;
        @exec('"' . $nssm . '" ' . $action . ' ' . escapeshellarg($entry['nssm']) . ' 2>&1', $out, $rc);
        $raw   = nssm_service_state($nssm, $entry['nssm']);
        $state = ($raw === 'SERVICE_RUNNING') ? 'active' : 'inactive';
        return [
            'unit' => $entry['unit'], 'action' => $action,
            'success' => ($action === 'stop') ? ($state === 'inactive') : ($state === 'active'),
            'state' => $state, 'exit_code' => $rc, 'output' => trim(implode("\n", $out)),
            'seconds' => round(microtime(true) - $t0, 2),
        ];
    }
}

if (!function_exists('sc_journal')) {
    /**
     * Tail one unit's journal. Linux only; returns null elsewhere so callers fall
     * back to reading log files.
     *
     * www-data can normally read the journal once it is in the systemd-journal
     * group; if it is not, we retry through sudo before giving up.
     *
     * @return string[]|null
     */
    function sc_journal($unit, $lines) {
        if (!sc_is_linux()) {
            return null;
        }
        $entry = sc_find_unit($unit);
        if ($entry === null) {
            return null;
        }
        $n    = max(10, min(500, (int) $lines));
        $base = 'journalctl -u ' . escapeshellarg($entry['unit']) . ' -n ' . $n . ' --no-pager -o short-iso 2>&1';

        $out = [];
        $rc  = null;
        @exec($base, $out, $rc);
        if ($rc !== 0 || !$out) {
            $out = [];
            $rc  = null;
            @exec('sudo -n ' . $base, $out, $rc);
        }
        if ($rc !== 0 || !$out) {
            return null;
        }
        return $out;
    }
}

if (!function_exists('sc_http_probe')) {
    /**
     * GET a small JSON document off a loopback port.
     *
     * The Flask engines bind 0.0.0.0 but ufw keeps them private, so the browser
     * can never reach them — this API runs on the same box, which is the whole
     * reason these probes live server-side. Every failure is data, never an
     * exception: a dead engine must render as "unreachable", not as a 500.
     *
     * @return array{ok:bool,status:int|null,data:mixed,error:string|null,ms:int}
     */
    function sc_http_probe($port, $path, $timeout = 1.5) {
        $t0  = microtime(true);
        $url = 'http://127.0.0.1:' . (int) $port . $path;

        $ctx = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'timeout'       => $timeout,
                'ignore_errors' => true,
                'header'        => "Accept: application/json\r\nConnection: close\r\n",
            ],
        ]);

        $body = @file_get_contents($url, false, $ctx);
        $ms   = (int) round((microtime(true) - $t0) * 1000);

        if ($body === false) {
            return ['ok' => false, 'status' => null, 'data' => null, 'error' => 'unreachable', 'ms' => $ms];
        }

        $status = null;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $h) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                    $status = (int) $m[1];
                }
            }
        }

        $data = json_decode($body, true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            return ['ok' => false, 'status' => $status, 'data' => null, 'error' => 'non-JSON response', 'ms' => $ms];
        }

        return [
            'ok'     => ($status === null || ($status >= 200 && $status < 300)),
            'status' => $status,
            'data'   => $data,
            'error'  => null,
            'ms'     => $ms,
        ];
    }
}

if (!function_exists('sc_privilege_hint')) {
    /**
     * The sudoers rule start/stop/restart needs on the Ubuntu boxes. Surfaced in
     * the API response so a permission failure explains its own fix instead of
     * looking like a broken endpoint.
     */
    function sc_privilege_hint() {
        if (!sc_is_linux()) {
            return null;
        }
        return 'Requires /etc/sudoers.d/sinegu-www: '
             . 'www-data ALL=(root) NOPASSWD: /usr/bin/systemctl start sinegu-*, '
             . '/usr/bin/systemctl stop sinegu-*, /usr/bin/systemctl restart sinegu-*  '
             . '(and www-data in the systemd-journal group for log tails).';
    }
}
