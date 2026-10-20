<?php
/**
 * Admin guard for sensitive admin endpoints (credentials, account lists, database
 * exports, invoice generate/clear, engine control, trade-incident actions, webhooks).
 *
 * Since 2026-10-20 access follows the ACCOUNT TYPE (user_credentials.type, migration
 * 095), not a shared password: the dashboard sends the signed-in account's uni_id and
 * the request passes when that account's type opens the admin portal
 * (ADMIN_PORTAL_TYPES in includes/user_types.php). There is no password prompt any more.
 *
 * Where the uni_id is read from, first hit wins:
 *   - header `X-Admin-Uni-Id`, or body/query `admin_uni_id`;
 *   - the legacy slots `X-Admin-Password` header / `admin_password` body. The dashboard
 *     still sends its credential there, because every endpoint already allows that
 *     header through CORS — renaming it would mean touching all of them.
 *
 * Scripts and cron (curl, the billing runner) may still send the shared action
 * password in those legacy slots: ADMIN_ACTION_PASSWORD from .env, else the default
 * below. That path is for servers, not people.
 *
 * Honest limit: the API has no sessions, so this proves "the caller knows an admin's
 * uni_id", not "the caller IS that admin". It hides nothing from someone who can read
 * a uni_id — it replaces a shared password whose default sits in this file.
 *
 * Usage (before doing anything sensitive; conn.php is loaded here when needed):
 *   require_once __DIR__ . '/includes/admin_action_auth.php';
 *   requireAdminActionPassword($input);   // exits 401 on failure
 *
 * @param array|null $input Decoded JSON request body, when there is one.
 */

require_once __DIR__ . '/../env.php';
require_once __DIR__ . '/user_types.php';

/** Shared action password for scripts; override per-server with ADMIN_ACTION_PASSWORD in .env. */
const ADMIN_ACTION_PASSWORD_DEFAULT = 'kali.xly';

if (!function_exists('admin_request_credentials')) {
    /** Every non-empty credential the request carries, in the order they are tried. */
    function admin_request_credentials($input = null): array {
        $found = [];
        $add = function ($v) use (&$found) {
            $v = trim((string) $v);
            if ($v !== '' && !in_array($v, $found, true)) {
                $found[] = $v;
            }
        };
        $add($_SERVER['HTTP_X_ADMIN_UNI_ID'] ?? '');
        if (is_array($input)) {
            $add($input['admin_uni_id'] ?? '');
        }
        $add($_GET['admin_uni_id'] ?? '');
        $add($_SERVER['HTTP_X_ADMIN_PASSWORD'] ?? '');
        if (is_array($input)) {
            $add($input['admin_password'] ?? '');
        }
        return $found;
    }
}

if (!function_exists('admin_credential_is_admin_account')) {
    /** True when $uniId names an account whose type opens the admin portal. */
    function admin_credential_is_admin_account(string $uniId): bool {
        // uni_ids are UUIDs; anything else is not worth a query.
        if (!preg_match('/^[0-9a-f-]{8,64}$/i', $uniId)) {
            return false;
        }
        global $conn;
        if (empty($conn)) {
            // The caller may not have loaded conn.php yet. With `global $conn` in scope,
            // conn.php sets the global, and the caller's own require_once is then skipped.
            require __DIR__ . '/../conn.php';
        }
        if (empty($conn)) {
            return false;
        }
        try {
            $type = user_type_of($conn, $uniId);
        } catch (Throwable $e) {
            return false;
        }
        return $type !== null && in_array($type, ADMIN_PORTAL_TYPES, true);
    }
}

if (!function_exists('requireAdminActionPassword')) {
    function requireAdminActionPassword($input = null) {
        $credentials = admin_request_credentials($input);

        $password = (string) env('ADMIN_ACTION_PASSWORD', ADMIN_ACTION_PASSWORD_DEFAULT);
        if ($password === '') {
            $password = ADMIN_ACTION_PASSWORD_DEFAULT;
        }

        foreach ($credentials as $credential) {
            // Scripts / cron: the shared action password. hash_equals is timing-safe.
            if (hash_equals($password, $credential)) {
                return;
            }
            // People: the signed-in dashboard account, when its type is an admin type.
            if (admin_credential_is_admin_account($credential)) {
                return;
            }
        }

        http_response_code(401);
        // The export endpoints stream a file, so their own Content-Type is set later —
        // make sure a rejection still reads as JSON.
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'success'       => false,
            'error'         => $credentials === []
                ? 'Sign in with an admin account to do this.'
                : 'This needs an admin account (Administrator, Developer, Collaborator or Tester).',
            'auth_required' => true,
        ]);
        exit;
    }
}

if (!function_exists('requireAdminAccess')) {
    /** Same check under its current name; requireAdminActionPassword is kept for the 16 callers. */
    function requireAdminAccess($input = null) {
        requireAdminActionPassword($input);
    }
}
