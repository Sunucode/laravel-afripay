<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use SunuCode\AfriPay\Enums\TransactionStatus;
use SunuCode\AfriPay\Events\PaymentCompleted;
use SunuCode\AfriPay\Events\PaymentFailed;
use SunuCode\AfriPay\Models\Transaction;
use SunuCode\AfriPay\PaymentManager;
use SunuCode\AfriPay\Tests\TestCase;

uses(TestCase::class);

/*
 * A transaction leaves pending once, under a lock, and the event goes out only
 * then: replays, late webhooks and return pages never dispatch twice, and a
 * payment of the wrong amount or currency fails.
 */

function pendingTransaction(string $gateway, string $reference, string $gatewayReference, float $amount = 1000, string $currency = 'XOF'): Transaction
{
    return Transaction::create([
        'gateway' => $gateway,
        'reference' => $reference,
        'gateway_reference' => $gatewayReference,
        'amount' => $amount,
        'currency' => $currency,
        'status' => TransactionStatus::Pending,
    ]);
}

function waveWebhook(array $checkout, string $type = 'checkout.session.completed', string $secret = 'test_webhook_secret'): array
{
    $body = json_encode(['id' => 'EV_1', 'type' => $type, 'data' => $checkout]);
    $timestamp = time();

    return [$body, 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.$body, $secret)];
}

function postWave(array $checkout, string $type = 'checkout.session.completed', string $secret = 'test_webhook_secret')
{
    [$body, $signature] = waveWebhook($checkout, $type, $secret);

    return test()->call('POST', '/afripay/webhooks/wave', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_WAVE_SIGNATURE' => $signature,
    ], $body);
}

function waveCheckout(string $reference, string $amount = '1000', string $currency = 'XOF'): array
{
    return [
        'id' => 'cos_1',
        'amount' => $amount,
        'currency' => $currency,
        'checkout_status' => 'complete',
        'payment_status' => 'succeeded',
        'client_reference' => $reference,
        'transaction_id' => 'T_1',
    ];
}

function postStripe(array $session, string $type = 'checkout.session.completed')
{
    $body = json_encode(['id' => 'evt_1', 'type' => $type, 'data' => ['object' => $session]]);
    $timestamp = time();
    $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_test_xxx');

    return test()->call('POST', '/afripay/webhooks/stripe', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $signature,
    ], $body);
}

beforeEach(function () {
    Event::fake([PaymentCompleted::class, PaymentFailed::class]);
});

// Wave

test('a signed Wave webhook completes the transaction and dispatches once', function () {
    $transaction = pendingTransaction('wave', 'WV-1', 'cos_1');

    postWave(waveCheckout('WV-1'))->assertOk()->assertJson(['status' => 'confirmed']);
    postWave(waveCheckout('WV-1'))->assertOk()->assertJson(['status' => 'ignored']);

    expect($transaction->fresh()->status)->toBe(TransactionStatus::Completed);
    Event::assertDispatchedTimes(PaymentCompleted::class, 1);
});

test('a Wave webhook with a bad signature is refused', function () {
    $transaction = pendingTransaction('wave', 'WV-2', 'cos_2');

    postWave(waveCheckout('WV-2'), secret: 'wrong_secret')->assertForbidden();

    expect($transaction->fresh()->status)->toBe(TransactionStatus::Pending);
    Event::assertNotDispatched(PaymentCompleted::class);
});

test('a Wave signature listing the new key during a rotation is accepted', function () {
    pendingTransaction('wave', 'WV-3', 'cos_3');
    [$body, $signature] = waveWebhook(waveCheckout('WV-3'));
    $timestamp = explode('=', explode(',', $signature)[0])[1];

    test()->call('POST', '/afripay/webhooks/wave', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_WAVE_SIGNATURE' => 't='.$timestamp.',v1='.str_repeat('0', 64).','.explode(',', $signature)[1],
    ], $body)->assertOk()->assertJson(['status' => 'confirmed']);
});

test('a Wave payment of the wrong amount fails', function () {
    $transaction = pendingTransaction('wave', 'WV-4', 'cos_4');

    postWave(waveCheckout('WV-4', '5'))->assertOk();

    expect($transaction->fresh()->status)->toBe(TransactionStatus::Failed);
    Event::assertNotDispatched(PaymentCompleted::class);
    Event::assertDispatchedTimes(PaymentFailed::class, 1);
});

test('a Wave payment in another currency fails', function () {
    $transaction = pendingTransaction('wave', 'WV-5', 'cos_5');

    postWave(waveCheckout('WV-5', '1000', 'EUR'))->assertOk();

    expect($transaction->fresh()->status)->toBe(TransactionStatus::Failed);
});

