<?php

declare(strict_types=1);

namespace FourPay;

/**
 * Money is integer minor units, always.
 *
 * The API speaks decimal strings — `"1000"` is 10.00 in a two-decimal currency
 * — because an 18-decimal asset does not fit a JSON number, and PHP's own
 * integers stop at 2^63. Everything here is string arithmetic through bcmath:
 * a float amount is a rounding bug with a delay fuse.
 */
final class Money
{
    /** @var array<string, int> */
    private const DECIMALS = [
        'USD' => 2, 'EUR' => 2, 'GBP' => 2, 'RUB' => 2, 'KZT' => 2, 'AED' => 2,
        'TRY' => 2, 'CNY' => 2, 'INR' => 2, 'BRL' => 2,
        'JPY' => 0, 'KRW' => 0, 'VND' => 0, 'IDR' => 0,
        'KWD' => 3, 'BHD' => 3, 'OMR' => 3, 'JOD' => 3, 'TND' => 3,
        'USDT' => 6, 'USDC' => 6, 'TRX' => 6, 'ZOLT' => 6,
        'BTC' => 8,
        'ETH' => 18,
    ];

    /**
     * How many minor units make one major unit.
     *
     * Throws for an unknown currency rather than assuming two: a guessed scale
     * overstates JPY a hundredfold and ETH by sixteen orders of magnitude.
     */
    public static function decimalsFor(string $currency): int
    {
        $code = strtoupper($currency);
        if (!isset(self::DECIMALS[$code])) {
            throw new \InvalidArgumentException(
                "Unknown minor-unit scale for currency {$currency}. "
                . 'Pass decimals explicitly instead of letting the SDK guess.'
            );
        }
        return self::DECIMALS[$code];
    }

    /**
     * `"10.00"` or `1000` to minor units as the API wants them.
     *
     * A float is refused outright, and a decimal string with more places than
     * the currency has is refused too — rounding money quietly is how ledgers
     * drift.
     */
    public static function toMinorUnits(string|int|float $value, string $currency, ?int $decimals = null): string
    {
        if (is_float($value)) {
            throw new \InvalidArgumentException(
                'Refusing a float amount. Pass a decimal string like "10.50", or an integer count '
                . 'of minor units.'
            );
        }
        $decimals ??= self::decimalsFor($currency);

        if (is_int($value)) {
            return (string) $value;
        }

        $text = trim($value);
        if (preg_match('/^-?\d+$/', $text) === 1) {
            return $text;
        }
        if (preg_match('/^(-?)(\d*)(?:\.(\d+))?$/', $text, $matches) !== 1) {
            throw new \InvalidArgumentException("Not an amount: {$value}");
        }

        [, $sign, $whole, $fraction] = $matches + [3 => ''];
        if (strlen($fraction) > $decimals) {
            throw new \InvalidArgumentException(sprintf(
                '%s has %d decimal places but %s has %d. Rounding money silently is not this '
                . "SDK's job.",
                $value,
                strlen($fraction),
                $currency,
                $decimals,
            ));
        }

        $digits = ltrim(($whole === '' ? '0' : $whole) . str_pad($fraction, $decimals, '0'), '0');
        return $sign . ($digits === '' ? '0' : $digits);
    }

    /** Minor units to a display string with the currency's own number of decimals. */
    public static function fromMinorUnits(string|int $minor, string $currency, ?int $decimals = null): string
    {
        $decimals ??= self::decimalsFor($currency);
        $text = trim((string) $minor);
        $negative = str_starts_with($text, '-');
        $digits = ltrim(ltrim($text, '-'), '0');
        $digits = $digits === '' ? '0' : $digits;

        if ($decimals === 0) {
            return ($negative ? '-' : '') . $digits;
        }

        $padded = str_pad($digits, $decimals + 1, '0', STR_PAD_LEFT);
        $whole = substr($padded, 0, -$decimals);
        $fraction = substr($padded, -$decimals);
        return ($negative ? '-' : '') . $whole . '.' . $fraction;
    }

    /** `"10.00 USD"`. */
    public static function format(string|int $minor, string $currency, ?int $decimals = null): string
    {
        return self::fromMinorUnits($minor, $currency, $decimals) . ' ' . strtoupper($currency);
    }
}
