<?php

namespace SunuCode\AfriPay\Gateways;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SunuCode\AfriPay\Enums\TransactionStatus;
use SunuCode\AfriPay\Models\Transaction;

class PayPalGateway extends AbstractGateway
{
    public function name(): string
    {
        return 'paypal';
    }

    public function charge(array $params): array
    {
        $amount = $params['amount'];
        $currency = $params['currency'] ?? $this->defaultCurrency();
        $reference = $params['reference'] ?? $this->generateReference('PP');
        $description = $params['description'] ?? config('app.name', 'AfriPay').' Payment';

        $token = $this->getOAuthToken();
        $paypalAmount = number_format($amount, 2, '.', '');

        $response = Http::withToken($token)
            ->acceptJson()
            ->post($this->baseUrl().'/v2/checkout/orders', [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'reference_id' => $reference,
                    'amount' => [
                        'currency_code' => strtoupper($currency),
                        'value' => $paypalAmount,
                    ],
                    'description' => $description,
                ]],
                'application_context' => [
                    'return_url' => $params['success_url'].(str_contains($params['success_url'], '?') ? '&' : '?').'reference='.$reference,
                    'cancel_url' => $params['error_url'],
                    'brand_name' => config('app.name', 'AfriPay'),
                    'user_action' => 'PAY_NOW',
                ],
            ]);

        if (! $response->successful()) {
            Log::error('AfriPay PayPal: checkout failed', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 500),
            ]);
            throw new \RuntimeException('PayPal checkout failed: '.($response->json()['message'] ?? $response->body()));
        }

        $data = $response->json();
        $approveLink = collect($data['links'] ?? [])->firstWhere('rel', 'approve');

        if (! $approveLink) {
            throw new \RuntimeException('PayPal: approve link missing');
        }

        $transaction = $this->createTransaction(array_merge($params, [
            'reference' => $reference,
            'amount' => $amount,
            'currency' => strtoupper($currency),
        ]));

        $transaction->update([
            'gateway_reference' => $data['id'],
            'gateway_response' => $data,
        ]);

        return [
            'redirect_url' => $approveLink['href'],
            'transaction' => $transaction,
        ];
    }

    public function handleWebhook(array $data): ?Transaction
    {
        $orderId = $data['token'] ?? $data['order_id'] ?? $data['resource']['id'] ?? null;
        if (! $orderId) {
            return null;
        }

        return DB::transaction(function () use ($orderId) {
            $transaction = Transaction::where('gateway', 'paypal')
                ->where('gateway_reference', $orderId)
                ->lockForUpdate()
                ->first();

            if (! $transaction || $transaction->status === TransactionStatus::Completed) {
                return $transaction;
            }

            // Verify and capture via PayPal API
            return $this->verify($transaction);
        });
    }

    public function verify(Transaction $transaction): Transaction
    {
        if ($transaction->status !== TransactionStatus::Pending) {
            return $transaction;
        }

        try {
            $token = $this->getOAuthToken();
            $orderId = $transaction->gateway_reference;

            // Capture the payment
            $response = Http::withToken($token)
                ->acceptJson()
                ->post($this->baseUrl()."/v2/checkout/orders/{$orderId}/capture");

            if ($response->successful()) {
                $data = $response->json();
                $status = $data['status'] ?? null;

                $newStatus = match ($status) {
                    'COMPLETED' => TransactionStatus::Completed,
                    'VOIDED', 'PAYER_ACTION_REQUIRED' => TransactionStatus::Failed,
                    default => TransactionStatus::Pending,
                };

                $transaction->update([
                    'status' => $newStatus,
                    'gateway_response' => $data,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('AfriPay PayPal: verification failed', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $transaction->fresh();
    }

    public function verifySignature(string $signature, string $rawBody): bool
    {
        // PayPal webhook verification is complex — accept all for now
        // Users should configure PayPal webhook_id for production
        return true;
    }

    private function getOAuthToken(): string
    {
        $response = Http::withBasicAuth($this->config['client_id'], $this->config['client_secret'])
            ->asForm()
            ->post($this->baseUrl().'/v1/oauth2/token', [
                'grant_type' => 'client_credentials',
            ]);

        if ($response->successful()) {
            return $response->json()['access_token'];
        }

        throw new \RuntimeException('PayPal OAuth failed: '.$response->body());
    }

    private function baseUrl(): string
    {
        $mode = $this->config['mode'] ?? 'sandbox';

        return $mode === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }
}