test('a Wave payment failure marks the transaction failed', function () {
    $transaction = pendingTransaction('wave', 'WV-6', 'cos_6');

    postWave(['id' => 'cos_6', 'client_reference' => 'WV-6', 'checkout_status' => 'failed'], 'checkout.session.payment_failed')->assertOk();

    expect($transaction->fresh()->status)->toBe(TransactionStatus::Failed);
    Event::assertDispatchedTimes(PaymentFailed::class, 1);
});

test('a Wave webhook without its amount reads it back from the API', function () {
    $transaction = pendingTransaction('wave', 'WV-7', 'cos_7');
    Http::fake(['https://api.wave.com/v1/checkout/sessions/cos_7' => Http::response(waveCheckout('WV-7'))]);

    $checkout = waveCheckout('WV-7');
    unset($checkout['amount']);
    postWave($checkout)->assertOk();

    expect($transaction->fresh()->status)->toBe(TransactionStatus::Completed);
    Event::assertDispatchedTimes(PaymentCompleted::class, 1);
});

test('wave charges the test amount only when it is configured', function () {
    Http::fake(['https://api.wave.com/v1/checkout/sessions' => Http::response(['id' => 'cos_8', 'wave_launch_url' => 'https://pay.wave.com/c/cos_8'])]);
    $charge = fn () => app(PaymentManager::class)->charge([
        'amount' => 25000,
        'success_url' => 'https://example.test/ok',
        'error_url' => 'https://example.test/ko',
    ], 'wave');

    $charge();
    Http::assertSent(fn ($request) => $request['amount'] === '25000');

    config()->set('afripay.gateways.wave.test_amount', 5);
    app()->forgetInstance(PaymentManager::class);
    $charge();
    Http::assertSent(fn ($request) => $request['amount'] === '5');
});

test('with a test amount, the Wave payment of that amount completes the transaction', function () {
    config()->set('afripay.gateways.wave.test_amount', '5');
    app()->forgetInstance(PaymentManager::class);
    $transaction = pendingTransaction('wave', 'WV-12', 'cos_12', 25000);

    postWave(waveCheckout('WV-12', '5'))->assertOk();

    expect($transaction->fresh()->status)->toBe(TransactionStatus::Completed);
});

// The return page and the webhook

test('the return page reads the status without settling, so the late webhook still dispatches', function () {
    config()->set('afripay.trust_webhook_only', true);
    $transaction = pendingTransaction('wave', 'WV-9', 'cos_9');
    Http::fake(['https://api.wave.com/v1/checkout/sessions/cos_9' => Http::response(waveCheckout('WV-9'))]);

    $returned = app(PaymentManager::class)->verifyAndProcess($transaction);

    expect($returned->isConfirmedByGateway())->toBeTrue()
        ->and($transaction->fresh()->status)->toBe(TransactionStatus::Pending);
    Event::assertNotDispatched(PaymentCompleted::class);

    postWave(waveCheckout('WV-9'))->assertOk();

    expect($transaction->fresh()->status)->toBe(TransactionStatus::Completed);
    Event::assertDispatchedTimes(PaymentCompleted::class, 1);
});

test('without trust_webhook_only the first of the return page and the webhook settles, once', function () {
    config()->set('afripay.trust_webhook_only', false);
    $transaction = pendingTransaction('wave', 'WV-10', 'cos_10');
    Http::fake(['https://api.wave.com/v1/checkout/sessions/cos_10' => Http::response(waveCheckout('WV-10'))]);

    $returned = app(PaymentManager::class)->verifyAndProcess($transaction);
    postWave(waveCheckout('WV-10'))->assertOk()->assertJson(['status' => 'ignored']);
    app(PaymentManager::class)->verifyAndProcess($transaction->fresh());

    expect($returned->status)->toBe(TransactionStatus::Completed);
    Event::assertDispatchedTimes(PaymentCompleted::class, 1);
});

test('an unreachable gateway leaves the return page on pending', function () {
    config()->set('afripay.trust_webhook_only', false);
    $transaction = pendingTransaction('wave', 'WV-11', 'cos_11');
    Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));

    $returned = app(PaymentManager::class)->verifyAndProcess($transaction);

    expect($returned->status)->toBe(TransactionStatus::Pending)
        ->and(app(PaymentManager::class)->verify($transaction)->isConfirmedByGateway())->toBeFalse();
});

// Stripe

