<?php

declare(strict_types=1);

namespace FourPay;

/**
 * The platform stamps times as `"2026-09-03 11:18:30.101839 Etc/UTC"`.
 *
 * Not ISO 8601: a space where `T` belongs, six fractional digits, and a named
 * zone. PHP's own parser is more forgiving than most — `new DateTimeImmutable`
 * reads that string correctly, though `DateTimeImmutable::createFromFormat(DATE_ATOM, …)`
 * returns `false` on it. The trap is the timestamp that arrives **without** a
 * zone: PHP reads it in `date.timezone`, so the same payment is booked three
 * hours early on a server set to Europe/Moscow and correctly on one set to UTC.
 *
 * This helper always returns UTC, whatever the server is set to.
 */
final class Dates
{
    /**
     * Parse a platform timestamp into a UTC `DateTimeImmutable`.
     *
     * Returns `null` for empty or unparseable input — a wrong timestamp is
     * worse than a missing one.
     */
    public static function parseTimestamp(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $pattern = '/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})(?:\.(\d+))?(?:\s+(\S+))?$/';
        if (preg_match($pattern, trim($value), $m) === 1) {
            $zone = $m[8] ?? '';
            if (in_array($zone, ['Etc/UTC', 'UTC', 'Z', 'GMT', ''], true)) {
                $micro = substr(str_pad($m[7] ?? '0', 6, '0'), 0, 6);
                return new \DateTimeImmutable(
                    "{$m[1]}-{$m[2]}-{$m[3]}T{$m[4]}:{$m[5]}:{$m[6]}.{$micro}+00:00"
                );
            }
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    /** Unix seconds — as webhook envelopes and sessions report time — to a DateTimeImmutable. */
    public static function fromUnixSeconds(int|string|null $seconds): ?\DateTimeImmutable
    {
        if ($seconds === null || $seconds === '') {
            return null;
        }
        return (new \DateTimeImmutable('@' . (int) $seconds))->setTimezone(new \DateTimeZone('UTC'));
    }
}
