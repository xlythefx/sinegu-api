# Deposit Methods

## Deposit Object

| Name | Type | Description |
|------|------|-------------|
| `id` | string | The unique system identifier of a deposit.<br><br>This value is generated automatically at the moment of deposit creation and can't be changed. |
| `status` | number | The current deposit status. Possible values:<br><br>- `2` — Created — the deposit has just been created or hasn't yet been paid in full (for deposits with indicated amounts). The deposits to Enterprise wallets are always assigned the Created status.<br>- `3` — Paid — the deposit with the indicated amount was paid in full.<br>- `4` — Canceled — the deposit was canceled by a user or expired with no payments received. A deposit in any status can be canceled by a user.<br>- `5` — Unresolved — the deposit requires actions from the user. This status is possible in the following cases:<br>  - If the amount of an incoming transfer is greater than the deposit amount.<br>  - If a payment is received after the specified expiration date.<br>  - If a payment is received for a deposit assigned the Paid or Canceled status. |
| `is_active` | boolean | Applicable for the Algorand blockchain only.<br><br>If `true`, the address is activated.<br><br>In Algorand, any deposit address requires activation, that is sending 0.1–0.2 ALGO to the address. Until the address is activated, the field value is set to `false`, funds can't be sent to the address. In Coinsbuy, the activation process is automated, the activation transfer has a type with the index 49. After the address is activated, the field value changes to `true` and a callback is sent (if the callback URL was specified in the deposit settings), containing the deposit address.<br><br>Mind that:<br>- Address activation takes some time, although it usually happens quickly.<br>- The address field will remain empty until the deposit address is activated.<br>- Creating a deposit address costs from 0.1 (for coins) to 0.2 (for tokens) ALGO.<br><br>For other blockchains the value is always `true` and insignificant. |
| `address` | string or null | The deposit address.<br><br>For deposits to Merchant wallets, if the payment currency wasn't specified, the value is null until a payer selects the payment currency. After that, this field is filled in with the address generated depending on the payment currency selected by the payer and can't be changed.<br><br>For the Algorand blockchain, the value is null until the deposit address is activated. Refer to `is_active` for details. |
| `address_type` | string | For payments in BTC, LTC, BCH, XRP: the address type. Refer to [Address types](./Address%20types.md) for supported values.<br><br>For other payments, the value is an empty string. |
| `label` | string | The tag or name assigned to a deposit for easier locating it in the system.<br><br>This value is set when creating a deposit and can be changed anytime. |
| `tracking_id` | string | The user-provided identifier assigned to a deposit for easier locating related payments in external systems.<br><br>This value is set when creating a deposit and can be changed anytime. |
| `confirmations_needed` | number or null | The number of confirmations needed to trigger an additional callback.<br><br>If this field isn't null, two callbacks are sent: upon receiving the number of confirmations specified here and upon receiving the number of confirmations specified in the system settings. The corresponding transaction is assigned the Confirmed status as soon as the number of confirmations specified in this field received. |
| `time_limit` | number or null | Applicable for deposits to Merchant wallets only.<br><br>The lifetime of a deposit, in seconds, in the range from 59 to 2592000 (30 days). |
| `callback` | string | The URL for callback notifications on new payments. |
| `inaccuracy` | string | Applicable for deposits to Merchant wallets with indicated amounts.<br><br>The payment delta, in the wallet currency.<br><br>The delta can be useful to address possible rate changes. For example, you create a deposit for 100 USDT with the expiration time of 10 minutes without specifying the payment currency. This means that the payer can pay in any currency within 10 minutes. But the rate of the currency pair may change within the specified time. In order to minimize your risks, you can set the delta value, for example of 5 USDT, which means that you expect payment from 95 USDT to 105 USDT (depending on the rate) within 10 minutes.<br><br>The delta can be also useful when the payment currency is the same as the wallet currency. For example, if you create a deposit with the indicated amount of 0.1 BTC, and the payer sends 0.1 BTC minus the commission, you won't receive the full deposit amount. As a result, the deposit can't be transferred to the Paid status. To avoid such situations, enter the delta value.<br><br>Mind that the delta must be less than the `target_amount_requested` value. |
| `target_amount_requested` | string or null | Applicable for deposits to Merchant wallets only.<br><br>The requested deposit amount, in the wallet currency. |
| `rate_requested` | string or null | If the payment currency differs from the wallet currency, the value is the exchange rate of the payment currency to the wallet currency; otherwise, the value is 1.<br><br>For Merchant wallets, if the payment currency wasn't specified, the value is null until a payer selects the payment currency. After that, this field is filled in with the current exchange rate. |
| `rate_expired_at` | string | The date and time of rate expiration. |
| `invoice_updated_at` | string or null | Applicable for deposits to Merchant wallets only.<br><br>For deposits with the expiration time specified: the date and time when the time limit was set or last updated. For other deposits the value is null. |
| `payment_page` | string | The URL of a payment page. |
| `target_paid` | string | The total amount of funds that have already been received to the deposit address, in the wallet currency. |
| `source_amount_requested` | string | Applicable for deposits to Merchant wallets only.<br><br>The deposit amount, in the wallet currency. If the deposit currency is the same as the wallet currency, this value is calculated as `target_amount_requested × 1` and then rounded up to the precision of the wallet currency (for example, to 8 digits after the decimal separator for BTC). |
| `target_paid_pending` | string | The amount of funds that have been received to the deposit address, but not yet credited, in the wallet currency. |
| `assets` | object | The amounts of incoming transfers in different currencies.<br><br>The alphabetic code of a currency is used as a key, the aggregated amounts are values. |
| `destination` | object or null | The deposit address.<br><br>For deposits to Merchant wallets, if the payment currency wasn't specified, the value is null until a payer selects the payment currency. After that, this field is filled in with the address generated depending on the payment currency selected by the payer and can't be changed.<br><br>For more information, refer to [Destination object](./API%20Overview.md#destination-object) |
| `payment_page_redirect_url` | string | The link that is displayed as a button on the payment page. |
| `payment_page_button_text` | string | The custom name of a button displayed on the payment page. |
| `currency` | object | The payment currency.<br><br>The object contains the string `id` field matching the currency ISO code (refer to Currency codes for possible values). For Merchant wallets, if the payment currency wasn't specified, `currency.data` is null. |
| `wallet` | object | The wallet to which the deposit was made.<br><br>The object contains the string `id` field matching the wallet system identifier. |

### Deposit Object Example

```json
{
  "type": "deposit",
  "id": "2205",
  "attributes": {
    "status": 2,
    "address": "2NFSVSgbXK7mipDFfuVrLvVJJ9HEgyPNXqu",
    "address_type": "p2sh-segwit",
    "label": "My Deposit",
    "tracking_id": "2244",
    "confirmations_needed": null,
    "time_limit": null,
    "callback_url": "https://my.client.url/cb/",
    "inaccuracy": "0.00000000",
    "target_amount_requested": null,
    "rate_requested": "1.00000000",
    "rate_expired_at": "2024-02-06T16:39:06.507593Z",
    "invoice_updated_at": null,
    "payment_page": "https://pay-sandbox.com/en/a08c7567-956c-4922-b80b-6e515718a9a4/pay",
    "target_paid": "0.00000000",
    "source_amount_requested": "0.00000000",
    "target_paid_pending": "0.00000000",
    "assets": {},
    "destination": {
      "address_type": "p2sh-segwit",
      "address": "2NFSVSgbXK7mipDFfuVrLvVJJ9HEgyPNXqu"
    },
    "payment_page_redirect_url": "https://my.crm.com",
    "payment_page_button_text": "Back to CRM"
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
        "id": "448"
      }
    }
  }
}
```

## Get Deposit

### Request

```
GET [base]/deposit/{id}
```

### Request Parameters

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `id` | string | No | The unique system identifier of a deposit.<br><br>Filtering by object parameters can be applied according to the JSON API Specification. |

### Request Example

**cURL:**
```bash
curl --request GET \
--url [base]/deposit/ \
--header 'Authorization: Bearer <token>' \
--header 'Content-Type: application/vnd.api+json'
```

**Python:**
```python
import requests

url = "[base]/deposit/"
headers = {
    "Authorization": "Bearer <token>",
    "Content-Type": "application/vnd.api+json"
}

response = requests.get(url, headers=headers)
```

**PHP:**
```php
$ch = curl_init('[base]/deposit/');
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

In case of success, the response body contains a deposit object or an array of objects (if the `id` wasn't specified).

The deposits list is paginated and the default page size is 10. You can adjust pagination according to the JSON API Specification.

### Response Codes

| HTTP code | Application code | Description | Suggested action |
|-----------|------------------|-------------|------------------|
| 200 | — | The request succeeded. | — |
| 400 | 5001: You can not view deposit | You don't have permissions to view the deposit. | — |
| 401 | 2007: No active account found with the given credentials | Incorrect credentials. | Send correct credentials. |
| 404 | 404: Not found | The deposit with the given `id` wasn't found. | Send a correct `id`. |
| 500 | — | Internal server error. | Try again later. |
| 502 | — | Bad gateway. | Try again later. |
| 503 | — | Service unavailable. | Try again later. |
| 504 | — | Gateway timeout. | Try again later. |
| 5xx | — | Other server errors. | Try again later. |

## Create Deposit

Before creating a deposit, you can retrieve parameters of the fields you need to fill in by calling the [Deposit options](#deposit-options) method.

### Request

```
POST [base]/deposit/
```

### Request Parameters

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `label` | string | No | The tag or name assigned to a deposit for easier locating it in the system.<br><br>The maximum string length is 32 characters. |
| `tracking_id` | string | No | The user-provided identifier assigned to a deposit for easier locating related payments in external systems.<br><br>The maximum string length is 128 characters. |
| `confirmations_needed` | number | No | The number of confirmations needed to trigger an additional callback, in the range from 0 to 100.<br><br>Refer to [Deposit callback](#deposit-callback) for more details. |
| `callback_url` | string | No | The URL for callback notifications on new payments.<br><br>The maximum string length is 256 characters.<br><br>Refer to [Deposit callback](#deposit-callback) for more details. |
| `payment_page_redirect_url` | string | No | The link that is displayed as a button on the payment page. |
| `payment_page_button_text` | string | No | The custom name of a button displayed on the payment page. |
| `id` | string | Yes | The unique system identifier of a wallet to which deposit-related payments are made. |

### Request Example

**cURL:**
```bash
curl --request POST \
--url [base]/deposit/ \
--header 'Authorization: Bearer <token>' \
--header 'Content-Type: application/vnd.api+json' \
--data '{
  "data": {
    "type": "deposit",
    "attributes": {
      "label": "My new deposit",
      "tracking_id": "d-abcd",
      "confirmations_needed": 2,
      "callback_url": "https://my.client.com/cb/",
      "payment_page_redirect_url": "https://my.crm.com",
      "payment_page_button_text": "Back to CRM"
    },
    "relationships": {
      "wallet": {
        "data": {
          "type": "wallet",
          "id": "1"
        }
      }
    }
  }
}'
```

**Python:**
```python
import requests

