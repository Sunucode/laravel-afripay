<?php

use Illuminate\Support\Facades\Http;
use SunuCode\AfriPay\Enums\TransactionStatus;
use SunuCode\AfriPay\Models\Transaction;
use SunuCode\AfriPay\PaymentManager;
use SunuCode\AfriPay\Tests\TestCase;

uses(TestCase::class);

test('wave verify GET request sends no body and no signature when signing is disabled', function () {
    config()->set('afripay.gateways.wave.api_secret', '');

    $transaction = Transaction::create([
        'gateway' => 'wave',
        'reference' => 'WV-VERIFY-001',
        'gateway_reference' => 'session_123',
        'amount' => 100,
        'currency' => 'XOF',
        'status' => TransactionStatus::Pending,
    ]);

    Http::fake([
        'https://api.wave.com/v1/checkout/sessions/*' => Http::response([
            'checkout_status' => 'open',
        ], 200),
    ]);

    app(PaymentManager::class)->gateway('wave')->verify($transaction);

    Http::assertSent(function ($request) {
        return $request->method() === 'GET'
            && $request->url() === 'https://api.wave.com/v1/checkout/sessions/session_123'
            && $request->body() === ''
            && ! $request->hasHeader('Wave-Signature');
    });
});

test('wave charge POST request includes signed header when signing is enabled', function () {
    config()->set('afripay.gateways.wave.api_secret', 'wave_sn_AKS_test_secret');

    Http::fake([
        'https://api.wave.com/v1/checkout/sessions' => Http::response([
            'id' => 'session_abc',
            'wave_launch_url' => 'https://pay.wave.com/checkout/session_abc',
        ], 200),
    ]);

    app(PaymentManager::class)->gateway('wave')->charge([
        'amount' => 100,
        'currency' => 'XOF',
        'error_url' => 'https://example.com/error',
        'success_url' => 'https://example.com/success',
    ]);

    Http::assertSent(function ($request) {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.wave.com/v1/checkout/sessions'
            && $request->hasHeader('Wave-Signature')
            && preg_match('/^t=\d+,v1=[a-f0-9]{64}$/', $request->header('Wave-Signature')[0] ?? '') === 1;
    });
});
