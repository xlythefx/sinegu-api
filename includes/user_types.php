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
 *   tester        admin portal; its Binance and Bybit accounts trade ONLY the test
 *                 feed (/binance_test_webhook, /bybit_test_webhook)
 *   quantlab_tester  admin portal; its Binance and Bybit accounts trade ONLY the
 *                 quantlab feed (/binance_quantlab_webhook, /bybit_quantlab_webhook),
 *                 which QuantLab's alerts post to (migration 103)
 *
 * Which feed a type trades is FEED_BY_USER_TYPE, below — the one place that says it.
 * fetch_binance_accounts.php / fetch_bybit_accounts.php hand the engine `feed` per
 * account from it; the engines only filter.
 *
 * Every helper here tolerates the column not existing yet: deploy-api lands the
 * PHP before `php migrate.php run` adds it, and a SELECT of a missing column
 * throws (mysqli raises by default since PHP 8.1) — on users/login.php that
 * would lock everyone out for the length of the gap. Without the column every
 * account reads as 'user'.
 */

const USER_TYPES = ['user', 'admin', 'developer', 'collaborator', 'tester', 'quantlab_tester'];

/** Types that may open the admin portal. */
const ADMIN_PORTAL_TYPES = ['admin', 'developer', 'collaborator', 'tester', 'quantlab_tester'];

/** The only type that is billed and counted as a customer. */
const CUSTOMER_USER_TYPE = 'user';

const TESTER_USER_TYPE = 'tester';
const QUANTLAB_TESTER_USER_TYPE = 'quantlab_tester';

/** Signal feeds (the crypto engines' hooks.FEEDS). trade_logs.feed is VARCHAR(8). */
const MAIN_FEED = 'main';
const SIGNAL_FEEDS = ['main', 'test', 'quantlab'];

/**
 * The account types whose Binance / Bybit accounts trade a feed other than main —
 * and ONLY that feed. Every type not listed here is on main.
 */
const FEED_BY_USER_TYPE = [
    'tester'          => 'test',
    'quantlab_tester' => 'quantlab',
];

if (!function_exists('user_type_feed')) {
    /** The signal feed an account type's crypto accounts trade on ('main' for anything unlisted). */
    function user_type_feed(?string $type): string {
        return FEED_BY_USER_TYPE[(string) $type] ?? MAIN_FEED;
    }
}

if (!function_exists('feed_user_types')) {
    /** The types that trade `$feed` — empty for main, which is "every type not listed". */
    function feed_user_types(string $feed): array {
        return array_keys(array_filter(FEED_BY_USER_TYPE, function ($f) use ($feed) {
            return $f === $feed;
        }));
    }
}

if (!function_exists('is_signal_feed')) {
    function is_signal_feed($feed): bool {
        return is_string($feed) && in_array($feed, SIGNAL_FEEDS, true);
    }
}

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
     * Why a type change that moves the user to another feed (user_type_feed differs)
     * is refused while this is true: Binance merges positions by symbol + side, so
     * after the switch the OTHER feed's exit signal would close an entry this feed
     * opened. Close first, then change the type.
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
        return refresh_engine_accounts(5001);
    }
}

if (!function_exists('refresh_bybit_engine_accounts')) {
    /** The same for bybit-flask (:5004). Only staging runs it; elsewhere the connect is refused at once. */
    function refresh_bybit_engine_accounts(): bool {
        return refresh_engine_accounts(5004);
    }
}

if (!function_exists('user_has_open_bybit_positions')) {
    /**
     * user_has_open_binance_positions() for Bybit (bybit_positions, migration 099).
     * False on a box without the bybit_* tables — nobody can hold a Bybit position there.
     */
    function user_has_open_bybit_positions($conn, string $uniId): bool {
        try {
            $stmt = mysqli_prepare(
                $conn,
                'SELECT 1 FROM bybit_positions p
                 JOIN bybit_accounts a ON a.api_key = p.api_key
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
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('account_feed_by_uni_id')) {
    /**
     * uni_id => feed for every account whose type trades a non-main feed (one query).
     * A uni_id that is absent is on main. Empty — everyone on main — when the type
     * column does not exist yet or the read fails (as the tester-only lookup it
     * replaces did): the non-main feeds then match no account at all. Before migration
     * 103 the ENUM has no 'quantlab_tester', which the IN () simply never matches.
     */
    function account_feed_by_uni_id($conn): array {
        $feeds = [];
        try {
            if (!user_credentials_has_type($conn)) {
                return [];
            }
            $in = "'" . implode("','", array_keys(FEED_BY_USER_TYPE)) . "'";
            $res = mysqli_query($conn, "SELECT uni_id, `type` FROM user_credentials WHERE `type` IN ($in)");
            if ($res) {
                while ($r = mysqli_fetch_assoc($res)) {
                    $feeds[(string) $r['uni_id']] = user_type_feed((string) $r['type']);
                }
                mysqli_free_result($res);
            }
        } catch (Throwable $e) {
            return [];
        }
        return $feeds;
    }
}

if (!function_exists('refresh_engine_accounts')) {
    /** POST 127.0.0.1:<port>/admin/refresh-assets — drops a crypto engine's account cache. Best effort. */
    function refresh_engine_accounts(int $port): bool {
        if (!function_exists('curl_init')) {
            return false;
        }
        $ch = curl_init("http://127.0.0.1:{$port}/admin/refresh-assets");
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