url = "[base]/deposit/"
headers = {
    "Authorization": "Bearer <token>",
    "Content-Type": "application/vnd.api+json"
}
data = {
    "data": {
        "type": "deposit",
        "attributes": {
            "label": "My new deposit",
            "tracking_id": "d-abcd",
            "confirmations_needed": 2,
            "callback_url": "https://my.client.com/cb/",
            "payment_page_redirect_url": "https://my.crm.com",
            "payment_page_button_text": "Back to CRM"
        },
        "relationships": {
            "wallet": {
                "data": {
                    "type": "wallet",
                    "id": "1"
                }
            }
        }
    }
}

response = requests.post(url, headers=headers, json=data)
```

**PHP:**
```php
$ch = curl_init('[base]/deposit/');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer <token>',
        'Content-Type: application/vnd.api+json',
    ],
    CURLOPT_POSTFIELDS => json_encode([
        'data' => [
            'type' => 'deposit',
            'attributes' => [
                'label' => 'My new deposit',
                'tracking_id' => 'd-abcd',
                'confirmations_needed' => 2,
                'callback_url' => 'https://my.client.com/cb/',
                'payment_page_redirect_url' => 'https://my.crm.com',
                'payment_page_button_text' => 'Back to CRM',
            ],
            'relationships' => [
                'wallet' => [
                    'data' => [
                        'type' => 'wallet',
                        'id' => '1',
                    ],
                ],
            ],
        ],
    ]),
]);

