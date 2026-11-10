<?php

namespace SunuCode\AfriPay\Gateways;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SunuCode\AfriPay\Contracts\ChargesOffSession;
use SunuCode\AfriPay\Contracts\SettlesFromGateway;
use SunuCode\AfriPay\Enums\TransactionStatus;
use SunuCode\AfriPay\Exceptions\RequiresCustomerAuthentication;
use SunuCode\AfriPay\Models\PaymentMethod;
use SunuCode\AfriPay\Models\Transaction;
use SunuCode\AfriPay\Support\AmountConverter;
use SunuCode\AfriPay\Support\SignatureVerifier;

class StripeGateway extends AbstractGateway implements ChargesOffSession, SettlesFromGateway
{
    public function name(): string
    {
        return 'stripe';
    }

    public function charge(array $params): array
    {
        $amount = $params['amount'];
        $currency = strtolower($params['currency'] ?? $this->defaultCurrency());
        $reference = $params['reference'] ?? $this->generateReference('ST');
        $description = $params['description'] ?? config('app.name', 'AfriPay').' Payment';

        $stripeAmount = AmountConverter::toStripeAmount($amount, $currency);

        $sessionParams = [
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => $currency,
                    'product_data' => [
                        'name' => $description,
                    ],
                    'unit_amount' => $stripeAmount,
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'success_url' => $params['success_url'].(str_contains($params['success_url'], '?') ? '&' : '?').'session_id={CHECKOUT_SESSION_ID}&reference='.$reference,
            'cancel_url' => $params['error_url'],
            'client_reference_id' => $reference,
            'metadata' => array_merge(['reference' => $reference], $params['metadata'] ?? []),
        ];

        $response = Http::withToken($this->config['secret'])
            ->asForm()
            ->post('https://api.stripe.com/v1/checkout/sessions', $this->flattenForStripe($sessionParams));

        if ($response->failed()) {
            Log::error('AfriPay Stripe: checkout failed', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
            throw new \RuntimeException('Stripe checkout failed: '.($response->json('error.message') ?? 'Unknown error'));
        }

        $data = $response->json();

        $transaction = $this->createTransaction(array_merge($params, [
            'reference' => $reference,
            'amount' => $amount,
            'currency' => strtoupper($currency),
        ]));

        $transaction->update([
            'gateway_reference' => $data['id'] ?? null,
            'gateway_response' => $data,
        ]);

        return [
            'redirect_url' => $data['url'] ?? '',
            'transaction' => $transaction,
        ];
    }

    /**
     * checkout.session.completed with payment_status « paid » settles the payment;
     * an expired session fails it. Amount and currency are checked against the
     * transaction, in the currency's own unit (zero-decimal for XOF).
     */
    /**
     * Open a Stripe Checkout page in setup mode.
     *
     * Same endpoint as charge(), same redirect, one parameter apart: `setup`
     * authorises a card instead of charging it. Reusing the paid path means
     * the webhook, the signature check and the return page all keep working
     * as they are.
     */
    public function startSavingMethod(array $params): array
    {
        $reference = $params['reference'] ?? $this->generateReference('SM');

        $sessionParams = [
            'mode' => 'setup',
            'payment_method_types' => ['card'],
            // Sans cela, rien ne garantit qu'un Customer existe a la fin :
            // `if_required`, la valeur par defaut, ne le cree que pour les
            // abonnements et certains paiements, jamais pour un `setup`.
            // Or un prelevement hors session exige `customer` ET
            // `payment_method` : sans client, la carte est autorisee puis
            // perdue, et l'enregistrement echoue en silence.
            'customer_creation' => 'always',
            'success_url' => $params['success_url'].(str_contains($params['success_url'], '?') ? '&' : '?').'session_id={CHECKOUT_SESSION_ID}&reference='.$reference,
            'cancel_url' => $params['error_url'],
            'client_reference_id' => $reference,
            'metadata' => array_merge(['reference' => $reference], $params['metadata'] ?? []),
        ];

        $response = $this->stripe()->post('https://api.stripe.com/v1/checkout/sessions', $this->flattenForStripe($sessionParams));

        if ($response->failed()) {
            Log::error('AfriPay Stripe: setup session failed', [
                'status' => $response->status(),
                'body' => $this->safeLog((array) $response->json()),
            ]);

            throw new \RuntimeException('Stripe setup failed: '.($response->json('error.message') ?? 'Unknown error'));
        }

        return [
            'redirect_url' => (string) $response->json('url', ''),
            'reference' => $reference,
        ];
    }

    /**
     * Read the finished authorisation and keep the handles.
     *
     * The card itself never reaches us: Stripe keeps it, and what is stored
     * is the customer, the method, and the four digits a human recognises.
     *
     * Returns null when the session is not complete, so a return page opened
     * too early stores nothing. Calling it twice updates the same row rather
     * than making a second one, because the same card authorised twice is
     * still one card.
     */
    public function confirmSavedMethod(string $gatewayReference, ?Model $payable = null, ?string $expectedReference = null): ?PaymentMethod
    {
        // L'identifiant vient d'une URL publique et part dans un appel API :
        // on vérifie sa forme avant de l'interpoler, plutôt que de laisser une
        // chaîne quelconque atteindre Stripe.
        if (! preg_match('/^cs_[A-Za-z0-9_]{1,250}$/', $gatewayReference)) {
            Log::warning('AfriPay Stripe: malformed setup session id');

            return null;
        }

        $response = $this->stripe()->get("https://api.stripe.com/v1/checkout/sessions/{$gatewayReference}", [
            'expand' => ['setup_intent.payment_method'],
        ]);

        if ($response->failed()) {
            Log::error('AfriPay Stripe: setup session unreadable', [
                'status' => $response->status(),
                'body' => $this->safeLog((array) $response->json()),
            ]);

            return null;
        }

        // Le client_reference_id est pose par startSavingMethod et n'est pas
        // devinable : il est la preuve que cette session est bien celle que
        // l'appelant a ouverte. Sans ce controle, l'identifiant de session,
        // qui voyage en clair dans l'URL de retour, suffirait a revendiquer la
        // carte d'un autre.
        if ($expectedReference !== null && (string) $response->json('client_reference_id') !== $expectedReference) {
            Log::warning('AfriPay Stripe: setup session does not match the expected reference', [
                'session' => $gatewayReference,
            ]);

            return null;
        }

        $intent = (array) $response->json('setup_intent');

        if (($intent['status'] ?? null) !== 'succeeded') {
            return null;
        }

        $method = $intent['payment_method'] ?? null;
        $methodId = is_array($method) ? ($method['id'] ?? null) : $method;
        $customerId = $intent['customer'] ?? null;

        if (! is_string($methodId) || ! is_string($customerId)) {
            // Un moyen de paiement sans client ne peut pas etre preleve plus
            // tard : mieux vaut ne rien enregistrer que garder une carte
            // inutilisable qu'on croira valide au premier prelevement.
            Log::warning('AfriPay Stripe: setup without a customer', [
                'session' => $gatewayReference,
                'has_method' => is_string($methodId),
            ]);

            return null;
        }

        $card = is_array($method) ? (array) ($method['card'] ?? []) : [];

        // Une carte retirée puis réenregistrée : la ligne existe encore, en
        // suppression douce, et l'index unique porte sur (gateway,
        // gateway_method_id). updateOrCreate ignore les lignes supprimées, il
        // tenterait donc une insertion que la base refuse, et la page de
        // retour rendrait une 500 au client au lieu d'enregistrer sa carte.
        PaymentMethod::onlyTrashed()
            ->where('gateway', $this->name())
            ->where('gateway_method_id', $methodId)
            ->restore();

        return PaymentMethod::updateOrCreate(
            ['gateway' => $this->name(), 'gateway_method_id' => $methodId],
            [
                'gateway_customer_id' => $customerId,
                'brand' => $card['brand'] ?? null,
                'last4' => $card['last4'] ?? null,
                'exp_month' => $card['exp_month'] ?? null,
                'exp_year' => $card['exp_year'] ?? null,
                'payable_type' => $payable?->getMorphClass(),
                'payable_id' => $payable?->getKey(),
                'is_default' => true,
            ],
        );
    }

    /**
     * Charge a stored card with nobody watching.
     *
     * `off_session` tells the issuer the customer is away, which changes how
     * it decides. Most cards go through; some answer that they want the
     * customer anyway, and that answer is not a refusal. It gets its own
     * exception so the caller stops instead of retrying a card that was never
     * declined, and brings the customer back to confirm.
     */
    public function chargeSaved(PaymentMethod $method, array $params): Transaction
    {
        $amount = (float) $params['amount'];
        $currency = strtolower($params['currency'] ?? $this->defaultCurrency());
        $reference = $params['reference'] ?? $this->generateReference('OS');

        $transaction = $this->createTransaction(array_merge($params, [
            'reference' => $reference,
            'amount' => $amount,
            'currency' => strtoupper($currency),
        ]));

        // La cle d'idempotence est la reference : elle est stable pour cette
        // tentative, et Stripe renvoie alors le meme PaymentIntent au lieu
        // d'en creer un second. Sans elle, un timeout survenu apres le debit
        // laisse l'appelant croire a un echec, et la tentative suivante
        // debite une seconde fois.
        $response = $this->stripe()
            ->withHeaders(['Idempotency-Key' => $reference])
            ->post('https://api.stripe.com/v1/payment_intents', $this->flattenForStripe([
                'amount' => AmountConverter::toStripeAmount($amount, $currency),
                'currency' => $currency,
                'customer' => $method->gateway_customer_id,
                'payment_method' => $method->gateway_method_id,
                'off_session' => 'true',
                'confirm' => 'true',
                'description' => $params['description'] ?? config('app.name', 'AfriPay').' usage',
                'metadata' => array_merge(['reference' => $reference], $params['metadata'] ?? []),
            ]));

        $body = (array) $response->json();

        $transaction->update(['gateway_reference' => $body['id'] ?? ($body['error']['payment_intent']['id'] ?? null)]);

        if ($response->successful() && ($body['status'] ?? null) === 'succeeded') {
            // Le montant que Stripe confirme, pas celui qu'on lui a demandé.
            // Passer $amount revenait a comparer la transaction a elle-meme :
            // le controle existait mais ne pouvait jamais echouer.
            $encaisse = isset($body['amount_received']) || isset($body['amount'])
                ? AmountConverter::fromStripeAmount((int) ($body['amount_received'] ?? $body['amount']), $currency)
                : null;

            return $this->completeTransaction($transaction, $body, $encaisse);
        }

        if (($body['error']['code'] ?? null) === 'authentication_required') {
            // La transaction reste `pending`, et c'est tout l'enjeu : le
            // paiement n'est pas fini, il attend le client. La marquer en
            // echec ici la rendrait inreglable, parce que transition() ignore
            // tout ce qui n'est plus `pending` : le webhook
            // `payment_intent.succeeded` qui suit la confirmation 3DS ne
            // reglerait rien, l'ardoise resterait entiere, et le client serait
            // debite une seconde fois au declencheur suivant.
            throw new RequiresCustomerAuthentication(
                $transaction,
                $body['error']['payment_intent']['next_action']['redirect_to_url']['url'] ?? null,
            );
        }

        $failed = $this->failTransaction($transaction, $body);

        Log::warning('AfriPay Stripe: off-session charge refused', [
            'reference' => $reference,
            'code' => $body['error']['code'] ?? null,
        ]);

        return $failed;
    }

    public function detachSavedMethod(PaymentMethod $method): bool
    {
        // Le contrat promet un booléen, jamais une exception : le client a
        // demandé le retrait de sa carte, et une passerelle injoignable ne
        // doit pas l'en empêcher. L'échec se signale par la valeur de retour,
        // et l'appelant décide quoi en faire.
        try {
            $response = $this->stripe()->post(
                "https://api.stripe.com/v1/payment_methods/{$method->gateway_method_id}/detach"
            );
        } catch (\Throwable $e) {
            Log::warning('AfriPay Stripe: payment method detach unreachable', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        if ($response->successful()) {
            return true;
        }

        // Déjà détachée, ou inconnue : le but est atteint.
        if ($response->status() === 404 || ($response->json('error.code') === 'resource_missing')) {
            return true;
        }

        Log::warning('AfriPay Stripe: payment method not detached', [
            'status' => $response->status(),
            'body' => $this->safeLog((array) $response->json()),
        ]);

        return false;
    }

    /**
     * Le client HTTP de Stripe, monte une fois.
     */
    private function stripe(): PendingRequest
    {
        return Http::withToken($this->config['secret'])->asForm();
    }

    public function handleWebhook(array $data): ?Transaction
    {
        $object = $data['data']['object'] ?? [];
        $reference = $object['client_reference_id'] ?? $object['metadata']['reference'] ?? null;

        if (! $reference) {
            return null;
        }

        $remote = $this->readSession($object);

        if ($remote['status'] === TransactionStatus::Pending) {
            return null;
        }

        return $this->transition($reference, $remote['status'], $data, $remote['amount'], $remote['currency']);
    }

    protected function fetchStatus(Transaction $transaction): ?array
    {
        if (! $transaction->gateway_reference) {
            return null;
        }

        // La reference pointe soit une session Checkout, soit un PaymentIntent
        // pour un prelevement hors session. Interroger le mauvais endpoint rend
        // 404, et la transaction resterait en attente pour toujours.
        $endpoint = str_starts_with($transaction->gateway_reference, 'pi_')
            ? 'payment_intents'
            : 'checkout/sessions';

        $response = Http::withToken($this->config['secret'])
            ->get("https://api.stripe.com/v1/{$endpoint}/{$transaction->gateway_reference}");

        return $response->successful() ? $this->readSession($response->json()) : null;
    }

    /**
     * @return array{status: TransactionStatus, amount: ?float, currency: ?string, data: array}
     */
    /**
     * Read a Stripe object, whichever kind it is.
     *
     * Two shapes reach this method. A Checkout Session says `payment_status`
     * and `amount_total`; a PaymentIntent says `status` and `amount`. Off-session
     * charges produce the second, and a session reader returns « pending » for
     * it: a payment confirmed after strong authentication would then never be
     * settled, because the webhook that announces it would be ignored.
     */
    private function readSession(array $object): array
    {
        $currency = $object['currency'] ?? null;
        $estIntent = ($object['object'] ?? null) === 'payment_intent' || str_starts_with((string) ($object['id'] ?? ''), 'pi_');

        $montant = match (true) {
            isset($object['amount_total']) => (int) $object['amount_total'],
            $estIntent && isset($object['amount_received']) && (int) $object['amount_received'] > 0 => (int) $object['amount_received'],
            $estIntent && isset($object['amount']) => (int) $object['amount'],
            default => null,
        };

        return [
            'status' => match (true) {
                ($object['payment_status'] ?? null) === 'paid' => TransactionStatus::Completed,
                $estIntent && ($object['status'] ?? null) === 'succeeded' => TransactionStatus::Completed,
                // `requires_action` reste en attente : le client peut encore
                // confirmer. `canceled` et `requires_payment_method` sont des
                // fins de partie.
                $estIntent && in_array($object['status'] ?? null, ['canceled', 'requires_payment_method'], true) => TransactionStatus::Failed,
                ($object['status'] ?? null) === 'expired' => TransactionStatus::Failed,
                default => TransactionStatus::Pending,
            },
            'amount' => $montant !== null && $currency !== null
                ? AmountConverter::fromStripeAmount($montant, $currency)
                : null,
            'currency' => $currency,
            'data' => $object,
        ];
    }

    public function verifySignature(string $signature, string $rawBody): bool
    {
        $secret = $this->config['webhook_secret'] ?? '';
        if (! $secret) {
            return false;
        }

        return SignatureVerifier::verifyStripeSignature($rawBody, $signature, $secret);
    }

    /**
     * Flatten nested array for Stripe's form-encoded API.
     */
    private function flattenForStripe(array $params, string $prefix = ''): array
    {
        $result = [];

        foreach ($params as $key => $value) {
            $newKey = $prefix ? "{$prefix}[{$key}]" : $key;

            if (is_array($value)) {
                $result = array_merge($result, $this->flattenForStripe($value, $newKey));
            } else {
                $result[$newKey] = $value;
            }
        }

        return $result;
    }
}
