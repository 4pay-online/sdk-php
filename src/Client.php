<?php

declare(strict_types=1);

namespace FourPay;

use FourPay\Exception\ApiException;
use FourPay\Exception\AuthException;
use FourPay\Exception\ConnectionException;
use FourPay\Exception\PaymentUrlUnavailableException;
use FourPay\Exception\RateLimitException;
use FourPay\Exception\TimeoutException;
use FourPay\Exception\TransactionRejectedException;
use FourPay\Exception\ValidationException;

/**
 * Client for the partner-facing API.
 *
 * ```php
 * $pay = new Client(
 *     apiKey: getenv('FOURPAY_API_KEY'),
 *     organizationId: getenv('FOURPAY_ORGANIZATION_ID'),
 *     baseUrl: 'https://sandbox.4pay.online',
 * );
 *
 * [$transaction, $url] = $pay->createHostedPayment([
 *     'amount' => '10.00', 'currency' => 'USD', 'env' => 'test', 'txid' => 'order-1',
 * ]);
 * ```
 */
class Client
{
    public const VERSION = '0.1.0';

    /** Statuses after which nothing more happens on its own. */
    public const FINAL_STATUSES = ['charged', 'rejected', 'failed', 'reversed'];

    private string $baseUrl;
    private string $userAgent;

    /** @var callable|null Injected in tests: fn(array $request): array{status:int, body:string, headers:array} */
    private $transport;

    public function __construct(
        private readonly ?string $apiKey = null,
        private readonly ?string $organizationId = null,
        private ?string $bearerToken = null,
        string $baseUrl = 'https://4pay.online',
        private readonly float $timeout = 30.0,
        private readonly int $maxRetries = 2,
        ?string $userAgent = null,
        ?callable $transport = null,
    ) {
        if ($apiKey === null && $bearerToken === null) {
            throw new \InvalidArgumentException(
                'Pass an apiKey (with its organizationId) or a bearerToken — there is no anonymous access.'
            );
        }
        if ($apiKey !== null && $organizationId === null) {
            throw new \InvalidArgumentException(
                'An API key without organizationId is not a credential: the platform resolves the key '
                . 'inside the organization named by the x-organization-id header, and refuses the '
                . 'request before reading the key. Your operator issues both values together.'
            );
        }

        $this->baseUrl = rtrim($baseUrl, '/');
        $this->userAgent = '4pay-sdk-php/' . self::VERSION . ($userAgent !== null ? " {$userAgent}" : '');
        $this->transport = $transport;
    }

    /** Is this the end of the road? An unknown status counts as non-final — the safe answer. */
    public static function isFinalStatus(string $status): bool
    {
        return in_array($status, self::FINAL_STATUSES, true);
    }

    /** Did the money actually move? Only `charged` means yes. */
    public static function isSettled(string $status): bool
    {
        return $status === 'charged';
    }

    // ------------------------------------------------------------- payments

    /**
     * Create a payment and return `[transaction, paymentUrl]`.
     *
     * Sets `hosted` for you. Left to itself the platform has no hosted default:
     * a create without it is read as server-to-server, finds no card, and comes
     * back `201 Created` with `status: "rejected"`.
     *
     * The URL carries a JWT that expires in about 15 minutes. Redirect
     * promptly; do not store it.
     *
     * @param array<string, mixed> $params
     * @return array{0: array<string, mixed>, 1: string}
     */
    public function createHostedPayment(array $params): array
    {
        $params['hosted'] = true;
        $transaction = $this->createPayment($params);

        $url = $transaction['widget_url'] ?? null;
        if (!is_string($url) || $url === '') {
            throw new PaymentUrlUnavailableException($transaction);
        }
        return [$transaction, $url];
    }

