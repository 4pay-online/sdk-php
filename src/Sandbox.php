<?php

declare(strict_types=1);

namespace FourPay;

/**
 * Sandbox fixtures.
 *
 * The emulator does not classify a card by its BIN. It compares the **whole
 * tuple** — number, CVV, holder, expiry — against a fixed list, and one
 * character of difference is refused with `unexpected card`. The holder is
 * upper-case and the expiry year is two digits.
 *
 * There is no Visa `4111 1111 1111 1111` here and no Mastercard `5500 …`: the
 * emulator has never accepted them.
 */
final class Sandbox
{
    public const BASE_URL = 'https://sandbox.4pay.online';
    public const PRODUCTION_BASE_URL = 'https://4pay.online';

    /** Settles: `charging` first, then `charged` on its own within about a minute. */
    public const CARD_CHARGE = [
        'cardnumber' => '2201382000000013', 'cvv' => '283',
        'cardholder' => 'IVAN IVANOV', 'exp_year' => '20', 'exp_month' => '12',
    ];

    /** Refused by the provider — ends `rejected`. */
    public const CARD_REJECT = [
        'cardnumber' => '2201382000000021', 'cvv' => '749',
        'cardholder' => 'IVAN IVANOV', 'exp_year' => '20', 'exp_month' => '12',
    ];

    /** Settles and then refunds. */
    public const CARD_REFUND = [
        'cardnumber' => '2201382000000047', 'cvv' => '480',
        'cardholder' => 'IVAN IVANOV', 'exp_year' => '20', 'exp_month' => '12',
    ];

    /** Ends `failed` — the emulator's cancellation, which is never `reversed`. */
    public const CARD_FAIL = [
        'cardnumber' => '2201382000000039', 'cvv' => '572',
        'cardholder' => 'IVAN IVANOV', 'exp_year' => '20', 'exp_month' => '12',
    ];

    /** Payout that hangs in `payout_suspended`. */
    public const CARD_PAYOUT_SUSPENDED = [
        'cardnumber' => '2201382000000054', 'cvv' => '111',
        'cardholder' => 'IVAN IVANOV', 'exp_year' => '20', 'exp_month' => '12',
    ];

    /** Payout destinations the emulator knows. */
    public const PAYOUT_BANK_CHARGE = '200000000001';
    public const PAYOUT_BANK_REJECT = '200000000002';

    /** SBP payer name that settles; any other name is refused. */
    public const SBP_CUSTOMER_CHARGE = 'John Doe';
}
