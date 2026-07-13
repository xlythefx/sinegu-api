# Combined Master (TEMPORARY — prod/167 only)

The public **Bot Performance Analytics** chart normally shows the account named
`master`. Elias (the original master bot) was banned, so the chart was repointed
to **Dima** (account id 2). This feature lets us splice Elias's older track record
onto Dima's recent one so the chart reads as one continuous master history.

- **Elias (legacy, id 1)** — trades **before** the cutoff (Oct–Dec 2025)
- **Dima (current, id 2)** — trades **from** the cutoff onward (Jan 2026 →)
- Shown in **USD**, no FX conversion (raw numbers concatenated).

## The switch

Controlled entirely by `.env` on each server (`combined_master.php` reads it):

```
COMBINED_MASTER=on|off
COMBINED_MASTER_LEGACY_ID=1
COMBINED_MASTER_CURRENT_ID=2
COMBINED_MASTER_CUTOFF=2026-01-01
COMBINED_MASTER_CURRENCY=USD
COMBINED_MASTER_BLOCK_HOSTS=andrea-orcelinvest,156.67.30.81
```

- **OFF / keys absent** → plain single-account logic (chart shows the account named `master`).
- **ON** → splice id 1 + id 2 across the cutoff.

## Deployment — the one rule

**The code ships to BOTH 167 and 156. Only 167 should ever have `COMBINED_MASTER=on`.**

You do **not** have to think about this when deploying to **156 (staging/test)**:

1. `.env` is per-VPS and never copied by the deploy — so 156 simply never has the flag.
2. Even if the flag *were* copied to 156 by mistake, `COMBINED_MASTER_BLOCK_HOSTS`
   makes the code **refuse to splice on the staging host**. 156 is safe by construction.

So: **deploy to 156 freely and ignore this feature.** It only ever activates on 167.

## Turn it on (167 only)

On the prod VPS edit `C:\wamp64\www\sinegualerts\api\.env`, set `COMBINED_MASTER=on`
(the other keys are already correct), then restart WAMP.

## Turn it off (when Elias returns)

Set `COMBINED_MASTER=off` on 167 and restart WAMP. To fully revert, rename the
account back to `master` as desired — the OFF path finds the master account **by name**,
not by id.

## Files

- `combined_master.php` — the switch + host guard.
- `account_stats_filtered.php`, `users/get_account_daily_pnl.php` — splice when active.
- Frontend `BotPerformance.tsx` reads the `currency` hint to render USD.
