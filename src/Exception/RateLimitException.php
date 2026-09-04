<?php

declare(strict_types=1);

namespace FourPay\Exception;

/** `429`. `$retryAfter` is seconds when the platform names one. */
class RateLimitException extends ApiException
{
    public function __construct(
        int $status,
        array $details = [],
        mixed $body = null,
        public readonly ?int $retryAfter = null,
        ?string $requestId = null,
    ) {
        parent::__construct($status, $details, $body, $requestId);
    }
}
