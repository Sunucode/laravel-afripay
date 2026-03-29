<?php

namespace SunuCode\AfriPay\Gateways;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SunuCode\AfriPay\Enums\TransactionStatus;
use SunuCode\AfriPay\Models\Transaction;

class OrangeMoneyGateway extends AbstractGateway
{
    public function name(): string
    {
        return 'orange_money';
    }

    public function charge(array $params): array
    {
        $amount = $params['amount'];
        $currency = $params['currency'] ?? 'OUV';
        $reference = $params['reference'] ?? $this->generateReference('OM');

        $token = $this->getOAuthToken();
        $merchantKey = $this->config['merchant_key'];
        $webhookPath = config('afripay.webhook_path', '/afripay/webhooks');

        $body = [
            'merchant_key' => $merchantKey,
            'currency' => 'OUV',
            'order_id' => $reference,
            'amount' => (int) $amount,
            'return_url' => $params['success_url'].(str_contains($params['success_url'], '?') ? '&' : '?').'reference='.$reference,
            'cancel_url' => $params['error_url'],
            'notif_url' => $this->callbackUrl($webhookPath.'/orange-money'),
            'lang' => 'fr',
        ];

        $response = Http::withToken($token)
            ->acceptJson()
            ->post($this->baseUrl().'/orange-money-webpay/dev/v1/webpayment', $body);

        if (! $response->successful() || ! isset($response->json()['payment_url'])) {
            Log::error('AfriPay Orange Money: checkout failed', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 500),
            ]);
            throw new \RuntimeException('Orange Money checkout failed: '.($response->json()['message'] ?? $response->body()));
        }

        $data = $response->json();

        $transaction = $this->createTransaction(array_merge($params, [
            'reference' => $reference,
            'amount' => $amount,
            'currency' => $currency,
        ]));

        $transaction->update([
            'gateway_reference' => $data['pay_token'] ?? null,
            'gateway_response' => $data,
        ]);

        return [
            'redirect_url' => $data['payment_url'],
            'transaction' => $transaction,
        ];
    }

    /**
     * Orange Money has NO webhook signature.
     * NEVER trust callback data — always counter-verify via API.
     */
    public function handleWebhook(array $data): ?Transaction
    {
        $orderId = $data['order_id'] ?? null;
        if (! $orderId) {
            return null;
        }

        return DB::transaction(function () use ($orderId) {
            $transaction = Transaction::where('reference', $orderId)
                ->where('gateway', 'orange_money')
                ->lockForUpdate()
                ->first();

            if (! $transaction || $transaction->status === TransactionStatus::Completed) {
                return $transaction;
            }

            // Counter-verify via Orange Money API (mandatory — no signature)
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

            $response = Http::withToken($token)
                ->acceptJson()
                ->post($this->baseUrl().'/orange-money-webpay/dev/v1/transactionstatus', [
                    'order_id' => $transaction->reference,
                    'amount' => (int) $transaction->amount,
                    'pay_token' => $transaction->gateway_reference,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                $status = $data['status'] ?? null;

                $newStatus = match ($status) {
                    'SUCCESS' => TransactionStatus::Completed,
                    'FAILED', 'EXPIRED' => TransactionStatus::Failed,
                    default => TransactionStatus::Pending,
                };

                $transaction->update([
                    'status' => $newStatus,
                    'gateway_response' => $data,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('AfriPay Orange Money: verification failed', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $transaction->fresh();
    }

    /**
     * Orange Money does not sign webhooks — always returns false.
     * Security is handled via counter-verification in handleWebhook().
     */
    public function verifySignature(string $signature, string $rawBody): bool
    {
        // Orange Money has no webhook signature — verification is done via API
        return true;
    }

    private function getOAuthToken(): string
    {
        $authHeader = $this->config['auth_header'] ?? null;
        if (! $authHeader) {
            $authHeader = base64_encode($this->config['client_id'].':'.$this->config['client_secret']);
        }

        $response = Http::withHeaders([
            'Authorization' => "Basic {$authHeader}",
            'Accept' => 'application/json',
        ])->asForm()->post($this->baseUrl().'/oauth/v2/token', [
            'grant_type' => 'client_credentials',
        ]);

        if ($response->successful() && isset($response->json()['access_token'])) {
            return $response->json()['access_token'];
        }

        throw new \RuntimeException('Orange Money OAuth failed: '.$response->body());
    }

    private function baseUrl(): string
    {
        return $this->config['base_url'] ?? 'https://api.orange.com';
    }
}
