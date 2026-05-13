# Rate Methods

## Rate Object

| Name | Type | Description |
|------|------|-------------|
| `left` | string | The base currency. |
| `right` | string | The quote currency. |
| `bid` | string | The current bid price. |
| `ask` | string | The current ask price. |
| `exp` | number | The currency precision, that is the number of digits after the decimal separator, for the `bid` and `ask` fields. |
| `created_at` | string | The date and time when a rate was received. |
| `expired_at` | string | The date and time of rate expiration. |

### Rate Object Example

```json
{
  "type": "rate",
  "id": "0",
  "attributes": {
    "left": "ZRX",
    "right": "USDC",
    "bid": "0.381855018600000000",
    "ask": "0.389670322000000000",
    "exp": 18,
    "expired_at": "2024-02-28T09:45:48.314413Z",
    "created_at": "2024-02-28T09:40:48.314413Z"
  }
}
```

## Get Rates

### Request

```
GET [base]/rates/
```

Rates can be filtered by the `left` and `right` parameters according to the JSON API Specification. For example, the response body will only contain the rates with the BTC base currency: `/rates?filter[left]=BTC`. You can specify more than one currency, for example, the response body will contain the rates with the BTC and USDT base currencies: `/rates?filter[left]=BTC,USDT`.

### Request Parameters

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `filter[left]` | string | No | Filter by base currency. Can specify multiple currencies separated by commas (e.g., `BTC,USDT`). |
| `filter[right]` | string | No | Filter by quote currency. |

### Request Example

**cURL:**
```bash
curl --request GET \
--url [base]/rates/ \
--header 'Authorization: Bearer <token>' \
--header 'Content-Type: application/vnd.api+json'
```

**Filter by base currency:**
```bash
curl --request GET \
--url '[base]/rates/?filter[left]=BTC' \
--header 'Authorization: Bearer <token>' \
--header 'Content-Type: application/vnd.api+json'
```

**Filter by multiple base currencies:**
```bash
curl --request GET \
--url '[base]/rates/?filter[left]=BTC,USDT' \
--header 'Authorization: Bearer <token>' \
--header 'Content-Type: application/vnd.api+json'
```

**Python:**
```python
import requests

url = '[base]/rates/'
headers = {
    'Authorization': 'Bearer <token>',
    'Content-Type': 'application/vnd.api+json',
}

# Get all rates
response = requests.get(url, headers=headers)

# Filter by base currency
url_filtered = '[base]/rates/?filter[left]=BTC'
response = requests.get(url_filtered, headers=headers)
```

**PHP:**
```php
<?php
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

$client = new GuzzleHttp\Client();
try {
  // Get all rates
  $res = $client->get('[base]/rates/', [
    'headers' => [
      'Authorization' => 'Bearer <token>',
      'Content-Type' => 'application/vnd.api+json',
    ],
  ]);
  echo $res->getBody();
  
  // Filter by base currency
  $res = $client->get('[base]/rates/?filter[left]=BTC', [
    'headers' => [
      'Authorization' => 'Bearer <token>',
      'Content-Type' => 'application/vnd.api+json',
    ],
  ]);
  echo $res->getBody();
} catch (RequestException $e) {}
```

### Response

In case of success, the response body contains an array of rate objects.

### Response Codes

| HTTP code | Application code | Description | Suggested action |
|-----------|------------------|-------------|------------------|
| 200 | — | The request succeeded. | — |
| 400 | — | Bad request. | Check request parameters. |
| 500 | — | Internal server error. | Try again later. |
| 502 | — | Bad gateway. | Try again later. |
| 503 | — | Service unavailable. | Try again later. |
| 504 | — | Gateway timeout. | Try again later. |
| 5xx | — | Other server errors. | Try again later. |

## Filtering Examples

### Get rates for a specific base currency

```
GET [base]/rates/?filter[left]=BTC
```

Returns all rates where BTC is the base currency (e.g., BTC/USD, BTC/EUR, BTC/USDT).

### Get rates for multiple base currencies

```
GET [base]/rates/?filter[left]=BTC,USDT
```

Returns all rates where BTC or USDT is the base currency.

### Get rates for a specific currency pair

```
GET [base]/rates/?filter[left]=BTC&filter[right]=USD
```

Returns the rate for the BTC/USD pair.

