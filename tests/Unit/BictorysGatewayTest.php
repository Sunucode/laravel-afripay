<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use SunuCode\AfriPay\Enums\TransactionStatus;
use SunuCode\AfriPay\Events\PaymentCompleted;
use SunuCode\AfriPay\Events\PaymentFailed;
use SunuCode\AfriPay\Gateways\BictorysGateway;
use SunuCode\AfriPay\Models\Transaction;
use SunuCode\AfriPay\PaymentManager;
use SunuCode\AfriPay\Tests\TestCase;

uses(TestCase::class);

/*
 * Bictorys, page hébergée : l'ouverture du paiement, le webhook qui le règle
 * une fois, et le refus de régler ce qu'on ne peut pas vérifier.
 */

function bictorysPending(string $reference, string $gatewayReference = 'bic_tx_1', float $amount = 1000, string $currency = 'XOF'): Transaction
{
    return Transaction::create([
        'gateway' => 'bictorys',
        'reference' => $reference,
        'gateway_reference' => $gatewayReference,
        'amount' => $amount,
        'currency' => $currency,
        'status' => TransactionStatus::Pending,
    ]);
}

function bictorysPayload(string $reference, string $status = 'succeeded', mixed $amount = 1000, string $currency = 'XOF'): array
{
    return [
        'id' => 'bic_tx_1',
        'merchantId' => 'm_1',
        'type' => 'payment',
        'amount' => $amount,
        'currency' => $currency,
        'paymentReference' => $reference,
        'merchantReference' => $reference,
        'pspName' => 'wave_money',
        'status' => $status,
        'timestamp' => '2026-11-18T10:00:00Z',
    ];
}

function postBictorys(array $payload, string $secret = 'test_bictorys_secret', bool $hmac = true)
{
    $body = json_encode($payload);
    $headers = ['CONTENT_TYPE' => 'application/json'];

    if ($hmac) {
        $millisecondes = (string) (int) (microtime(true) * 1000);
        $headers['HTTP_X_WEBHOOK_TIMESTAMP'] = $millisecondes;
        $headers['HTTP_X_WEBHOOK_SIGNATURE'] = hash_hmac('sha256', $millisecondes.'.'.$body, $secret);
    } else {
        $headers['HTTP_X_SECRET_KEY'] = $secret;
    }

    return test()->call('POST', '/afripay/webhooks/bictorys', [], [], [], $headers, $body);
}

it('ouvre le paiement avec le corps attendu par Bictorys', function () {
    Http::fake(['api.test.bictorys.com/pay/v1/charges' => Http::response([
        'transactionId' => 'bic_tx_42',
        'redirectUrl' => 'https://pay.bictorys.com/checkout/bic_tx_42',
        'qrCode' => 'data:image/png;base64,'.str_repeat('A', 3000),
    ], 201)]);

    $result = app(PaymentManager::class)->via('bictorys')->charge([
        'amount' => 1499,
        'reference' => 'CMD-26-9051',
        'success_url' => 'https://samabac.sn/retour',
        'error_url' => 'https://samabac.sn/erreur',
        'metadata' => ['customer' => ['name' => 'Amadou Fall', 'phone' => '+221 77 123 45 67']],
    ]);

    expect($result['redirect_url'])->toBe('https://pay.bictorys.com/checkout/bic_tx_42');

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $request->hasHeader('X-Api-Key', 'test_public_key')
            && $body['amount'] === 1499
            && $body['currency'] === 'XOF'
            && $body['country'] === 'SN'
            && $body['paymentReference'] === 'CMD-26-9051'
            && $body['successRedirectUrl'] === 'https://samabac.sn/retour'
            // La majuscule de leur API : sans elle, l'URL d'échec est perdue.
            && $body['ErrorRedirectUrl'] === 'https://samabac.sn/erreur'
            && $body['customerObject']['phone'] === '+221771234567';
    });
});

it('garde le transactionId et jette le QR code', function () {
    Http::fake(['api.test.bictorys.com/pay/v1/charges' => Http::response([
        'transactionId' => 'bic_tx_42',
        'redirectUrl' => 'https://pay.bictorys.com/checkout/bic_tx_42',
        'qrCode' => 'data:image/png;base64,'.str_repeat('A', 3000),
    ], 201)]);

    $transaction = app(PaymentManager::class)->via('bictorys')->charge([
        'amount' => 1000,
        'success_url' => 'https://exemple.test/ok',
        'error_url' => 'https://exemple.test/ko',
    ])['transaction'];

    expect($transaction->gateway_reference)->toBe('bic_tx_42')
        ->and($transaction->gateway_response)->not->toHaveKey('qrCode')
        ->and($transaction->status)->toBe(TransactionStatus::Pending);
});

it('n envoie pas un telephone inutilisable', function () {
    Http::fake(['api.test.bictorys.com/pay/v1/charges' => Http::response([
        'transactionId' => 'bic_tx_43',
        'redirectUrl' => 'https://pay.bictorys.com/checkout/bic_tx_43',
    ], 201)]);

    app(PaymentManager::class)->via('bictorys')->charge([
        'amount' => 1000,
        'success_url' => 'https://exemple.test/ok',
        'error_url' => 'https://exemple.test/ko',
        // Sans indicatif : Bictorys refuse tout l'appel. On ne devine pas le pays.
        'metadata' => ['customer' => ['name' => 'Awa', 'phone' => '771234567']],
    ]);

    Http::assertSent(fn ($request) => $request->data()['customerObject'] === ['name' => 'Awa']);
});

