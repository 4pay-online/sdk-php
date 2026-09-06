<?php

declare(strict_types=1);

use FourPay\Exception\AuthException;
use FourPay\Exception\TransactionRejectedException;
use FourPay\Client;

test('an API key without an organization id is refused before any request', function (): void {
    assertThrows(InvalidArgumentException::class, fn () => new Client(apiKey: 'key-1'), 'organizationId');
});

// The custom-domain path is for session tokens. A key is issued together with its
// organizationId, so there is no reason to guess at one.
test('an API key is refused without an organization id even behind a perimeter', function (): void {
    assertThrows(
        InvalidArgumentException::class,
        fn () => new Client(
            apiKey: 'key-1',
            baseUrl: 'https://pay.partner.example',
            organizationFromPerimeter: true,
        ),
        'organizationId'
    );
});

// Nothing on the default host says which organization the session acts in.
test('a bearer token on the shared host needs an organization id', function (): void {
    assertThrows(
        InvalidArgumentException::class,
        fn () => new Client(bearerToken: 'token-1'),
        'organizationId'
    );
});

// The partner states it: their own proxy pins x-organization-id from the vhost,
// so the client has nothing to add.
test('a bearer token behind a perimeter needs no organization id', function (): void {
    $calls = [];
    $client = new Client(
        bearerToken: 'token-1',
        baseUrl: 'https://pay.partner.example',
        organizationFromPerimeter: true,
        transport: function (array $request) use (&$calls): array {
            $calls[] = $request;
            return ['status' => 200, 'body' => json_encode(CREATED), 'headers' => []];
        },
    );
    $client->getTransaction('tx-1');

    assertSameValue(null, headerValue($calls[0]['headers'], 'x-organization-id'));
    assertSameValue('Bearer token-1', headerValue($calls[0]['headers'], 'authorization'));
});

// The bug this option replaced. The SDK used to infer "own domain" from one
// literal host, so every OTHER host of ours counted as somebody's own — the
// sandbox included. A session token on the sandbox with no organizationId was
// accepted, and every call it made carried no organization at all.
test('our own hosts other than the default still need an organization id', function (): void {
    $ours = [
        'https://sandbox.4pay.online',
        'https://api.4pay.online',
        'https://pay.4pay.online',
        'https://pay.sandbox.4pay.online',
    ];

    foreach ($ours as $baseUrl) {
        assertThrows(
            InvalidArgumentException::class,
            fn () => new Client(bearerToken: 'token-1', baseUrl: $baseUrl),
            'organizationId'
        );
    }
});

// Stating it does not make it so. Nothing in front of our hosts pins the header,
// so the claim is a misunderstanding — and left standing it would send the same
// organization-less calls the option exists to prevent.
test('claiming a perimeter on our own host is refused', function (): void {
    $ours = ['https://4pay.online', 'https://sandbox.4pay.online', 'https://api.4pay.online'];

    foreach ($ours as $baseUrl) {
        assertThrows(
            InvalidArgumentException::class,
            fn () => new Client(
                bearerToken: 'token-1',
                baseUrl: $baseUrl,
                organizationFromPerimeter: true,
            ),
            'that host is ours'
        );
    }
});

// The credential check has exactly one way past it, and it is not a parameter a
// caller can pass: forLogin() raises a flag this class keeps to itself.
test('the login-only path cannot be taken from outside', function (): void {
    assertThrows(
        InvalidArgumentException::class,
        fn () => new Client(organizationId: 'org-1'),
        'anonymous access'
    );

    // And the flag does not survive a throw inside forLogin(): the next
    // ordinary client must still be refused
    try {
        Client::forLogin(baseUrl: 'https://4pay.online', organizationFromPerimeter: true);
    } catch (InvalidArgumentException) {
        // expected — the claim is refused on our own host
    }

    assertThrows(
        InvalidArgumentException::class,
        fn () => new Client(organizationId: 'org-1'),
        'anonymous access'
    );
});

// Spaces would sail through a check for null and turn the gate into a formality —
// the failure would surface as a platform error on the first call.
test('a blank organization id is no organization id', function (): void {
    assertThrows(
        InvalidArgumentException::class,
        fn () => new Client(apiKey: 'key-1', organizationId: '   '),
        'organizationId'
    );
});

