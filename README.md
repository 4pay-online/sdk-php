# 4pay/sdk

PHP client for the [4pay.online](https://4pay.online) payment platform. PHP 8.1+, requires
`ext-json`, `ext-curl` and `ext-bcmath`.

The package is not on Packagist. Composer reaches this repository through a `vcs` entry:

```json
{
  "repositories": [
    { "type": "vcs", "url": "https://github.com/4pay-online/sdk-php.git" }
  ],
  "require": { "4pay/sdk": "dev-master" }
}
```

```bash
composer install
```

Or unpack [the archive](https://docs.4pay.online/sdk/4pay-sdk-php.tar.gz) and run
`composer install` inside it.

```php
use FourPay\Client;

$pay = new Client(
    apiKey: getenv('FOURPAY_API_KEY'),
    organizationId: getenv('FOURPAY_ORGANIZATION_ID'),   // issued together with the key
    baseUrl: 'https://sandbox.4pay.online',
);

[$transaction, $paymentUrl] = $pay->createHostedPayment([
    'amount' => '150.00',        // or '15000' in minor units — both work
    'currency' => 'USD',
    'env' => 'test',
    'txid' => 'order-8891',      // your order id, and the idempotency key
    'returnUrl' => 'https://shop.example/thanks',
    'failUrl' => 'https://shop.example/sorry',
]);

header("Location: {$paymentUrl}");   // valid for ~15 minutes
```

## Webhooks

```php
use FourPay\Webhooks;
use FourPay\WebhookVerificationException;

try {
    $event = Webhooks::verify(file_get_contents('php://input'), Webhooks::requestHeaders(), $secret);
} catch (WebhookVerificationException) {
    http_response_code(401);
    exit;
}

http_response_code(200);
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();   // acknowledge first, work afterwards
}
```

`php://input`, never `$_POST`: the signature covers the raw bytes. Deduplicate on `event_id`
against your database — a PHP process does not outlive the request.

## Development

```bash
php tests/run.php
```

## What it handles for you

| The raw API does this | The SDK does this |
|---|---|
| `hosted` has no default; without it a payment is refused as `unexpected card` | the hosted helper sets it |
| `201 Created` carries refusals (`rejected`, `failed`) | raises, with a flag telling "no terminal" from "card declined" |
| a missing `x-organization-id` gives `400`, not `401` | both headers always sent |
| timestamps like `2026-09-03 11:18:30.101839 Etc/UTC` | parsed correctly, always UTC |
| paging is `cursor`; `start_cursor` is silently ignored | follows the real cursor, and stops if it stops moving |
| a retried create without `txid` charges twice | only idempotent writes are retried |
| money as decimal strings in minor units | integer arithmetic throughout — never floats |

## Agentic commerce

`agentic/` carries the merchant side of MCP, AP2, ACP and A2A — see
[Agentic Payments](https://docs.4pay.online/docs/partners/agentic-payments).

## Documentation

[docs.4pay.online/docs/partners/sdk](https://docs.4pay.online/docs/partners/sdk)

## Licence

MIT.
