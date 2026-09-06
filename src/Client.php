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
 *
 * Every call has to say which organization it acts in, and there are two ways to
 * say it. Either pass `organizationId` — the client then sends it as
 * `x-organization-id` on every call — or set `organizationFromPerimeter` when
 * your own proxy pins that header for you, and the client adds nothing. An API
 * key always takes the first way: it is issued together with its
 * `organizationId`.
 *
 * No credential yet, only a login and a password? {@see Client::forLogin()}.
 */
class Client
{
    public const VERSION = '0.1.0';

    /** The shared host, which serves every organization and names none of them. */
    public const DEFAULT_BASE_URL = 'https://4pay.online';

    /** Statuses after which nothing more happens on its own. */
    public const FINAL_STATUSES = ['charged', 'rejected', 'failed', 'reversed'];

    private readonly ?string $apiKey;
    private readonly ?string $organizationId;
    private ?string $bearerToken;
    private string $baseUrl;
    private string $userAgent;
    private readonly bool $organizationFromPerimeter;

    /**
     * Set only inside {@see forLogin()} and read by the constructor.
     *
     * A private static flag, and not a constructor parameter, because a
     * parameter would be part of the public signature: anyone reading it would
     * find a documented way past the credential check. PHP runs one request in
     * one process, so the flag cannot be seen half-set by anybody else.
     */
    private static bool $buildingLoginClient = false;

    /** @var callable|null Injected in tests: fn(array $request): array{status:int, body:string, headers:array} */
    private $transport;

    public function __construct(
        ?string $apiKey = null,
        ?string $organizationId = null,
        ?string $bearerToken = null,
        string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly float $timeout = 30.0,
        private readonly int $maxRetries = 2,
        ?string $userAgent = null,
        ?callable $transport = null,
        bool $organizationFromPerimeter = false,
    ) {
        $apiKey = self::trimmed($apiKey);
        $organizationId = self::trimmed($organizationId);
        $bearerToken = self::trimmed($bearerToken);
        $baseUrl = rtrim(self::trimmed($baseUrl) ?? self::DEFAULT_BASE_URL, '/');

        // Whether the proxy in front of $baseUrl pins x-organization-id is a
        // statement about the caller's deployment, not something the SDK can
        // see — so the caller makes it and the SDK does not guess.
        //
        // It used to guess, by comparing the host against the one default, and
        // the guess was wrong for every 4pay host but that one: with $baseUrl on
        // the sandbox, a session token and no organizationId, the client was
        // built naming no organization and every call it made landed in none.
        $fromPerimeter = $organizationFromPerimeter;
        $loginOnly = self::$buildingLoginClient;

        if ($apiKey === null && $bearerToken === null && !$loginOnly) {
            throw new \InvalidArgumentException(
                'Pass an apiKey or a bearerToken — there is no anonymous access. Arriving with a '
                . 'login and a password instead? Client::forLogin() builds the client that has no '
                . 'credential yet, and its one purpose is to call createSession().'
            );
        }
        if ($apiKey !== null && $organizationId === null) {
            throw new \InvalidArgumentException(
                'An API key without organizationId is not a credential: the platform resolves the key '
                . 'inside the organization named by the x-organization-id header, and refuses the '
                . 'request before reading the key. Your operator issues both values together. The '
                . 'other way to name an organization — organizationFromPerimeter, where your own '
                . 'proxy pins that header — belongs to session tokens; this SDK does not take it in '
                . 'place of organizationId for a key.'
            );
        }
        // The claim cannot be true on a host we run: none of ours pins the
        // header, and a client built on the claim would send no organization at
        // all. Better caught here than as a 400 from the first call, which reads
        // as our fault.
        if ($fromPerimeter && self::isSharedHost(self::hostOf($baseUrl))) {
            throw new \InvalidArgumentException(
                "organizationFromPerimeter says a proxy in front of {$baseUrl} pins "
                . 'x-organization-id for one organization, but that host is ours: it serves every '
                . 'organization and pins nothing. The option is for a domain of your own. On our '
                . 'hosts, pass organizationId.'
            );
        }
        if ($organizationId === null && !$fromPerimeter && !$loginOnly) {
            throw new \InvalidArgumentException(
                'A session token still has to say which organization it acts in, and there are two '
                . 'ways to say it: pass organizationId, or set organizationFromPerimeter when your '
                . 'own proxy pins x-organization-id for you. Neither is set, so the call would '
                . 'reach no organization at all.'
            );
        }

        $this->apiKey = $apiKey;
        $this->organizationId = $organizationId;
        $this->bearerToken = $bearerToken;
        $this->baseUrl = $baseUrl;
        $this->userAgent = '4pay-sdk-php/' . self::VERSION . ($userAgent !== null ? " {$userAgent}" : '');
        $this->transport = $transport;
        $this->organizationFromPerimeter = $fromPerimeter;
    }

