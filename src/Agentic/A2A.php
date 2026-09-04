<?php

declare(strict_types=1);

namespace FourPay\Agentic;

use FourPay\Exception\TransactionRejectedException;
use FourPay\Money;
use FourPay\Client;

/**
 * A2A — Agent2Agent (payment agent).
 *
 * A2A is how one agent hires another. Your payment agent publishes an **agent
 * card** at `/.well-known/agent-card.json` saying what it can do; a client
 * agent sends a message and gets back a **task** it polls until `completed`.
 *
 * A2A carries no money itself — it moves tasks and artifacts. What this class
 * does is expose the platform's payment operations as A2A skills.
 *
 * Tasks are held in memory, which under PHP-FPM means *for one request*. A2A
 * clients are entitled to poll a task id afterwards, so a deployment that
 * answers `tasks/get` needs them in storage that outlives the process.
 */
final class A2A
{
    public const PROTOCOL_VERSION = '0.3.0';

    /** @var array<string, array<string, mixed>> */
    private array $tasks = [];

    public function __construct(
        private readonly Client $client,
        private readonly string $env,
        private readonly string $url,
        private readonly ?string $currency = null,
        private readonly ?string $returnUrl = null,
        private readonly ?string $failUrl = null,
        /**
         * Cap on a single payment, in minor units.
         *
         * An agent endpoint is reachable by whoever holds its URL. Without a
         * ceiling, one malformed request from a peer agent is an unbounded charge.
         */
        private readonly ?string $maxAmount = null,
        private readonly ?string $name = null,
        private readonly ?string $description = null,
    ) {
    }

