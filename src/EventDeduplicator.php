<?php

declare(strict_types=1);

namespace FourPay;

/**
 * Deduplication by `event_id`, kept in memory.
 *
 * Deliveries repeat: a handler that timed out after doing the work still gets
 * the event again. Deduplicate on `event_id` — never on the transaction id,
 * since one transaction legitimately produces several events.
 *
 * In-memory means *within one PHP process*, and a PHP process typically dies
 * at the end of the request. Under PHP-FPM this class is a convenience for
 * long-running workers only: a web handler needs the same check against Redis
 * or your database, or it deduplicates nothing at all.
 */
final class EventDeduplicator
{
    /** @var array<string, int> */
    private array $seen = [];

    public function __construct(
        private readonly int $ttlSeconds = 86400,
        private readonly int $maxEntries = 10000,
    ) {
    }

    /** `true` the first time this event id is seen, `false` on every repeat. */
    public function accept(string $eventId): bool
    {
        $now = time();
        foreach ($this->seen as $id => $seenAt) {
            if ($now - $seenAt <= $this->ttlSeconds) {
                break;
            }
            unset($this->seen[$id]);
        }

        if (isset($this->seen[$eventId])) {
            return false;
        }

        $this->seen[$eventId] = $now;
        while (count($this->seen) > $this->maxEntries) {
            array_shift($this->seen);
        }
        return true;
    }
}
