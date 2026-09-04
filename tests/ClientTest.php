<?php

declare(strict_types=1);

use FourPay\Exception\AuthException;
use FourPay\Exception\TransactionRejectedException;
use FourPay\Client;

test('an API key without an organization id is refused before any request', function (): void {
    assertThrows(InvalidArgumentException::class, fn () => new Client(apiKey: 'key-1'), 'organizationId');
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

test('health reports core_status, since nothing is called status', function (): void {
    [$client, $recorder] = stubClient(fn (int $n) => ['body' => ['core_status' => 'ok', 'appversion' => 'f0175d14']]);
    $health = $client->health();

    assertSameValue('ok', $health['core_status']);
    assertSameValue(null, $health['status'] ?? null);
    assertSameValue(null, headerValue($recorder->calls[0]['headers'], 'x-api-key'));
});