it('refuse d ouvrir un paiement sans cle publique', function () {
    config(['afripay.gateways.bictorys.api_key' => '']);

    app(PaymentManager::class)->via('bictorys')->charge([
        'amount' => 1000,
        'success_url' => 'https://exemple.test/ok',
        'error_url' => 'https://exemple.test/ko',
    ]);
})->throws(RuntimeException::class, 'BICTORYS_API_KEY');

it('regle le paiement sur un webhook signe, une seule fois', function () {
    Event::fake([PaymentCompleted::class, PaymentFailed::class]);
    bictorysPending('CMD-1');

    postBictorys(bictorysPayload('CMD-1'))->assertOk()->assertJson(['status' => 'ok']);

    expect(Transaction::where('reference', 'CMD-1')->first()->status)->toBe(TransactionStatus::Completed);
    Event::assertDispatchedTimes(PaymentCompleted::class, 1);

    // Bictorys réessaie trois fois : le rejeu ne règle ni n'annonce rien.
    postBictorys(bictorysPayload('CMD-1'))->assertOk()->assertJson(['status' => 'ignored']);
    Event::assertDispatchedTimes(PaymentCompleted::class, 1);
});

it('accepte la cle statique quand le HMAC n est pas envoye', function () {
    bictorysPending('CMD-2');

    postBictorys(bictorysPayload('CMD-2'), hmac: false)->assertOk()->assertJson(['status' => 'ok']);

    expect(Transaction::where('reference', 'CMD-2')->first()->status)->toBe(TransactionStatus::Completed);
});

it('refuse un webhook mal signe', function () {
    bictorysPending('CMD-3');

    postBictorys(bictorysPayload('CMD-3'), secret: 'mauvais_secret')->assertStatus(403);

    expect(Transaction::where('reference', 'CMD-3')->first()->status)->toBe(TransactionStatus::Pending);
});

it('traite authorized comme un succes et laisse pending en attente', function () {
    bictorysPending('CMD-4');
    bictorysPending('CMD-5');

    postBictorys(bictorysPayload('CMD-4', 'authorized'))->assertOk();
    postBictorys(bictorysPayload('CMD-5', 'processing'))->assertOk()->assertJson(['status' => 'ignored']);

    expect(Transaction::where('reference', 'CMD-4')->first()->status)->toBe(TransactionStatus::Completed)
        ->and(Transaction::where('reference', 'CMD-5')->first()->status)->toBe(TransactionStatus::Pending);
});

it('fait echouer un paiement annule', function () {
    Event::fake([PaymentCompleted::class, PaymentFailed::class]);
    bictorysPending('CMD-6');

    postBictorys(bictorysPayload('CMD-6', 'cancelled'))->assertOk();

    expect(Transaction::where('reference', 'CMD-6')->first()->status)->toBe(TransactionStatus::Failed);
    Event::assertDispatchedTimes(PaymentFailed::class, 1);
});

it('fait echouer un paiement dont le montant ne correspond pas', function () {
    bictorysPending('CMD-7', amount: 1499);

    postBictorys(bictorysPayload('CMD-7', 'succeeded', 100))->assertOk();

    expect(Transaction::where('reference', 'CMD-7')->first()->status)->toBe(TransactionStatus::Failed);
});

it('ne regle pas depuis la page de retour un statut paye sans montant', function () {
    $transaction = bictorysPending('CMD-8');

    Http::fake(['api.test.bictorys.com/pay/v1/transactions/bic_tx_1/status' => Http::response([
        'status' => 'succeeded',
    ])]);

    $regle = app(PaymentManager::class)->gateway('bictorys')->settle($transaction);

    expect($regle)->toBeNull()
        ->and($transaction->fresh()->status)->toBe(TransactionStatus::Pending);
});

it('regle depuis la page de retour quand le montant est donne', function () {
    $transaction = bictorysPending('CMD-9', amount: 1499);

    Http::fake(['api.test.bictorys.com/pay/v1/transactions/bic_tx_1/status' => Http::response([
        'status' => 'succeeded',
        'amount' => 1499,
        'currency' => 'XOF',
    ])]);

    $regle = app(PaymentManager::class)->gateway('bictorys')->settle($transaction);

    expect($regle?->status)->toBe(TransactionStatus::Completed);
});

it('ramene les en-tetes Bictorys a une seule chaine de signature', function () {
    $requete = Illuminate\Http\Request::create('/afripay/webhooks/bictorys', 'POST', server: [
        'HTTP_X_WEBHOOK_TIMESTAMP' => '1763462400000',
        'HTTP_X_WEBHOOK_SIGNATURE' => 'abc123',
        'HTTP_X_SECRET_KEY' => 'statique',
    ]);

    expect(BictorysGateway::signatureFrom($requete))->toBe('t=1763462400000,v1=abc123');

    $sansHmac = Illuminate\Http\Request::create('/afripay/webhooks/bictorys', 'POST', server: [
        'HTTP_X_SECRET_KEY' => 'statique',
    ]);

    expect(BictorysGateway::signatureFrom($sansHmac))->toBe('statique');
});

it('ne peut pas prelever hors session', function () {
    expect(app(PaymentManager::class)->canChargeOffSession('bictorys'))->toBeFalse();
});