test('a signed Stripe webhook completes once, with the zero-decimal amount', function () {
    $transaction = pendingTransaction('stripe', 'ST-1', 'cs_1', 15000);
    $session = ['id' => 'cs_1', 'client_reference_id' => 'ST-1', 'payment_status' => 'paid', 'status' => 'complete', 'amount_total' => 15000, 'currency' => 'xof'];

    postStripe($session)->assertOk()->assertJson(['status' => 'ok']);
    postStripe($session)->assertOk()->assertJson(['status' => 'ignored']);

    expect($transaction->fresh()->status)->toBe(TransactionStatus::Completed);
    Event::assertDispatchedTimes(PaymentCompleted::class, 1);
});

test('a Stripe payment of the wrong amount fails', function () {
    $transaction = pendingTransaction('stripe', 'ST-2', 'cs_2', 15000);

    postStripe(['id' => 'cs_2', 'client_reference_id' => 'ST-2', 'payment_status' => 'paid', 'amount_total' => 100, 'currency' => 'xof'])->assertOk();

    expect($transaction->fresh()->status)->toBe(TransactionStatus::Failed);
});

test('an expired Stripe session fails the transaction', function () {
    $transaction = pendingTransaction('stripe', 'ST-3', 'cs_3', 15000);

    postStripe(['id' => 'cs_3', 'client_reference_id' => 'ST-3', 'payment_status' => 'unpaid', 'status' => 'expired', 'currency' => 'xof'], 'checkout.session.expired')->assertOk();

    expect($transaction->fresh()->status)->toBe(TransactionStatus::Failed);
    Event::assertDispatchedTimes(PaymentFailed::class, 1);
});

test('a Stripe webhook with a bad signature is refused', function () {
    pendingTransaction('stripe', 'ST-4', 'cs_4');

    test()->call('POST', '/afripay/webhooks/stripe', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => 't='.time().',v1=bad',
    ], json_encode(['type' => 'checkout.session.completed']))->assertUnauthorized();
});

// PayDunya

test('a PayDunya IPN is settled from the confirm API, never from its own data', function () {
    $transaction = pendingTransaction('paydunya', 'PD-1', 'tok_1', 2900);
    Http::fake(['*/checkout-invoice/confirm/tok_1' => Http::response(['response_code' => '00', 'status' => 'pending', 'invoice' => ['total_amount' => 2900]])]);

    $ipn = ['data' => ['hash' => hash('sha512', 'test_master_key'), 'status' => 'completed', 'invoice' => ['token' => 'tok_1', 'total_amount' => 2900]]];
    test()->post('/afripay/webhooks/paydunya', $ipn)->assertOk();

    expect($transaction->fresh()->status)->toBe(TransactionStatus::Pending);
    Event::assertNotDispatched(PaymentCompleted::class);
});

test('a PayDunya IPN completes once when the API confirms it', function () {
    $transaction = pendingTransaction('paydunya', 'PD-2', 'tok_2', 2900);
    Http::fake(['*/checkout-invoice/confirm/tok_2' => Http::response(['response_code' => '00', 'status' => 'Completed', 'invoice' => ['total_amount' => '2900']])]);

    $ipn = ['data' => ['hash' => hash('sha512', 'test_master_key'), 'status' => 'completed', 'invoice' => ['token' => 'tok_2']]];
    test()->post('/afripay/webhooks/paydunya', $ipn)->assertOk();
    test()->post('/afripay/webhooks/paydunya', $ipn)->assertOk();

    expect($transaction->fresh()->status)->toBe(TransactionStatus::Completed);
    Event::assertDispatchedTimes(PaymentCompleted::class, 1);
});

test('a PayDunya IPN without the master key hash is refused', function () {
    pendingTransaction('paydunya', 'PD-3', 'tok_3', 2900);

    test()->post('/afripay/webhooks/paydunya', ['data' => ['hash' => 'nope', 'invoice' => ['token' => 'tok_3']]])->assertUnauthorized();
});

// Routes and storage

test('only enabled gateways have a webhook route', function () {
    expect(Route::has('afripay.webhooks.wave'))->toBeTrue()
        ->and(Route::has('afripay.webhooks.paypal'))->toBeFalse()
        ->and(Route::has('afripay.webhooks.orange-money'))->toBeFalse();
});

test('a reference is unique per gateway', function () {
    pendingTransaction('wave', 'WV-DUP', 'cos_a');

    pendingTransaction('wave', 'WV-DUP', 'cos_b');
})->throws(\Illuminate\Database\QueryException::class);
