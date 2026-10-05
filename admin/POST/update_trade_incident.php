<?php
/**
 * Admin: change one trade incident.
 *
 * POST /admin/POST/update_trade_incident.php
 * { "id": 123, "op": "resolve"|"ignore"|"reopen"|"note"|"read"|"unread", "note": "..." }
 *
 * resolve / ignore / reopen require the admin action password (header
 * X-Admin-Password, or body admin_password) — see includes/admin_action_auth.php.
 * A wrong/missing password answers 401 { success:false, auth_required:true, error }.
 * note / read / unread need no password.
 *
 * resolve / ignore: status -> resolved|ignored, resolved_by='admin', resolved_at=NOW(),
 *   resolution_note=note, and the dedupe_key gets "|r<id>" — so if the bot reports the
 *   same problem again it opens a NEW row instead of silently bumping a closed one.
 * reopen: status -> open, resolved_* cleared; the original dedupe_key is restored
 *   unless a newer open row already holds it (then this row keeps the suffixed key).
 *
 * -> { success, incident, changed }
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Admin-Password');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../../includes/trade_incidents_lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ti_json(405, ['success' => false, 'error' => 'Method not allowed']);
}

$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input)) {
    ti_json(400, ['success' => false, 'error' => 'Invalid JSON body']);
}

$id = isset($input['id']) ? (int) $input['id'] : 0;
$op = isset($input['op']) ? strtolower(trim((string) $input['op'])) : '';
if ($id <= 0) {
    ti_json(400, ['success' => false, 'error' => 'id is required']);
}
if (!in_array($op, ['resolve', 'ignore', 'reopen', 'note', 'read', 'unread'], true)) {
    ti_json(400, ['success' => false, 'error' => 'op must be one of: resolve, ignore, reopen, note, read, unread']);
}
$note = ti_str($input['note'] ?? null, 4000, null);

if (in_array($op, ['resolve', 'ignore', 'reopen'], true)) {
    require_once __DIR__ . '/../../includes/admin_action_auth.php';
    requireAdminActionPassword($input); // exits 401 { auth_required: true } on failure
}

mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . '/../../conn.php';
if (!$conn) {
    ti_json(500, ['success' => false, 'error' => 'Database connection failed']);
}
ti_require_table($conn);

$st = mysqli_prepare($conn, 'SELECT id, status, dedupe_key FROM trade_incidents WHERE id = ? LIMIT 1');
if (!$st) {
    $err = mysqli_error($conn);
    mysqli_close($conn);
    ti_json(500, ['success' => false, 'error' => $err]);
}
mysqli_stmt_bind_param($st, 'i', $id);
mysqli_stmt_execute($st);
$cur = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
mysqli_stmt_close($st);
if (!$cur) {
    mysqli_close($conn);
    ti_json(404, ['success' => false, 'error' => 'Incident not found']);
}

$changed = false;
$error = null;

switch ($op) {
    case 'resolve':
    case 'ignore':
        $changed = ti_close_ids($conn, [$id], $op === 'resolve' ? 'resolved' : 'ignored', 'admin', $note) > 0;
        if (!$changed && in_array($cur['status'], ['open', 'retrying'], true)) {
            $error = mysqli_error($conn) ?: 'Update failed';
        }
        break;

    case 'reopen':
        if (!in_array($cur['status'], ['resolved', 'ignored'], true)) {
            break; // already open/retrying: nothing to do
        }
        $key = (string) $cur['dedupe_key'];
        $suffix = '|r' . $id;
        $original = (substr($key, -strlen($suffix)) === $suffix) ? substr($key, 0, -strlen($suffix)) : $key;
        // Only take the original key back if no newer row owns it.
        $chk = mysqli_prepare($conn, 'SELECT id FROM trade_incidents WHERE dedupe_key = ? AND id <> ? LIMIT 1');
        $taken = true;
        if ($chk) {
            mysqli_stmt_bind_param($chk, 'si', $original, $id);
            mysqli_stmt_execute($chk);
            $taken = (bool) mysqli_fetch_assoc(mysqli_stmt_get_result($chk));
            mysqli_stmt_close($chk);
        }
        $newKey = $taken ? $key : $original;
        $up = mysqli_prepare(
            $conn,
            "UPDATE trade_incidents
                SET status = 'open', resolved_at = NULL, resolved_by = NULL, dedupe_key = ?,
                    resolution_note = COALESCE(?, resolution_note)
              WHERE id = ? AND status IN ('resolved','ignored')"
        );
        if ($up) {
            mysqli_stmt_bind_param($up, 'ssi', $newKey, $note, $id);
            $changed = mysqli_stmt_execute($up) && mysqli_stmt_affected_rows($up) > 0;
            if (!$changed) {
                $error = mysqli_stmt_error($up) ?: null;
            }
            mysqli_stmt_close($up);
        } else {
            $error = mysqli_error($conn);
        }
        break;

    case 'note':
        $up = mysqli_prepare($conn, 'UPDATE trade_incidents SET resolution_note = ? WHERE id = ?');
        if ($up) {
            mysqli_stmt_bind_param($up, 'si', $note, $id);
            if (mysqli_stmt_execute($up)) {
                $changed = mysqli_stmt_affected_rows($up) > 0;
            } else {
                $error = mysqli_stmt_error($up);
            }
            mysqli_stmt_close($up);
        } else {
            $error = mysqli_error($conn);
        }
        break;

    case 'read':
    case 'unread':
        $flag = $op === 'read' ? 1 : 0;
        $up = mysqli_prepare($conn, 'UPDATE trade_incidents SET is_read = ? WHERE id = ?');
        if ($up) {
            mysqli_stmt_bind_param($up, 'ii', $flag, $id);
            if (mysqli_stmt_execute($up)) {
                $changed = mysqli_stmt_affected_rows($up) > 0;
            } else {
                $error = mysqli_stmt_error($up);
            }
            mysqli_stmt_close($up);
        } else {
            $error = mysqli_error($conn);
        }
        break;
}

if ($error) {
    mysqli_close($conn);
    ti_json(500, ['success' => false, 'error' => $error]);
}

$incident = ti_load_one($conn, $id);
mysqli_close($conn);

echo json_encode(['success' => true, 'incident' => $incident, 'changed' => $changed], JSON_INVALID_UTF8_SUBSTITUTE);