test('a padded organization id travels trimmed', function (): void {
    [$client, $recorder] = stubClient(fn (int $n) => ['body' => CREATED], organizationId: '  org-1  ');
    $client->getTransaction('tx-1');

    // Asserted on the raw header line rather than through headerValue(), which
    // trims what it reads back: a padded value would sail through that helper
    // and the test would prove nothing.
    $sent = array_values(array_filter(
        $recorder->calls[0]['headers'],
        static fn (string $header): bool => str_starts_with($header, 'x-organization-id:'),
    ));
    assertSameValue(['x-organization-id: org-1'], $sent);
});

test('both credential headers travel on every call', function (): void {
    [$client, $recorder] = stubClient(fn (int $n) => ['body' => CREATED]);
    $client->getTransaction('tx-1');

    assertSameValue('key-1', headerValue($recorder->calls[0]['headers'], 'x-api-key'));
    assertSameValue('org-1', headerValue($recorder->calls[0]['headers'], 'x-organization-id'));
});

test('createHostedPayment sets hosted because the platform has no default', function (): void {
    [$client, $recorder] = stubClient(fn (int $n) => ['status' => 201, 'body' => CREATED]);
    [, $url] = $client->createHostedPayment([
        'amount' => '10.00', 'currency' => 'USD', 'env' => 'test', 'txid' => 'order-1',
    ]);

    $sent = $recorder->calls[0]['body']['params'];
    assertSameValue(true, $sent['hosted']);
    assertSameValue('1000', $sent['amount'], 'major units are converted');
    assertSameValue(CREATED['widget_url'], $url);
});

test('201 Created carrying a rejected transaction raises', function (): void {
    [$client] = stubClient(fn (int $n) => [
        'status' => 201,
        'body' => array_merge(CREATED, ['status' => 'rejected', 'error_description' => 'terminal not found']),
    ]);

    assertThrows(
        TransactionRejectedException::class,
        fn () => $client->createPayment(['amount' => '1000', 'currency' => 'USD', 'env' => 'test']),
        'terminal not found',
    );
});

test('a create without an idempotency key is never retried', function (): void {
    [$client, $recorder] = stubClient(fn (int $n) => [
        'status' => 503, 'body' => ['errors' => [['detail' => 'busy']]],
    ]);

    assertThrows(
        Throwable::class,
        fn () => $client->createPayment(['amount' => '1000', 'currency' => 'USD', 'env' => 'test']),
    );
    assertSameValue(1, count($recorder->calls), 'retrying an un-keyed create would charge twice');
});

test('a create with a txid is retried', function (): void {
    [$client, $recorder] = stubClient(fn (int $n) => $n < 3
        ? ['status' => 503, 'body' => ['errors' => [['detail' => 'busy']]]]
        : ['status' => 201, 'body' => CREATED]);

    $tx = $client->createPayment([
        'amount' => '1000', 'currency' => 'USD', 'env' => 'test', 'txid' => 'order-1',
    ]);
    assertSameValue('tx-1', $tx['id']);
    assertSameValue(3, count($recorder->calls));
});

test('a bare 400 is surfaced as an auth problem', function (): void {
    [$client] = stubClient(fn (int $n) => ['status' => 400, 'body' => ['errors' => [['detail' => 'Bad Request']]]]);
    assertThrows(AuthException::class, fn () => $client->getTransaction('tx-1'));
});

test('env must be in the body, and the SDK refuses to guess it', function (): void {
    [$client] = stubClient(fn (int $n) => ['status' => 201, 'body' => CREATED]);
    assertThrows(
        InvalidArgumentException::class,
        fn () => $client->createPayment(['amount' => '1000', 'currency' => 'USD']),
        'PRODUCTION transaction',
    );
});

test('a server-to-server payment without redirect URLs is stopped before it creates debris', function (): void {
    [$client, $recorder] = stubClient(fn (int $n) => ['status' => 201, 'body' => CREATED]);
    assertThrows(
        InvalidArgumentException::class,
        fn () => $client->createPayment([
            'amount' => '1000', 'currency' => 'RUB', 'env' => 'test', 'txid' => 'o-1',
            'money_storage' => ['cardnumber' => '2201382000000013'],
        ]),
        'returnUrl and failUrl',
    );
    assertSameValue(0, count($recorder->calls));
});

