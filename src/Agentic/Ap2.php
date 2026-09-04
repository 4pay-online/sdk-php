<?php

declare(strict_types=1);

namespace FourPay\Agentic;

use FourPay\Exception\FourPayException;

/** A mandate that fails any check is not a mandate — it is a claim. */
class MandateVerificationException extends FourPayException
{
}

/**
 * AP2 — Agent Payments Protocol (merchant side).
 *
 * AP2 answers a question a normal checkout never has to ask: *who authorised
 * this, and to what extent?* When a human clicks Pay, the click is the proof.
 * When an agent pays for them, the proof has to travel with the request —
 * signed, bounded and verifiable afterwards.
 *
 * It travels as **mandates**: an *Intent Mandate* is standing permission
 * ("buy these shoes below 120 EUR"), a *Cart Mandate* is a signed itemised
 * cart, and a *Payment Mandate* is what the merchant forwards to the rail.
 *
 * Verification here is local — signature, audience, expiry, totals. The mandate
 * then rides in `merchant_meta.ap2` so the authority stays attached to the
 * transaction. 4pay.online does not forward a Payment Mandate to card networks as
 * an AP2 credential; no rail we route to accepts one today.
 */
final class Ap2
{
    /**
     * Verify a Cart Mandate presented as a compact JWS.
     *
     * Checks, in order: the header names a key we hold; the signature matches;
     * the cart is addressed to us; it has not expired; the total equals the sum
     * of its lines.
     *
     * The line-sum check is the one people leave out. Without it an agent can
     * present three items worth 300 and a total of 3, and every signature in
     * the chain still verifies — the issuer signed what it was asked to.
     *
     * @param array<string, string> $keys PEM public keys by `kid`
     * @return array<string, mixed>
     */
    public static function verifyCartMandate(
        string $jws,
        array $keys,
        string $merchantId,
        int $toleranceSeconds = 0,
        ?int $now = null,
    ): array {
        [$header, $payload, $signingInput, $signature] = self::decodeJws($jws);

        $kid = $header['kid'] ?? null;
        if ($kid === null) {
            throw new MandateVerificationException('JWS header carries no kid.');
        }
        if (!isset($keys[$kid])) {
            throw new MandateVerificationException(
                "No public key for kid {$kid}. Refusing a mandate signed by an unknown issuer."
            );
        }

        if (!self::verifySignature((string) ($header['alg'] ?? ''), $keys[$kid], $signingInput, $signature)) {
            throw new MandateVerificationException('Mandate signature does not verify.');
        }

        if (($payload['merchant_id'] ?? null) !== $merchantId) {
            throw new MandateVerificationException(sprintf(
                'Mandate is addressed to merchant %s, not %s.',
                $payload['merchant_id'] ?? '?',
                $merchantId,
            ));
        }

        $expiresAt = strtotime((string) ($payload['expires_at'] ?? ''));
        if ($expiresAt === false) {
            throw new MandateVerificationException(
                'Mandate expires_at is not a date: ' . ($payload['expires_at'] ?? 'null')
            );
        }
        if ($expiresAt + $toleranceSeconds < ($now ?? time())) {
            throw new MandateVerificationException("Mandate expired at {$payload['expires_at']}.");
        }

        self::assertTotalMatchesItems($payload);
        return $payload;
    }

    /**
     * Does the signed total agree with the signed lines?
     *
     * @param array<string, mixed> $mandate
     */
    public static function assertTotalMatchesItems(array $mandate): void
    {
        $sum = '0';
        foreach ($mandate['items'] ?? [] as $item) {
            $sum = bcadd($sum, bcmul((string) $item['price'], (string) $item['quantity']));
        }
        if (bccomp($sum, (string) $mandate['total']) !== 0) {
            throw new MandateVerificationException(sprintf(
                'Cart total %s does not match its lines (%s). Refusing the cart.',
                $mandate['total'],
                $sum,
            ));
        }
    }

    /**
     * Is this cart within the standing permission the user gave?
     *
     * A Cart Mandate proves the *agent* signed a cart. Only the Intent Mandate
     * proves the *user* allowed that kind of purchase at all.
     *
     * @param array<string, mixed> $cart
     * @param array<string, mixed> $intent
     */
    public static function assertWithinIntent(array $cart, array $intent, ?int $now = null): void
    {
        $expiresAt = strtotime((string) ($intent['expires_at'] ?? ''));
        if ($expiresAt === false || $expiresAt < ($now ?? time())) {
            throw new MandateVerificationException(sprintf(
                'Intent mandate %s expired at %s.',
                $intent['id'] ?? '?',
                $intent['expires_at'] ?? '?',
            ));
        }

        $constraints = $intent['constraints'] ?? [];
        $currency = $constraints['currency'] ?? null;
        if ($currency !== null && $currency !== ($cart['currency'] ?? null)) {
            throw new MandateVerificationException(
                "Intent covers {$currency}; the cart is in " . ($cart['currency'] ?? '?') . '.'
            );
        }

        $maxAmount = $constraints['max_amount'] ?? null;
        if ($maxAmount !== null && bccomp((string) $cart['total'], (string) $maxAmount) > 0) {
            throw new MandateVerificationException(
                "Cart total {$cart['total']} exceeds the {$maxAmount} the user authorised."
            );
        }

        $merchants = $constraints['merchants'] ?? null;
        if (is_array($merchants) && $merchants !== [] && !in_array($cart['merchant_id'] ?? '', $merchants, true)) {
            throw new MandateVerificationException(sprintf(
                'Merchant %s is not among those the user authorised.',
                $cart['merchant_id'] ?? '?',
            ));
        }
    }

