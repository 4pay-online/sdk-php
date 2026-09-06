<?php

/**
 * Fixtures shared by the test files.
 *
 * The stub transport is the only seam the client exposes for tests: it records
 * what would have gone over the wire and replays a canned response, so the
 * suite proves the request shape without a network or a sandbox account.
 */

declare(strict_types=1);

use FourPay\Client;

const CREATED = [
    'id' => 'tx-1', 'status' => 'created', 'type' => 'payment', 'env' => 'test',
    'amount' => '1000', 'currency' => 'USD', 'txid' => 'order-1',
    'widget_url' => 'https://pay.sandbox.4pay.online?id=tx-1&token=jwt',
];

/**
 * The transport on its own, for tests where the client is the thing under test.
 *
 * stubClient() below wraps it in a keyed client, which is what most tests want.
 * The tests about how a client comes into being cannot use that — they have to
 * build their own.
 *
 * @param callable(int): array{status?: int, body?: array} $handler
 * @return array{0: callable, 1: object}
 */
function recordingTransport(callable $handler): array
{
    $recorder = new class {
        /** @var list<array<string, mixed>> */
        public array $calls = [];
    };

    $transport = static function (array $request) use ($handler, $recorder): array {
        $recorder->calls[] = [
            'url' => $request['url'],
            'method' => $request['method'],
            'headers' => $request['headers'],
            'body' => $request['body'] !== null ? json_decode($request['body'], true) : null,
        ];
        $response = $handler(count($recorder->calls));
        return [
            'status' => $response['status'] ?? 200,
            'body' => json_encode($response['body'] ?? []),
            'headers' => [],
        ];
    };

    return [$transport, $recorder];
}

/**
 * @param callable(int): array{status?: int, body?: array} $handler
 * @return array{0: Client, 1: object}
 */
function stubClient(callable $handler, string $organizationId = 'org-1'): array
{
    [$transport, $recorder] = recordingTransport($handler);

    $client = new Client(
        apiKey: 'key-1',
        organizationId: $organizationId,
        baseUrl: 'https://sandbox.4pay.online',
        maxRetries: 2,
        transport: $transport,
    );

    return [$client, $recorder];
}

function headerValue(array $headers, string $name): ?string
{
    foreach ($headers as $header) {
        if (str_starts_with(strtolower($header), strtolower($name) . ':')) {
            return trim(substr($header, strlen($name) + 1));
        }
    }
    return null;
}

