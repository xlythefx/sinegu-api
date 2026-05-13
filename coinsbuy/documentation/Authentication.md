# Authentication

## Obtain token

### Request

```
POST [base]/token
```

### Request Parameters

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `client_id` | string | Yes | Your API key. |
| `client_secret` | string | Yes | Your API secret. |

### Request Example

**cURL:**
```bash
curl --location '{base_url}/token/' \
--header 'Content-Type: application/vnd.api+json' \
--data '{
  "data": {
    "type": "auth-token",
    "attributes": {
      "client_id": "<Your API key>",
      "client_secret": "<Your API secret>"
    }
  }
}'
```

**Python:**
```python
import requests

url = "{base_url}/token/"
headers = {
    "Content-Type": "application/vnd.api+json"
}
data = {
    "data": {
        "type": "auth-token",
        "attributes": {
            "client_id": "<Your API key>",
            "client_secret": "<Your API secret>"
        }
    }
}

response = requests.post(url, headers=headers, json=data)
```

**PHP:**
```php
$ch = curl_init('{base_url}/token/');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/vnd.api+json',
    ],
    CURLOPT_POSTFIELDS => json_encode([
        'data' => [
            'type' => 'auth-token',
            'attributes' => [
                'client_id' => '<Your API key>',
                'client_secret' => '<Your API secret>',
            ],
        ],
    ]),
]);

$response = curl_exec($ch);
curl_close($ch);
```

### Response

| Name | Type | Description |
|------|------|-------------|
| `access` | string | Your access token. It has an expiry time and after expiration should be refreshed by resending the request. |
| `expires_in` | number | The lifetime of the token, in seconds. |
| `token_type` | string | Always `"Bearer"`. |

### Response Example

```json
{
  "data": {
    "type": "auth-token",
    "id": "0",
    "attributes": {
      "access": "eyJhbGciOiJSUzI1NiIsImtpZCI6IjMy...",
      "expires_in": 3599,
      "token_type": "Bearer"
    }
  }
}
```

### Response Codes

| HTTP code | Description | Suggested action |
|-----------|-------------|------------------|
| 200 | OK | — |
| 400 | No active account found with the given credentials | Resend the request with the correct credentials. |
| 429 | Too many requests | Try again later. |
| 4xx | Incorrect request | Resend the request with the correct parameters. |
| 5xx | Server error | Try again later. |

## Using the Access Token

Include the access token in all subsequent API requests:

```http
Authorization: Bearer {YOUR_ACCESS_TOKEN}
Content-Type: application/vnd.api+json
```

**Note:** The access token is returned in the `attributes.access` field, not `access_token`. Make sure to extract it correctly from the JSON API response format.

