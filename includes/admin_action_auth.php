<?php
/**
 * Password guard for destructive admin invoice actions.
 *
 * Used by the invoice generate/clear endpoints. The admin UI prompts for the
 * password and posts it up; this is the only place it is checked.
 *
 * The password defaults to the value below and can be overridden per-server by
 * setting ADMIN_ACTION_PASSWORD in .env. The default exists because .env is
 * gitignored: without it a fresh deploy would fail closed on every server until
 * someone hand-edited .env on each box.
 *
 * The check is server-side on purpose: these endpoints are reachable directly,
 * so a guard that only lived in the React app would be bypassable with curl.
 * For the same reason the password is never shipped in the frontend bundle –
 * that is about the check being enforceable, not about the value being secret.
 *
 * This gates test/maintenance actions against accidental clicks. It is not a
 * substitute for real admin authentication on these endpoints.
 *
 * Usage (after conn.php, before doing anything destructive):
 *   require_once __DIR__ . '/includes/admin_action_auth.php';
 *   requireAdminActionPassword($input);   // exits 401 on failure
 *
 * The password is read from the `X-Admin-Password` request header first, then
 * from the `admin_password` key of the decoded JSON body. The header exists for
 * the GET/streaming export endpoints, which have no JSON body to carry it — and
 * a query parameter was not an option there, because full request URLs are
 * written to the nginx access log. Body callers are unaffected.
 *
 * Endpoints accepting the header must add it to Access-Control-Allow-Headers, or
 * the browser's preflight fails before the request is ever made.
 *
 * @param array|null $input Decoded JSON request body, when there is one.
 */

require_once __DIR__ . '/../env.php';

/** Default admin action password; override per-server with ADMIN_ACTION_PASSWORD in .env. */
const ADMIN_ACTION_PASSWORD_DEFAULT = 'kali.xly';

if (!function_exists('requireAdminActionPassword')) {
    function requireAdminActionPassword($input = null) {
        $expected = (string) env('ADMIN_ACTION_PASSWORD', ADMIN_ACTION_PASSWORD_DEFAULT);
        if ($expected === '') {
            $expected = ADMIN_ACTION_PASSWORD_DEFAULT;
        }

        $provided = '';
        if (isset($_SERVER['HTTP_X_ADMIN_PASSWORD'])) {
            $provided = (string) $_SERVER['HTTP_X_ADMIN_PASSWORD'];
        } elseif (is_array($input) && isset($input['admin_password'])) {
            $provided = (string) $input['admin_password'];
        }

        // hash_equals is timing-safe; strlen guard keeps it from erroring on ''.
        if ($provided === '' || !hash_equals($expected, $provided)) {
            http_response_code(401);
            // The export endpoints stream a file, so their own Content-Type is
            // set later — make sure a rejection still reads as JSON.
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode([
                'success' => false,
                'error'   => $provided === ''
                    ? 'Admin password required for this action.'
                    : 'Incorrect admin password.',
                'auth_required' => true,
            ]);
            exit;
        }
    }
}