test('iteration follows the cursor and stops when it stops moving', function (): void {
    $pages = [
        ['data' => [['id' => 'a'], ['id' => 'b']], 'cursor' => ['cursor_field' => 'started', 'cursor_value' => 't2']],
        ['data' => [['id' => 'c']], 'cursor' => ['cursor_field' => 'started', 'cursor_value' => 't3']],
        ['data' => [], 'cursor' => ['cursor_field' => 'started', 'cursor_value' => null]],
    ];
    [$client, $recorder] = stubClient(fn (int $n) => ['body' => $pages[$n - 1]]);

    $seen = [];
    foreach ($client->iterateTransactions(['env' => 'test']) as $tx) {
        $seen[] = $tx['id'];
    }

    assertSameValue(['a', 'b', 'c'], $seen);
    assertTrueValue(str_contains($recorder->calls[1]['url'], 'cursor=t2'), 'paging uses cursor');
});

test('a repeating cursor ends the walk instead of looping for ever', function (): void {
    $page = ['data' => [['id' => 'a']], 'cursor' => ['cursor_field' => 'started', 'cursor_value' => 'same']];
    [$client, $recorder] = stubClient(fn (int $n) => ['body' => $page]);

    $seen = [];
    foreach ($client->iterateTransactions(['env' => 'test']) as $tx) {
        $seen[] = $tx['id'];
    }

    assertSameValue(['a', 'a'], $seen);
    assertSameValue(2, count($recorder->calls));
});

// The login itself is unauthenticated, but it is not organization-agnostic: a
// person is looked up in that organization's own schema. Sending the credential
// headers here would be pointless; sending the organization is not.
test('createSession carries the organization without carrying a credential', function (): void {
    [$client, $recorder] = stubClient(fn (int $n) => ['body' => ['token' => 'session-1', 'expire_at' => 1]]);
    $client->createSession('partner@example.com', 'secret');

    $headers = $recorder->calls[0]['headers'];
    assertSameValue('org-1', headerValue($headers, 'x-organization-id'));
    assertSameValue(null, headerValue($headers, 'x-api-key'));
    assertSameValue(null, headerValue($headers, 'authorization'));
});

test('health reports core_status, since nothing is called status', function (): void {
    [$client, $recorder] = stubClient(fn (int $n) => ['body' => ['core_status' => 'ok', 'appversion' => 'f0175d14']]);
    $health = $client->health();

    assertSameValue('ok', $health['core_status']);
    assertSameValue(null, $health['status'] ?? null);
    assertSameValue(null, headerValue($recorder->calls[0]['headers'], 'x-api-key'));
});

// --- the shared host is a host, not a spelling of one ------------------------

// The legitimate shared-host case, and the one the gate exists to allow.
test('a session on the shared host is built when it names its organization', function (): void {
    [$transport, $recorder] = recordingTransport(fn (int $n) => ['body' => CREATED]);
    $client = new Client(bearerToken: 'token-1', organizationId: 'org-1', transport: $transport);
    $client->getTransaction('tx-1');

    assertSameValue('https://4pay.online/api/v1/transactions/tx-1', $recorder->calls[0]['url']);
    assertSameValue('org-1', headerValue($recorder->calls[0]['headers'], 'x-organization-id'));
    assertSameValue('Bearer token-1', headerValue($recorder->calls[0]['headers'], 'authorization'));
});

test('the same session without an organization id is not built', function (): void {
    assertThrows(
        InvalidArgumentException::class,
        fn () => new Client(bearerToken: 'token-1'),
        'organizationId'
    );
});

// A path, a port, credentials, capitals — none of them is a domain of one's own.
// Compared as whole strings each of these sailed past the gate, and the client
// went out naming no organization at all.
test('our host spelled differently is still our host', function (): void {
    $spellings = [
        'https://4pay.online/api',
        'https://4pay.online/',
        'https://4pay.online:443',
        'https://someone:secret@4pay.online',
        'HTTPS://4PAY.ONLINE',
        'https://sandbox.4pay.online:8443/api',
    ];

    foreach ($spellings as $baseUrl) {
        assertThrows(
            InvalidArgumentException::class,
            fn () => new Client(
                bearerToken: 'token-1',
                baseUrl: $baseUrl,
                organizationFromPerimeter: true,
            ),
            'that host is ours'
        );
    }
});

