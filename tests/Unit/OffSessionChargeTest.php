<?php

use Illuminate\Support\Facades\Http;
use SunuCode\AfriPay\Enums\TransactionStatus;
use SunuCode\AfriPay\Exceptions\RequiresCustomerAuthentication;
use SunuCode\AfriPay\Models\PaymentMethod;
use SunuCode\AfriPay\PaymentManager;
use SunuCode\AfriPay\Tests\TestCase;

uses(TestCase::class);

/*
 * A card can be charged again without the customer; Mobile Money cannot.
 * The capability is optional, so asking a gateway that does not have it must
 * fail loudly here rather than quietly on a billing run.
 */

function savedCard(array $overrides = []): PaymentMethod
{
    return PaymentMethod::create(array_merge([
        'gateway' => 'stripe',
        'gateway_customer_id' => 'cus_123',
        'gateway_method_id' => 'pm_123',
        'brand' => 'visa',
        'last4' => '4242',
        'exp_month' => 12,
        'exp_year' => (int) now()->addYear()->format('Y'),
    ], $overrides));
}

function setupSession(string $status = 'succeeded'): array
{
    return [
        'id' => 'cs_setup_1',
        'mode' => 'setup',
        'setup_intent' => [
            'id' => 'seti_1',
            'status' => $status,
            'customer' => 'cus_123',
            'payment_method' => [
                'id' => 'pm_123',
                'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 9, 'exp_year' => 2030],
            ],
        ],
    ];
}

// --- La capacité ----------------------------------------------------------

it('tells which gateways can charge without the customer', function () {
    $manager = app(PaymentManager::class);

    expect($manager->canChargeOffSession('stripe'))->toBeTrue()
        ->and($manager->canChargeOffSession('wave'))->toBeFalse();
});

it('refuses outright a gateway that cannot charge off session', function () {
    expect(fn () => app(PaymentManager::class)->via('wave')->startSavingMethod([
        'success_url' => 'https://exemple.test/ok',
        'error_url' => 'https://exemple.test/ko',
    ]))->toThrow(InvalidArgumentException::class);
});

// --- Enregistrer la carte -------------------------------------------------

it('opens a setup page that charges nothing', function () {
    Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_setup_1', 'url' => 'https://stripe.test/setup'])]);

    $resultat = app(PaymentManager::class)->via('stripe')->startSavingMethod([
        'success_url' => 'https://exemple.test/ok',
        'error_url' => 'https://exemple.test/ko',
    ]);

    expect($resultat['redirect_url'])->toBe('https://stripe.test/setup')
        ->and($resultat['reference'])->toStartWith('SM');

    Http::assertSent(function ($request) {
        // Mode setup, et surtout aucun montant : rien ne doit être prélevé ici.
        return $request['mode'] === 'setup'
            && ! isset($request['line_items'])
            && ! collect($request->data())->keys()->contains(fn ($k) => str_contains((string) $k, 'unit_amount'));
    });
});

it('keeps the handles and the four digits once the card is authorised', function () {
    Http::fake(['api.stripe.com/v1/checkout/sessions/*' => Http::response(setupSession())]);

    $method = app(PaymentManager::class)->confirmSavedMethod('cs_setup_1', null, 'stripe');

    expect($method)->not->toBeNull()
        ->and($method->gateway_customer_id)->toBe('cus_123')
        ->and($method->gateway_method_id)->toBe('pm_123')
        ->and($method->label())->toBe('visa ···· 4242')
        ->and($method->exp_year)->toBe(2030);
});

it('stores nothing while the authorisation is unfinished', function () {
    Http::fake(['api.stripe.com/v1/checkout/sessions/*' => Http::response(setupSession('processing'))]);

    expect(app(PaymentManager::class)->confirmSavedMethod('cs_setup_1', null, 'stripe'))->toBeNull()
        ->and(PaymentMethod::count())->toBe(0);
});

it('does not make a second row for the same card authorised twice', function () {
    Http::fake(['api.stripe.com/v1/checkout/sessions/*' => Http::response(setupSession())]);

    app(PaymentManager::class)->confirmSavedMethod('cs_setup_1', null, 'stripe');
    app(PaymentManager::class)->confirmSavedMethod('cs_setup_1', null, 'stripe');

    expect(PaymentMethod::count())->toBe(1);
});

// --- Prélever -------------------------------------------------------------

it('charges a stored card and settles the transaction', function () {
    Http::fake(['api.stripe.com/v1/payment_intents' => Http::response(['id' => 'pi_1', 'status' => 'succeeded'])]);

    $transaction = app(PaymentManager::class)->chargeSaved(savedCard(), ['amount' => 12000.0, 'currency' => 'XOF']);

    expect($transaction->status)->toBe(TransactionStatus::Completed)
        ->and($transaction->gateway_reference)->toBe('pi_1');

    Http::assertSent(fn ($request) => $request['off_session'] === 'true'
        && $request['confirm'] === 'true'
        && $request['customer'] === 'cus_123'
        && $request['payment_method'] === 'pm_123');
});

it('stops instead of retrying when the issuer wants the customer', function () {
    Http::fake(['api.stripe.com/v1/payment_intents' => Http::response([
        'error' => [
            'code' => 'authentication_required',
            'payment_intent' => [
                'id' => 'pi_auth',
                'next_action' => ['redirect_to_url' => ['url' => 'https://stripe.test/3ds']],
            ],
        ],
    ], 402)]);

    try {
        app(PaymentManager::class)->chargeSaved(savedCard(), ['amount' => 12000.0, 'currency' => 'XOF']);
        $this->fail('L\'exception d\'authentification aurait dû être levée.');
    } catch (RequiresCustomerAuthentication $e) {
        expect($e->redirectUrl)->toBe('https://stripe.test/3ds')
            ->and($e->transaction->status)->toBe(TransactionStatus::Failed)
            ->and($e->transaction->gateway_reference)->toBe('pi_auth');
    }
});

it('fails plainly on a declined card, without the authentication exception', function () {
    Http::fake(['api.stripe.com/v1/payment_intents' => Http::response([
        'error' => ['code' => 'card_declined', 'payment_intent' => ['id' => 'pi_ko']],
    ], 402)]);

    $transaction = app(PaymentManager::class)->chargeSaved(savedCard(), ['amount' => 12000.0, 'currency' => 'XOF']);

    expect($transaction->status)->toBe(TransactionStatus::Failed);
});

// --- Le modèle ------------------------------------------------------------

it('knows a card the gateway will refuse before it is tried', function () {
    expect(savedCard(['gateway_method_id' => 'pm_old', 'exp_month' => 1, 'exp_year' => 2020])->hasExpired())->toBeTrue()
        ->and(savedCard(['gateway_method_id' => 'pm_new'])->hasExpired())->toBeFalse();
});