    /**
     * Create a payment.
     *
     * `amount` may be minor units (`"1000"`) or a decimal string in major units
     * (`"10.00"`). Throws {@see TransactionRejectedException} when the platform
     * refuses the payment, because it says so with `201 Created`.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function createPayment(array $params): array
    {
        $params['type'] ??= 'payment';
        $this->assertEnvironment($params['env'] ?? null);
        $this->assertRedirectUrls($params);

        $transaction = $this->request('POST', '/api/v1/transactions', [
            'body' => ['params' => $this->normalizeAmount($params)],
            'idempotent' => isset($params['txid']),
        ]);

        if (in_array($transaction['status'] ?? '', ['rejected', 'failed'], true)) {
            throw new TransactionRejectedException($transaction);
        }
        return $transaction;
    }

    /**
     * Create a payout. Same rules as {@see createPayment}; there is no hosted page.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function createPayout(array $params): array
    {
        $params['type'] = 'payout';
        $this->assertEnvironment($params['env'] ?? null);

        $transaction = $this->request('POST', '/api/v1/transactions', [
            'body' => ['params' => $this->normalizeAmount($params)],
            'idempotent' => isset($params['txid']),
        ]);

        if (in_array($transaction['status'] ?? '', ['rejected', 'failed'], true)) {
            throw new TransactionRejectedException($transaction);
        }
        return $transaction;
    }

    /**
     * Read one transaction by its platform id (a UUID, not your `txid`).
     *
     * @return array<string, mixed>
     */
    public function getTransaction(string $id): array
    {
        return $this->request('GET', '/api/v1/transactions/' . rawurlencode($id));
    }

    /**
     * One page of transactions, plus the cursor that opens the next one.
     *
     * Only the documented filters exist — `page`, `offset`, `from`, `to` and
     * `currency` are refused with `422`. Paging is `cursor`; `start_cursor` is
     * accepted and then ignored.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function listTransactions(array $params = []): array
    {
        return $this->request('GET', '/api/v1/transactions', ['query' => $params]);
    }

    /**
     * Walk every transaction matching the filter, following the cursor.
     *
     * Stops on an empty page or a cursor that stops moving — a repeating cursor
     * means the filter was silently dropped, and looping on it would never end.
     *
     * @param array<string, mixed> $params
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterateTransactions(array $params = []): \Generator
    {
        $cursor = $params['cursor'] ?? null;
        unset($params['cursor']);
        $previous = null;

        while (true) {
            $page = $this->listTransactions($cursor !== null ? $params + ['cursor' => $cursor] : $params);
            $rows = $page['data'] ?? [];
            if ($rows === []) {
                return;
            }
            foreach ($rows as $transaction) {
                yield $transaction;
            }

            $next = $page['cursor']['cursor_value'] ?? null;
            if ($next === null || $next === $previous || $next === $cursor) {
                return;
            }
            $previous = $cursor;
            $cursor = $next;
        }
    }

    /**
     * Summed totals for the filter, in the platform's own `{"aggr": [...]}` shape.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function aggregate(array $params = []): array
    {
        return $this->request('GET', '/api/v1/transactions/aggregate', ['query' => $params]);
    }

    /**
     * Refund a settled payment. Omit `$amount` for the full sum.
     *
     * @return array<string, mixed>
     */
    public function refund(string $id, string|int|null $amount = null, ?string $currency = null): array
    {
        $params = ['type' => 'refund'];
        if ($amount !== null) {
            $params['amount'] = $currency !== null
                ? Money::toMinorUnits($amount, $currency)
                : (string) $amount;
        }
        return $this->request('PUT', '/api/v1/transactions/' . rawurlencode($id), [
            'body' => ['params' => $params],
        ]);
    }

    /**
     * Cancel a transaction that has not settled. It ends `failed`, never `reversed`.
     *
     * @return array<string, mixed>
     */
    public function cancel(string $id): array
    {
        return $this->request('PUT', '/api/v1/transactions/' . rawurlencode($id), [
            'body' => ['params' => ['type' => 'cancel']],
        ]);
    }

    /**
     * Capture a two-phase authorization.
     *
     * @return array<string, mixed>
     */
    public function commit(string $id): array
    {
        return $this->request('POST', '/api/v1/transactions/commit/' . rawurlencode($id));
    }

    /**
     * Submit what the payer produced for `action_required` — an OTP, a 3DS result.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function submitAuthParams(string $id, array $params): array
    {
        return $this->request(
            'POST',
            '/api/v1/transactions/additional_params_for_auth/' . rawurlencode($id),
            ['body' => ['params' => $params]],
        );
    }

    /**
     * Poll until the transaction reaches a final status.
     *
     * A convenience for scripts and CLI tools. A web integration should take
     * the webhook instead — polling costs a request per interval and still lags
     * the event, and PHP request timeouts are shorter than most payments.
     *
     * @return array<string, mixed>
     */
    public function waitForCompletion(string $id, float $timeout = 180.0, float $interval = 3.0): array
    {
        $deadline = microtime(true) + $timeout;

        while (true) {
            $transaction = $this->getTransaction($id);
            if (self::isFinalStatus((string) ($transaction['status'] ?? ''))) {
                return $transaction;
            }
            if (microtime(true) + $interval > $deadline) {
                throw new TimeoutException(sprintf(
                    'Transaction %s was still %s after %ds',
                    $id,
                    $transaction['status'] ?? '?',
                    (int) $timeout,
                ), $transaction);
            }
            usleep((int) ($interval * 1_000_000));
        }
    }

