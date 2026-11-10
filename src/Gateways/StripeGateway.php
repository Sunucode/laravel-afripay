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
    public function confirmSavedMethod(string $gatewayReference, ?Model $payable = null): ?PaymentMethod
    {
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

        $intent = (array) $response->json('setup_intent');

        if (($intent['status'] ?? null) !== 'succeeded') {
            return null;
        }

        $method = $intent['payment_method'] ?? null;
        $methodId = is_array($method) ? ($method['id'] ?? null) : $method;
        $customerId = $intent['customer'] ?? null;

        if (! is_string($methodId) || ! is_string($customerId)) {
            return null;
        }

        $card = is_array($method) ? (array) ($method['card'] ?? []) : [];

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

        $response = $this->stripe()->post('https://api.stripe.com/v1/payment_intents', $this->flattenForStripe([
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
            return $this->completeTransaction($transaction, $body, $amount);
        }

        $failed = $this->failTransaction($transaction, $body);

        if (($body['error']['code'] ?? null) === 'authentication_required') {
            throw new RequiresCustomerAuthentication(
                $failed,
                $body['error']['payment_intent']['next_action']['redirect_to_url']['url'] ?? null,
            );
        }

        Log::warning('AfriPay Stripe: off-session charge refused', [
            'reference' => $reference,
            'code' => $body['error']['code'] ?? null,
        ]);

        return $failed;
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

        $response = Http::withToken($this->config['secret'])
            ->get("https://api.stripe.com/v1/checkout/sessions/{$transaction->gateway_reference}");

        return $response->successful() ? $this->readSession($response->json()) : null;
    }

    /**
     * @return array{status: TransactionStatus, amount: ?float, currency: ?string, data: array}
     */
    private function readSession(array $session): array
    {
        $currency = $session['currency'] ?? null;

        return [
            'status' => match (true) {
                ($session['payment_status'] ?? null) === 'paid' => TransactionStatus::Completed,
                ($session['status'] ?? null) === 'expired' => TransactionStatus::Failed,
                default => TransactionStatus::Pending,
            },
            'amount' => isset($session['amount_total']) && $currency !== null
                ? AmountConverter::fromStripeAmount((int) $session['amount_total'], $currency)
                : null,
            'currency' => $currency,
            'data' => $session,
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
