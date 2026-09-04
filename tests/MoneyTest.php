<?php

declare(strict_types=1);

use FourPay\Dates;
use FourPay\Money;

test('major-unit strings convert with the currency\'s own scale', function (): void {
    assertSameValue('1000', Money::toMinorUnits('10.00', 'USD'));
    assertSameValue('1050', Money::toMinorUnits('10.5', 'USD'));
    assertSameValue('1000', Money::toMinorUnits('1000', 'JPY'));
    assertSameValue('1234567', Money::toMinorUnits('1.234567', 'USDT'));
});

test('a float amount is refused outright', function (): void {
    assertThrows(InvalidArgumentException::class, fn () => Money::toMinorUnits(10.5, 'USD'), 'float');
});

test('more decimals than the currency has is refused', function (): void {
    assertThrows(InvalidArgumentException::class, fn () => Money::toMinorUnits('10.001', 'USD'), 'decimal places');
});

test('an unknown currency is refused rather than assumed', function (): void {
    assertThrows(InvalidArgumentException::class, fn () => Money::toMinorUnits('10.00', 'XYZ'), 'Unknown minor-unit');
});

test('eighteen-decimal assets survive the round trip', function (): void {
    $wei = '1234567890123456789';
    assertSameValue($wei, Money::toMinorUnits(Money::fromMinorUnits($wei, 'ETH'), 'ETH'));
});

test('display formatting', function (): void {
    assertSameValue('10.00', Money::fromMinorUnits('1000', 'USD'));
    assertSameValue('-10.50', Money::fromMinorUnits('-1050', 'USD'));
    assertSameValue('1000', Money::fromMinorUnits('1000', 'JPY'));
    assertSameValue('25.00 EUR', Money::format('2500', 'EUR'));
});

test('the platform\'s own timestamp format parses as UTC', function (): void {
    $parsed = Dates::parseTimestamp('2026-09-03 11:18:30.101839 Etc/UTC');
    assertSameValue('2026-09-03T11:18:30+00:00', $parsed->format('c'));
});

test('the ATOM format cannot read it — the platform is not ISO 8601', function (): void {
    assertSameValue(false, DateTimeImmutable::createFromFormat(DATE_ATOM, '2026-09-03 11:18:30.101839 Etc/UTC'));
});

test('a zoneless timestamp is UTC here, whatever date.timezone says', function (): void {
    // PHP reads it in the server's own zone: on Europe/Moscow the native parser
    // answers 08:18 UTC for a string the platform meant as 11:18 UTC. A
    // three-hour error in every settlement report, and it only shows up on
    // deployment.
    $previous = date_default_timezone_get();
    date_default_timezone_set('Europe/Moscow');
    try {
        assertSameValue(
            '2026-09-03T11:18:30+00:00',
            Dates::parseTimestamp('2026-09-03 11:18:30')->format('c'),
        );
        assertSameValue(
            '2026-09-03T08:18:30+00:00',
            (new DateTimeImmutable('2026-09-03 11:18:30'))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('c'),
        );
    } finally {
        date_default_timezone_set($previous);
    }
});

test('a bare timestamp is read as UTC, not local time', function (): void {
    assertSameValue('2026-09-03T11:18:30+00:00', Dates::parseTimestamp('2026-09-03 11:18:30')->format('c'));
});

test('nonsense gives null rather than a wrong date', function (): void {
    assertSameValue(null, Dates::parseTimestamp(null));
    assertSameValue(null, Dates::parseTimestamp(''));
    assertSameValue(null, Dates::parseTimestamp('not a date'));
});

test('unix seconds from sessions and webhooks', function (): void {
    assertSameValue('2026-09-03T11:21:30+00:00', Dates::fromUnixSeconds(1788434490)->format('c'));
    assertSameValue(null, Dates::fromUnixSeconds(null));
});