    // --------------------------------------------------------------- system

    /**
     * Platform health. The readiness field is `core_status` — there is no `status`.
     *
     * @return array<string, mixed>
     */
    public function health(): array
    {
        return $this->request('GET', '/api/v1/health', ['auth' => false]);
    }

    /**
     * Exchange login and password for a session token.
     *
     * `expire_at` is Unix **seconds** and the field is singular — code written
     * against an `expires_at` ISO string reads `null` and treats the token as
     * immortal.
     *
     * @return array<string, mixed>
     */
    public function createSession(string $login, string $password, string $type = 'partner'): array
    {
        $session = $this->request('POST', '/api/v1/session', [
            'body' => ['type' => $type, 'login' => $login, 'password' => $password],
            'auth' => false,
        ]);
        $this->bearerToken = $session['token'] ?? null;
        return $session;
    }

    // ------------------------------------------------------------- plumbing

    /**
     * Escape hatch for endpoints the SDK does not wrap yet.
     *
     * @param array{body?: mixed, query?: array<string, mixed>, auth?: bool, idempotent?: bool} $options
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, array $options = []): array
    {
        $url = $this->baseUrl . $path;
        $query = array_filter(
            $options['query'] ?? [],
            static fn ($value) => $value !== null && $value !== '',
        );
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $headers = ['accept: application/json', 'user-agent: ' . $this->userAgent];
        if (isset($options['body'])) {
            $headers[] = 'content-type: application/json';
        }
        if (($options['auth'] ?? true) === true) {
            if ($this->apiKey !== null) {
                $headers[] = 'x-api-key: ' . $this->apiKey;
            }
            if ($this->bearerToken !== null) {
                $headers[] = 'authorization: Bearer ' . $this->bearerToken;
            }
            if ($this->organizationId !== null) {
                $headers[] = 'x-organization-id: ' . $this->organizationId;
            }
        }

        // A write is retried only when it carries an idempotency key. Repeating
        // a create without one after a timeout is how one order becomes two
        // charges — the platform cannot tell the retry from a new payment.
        $retryable = $method === 'GET' || ($options['idempotent'] ?? false) === true;
        $attempts = $retryable ? $this->maxRetries + 1 : 1;
        $body = isset($options['body']) ? json_encode($options['body'], JSON_THROW_ON_ERROR) : null;

        $lastError = null;
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            if ($attempt > 0) {
                usleep((int) ($this->backoffSeconds($attempt, $lastError) * 1_000_000));
            }

            $response = $this->send($method, $url, $headers, $body);
            if ($response === null) {
                $lastError = new ConnectionException("{$method} {$path} failed: transport error");
                if ($attempt === $attempts - 1) {
                    throw $lastError;
                }
                continue;
            }

            $decoded = $response['body'] === '' ? [] : json_decode($response['body'], true);
            if (!is_array($decoded)) {
                $decoded = ['errors' => [['detail' => substr($response['body'], 0, 500)]]];
            }

            if ($response['status'] < 400) {
                return $decoded;
            }

            $error = $this->errorFor($response['status'], $decoded, $response['headers']);
            if (!($error->isRetryable() && $attempt < $attempts - 1)) {
                throw $error;
            }
            $lastError = $error;
        }

        throw $lastError ?? new ConnectionException("{$method} {$path} failed");
    }

    /**
     * @param list<string> $headers
     * @return array{status: int, body: string, headers: array<string, string>}|null
     */
    private function send(string $method, string $url, array $headers, ?string $body): ?array
    {
        if ($this->transport !== null) {
            /** @var array{status: int, body: string, headers: array<string, string>}|null */
            return ($this->transport)([
                'method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body,
            ]);
        }

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => (int) ceil($this->timeout),
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }

