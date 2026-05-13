# Coinsbuy Integration

This folder contains the Coinsbuy cryptocurrency payment gateway integration.

**Important:** Coinsbuy is fundamentally different from Stripe:
- **Stripe**: Direct payment processing with checkout sessions
- **Coinsbuy**: Cryptocurrency deposit system where users send crypto to addresses

## Files

- **`config.php`** - Configuration file for Coinsbuy API credentials and settings
- **`create-payment.php`** - Creates a Coinsbuy deposit request for invoice payments
- **`webhook.php`** - Handles webhook callbacks from Coinsbuy for deposit status updates
- **`README.md`** - This file

## How Coinsbuy Works

### Merchant Wallet (Payment Gateway - Recommended)

1. **Create Deposit**: API creates a deposit request with amount (USD) in a Merchant wallet
2. **Payment Page**: Returns a hosted payment page URL where users can select cryptocurrency
3. **User Pays**: User visits payment page, selects cryptocurrency, and completes payment
4. **Webhook Notification**: Coinsbuy sends webhook when deposit is confirmed
5. **Payment Processing**: System marks billing period as paid

### Enterprise Wallet (Direct Crypto Transfer)

1. **Create Deposit**: API creates a deposit request and returns a cryptocurrency address
2. **User Sends Crypto**: User sends cryptocurrency to the provided address
3. **Webhook Notification**: Coinsbuy sends webhook when deposit is confirmed
4. **Payment Processing**: System marks billing period as paid

**Note:** Merchant wallets are recommended for a payment gateway experience similar to Stripe. Enterprise wallets are for direct cryptocurrency transfers.

## Setup Instructions

### 1. Obtain API Credentials

1. Log in to your Coinsbuy account
2. Click your user icon → "Access list"
3. Navigate to the "API" tab
4. Optionally whitelist IP addresses for API access
5. Enable "Activate API user" toggle
6. Click "Regenerate" in "Your API access credentials" section
7. Enter your password and 2FA code
8. Copy and store your API key and secret securely

### 2. Configure API Credentials

Edit `config.php` and update the following:

**For Development (Sandbox):**
```php
define('COINSBUY_API_KEY', 'YOUR_SANDBOX_API_KEY_HERE');
define('COINSBUY_API_SECRET', 'YOUR_SANDBOX_API_SECRET_HERE');
define('COINSBUY_WEBHOOK_SECRET', 'YOUR_SANDBOX_WEBHOOK_SECRET_HERE');
```

**For Production:**
```php
define('COINSBUY_API_KEY', 'YOUR_PRODUCTION_API_KEY_HERE');
define('COINSBUY_API_SECRET', 'YOUR_PRODUCTION_API_SECRET_HERE');
define('COINSBUY_WEBHOOK_SECRET', 'YOUR_PRODUCTION_WEBHOOK_SECRET_HERE');
```

### 3. Configure Webhook URL

In your Coinsbuy dashboard, set the webhook URL to:
- Development: `http://localhost/sinegu-api/coinsbuy/webhook.php`
- Production: `https://api.sinegualfamily.com/coinsbuy/webhook.php`

### 4. Test the Integration

1. Use the sandbox environment to test payments
2. Use the Testnet faucet to get test funds
3. Create a test payment and verify webhook callbacks

## API Endpoints

### Create Deposit

**Endpoint:** `POST /coinsbuy/create-payment.php`

**Request Body:**
```json
{
  "billing_period_id": 123,
  "amount": 100.00,
  "currency": "USD",
  "cryptocurrency": "BTC"
}
```

**Response (Merchant Wallet - Payment Page):**
```json
{
  "success": true,
  "deposit_id": "2205",
  "status": 2,
  "amount": 100.00,
  "currency": "USD",
  "cryptocurrency": "BTC",
  "tracking_id": "billing_123_1234567890",
  "payment_url": "https://pay-sandbox.coinsbuy.com/en/a08c7567-956c-4922-b80b-6e515718a9a4/pay",
  "payment_type": "payment_page",
  "payment_page": "https://pay-sandbox.coinsbuy.com/en/a08c7567-956c-4922-b80b-6e515718a9a4/pay",
  "target_amount_requested": "100.00",
  "rate_expires_at": "2024-02-06T16:39:06.507593Z",
  "message": "Payment page created. Visit the payment page to complete payment with cryptocurrency."
}
```

