# Wallet Methods

## Wallet Object

| Name | Type | Description |
|------|------|-------------|
| `id` | string | The unique system identifier of a wallet.<br><br>This value was generated automatically when creating a wallet and can't be changed. |
| `label` | string | The tag or name assigned to a wallet for easier locating it in the system.<br><br>This value is set when creating a wallet and can be changed anytime. |
| `status` | number | The current status of a wallet. Possible values:<br><br>- `1` — Not active. The wallet hasn't been activated due to some technical or blockchain issues.<br>- `2` — In progress. The wallet is now being registered in the system or requires the activation and currently unavailable.<br>- `3` — Active. The wallet has been activated (if required) and can be used. |
| `type` | number | The wallet type. Possible values:<br><br>- `1` — Merchant<br>- `2` — Enterprise<br>- `5` — Swap<br><br>This value was selected when creating a wallet and can't be changed. |
| `created_at` | string | The date and time of wallet creation. |
| `balance_confirmed` | string | The balance available for financial operations. |
| `balance_pending` | string | The sum of all deposit- and payout-related transactions that haven't yet received the required number of confirmation blocks.<br><br>This value is positive for incoming and negative for outgoing transactions. This balance can't currently be used for financial operations. |
| `balance_unusable` | string | The amount of incoming transfers blocked by AML.<br><br>This balance can't currently be used for financial operations. |
| `minimal_transfer_amount` | string | For Enterprise wallets only.<br><br>The minimum amount of the incoming transfer, in the wallet currency.<br><br>Payments below the specified amount are automatically rejected. This can be useful if the transaction blockchain fee exceeds the transaction amount. In this case, you can see a new transfer with the Canceled status on the Wallet management > Transfers page of the Web UI; the callback isn't sent. Such transfers can be confirmed and accepted manually, on the Events page of the Web UI. |
| `destination` | object | For Enterprise wallets only.<br><br>The wallet address.<br><br>For more information, refer to [Destination object](./API%20Overview.md#destination-object) |
| `currency` | object | The wallet currency.<br><br>The object contains the string `id` field matching the currency ISO code (refer to Currency codes for possible values). |
| `parent` | object | For wallets denominated in tokens only.<br><br>The Parent wallet of a wallet denominated in tokens. For other wallets, returns `"data": null`.<br><br>The object contains the string `id` field matching the parent wallet system identifier. |

### Wallet Object Example

```json
{
  "type": "wallet",
  "id": "448",
  "attributes": {
    "label": "My Wallet",
    "status": 3,
    "type": 2,
    "created_at": "2020-11-06T06:03:44.301815Z",
    "balance_confirmed": "0.23221548",
    "balance_pending": "0.00000000",
    "balance_unusable": "0.00364702",
    "minimal_transfer_amount": "0.00000546",
    "destination": {
      "address_type": "p2sh-segwit",
      "address": "2N3Ac2cZzRVoqfJGu1bFaAebq3izTgr1WLv"
    }
  },
  "relationships": {
    "currency": {
      "data": {
        "type": "currency",
        "id": "2005"
      }
    },
    "parent": {
      "data": {
        "type": "wallet",
        "id": "14"
      }
    }
  }
}
```

## Get Wallet

### Request

```
GET [base]/wallet/{id}
```

### Request Parameters

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `id` | string | No | The unique system identifier of a wallet.<br><br>Filtering by object parameters can be applied according to the JSON API Specification. |

### Request Example

**cURL:**
```bash
curl --request GET \
--url [base]/wallet/ \
--header 'Authorization: Bearer <token>' \
--header 'Content-Type: application/vnd.api+json'
```

**Python:**
```python
import requests

url = "[base]/wallet/"
headers = {
    "Authorization": "Bearer <token>",
    "Content-Type": "application/vnd.api+json"
}

response = requests.get(url, headers=headers)
```

**PHP:**
```php
$ch = curl_init('[base]/wallet/');
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

In case of success, the response body contains a wallet object or an array of objects (if the `id` wasn't specified).

The wallets list is paginated and the default page size is 10. You can adjust pagination according to the JSON API Specification.

### Response Codes

| HTTP code | Application code | Description | Suggested action |
|-----------|------------------|-------------|------------------|
| 200 | — | The request succeeded. | — |
| 401 | 2007: No active account found with the given credentials | Incorrect credentials. | Send correct credentials. |
| 403 | 3001: You can not view wallet | You don't have permissions to view the wallet. | — |
| 404 | 404: Not found | The wallet with the given `id` wasn't found. | Send a correct `id`. |
| 500 | — | Internal server error. | Try again later. |
| 502 | — | Bad gateway. | Try again later. |
| 503 | — | Service unavailable. | Try again later. |
| 504 | — | Gateway timeout. | Try again later. |
| 5xx | — | Other server errors. | Try again later. |

