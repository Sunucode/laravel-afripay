<?php

namespace SunuCode\AfriPay\Enums;

enum Gateway: string
{
    case Wave = 'wave';
    case Stripe = 'stripe';
    case PayDunya = 'paydunya';
    case OrangeMoney = 'orange_money';
    case PayPal = 'paypal';
    case PayTech = 'paytech';

    public function label(): string
    {
        return match ($this) {
            self::Wave => 'Wave',
            self::Stripe => 'Stripe',
            self::PayDunya => 'PayDunya',
            self::OrangeMoney => 'Orange Money',
            self::PayPal => 'PayPal',
            self::PayTech => 'PayTech',
        };
    }

    /**
     * Countries where this gateway operates.
     *
     * @return array<string>
     */
    public function countries(): array
    {
        return match ($this) {
            self::Wave => ['SN', 'CI', 'ML', 'BF'],
            self::OrangeMoney => ['SN', 'CI', 'ML', 'BF', 'CM', 'GN'],
            self::PayDunya => ['SN', 'CI', 'BJ', 'TG', 'BF', 'ML'],
            self::PayTech => ['SN'],
            self::Stripe => [],  // Global
            self::PayPal => [],  // Global
        };
    }

    public function isLocal(): bool
    {
        return in_array($this, [self::Wave, self::OrangeMoney, self::PayDunya, self::PayTech]);
    }

    public function isGlobal(): bool
    {
        return in_array($this, [self::Stripe, self::PayPal]);
    }
}
