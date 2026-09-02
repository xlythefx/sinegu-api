<?php
/**
 * Shared NSSM service-control helpers for the bot-controller restart endpoints.
 *
 * Why this exists: `nssm restart <svc>` is NOT a reliable success/failure signal.
 * NSSM sends STOP, then START, then samples the service state once. Flask bots
 * take a few seconds to bind their port, so NSSM very often samples while the
 * service is still SERVICE_START_PENDING and bails out with
 *
 *     <svc>: Unexpected status SERVICE_START_PENDING in response to START control.
 *
 * ...and exit code 1 — even though the service comes up fine a second later.
 * That false failure is what surfaced in the admin UI as
 * "NSSM restart failed (exit code 1)".
 *
 * So: never trust nssm's exit code. Issue the restart, then poll the real
 * service state and report success based on the service actually RUNNING.
 */

if (!function_exists('nssm_env_name')) {
    /**
     * Which deployment is answering this request: 'prod' | 'staging' | 'dev'.
     *
     * Prefers env.php's app_server_env(), but falls back to its own host/IP check
     * because older api/env.php copies on the VPSs don't define it yet — and a
     * restart endpoint must never fatal just to label its own environment.
     */
    function nssm_env_name() {
        if (function_exists('app_server_env')) {
            return app_server_env();
        }
        $ip   = (string) ($_SERVER['SERVER_ADDR'] ?? '');
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if (strpos($ip, '191.218.162.182') === 0 || strpos($host, 'nexastaging') !== false) return 'staging';
        if (strpos($ip, '167.86.109.159') === 0 || strpos($host, 'sinegualfamily') !== false) return 'prod';
        return 'dev';
    }
}

if (!function_exists('nssm_path')) {
    /** Locate nssm.exe — standard install path first, then PATH fallback. */
    function nssm_path() {
        $p = 'C:\\nssm\\nssm.exe';
        return is_file($p) ? $p : 'nssm';
    }
}

if (!function_exists('nssm_service_state')) {
    /**
     * Current state of a Windows service, e.g. SERVICE_RUNNING / SERVICE_STOPPED
     * / SERVICE_START_PENDING. Returns SERVICE_UNKNOWN if it can't be read.
     */
    function nssm_service_state($nssm, $service) {
        $out = [];
        $rc = null;
        exec('"' . $nssm . '" status ' . escapeshellarg($service) . ' 2>&1', $out, $rc);
        $text = strtoupper(trim(implode(' ', $out)));
        foreach (['SERVICE_RUNNING', 'SERVICE_START_PENDING', 'SERVICE_STOP_PENDING', 'SERVICE_STOPPED', 'SERVICE_PAUSED'] as $state) {
            if (strpos($text, $state) !== false) {
                return $state;
            }
        }

        // nssm couldn't answer (older build / odd service) — fall back to sc.
        $out = [];
        exec('sc query ' . escapeshellarg($service) . ' 2>&1', $out, $rc);
        $text = strtoupper(implode(' ', $out));
        if (strpos($text, 'RUNNING') !== false) return 'SERVICE_RUNNING';
        if (strpos($text, 'START_PENDING') !== false) return 'SERVICE_START_PENDING';
        if (strpos($text, 'STOP_PENDING') !== false) return 'SERVICE_STOP_PENDING';
        if (strpos($text, 'STOPPED') !== false) return 'SERVICE_STOPPED';

        return 'SERVICE_UNKNOWN';
    }
}

if (!function_exists('nssm_wait_running')) {
    /** Poll until the service reports RUNNING, or $timeout seconds elapse. */
    function nssm_wait_running($nssm, $service, $timeout = 30) {
        $deadline = time() + $timeout;
        $state = nssm_service_state($nssm, $service);
        while ($state !== 'SERVICE_RUNNING' && time() < $deadline) {
            sleep(1);
            $state = nssm_service_state($nssm, $service);
        }
        return $state;
    }
}

if (!function_exists('nssm_restart_service')) {
    /**
     * Restart one service and verify it actually came back up.
     *
     * @return array{service:string,success:bool,state:string,exit_code:int|null,output:string,seconds:float}
     */
    function nssm_restart_service($nssm, $service, $timeout = 30) {
        $t0 = microtime(true);

        $out = [];
        $exitCode = null;
        exec('"' . $nssm . '" restart ' . escapeshellarg($service) . ' 2>&1', $out, $exitCode);
        $output = trim(implode("\n", $out));

        // nssm returned non-zero for the START leg but the service may still be
        // coming up; and even a failed STOP leg can leave it running. Either way
        // the truth is the service state, so always confirm it.
        $state = nssm_wait_running($nssm, $service, $timeout);

        // A stopped service after a failed STOP means nssm never restarted it —
        // try an explicit start before calling it a failure.
        if ($state === 'SERVICE_STOPPED') {
            $out2 = [];
            $rc2 = null;
            exec('"' . $nssm . '" start ' . escapeshellarg($service) . ' 2>&1', $out2, $rc2);
            $output = trim($output . "\n" . trim(implode("\n", $out2)));
            $state = nssm_wait_running($nssm, $service, $timeout);
        }

        return [
            'service'   => $service,
            'success'   => ($state === 'SERVICE_RUNNING'),
            'state'     => $state,
            'exit_code' => $exitCode,
            'output'    => $output,
            'seconds'   => round(microtime(true) - $t0, 2),
        ];
    }
}
