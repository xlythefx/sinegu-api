# API Overview

## Important announcement

We announce the release of the new API version v3 on June 1, 2025.

This version introduces the following significant changes:

- New base URLs
- New Authentication procedure
- New Callback secret and modifications in the callback verification method for deposits and payouts

**Action required:**

We strongly encourage you to review the changes and update your integrations before December 1, 2025, as the old API version will be shut down after this date. Please ensure all updates are completed before the deadline to avoid any service disruptions.

**Deprecated API notice:**

The previous version of the API guide has been moved to a separate section and is now marked as deprecated.

Before you start working with the Coinsbuy API, you need to enable API access to the system. Refer to [How to access the API](./How to access the API.md) for step-by-step instructions.

## General information

The Coinsbuy API v3 is organized in accordance with JSON API paradigm. For a better understanding of the paradigm principles, read the [JSON API Specification](https://jsonapi.org/).

We use conventional HTTP response codes, OAuth 2.0 protocol for authentication, and HMAC-SHA256 algorithm for encryption.

Except for Authentication, all requests must contain the following HTTP headers:

- **Authorization: Bearer {YOUR_ACCESS_TOKEN}**: Used to authenticate your request.
- **Content-Type: application/vnd.api+json**: Required according to JSON API Specification.

## Filtering

Filters by object parameters can be applied to any GET-method according to the JSON API Specification.

## Callbacks

The Coinsbuy API also provides flexible options for callback — an asynchronous notification about changing statuses of deposits and payouts. To learn more, refer to [Callback](./Callback.md).

To receive callbacks, specify a callback URL when sending a Create deposit or Create payout request. When a transaction receives the required number of confirmation blocks, the callback is sent via an HTTP POST-request to the specified URL. If you also want to be notified when the number of confirmations received for a transaction doesn't reach a specific threshold or exceeds it, indicate the required number of confirmations in the request.

## Date-time values

All date-time values are specified as per ISO 8601-1:2019, with milliseconds precision and timezone included: `YYYY-MM-DDThh:mm:ss[.SSSSSS]±hh:mm`.

## Destination object

The object contains the following fields:

### address_type (string or null)

- For wallets denominated in BTC, LTC, BCH, XRP: the address type. Refer to [Address types](./Address types.md) for supported values.
- For other wallets the value is null.

### address (string)

The deposit address.

**For payments in XRP**, an array of objects is returned containing the x-address and address (with a destination tag additionally specified):

```json
"destination": [
  {
    "address_type": "x-address",
    "address": "X7dBkB9KmvUh6GGHbjhxdu4LfkwhJ74oVWbGRoy7VLnHdJ6"
  },
  {
    "address_type": "address",
    "address": "rsxXXvBXmKUkCyCeNCHUFpfCX9pQdxQhv5",
    "tag": "0"
  }
]
```

**For payments in XLM**, the destination object is as follows:

```json
"destination": {
  "address_type": "address",
  "address": "GCZJFWB5NVQHVBMV4U6CCJIXXBGINGYF2W33PMD5REBD5VQ6H6BLCJR5",
  "tag_type": 0,
  "tag": ""
}
```

where:

- `address_type` is always "address".
- `address` is a string value containing the wallet address.
- `tag_type` is a number value containing tag or memo type. Possible values:
  - `0` — no memo
  - `1` — a 64-bit unsigned integer
- `tag` is a string value containing the tag.

## API rate limits and accessibility

The number of requests to the endpoint without prior authentication is limited to 15 per 1 minute.

To check the network availability of the system, use the `/ping` endpoint without authorization headers.

## Base URLs

| Code | Production | Sandbox |
|------|-----------|---------|
| `[base]` | `https://v3.api.coinsbuy.com/` | `https://v3.api-sandbox.coinsbuy.com/` |

