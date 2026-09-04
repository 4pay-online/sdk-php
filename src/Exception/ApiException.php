<?php

declare(strict_types=1);

namespace FourPay\Exception;

/**
 * The platform answered, and the answer was an error.
 *
 * Two body shapes exist in the wild: the current
 * `{"errors":[{"code":…,"detail":…}]}` and, on a few older endpoints,
 * `{"error":…,"message":…}`. Both are folded into `$details`.
 */
class ApiException extends FourPayException
{
    /** @param list<array{detail: string, code?: string, pointer?: string}> $details */
    public function __construct(
        public readonly int $status,
        public readonly array $details = [],
        public readonly mixed $body = null,
        public readonly ?string $requestId = null,
    ) {
        $summary = implode('; ', array_filter(array_column($details, 'detail')));
        parent::__construct($summary !== '' ? $summary : "HTTP {$status}");
    }

    /** Rate limits and platform faults are worth another try; your own bad request is not. */
    public function isRetryable(): bool
    {
        return $this->status === 429 || $this->status >= 500;
    }
}
