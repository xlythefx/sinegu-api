# Monthly Invoice Generation Cron

Run `generate_invoices.php` at **12:01 AM on the 1st of every month** (USA timezone, e.g. America/New_York) so that "last month" is correct when the script runs.

## Linux / macOS (crontab)

Use crontab on Linux or macOS servers:

```cron
# Run invoice generation at 12:01 AM on the 1st of every month (USA Eastern)
1 0 1 * * TZ=America/New_York /usr/bin/php /path/to/sinegu-api/generate_invoices.php
```

Add to crontab: `crontab -e` and paste the line above. Adjust `/usr/bin/php` and `/path/to/sinegu-api` to your server.

## Windows VPS only: Task Scheduler

**Task Scheduler is for Windows VPS only.** On Linux/macOS, use crontab (above).

On a Windows VPS:

1. Open **Task Scheduler** (taskschd.msc).
2. Create Basic Task: name e.g. "Sinegu Monthly Invoice Generation".
3. Trigger: **Monthly**, on day **1**, at **12:01 AM**. Start time zone: **Eastern Time (US & Canada)** (or your USA timezone).
4. Action: **Start a program**.
   - Program: `C:\wamp64\bin\php\php8.x.x\php.exe` (use your WAMP PHP path).
   - Arguments: `C:\wamp64\www\sinegu-api\generate_invoices.php`
   - Start in: `C:\wamp64\www\sinegu-api`
5. Finish.

Alternatively, use the batch script in this folder and point Task Scheduler at it:

- Program: `C:\wamp64\www\sinegu-api\cron\run_generate_invoices_monthly.bat`

Adjust paths inside the batch file if your PHP or project path is different.

## Optional: include demo accounts

Default is to exclude demo accounts. To include them when running from cron:

- **curl (HTTP):**  
  `curl -X POST "https://your-api/sinegu-api/generate_invoices.php" -H "Content-Type: application/json" -d "{\"invoice_demo_accounts\":true}"`

- **CLI with stdin:**  
  `echo {"invoice_demo_accounts":true} | php C:\wamp64\www\sinegu-api\generate_invoices.php`

---

## Invoice reminders and overdue (daily cron, 1st–8th only)

After invoices are generated on the 1st, reminders and the overdue/disconnection email are sent by two scripts. **Schedule both to run daily** (e.g. 09:00 and 09:15 USA Eastern). The scripts **only do work on the 1st–8th of each month**; on other days they exit immediately with a "skipped" message (no DB or email).

- **2nd reminder:** 2 days after first invoice email  
- **3rd reminder:** 4 days after first  
- **4th reminder:** on day 7 / due date (last day)  
- **Overdue:** after due date → status set to `overdue`, disconnection email sent  

### Linux / macOS (crontab)

```cron
# Reminders: daily at 09:00 USA Eastern (script runs logic only on 1st–8th)
0 9 * * * TZ=America/New_York /usr/bin/php /path/to/sinegu-api/cron/send_invoice_reminders.php

# Overdue + disconnection: daily at 09:15 USA Eastern (script runs logic only on 1st–8th)
15 9 * * * TZ=America/New_York /usr/bin/php /path/to/sinegu-api/cron/mark_overdue_and_disconnect.php
```

### Windows VPS (Task Scheduler)

Create two scheduled tasks (both run daily; scripts no-op on days 9–31):

1. **Reminders:** Daily at 09:00 (Eastern). Action: run  
   `C:\wamp64\bin\php\php8.4.0\php.exe` with arguments  
   `C:\wamp64\www\sinegu-api\cron\send_invoice_reminders.php`

2. **Overdue:** Daily at 09:15 (Eastern). Action: run  
   `C:\wamp64\bin\php\php8.4.0\php.exe` with arguments  
   `C:\wamp64\www\sinegu-api\cron\mark_overdue_and_disconnect.php`

Adjust the PHP path to match your WAMP install. **Start in:** `C:\wamp64\www\sinegu-api` so `conn.php` and includes resolve correctly.

### Test via HTTP (localhost) vs CLI

Both reminder and overdue scripts work from **CLI** (Task Scheduler / `php.exe`) and **HTTP** (browser or curl). Use HTTP to confirm behavior without Task Scheduler.

| Script | CLI (Task Scheduler) | HTTP (browser test) |
|--------|----------------------|----------------------|
| Reminders | `php cron\send_invoice_reminders.php` | **http://localhost/sinegu-api/cron/send_invoice_reminders.php** |
| Overdue | `php cron\mark_overdue_and_disconnect.php` | **http://localhost/sinegu-api/cron/mark_overdue_and_disconnect.php** |

- JSON response includes `run_mode`: `"cli"` or `"http"`.
- Each run appends one line to `cron_reminders.log` or `cron_overdue.log` in the project root so you can verify Task Scheduler executed the script.
