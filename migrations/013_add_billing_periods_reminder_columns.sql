-- Reminder and invoice-sent tracking for billing_periods
-- Used by cron: send_invoice_reminders.php (2nd, 3rd, 4th reminder) and mark_overdue_and_disconnect.php (overdue + disconnection email)

ALTER TABLE billing_periods
  ADD COLUMN reminder_count TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of reminder emails sent (0=initial only, 1=2nd, 2=3rd, 3=4th)' AFTER due_date,
  ADD COLUMN last_reminder_sent_at DATETIME NULL COMMENT 'When the last reminder (2nd/3rd/4th) was sent' AFTER reminder_count,
  ADD COLUMN invoice_sent_at DATETIME NULL COMMENT 'When the first invoice email was sent' AFTER last_reminder_sent_at;
