<?php

use Illuminate\Support\Facades\Event;
use SunuCode\AfriPay\Contracts\GatewayInterface;
use SunuCode\AfriPay\Enums\TransactionStatus;
use SunuCode\AfriPay\Events\PaymentRefunded;
use SunuCode\AfriPay\Gateways\WaveGateway;
use SunuCode\AfriPay\Models\Transaction;
use SunuCode\AfriPay\PaymentManager;
use SunuCode\AfriPay\Tests\TestCase;

uses(TestCase::class);

test('payment manager resolves wave gateway', function () {
    $manager = app(PaymentManager::class);
    $gateway = $manager->gateway('wave');

    expect($gateway)->toBeInstanceOf(WaveGateway::class)
        ->and($gateway->name())->toBe('wave');
});

test('payment manager throws for unknown gateway', function () {
    $manager = app(PaymentManager::class);

    $manager->gateway('unknown');
})->throws(InvalidArgumentException::class);

test('custom gateways can be registered', function () {
    $mockGateway = Mockery::mock(GatewayInterface::class);
    $mockGateway->shouldReceive('name')->andReturn('cinetpay');

    PaymentManager::extend('cinetpay', fn ($config) => $mockGateway);

    $manager = app(PaymentManager::class);
    $gateway = $manager->gateway('cinetpay');

    expect($gateway->name())->toBe('cinetpay');
});

test('refund updates status and dispatches event', function () {
    $transaction = Transaction::create([
        'gateway' => 'wave',
        'reference' => 'REF-REFUND',
        'amount' => 10000,
        'currency' => 'XOF',
        'status' => TransactionStatus::Completed,
    ]);

    Event::fake();

    $manager = app(PaymentManager::class);
    $result = $manager->refund($transaction, 'Test refund');

    expect($result->status)->toBe(TransactionStatus::Refunded)
        ->and($result->metadata['refund_reason'])->toBe('Test refund');

    Event::assertDispatched(PaymentRefunded::class);
});

test('available gateways returns configured gateways', function () {
    $manager = app(PaymentManager::class);
    $gateways = $manager->availableGateways();

    expect($gateways)->toContain('wave')
        ->and($gateways)->toContain('stripe')
        ->and($gateways)->toContain('paydunya')
        ->and($gateways)->toContain('paytech');
});
