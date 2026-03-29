<?php

use SunuCode\AfriPay\Enums\TransactionStatus;
use SunuCode\AfriPay\Models\Transaction;
use SunuCode\AfriPay\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->transaction = Transaction::create([
        'gateway' => 'wave',
        'reference' => 'TEST-001',
        'amount' => 15000,
        'currency' => 'XOF',
        'status' => TransactionStatus::Pending,
    ]);
});

test('transaction can be created', function () {
    expect($this->transaction)->toBeInstanceOf(Transaction::class)
        ->and($this->transaction->gateway)->toBe('wave')
        ->and($this->transaction->amount)->toBe('15000.00')
        ->and($this->transaction->status)->toBe(TransactionStatus::Pending);
});

test('markProcessed sets processed_at atomically', function () {
    expect($this->transaction->isProcessed())->toBeFalse();

    $result = $this->transaction->markProcessed();

    expect($result)->toBeTrue()
        ->and($this->transaction->isProcessed())->toBeTrue()
        ->and($this->transaction->processed_at)->not->toBeNull();
});

test('markProcessed returns false when already processed', function () {
    $this->transaction->markProcessed();

    $result = $this->transaction->markProcessed();

    expect($result)->toBeFalse();
});

test('markProcessed is idempotent across instances', function () {
    // Simulate two concurrent processes
    $tx1 = Transaction::find($this->transaction->id);
    $tx2 = Transaction::find($this->transaction->id);

    $result1 = $tx1->markProcessed();
    $result2 = $tx2->markProcessed();

    // Only one should succeed
    expect($result1)->toBeTrue()
        ->and($result2)->toBeFalse();
});

test('scopes filter correctly', function () {
    Transaction::create([
        'gateway' => 'stripe',
        'reference' => 'TEST-002',
        'amount' => 5000,
        'currency' => 'XOF',
        'status' => TransactionStatus::Completed,
    ]);

    Transaction::create([
        'gateway' => 'wave',
        'reference' => 'TEST-003',
        'amount' => 8000,
        'currency' => 'XOF',
        'status' => TransactionStatus::Failed,
    ]);

    expect(Transaction::pending()->count())->toBe(1)
        ->and(Transaction::completed()->count())->toBe(1)
        ->and(Transaction::failed()->count())->toBe(1)
        ->and(Transaction::forGateway('wave')->count())->toBe(2);
});

test('metadata is cast to array', function () {
    $this->transaction->update([
        'metadata' => ['order_id' => 123, 'user_id' => 456],
    ]);

    $fresh = $this->transaction->fresh();

    expect($fresh->metadata)->toBeArray()
        ->and($fresh->metadata['order_id'])->toBe(123);
});

test('gateway_response is cast to array', function () {
    $this->transaction->update([
        'gateway_response' => ['id' => 'ch_xxx', 'status' => 'complete'],
    ]);

    $fresh = $this->transaction->fresh();

    expect($fresh->gateway_response)->toBeArray()
        ->and($fresh->gateway_response['status'])->toBe('complete');
});

test('soft deletes work', function () {
    $this->transaction->delete();

    expect(Transaction::count())->toBe(0)
        ->and(Transaction::withTrashed()->count())->toBe(1);
});