    /**
     * A client with no credential yet, whose one purpose is to log in.
     *
     * ```php
     * $pay = Client::forLogin(baseUrl: 'https://sandbox.4pay.online');
     * $session = $pay->createSession('admin@example.com', 'secret', 'admin');
     * // from here the client carries $session['token'] like any other
     * ```
     *
     * The constructor insists on a credential, and login is exactly where you do
     * not have one. `$organizationId` is optional here, and only here: admins and
     * clients live in the platform's own schema and are found without it, and a
     * partner is searched for across organizations. A `person` is not — see
     * {@see createSession()}.
     *
     * Anything other than {@see createSession()} and {@see health()} throws until
     * the session token arrives; an unauthenticated call would only come back 401.
     */
    public static function forLogin(
        ?string $organizationId = null,
        string $baseUrl = self::DEFAULT_BASE_URL,
        float $timeout = 30.0,
        int $maxRetries = 2,
        ?string $userAgent = null,
        ?callable $transport = null,
        bool $organizationFromPerimeter = false,
    ): self {
        self::$buildingLoginClient = true;

        try {
            return new self(
                organizationId: $organizationId,
                baseUrl: $baseUrl,
                timeout: $timeout,
                maxRetries: $maxRetries,
                userAgent: $userAgent,
                transport: $transport,
                organizationFromPerimeter: $organizationFromPerimeter,
            );
        } finally {
            // Cleared even when the constructor throws: a flag left standing
            // would let the NEXT ordinary client skip the credential check.
            self::$buildingLoginClient = false;
        }
    }

    /**
     * The host of a base URL — no scheme, no userinfo, no port, no path.
     *
     * "Is this a domain of my own?" is a question about the host, and only the
     * host answers it. A URL that carries the same host with a path, a port or
     * credentials in front of it is the same host.
     */
    private static function hostOf(string $url): string
    {
        $host = parse_url(str_contains($url, '//') ? $url : '//' . $url, PHP_URL_HOST);
        return strtolower((string) $host);
    }

    /**
     * Hosts we run ourselves: the apex and everything under it.
     *
     * Each serves every organization and names none — the sandbox, the API host
     * and the payment host included. The check exists to refuse a claim that
     * cannot be true: `organizationFromPerimeter` says a proxy in front of this
     * host pins `x-organization-id` for one organization, and in front of ours
     * nothing pins it.
     */
    private static function isSharedHost(string $host): bool
    {
        return $host === '4pay.online' || str_ends_with($host, '.4pay.online');
    }

    /**
     * Blank is absent.
     *
     * A string of spaces is neither a credential nor an organization. Accepting
     * one would make the constructor's checks a formality and move the failure to
     * the first HTTP call, where a typo in an environment variable reads as a
     * platform fault.
     */
    private static function trimmed(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
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
     * Reachable without a credential through {@see forLogin()}; on a client that
     * already holds one it simply replaces the session token.
     *
     * `expire_at` is Unix **seconds** and the field is singular — code written
     * against an `expires_at` ISO string reads `null` and treats the token as
     * immortal.
     *
     * A `person` is looked up inside one organization's own schema, so that login
     * has to name an organization. `admin`, `client` and `partner` do not: the
     * first two live in the platform's own schema, and a partner is searched for
     * across organizations.
     *
     * @return array<string, mixed>
     */
    public function createSession(string $login, string $password, string $type = 'partner'): array
    {
        if ($type === 'person' && $this->organizationId === null && !$this->organizationFromPerimeter) {
            throw new \InvalidArgumentException(
                'A person login has to name an organization: the platform looks the person up in '
                . "that organization's own schema, and with nowhere to look it answers a flat 401 "
                . 'that reads like a wrong password. Pass an organizationId, or set '
                . 'organizationFromPerimeter when your own proxy pins x-organization-id for you.'
            );
        }

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
        if (($options['auth'] ?? true) === true && $this->apiKey === null && $this->bearerToken === null) {
            throw new \InvalidArgumentException(
                'This client has no credential yet: Client::forLogin() builds one only to call '
                . 'createSession(). Call that first — the session token it returns stays on the '
                . 'client — or build the client with an apiKey or a bearerToken.'
            );
        }

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
        }
        // Outside the auth branch on purpose: this header is not a credential, it
        // says which organization the call is about, and the unauthenticated calls
        // need it too. createSession() is the one that matters — a person login is
        // looked up in that organization's own schema, and without the header the
        // platform has nowhere to look. Absent means the client runs on its own
        // domain, where the perimeter supplies it.
        if ($this->organizationId !== null) {
            $headers[] = 'x-organization-id: ' . $this->organizationId;
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
