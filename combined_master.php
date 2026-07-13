<?php
/**
 * Combined Master (TEMPORARY) — splice a legacy account's pre-cutoff history with
 * the current master account's post-cutoff history into one continuous series.
 *
 * Context: Elias (the original master bot) was banned, so the public Bot
 * Performance chart was repointed to Dima's account. This stitches Elias's older
 * track record onto Dima's recent one so the chart reads as one master history:
 *   - Elias (legacy)  : trades with date <  cutoff   (e.g. Oct–Dec 2025)
 *   - Dima  (current) : trades with date >= cutoff   (e.g. Jan 2026 onward)
 *
 * SWITCH / SCOPE: enabled only when COMBINED_MASTER=on in THIS server's .env.
 * Because .env is per-VPS, only prod (167) sets it — staging (156) and local
 * never do, so it is naturally off there. To restore the plain single-account
 * logic when Elias returns, set COMBINED_MASTER=off (or remove the keys).
 *
 * Currency: no FX conversion — legacy (USD) and current (EUR) daily PnL are
 * concatenated as raw numbers and the chart is labelled in COMBINED_MASTER_CURRENCY.
 *
 * .env keys:
 *   COMBINED_MASTER=on|off
 *   COMBINED_MASTER_LEGACY_ID=1        # Elias  (pre-cutoff)
 *   COMBINED_MASTER_CURRENT_ID=2       # Dima   (post-cutoff; = account the dashboard requests)
 *   COMBINED_MASTER_CUTOFF=2026-01-01  # legacy < cutoff, current >= cutoff
 *   COMBINED_MASTER_CURRENCY=USD       # display currency hint (no conversion)
 */

require_once __DIR__ . '/env.php';

if (!function_exists('combined_master_config')) {
    /**
     * @return array{legacy_id:int,current_id:int,cutoff:string,currency:string}|null
     *         null when disabled or misconfigured.
     */
    function combined_master_config() {
        $on = strtolower(trim((string) env('COMBINED_MASTER', 'off')));
        if ($on !== 'on' && $on !== 'true' && $on !== '1') {
            return null;
        }
        // Hard safety net: never activate on the staging/test host (156 / andrea),
        // even if the flag is mistakenly copied into that server's .env. The .env is
        // per-VPS so this is belt-and-suspenders, letting 156 be deployed to freely.
        // Prod (167 / sinegualfamily) is not in this list, so it is unaffected.
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
        $blocked = array_filter(array_map('trim', explode(',', strtolower(
            (string) env('COMBINED_MASTER_BLOCK_HOSTS', 'andrea-orcelinvest,156.67.30.81')
        ))));
        foreach ($blocked as $b) {
            if ($b !== '' && strpos($host, $b) !== false) {
                return null;
            }
        }
        $legacy   = (int) env('COMBINED_MASTER_LEGACY_ID', 0);
        $current  = (int) env('COMBINED_MASTER_CURRENT_ID', 0);
        $cutoff   = trim((string) env('COMBINED_MASTER_CUTOFF', ''));
        $currency = trim((string) env('COMBINED_MASTER_CURRENCY', 'USD'));
        if ($legacy <= 0 || $current <= 0 || $legacy === $current) {
            return null;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $cutoff)) {
            return null;
        }
        return [
            'legacy_id'  => $legacy,
            'current_id' => $current,
            'cutoff'     => $cutoff,
            'currency'   => $currency !== '' ? $currency : 'USD',
        ];
    }
}

if (!function_exists('combined_master_for_account')) {
    /**
     * Returns the combined config when it applies to $accountId (combined mode on
     * AND $accountId is the current master the splice targets), else null.
     *
     * @return array{legacy_id:int,current_id:int,cutoff:string,currency:string}|null
     */
    function combined_master_for_account($accountId) {
        $cfg = combined_master_config();
        if ($cfg === null) {
            return null;
        }
        return ((int) $accountId === $cfg['current_id']) ? $cfg : null;
    }
}
