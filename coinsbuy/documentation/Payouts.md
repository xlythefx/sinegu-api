# Payout Methods

## Payout Object

| Name | Type | Description |
|------|------|-------------|
| `id` | string | The unique system identifier of a payout.<br><br>This value is generated automatically at the moment of payout creation and can't be changed. |
| `amount` | string | The payout amount, in the payment currency. |
| `address` | string | The blockchain address of a receiver's wallet. |
| `target_deposit` | string or null | The identifier of a Coinsbuy deposit, in the UUID4 format. The same as the payment page identifier. |
| `target_wallet` | string or null | For payouts to client's own wallets (such as payouts from Enterprise/Merchant wallets to Swap wallets and back): the identifier of a wallet to which the payout is made.<br><br>For other payouts, the value is `null`. |
| `tracking_id` | string | The unique user-provided identifier assigned to a payout for easier locating related payments in external systems.<br><br>This value is set when creating a payout and can be changed anytime. |
| `label` | string | The tag or name assigned to a payout for easier locating it in the system.<br><br>This value is set when creating a payout and can be changed anytime. |
| `confirmations_needed` | number | The number of confirmations needed to trigger an additional callback.<br><br>If this field is not `null`, two callbacks are sent: upon receiving the number of confirmations specified here and upon receiving the number of confirmations specified in the system settings. The corresponding transaction is assigned the *Confirmed* status as soon as the number of confirmations specified in this field received. |
| `fee_amount` | string | The blockchain fee charged for processing an on-chain transaction, in the payment currency. |
| `is_fee_included` | boolean | If `true`, the fee is included in the payment amount, the remaining part is credited to the receiver's account. If `false`, the fee is additionally debited from the wallet.<br><br>The fee can't be included in the amount for tokens and the following currencies:<br>- ADA (Cardano)<br>- ARB (Arbitrium)<br>- AVAX (Avalanche)<br>- BNB (BSC)<br>- ETH<br>- ETH-BASE<br>- MATIC (Polygon)<br>- OPT (Optimism)<br>- TRX (Tron)<br>- XLM (Stellar)<br>- XMR (Monero)<br>- XRP (Ripple) |
| `status` | number | The current payout status. Possible values:<br><br>- `1` — Waiting for approval — for a payout created by a user with the *Withdrawal with approval* role: the payout was created and awaits the approval.<br>- `2` — Approved — the payout was approved.<br>- `3` — Canceled — the payout was canceled. |
| `rate_requested` | string or null | If the payment currency differs from the wallet currency, the value is the exchange rate of the payment currency to the wallet currency; otherwise, the value is `1`.<br><br>The exchange rates are updated every 30 seconds. |
| `callback_url` | string | The URL for callback notifications on new payments. |
| `force_blockchain` | boolean | Applicable for payouts from Merchant wallets only.<br><br>If the destination address of a payout also belongs to Coinsbuy, such a transaction is off-chain. As a result, it doesn't have a `txid` and can't be found in block explorers. Set this value to `true`, to obtain `txid`.<br><br>By default, the value is `false`. |
| `exp` | number | The payment currency precision, that is the number of digits after the decimal separator. |
| `tag_type` | string or null | Applicable for payouts in XLM only.<br><br>The tag or memo type. Possible values:<br>- `0` — no memo<br>- `1` — a 64-bit unsigned integer |
| `tag` | string or null | - For payouts in XRP: the destination tag — an unsigned integer (commonly 32-bit), used for routing payments on exchanges/custodians. If the recipient address requires a Destination Tag, provide it; otherwise, the value is `null`. Example: `123456`<br>- For payouts in XLM: the memo value corresponding to the selected `tag_type`. Examples: With `tag_type` = 1: `9876543210`<br>- For payouts in TON: the transfer comment — a free-form UTF-8 string that may be required by some services to identify a payment. If not required, the value is `null`. Example: "Invoice 8472"<br><br>For other payouts, the value is `null`. |
| `destination` | object or null | The deposit address.<br><br>For more information, refer to [Destination object](./API%20Overview.md#destination-object) |
| `travel_rule_info` | object | The information about a payment receiver.<br><br>The object fields are different for natural and legal persons, refer to [Travel rule](#travel-rule) for more information and examples. |
| `currency` | object | The payment currency.<br><br>The object contains the string `id` field matching the currency ISO code (refer to Currency codes for possible values). For Merchant wallets, if the payment currency wasn't specified, `currency.data` is `null`. |
| `wallet` | object | The wallet from which the payout was made.<br><br>The object contains the string `id` field matching the wallet system identifier. |

### Payout Object Example

```json
{
  "type": "payout",
  "id": "1815",
  "attributes": {
    "amount": "1.00000000",
    "address": "bcrt1qs8vkvgj53tdzxaxzcxrwnvtp0ezse0vmrjty6u",
    "target_deposit": "15987fbe-993e-45a8-93fa-cdd1a626488f",
    "target_wallet": null,
    "tracking_id": "",
    "label": "",
    "confirmations_needed": null,
    "fee_amount": "0.00000000",
    "is_fee_included": false,
    "status": 2,
    "rate_requested": "1.00000000",
    "callback_url": "",
    "force_blockchain": false,
    "exp": 8,
    "tag_type": null,
    "tag": null,
    "destination": {
      "address_type": "bech32",
      "address": "bcrt1qs8vkvgj53tdzxaxzcxrwnvtp0ezse0vmrjty6u"
    },
    "travel_rule_info": {
      "beneficiary": {
        "beneficiaryPersons": [
          {
            "naturalPerson": {
              "name": [
                {
                  "nameIdentifier": [
                    {
                      "primaryIdentifier": "John",
                      "secondaryIdentifier": "Smith"
                    }
                  ]
                }
              ],
              "geographicAddress": [
                {
                  "country": "Country",
                  "addressLine": [
                    "Address"
                  ],
                  "addressType": "HOME"
                }
              ]
            }
          }
        ]
      }
    }
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
        "id": "3614"
      }
    }
  }
}
```

## Get Payout

### Request

```
GET [base]/payout/{id}
```

### Request Parameters

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `id` | string | No | The unique system identifier of a payout.<br><br>Filtering by object parameters can be applied according to the JSON API Specification. |

### Request Example

**cURL:**
```bash
curl --request GET \
--url [base]/payout/ \
--header 'Authorization: Bearer <token>' \
--header 'Content-Type: application/vnd.api+json'
```

**Python:**
```python
import requests

url = '[base]/payout/'
headers = {
    'Authorization': 'Bearer <token>',
    'Content-Type': 'application/vnd.api+json',
}

response = requests.get(url, headers=headers)
```

**PHP:**
```php
<?php
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

$client = new GuzzleHttp\Client();
try {
  $res = $client->get('[base]/payout/', [
    'headers' => [
      'Authorization' => 'Bearer <token>',
      'Content-Type' => 'application/vnd.api+json',
    ],
  ]);
  echo $res->getBody();
} catch (RequestException $e) {}
```

### Response

In case of success, the response body contains a payout object or an array of objects (if the `id` wasn't specified).

The payouts list is paginated and the default page size is 10. You can adjust pagination according to the JSON API Specification.

### Response Codes

| HTTP code | Application code | Description | Suggested action |
|-----------|------------------|-------------|------------------|
| 200 | — | The request succeeded. | — |
| 400 | 5001: You can not view payout | You don't have permissions to view the payout. | — |
| 401 | 2007: No active account found with the given credentials | Incorrect credentials. | Send correct credentials. |
| 404 | 404: Not found | The payout with the given `id` wasn't found. | Send a correct `id`. |
| 500 | — | Internal server error. | Try again later. |
| 502 | — | Bad gateway. | Try again later. |
| 503 | — | Service unavailable. | Try again later. |
| 504 | — | Gateway timeout. | Try again later. |
| 5xx | — | Other server errors. | Try again later. |

## Create Payout

Before creating a payout, you can retrieve parameters of the fields you need to fill in by calling the [Payout options](#payout-options) method.

> **Important:** For security reasons, an additional HTTP header with a unique idempotency key must be sent in the request. The key is a UUID4 string with hyphens, for example: `2dbcb513-bd35-404f-9709-e34878def180`.

### Request

```
POST [base]/payout/
```

### Request Parameters

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `label` | string | No | The tag or name assigned to a payout for easier locating it in the system.<br><br>The maximum string length is 32 characters. |
| `amount` | string | Yes | The payout amount, in the payment currency.<br><br>The maximum string length is 36 characters. |
| `fee_amount` | string | Yes/No | The blockchain fee to be charged for processing an on-chain transaction, in the payment currency.<br><br>This field isn't required for internal off-chain transfers; for all other payouts, this field is mandatory.<br><br>The maximum string length is 36 characters.<br><br>To determine the fee value, call the [Precalculate fee](#precalculate-fee) method. |
| `address` | string | No | The payout destination address.<br><br>The maximum string length is 128 characters. |
| `tracking_id` | string | No | The unique user-provided identifier assigned to a payout for easier locating related payments in external systems.<br><br>The maximum string length is 128 characters. |
| `confirmations_needed` | number | No | The number of confirmations needed to trigger an additional callback, in the range from `0` to `100`.<br><br>Refer to [Payout callback](#payout-callback) for more details. |
| `callback_url` | string | No | The URL for callback notifications on new payments.<br><br>The maximum string length is 256 characters.<br><br>Refer to [Payout callback](#payout-callback) for more details. |
| `travel_rule_info` | object | Yes | The information about a payment receiver.<br><br>The object fields are different for natural and legal persons, refer to [Travel rule](#travel-rule) for more information and examples. |
| `currency` | object | Yes | The payment currency.<br><br>The object contains the string `id` field containing the currency ISO code (refer to Currency codes for possible values). |
| `wallet` | object | Yes | The wallet from which the payout was made.<br><br>The object contains the string `id` field containing the wallet system identifier. |

### Request Example

**cURL:**
```bash
curl --request POST \
--url [base]/payout/ \
--header 'Authorization: Bearer <token>' \
--header 'Content-Type: application/vnd.api+json' \
--header 'idempotency-key: b6891f5b-d16f-4ba9-a1c4-9828be64a492' \
--data '{
  "data": {
    "type": "payout",
    "attributes": {
      "label": "My Payout",
      "amount": "0.05",
      "fee_amount": "0.00000550",
      "address": "bcrt1q92k5z02dyrjahm4hput42nps3t7ryxzzz0vl76",
      "tracking_id": "f12",
      "confirmations_needed": 2,
      "callback_url": "https://my.client.com/cb/",
      "travel_rule_info": {
        "beneficiary": {
          "beneficiaryPersons": [
            {
              "naturalPerson": {
                "name": [
                  {
                    "nameIdentifier": [
                      {
                        "primaryIdentifier": "John",
                        "secondaryIdentifier": "Smith"
                      }
                    ]
                  }
                ],
                "geographicAddress": [
                  {
                    "country": "Country",
                    "addressLine": [
                      "Address"
                    ],
                    "addressType": "HOME"
                  }
                ]
              }
            }
          ]
        }
      }
    },
    "relationships": {
      "wallet": {
        "data": {
          "type": "wallet",
          "id": "1"
        }
      },
      "currency": {
        "data": {
          "type": "currency",
          "id": "1000"
        }
      }
    }
  }
}'
```

**Python:**
```python
import requests
import json

url = "[base]/payout/"
payload = json.dumps({
  "data": {
    "type": "payout",
    "attributes": {
      "label": "My Payout",
      "amount": "0.05",
      "fee_amount": "0.00000550",
      "address": "bcrt1q92k5z02dyrjahm4hput42nps3t7ryxzzz0vl76",
      "tracking_id": "f12",
      "confirmations_needed": 2,
      "callback_url": "https://my.client.com/cb/",
      "travel_rule_info": {
        "beneficiary": {
          "beneficiaryPersons": [
            {
              "naturalPerson": {
                "name": [
                  {
                    "nameIdentifier": [
                      {
                        "primaryIdentifier": "John",
                        "secondaryIdentifier": "Smith"
                      }
                    ]
                  }
                ],
                "geographicAddress": [
                  {
                    "country": "Country",
                    "addressLine": [
                      "Address"
                    ],
                    "addressType": "HOME"
                  }
                ]
              }
            }
          ]
        }
      }
    },
    "relationships": {
      "wallet": {
        "data": {
          "type": "wallet",
          "id": "1"
        }
      },
      "currency": {
        "data": {
          "type": "currency",
          "id": "1000"
        }
      }
    }
  }
})
headers = {
  'Content-Type': 'application/vnd.api+json',
  'Authorization': 'Bearer <token>',
  'Idempotency-Key': 'b6891f5b-d16f-4ba9-a1c4-9828be64a492'
}

response = requests.post(url, headers=headers, data=payload)
```

**PHP:**
```php
<?php
$client = new Client();
$headers = [
  'Content-Type' => 'application/vnd.api+json',
  'Authorization' => 'Bearer <token>',
  'Idempotency-Key' => 'b6891f5b-d16f-4ba9-a1c4-9828be64a492'
];
$body = '{
  "data": {
    "type": "payout",
    "attributes": {
      "label": "My Payout",
      "amount": "0.05",
      "fee_amount": "0.00000550",
      "address": "bcrt1q92k5z02dyrjahm4hput42nps3t7ryxzzz0vl76",
      "tracking_id": "f12",
      "confirmations_needed": 2,
      "callback_url": "https://my.client.com/cb/",
      "travel_rule_info": {
        "beneficiary": {
          "beneficiaryPersons": [
            {
              "naturalPerson": {
                "name": [
                  {
                    "nameIdentifier": [
                      {
                        "primaryIdentifier": "John",
                        "secondaryIdentifier": "Smith"
                      }
                    ]
                  }
                ],
                "geographicAddress": [
                  {
                    "country": "Country",
                    "addressLine": [
                      "Address"
                    ],
                    "addressType": "HOME"
                  }
                ]
              }
            }
          ]
        }
      }
    },
    "relationships": {
      "wallet": {
        "data": {
          "type": "wallet",
          "id": "1"
        }
      },
      "currency": {
        "data": {
          "type": "currency",
          "id": "1000"
        }
      }
    }
  }
}';
$request = new Request('POST', '[base]/payout/', $headers, $body);
$res = $client->sendAsync($request)->wait();
echo $res->getBody();
```

### Response

In case of success, the response body contains a newly created payout object.

### Response Codes

| HTTP code | Application code | Description | Suggested action |
|-----------|------------------|-------------|------------------|
| 201 | — | The request succeeded. | — |
| 400 | 1007 | An invalid value was specified for a field. | Send valid values. |
| 400 | 3008 | The wallet is inactive or is being registered in the system. | Activate the wallet or wait until the process of wallet registration in the system is finished and try again. |
| 400 | 3021 | A wallet activation transfer is required. | Make a transfer to activate the wallet. |
| 400 | 3022 | An invalid wallet type. | Make sure you specify either Enterprise, or Merchant, or Swap wallet. |
| 400 | 4002 | A payout can't be made from the specified wallet. | Make sure you have the permission to make payouts from the wallet you have specified. |
| 400 | 4007 | The funds are sent to the wallet from which the payout is being made. | The funds can't be sent to the wallet from which the payout is being made. Such transactions are allowed for the following blockchains: BTC, BCH, BSC, ADA, DASH, DOGE, ETH, LTC, OMNI, TRX, ZCASH. |
| 400 | 4008 | Insufficient funds. | Top up the wallet or decrease the payout amount. |
| 400 | 4009 | The specified blockchain fee is either too high or too low. | Correct the fee value. Refer to [Precalculate fee](#precalculate-fee). |
| 400 | 4010 | The blockchain fee can't be applied to tokens. | Exclude the fee value from the payout body. |
| 400 | 4011 | A payout amount can't be zero or negative. | Make sure that the payout amount is greater than zero. |
| 400 | 4012 | The specified `tracking_id` isn't unique for the selected wallet. | Specify a unique `tracking_id`. |
| 400 | 4013 | The payout amount is less than the Minimal transfer amount. | Specify the payout amount that is greater than or equal to the minimal transfer amount. |
| 400 | 4016 | The blockchain fee can't be applied to the selected currency. | Set the `is_fee_included` value to false. |
| 400 | 4019 | The blockchain fee amount exceeds the amount of the payout with the included fee. | Increase the payout amount or decrease the fee value. |
| 400 | 6002 | The selected currency is disabled. | Select an active currency. |
| 400 | 6015 | The currency is unavailable for all clients or for a specific client. | Select an available currency. |
| 400 | 6017 | A user is trying to make a transaction with a custom token that wasn't paid for. | Make a payment for a custom token and proceed with the transaction. |
| 400 | 7001 | An incorrect payment address is specified or the address isn't whitelisted. | Specify a correct payment address and proceed with the payment. |
| 400 | — | Invalid data (serializer errors). | Send valid data. |
| 401 | 2007 | No active account with the given credentials is found. | Send correct credentials or update access tokens. |
| 403 | 3019 | Payouts are blocked for the selected wallet. | Contact the account manager or technical support. |
| 409 | 1004 | A request with the specified idempotency key has already been submitted. | Specify another idempotency key for the request. |
| 428 | 1003 | The idempotency key header is not specified. | Specify the idempotency key header in the request header. |
| 429 | — | The request was throttled. | Try again later. |
| 500 | 500 | Internal server error. | Try again later. |
| 500 | — | A node-related error. | Try again later. |
| 502 | — | Bad gateway. | Try again later. |
| 503 | 4018 | Fee calculation or payout creation for the selected currency is temporarily unavailable. | Change the currency or try again later. |
| 503 | — | Service unavailable. | Try again later. |
| 504 | — | Gateway timeout. | Try again later. |
| 5xx | — | Other server errors. | Try again later. |

## Payout Status Values

| Status | Code | Description |
|--------|------|-------------|
| Waiting for approval | 1 | For a payout created by a user with the *Withdrawal with approval* role: the payout was created and awaits the approval. |
| Approved | 2 | The payout was approved. |
| Canceled | 3 | The payout was canceled. |

## Travel Rule

The `travel_rule_info` object contains information about a payment receiver and must be filled in when creating a payout.

The object fields are different for natural and legal persons.

### Natural Person

```json
"travel_rule_info": {
  "beneficiary": {
    "beneficiaryPersons": [
      {
        "naturalPerson": {
          "name": [
            {
              "nameIdentifier": [
                {
                  "primaryIdentifier": "First Name",
                  "secondaryIdentifier": "Last Name"
                }
              ]
            }
          ],
          "geographicAddress": [
            {
              "country": "Country",
              "addressLine": [
                "Address"
              ],
              "addressType": "HOME"
            }
          ]
        }
      }
    ]
  }
}
```

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `primaryIdentifier` | string | Yes | The family name, maiden name or married name, main name, surname, or, in some cases, the entire name when the natural person's name can't be divided into two parts, or when the sender is unable to divide the natural person's name into two parts. |
| `secondaryIdentifier` | string | Yes | The forename, familiar name, given name, initials, prefixes, suffixes, Roman numerals (if legally part of the name), or any other secondary names. |
| `country` | string | No | The ISO-3166-1 alpha-2 code or the value XX. |
| `addressLine` | string | Yes | Information that locates and identifies a specific address, as defined by postal services, presented in free format text. |
| `addressType` | string | Yes | The nature of the address. Possible values:<br>- `HOME` — Residential — the address is the home address.<br>- `BIZZ` — Business — the address is the business address.<br>- `GEOG` — Geographic — the address is the unspecified physical (geographical) address suitable for identification of the natural or legal person. |

### Legal Person

```json
"travel_rule_info": {
  "beneficiary": {
    "beneficiaryPersons": [
      {
        "legalPerson": {
          "name": {
            "nameIdentifier": [
              {
                "legalPersonName": "Name",
                "legalPersonNameIdentifierType": "LEGL"
              }
            ]
          },
          "geographicAddress": [
            {
              "country": "Country",
              "addressLine": [
                "Address"
              ],
              "addressType": "BIZZ"
            }
          ]
        }
      }
    ]
  }
}
```

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `legalPersonName` | string | Yes | The name of a legal person. |
| `legalPersonNameIdentifierType` | string | Yes | The nature of name being specified for the legal person. Possible values:<br>- `LEGL` — Legal name — the official name under which an organization is registered.<br>- `SHRT` — Short name — the short name of an organization.<br>- `TRAD` — Trading name — the name used by a business for business purposes, although its registered legal name used for contracts and other formal situations may be different. |
| `country` | string | No | The ISO-3166-1 alpha-2 code or the value XX. |
| `addressLine` | string | Yes | Information that locates and identifies a specific address, as defined by postal services, presented in free format text. |
| `addressType` | string | Yes | The nature of the address. Possible values:<br>- `HOME` — Residential — the address is the home address.<br>- `BIZZ` — Business — the address is the business address.<br>- `GEOG` — Geographic — the address is the unspecified physical (geographical) address suitable for identification of the natural or legal person. |

## Precalculate Fee

Use this method to precalculate possible blockchain fee options.

For calculations, you need to provide the identifier of a wallet from which the payout is made along with the destination address and payout amount.

### Request

```
POST [base]/payout/calculate/
```

### Request Parameters

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `amount` | string | Yes | The payout amount, in the payment currency. |
| `to_address` | string | Yes | The payout destination address. |
| `wallet` | object | Yes | The unique system identifier of a wallet from which the payout is made. |
| `currency` | object | No | The payout currency. |

### Request Example

**cURL:**
```bash
curl --request POST \
--url [base]/payout/calculate/ \
--header 'Authorization: Bearer <token>' \
--header 'Content-Type: application/vnd.api+json' \
--data '{
  "data": {
    "type": "payout-calculation",
    "attributes": {
      "amount": "0.0000001",
      "to_address": "2N3Ac2cZzRVoqfJGu1bFaAebq3izTgr1WLv"
    },
    "relationships": {
      "wallet": {
        "data": {
          "type": "wallet",
          "id": "13"
        }
      },
      "currency": {
        "data": {
          "type": "currency",
          "id": "1000"
        }
      }
    }
  }
}'
```

**Python:**
```python
import requests

url = '[base]/payout/calculate/'
headers = {
  'Authorization': 'Bearer <token>',
  'Content-Type': 'application/vnd.api+json',
}
data = {
  'data': {
    'type': 'payout-calculation',
    'attributes': {
      'amount': '0.0000001',
      'to_address': '2N3Ac2cZzRVoqfJGu1bFaAebq3izTgr1WLv',
    },
    'relationships': {
      'wallet': {
        'data': {
          'type': 'wallet',
          'id': '13',
        }
      },
      'currency': {
        'data': {
          'type': 'currency',
          'id': '1000',
        },
      },
    },
  },
}

response = requests.post(url, headers=headers, json=data)
```

**PHP:**
```php
<?php
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

$client = new GuzzleHttp\Client();
try {
  $res = $client->post('[base]/payout/calculate/', [
    'json' => [
      'data' => [
        'type' => 'payout-calculation',
        'attributes' => [
          'amount' => '0.05',
          'to_address' => 'bcrt1q92k5z02dyrjahm4hput42nps3t7ryxzzz0vl76',
        ],
        'relationships' => [
          'wallet' => [
            'data' => [
              'type' => 'wallet',
              'id' => '1',
            ],
          ],
          'currency' => [
            'data' => [
              'type' => 'currency',
              'id' => '1000',
            ],
          ],
        ],
      ],
    ],
    'headers' => [
      'Authorization' => 'Bearer <token>',
      'Content-Type' => 'application/vnd.api+json',
    ],
  ]);
  echo $res->getBody();
} catch (RequestException $e) {}
```

### Response

| Name | Type | Description |
|------|------|-------------|
| `is_internal` | boolean | If `true`, the payout is made to the Coinsbuy address. |
| `fee.low` | string | The low mode fee amount. |
| `fee.medium` | string | The medium mode fee amount. |
| `fee.high` | string | The high mode fee amount. |
| `dust_amount` | string | The payout-related dust. |
| `warning` | number or null | The blockchain-related warning. Possible values:<br>- `1` — the large withdrawal fee.<br>- `2` — the payout may fail due to low finalizing balance or too low change amount.<br>- `3` — the final balance may be less than minimal fee and can't be withdrawn. Applicable for the Ethereum blockchain. |
| `fee.currency` | number | The blockchain fee currency. |
| `commission.amount` | object | The commission amount. |
| `commission.currency` | object | The commission currency. |

### Response Example

```json
{
  "data": {
    "type": "payout-calculation",
    "id": "0",
    "attributes": {
      "is_internal": true,
      "fee": {
        "low": "0.0001000",
        "medium": "0.0001000",
        "high": "0.1073686",
        "dust_amount": "0.0000000",
        "warning": null,
        "currency": 1021
      },
      "commission": {
        "amount": "0.0000000",
        "currency": 1021
      }
    }
  }
}
```

### Response Codes

| HTTP code | Application code | Description | Suggested action |
|-----------|------------------|-------------|------------------|
| 200 | — | The request succeeded. | — |
| 400 | 1006 | Missing field {field_name}. | Add the missing field to the request body. |
| 400 | 1007 | An invalid value was specified for a field. | Send valid values. |
| 400 | 3008 | The wallet is inactive. | Activate the wallet or choose another one. |
| 400 | 3021 | A wallet activation transfer is required. | Make a transfer to activate the wallet. |
| 400 | 4007 | The funds are sent to the wallet from which the payout is being made. | The funds can't be sent to the same wallet from which the payout is being made. |
| 400 | 4008 | Insufficient funds. | Top up the wallet or decrease the payout amount. |
| 400 | 6002 | The selected currency is disabled. | Select an active currency. |
| 400 | 7001 | An incorrect payment address is specified or the address isn't whitelisted. | Specify a correct payment address and proceed with the payment. |
| 400 | — | Invalid data (serializer errors). | Send valid data. |
| 401 | — | No active account with the given credentials is found. | Send correct credentials or update access tokens. |
| 403 | 403 | Permission denied. | Make sure that: 1) The registration process is completed, the KYC is passed. 2) The access token is valid. 3) The correct wallet is specified in the request. |
| 500 | 500 | Internal server error. | Try again later. |
| 502 | — | Bad gateway. | Try again later. |
| 503 (old 400) | 4018 | Fee calculation or payout creation for the selected currency is temporarily unavailable. | Change the currency or try again later. |
| 503 | — | Service unavailable. | Try again later. |
| 504 | — | Gateway timeout. | Try again later. |
| 5xx | — | Other server errors. | Try again later. |

## Payout Callback

A callback is a notification sent to a user's callback URL when a new payout-related transaction occurs on the blockchain.

### Callback Processing

Upon receiving a required number of confirmations related to a new transaction, the callback is sent to your server if the payout request body includes a valid callback URL. The callback is sent as a POST-request, which contains useful JSON payload.

After processing the payload, your server should respond with the HTTP 200 response code without a body.

If your server is temporarily unavailable or the status of the response is different from 200, the system will resend the callback several times with the increasing delay time. The number of re-sendings is limited.

If the manual resending is required, you can do it on the **Wallet management** > **Callbacks** page in the Web UI.

### Callback Verification

To verify that the callback was sent by Coinsbuy, generate an HMAC-signature using `sha256` as algorithm: `sha256` hash of your `callback_secret` and `message` (the concatenation of the `transfer.status`, `transfer.amount`, `payout.tracking_id`, `meta.time` fields).

**Callback Verification Example (PHP):**

```php
<?php
# Generate secret key in your account settings and set it here
$callback_secret = 'grOyrH6AnqF2JnVpELbv4LAQCNCzW2hZS/pzuj94GcQ=';

# parse callback data
$callback_payload = json_decode(file_get_contents('php://input'), true);

$callback_sign = $callback_payload['meta']['sign'];
$callback_time = $callback_payload['meta']['time'];

# retrieve transfer and payout attributes
$included_transfer = array_filter(
  $callback_payload['included'],
  function ($item) {
    return $item['type'] === 'transfer';
  }
);
$included_transfer = array_pop($included_transfer)['attributes'];
$payout = $callback_payload['data']['attributes'];

$status = $included_transfer['status'];
$amount = $included_transfer['amount'];
$tracking_id = $payout['tracking_id'];

# prepare data for hash check
$message = $status . $amount . $tracking_id . $callback_time;
$hash_hmac_result = hash_hmac('sha256', $message, $callback_secret);

# verify signature
if ($hash_hmac_result === $callback_sign) {
  echo 'Verified';
} else {
  echo 'Invalid sign';
}
```

### Callback Structure

The callback consists of three parts:

- **data** — the payout itself. Contains the Payout object.
- **included** — the transaction received for this payout. Contains the Currency object and Transfer object.
- **meta** — the metadata used for callback authentication. Contains the following fields:
  - `time` (string) — the date and time when the callback was sent.
  - `sign` (string) — the HMAC signature for a callback authentication.

### Callback Body Example

```json
{
  "data": {
    "type": "payout",
    "id": "26",
    "attributes": {
      "address": "2NBr9k5xhvE2PxAAiFuczqQkeN76ShMdRZ6",
      "created_at": "2021-09-30T13:01:49.930612Z",
      "tracking_id": "12",
      "fee_amount": "0.00000823",
      "is_fee_included": false,
      "amount": "0.00010000",
      "destination": {
        "address_type": "legacy",
        "address": "2NBr9k5xhvE2PxAAiFuczqQkeN76ShMdRZ6"
      }
    },
    "relationships": {
      "wallet": {
        "data": {
          "type": "wallet",
          "id": "19"
        }
      },
      "currency": {
        "data": {
          "type": "currency",
          "id": "1000"
        }
      },
      "transfer": {
        "data": {
          "type": "transfer",
          "id": "145"
        }
      }
    }
  },
  "included": [
    {
      "type": "currency",
      "id": "1000",
      "attributes": {
        "iso": 1000,
        "name": "Bitcoin",
        "alpha": "BTC",
        "alias": null,
        "exp": 8,
        "confirmation_blocks": 3,
        "minimal_transfer_amount": "0.00000546",
        "block_delay": 3600
      }
    },
    {
      "type": "transfer",
      "id": "145",
      "attributes": {
        "confirmations": 3,
        "risk": 0,
        "risk_status": 4,
        "op_id": 26,
        "op_type": 2,
        "amount": "0.00010000",
        "commission": "0.00000000",
        "fee": "0.00000823",
        "txid": "c3cc36f4569fdbfaacdbc14647e5046d9f239ab1af0268b531a5a213411a8fc9",
        "status": 2,
        "message": null,
        "user_message": null,
        "created_at": "2021-09-30T13:01:50.273446Z",
        "updated_at": "2021-09-30T13:02:33.743770Z"
      },
      "relationships": {
        "currency": {
          "data": {
            "type": "currency",
            "id": "1000"
          }
        }
      }
    }
  ],
  "meta": {
    "time": "2021-09-30T13:02:34.059939+00:00",
    "sign": "8ef2a0f0c6826895593d0d137cf6ce7353a4bbe999d4a6c363f92f1e9d7f8e32"
  }
}
```

## Payout Options

### Request

```
OPTIONS [base]/payout
```

### Request Parameters

No request parameters.

### Request Example

**cURL:**
```bash
curl --request OPTIONS \
--url [base]/payout/ \
--header 'Authorization: Bearer <token>' \
--header 'Content-Type: application/vnd.api+json'
```

**Python:**
```python
import requests
import json

url = '[base]/payout/'
headers = {
  'Content-Type': 'application/vnd.api+json',
  'Authorization': 'Bearer <token>'
}

response = requests.options(url, headers=headers)
print(response.text)
```

**PHP:**
```php
<?php
$client = new Client();
$headers = [
  'Content-Type' => 'application/vnd.api+json',
  'Authorization' => 'Bearer <token>'
];
$request = new Request('OPTIONS', '[base]/payout/', $headers, $body);
$res = $client->sendAsync($request)->wait();
echo $res->getBody();
```

### Response

In case of success, the response body contains an array of available methods along with a list of field parameters such as labels, min and max values, regular expressions used for validation, and so on.

### Response Codes

| HTTP code | Description | Suggested action |
|-----------|-------------|------------------|
| 200 | The request succeeded. | — |
| 500 | Internal server error. The request wasn't completed due to an internal error on the server side. | Try again later. |

### Response Body Example

The response contains detailed information about available fields, their types, validation rules, and allowed values. This includes:

- Field types (string, decimal, integer, boolean, field, url)
- Required/optional status
- Min/max values
- Maximum string lengths
- Regular expressions for validation
- Address masks for different currencies
- Allowed choices for enum fields

This information is useful for understanding what parameters are required or optional when creating payouts.
