<?php

declare(strict_types=1);

use FourPay\EventDeduplicator;
use FourPay\Webhooks;
use FourPay\WebhookVerificationException;

const WEBHOOK_SECRET = 'whsec_test';

/** @return array{0: string, 1: array<string, string>} */
function delivery(string $secret = WEBHOOK_SECRET, int $timestamp = 1788434490): array
{
    $envelope = [
        'event_id' => '4d9d0e6c-1d0a-4a2e-9a2f-0c9a1b0e8f21',
        'timestamp' => $timestamp,
        'type' => 'transaction.charged',
        'data' => [
            'id' => 'tx-1', 'txid' => 'order-1', 'type' => 'payment',
            'status' => 'charged', 'amount' => '1000',
        ],
    ];
    $body = json_encode($envelope);

    return [$body, [
        'x-webhook-event-id' => $envelope['event_id'],
        'x-webhook-timestamp' => (string) $timestamp,
        'x-webhook-signature' => hash_hmac('sha256', "{$timestamp}.{$body}", $secret),
    ]];
}

test('a genuine delivery verifies and returns the envelope', function (): void {
    [$body, $headers] = delivery();
    $event = Webhooks::verify($body, $headers, WEBHOOK_SECRET, now: 1788434500);
    assertSameValue('charged', $event['data']['status']);
});

test('re-encoding the body breaks the signature — sign the raw bytes', function (): void {
    [$body, $headers] = delivery();
    $reencoded = json_encode(json_decode($body, true), JSON_PRETTY_PRINT);
    assertThrows(
        WebhookVerificationException::class,
        fn () => Webhooks::verify($reencoded, $headers, WEBHOOK_SECRET, now: 1788434500),
    );
});

test('a forged signature is refused', function (): void {
    [$body, $headers] = delivery();
    $headers['x-webhook-signature'] = str_repeat('0', 64);
    assertThrows(
        WebhookVerificationException::class,
        fn () => Webhooks::verify($body, $headers, WEBHOOK_SECRET, now: 1788434500),
        'does not match',
    );
});

test('a delivery signed with another secret is refused', function (): void {
    [$body, $headers] = delivery('whsec_other');
    assertThrows(
        WebhookVerificationException::class,
        fn () => Webhooks::verify($body, $headers, WEBHOOK_SECRET, now: 1788434500),
        'does not match',
    );
});

test('an old delivery is refused as a replay', function (): void {
    [$body, $headers] = delivery();
    assertThrows(
        WebhookVerificationException::class,
        fn () => Webhooks::verify($body, $headers, WEBHOOK_SECRET, now: 1788434490 + 3600),
        'replay refused',
    );
});

test('an unsigned delivery is refused, not trusted', function (): void {
    [$body] = delivery();
    assertThrows(
        WebhookVerificationException::class,
        fn () => Webhooks::verify($body, [], WEBHOOK_SECRET),
        'no signature headers',
    );
});

test('header case does not matter', function (): void {
    [$body, $headers] = delivery();
    $upper = [];
    foreach ($headers as $name => $value) {
        $upper[strtoupper($name)] = $value;
    }
    assertSameValue(
        'transaction.charged',
        Webhooks::verify($body, $upper, WEBHOOK_SECRET, now: 1788434500)['type'],
    );
});

test('the same event id is accepted once', function (): void {
    $dedupe = new EventDeduplicator();
    assertSameValue(true, $dedupe->accept('evt-1'));
    assertSameValue(false, $dedupe->accept('evt-1'));
    assertSameValue(true, $dedupe->accept('evt-2'));
});
