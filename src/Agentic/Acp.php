<?php

declare(strict_types=1);

namespace FourPay\Agentic;

use FourPay\Exception\FourPayException;
use FourPay\Exception\TransactionRejectedException;
use FourPay\Client;

/** An error in the shape ACP clients expect, with the status to answer with. */
class AcpException extends FourPayException
{
    /**
     * `$errorCode`, not `$code`: `Exception` already owns a mutable `$code`,
     * and redeclaring it readonly is a fatal error at load time.
     */
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $httpStatus = 400,
        public readonly string $type = 'invalid_request',
    ) {
        parent::__construct($message);
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return ['type' => $this->type, 'code' => $this->errorCode, 'message' => $this->getMessage()];
    }
}

/**
 * ACP — Agentic Commerce Protocol (merchant side).
 *
 * ACP is the shape of a checkout that happens inside someone else's chat. The
 * buyer never reaches your site: an agent reads your product feed, builds a
 * cart, and drives a checkout session against **your** endpoints:
 *
 * ```
 * POST /checkout_sessions               create — price it, quote tax and shipping
 * POST /checkout_sessions/:id           update — address or quantity changed
 * POST /checkout_sessions/:id/complete  pay
 * POST /checkout_sessions/:id/cancel    cancel
 * ```
 *
 * **Where the delegated token stands.** ACP's delegated-payments flow has the
 * agent hand you a single-use token minted by the buyer's payment provider.
 * 4pay.online does not accept third-party vault tokens today, so this bridge offers
 * the two honest options: `hosted` returns a payment URL for the agent to pass
 * back, and `saved_card` charges a card already stored in the platform.
 *
 * Sessions are held by a store you provide. The default keeps them in memory,
 * which under PHP-FPM means *for one request* — wire a real store (database,
 * Redis) before an agent ever calls `update` or `complete`.
 */
final class Acp
{
    /** @var array<string, array<string, mixed>> */
    private array $sessions = [];

    /**
     * @param callable(string, int): string $priceItem   catalogue price in minor units
     * @param callable(array): string|null  $calculateTax
     * @param callable(array): string|null  $calculateFulfillment
     * @param callable(): string|null       $generateId
     */
    public function __construct(
        private readonly Client $client,
        private readonly string $env,
        private readonly string $currency,
        private readonly \Closure $priceItem,
        private readonly ?\Closure $calculateTax = null,
        private readonly ?\Closure $calculateFulfillment = null,
        private readonly string $mode = 'hosted',
        private readonly ?string $returnUrl = null,
        private readonly ?string $failUrl = null,
        private readonly ?\Closure $generateId = null,
        private readonly ?AcpSessionStore $store = null,
    ) {
    }

    /**
     * `POST /checkout_sessions`
     *
     * @param list<array{id: string, quantity: int}> $items
     * @param array<string, mixed>|null $buyer
     * @param array<string, mixed>|null $fulfillmentAddress
     * @return array<string, mixed>
     */
    public function create(array $items, ?array $buyer = null, ?array $fulfillmentAddress = null): array
    {
        if ($items === []) {
            throw new AcpException('A checkout session needs at least one line item.', 'missing_items', 422);
        }

        $session = [
            'id' => $this->generateId !== null ? ($this->generateId)() : self::uuid(),
            'status' => 'not_ready_for_payment',
            'currency' => $this->currency,
            'line_items' => [],
            'totals' => [],
            'messages' => [],
            'links' => [],
        ];
        if ($buyer !== null) {
            $session['buyer'] = $buyer;
        }
        if ($fulfillmentAddress !== null) {
            $session['fulfillment_address'] = $fulfillmentAddress;
        }

        $this->price($session, $items);
        $this->put($session);
        return $session;
    }

