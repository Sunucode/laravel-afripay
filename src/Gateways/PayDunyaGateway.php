<?php

namespace SunuCode\AfriPay\Gateways;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SunuCode\AfriPay\Contracts\SettlesFromGateway;
use SunuCode\AfriPay\Enums\TransactionStatus;
use SunuCode\AfriPay\Models\Transaction;

class PayDunyaGateway extends AbstractGateway implements SettlesFromGateway
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

    /**
     * Handle a PayDunya IPN.
     *
     * PayDunya POSTs `application/x-www-form-urlencoded` data nested under a
     * single `data` node: `data[status]`, `data[invoice][token]`,
     * `data[custom_data][...]`. The node is unwrapped first; a flat payload is
     * still accepted, for callers that unwrap it themselves.
     *
     * The IPN is not signed: its `hash` is the same for every IPN (the SHA-512 of
     * the master key), so it proves nothing about the status or the amount it
     * carries. The IPN only names the invoice; its status and amount are read
     * from the confirm endpoint, and only that answer settles the transaction.
     */
    public function handleWebhook(array $data): ?Transaction
    {
        if (isset($data['data']) && is_array($data['data'])) {
            $data = $data['data'];
        }

        $token = $data['invoice']['token'] ?? $data['token'] ?? $data['invoice_token'] ?? null;
        $reference = $data['custom_data']['reference'] ?? null;

        if (! $token && ! $reference) {
            return null;
        }

        $transaction = Transaction::where('gateway', 'paydunya')
            ->where(function ($q) use ($token, $reference) {
                if ($token) {
                    $q->orWhere('gateway_reference', $token);
                }
                if ($reference) {
                    $q->orWhere('reference', $reference);
                }
            })
            ->first();

        return $transaction ? $this->settle($transaction) : null;
    }

    protected function fetchStatus(Transaction $transaction): ?array
    {
        if (! $transaction->gateway_reference) {
            return null;
        }

        $response = Http::withHeaders($this->apiHeaders())
            ->get($this->baseUrl().'/checkout-invoice/confirm/'.$transaction->gateway_reference);

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json();

        // The documentation writes « Completed » where the API answers « completed »: compared without case.
        return [
            'status' => match (strtolower((string) ($data['status'] ?? ''))) {
                'completed' => TransactionStatus::Completed,
                'failed', 'cancelled' => TransactionStatus::Failed,
                default => TransactionStatus::Pending,
            },
            'amount' => isset($data['invoice']['total_amount']) ? (float) $data['invoice']['total_amount'] : null,
            'currency' => null,
            'data' => $data,
        ];
    }

    /**
     * Verify a PayDunya IPN.
     *
     * PayDunya does not sign its IPN: it sends back `data[hash]`, which is the
     * SHA-512 hash of your master key. Comparing the raw master key — as this
     * method used to — rejected every legitimate IPN.
     *
     * The raw master key is still accepted, since some integrations forward it
     * as-is (and the `master_key` field of older payloads carries it).
     */
    public function verifySignature(string $signature, string $rawBody): bool
    {
        $masterKey = $this->config['master_key'] ?? '';

        if ($masterKey === '' || $signature === '') {
            return false;
        }

        if (hash_equals(hash('sha512', $masterKey), $signature)) {
            return true;
        }

        return hash_equals($masterKey, $signature);
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