// A name that merely ENDS in something of ours is not ours: the check is on the
// dot boundary, not on a substring.
test('a partner host that only looks like ours is a partner host', function (): void {
    $hosts = [
        'https://pay.partner.example',
        'https://pay.partner.example/gateway',
        'https://4pay.online.partner.example',
        'https://not4pay.online',
    ];

    foreach ($hosts as $baseUrl) {
        [$transport, $recorder] = recordingTransport(fn (int $n) => ['body' => CREATED]);
        $client = new Client(
            bearerToken: 'token-1',
            baseUrl: $baseUrl,
            organizationFromPerimeter: true,
            transport: $transport,
        );
        $client->getTransaction('tx-1');

        assertSameValue(null, headerValue($recorder->calls[0]['headers'], 'x-organization-id'));
    }
});

// The URL is built by concatenation, so the normalization is what keeps
// "//api/v1/..." out of the request line.
test('a trailing slash on the base url does not double up in the path', function (): void {
    [$transport, $recorder] = recordingTransport(fn (int $n) => ['body' => CREATED]);
    $client = new Client(
        apiKey: 'key-1',
        organizationId: 'org-1',
        baseUrl: 'https://sandbox.4pay.online///',
        transport: $transport,
    );
    $client->getTransaction('tx-1');

    assertSameValue(
        'https://sandbox.4pay.online/api/v1/transactions/tx-1',
        $recorder->calls[0]['url']
    );
});

// --- logging in, which starts without a credential ---------------------------

test('a login client is built without a credential because that is the point', function (): void {
    [$transport, $recorder] = recordingTransport(
        fn (int $n) => ['body' => ['token' => 'session-1', 'expire_at' => 1]]
    );
    $client = Client::forLogin(
        organizationId: 'org-1',
        baseUrl: 'https://sandbox.4pay.online',
        transport: $transport,
    );
    $client->createSession('partner@example.com', 'secret');

    assertSameValue('https://sandbox.4pay.online/api/v1/session', $recorder->calls[0]['url']);
    assertSameValue(null, headerValue($recorder->calls[0]['headers'], 'x-api-key'));
});

// An admin lives in the platform's own schema, not in an organization's, so there
// is nothing to put in x-organization-id — and the login still works.
test('an admin logs in on the shared host with no organization to name', function (): void {
    [$transport, $recorder] = recordingTransport(
        fn (int $n) => ['body' => ['token' => 'session-1', 'expire_at' => 1]]
    );
    Client::forLogin(transport: $transport)->createSession('admin@example.com', 'secret', 'admin');

    assertSameValue('https://4pay.online/api/v1/session', $recorder->calls[0]['url']);
    assertSameValue(null, headerValue($recorder->calls[0]['headers'], 'x-organization-id'));
});

test('a login hands the client the session token it got', function (): void {
    [$transport, $recorder] = recordingTransport(
        fn (int $n) => $n === 1
            ? ['body' => ['token' => 'session-1', 'expire_at' => 1]]
            : ['body' => CREATED]
    );
    $client = Client::forLogin(
        organizationId: 'org-1',
        baseUrl: 'https://sandbox.4pay.online',
        transport: $transport,
    );
    $session = $client->createSession('person@example.com', 'secret', 'person');
    $client->getTransaction('tx-1');

    assertSameValue('session-1', $session['token']);
    assertSameValue('org-1', headerValue($recorder->calls[0]['headers'], 'x-organization-id'));
    assertSameValue(null, headerValue($recorder->calls[0]['headers'], 'authorization'));
    assertSameValue('Bearer session-1', headerValue($recorder->calls[1]['headers'], 'authorization'));
});

// The platform looks a person up in one organization's schema. With nothing to
// look in it answers 401, which reads as a wrong password.
test('a person login with no organization is stopped before the flat 401', function (): void {
    [$transport, $recorder] = recordingTransport(fn (int $n) => ['body' => []]);
    $client = Client::forLogin(transport: $transport);

    assertThrows(
        InvalidArgumentException::class,
        fn () => $client->createSession('person@example.com', 'secret', 'person'),
        'person login'
    );
    assertSameValue([], $recorder->calls);
});

test('a login client refuses an ordinary call until it has a session', function (): void {
    [$transport, $recorder] = recordingTransport(fn (int $n) => ['body' => CREATED]);
    $client = Client::forLogin(
        organizationId: 'org-1',
        baseUrl: 'https://sandbox.4pay.online',
        transport: $transport,
    );

    assertThrows(
        InvalidArgumentException::class,
        fn () => $client->getTransaction('tx-1'),
        'no credential yet'
    );
    assertSameValue([], $recorder->calls);
});
