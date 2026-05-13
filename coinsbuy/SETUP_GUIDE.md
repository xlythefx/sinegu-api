# Coinsbuy Payment Gateway Setup Guide

## Quick Setup Checklist

- [ ] Get Coinsbuy API credentials (API Key, API Secret, Webhook Secret)
- [ ] Configure API credentials in `config.php`
- [ ] Create a Merchant wallet in USD (recommended) OR Enterprise wallet for BTC
- [ ] Configure webhook URL in Coinsbuy dashboard
- [ ] Test the integration

---

## Step 1: Get Coinsbuy API Credentials

### For Sandbox (Testing):

**Option 1: Through Settings**
1. Go to [Coinsbuy Sandbox](https://sandbox.coinsbuy.com) or [app.coinsbuy.com](https://app.coinsbuy.com) and log in
2. Click your **user icon** (top right, shows your initials like "DC")
3. Click **"Settings"** from the dropdown menu
4. Look for **"API"** or **"API Access"** or **"API Credentials"** section
5. Enable **"Activate API user"** toggle (if available)
6. Click **"Regenerate"** or **"Create"** to generate API credentials
7. Enter your password and 2FA code if prompted
8. **Copy and save** these three values:
   - **API Key** (client_id)
   - **API Secret** (client_secret)
   - **Webhook Secret** (callback_secret)

**Option 2: Through API Guide**
1. Click your **user icon** (top right)
2. Click **"API Guide"** from the dropdown menu
3. Look for a link to **"Manage API Credentials"** or **"API Settings"**
4. Follow the steps above to generate credentials

**Option 3: Direct URL (if available)**
- Try navigating directly to: `https://app.coinsbuy.com/en/settings/api` or similar
- Or check the URL when you're in Settings to find the API section

### For Production:

1. Go to [Coinsbuy](https://coinsbuy.com) or [app.coinsbuy.com](https://app.coinsbuy.com) and log in
2. Follow the same steps as Option 1, 2, or 3 above

---

## Step 2: Configure API Credentials

Edit `coinsbuy/config.php` and replace the placeholder values:

### For Development (Sandbox):

```php
$isDevelopment = true; // Keep this as true for testing

if ($isDevelopment) {
    // DEVELOPMENT CONFIGURATION (Sandbox)
    define('COINSBUY_API_KEY', 'your_actual_sandbox_api_key_here');
    define('COINSBUY_API_SECRET', 'your_actual_sandbox_api_secret_here');
    define('COINSBUY_WEBHOOK_SECRET', 'your_actual_sandbox_webhook_secret_here');
    // ... rest stays the same
}
```

### For Production:

```php
$isDevelopment = false; // Change to false for production

} else {
    // PRODUCTION CONFIGURATION (Live Mode)
    define('COINSBUY_API_KEY', 'your_actual_production_api_key_here');
    define('COINSBUY_API_SECRET', 'your_actual_production_api_secret_here');
    define('COINSBUY_WEBHOOK_SECRET', 'your_actual_production_webhook_secret_here');
    // ... rest stays the same
}
```

---

## Step 3: Create Wallets in Coinsbuy

You need **at least one wallet** configured. Choose one option:

### Option A: Merchant Wallet (Recommended - Payment Gateway Experience)

**Best for:** Payment gateway experience similar to Stripe

1. Log in to Coinsbuy dashboard
2. Go to **"Wallets"** section
3. Click **"Create Wallet"** or **"Add Wallet"**
4. Select **"Merchant Wallet"** type
5. Choose **USD** as the currency
6. Complete the wallet setup
7. **Note the Wallet ID** (you'll need this if the auto-detection doesn't work)

**Benefits:**
- Hosted payment page (like Stripe checkout)
- Users can select any cryptocurrency
- Automatic exchange rate conversion
- Better user experience

### Option B: Enterprise Wallet (Direct Crypto Transfer)

**Best for:** Direct cryptocurrency transfers

1. Log in to Coinsbuy dashboard
2. Go to **"Wallets"** section
3. Click **"Create Wallet"** or **"Add Wallet"**
4. Select **"Enterprise Wallet"** type
5. Choose **BTC** (or ETH, USDT, USDC) as the currency
6. Complete the wallet setup

**Note:** You'll need separate wallets for each cryptocurrency (BTC, ETH, USDT, USDC)

---

## Step 4: Configure Webhook URL

**Important:** Coinsbuy cannot send webhooks to `localhost`. You need a publicly accessible URL.

### For Local Development (Using ngrok):

1. **Install and run ngrok:**
   ```bash
   ngrok http 80
   ```
   (Keep this running while testing)

2. **Copy your ngrok URL** (e.g., `https://shakira-adducible-roentgenographically.ngrok-free.dev`)

3. **Log in to Coinsbuy dashboard**
4. Go to **"Settings"** → **"Webhooks"** or **"Callbacks"**
5. Set the webhook URL:
   ```
   https://YOUR-NGROK-URL.ngrok-free.dev/sinegu-api/coinsbuy/webhook.php
   ```
   Example:
   ```
   https://shakira-adducible-roentgenographically.ngrok-free.dev/sinegu-api/coinsbuy/webhook.php
   ```

6. **Save the webhook secret** (this should match `COINSBUY_WEBHOOK_SECRET` in config.php)

**Note:** Your ngrok URL changes each time you restart ngrok (unless you have a paid plan with a fixed domain). Update the webhook URL in Coinsbuy if you restart ngrok.

### For Production:

```
https://api.sinegualfamily.com/coinsbuy/webhook.php
```
(Replace with your actual API domain)

---

## Step 5: Verify Configuration

### Check if wallets are accessible:

The system will automatically detect wallets. To verify:

1. Make sure your API credentials are correct in `config.php`
2. The system will try to find:
   - First: Merchant wallet for USD (if available)
   - Fallback: Enterprise wallet for the specified cryptocurrency (BTC, ETH, etc.)

### Test the Integration:

1. Try making a test payment from the frontend
2. Check the browser console for any errors
3. Check PHP error logs for API errors
4. Verify the webhook is receiving callbacks

---

## Troubleshooting

### Error: "No active wallet found"

**Possible causes:**
1. **No wallets created** - Create at least one wallet in Coinsbuy dashboard
2. **Wallet not active** - Make sure the wallet status is "Active" in Coinsbuy
3. **Wrong currency** - For Merchant wallets, ensure it's denominated in USD
4. **API credentials incorrect** - Verify your API key and secret are correct
5. **Wrong environment** - Make sure you're using sandbox credentials for sandbox, production for production

**Solution:**
- Check Coinsbuy dashboard → Wallets section
- Ensure at least one wallet is active
- For Merchant wallets: Should be in USD
- For Enterprise wallets: Should match the cryptocurrency you're trying to use (BTC, ETH, etc.)

### Error: "Failed to create Coinsbuy deposit"

**Possible causes:**
1. API credentials are wrong
2. API rate limit exceeded
3. Wallet not properly configured
4. Network/connectivity issues

**Solution:**
- Verify API credentials in `config.php`
- Check Coinsbuy dashboard for API status
- Review PHP error logs
- Test API connection manually

### Webhook not receiving callbacks

**Possible causes:**
1. Webhook URL not configured in Coinsbuy
2. Webhook secret mismatch
3. Server not accessible from internet (for production)
4. SSL certificate issues (for production)

**Solution:**
- Verify webhook URL in Coinsbuy dashboard
- Ensure `COINSBUY_WEBHOOK_SECRET` matches the one in Coinsbuy
- Test webhook URL is accessible (use a webhook testing tool)
- For production: Ensure HTTPS is properly configured

---

## API Credentials Location in Coinsbuy

1. **Sandbox:** https://app.coinsbuy.com (with sandbox environment selected)
2. **Production:** https://app.coinsbuy.com

**Where to find API credentials:**

Try these locations in order:
1. **User Icon** (top right) → **Settings** → Look for **"API"** or **"API Access"** section
2. **User Icon** → **API Guide** → Look for **"Manage API Credentials"** link
3. Check the left sidebar for **"API"** or **"Developer"** section
4. Direct URL: Try `https://app.coinsbuy.com/en/settings/api` or similar

**What you need:**
- **API Key** (also called client_id)
- **API Secret** (also called client_secret)  
- **Webhook Secret** (also called callback_secret)

**Note:** If you can't find API credentials:
- Contact Coinsbuy support to enable API access for your account
- Some accounts may need to request API access to be enabled
- Check if there's a "Request API Access" button or similar

---

## Next Steps

Once configured:
1. Test with a small amount in sandbox
2. Verify webhook callbacks are working
3. Test the full payment flow
4. Switch to production when ready

---

## Support

- [Coinsbuy API Documentation](https://docs.coinsbuy.com/)
- [Coinsbuy Support](https://helpdesk.coinsbuy.com/)
- Check PHP error logs: Usually in `C:\wamp64\logs\php_error.log` or your server's error log

