<?php
/**
 * Account types — user_credentials.type (migration 095).
 *
 * One definition of who is what, so a gate is never re-spelled slightly
 * differently in two endpoints. The dashboard's twin is src/lib/user-types.ts;
 * keep the two lists identical.
 *
 *   user          a customer — the default, and the ONLY type that is invoiced or
 *                 counted in customer stats
 *   admin         full admin portal ("Administrator" in the UI)
 *   developer     admin + the developer-only pages (API Keys, Database)
 *   collaborator  full admin portal for now
 *   tester        admin portal; its Binance accounts trade ONLY the test feed
 *                 (/binance_test_webhook) — fetch_binance_accounts.php hands the
 *                 engine `feed` per account, the engine only filters
 *
 * Every helper here tolerates the column not existing yet: deploy-api lands the
 * PHP before `php migrate.php run` adds it, and a SELECT of a missing column
 * throws (mysqli raises by default since PHP 8.1) — on users/login.php that
 * would lock everyone out for the length of the gap. Without the column every
 * account reads as 'user'.
 */

const USER_TYPES = ['user', 'admin', 'developer', 'collaborator', 'tester'];

/** Types that may open the admin portal. */
const ADMIN_PORTAL_TYPES = ['admin', 'developer', 'collaborator', 'tester'];

/** The only type that is billed and counted as a customer. */
const CUSTOMER_USER_TYPE = 'user';

const TESTER_USER_TYPE = 'tester';

if (!function_exists('user_credentials_has_type')) {
    /** True once migration 095 has added user_credentials.type. Cached per request. */
    function user_credentials_has_type($conn): bool {
        static $has = null;
        if ($has !== null) {
            return $has;
        }
        try {
            $res = mysqli_query(
                $conn,
                "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_credentials' AND COLUMN_NAME = 'type'"
            );
            $row = $res ? mysqli_fetch_assoc($res) : null;
            $has = $row && (int) $row['c'] > 0;
        } catch (Throwable $e) {
            $has = false;
        }
        return $has;
    }
}

if (!function_exists('user_type_select')) {
    /**
     * SELECT fragment for the type: "`type`" (or "alias.`type`") when the column
     * exists, otherwise the literal 'user' — always aliased `type`, so callers can
     * read $row['type'] either way.
     */
    function user_type_select($conn, string $alias = ''): string {
        if (!user_credentials_has_type($conn)) {
            return "'user' AS `type`";
        }
        return ($alias !== '' ? $alias . '.' : '') . '`type` AS `type`';
    }
}

if (!function_exists('is_valid_user_type')) {
    function is_valid_user_type($type): bool {
        return is_string($type) && in_array($type, USER_TYPES, true);
    }
}

if (!function_exists('user_type_of')) {
    /** The account's type, or null when the uni_id does not exist. */
    function user_type_of($conn, string $uniId): ?string {
        $stmt = mysqli_prepare(
            $conn,
            'SELECT ' . user_type_select($conn) . ' FROM user_credentials WHERE uni_id = ? LIMIT 1'
        );
        if (!$stmt) {
            return null;
        }
        mysqli_stmt_bind_param($stmt, 's', $uniId);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        return $row ? (string) $row['type'] : null;
    }
}

if (!function_exists('user_has_open_binance_positions')) {
    /**
     * True when the user holds a Binance position, per the poller's last sync
     * (binance_positions), on any account that is not deleted.
     *
     * Why a type change to/from tester is refused while this is true: Binance merges
     * positions by symbol + side, so after the switch the OTHER feed's exit signal
     * would close an entry this feed opened. Close first, then change the type.
     */
    function user_has_open_binance_positions($conn, string $uniId): bool {
        $stmt = mysqli_prepare(
            $conn,
            'SELECT 1 FROM binance_positions p
             JOIN binance_accounts a ON a.api_key = p.api_key
             WHERE a.deleted_at IS NULL AND p.uni_id = ? AND p.position_amt <> 0
             LIMIT 1'
        );
        if (!$stmt) {
            return false;
        }
        mysqli_stmt_bind_param($stmt, 's', $uniId);
        mysqli_stmt_execute($stmt);
        $has = (bool) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        return $has;
    }
}

if (!function_exists('refresh_binance_engine_accounts')) {
    /**
     * Ask binance-flask (:5001) to drop its 90 s account cache, so a type change
     * re-routes the user's accounts to the right feed now instead of after expiry.
     * Best effort: false when the engine is unreachable (the cache expires anyway).
     */
    function refresh_binance_engine_accounts(): bool {
        if (!function_exists('curl_init')) {
            return false;
        }
        $ch = curl_init('http://127.0.0.1:5001/admin/refresh-assets');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $body !== false && $code >= 200 && $code < 300;
    }
}
