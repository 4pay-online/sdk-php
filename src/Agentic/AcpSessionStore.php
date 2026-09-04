<?php

declare(strict_types=1);

namespace FourPay\Agentic;

/**
 * Where checkout sessions live between calls.
 *
 * Implement it against your database. An ACP conversation spans several HTTP
 * requests from the agent, and under PHP-FPM nothing survives in memory
 * between them — without a store, `update` and `complete` answer 404.
 */
interface AcpSessionStore
{
    /** @return array<string, mixed>|null */
    public function get(string $sessionId): ?array;

    /** @param array<string, mixed> $session */
    public function set(string $sessionId, array $session): void;
}
