# Coinsbuy Sandbox Testing Guide

## How to Test Payments in Sandbox Mode

### Current Status
✅ You're already in sandbox mode (config.php has `$isDevelopment = true`)
✅ Your payment page is working (you can see the Coinsbuy payment page)

---

## Testing Methods

### Method 1: Use Coinsbuy Testnet Faucet (Recommended)

1. **Get Testnet Cryptocurrency:**
   - In Coinsbuy dashboard, go to your **user icon** → **"Testnet Faucet"**
   - Select the cryptocurrency you want to test (USDT, BTC, ETH, etc.)
   - Click "Get Test Coins" or similar button
   - This will give you test cryptocurrency in your Coinsbuy wallet

2. **Make a Test Payment:**
   - On the payment page you're viewing, you'll see:
     - QR code
     - Cryptocurrency address
     - Amount in crypto (e.g., "BTC 0.00028806")
   - Copy the address or scan the QR code
   - Send the test cryptocurrency from your Coinsbuy wallet to that address
   - The payment will be automatically detected

3. **Verify Payment:**
   - Wait for blockchain confirmation (usually instant in sandbox)
   - Coinsbuy will send a webhook to your server
   - Your `billing_periods` table should update: `status = 'paid'`
   - Check your frontend - the invoice should show as paid

---

### Method 2: Simulate Payment via Coinsbuy Dashboard

1. **Go to Coinsbuy Dashboard:**
   - Navigate to **Wallet management** → **Deposits**
   - Find your deposit (Order #2445 or similar)
   - Look for a "Simulate Payment" or "Test Payment" button
   - Some sandbox environments allow you to manually trigger payment simulation

2. **Or Use Coinsbuy API:**
   - You can manually trigger a test payment via API (if Coinsbuy supports it)
   - Check Coinsbuy documentation for sandbox testing endpoints

---

### Method 3: Manual Webhook Testing

If you want to test the webhook directly without making a real payment:

1. **Get a sample webhook payload** from Coinsbuy documentation
2. **Send it to your webhook URL:**
   ```
   POST http://localhost/sinegu-api/coinsbuy/webhook.php
   ```
3. **Use a tool like Postman or curl** to send the test webhook

---

## Changing Default Cryptocurrency to USDT

The payment page currently shows Bitcoin, but you can:

### Option A: Select USDT on Payment Page
- On the Coinsbuy payment page, look for a cryptocurrency selector
- Choose **USDT** instead of Bitcoin
- The address and amount will update automatically

### Option B: Make USDT the Default (Already Done)
✅ I've updated the code to default to USDT
- Frontend now sends `cryptocurrency: 'USDT'`
- Backend will pre-select USDT on the payment page
- Users can still change it if needed

---

## Testing Checklist

- [ ] Payment page loads correctly
- [ ] USDT is pre-selected (or can be selected)
- [ ] QR code and address are displayed
- [ ] Amount in USDT is shown correctly
- [ ] Test payment sent from Coinsbuy testnet faucet
- [ ] Webhook received and processed
- [ ] `billing_periods.status` updated to 'paid'
- [ ] Frontend shows invoice as paid
- [ ] HWM updated in accounts table

---

## Common Issues

### Issue: "Waiting for transactions..." never completes
**Solution:**
- Make sure you're using testnet cryptocurrency (not real crypto)
- Check that you sent to the correct address
- Verify the amount matches exactly
- Check Coinsbuy dashboard → Deposits to see deposit status

### Issue: Webhook not received
**Solution:**
- Verify webhook URL is configured in Coinsbuy: `http://localhost/sinegu-api/coinsbuy/webhook.php`
- Check if your local server is accessible (might need ngrok for testing)
- Check PHP error logs for webhook processing errors
- Verify webhook secret matches in config.php

### Issue: Payment shows but billing_periods not updated
**Solution:**
- Check webhook logs
- Verify `tracking_id` format matches: `billing_{id}_{timestamp}`
- Check database connection
- Verify billing_period_id exists in database

---

## Quick Test Steps

1. **Get Test USDT:**
   - Coinsbuy Dashboard → User Icon → Testnet Faucet → Select USDT → Get Test Coins

2. **Make Payment:**
   - On payment page, select USDT (if not already selected)
   - Copy the USDT address
   - Send test USDT from Coinsbuy wallet to that address

3. **Verify:**
   - Check Coinsbuy dashboard → Deposits → Status should be "Paid"
   - Check your database: `SELECT * FROM billing_periods WHERE id = 129` → status should be 'paid'
   - Refresh frontend → Invoice should show as paid

---

## Note About Sandbox Mode

The yellow banner says: **"You are in Sandbox Mode. Do NOT send real cryptocurrency."**

- ✅ All transactions are simulated
- ✅ Use testnet cryptocurrency only
- ✅ No real money involved
- ✅ Perfect for testing

Once everything works in sandbox, switch to production by:
1. Change `$isDevelopment = false` in `config.php`
2. Use production API credentials
3. Use real cryptocurrency (or production testnet)