$response = curl_exec($ch);
curl_close($ch);
```

### Response

In case of success, the response body contains a newly created deposit object.

### Response Codes

| HTTP code | Application code | Description | Suggested action |
|-----------|------------------|-------------|------------------|
| 201 | — | The request succeeded. | — |
| 400 | 1007 | An invalid value was specified for a field. | Send valid values. |
| 400 | 3008 | The wallet is inactive or is being registered in the system. | Activate the wallet or wait until the process of wallet registration in the system is finished and try again. |
| 400 | 3021 | A wallet activation transfer is required. | Make a transfer to activate the wallet. |
| 400 | 5005 | The deposit address can't be generated due to technical issues. | Try again later. |
| 400 | 5006 | The deposit rates can't be obtained due to technical issues. | Try again later. |
| 400 | 5007 | The currency value is required if the address type is specified. | Specify the currency value. |
| 400 | 6002 | The selected currency is disabled. | Select an active currency. |
| 400 | 6015 | The currency is unavailable for all clients or for a specific client. | Select an available currency. |
| 400 | 6017 | A user is trying to make a transaction with a custom token that wasn't paid for. | Make a payment for a custom token and proceed with the transaction. |
| 400 | — | Invalid data (serializer errors). | Send valid data. |
| 401 | 2007 | No active account with the given credentials is found. | Send correct credentials or update access tokens. |
| 429 | — | The request was throttled. | Try again later. |
| 500 | — | Internal server error. | Try again later. |
| 502 | — | Bad gateway. | Try again later. |
| 503 | — | Service unavailable. | Try again later. |
| 504 | — | Gateway timeout. | Try again later. |
| 5xx | — | Other server errors. | Try again later. |

## Deposit Status Values

| Status | Code | Description |
|--------|------|-------------|
| Created | 2 | The deposit has just been created or hasn't yet been paid in full (for deposits with indicated amounts). The deposits to Enterprise wallets are always assigned the Created status. |
| Paid | 3 | The deposit with the indicated amount was paid in full. |
| Canceled | 4 | The deposit was canceled by a user or expired with no payments received. A deposit in any status can be canceled by a user. |
| Unresolved | 5 | The deposit requires actions from the user. This status is possible when:<br>- The amount of an incoming transfer is greater than the deposit amount<br>- A payment is received after the specified expiration date<br>- A payment is received for a deposit assigned the Paid or Canceled status |

## Deposit Callback

A callback is a notification sent to a user's callback URL when a new deposit-related transaction occurs on the blockchain. To learn more, refer to [Callback](./Callback.md).

### Callback Processing

The callback is sent to your server if the deposit request body includes a valid callback URL. The callback is sent as a POST-request, which contains useful JSON payload.

After processing the payload, your server should respond with the HTTP 200 response code without a body.

If your server is temporarily unavailable or the status of the response is different from 200, the system will resend the callback several times with the increasing delay time. The number of re-sendings is limited.

If the manual resending is required, you can do it on the Wallet management > Callbacks page in the Web UI.

### Callback Verification

To verify that the callback was sent by Coinsbuy, generate an HMAC-signature using sha256 as algorithm: sha256 hash of your `callback_secret` (see [Obtain a callback secret](./How%20to%20access%20the%20API.md)) and message (the concatenation of the `transfer.status`, `transfer.amount`, `deposit.tracking_id`, `meta.time` fields). Refer to the example below for a callback verification example.

### Callback Structure

The callback consists of three parts:

- **data** — the deposit itself. Contains the Deposit object.
- **included** — the transaction received for this deposit. Contains the Currency object and Transfer object (optional). There may be no Transfer object, if only the deposit status has changed. Keep in mind that transfer confirmation and deposit status changes are separate events that trigger two different callbacks.
- **meta** — the metadata used for callback authentication. Contains the following fields:
  - `time` (string) — the date and time when the callback was sent.
  - `sign` (string) — the HMAC signature for a callback authentication. Refer to the Callback verification section for a callback verification example.

### Callback Body Example

```json
{
  "data": {
    "type": "deposit",
    "id": "11203",
    "attributes": {
      "address": "0xcb959a408cbfbe64116a2dadc20188c290226fae",
      "created_at": "2022-07-15T16:51:52.702456Z",
      "tracking_id": "",
      "target_paid": "0.300000000000000000",
      "destination": {
        "address_type": null,
        "address": "0xcb959a408cbfbe64116a2dadc20188c290226fae"
      }
    },
    "relationships": {
      "currency": {
        "data": {
          "type": "currency",
          "id": "1002"
        }
      },
      "wallet": {
        "data": {
          "type": "wallet",
          "id": "318"
        }
      },
      "transfer": {
        "data": {
          "type": "transfer",
          "id": "17618"
        }
      }
    }
  },
  "included": [
    {
      "type": "currency",
      "id": "1002",
      "attributes": {
        "iso": 1002,
        "name": "Ethereum",
        "alpha": "ETH",
        "alias": null,
        "exp": 18,
        "confirmation_blocks": 3,
        "minimal_transfer_amount": "0.000000000000000000",
        "block_delay": 30
      }
    },
    {
      "type": "transfer",
      "id": "17618",
      "attributes": {
        "op_id": 11203,
        "op_type": 1,
        "amount": "0.300000000000000000",
        "commission": "0.001200000000000000",
        "fee": "0.000000000000000000",
        "txid": "0xa09cb1de38b9b21712ff18d08d6a625cc80ec41c9e64586095d4c46449a9eb51",
        "status": 2,
        "user_message": null,
        "created_at": "2022-07-15T16:53:04.098536Z",
        "updated_at": "2022-07-15T16:54:39.903843Z",
        "confirmations": 8,
        "risk": 0,
        "risk_status": 4,
        "amount_cleared": "0.298800000000000000"
      },
      "relationships": {
        "currency": {
          "data": {
            "type": "currency",
            "id": "1002"
          }
        }
      }
    }
  ],
  "meta": {
    "time": "2022-07-15T16:54:39.966327+00:00",
    "sign": "1377e7a9eb3d62f7708285cd148711c62f50e53d8046e4d412a18ae9a575da85"
  }
}
```

## Deposit Options

### Request

```
OPTIONS [base]/deposit
```

### Request Parameters

No request parameters.

### Request Example

**cURL:**
```bash
curl --request OPTIONS \
--url [base]/deposit/ \
--header 'Authorization: Bearer <token>' \
--header 'Content-Type: application/vnd.api+json'
```

**Python:**
```python
import requests

