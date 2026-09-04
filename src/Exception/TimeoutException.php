<?php

declare(strict_types=1);

namespace FourPay\Exception;

/** A wait helper gave up before the transaction reached a final status. */
class TimeoutException extends FourPayException
{
    /** @param array<string, mixed>|null $lastTransaction */
    public function __construct(string $message, public readonly ?array $lastTransaction = null)
    {
        parent::__construct($message);
    }
}