**Response (Enterprise Wallet - Direct Address):**
```json
{
  "success": true,
  "deposit_id": "2205",
  "status": 2,
  "amount": 100.00,
  "currency": "USD",
  "cryptocurrency": "BTC",
  "tracking_id": "billing_123_1234567890",
  "payment_url": "1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa",
  "payment_type": "destination_address",
  "destination": "1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa",
  "message": "Deposit created. Send BTC to the provided address."
}
```

**Note:** 
- **Merchant Wallet**: Redirect users to the `payment_page` URL where they can select cryptocurrency and complete payment
- **Enterprise Wallet**: Users send cryptocurrency directly to the `destination` address
- The system will be notified via webhook when the deposit is confirmed

### Webhook

**Endpoint:** `POST /coinsbuy/webhook.php`

Coinsbuy will send webhook callbacks when payment status changes. The webhook verifies HMAC-SHA256 signatures for security.

## Supported Cryptocurrencies

Currently supported:
- BTC (Bitcoin)
- ETH (Ethereum)
- USDT (Tether)
- USDC (USD Coin)

Add more in `config.php` under `SUPPORTED_CRYPTOCURRENCIES`.

## Database

The integration uses a tracking table `coinsbuy_deposits` to link Coinsbuy deposits to billing periods, since Coinsbuy may not support custom metadata in deposit creation.

**Run migration:**
```bash
php migrations/run_coinsbuy_deposits_migration.php
```

## Security

- All webhook callbacks are verified using HMAC-SHA256 signatures
- API credentials are stored in `config.php` (keep this file secure)
- Use environment variables or secure vaults in production
- Access tokens are cached to reduce API calls

## API Structure

Coinsbuy uses **JSON API specification** (https://jsonapi.org/):
- All requests/responses follow: `{ "data": { "type": "...", "attributes": {...} } }`
- Content-Type: `application/vnd.api+json`
- Authentication: OAuth 2.0 with `client_id` and `client_secret`

## Documentation

- [Coinsbuy API Documentation](https://docs.coinsbuy.com/)
- [Coinsbuy API Overview](https://docs.coinsbuy.com/api-guide/api-overview)
- [Coinsbuy Authentication](https://docs.coinsbuy.com/api-guide/authentication)
- [Verifying Callback Signatures](https://helpdesk.sandbox.coinsbuy.com/hc/en-us/articles/37788101510161-Verifying-COINSBUY-Callback-Signatures)

## Key Differences from Stripe

| Feature | Stripe | Coinsbuy (Merchant Wallet) | Coinsbuy (Enterprise Wallet) |
|---------|-------|---------------------------|---------------------------|
| Payment Method | Credit card checkout | Cryptocurrency payment page | Cryptocurrency deposit |
| User Action | Click to pay | Visit payment page & select crypto | Send crypto to address |
| Response | Checkout session URL | Payment page URL | Cryptocurrency address |
| Confirmation | Immediate | Blockchain confirmation | Blockchain confirmation |
| API Format | REST JSON | JSON API spec | JSON API spec |
| Metadata | Supported | Limited (uses tracking table) | Limited (uses tracking table) |
| Amount Handling | Stored in session | `target_amount_requested` in USD | Wallet currency only |

**Note:** Merchant wallets provide a payment gateway experience similar to Stripe, with hosted payment pages and automatic cryptocurrency conversion. Enterprise wallets are for direct cryptocurrency transfers.

## Merchant Wallet Configuration

To use Merchant wallets (recommended for payment gateway):

1. **Create a Merchant Wallet in USD** in your Coinsbuy dashboard
2. The system will automatically detect and use the USD Merchant wallet
3. If no Merchant wallet is configured, the system falls back to Enterprise wallets (cryptocurrency-specific)

**Merchant Wallet Benefits:**
- Hosted payment page (like Stripe checkout)
- Automatic cryptocurrency selection for users
- Real-time exchange rates
- Payment expiration handling
- Better user experience

## Notes

- Coinsbuy uses OAuth 2.0 for authentication with `client_id` and `client_secret`
- Access tokens are automatically cached (stored in `.token_cache` file)
- The API uses strict JSON API format for all requests/responses
- Deposits must be tracked in `coinsbuy_deposits` table for webhook matching
- **Merchant Wallet**: Users visit payment page and select cryptocurrency (similar to Stripe)
- **Enterprise Wallet**: Users send cryptocurrency directly to provided address
- For Merchant wallets, `target_amount_requested` specifies the amount in USD
- Payment page URLs expire based on `time_limit` (default: 1 hour)
- Cryptocurrency conversion is handled automatically by Coinsbuy

