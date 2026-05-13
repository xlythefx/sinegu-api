# Coinsbuy Webhook Debugging Guide

## Issue: Order Shows "Unresolved" Despite Confirmed Payments

If your Coinsbuy payment page shows "Unresolved" status even though payments are confirmed (have confirmations), this guide will help you debug.

---

## Quick Checks

### 1. Check if Webhook is Being Called

**Check PHP Error Logs:**
- On Windows with WAMP: Check `C:\wamp64\logs\php_error.log` or `C:\wamp64\logs\apache_error.log`
- Look for lines starting with `Coinsbuy webhook:`

**What to look for:**
```
Coinsbuy webhook called: 2026-01-09 20:47:00
Coinsbuy webhook: deposit_id=123, deposit_status=5, transfer_status=2, tracking_id=billing_123_...
Coinsbuy webhook: Processing - Transfer is Confirmed (2), deposit_status=5
```

**If you see NO webhook logs:**
- Webhook is not being called by Coinsbuy
- Check webhook URL configuration (see Step 2)

### 2. Verify Webhook URL in Coinsbuy Dashboard

1. Go to Coinsbuy Sandbox Dashboard
2. Navigate to **Settings** → **API** or **Webhooks**
3. Check the **Callback URL** or **Webhook URL**
4. It should be: `https://your-domain.com/sinegu-api/coinsbuy/webhook.php`
   - For local testing with ngrok: `https://YOUR-NGROK-URL.ngrok-free.dev/sinegu-api/coinsbuy/webhook.php`
   - Example: `https://shakira-adducible-roentgenographically.ngrok-free.dev/sinegu-api/coinsbuy/webhook.php`
   - ❌ `http://localhost/sinegu-api/coinsbuy/webhook.php` (won't work - Coinsbuy can't reach localhost)

**Important:** Coinsbuy **cannot** send webhooks to `localhost` or `127.0.0.1`. You need a public URL.

### 3. Test Webhook Manually

You can test if your webhook endpoint is accessible:

**Using curl (PowerShell):**
```powershell
curl -X POST http://localhost/sinegu-api/coinsbuy/webhook.php `
  -H "Content-Type: application/json" `
  -d '{"test": "webhook"}'
```

**Expected response:**
- If webhook is accessible: You'll get a JSON response (might be an error, but that's OK - it means the endpoint is reachable)
- If webhook is not accessible: Connection error

---

## Understanding "Unresolved" Status

According to Coinsbuy documentation, **"Unresolved" (status 5)** happens when:
- The amount of an incoming transfer is **greater** than the deposit amount
- A payment is received **after** the specified expiration date
- A payment is received for a deposit that was already **Paid** or **Canceled**

**However**, if transfers are **Confirmed** (status 2), the payment is valid and should be processed.

### Our Webhook Logic

The webhook now processes payments when:
1. ✅ Deposit status is **3 (Paid)** - normal case
2. ✅ Transfer status is **2 (Confirmed)** - even if deposit is Unresolved

This means if you have confirmed transfers, the webhook should process them and mark your invoice as paid.

---

## Debugging Steps

### Step 1: Check Webhook Logs

1. Open your PHP error log file
2. Search for `Coinsbuy webhook`
3. Look for the latest webhook call

**Example log entry:**
```
[09-Jan-2026 20:47:00 UTC] Coinsbuy webhook called: 2026-01-09 20:47:00
[09-Jan-2026 20:47:00 UTC] Coinsbuy webhook: deposit_id=123, deposit_status=5, transfer_status=2, tracking_id=billing_456_1234567890
[09-Jan-2026 20:47:00 UTC] Coinsbuy webhook: Processing - Transfer is Confirmed (2), deposit_status=5
```

### Step 2: Check Database

Check if the billing period was updated:

```sql
SELECT id, status, total_fee, currency_type, month_year 
FROM billing_periods 
WHERE id = YOUR_BILLING_PERIOD_ID;
```

**Expected:** `status` should be `'paid'` if webhook processed successfully.

### Step 3: Check Webhook Response

If webhook is being called but failing, check the error logs for:
- Signature verification failures
- Database errors
- Amount mismatch errors

**Common errors:**
- `Coinsbuy webhook signature verification failed` → Check `COINSBUY_WEBHOOK_SECRET` in `config.php`
- `Coinsbuy webhook: Payment amount mismatch` → Payment amount doesn't match invoice amount
- `Database connection failed` → Check `conn.php` database connection

### Step 4: Manually Trigger Webhook (Advanced)

If webhook is not being called automatically, you can manually check the deposit status:

1. Go to Coinsbuy Dashboard → Deposits
2. Find your deposit (by tracking_id or deposit_id)
3. Check the status
4. If status is "Unresolved" but transfers are confirmed, the webhook should process it

**Note:** Coinsbuy should automatically send webhooks when status changes. If not, there might be a delay or the webhook URL is incorrect.

---

## Solutions

### Solution 1: Webhook Not Being Called

**Problem:** No webhook logs found

**Fix:**
1. Verify webhook URL in Coinsbuy dashboard is correct and publicly accessible
2. Check if your server/firewall is blocking incoming requests
3. For local testing, use **ngrok** or **localtunnel** to expose your local server:
   ```bash
   ngrok http 80
   # Use the ngrok URL in Coinsbuy webhook configuration
   ```

### Solution 2: Webhook Called But Not Processing

**Problem:** Webhook logs show it's called but status not updated

**Fix:**
1. Check error logs for specific error messages
2. Verify database connection
3. Check if billing_period_id is correctly extracted from tracking_id
4. Verify amount matching (webhook compares payment amount to invoice amount)

### Solution 3: Unresolved Status Persists

**Problem:** Order shows "Unresolved" even after webhook processes

**Fix:**
- This is normal! The webhook processes confirmed transfers even if Coinsbuy shows "Unresolved"
- Check your database - if `billing_periods.status = 'paid'`, the payment was processed successfully
- The "Unresolved" status in Coinsbuy might be due to overpayment or timing, but your system will mark it as paid

---

## Testing Checklist

- [ ] Webhook URL is publicly accessible (not localhost)
- [ ] Webhook URL is configured in Coinsbuy dashboard
- [ ] PHP error logs show webhook calls
- [ ] Database shows `billing_periods.status = 'paid'` after payment
- [ ] No signature verification errors in logs
- [ ] No database connection errors in logs

---

## Need More Help?

If webhook is still not working:
1. Share the latest webhook log entries
2. Share the Coinsbuy deposit status from dashboard
3. Share any error messages from PHP error logs
4. Verify the webhook URL is accessible from outside your network