    /**
     * `POST /checkout_sessions/:id` — repricing after the agent changes something.
     *
     * @param list<array{id: string, quantity: int}>|null $items
     * @param array<string, mixed>|null $buyer
     * @param array<string, mixed>|null $fulfillmentAddress
     * @return array<string, mixed>
     */
    public function update(
        string $sessionId,
        ?array $items = null,
        ?array $buyer = null,
        ?array $fulfillmentAddress = null,
    ): array {
        $session = $this->require($sessionId);
        if (in_array($session['status'], ['completed', 'canceled'], true)) {
            throw new AcpException(
                "Session {$sessionId} is {$session['status']}.",
                'session_not_updatable',
                409,
            );
        }

        if ($buyer !== null) {
            $session['buyer'] = array_merge($session['buyer'] ?? [], $buyer);
        }
        if ($fulfillmentAddress !== null) {
            $session['fulfillment_address'] = $fulfillmentAddress;
        }

        if ($items !== null) {
            $this->price($session, $items);
        } else {
            $this->recomputeTotals($session);
        }

        $this->put($session);
        return $session;
    }

    /**
     * `POST /checkout_sessions/:id/complete` — turn the session into a payment.
     *
     * The session id becomes the transaction's `txid`, so an agent that retries
     * a timed-out complete gets the same payment back.
     *
     * @param array<string, mixed> $paymentData
     * @return array<string, mixed>
     */
    public function complete(string $sessionId, array $paymentData = []): array
    {
        $session = $this->require($sessionId);

        if ($session['status'] === 'completed') {
            return $session; // idempotent by design
        }
        if ($session['status'] === 'canceled') {
            throw new AcpException("Session {$sessionId} was canceled.", 'session_canceled', 409);
        }
        if ($session['status'] !== 'ready_for_payment') {
            throw new AcpException(
                "Session {$sessionId} is {$session['status']} — price and address must settle first.",
                'session_not_ready',
                409,
            );
        }

        $total = self::totalOf($session);
        $session['status'] = 'in_progress';
        $this->put($session);

        try {
            $transaction = $this->charge($session, $total, $paymentData);
        } catch (\Throwable $error) {
            $session['status'] = 'ready_for_payment';
            $session['messages'][] = [
                'type' => 'error',
                'content_type' => 'plain',
                'content' => $error instanceof TransactionRejectedException
                    ? 'Payment refused: ' . $error->reason()
                    : 'Payment could not be created: ' . $error->getMessage(),
            ];
            $this->put($session);
            throw $error;
        }

        $session['order'] = ['id' => $transaction['id'], 'checkout_session_id' => $sessionId];
        $url = $transaction['widget_url'] ?? null;

        if (is_string($url) && $url !== '') {
            $session['order']['permalink_url'] = $url;
            $session['links'] = [['type' => 'payment', 'url' => $url]];
            $session['messages'][] = [
                'type' => 'info',
                'content_type' => 'plain',
                'content' => 'Open the payment link to finish paying.',
            ];
            // Not `completed`: the buyer has not paid yet, and saying otherwise
            // is how an agent tells someone their order is placed when it isn't.
            $session['status'] = 'in_progress';
        } else {
            $session['status'] = ($transaction['status'] ?? '') === 'charged' ? 'completed' : 'in_progress';
        }

        $this->put($session);
        return $session;
    }

    /**
     * `POST /checkout_sessions/:id/cancel`
     *
     * @return array<string, mixed>
     */
    public function cancel(string $sessionId): array
    {
        $session = $this->require($sessionId);
        if ($session['status'] === 'completed') {
            throw new AcpException("Session {$sessionId} is already completed.", 'session_completed', 409);
        }
        $session['status'] = 'canceled';
        $this->put($session);
        return $session;
    }

    /**
     * `GET /checkout_sessions/:id`
     *
     * @return array<string, mixed>
     */
    public function get(string $sessionId): array
    {
        return $this->require($sessionId);
    }

    // ------------------------------------------------------------ internals

    /**
     * @param array<string, mixed> $session
     * @param array<string, mixed> $paymentData
     * @return array<string, mixed>
     */
    private function charge(array $session, string $total, array $paymentData): array
    {
        $base = [
            'amount' => $total,
            'currency' => $session['currency'],
            'txid' => $session['id'],
            'env' => $this->env,
            'description' => self::describe($session),
            'merchant_meta' => [
                'acp' => [
                    'checkout_session_id' => $session['id'],
                    'line_items' => array_map(
                        static fn (array $line) => ['item_id' => $line['item_id'], 'quantity' => $line['quantity']],
                        $session['line_items'],
                    ),
                    'payment_provider' => $paymentData['provider'] ?? null,
                ],
            ],
        ];
        if (isset($session['buyer']['email'])) {
            $base['customer'] = ['email' => $session['buyer']['email']];
        }

        if ($this->mode === 'saved_card') {
            if (empty($paymentData['token'])) {
                throw new AcpException(
                    'saved_card mode needs the token of a card already stored in the platform.',
                    'missing_payment_token',
                    422,
                );
            }
            return $this->client->createPayment($base + ['money_storage' => ['token' => $paymentData['token']]]);
        }

        // createPayment rather than createHostedPayment: a terminal set to
        // charge instantly settles without ever issuing a page.
        return $this->client->createPayment($base + [
            'hosted' => true,
            'returnUrl' => $this->returnUrl,
            'failUrl' => $this->failUrl,
        ]);
    }