url = "[base]/deposit/"
headers = {
    "Authorization": "Bearer <token>",
    "Content-Type": "application/vnd.api+json"
}

response = requests.options(url, headers=headers)
```

**PHP:**
```php
$ch = curl_init('[base]/deposit/');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => 'OPTIONS',
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer <token>',
        'Content-Type: application/vnd.api+json',
    ],
]);

$response = curl_exec($ch);
curl_close($ch);
```

### Response

In case of success, the response body contains an array of available methods along with a list of field parameters such as labels, min and max values, regular expressions used for validation, and so on.

### Response Codes

| HTTP code | Description | Suggested action |
|-----------|-------------|------------------|
| 200 | The request succeeded. | — |
| 500 | Internal server error. The request wasn't completed due to an internal error on the server side. | Try again later. |

### Response Body Example

The response contains detailed information about available fields, their types, validation rules, and allowed values. This is useful for understanding what parameters are required or optional when creating deposits.

```json
{
  "data": {
    "renders": [
      "application/vnd.api+json"
    ],
    "parses": [
      "application/vnd.api+json",
      "multipart/form-data"
    ],
    "allowed_methods": [
      "GET",
      "POST",
      "HEAD",
      "OPTIONS"
    ],
    "actions": {
      "GET": {
        // ... filtering parameters ...
      },
      "POST": {
        "wallet": {
          "type": "field",
          "required": true,
          "read_only": false,
          "label": "Wallet"
        },
        "label": {
          "type": "string",
          "required": false,
          "read_only": false,
          "label": "Label",
          "max_length": 32
        },
        "tracking_id": {
          "type": "string",
          "required": false,
          "read_only": false,
          "label": "Tracking id",
          "max_length": 128
        },
        "confirmations_needed": {
          "type": "integer",
          "required": false,
          "read_only": false,
          "label": "Confirmations needed",
          "min_value": 0,
          "max_value": 100
        },
        "callback_url": {
          "type": "url",
          "required": false,
          "read_only": false,
          "label": "Callback url",
          "max_length": 256
        }
        // ... more fields ...
      }
    }
  }
}
```
