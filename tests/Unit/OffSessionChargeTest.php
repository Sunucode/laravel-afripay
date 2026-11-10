<?php

use Illuminate\Support\Facades\Http;
use SunuCode\AfriPay\Support\AmountConverter;
use Illuminate\Support\Facades\Event;
use SunuCode\AfriPay\Enums\TransactionStatus;
use SunuCode\AfriPay\Events\PaymentCompleted;
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
        // `customer_creation` est indispensable : sans client, la carte est
        // autorisée puis perdue, et un prélèvement hors session exige
        // `customer` autant que `payment_method`.
        return $request['mode'] === 'setup'
            && $request['customer_creation'] === 'always'
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

it('stores nothing when the gateway kept no customer', function () {
    // Un moyen de paiement sans client ne peut pas etre preleve plus tard.
    Http::fake(['api.stripe.com/v1/checkout/sessions/*' => Http::response([
        'id' => 'cs_setup_1',
        'setup_intent' => [
            'id' => 'seti_1', 'status' => 'succeeded', 'customer' => null,
            'payment_method' => ['id' => 'pm_123', 'card' => ['brand' => 'visa', 'last4' => '4242']],
        ],
    ])]);

    expect(app(PaymentManager::class)->confirmSavedMethod('cs_setup_1', null, 'stripe'))->toBeNull()
        ->and(PaymentMethod::count())->toBe(0);
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

it('refuses a session whose reference is not the expected one', function () {
    // L'identifiant de session voyage dans l'URL de retour : il atteint
    // l'historique du navigateur et les journaux. Sans ce contrôle, le
    // rejouer suffisait à revendiquer la carte qu'il avait autorisée.
    Http::fake(['api.stripe.com/v1/checkout/sessions/*' => Http::response(
        array_merge(setupSession(), ['client_reference_id' => 'SM-DE-QUELQU-UN-DAUTRE'])
    )]);

    $method = app(PaymentManager::class)->confirmSavedMethod('cs_setup_1', null, 'stripe', 'SM-LA-MIENNE');

    expect($method)->toBeNull()
        ->and(PaymentMethod::count())->toBe(0);
});

it('keeps the card when the reference is the expected one', function () {
    Http::fake(['api.stripe.com/v1/checkout/sessions/*' => Http::response(
        array_merge(setupSession(), ['client_reference_id' => 'SM-LA-MIENNE'])
    )]);

    $method = app(PaymentManager::class)->confirmSavedMethod('cs_setup_1', null, 'stripe', 'SM-LA-MIENNE');

    expect($method)->not->toBeNull()
        ->and($method->gateway_method_id)->toBe('pm_123');
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
        // `pending`, et non `failed` : le paiement n'est pas refusé, il attend
        // le client. C'est `transition()` qui l'impose, puisqu'il ignore tout
        // ce qui n'est plus `pending` : marquée en échec, la transaction
        // n'aurait plus jamais pu être réglée par le webhook qui suit la
        // confirmation 3DS. Le client payait, son ardoise restait entière, et
        // le déclencheur suivant le débitait une seconde fois.
        expect($e->redirectUrl)->toBe('https://stripe.test/3ds')
            ->and($e->transaction->status)->toBe(TransactionStatus::Pending)
            ->and($e->transaction->gateway_reference)->toBe('pi_auth');
    }
});

it('settles a 3DS payment from the webhook that follows it', function () {
    Http::fake(['api.stripe.com/v1/payment_intents' => Http::response([
        'error' => [
            'code' => 'authentication_required',
            'payment_intent' => [
                'id' => 'pi_sca',
                'next_action' => ['redirect_to_url' => ['url' => 'https://stripe.test/3ds']],
            ],
        ],
    ], 402)]);

    $manager = app(PaymentManager::class);

    try {
        $manager->chargeSaved(savedCard(), [
            'amount' => 12000.0,
            'currency' => 'XOF',
            'reference' => 'OS-SCA-1',
        ]);
    } catch (RequiresCustomerAuthentication) {
        // Attendu : le client doit confirmer chez sa banque.
    }

    // Ce que Stripe envoie une fois le client revenu de la page 3DS.
    $settled = $manager->handleWebhook('stripe', [
        'type' => 'payment_intent.succeeded',
        'data' => ['object' => [
            'id' => 'pi_sca',
            'object' => 'payment_intent',
            'status' => 'succeeded',
            // XOF n'a pas de sous-unité : le montant Stripe est le montant.
            'amount' => 12000,
            'currency' => 'xof',
            'metadata' => ['reference' => 'OS-SCA-1'],
        ]],
    ]);

    expect($settled)->not->toBeNull()
        ->and($settled->status)->toBe(TransactionStatus::Completed);
});

it('carries an idempotency key so a timeout cannot charge twice', function () {
    Http::fake(['api.stripe.com/v1/payment_intents' => Http::response([
        'id' => 'pi_idem',
        'status' => 'succeeded',
        'amount' => 1200000,
        'currency' => 'xof',
    ], 200)]);

    app(PaymentManager::class)->chargeSaved(savedCard(), [
        'amount' => 12000.0,
        'currency' => 'XOF',
        'reference' => 'OS-IDEM-1',
    ]);

    Http::assertSent(fn ($request) => $request->hasHeader('Idempotency-Key', 'OS-IDEM-1'));
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

// --- Le webhook d'un prelevement hors session -----------------------------

/*
 * Un prelevement hors session produit un PaymentIntent, pas une session
 * Checkout. Un lecteur de session rend « en attente » pour un intent : un
 * paiement confirme apres authentification forte ne serait alors jamais
 * regle, parce que le webhook qui l'annonce serait ignore.
 */
function pendingOffSession(string $reference = 'OS-1', string $intentId = 'pi_1'): \SunuCode\AfriPay\Models\Transaction
{
    return \SunuCode\AfriPay\Models\Transaction::create([
        'gateway' => 'stripe',
        'reference' => $reference,
        'gateway_reference' => $intentId,
        'amount' => 120.0,
        'currency' => 'USD',
        'status' => TransactionStatus::Pending,
    ]);
}

it('settles an off-session charge confirmed later by webhook', function () {
    pendingOffSession();

    $transaction = app(PaymentManager::class)->handleWebhook('stripe', [
        'type' => 'payment_intent.succeeded',
        'data' => ['object' => [
            'id' => 'pi_1',
            'object' => 'payment_intent',
            'status' => 'succeeded',
            'amount' => 12000,
            'amount_received' => 12000,
            'currency' => 'usd',
            'metadata' => ['reference' => 'OS-1'],
        ]],
    ]);

    expect($transaction)->not->toBeNull()
        ->and($transaction->status)->toBe(TransactionStatus::Completed);
});

it('leaves an intent awaiting the customer in pending', function () {
    pendingOffSession('OS-2', 'pi_2');

    $transaction = app(PaymentManager::class)->handleWebhook('stripe', [
        'type' => 'payment_intent.requires_action',
        'data' => ['object' => [
            'id' => 'pi_2',
            'object' => 'payment_intent',
            'status' => 'requires_action',
            'amount' => 12000,
            'currency' => 'usd',
            'metadata' => ['reference' => 'OS-2'],
        ]],
    ]);

    // Le client peut encore confirmer : rien ne doit etre decide ici.
    expect($transaction)->toBeNull();
});

it('fails an intent the customer never completed', function () {
    pendingOffSession('OS-3', 'pi_3');

    $transaction = app(PaymentManager::class)->handleWebhook('stripe', [
        'type' => 'payment_intent.canceled',
        'data' => ['object' => [
            'id' => 'pi_3',
            'object' => 'payment_intent',
            'status' => 'canceled',
            'amount' => 12000,
            'currency' => 'usd',
            'metadata' => ['reference' => 'OS-3'],
        ]],
    ]);

    expect($transaction->status)->toBe(TransactionStatus::Failed);
});

it('asks the right endpoint when verifying an off-session charge', function () {
    Http::fake(['api.stripe.com/v1/payment_intents/pi_4' => Http::response([
        'id' => 'pi_4', 'object' => 'payment_intent', 'status' => 'succeeded',
        'amount' => 12000, 'currency' => 'usd',
    ])]);

    // Une reference pi_ interrogee sur checkout/sessions rend 404, et la
    // transaction resterait en attente pour toujours.
    app(PaymentManager::class)->verify(pendingOffSession('OS-4', 'pi_4'));

    Http::assertSent(fn ($request) => str_contains($request->url(), '/payment_intents/pi_4'));
});

it('refuses a malformed session id before calling the gateway', function () {
    Http::fake();

    expect(app(PaymentManager::class)->confirmSavedMethod('../../admin', null, 'stripe'))->toBeNull();

    Http::assertNothingSent();
});

it('brings back a card that was removed and authorised again', function () {
    Http::fake(['api.stripe.com/v1/checkout/sessions/*' => Http::response(setupSession())]);

    $first = app(PaymentManager::class)->confirmSavedMethod('cs_setup_1', null, 'stripe');
    $first->delete();

    // Le retrait est une suppression douce, et l'index unique porte sur
    // (gateway, gateway_method_id) : sans restauration, updateOrCreate
    // tenterait une insertion que la base refuse, et la page de retour
    // rendrait une 500 au lieu d'enregistrer la carte.
    $again = app(PaymentManager::class)->confirmSavedMethod('cs_setup_1', null, 'stripe');

    expect($again)->not->toBeNull()
        ->and($again->id)->toBe($first->id)
        ->and(PaymentMethod::count())->toBe(1);
});

it('detaches the card at the gateway when it is forgotten', function () {
    Http::fake(['api.stripe.com/v1/payment_methods/*/detach' => Http::response(['id' => 'pm_123'])]);

    $method = savedCard();

    expect(app(PaymentManager::class)->forgetSavedMethod($method))->toBeTrue()
        ->and(PaymentMethod::find($method->id))->toBeNull();

    // Supprimer la ligne ne suffit pas : la carte resterait attachee au client
    // chez Stripe, donc visible et prelevable, alors qu'on annonce au client
    // que le prelevement automatique est coupe.
    Http::assertSent(fn ($r) => str_contains($r->url(), '/payment_methods/pm_123/detach'));
});

it('emits PaymentCompleted after a successful off-session charge', function () {
    Event::fake();
    Http::fake(['api.stripe.com/v1/payment_intents' => Http::response([
        'id' => 'pi_ev', 'status' => 'succeeded', 'amount' => 12000, 'currency' => 'xof',
    ])]);

    app(PaymentManager::class)->chargeSaved(savedCard(), ['amount' => 12000.0, 'currency' => 'XOF']);

    Event::assertDispatched(PaymentCompleted::class);
});

it('refuses a settlement whose amount is not the one charged', function () {
    // Le controle comparait la transaction a elle-meme : il ne pouvait pas
    // echouer. Il porte desormais sur ce que Stripe confirme.
    Http::fake(['api.stripe.com/v1/payment_intents' => Http::response([
        'id' => 'pi_short', 'status' => 'succeeded', 'amount' => 9000, 'currency' => 'xof',
    ])]);

    $transaction = app(PaymentManager::class)->chargeSaved(savedCard(), ['amount' => 12000.0, 'currency' => 'XOF']);

    expect($transaction->status)->toBe(TransactionStatus::Failed);
});

it('does not accept a dollar of difference on a usd settlement', function () {
    // Une tolerance plate de 1 valait un dollar entier, soit environ 600 F
    // aux taux employes ici, accepte en silence sur un reglement.
    expect(AmountConverter::amountsMatch(10.00, 9.50, null, 'usd'))->toBeFalse()
        ->and(AmountConverter::amountsMatch(10.00, 10.00, null, 'usd'))->toBeTrue()
        ->and(AmountConverter::amountsMatch(12000.0, 11999.0, null, 'xof'))->toBeTrue();
});