    /**
     * @param array<string, mixed> $session
     * @param list<array{id: string, quantity: int}> $items
     */
    private function price(array &$session, array $items): void
    {
        $lines = [];
        foreach ($items as $item) {
            if (!is_int($item['quantity']) || $item['quantity'] < 1) {
                throw new AcpException(
                    "Quantity for {$item['id']} must be a positive integer.",
                    'invalid_quantity',
                    422,
                );
            }
            $lines[] = [
                'id' => 'li_' . $item['id'],
                'item_id' => $item['id'],
                'quantity' => $item['quantity'],
                'base_amount' => (string) ($this->priceItem)($item['id'], $item['quantity']),
            ];
        }

        $session['line_items'] = $lines;
        $this->recomputeTotals($session);
    }

    /** @param array<string, mixed> $session */
    private function recomputeTotals(array &$session): void
    {
        $items = '0';
        foreach ($session['line_items'] as $line) {
            $items = bcadd($items, (string) $line['base_amount']);
        }
        $tax = $this->calculateTax !== null ? (string) ($this->calculateTax)($session) : '0';
        $fulfillment = $this->calculateFulfillment !== null
            ? (string) ($this->calculateFulfillment)($session)
            : '0';

        $totals = [['type' => 'items_base_amount', 'display_text' => 'Items', 'amount' => $items]];
        if (bccomp($tax, '0') > 0) {
            $totals[] = ['type' => 'tax', 'display_text' => 'Tax', 'amount' => $tax];
        }
        if (bccomp($fulfillment, '0') > 0) {
            $totals[] = ['type' => 'fulfillment', 'display_text' => 'Shipping', 'amount' => $fulfillment];
        }
        $totals[] = [
            'type' => 'total',
            'display_text' => 'Total',
            'amount' => bcadd(bcadd($items, $tax), $fulfillment),
        ];

        $session['totals'] = $totals;
        if (in_array($session['status'], ['not_ready_for_payment', 'ready_for_payment'], true)) {
            $session['status'] = $this->isReady($session) ? 'ready_for_payment' : 'not_ready_for_payment';
        }
    }

    /**
     * Ready means priced and, when shipping is charged, addressed.
     *
     * @param array<string, mixed> $session
     */
    private function isReady(array $session): bool
    {
        if ($session['line_items'] === []) {
            return false;
        }
        if ($this->calculateFulfillment !== null && !isset($session['fulfillment_address'])) {
            return false;
        }
        return bccomp(self::totalOf($session), '0') > 0;
    }

    /** @return array<string, mixed> */
    private function require(string $sessionId): array
    {
        $session = $this->store !== null ? $this->store->get($sessionId) : ($this->sessions[$sessionId] ?? null);
        if ($session === null) {
            throw new AcpException("No checkout session {$sessionId}.", 'session_not_found', 404);
        }
        return $session;
    }

    /** @param array<string, mixed> $session */
    private function put(array $session): void
    {
        if ($this->store !== null) {
            $this->store->set($session['id'], $session);
            return;
        }
        $this->sessions[$session['id']] = $session;
    }

    /** @param array<string, mixed> $session */
    private static function totalOf(array $session): string
    {
        foreach ($session['totals'] as $total) {
            if ($total['type'] === 'total') {
                return (string) $total['amount'];
            }
        }
        return '0';
    }

    /** @param array<string, mixed> $session */
    private static function describe(array $session): string
    {
        $count = array_sum(array_column($session['line_items'], 'quantity'));
        return "ACP checkout {$session['id']} ({$count} item" . ($count === 1 ? '' : 's') . ')';
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);   // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);   // variant 1
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
