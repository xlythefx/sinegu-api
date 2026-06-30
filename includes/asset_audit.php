<?php
/**
 * Audit log for trading-asset changes (create / update / delete).
 * Writes one row to asset_audit with full before/after snapshots and, for updates,
 * a field-level diff (old → new). No actor is recorded by design.
 *
 * Usage:
 *   require_once __DIR__ . '/../includes/asset_audit.php';
 *   $old = asset_audit_fetch_snapshot($conn, $asset_id);   // before the change
 *   ... do the create/update/delete ...
 *   $new = asset_audit_fetch_snapshot($conn, $asset_id);   // after (null for delete)
 *   asset_audit_log($conn, $asset_id, $ticker, 'update', $old, $new);
 *
 * $old / $new are associative arrays of asset fields (or null).
 */

if (!function_exists('asset_audit_fetch_snapshot')) {
    /**
     * Fetch a normalized snapshot of an asset for auditing, or null if it doesn't exist.
     */
    function asset_audit_fetch_snapshot($conn, $asset_id) {
        if (!$conn || !is_object($conn)) {
            return null;
        }
        $stmt = mysqli_prepare(
            $conn,
            'SELECT ticker, type, broker, side, asset_image, max_increments, base_size, enabled FROM assets WHERE asset_id = ?'
        );
        if (!$stmt) {
            return null;
        }
        $aid = (int) $asset_id;
        mysqli_stmt_bind_param($stmt, 'i', $aid);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);
        if (!$row) {
            return null;
        }
        return [
            'ticker' => $row['ticker'],
            'type' => $row['type'],
            'broker' => $row['broker'],
            'side' => $row['side'],
            'asset_image' => $row['asset_image'],
            'max_increments' => $row['max_increments'] === null ? null : (float) $row['max_increments'],
            'base_size' => $row['base_size'] === null ? null : (float) $row['base_size'],
            'enabled' => $row['enabled'] === null ? null : (int) $row['enabled'],
        ];
    }
}

if (!function_exists('asset_audit_values_differ')) {
    /**
     * Compare two field values, treating numerics numerically (10 == 10.0)
     * and empty-string == null, so we don't record phantom changes.
     */
    function asset_audit_values_differ($a, $b) {
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a !== (float) $b;
        }
        $na = ($a === '' ? null : $a);
        $nb = ($b === '' ? null : $b);
        return $na !== $nb;
    }
}

if (!function_exists('asset_audit_log')) {
    /**
     * Insert an audit row. Never throws — failures are swallowed so they can't
     * break the asset operation itself.
     *
     * @param mysqli|false $conn
     * @param int|null     $asset_id
     * @param string|null  $ticker
     * @param string       $action  create|update|delete
     * @param array|null   $old     snapshot before the change
     * @param array|null   $new     snapshot after the change
     */
    function asset_audit_log($conn, $asset_id, $ticker, $action, $old, $new) {
        if (!$conn || !is_object($conn)) {
            return;
        }
        $action = in_array($action, ['create', 'update', 'delete'], true) ? $action : 'update';

        // Field-level diff (only meaningful for updates with both snapshots present).
        $changes = null;
        if ($action === 'update' && is_array($old) && is_array($new)) {
            $diff = [];
            foreach ($new as $key => $newVal) {
                $oldVal = array_key_exists($key, $old) ? $old[$key] : null;
                if (asset_audit_values_differ($oldVal, $newVal)) {
                    $diff[$key] = ['from' => $oldVal, 'to' => $newVal];
                }
            }
            $changes = json_encode(count($diff) > 0 ? $diff : new stdClass());
        }

        $old_json = is_array($old) ? json_encode($old) : null;
        $new_json = is_array($new) ? json_encode($new) : null;
        $asset_id_val = ($asset_id !== null) ? (int) $asset_id : null;
        $ticker_val = ($ticker !== null) ? substr((string) $ticker, 0, 64) : null;

        $stmt = mysqli_prepare(
            $conn,
            'INSERT INTO asset_audit (asset_id, ticker, action, changes, old_values, new_values) VALUES (?, ?, ?, ?, ?, ?)'
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'isssss', $asset_id_val, $ticker_val, $action, $changes, $old_json, $new_json);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }
}