    /**
     * Turn a verified cart into parameters for `Client::createPayment()`.
     *
     * The cart id becomes `txid`, which is what makes the payment idempotent:
     * an agent that retries after a timeout gets the original transaction back
     * instead of a second charge.
     *
     * @param array<string, mixed> $cart
     * @return array<string, mixed>
     */
    public static function cartMandateToPayment(
        array $cart,
        string $env,
        ?string $returnUrl = null,
        ?string $failUrl = null,
        string $modality = 'human_present',
        ?string $jws = null,
    ): array {
        $paymentMandate = [
            'id' => 'pm_' . $cart['id'],
            'cart_mandate_id' => $cart['id'],
            'amount' => $cart['total'],
            'currency' => $cart['currency'],
            'merchant_id' => $cart['merchant_id'],
            'modality' => $modality,
            'created_at' => gmdate('c'),
        ];

        $params = [
            'type' => 'payment',
            'amount' => $cart['total'],
            'currency' => $cart['currency'],
            'txid' => $cart['id'],
            'env' => $env,
            'hosted' => true,
            'description' => self::describe($cart),
            'merchant_meta' => [
                'ap2' => [
                    'cart_mandate_id' => $cart['id'],
                    'intent_mandate_id' => $cart['intent_mandate_id'] ?? null,
                    'user_id' => $cart['user_id'] ?? null,
                    'agent_id' => $cart['agent_id'] ?? null,
                    'payment_mandate' => $paymentMandate,
                    // The hash, not the mandate: a JWS runs to kilobytes, and
                    // merchant_meta rides on every read and every webhook.
                    'cart_mandate_sha256' => $jws !== null ? hash('sha256', $jws) : null,
                ],
            ],
        ];

        if ($returnUrl !== null) {
            $params['returnUrl'] = $returnUrl;
        }
        if ($failUrl !== null) {
            $params['failUrl'] = $failUrl;
        }
        return $params;
    }

    /** @param array<string, mixed> $cart */
    private static function describe(array $cart): string
    {
        $items = $cart['items'] ?? [];
        if ($items === []) {
            return "AP2 cart {$cart['id']}";
        }
        if (count($items) === 1) {
            return "{$items[0]['name']} x{$items[0]['quantity']}";
        }
        return $items[0]['name'] . ' and ' . (count($items) - 1) . ' more';
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: string, 3: string} */
    private static function decodeJws(string $jws): array
    {
        $parts = explode('.', $jws);
        if (count($parts) !== 3) {
            throw new MandateVerificationException('Not a compact JWS: expected three dot-separated parts.');
        }

        $header = json_decode(self::base64UrlDecode($parts[0]), true);
        $payload = json_decode(self::base64UrlDecode($parts[1]), true);
        if (!is_array($header) || !is_array($payload)) {
            throw new MandateVerificationException('JWS header or payload is not JSON.');
        }

        return [$header, $payload, "{$parts[0]}.{$parts[1]}", self::base64UrlDecode($parts[2])];
    }

    /**
     * Verify one of the algorithms AP2 issuers actually use.
     *
     * `none` and the HMAC family are refused outright: a mandate is a bearer
     * authorisation from a third party, and a symmetric key would mean the
     * merchant could mint the user's permissions itself.
     */
    private static function verifySignature(
        string $alg,
        string $publicKeyPem,
        string $signingInput,
        string $signature,
    ): bool {
        $algorithms = [
            'ES256' => OPENSSL_ALGO_SHA256,
            'ES384' => OPENSSL_ALGO_SHA384,
            'RS256' => OPENSSL_ALGO_SHA256,
        ];

        if ($alg === 'EdDSA') {
            throw new MandateVerificationException(
                'EdDSA mandates need sodium key material rather than an OpenSSL PEM; '
                . 'use an ES256 or RS256 issuer key with this SDK.'
            );
        }
        if (!isset($algorithms[$alg])) {
            throw new MandateVerificationException(
                "Refusing algorithm {$alg}. Mandates must be signed with an asymmetric key "
                . '(ES256, ES384, RS256).'
            );
        }

        // JWS carries an ECDSA signature as raw r‖s; OpenSSL expects DER.
        $der = str_starts_with($alg, 'ES') ? self::rawToDer($signature) : $signature;

        $key = openssl_pkey_get_public($publicKeyPem);
        if ($key === false) {
            throw new MandateVerificationException('Issuer public key is not a valid PEM.');
        }
        return openssl_verify($signingInput, $der, $key, $algorithms[$alg]) === 1;
    }

    /** Raw r‖s to a DER SEQUENCE of two INTEGERs. */
    private static function rawToDer(string $signature): string
    {
        $half = intdiv(strlen($signature), 2);
        $encodeInteger = static function (string $value): string {
            $value = ltrim($value, "\x00");
            if ($value === '') {
                $value = "\x00";
            }
            if (ord($value[0]) > 0x7F) {
                $value = "\x00" . $value;
            }
            return "\x02" . chr(strlen($value)) . $value;
        };

        $sequence = $encodeInteger(substr($signature, 0, $half)) . $encodeInteger(substr($signature, $half));
        return "\x30" . chr(strlen($sequence)) . $sequence;
    }

    private static function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        if ($decoded === false) {
            throw new MandateVerificationException('JWS segment is not valid base64url.');
        }
        return $decoded;
    }
}
