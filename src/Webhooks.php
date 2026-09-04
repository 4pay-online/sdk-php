<?php

declare(strict_types=1);

namespace FourPay;

use FourPay\Exception\FourPayException;

/** The delivery did not prove it came from the platform. Answer 401 and stop. */
class WebhookVerificationException extends FourPayException
{
}

/**
 * Verifying a webhook delivery.
 */
final class Webhooks
{
    /**
     * Verify a webhook and return its envelope.
     *
     * The signature is HMAC-SHA256 over `"<timestamp>.<raw body>"`, hex-encoded,
     * keyed with the webhook secret your operator set on the terminal.
     *
     * Two things decide whether this works:
     *
     * - pass the **raw bytes exactly as received** — `file_get_contents('php://input')`,
     *   never `$_POST` and never a re-encoded array. The signature covers bytes;
     * - the signature covers the **whole envelope**, not `data`.
     *
     * @param array<string, string> $headers
     * @return array<string, mixed>
     * @throws WebhookVerificationException on any failure. Never fall through
     *         to processing — an unverified delivery is a stranger's instruction.
     */
    public static function verify(
        string $rawBody,
        array $headers,
        string $secret,
        int $toleranceSeconds = 300,
        ?int $now = null,
    ): array {
        if ($secret === '') {
            throw new WebhookVerificationException(
                'No webhook secret. Without one the platform sends no signature at all, and every '
                . 'delivery is unauthenticated — ask your operator to set one on the terminal.'
            );
        }

        $lower = [];
        foreach ($headers as $name => $value) {
            $lower[strtolower((string) $name)] = is_array($value) ? ($value[0] ?? '') : (string) $value;
        }

        $signature = $lower['x-webhook-signature'] ?? '';
        $timestamp = $lower['x-webhook-timestamp'] ?? '';

        if ($signature === '' || $timestamp === '') {
            throw new WebhookVerificationException(
                'Delivery carries no signature headers. Either the terminal has no webhook secret, '
                . 'or this request did not come from the platform.'
            );
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
        if (!hash_equals($expected, $signature)) {
            throw new WebhookVerificationException('Webhook signature does not match.');
        }

        $current = $now ?? time();
        if (!ctype_digit(ltrim($timestamp, '-'))) {
            throw new WebhookVerificationException("Webhook timestamp {$timestamp} is not a number.");
        }
        if (abs($current - (int) $timestamp) > $toleranceSeconds) {
            throw new WebhookVerificationException(
                "Webhook timestamp {$timestamp} is outside the {$toleranceSeconds}s window — replay refused."
            );
        }

        $envelope = json_decode($rawBody, true);
        if (!is_array($envelope) || !isset($envelope['data'])) {
            throw new WebhookVerificationException('Verified body is not a webhook envelope.');
        }
        return $envelope;
    }

    /**
     * Read the headers of the current request, whatever SAPI is in use.
     *
     * `getallheaders()` exists under Apache and PHP-FPM but not under every
     * SAPI, and the `$_SERVER` fallback is the reason a webhook handler that
     * works locally can go silent behind a different runtime.
     *
     * @return array<string, string>
     */
    public static function requestHeaders(): array
    {
        if (function_exists('getallheaders')) {
            /** @var array<string, string> $headers */
            $headers = getallheaders();
            return $headers;
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_')) {
                $name = str_replace('_', '-', strtolower(substr((string) $key, 5)));
                $headers[$name] = (string) $value;
            }
        }
        return $headers;
    }
}
