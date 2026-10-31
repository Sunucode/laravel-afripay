<?php

namespace SunuCode\AfriPay\Gateways;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SunuCode\AfriPay\Contracts\SettlesFromGateway;
use SunuCode\AfriPay\Enums\TransactionStatus;
use SunuCode\AfriPay\Models\Transaction;
use SunuCode\AfriPay\Support\AmountConverter;
use SunuCode\AfriPay\Support\SignatureVerifier;

class StripeGateway extends AbstractGateway implements SettlesFromGateway
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
