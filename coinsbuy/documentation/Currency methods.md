# Currency Methods

## Currency Object

| Name | Type | Description |
|------|------|-------------|
| `id` | string | The unique system identifier of a currency, matching the ISO code. |
| `blockchain_name` | string | The name of a blockchain, for example: `Bitcoin`.<br><br>The value is filled in by default only for coins. For other currencies, the value is an empty string. |
| `iso` | number | The ISO code of a currency. |
| `name` | string | The name of a currency, for example: `Bitcoin`. |
| `alpha` | string | The alphabetic code of a currency, for example: `BTC`. |
| `alias` | string or null | The alternative identifier used to identify similar currencies in different blockchains. |
| `tags` | string | The tags assigned via the Coinsbuy Back Office. |
| `exp` | number | The currency precision, that is the number of digits after the decimal separator. |
| `confirmation_blocks` | number or null | The default number of blocks needed to send a callback. |
| `minimal_transfer_amount` | string | The minimum possible amount of transfers. |
| `block_delay` | number | The estimated block mining time, in seconds. |
| `parent` | object | For tokens only.<br><br>The parent currency of a token, in the same blockchain. For other currencies, returns `"data": null`.<br><br>The object contains the string `id` field matching the parent currency ISO code. |

### Currency Object Example

```json
{
  "type": "currency",
  "id": "2015",
  "attributes": {
    "blockchain_name": "",
    "iso": 2015,
    "name": "TetherUS",
    "alpha": "USDT-ETH",
    "alias": "USDT",
    "tags": "",
    "exp": 6,
    "confirmation_blocks": 3,
    "minimal_transfer_amount": "25.000000",
    "block_delay": 75
  },
  "relationships": {
    "parent": {
      "data": {
        "type": "currency",
        "id": "1002"
      }
    }
  }
}
```

## Get Currency

### Request

```
GET [base]/currency/{id}
```

### Request Parameters

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `id` | string | No | The unique system identifier of a currency, matching the ISO code.<br><br>Filtering by object parameters can be applied according to the JSON API Specification. |

### Request Example

**cURL:**
```bash
curl --request GET \
--url [base]/currency/ \
--header 'Authorization: Bearer <token>' \
--header 'Content-Type: application/vnd.api+json'
```

**Python:**
```python
import requests

url = '[base]/currency/'
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
  $res = $client->get('[base]/currency/', [
    'headers' => [
      'Authorization' => 'Bearer <token>',
      'Content-Type' => 'application/vnd.api+json',
    ],
  ]);
  echo $res->getBody();
} catch (RequestException $e) {}
```

### Response

In case of success, the response body contains a currency object or an array of objects (if the `id` wasn't specified).

The currencies list is paginated and the default page size is 10. You can adjust pagination according to the JSON API Specification.

### Response Codes

| HTTP code | Application code | Description | Suggested action |
|-----------|------------------|-------------|------------------|
| 200 | — | The request succeeded. | — |
| 401 | 2007: No active account found with the given credentials | Incorrect credentials. | Send correct credentials. |
| 404 | 404: Not found | The currency with the given `id` wasn't found. | Send a correct `id`. |
| 500 | — | Internal server error. | Try again later. |
| 502 | — | Bad gateway. | Try again later. |
| 503 | — | Service unavailable. | Try again later. |
| 504 | — | Gateway timeout. | Try again later. |
| 5xx | — | Other server errors. | Try again later. |

