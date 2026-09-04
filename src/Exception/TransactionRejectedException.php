<?php

declare(strict_types=1);

namespace FourPay\Exception;

/**
 * The request succeeded and the payment did not.
 *
 * `POST /transactions` answers `201 Created` even when routing, limits or
 * anti-fraud refused the payment — the resource exists, it is simply
 * `rejected`. Code that checks only the HTTP status books unpaid orders as
 * paid, so the client throws this instead.
 */
class TransactionRejectedException extends FourPayException
{
    /** @param array<string, mixed> $transaction */
    public function __construct(public readonly array $transaction)
    {
        $reason = $transaction['error_description'] ?: 'no reason given';
        parent::__construct(sprintf(
            'Transaction %s was %s: %s',
            $transaction['id'] ?? '?',
            $transaction['status'] ?? '?',
            $reason,
        ));
    }

    public function reason(): string
    {
        return (string) ($this->transaction['error_description'] ?? '');
    }

    /** No terminal matched. Nothing the payer does will fix it. */
    public function isRoutingFailure(): bool
    {
        $reason = strtolower($this->reason());
        return str_contains($reason, 'terminal not found') || str_contains($reason, 'routing');
    }
}
