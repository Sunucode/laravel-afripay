<?php

namespace SunuCode\AfriPay\Gateways;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SunuCode\AfriPay\Enums\TransactionStatus;
use SunuCode\AfriPay\Models\Transaction;
use SunuCode\AfriPay\Support\SignatureVerifier;

class PayTechGateway extends AbstractGateway
{
    public function name(): string
    {
        return 'paytech';
    }

    public function charge(array $params): array
    {
        $amount = $params['amount'];
        $currency = $params['currency'] ?? 'XOF';
        $reference = $params['reference'] ?? $this->generateReference('PT');
        $description = $params['description'] ?? config('app.name', 'AfriPay').' Payment';

        $webhookPath = config('afripay.webhook_path', '/afripay/webhooks');
        $env = $this->config['env'] ?? 'test';

        $requestParams = [
            'item_name' => $description,
            'item_price' => (int) $amount,
            'currency' => 'XOF',
            'ref_command' => $reference,
            'command_name' => $params['metadata']['command_name'] ?? 'Payment',
            'env' => $env,
            'ipn_url' => $this->callbackUrl($webhookPath.'/paytech'),
            'success_url' => $params['success_url'].(str_contains($params['success_url'], '?') ? '&' : '?').'reference='.$reference,
            'cancel_url' => $params['error_url'],
            'custom_field' => json_encode($params['metadata'] ?? []),
        ];

        $response = Http::withHeaders([
            'API_KEY' => $this->config['api_key'],
            'API_SECRET' => $this->config['api_secret'],
        ])->asForm()->post($this->baseUrl().'/payment/request-payment', $requestParams);

        if (! $response->successful() || ($response->json()['success'] ?? 0) != 1) {
            Log::error('AfriPay PayTech: checkout failed', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 500),
            ]);
            throw new \RuntimeException('PayTech checkout failed: '.($response->json()['error'] ?? 'Unknown error'));
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
            'redirect_url' => $data['redirect_url'],
            'transaction' => $transaction,
        ];
    }

    public function handleWebhook(array $data): ?Transaction
    {
        $refCommand = $data['ref_command'] ?? null;
        $typeEvent = $data['type_event'] ?? null;

        if (! $refCommand) {
            return null;
        }

        // Verify HMAC signature
        $amount = $data['item_price'] ?? '';
        $receivedSignature = $data['signature'] ?? '';

        if (! SignatureVerifier::verifyPayTechSignature(
            $amount,
            $refCommand,
            $this->config['api_key'],
            $this->config['api_secret'],
            $receivedSignature
        )) {
            Log::warning('AfriPay PayTech: invalid signature', ['ref' => $refCommand]);

            return null;
        }

        return DB::transaction(function () use ($refCommand, $typeEvent, $data) {
            $transaction = Transaction::where('reference', $refCommand)
                ->where('gateway', 'paytech')
                ->lockForUpdate()
                ->first();

            if (! $transaction || $transaction->status === TransactionStatus::Completed) {
                return $transaction;
            }

            if ($typeEvent === 'sale_complete') {
                $paidAmount = (float) ($data['item_price'] ?? 0);

                return $this->completeTransaction($transaction, $data, $paidAmount);
            }

            if ($typeEvent === 'sale_canceled') {
                return $this->failTransaction($transaction, $data);
            }

            return $transaction;
        });
    }

    public function verify(Transaction $transaction): Transaction
    {
        // PayTech has no status verification endpoint — rely on IPN callback
        return $transaction;
    }

    public function verifySignature(string $signature, string $rawBody): bool
    {
        // PayTech uses per-request signature (verified in handleWebhook)
        return true;
    }

    private function baseUrl(): string
    {
        return $this->config['base_url'] ?? 'https://paytech.sn/api';
    }
}
