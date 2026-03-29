<?php

use SunuCode\AfriPay\Support\AmountConverter;

test('XOF is zero decimal', function () {
    expect(AmountConverter::isZeroDecimal('xof'))->toBeTrue()
        ->and(AmountConverter::isZeroDecimal('XOF'))->toBeTrue()
        ->and(AmountConverter::isZeroDecimal('xaf'))->toBeTrue();
});

test('EUR is not zero decimal', function () {
    expect(AmountConverter::isZeroDecimal('eur'))->toBeFalse()
        ->and(AmountConverter::isZeroDecimal('usd'))->toBeFalse();
});

test('toStripeAmount handles zero decimal currencies', function () {
    // XOF: 15000 FCFA -> 15000 (no conversion)
    expect(AmountConverter::toStripeAmount(15000, 'xof'))->toBe(15000);
    expect(AmountConverter::toStripeAmount(9900, 'XAF'))->toBe(9900);
});

test('toStripeAmount handles decimal currencies', function () {
    // EUR: 150.00 -> 15000 (multiply by 100)
    expect(AmountConverter::toStripeAmount(150.00, 'eur'))->toBe(15000);
    expect(AmountConverter::toStripeAmount(99.99, 'usd'))->toBe(9999);
});

test('fromStripeAmount handles zero decimal currencies', function () {
    expect(AmountConverter::fromStripeAmount(15000, 'xof'))->toBe(15000.0);
});

test('fromStripeAmount handles decimal currencies', function () {
    expect(AmountConverter::fromStripeAmount(15000, 'eur'))->toBe(150.0);
    expect(AmountConverter::fromStripeAmount(9999, 'usd'))->toBe(99.99);
});

test('amountsMatch with default tolerance', function () {
    expect(AmountConverter::amountsMatch(15000, 15000))->toBeTrue()
        ->and(AmountConverter::amountsMatch(15000, 15001))->toBeTrue()
        ->and(AmountConverter::amountsMatch(15000, 14999))->toBeTrue()
        ->and(AmountConverter::amountsMatch(15000, 15002))->toBeFalse();
});

test('amountsMatch with custom tolerance', function () {
    expect(AmountConverter::amountsMatch(15000, 15050, 100))->toBeTrue()
        ->and(AmountConverter::amountsMatch(15000, 15200, 100))->toBeFalse();
});
