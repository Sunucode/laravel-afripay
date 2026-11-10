<?php

namespace SunuCode\AfriPay\Support;

class AmountConverter
{
    /**
     * Zero-decimal currencies (no cents/centimes).
     * These currencies are stored as whole units.
     */
    private const ZERO_DECIMAL_CURRENCIES = [
        'bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw',
        'mga', 'pyg', 'rwf', 'ugx', 'vnd', 'vuv',
        'xaf', 'xof', 'xpf',
    ];

    /**
     * Check if a currency is zero-decimal.
     */
    public static function isZeroDecimal(string $currency): bool
    {
        return in_array(strtolower($currency), self::ZERO_DECIMAL_CURRENCIES);
    }

    /**
     * Convert amount to Stripe's smallest unit.
     * XOF/XAF: 15000 → 15000 (no change)
     * EUR/USD: 150.00 → 15000 (multiply by 100)
     */
    public static function toStripeAmount(float $amount, string $currency): int
    {
        if (self::isZeroDecimal($currency)) {
            return (int) $amount;
        }

        return (int) round($amount * 100);
    }

    /**
     * Convert Stripe's smallest unit back to a float.
     */
    public static function fromStripeAmount(int $stripeAmount, string $currency): float
    {
        if (self::isZeroDecimal($currency)) {
            return (float) $stripeAmount;
        }

        return round($stripeAmount / 100, 2);
    }

    /**
     * Check if two amounts match within a tolerance.
     *
     * The tolerance follows the currency when none is given. A flat ±1 meant
     * one franc on XOF, which is right, but a whole dollar on USD: at the
     * rates this package is used with, that is some 600 CFA francs of
     * difference silently accepted on a settlement. One minor unit is the
     * correct allowance everywhere, and it only exists to absorb rounding.
     */
    public static function amountsMatch(float $expected, float $received, ?float $tolerance = null, string $currency = 'xof'): bool
    {
        $tolerance ??= self::isZeroDecimal($currency) ? 1.0 : 0.01;

        return abs($expected - $received) <= $tolerance;
    }
}
