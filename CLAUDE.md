# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Navigation

Always `cd` out of `c:\wamp64\www\sinegu-api` before navigating to sibling projects:

| Project | Path |
|---|---|
| React Dashboard | `C:\Users\Xlythe\sinequal-dash-fusion-main` |
| Capital.com Flask bot | `C:\Users\Xlythe\SineguVariants - Branches\CapitalFlask` |
| Binance Flask bot | `C:\Users\Xlythe\binance-flask` |
| IG.com Flask bot | `C:\Users\Xlythe\SineguVariants - Branches\ig-flask` |
| Deployment tool (paramiko migrations) | `C:\wamp64\www\deployment` |

## Deployment / Remote Migrations

Reusable paramiko-based migration runner at `C:\wamp64\www\deployment` (standalone, never committed — `targets.json` holds VPS credentials). Applies pending `NNN_*.sql` migrations on remote Windows/WAMP VPSes over SSH instead of pasting SQL by hand. Switching env is just the target name:

```bash
cd C:\wamp64\www\deployment
python deploy.py ping    <target>            # check SSH auth + remote PHP
python deploy.py status  <target> <project>  # read-only applied vs pending
python deploy.py migrate <target> <project>  # sync .sql + runner, run pending migrations
```

| Target | Host | Project → remote path |
|---|---|---|
| `staging` | 156.67.30.81 | `andrea-orcelinvest` → `C:\wamp64\www\andrea-orcelinvest\api` |
| `production` | 167.86.109.159 | `sinegualerts` → `C:\wamp64\www\sinegualerts` |

New migrations are authored locally in `deployment\migrations\<project>\`. Reuses the same `migrate.php` runner pattern as `sinegu-api/migrations/`.

## Running the Flask Bots

Each Flask bot has a Tkinter launcher (run from project root):

```bash
# Capital.com bot — Flask :5000, Accounts listener :5001
python sinegu_tradingbot.py

# Binance bot — Flask :5001
python binance_tradingbot.py
```

Or headless (module form):
```bash
python -m src.main          # Flask webhook server
python -m src.accounts_listener  # Capital only: session refresh loop
python -m src.fetch_balances
python -m src.fetch_positions
```

## Architecture Overview

### System Flow

TradingView sends a JSON webhook → PHP API (sinegu-api on WAMP/Apache) → proxies to Flask bot → broker API (Capital.com / Binance / IG).

```
TradingView
    │
    ▼
sinegu-api (PHP/Apache :80)
    ├─ webhook_proxy.php        → Capital.com Flask (:5000/webhook)
    ├─ flask/proxy_webhook.php  → any Flask (configurable url + path)
    └─ flask/                   → shared data endpoints called by all bots
         ├─ fetch_accounts.php
         ├─ fetch_assets.php
         ├─ fetch_bot_config.php
         ├─ insert_position.php / insert_trade.php
         └─ ... (all bot ↔ DB communication)

Flask bots (Python)
    ├─ CapitalFlask   src/main.py        :5000  (Capital.com)
    ├─ CapitalFlask   src/accounts_listener.py  :5001  (session refresh)
    ├─ binance-flask  src/main.py        :5001  (Binance Futures)
    └─ ig-flask       src/main.py        (IG.com)
```

### PHP API (`sinegu-api`)

- **Entry point**: Apache at `http://localhost/sinegu-api/`. `index.php` returns 403 for all direct hits; `.htaccess` handles rewrites.
- **DB**: MySQL (`sinegu_db`) via `conn.php` (root, no password, localhost). All endpoints `require_once` conn.php.
- **Pattern**: Each PHP file is a standalone REST endpoint returning `application/json`. No MVC framework.
- **Auth**: Most endpoints use a secret header or POST field; shared secrets are in `flask/config_bot_listener.php`.
- **Logging**: `includes/api_log.php` writes to `api_logs` table. `includes/audit_log.php` for admin actions.
- **Sub-directories by broker**: `flask/` (Capital.com), `binance-flask/` (Binance), `igcom/` (IG.com), `coinsbuy/`, `stripe/`.
- **Migrations**: Sequential SQL files in `migrations/` (e.g. `001_...sql`). Run corresponding `run_*_migration.php` via browser or CLI.
- **Cron**: Scripts in `cron/` (invoices, reminders, overdue disconnect).

### Capital.com Flask (`CapitalFlask/src/`)

| File | Role |
|---|---|
| `config.py` | All constants. Switch `API_BASE_URL` for local vs prod. Ports, secrets, queue settings. |
| `main.py` | Flask app v3.4. `/webhook` (TradingView), `/admin/*` routes, webhook queue worker thread. |
| `trading_handler.py` | `handle_add`, `handle_sell`, `handle_exit` — pyramid position logic. |
| `capital_api.py` | Capital.com REST wrapper (`CapitalComAPI` class). |
| `accounts_listener.py` | Separate Flask on :5001. Receives PHP notification on broker connect; runs login loop. |
| `position_sync.py` | Syncs Capital positions back to sinegu-api DB. |
| `telegram_utils.py` / `tg_commands.py` | Telegram alert sending and `/status` command handler. |

Webhook queue serializes TradingView signals to avoid Capital.com rate limits. Config controls delay between accounts, queue max size, and 429 back-off requeue policy (`WEBHOOK_*` constants in `config.py`).

### Binance Flask (`binance-flask/src/`)

| File | Role |
|---|---|
| `hooks.py` | All constants (equivalent of CapitalFlask's config.py). `API_BASE_URL`, `WEBHOOK_SECRET`, ports. |
| `main.py` | Flask app. `/binance_webhook` (TradingView), `/health`. Fetches accounts fresh per request. |
| `trading_handler.py` | Executes Binance Futures orders. |
| `binance_api.py` | Binance REST + position mode API. |
| `binance_accounts_api.py` | Fetches enabled accounts from sinegu-api; demo/live detection. |
| `assets_api.py` | Fetches assets (tickers, symbols, sizes) from sinegu-api. |
| `routes/webhook.py` | Webhook Blueprint. |
| `routes/admin.py` | Admin Blueprint. |

Position mode (hedge vs one-way) is configured via `POSITION_MODE` in `hooks.py` and can be synced on startup with `BINANCE_SYNC_POSITION_MODE_ON_STARTUP`.

### Webhook Payload (TradingView → Flask)

```json
{
  "secret": "your_webhook_secret",
  "action": "add",
  "symbol": "XAUUSDT",
  "close": 2350.0,
  "quantity": 0.01
}
```

Actions: `add` / `buy` → open long; `sell` → open short; `exit` → close; `order` → raw order.

### Shared Secrets (must match across PHP + Python)

| Secret | PHP constant | Python constant |
|---|---|---|
| Accounts listener | `BOT_LISTENER_SECRET` in `flask/config_bot_listener.php` | `ACCOUNTS_LISTENER_SECRET` in `src/config.py` |
| Disable account | `DISABLE_ACCOUNT_SECRET` | `DISABLE_ACCOUNT_SECRET` |
| Capital API log | `CAPITAL_API_LOG_SECRET` | `CAPITAL_API_LOG_SECRET` |

### React Dashboard

Located at `C:\Users\Xlythe\sinequal-dash-fusion-main`. Communicates with sinegu-api PHP endpoints. Uses `flask/proxy_webhook.php` to forward test webhooks (avoids CORS). The proxy accepts optional `flask_url` and `flask_path` fields stripped before forwarding.
