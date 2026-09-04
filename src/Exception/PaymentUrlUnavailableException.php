<?php

declare(strict_types=1);

namespace FourPay\Exception;

/** Created, not refused, but with no hosted-page URL — read the status. */
class PaymentUrlUnavailableException extends FourPayException
{
    /** @param array<string, mixed> $transaction */
    public function __construct(public readonly array $transaction)
    {
        parent::__construct(sprintf(
            'Transaction %s is %s but carries no widget_url. Use createPayment() when a payment '
            . 'can complete without a hosted page.',
            $transaction['id'] ?? '?',
            $transaction['status'] ?? '?',
        ));
    }
}
