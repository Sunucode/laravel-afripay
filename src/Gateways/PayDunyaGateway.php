<?php

namespace SunuCode\AfriPay\Gateways;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SunuCode\AfriPay\Enums\TransactionStatus;
use SunuCode\AfriPay\Models\Transaction;

class PayDunyaGateway extends AbstractGateway
{
    public function name(): string
    {
        return 'paydunya';
    }

    public function charge(array $params): array
    {
        $amount = $params['amount'];
        $currency = $params['currency'] ?? $this->defaultCurrency();
        $reference = $params['reference'] ?? $this->generateReference('PD');
        $description = $params['description'] ?? config('app.name', 'AfriPay').' Payment';

        $webhookPath = config('afripay.webhook_path', '/afripay/webhooks');

        $response = Http::withHeaders($this->apiHeaders())
            ->post($this->baseUrl().'/checkout-invoice/create', [
                'invoice' => [
                    'total_amount' => (int) $amount,
                    'description' => $description,
                ],
                'store' => [
                    'name' => config('app.name'),
                    'tagline' => $params['metadata']['tagline'] ?? 'Powered by AfriPay',
                    'website_url' => config('app.url'),
                ],
                'custom_data' => [
                    'reference' => $reference,
                    ...(isset($params['metadata']) ? $params['metadata'] : []),
                ],
                'actions' => [
                    'cancel_url' => $params['error_url'],
                    'return_url' => $params['success_url'].(str_contains($params['success_url'], '?') ? '&' : '?').'reference='.$reference,
                    'callback_url' => $this->callbackUrl($webhookPath.'/paydunya'),
                ],
            ]);

        if ($response->failed() || ($response->json('response_code') ?? '') !== '00') {
            Log::error('AfriPay PayDunya: checkout failed', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
            throw new \RuntimeException('PayDunya checkout failed: '.($response->json('response_text') ?? 'Unknown error'));
        }

        $data = $response->json();

        $transaction = $this->createTransaction(array_merge($params, [
            'reference' => $reference,
            'amount' => $amount,
            'currency' => $currency,
        ]));

        $transaction->update([
            'gateway_reference' => $data['token'] ?? null,
            'gateway_response' => $data,
        ]);

        return [
            'redirect_url' => $data['response_text'] ?? '',
            'transaction' => $transaction,
        ];
    }

    public function handleWebhook(array $data): ?Transaction
    {
        $token = $data['token'] ?? $data['invoice_token'] ?? null;
        $reference = $data['custom_data']['reference'] ?? null;

        return DB::transaction(function () use ($data, $token, $reference) {
            $query = Transaction::where('gateway', 'paydunya')
                ->where('status', TransactionStatus::Pending);

            if ($token && $reference) {
                $query->where(function ($q) use ($token, $reference) {
                    $q->where('gateway_reference', $token)
                        ->orWhere('reference', $reference);
                });
            } elseif ($token) {
                $query->where('gateway_reference', $token);
            } elseif ($reference) {
                $query->where('reference', $reference);
            } else {
                return null;
            }

            $transaction = $query->lockForUpdate()->first();

            if (! $transaction) {
                // Try to verify via PayDunya API
                if ($token) {
                    return $this->verifyByToken($token, $data);
                }

                return null;
            }

            $paidAmount = (float) ($data['invoice']['total_amount'] ?? $data['total_amount'] ?? 0);

            $status = $data['status'] ?? $data['response_code'] ?? 'unknown';
            $newStatus = match ($status) {
                'completed', '00' => TransactionStatus::Completed,
                'failed', 'cancelled' => TransactionStatus::Failed,
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

        $response = Http::withHeaders($this->apiHeaders())
            ->get($this->baseUrl().'/checkout-invoice/confirm/'.$transaction->gateway_reference);

        if ($response->successful()) {
            $data = $response->json();
            $newStatus = match ($data['status'] ?? 'unknown') {
                'completed' => TransactionStatus::Completed,
                'failed', 'cancelled' => TransactionStatus::Failed,
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
        $masterKey = $this->config['master_key'] ?? '';

        return $masterKey && hash_equals($masterKey, $signature);
    }

    private function verifyByToken(string $token, array $callbackData): ?Transaction
    {
        $response = Http::withHeaders($this->apiHeaders())
            ->get($this->baseUrl().'/checkout-invoice/confirm/'.$token);

        if ($response->failed()) {
            return null;
        }

        $data = $response->json();
        $reference = $data['custom_data']['reference'] ?? null;

        if (! $reference) {
            return null;
        }

        return DB::transaction(function () use ($reference, $callbackData, $data) {
            $transaction = Transaction::where('reference', $reference)
                ->where('gateway', 'paydunya')
                ->lockForUpdate()
                ->first();

            if (! $transaction) {
                return null;
            }

            $newStatus = match ($data['status'] ?? 'unknown') {
                'completed' => TransactionStatus::Completed,
                'failed', 'cancelled' => TransactionStatus::Failed,
                default => TransactionStatus::Pending,
            };

            $transaction->update([
                'status' => $newStatus,
                'gateway_response' => array_merge($callbackData, $data),
            ]);

            return $transaction->fresh();
        });
    }

    private function apiHeaders(): array
    {
        return [
            'PAYDUNYA-MASTER-KEY' => $this->config['master_key'] ?? '',
            'PAYDUNYA-PRIVATE-KEY' => $this->config['private_key'] ?? '',
            'PAYDUNYA-TOKEN' => $this->config['token'] ?? '',
            'Content-Type' => 'application/json',
        ];
    }

    private function baseUrl(): string
    {
        $mode = $this->config['mode'] ?? 'test';

        return $mode === 'live'
            ? 'https://app.paydunya.com/api/v1'
            : 'https://app.paydunya.com/sandbox-api/v1';
    }
}
