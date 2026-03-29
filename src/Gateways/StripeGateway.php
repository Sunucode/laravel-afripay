<?php

namespace SunuCode\AfriPay\Gateways;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SunuCode\AfriPay\Enums\TransactionStatus;
use SunuCode\AfriPay\Models\Transaction;
use SunuCode\AfriPay\Support\AmountConverter;
use SunuCode\AfriPay\Support\SignatureVerifier;

class StripeGateway extends AbstractGateway
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

    public function handleWebhook(array $data): ?Transaction
    {
        $eventType = $data['type'] ?? '';
        $object = $data['data']['object'] ?? [];

        $reference = $object['client_reference_id'] ?? $object['metadata']['reference'] ?? null;
        $sessionId = ($eventType === 'checkout.session.completed') ? ($object['id'] ?? null) : null;

        return DB::transaction(function () use ($data, $object, $sessionId, $reference) {
            $query = Transaction::where('gateway', 'stripe')
                ->where('status', TransactionStatus::Pending);

            if ($sessionId && $reference) {
                $query->where(function ($q) use ($sessionId, $reference) {
                    $q->where('gateway_reference', $sessionId)
                        ->orWhere('reference', $reference);
                });
            } elseif ($sessionId) {
                $query->where('gateway_reference', $sessionId);
            } elseif ($reference) {
                $query->where('reference', $reference);
            } else {
                return null;
            }

            $transaction = $query->lockForUpdate()->first();

            if (! $transaction) {
                return null;
            }

            // Verify amount
            $stripeAmountTotal = (float) ($object['amount_total'] ?? 0);
            $paidAmount = null;
            if ($stripeAmountTotal > 0) {
                $cur = strtolower($object['currency'] ?? $transaction->currency ?? 'xof');
                $paidAmount = AmountConverter::fromStripeAmount((int) $stripeAmountTotal, $cur);
            }

            $paymentStatus = $object['payment_status'] ?? $object['status'] ?? 'unknown';

            $newStatus = match ($paymentStatus) {
                'paid', 'succeeded', 'complete' => TransactionStatus::Completed,
                'failed', 'canceled', 'expired' => TransactionStatus::Failed,
                default => TransactionStatus::Pending,
            };

            if ($newStatus === TransactionStatus::Completed) {
                return $this->completeTransaction($transaction, $data, $paidAmount);
            }

            if ($newStatus === TransactionStatus::Failed) {
                return $this->failTransaction($transaction, $data);
            }

            return $transaction;
        });
    }

    public function verify(Transaction $transaction): Transaction
    {
        if (! $transaction->gateway_reference || $transaction->status !== TransactionStatus::Pending) {
            return $transaction;
        }

        $response = Http::withToken($this->config['secret'])
            ->get("https://api.stripe.com/v1/checkout/sessions/{$transaction->gateway_reference}");

        if ($response->successful()) {
            $data = $response->json();
            $newStatus = match ($data['payment_status'] ?? 'unknown') {
                'paid' => TransactionStatus::Completed,
                default => TransactionStatus::Pending,
            };

            $transaction->update([
                'status' => $newStatus,
                'gateway_response' => $data,
            ]);
        }

        return $transaction->fresh();
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
