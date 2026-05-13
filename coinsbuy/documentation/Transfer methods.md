# Transfer Methods

## Transfer Object

| Name | Type | Description |
|------|------|-------------|
| `id` | string | The unique system identifier of a transfer.<br><br>This value is generated automatically at the moment of transfer creation and can't be changed. |
| `op_id` | number | For deposits and payouts, this is the unique operation identifier in the system. |
| `op_type` | number | The transfer purpose. For possible values, refer to Transfer types. |
| `amount` | string | The transfer amount, in the payment currency, excluding fees and commissions. |
| `rate_target` | string | If the payment currency differs from the wallet currency, this is the exchange rate of the payment currency to the wallet currency, valid at the moment of transaction execution.<br><br>If the payment currency is the same as the wallet currency, this value is equal to 1. |
| `commission` | string | The fee charged by Coinsbuy for transaction processing, in the payment currency. |
| `fee` | string | The blockchain fee charged for processing an on-chain transaction, in the payment currency. |
| `txid` | string | The blockchain transaction identifier, the same as the transaction hash. |
| `status` | number | The current status of a transfer. Possible values:<br><br>- `-3` — Canceled — the transfer was canceled due to security reasons or a transfer amount being too small.<br>- `-2` — Blocked — the transfer was considered suspicious during the AML check and is temporarily blocked until further Compliance verification.<br>- `-1` — Failed — the transfer has failed on the blockchain.<br>- `0` — Created — the transfer has been created and is currently in the queue for processing, the status will be changed soon.<br>- `1` — Unconfirmed — the transfer hasn't yet received the required number of block confirmations.<br>- `2` — Confirmed — the required number of block confirmations has been received and the transfer is completed. |
| `user_message` | string or null | Any comment for an operation made via the Coinsbuy Back Office. |
| `created_at` | string | The date and time when a transfer was created. |
| `updated_at` | string | The date and time when a transfer status was last updated. |
| `confirmations` | number | The current number of received confirmations on the blockchain. |
| `risk` | number | The AML risk percentage for a transfer.<br><br>Possible range of values is from 0 to 100, where 100 is the highest risk. May be null if AML verification hasn't been performed. |
| `risk_status` | number | The status of AML verification of a transfer. Possible values:<br><br>- `1` — Checked — the transfer has successfully passed the AML check.<br>- `2` — Pending — the AML check is in progress.<br>- `3` — Failed — the AML check has failed, the transfer has been marked as red.<br>- `4` — Unavailable — the AML check is unavailable for this transfer type. |
| `amount_target` | string | The amount that is debited from or credited to a wallet, in the wallet currency, including fees and commissions. |
| `commission_target` | string | This is a service field. The value is always `"0"`. |
| `amount_cleared` | string | The amount by which the transfer changes the wallet balance. For outgoing transfers, the value is multiplied by -1. |
| `currency` | object | The payment currency.<br><br>For Enterprise wallets, always the same as the wallet currency. For Merchant wallets, may differ from the wallet currency.<br><br>The object contains the string `id` field matching the currency ISO code (refer to Currency codes for possible values). |
| `wallet` | object | The wallet to or from which the transfer was made.<br><br>The object contains the string `id` field matching the wallet system identifier. |
| `parent` | object | Applicable mainly for tokens.<br><br>The object contains a link to the parent transfer, if the current transfer depends on the parent one. In other cases, returns `"data": null`. |

### Transfer Object Example

```json
{
  "type": "transfer",
  "id": "8163",
  "attributes": {
    "op_id": 3262,
    "op_type": 14,
    "amount": "0.77700000",
    "rate_target": "1.000000000000000000",
    "commission": "0.00233100",
    "fee": "0.00000000",
    "txid": "0f82d9a82c166ed87a47b968e6a713c...",
    "status": 2,
    "user_message": null,
    "created_at": "2024-02-08T11:16:16.179799Z",
    "updated_at": "2024-02-08T11:16:17.483373Z",
    "confirmations": 191,
    "risk": 0,
    "risk_status": 4,
    "amount_target": "0.77466900",
    "commission_target": "0",
    "amount_cleared": "0.77466900"
  },
  "relationships": {
    "currency": {
      "data": {
        "type": "currency",
        "id": "1000"
      }
    },
    "wallet": {
      "data": {
        "type": "wallet",
        "id": "3262"
      }
    },
    "parent": {
      "data": null
    }
  }
}
```

## Get Transfer

### Request

```
GET [base]/transfer/{id}
```

### Request Parameters

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `id` | string | No | The unique system identifier of a transfer.<br><br>Filtering by object parameters can be applied according to the JSON API Specification. |

### Request Example

**cURL:**
```bash
curl --request GET \
--url [base]/transfer/ \
--header 'Authorization: Bearer <token>' \
--header 'Content-Type: application/vnd.api+json'
```

**Python:**
```python
import requests

url = "[base]/transfer/"
headers = {
    "Authorization": "Bearer <token>",
    "Content-Type": "application/vnd.api+json"
}

response = requests.get(url, headers=headers)
```

**PHP:**
```php
$ch = curl_init('[base]/transfer/');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer <token>',
        'Content-Type: application/vnd.api+json',
    ],
]);

$response = curl_exec($ch);
curl_close($ch);
```

### Response

In case of success, the response body contains a transfer object or an array of objects (if the `id` wasn't specified).

The transfers list is paginated and the default page size is 10. You can adjust pagination according to the JSON API Specification.

### Response Codes

| HTTP code | Application code | Description | Suggested action |
|-----------|------------------|-------------|------------------|
| 200 | — | The request succeeded. | — |
| 401 | 2007: No active account found with the given credentials | Incorrect credentials. | Send correct credentials. |
| 404 | 404: Not found | The transfer with the given `id` wasn't found. | Send a correct `id`. |
| 500 | — | Internal server error. | Try again later. |
| 502 | — | Bad gateway. | Try again later. |
| 503 | — | Service unavailable. | Try again later. |
| 504 | — | Gateway timeout. | Try again later. |
| 5xx | — | Other server errors. | Try again later. |

## Transfer Status Values

| Status | Code | Description |
|--------|------|-------------|
| Canceled | -3 | The transfer was canceled due to security reasons or a transfer amount being too small. |
| Blocked | -2 | The transfer was considered suspicious during the AML check and is temporarily blocked until further Compliance verification. |
| Failed | -1 | The transfer has failed on the blockchain. |
| Created | 0 | The transfer has been created and is currently in the queue for processing, the status will be changed soon. |
| Unconfirmed | 1 | The transfer hasn't yet received the required number of block confirmations. |
| Confirmed | 2 | The required number of block confirmations has been received and the transfer is completed. |

## AML Risk Status Values

| Status | Code | Description |
|--------|------|-------------|
| Checked | 1 | The transfer has successfully passed the AML check. |
| Pending | 2 | The AML check is in progress. |
| Failed | 3 | The AML check has failed, the transfer has been marked as red. |
| Unavailable | 4 | The AML check is unavailable for this transfer type. |