        // No curl_close(): the handle is released when it goes out of scope, and
        // calling it has been deprecated since PHP 8.5 — it would emit a notice
        // on every single request.
        $raw = curl_exec($curl);
        if ($raw === false) {
            return null;
        }

        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);

        $responseHeaders = [];
        foreach (explode("\r\n", substr((string) $raw, 0, $headerSize)) as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);
            }
        }

        return ['status' => $status, 'body' => substr((string) $raw, $headerSize), 'headers' => $responseHeaders];
    }

    /**
     * Pick the class that matches the status, so `catch` clauses can discriminate.
     *
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    private function errorFor(int $status, array $body, array $headers): ApiException
    {
        $details = self::parseErrorBody($body);
        $requestId = $headers['x-request-id'] ?? null;

        if ($status === 429) {
            $retryAfter = $headers['retry-after'] ?? null;
            return new RateLimitException(
                $status,
                $details,
                $body,
                is_numeric($retryAfter) ? (int) $retryAfter : null,
                $requestId,
            );
        }
        if ($status === 401 || $status === 403) {
            return new AuthException($status, $details, $body, $requestId);
        }
        if ($status === 422) {
            return new ValidationException($status, $details, $body, $requestId);
        }
        // A bare 400 with no field pointers is what a missing x-organization-id
        // looks like. Treat it as the credential problem it is.
        if ($status === 400 && array_filter($details, static fn ($d) => isset($d['pointer'])) === []) {
            return new AuthException($status, $details, $body, $requestId);
        }
        return new ApiException($status, $details, $body, $requestId);
    }

    /**
     * Fold either error body shape into a flat list of details.
     *
     * @param array<string, mixed> $body
     * @return list<array{detail: string, code?: string, pointer?: string}>
     */
    public static function parseErrorBody(array $body): array
    {
        if (isset($body['errors']) && is_array($body['errors'])) {
            $out = [];
            foreach ($body['errors'] as $item) {
                if (is_string($item)) {
                    $out[] = ['detail' => $item];
                    continue;
                }
                if (!is_array($item)) {
                    continue;
                }
                $detail = ['detail' => (string) ($item['detail'] ?? $item['title'] ?? $item['message'] ?? 'unknown error')];
                if (isset($item['code'])) {
                    $detail['code'] = (string) $item['code'];
                }
                if (isset($item['source']['pointer'])) {
                    $detail['pointer'] = (string) $item['source']['pointer'];
                }
                $out[] = $detail;
            }
            return $out;
        }

        if (isset($body['error']) || isset($body['message'])) {
            $detail = ['detail' => (string) ($body['message'] ?? $body['error'])];
            if (is_string($body['error'] ?? null)) {
                $detail['code'] = $body['error'];
            }
            return [$detail];
        }

        return [];
    }

    private function assertEnvironment(mixed $env): void
    {
        if ($env !== 'prod' && $env !== 'test') {
            throw new \InvalidArgumentException(
                'env must be "prod" or "test" and must travel in the body. A "?env=test" query '
                . 'parameter only scopes searches: a create carrying just that produces a '
                . 'PRODUCTION transaction.'
            );
        }
    }

    /**
     * A server-to-server charge needs somewhere to send the payer afterwards.
     *
     * Without both URLs the platform answers `201 Created` with `rejected` and
     * `return_url/fail_url params not specified` — a real transaction, spent
     * `txid` and all. Hosted payments and payouts do not need them.
     *
     * @param array<string, mixed> $params
     */
    private function assertRedirectUrls(array $params): void
    {
        $hosted = ($params['hosted'] ?? false) === true || ($params['show_widget'] ?? false) === true;
        if (!$hosted && isset($params['money_storage'])) {
            if (empty($params['returnUrl']) || empty($params['failUrl'])) {
                throw new \InvalidArgumentException(
                    'A server-to-server payment needs both returnUrl and failUrl. Without them the '
                    . 'platform creates the transaction and immediately rejects it with '
                    . '"return_url/fail_url params not specified".'
                );
            }
        }
    }

    /**
     * Accept `"10.00"` alongside `"1000"`, converting with the currency's scale.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function normalizeAmount(array $params): array
    {
        $amount = $params['amount'] ?? null;
        $currency = (string) ($params['currency'] ?? '');

        if (is_string($amount) && str_contains($amount, '.')) {
            $params['amount'] = Money::toMinorUnits($amount, $currency);
        } elseif (is_int($amount)) {
            $params['amount'] = (string) $amount;
        }
        return $params;
    }

    /** Exponential backoff with jitter; a rate limit's own `Retry-After` wins. */
    private function backoffSeconds(int $attempt, ?\Throwable $lastError): float
    {
        if ($lastError instanceof RateLimitException && $lastError->retryAfter !== null) {
            return (float) $lastError->retryAfter;
        }
        return min(2 ** ($attempt - 1), 8) + random_int(0, 250) / 1000;
    }
}