    /**
     * The card a client agent fetches from `/.well-known/agent-card.json`.
     *
     * @return array<string, mixed>
     */
    public function agentCard(): array
    {
        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'name' => $this->name ?? '4pay.online Payments Agent',
            'description' => $this->description
                ?? 'Creates payments, reports their status and issues refunds on the 4pay.online platform.',
            'url' => $this->url,
            'version' => '0.1.0',
            'capabilities' => ['streaming' => false, 'pushNotifications' => false],
            'defaultInputModes' => ['text/plain', 'application/json'],
            'defaultOutputModes' => ['text/plain', 'application/json'],
            'skills' => [
                [
                    'id' => 'create_payment',
                    'name' => 'Create a payment',
                    'description' => 'Creates a payment and returns a link the payer opens. Amount is in minor units.',
                    'tags' => ['payments', 'checkout'],
                ],
                [
                    'id' => 'payment_status',
                    'name' => 'Check payment status',
                    'description' => 'Reports where a payment stands, by its platform id.',
                    'tags' => ['payments', 'status'],
                ],
                [
                    'id' => 'refund_payment',
                    'name' => 'Refund a payment',
                    'description' => 'Refunds a settled payment in full or in part.',
                    'tags' => ['payments', 'refunds'],
                ],
            ],
        ];
    }

    /**
     * Handle one JSON-RPC request; returns the response body to send back.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function handle(array $request): array
    {
        $id = $request['id'] ?? null;

        try {
            return match ($request['method'] ?? '') {
                'message/send' => self::ok($id, $this->onMessage($request['params'] ?? [])),
                'tasks/get' => self::ok($id, $this->requireTask((string) ($request['params']['id'] ?? ''))),
                'tasks/cancel' => self::ok($id, $this->cancelTask((string) ($request['params']['id'] ?? ''))),
                default => self::error($id, -32601, 'Unknown method ' . ($request['method'] ?? '')),
            };
        } catch (\Throwable $error) {
            return self::error($id, -32603, $error->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function onMessage(array $params): array
    {
        $message = $params['message'] ?? null;
        if (!is_array($message) || empty($message['parts'])) {
            throw new \InvalidArgumentException('message/send needs a message with at least one part.');
        }

        $task = [
            'id' => self::uuid(),
            'contextId' => (string) ($params['contextId'] ?? self::uuid()),
            'status' => ['state' => 'working', 'timestamp' => gmdate('c')],
            'artifacts' => [],
            'history' => [$message],
        ];

        $data = [];
        foreach ($message['parts'] as $part) {
            if (($part['kind'] ?? '') === 'data') {
                $data = $part['data'] ?? [];
                break;
            }
        }

        try {
            match ($data['skill'] ?? '') {
                'create_payment' => $this->createPayment($task, $data),
                'payment_status' => $this->paymentStatus($task, $data),
                'refund_payment' => $this->refundPayment($task, $data),
                default => $task['status'] = [
                    'state' => 'input-required',
                    'timestamp' => gmdate('c'),
                    'message' => self::agentText(
                        'Name a skill in the data part: create_payment, payment_status or refund_payment.'
                    ),
                ],
            };
        } catch (\Throwable $error) {
            $task['status'] = [
                'state' => 'failed',
                'timestamp' => gmdate('c'),
                'message' => self::agentText(
                    $error instanceof TransactionRejectedException
                        ? 'Payment refused: ' . $error->reason()
                        : $error->getMessage()
                ),
            ];
        }

        $this->tasks[$task['id']] = $task;
        return $task;
    }

    /**
     * @param array<string, mixed> $task
     * @param array<string, mixed> $data
     */
    private function createPayment(array &$task, array $data): void
    {
        $amount = (string) ($data['amount'] ?? '');
        $currency = (string) ($data['currency'] ?? $this->currency ?? '');
        if ($amount === '' || $currency === '') {
            throw new \InvalidArgumentException('create_payment needs amount and currency.');
        }
        if ($this->maxAmount !== null && bccomp($amount, $this->maxAmount) > 0) {
            throw new \InvalidArgumentException(
                "Amount {$amount} exceeds this agent's ceiling of {$this->maxAmount} minor units."
            );
        }

        [$transaction, $url] = $this->client->createHostedPayment(array_filter([
            'amount' => $amount,
            'currency' => $currency,
            'env' => $this->env,
            'txid' => isset($data['order_id']) ? (string) $data['order_id'] : null,
            'description' => isset($data['description']) ? (string) $data['description'] : null,
            'returnUrl' => $this->returnUrl,
            'failUrl' => $this->failUrl,
            'merchant_meta' => ['a2a' => ['task_id' => $task['id'], 'context_id' => $task['contextId']]],
        ], static fn ($value) => $value !== null));

        $task['artifacts'][] = [
            'artifactId' => self::uuid(),
            'name' => 'payment',
            'parts' => [
                [
                    'kind' => 'text',
                    'text' => 'Payment link for ' . Money::format($amount, $currency) . ": {$url}",
                ],
                [
                    'kind' => 'data',
                    'data' => [
                        'transaction_id' => $transaction['id'],
                        'status' => $transaction['status'],
                        'payment_url' => $url,
                        'amount' => $amount,
                        'currency' => $currency,
                        // The link is a short-lived JWT. An agent that caches it
                        // hands the buyer a dead page a quarter of an hour later.
                        'payment_url_expires_in_seconds' => 900,
                    ],
                ],
            ],
        ];
        $task['status'] = ['state' => 'completed', 'timestamp' => gmdate('c')];
    }

    /**
     * @param array<string, mixed> $task
     * @param array<string, mixed> $data
     */
    private function paymentStatus(array &$task, array $data): void
    {
        $id = (string) ($data['transaction_id'] ?? '');
        if ($id === '') {
            throw new \InvalidArgumentException('payment_status needs transaction_id.');
        }

        $tx = $this->client->getTransaction($id);
        $detail = !empty($tx['error_description']) ? " ({$tx['error_description']})" : '';

        $task['artifacts'][] = [
            'artifactId' => self::uuid(),
            'name' => 'status',
            'parts' => [
                ['kind' => 'text', 'text' => "Payment {$tx['id']} is {$tx['status']}{$detail}."],
                [
                    'kind' => 'data',
                    'data' => [
                        'transaction_id' => $tx['id'],
                        'status' => $tx['status'],
                        'final' => Client::isFinalStatus((string) $tx['status']),
                        'settled' => $tx['status'] === 'charged',
                        'amount' => $tx['amount'] ?? null,
                        'currency' => $tx['currency'] ?? null,
                    ],
                ],
            ],
        ];
        $task['status'] = ['state' => 'completed', 'timestamp' => gmdate('c')];
    }

    /**
     * @param array<string, mixed> $task
     * @param array<string, mixed> $data
     */
    private function refundPayment(array &$task, array $data): void
    {
        $id = (string) ($data['transaction_id'] ?? '');
        if ($id === '') {
            throw new \InvalidArgumentException('refund_payment needs transaction_id.');
        }

        $refund = $this->client->refund($id, $data['amount'] ?? null);
        $task['artifacts'][] = [
            'artifactId' => self::uuid(),
            'name' => 'refund',
            'parts' => [
                ['kind' => 'text', 'text' => "Refund of {$id} is {$refund['status']}."],
                ['kind' => 'data', 'data' => ['transaction_id' => $refund['id'], 'status' => $refund['status']]],
            ],
        ];
        $task['status'] = ['state' => 'completed', 'timestamp' => gmdate('c')];
    }

    /** @return array<string, mixed> */
    private function cancelTask(string $id): array
    {
        $task = $this->requireTask($id);
        if (!in_array($task['status']['state'], ['completed', 'failed', 'canceled'], true)) {
            $task['status'] = ['state' => 'canceled', 'timestamp' => gmdate('c')];
            $this->tasks[$id] = $task;
        }
        return $task;
    }

    /** @return array<string, mixed> */
    private function requireTask(string $id): array
    {
        if (!isset($this->tasks[$id])) {
            throw new \RuntimeException("No task {$id}.");
        }
        return $this->tasks[$id];
    }

    /** @return array<string, mixed> */
    private static function agentText(string $text): array
    {
        return [
            'role' => 'agent',
            'parts' => [['kind' => 'text', 'text' => $text]],
            'messageId' => self::uuid(),
        ];
    }

    /** @return array<string, mixed> */
    private static function ok(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /** @return array<string, mixed> */
    private static function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
