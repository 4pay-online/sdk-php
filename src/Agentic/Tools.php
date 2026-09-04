<?php

declare(strict_types=1);

namespace FourPay\Agentic;

use FourPay\Exception\TransactionRejectedException;
use FourPay\Money;
use FourPay\Client;

/**
 * Payment tools for an LLM that calls functions.
 *
 * MCP is one way to give an agent these operations; a plain function-calling
 * loop is another, and it needs no server. {@see definitions()} returns the
 * JSON Schemas to hand a model, {@see execute()} runs what it picks.
 *
 * Two decisions are baked in, both because the caller is a model:
 *
 * - **no free-form amounts** — minor units as a string. Letting a model do
 *   decimal arithmetic on money is how 10.00 becomes 1000.00;
 * - **an explicit ceiling** — enforced here, before the request leaves. A
 *   prompt injection that reaches the tool layer should hit a wall it cannot
 *   argue with.
 */
final class Tools
{
    /**
     * Tool definitions to give the model.
     *
     * @return list<array<string, mixed>>
     */
    public static function definitions(?string $currency = null, bool $allowRefunds = false): array
    {
        $currencyNote = $currency !== null ? " Defaults to {$currency} when omitted." : '';

        $tools = [
            [
                'name' => 'create_payment',
                'description' => 'Create a payment and get a link for the customer to pay. Returns the '
                    . 'payment id and the URL. The URL expires in 15 minutes — give it to the '
                    . 'customer immediately, do not store it.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'amount' => [
                            'type' => 'string',
                            'description' => 'Amount in MINOR units as digits only: 2500 means 25.00 in a '
                                . 'two-decimal currency. Never send a decimal point.',
                        ],
                        'currency' => ['type' => 'string', 'description' => "ISO 4217 code, uppercase.{$currencyNote}"],
                        'order_id' => [
                            'type' => 'string',
                            'description' => 'Your order reference. Reusing it returns the existing payment '
                                . 'instead of creating a second one — always pass it.',
                        ],
                        'description' => ['type' => 'string'],
                        'customer_email' => ['type' => 'string'],
                    ],
                    'required' => ['amount', 'order_id'],
                ],
            ],
            [
                'name' => 'get_payment',
                'description' => "Look a payment up by its platform id and report its status. Only "
                    . "'charged' means the money arrived.",
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['payment_id' => ['type' => 'string']],
                    'required' => ['payment_id'],
                ],
            ],
            [
                'name' => 'list_payments',
                'description' => 'List recent payments, newest first.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'status' => ['type' => 'string'],
                        'limit' => ['type' => 'integer', 'description' => '1-100, default 20.'],
                    ],
                ],
            ],
        ];

        if ($allowRefunds) {
            $tools[] = [
                'name' => 'refund_payment',
                'description' => 'Refund a settled payment, fully or partly. Irreversible — confirm with '
                    . 'the human first.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'payment_id' => ['type' => 'string'],
                        'amount' => ['type' => 'string', 'description' => 'Minor units. Omit to refund it all.'],
                    ],
                    'required' => ['payment_id'],
                ],
            ];
        }

        return $tools;
    }

    /**
     * Execute a tool the model chose.
     *
     * Returns `['ok' => bool, 'content' => string, 'data' => array]` instead of
     * throwing: a model that receives "this payment was refused because the card
     * expired" can do something useful with it, whereas a stack trace ends the
     * conversation.
     *
     * @param array<string, mixed> $args
     * @return array{ok: bool, content: string, data?: array<string, mixed>}
     */
    public static function execute(
        Client $client,
        string $name,
        array $args,
        string $env,
        ?string $currency = null,
        ?string $maxAmount = null,
        bool $allowRefunds = false,
        ?string $returnUrl = null,
        ?string $failUrl = null,
    ): array {
        try {
            switch ($name) {
                case 'create_payment':
                    $amount = (string) ($args['amount'] ?? '');
                    $money = (string) ($args['currency'] ?? $currency ?? '');

                    if (!ctype_digit($amount)) {
                        return self::fail(
                            "amount must be digits only, in minor units — \"{$amount}\" is not. "
                            . 'For 25.00 send "2500".'
                        );
                    }
                    if ($money === '') {
                        return self::fail('currency is required.');
                    }
                    if ($maxAmount !== null && bccomp($amount, $maxAmount) > 0) {
                        return self::fail(sprintf(
                            'Refused: %s is above the %s ceiling set for this agent.',
                            Money::format($amount, $money),
                            Money::format($maxAmount, $money),
                        ));
                    }

                    [$transaction, $url] = $client->createHostedPayment(array_filter([
                        'amount' => $amount,
                        'currency' => $money,
                        'env' => $env,
                        'txid' => (string) $args['order_id'],
                        'description' => $args['description'] ?? null,
                        'customer' => isset($args['customer_email']) ? ['email' => $args['customer_email']] : null,
                        'returnUrl' => $returnUrl,
                        'failUrl' => $failUrl,
                    ], static fn ($value) => $value !== null));

                    return [
                        'ok' => true,
                        'content' => sprintf(
                            'Payment %s created for %s. Payment link (valid 15 minutes): %s',
                            $transaction['id'],
                            Money::format($amount, $money),
                            $url,
                        ),
                        'data' => [
                            'payment_id' => $transaction['id'],
                            'payment_url' => $url,
                            'status' => $transaction['status'],
                        ],
                    ];

                case 'get_payment':
                    $tx = $client->getTransaction((string) $args['payment_id']);
                    $settled = ($tx['status'] ?? '') === 'charged';
                    $detail = !empty($tx['error_description']) ? " ({$tx['error_description']})" : '';
                    $verdict = $settled
                        ? 'The money arrived.'
                        : (Client::isFinalStatus((string) $tx['status'])
                            ? 'Final — no money arrived.'
                            : 'Still in flight.');

                    return [
                        'ok' => true,
                        'content' => "Payment {$tx['id']}: {$tx['status']}{$detail}. {$verdict}",
                        'data' => [
                            'status' => $tx['status'],
                            'settled' => $settled,
                            'final' => Client::isFinalStatus((string) $tx['status']),
                        ],
                    ];

                case 'list_payments':
                    $page = $client->listTransactions(array_filter([
                        'env' => $env,
                        'status' => $args['status'] ?? null,
                        'limit' => (int) ($args['limit'] ?? 20),
                    ], static fn ($value) => $value !== null));

                    $rows = $page['data'] ?? [];
                    $lines = array_map(
                        static fn (array $tx) => sprintf(
                            '%s %s %s %s',
                            $tx['id'],
                            $tx['status'],
                            Money::format($tx['amount'], $tx['currency']),
                            $tx['txid'] ?? '',
                        ),
                        $rows,
                    );

                    return [
                        'ok' => true,
                        'content' => $lines === [] ? 'No payments match.' : implode("\n", $lines),
                        'data' => ['count' => count($rows)],
                    ];

                case 'refund_payment':
                    if (!$allowRefunds) {
                        return self::fail('Refunds are not enabled for this agent.');
                    }
                    $refund = $client->refund((string) $args['payment_id'], $args['amount'] ?? null);
                    return [
                        'ok' => true,
                        'content' => "Refund of {$args['payment_id']} is {$refund['status']}.",
                        'data' => ['status' => $refund['status']],
                    ];

                default:
                    return self::fail("Unknown tool {$name}.");
            }
        } catch (TransactionRejectedException $error) {
            $hint = $error->isRoutingFailure()
                ? ' No terminal is configured for this currency and environment — a human has to fix that.'
                : '';
            return self::fail('The platform refused the payment: ' . $error->reason() . '.' . $hint);
        } catch (\Throwable $error) {
            return self::fail($error->getMessage());
        }
    }

    /** @return array{ok: false, content: string} */
    private static function fail(string $content): array
    {
        return ['ok' => false, 'content' => $content];
    }
}
