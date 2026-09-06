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
    organizationId: getenv('FOURPAY_ORGANIZATION_ID'),   // issued with the key; required for keys
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

## Naming the organization

Every call has to say which organization it acts in, and there are two ways to say it.

**Pass `organizationId`.** The client sends it as `x-organization-id` on every call —
including `createSession()`, which is unauthenticated and still needs it, because a person is
looked up in that organization's own schema. An API key always goes this way: it is issued
together with its `organizationId`, and the platform refuses the request before it ever reads
the key.

**Or set `organizationFromPerimeter`.** A partner integrating on a domain of their own does
not have to know the id: the proxy in front of that domain pins `x-organization-id` from the
vhost, and the client adds nothing. That is a statement about your deployment, which is why
you make it — the SDK cannot see your proxy and does not guess.

```php
// Your domain, your proxy, your organization — no organizationId needed.
$pay = new Client(
    bearerToken: $token,
    baseUrl: 'https://pay.partner.example',
    organizationFromPerimeter: true,
);
```

The constructor insists on one of the two — and on `organizationId` itself when you pass an
API key. A session token with neither names no organization at all, and is refused before any
request goes out.

The option is for a domain of **yours**. On ours — `4pay.online` and everything under it,
including `sandbox.4pay.online` — it is refused: those hosts serve every organization and pin
nothing. Ours is a question about the host: `https://4pay.online/api`,
`https://4pay.online:443` and `https://4pay.online` are the same host spelled three ways.

## Logging in

An API key is not the only way in. `Client::forLogin()` builds a client that has no
credential yet — because a login and a password are what you have — and `createSession()`
turns it into an ordinary client carrying the session token.

```php
$pay = Client::forLogin(baseUrl: 'https://sandbox.4pay.online');
$session = $pay->createSession('admin@example.com', 'secret', 'admin');   // or 'client', 'partner'

$pay->listTransactions(['env' => 'test']);   // the token from that session travels on
```

`expire_at` is Unix **seconds**, and the field is singular; there is no `expires_at`.

Who needs an `organizationId` to log in:

| Account type | Where the platform looks | `organizationId` |
|---|---|---|
| `admin`, `client` | the platform's own schema | not needed |
| `partner` | searched for across organizations | not needed |
| `person` | that one organization's schema | **required** (or `organizationFromPerimeter`) |

A `person` login without one is refused by the SDK: the platform would answer a flat `401`,
which reads like a wrong password.

An admin session names no organization of its own. To act inside one, say which:

```php
$admin = new Client(bearerToken: $session['token'], organizationId: $orgId);
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
| a missing `x-organization-id` gives `400`, not `401` | insists on an `organizationId` or `organizationFromPerimeter`, and sends the header whenever it has one |
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
