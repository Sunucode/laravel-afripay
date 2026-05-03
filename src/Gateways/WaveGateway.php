<?php

namespace SunuCode\AfriPay\Gateways;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SunuCode\AfriPay\Enums\TransactionStatus;
use SunuCode\AfriPay\Models\Transaction;
use SunuCode\AfriPay\Support\SignatureVerifier;

class WaveGateway extends AbstractGateway
{
    public function name(): string
    {
        return 'wave';
    }

    public function charge(array $params): array
    {
        $amount = $params['amount'];
        $currency = $params['currency'] ?? $this->defaultCurrency();
        $reference = $params['reference'] ?? $this->generateReference('WV');

        // Wave has no sandbox — use minimal amount in local/testing
        $waveAmount = app()->environment('local', 'testing')
            ? '5'
            : (string) (int) $amount;

        $body = [
            'amount' => $waveAmount,
            'currency' => $currency,
            'error_url' => $params['error_url'],
            'success_url' => $params['success_url'],
            'client_reference' => $reference,
        ];

        $response = $this->signedRequest('POST', $this->baseUrl().'/checkout/sessions', $body);

        if (! $response->successful() || ! isset($response->json()['wave_launch_url'])) {
            Log::error('AfriPay Wave: checkout failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new \RuntimeException('Wave checkout failed: '.($response->json()['message'] ?? $response->body()));
        }

        $data = $response->json();

        $transaction = $this->createTransaction(array_merge($params, [
            'reference' => $reference,
            'amount' => $amount,
            'currency' => $currency,
        ]));

        // Align local amount with what was sent to Wave
        $updateData = ['gateway_reference' => $data['id'], 'gateway_response' => $data];
        if (app()->environment('local', 'testing')) {
            $updateData['amount'] = 5;
        }
        $transaction->update($updateData);

        return [
            'redirect_url' => $data['wave_launch_url'],
            'transaction' => $transaction,
        ];
    }

    public function handleWebhook(array $data): ?Transaction
    {
        $checkout = $data['data'] ?? $data;

        $status = $checkout['checkout_status'] ?? $checkout['payment_status'] ?? null;
        if ($status !== 'complete' && $status !== 'succeeded') {
            return null;
        }

        $reference = $checkout['client_reference'] ?? null;
        if (! $reference) {
            return null;
        }

        return DB::transaction(function () use ($reference, $checkout) {
            $transaction = Transaction::where('reference', $reference)
                ->where('gateway', 'wave')
                ->where('status', TransactionStatus::Pending)
                ->lockForUpdate()
                ->first();

            if (! $transaction) {
                return null;
            }

            $paidAmount = (float) ($checkout['amount'] ?? 0);

            $transaction->update([
                'gateway_reference' => $checkout['transaction_id'] ?? $checkout['id'] ?? $transaction->gateway_reference,
            ]);

            return $this->completeTransaction($transaction, $checkout, $paidAmount);
        });
    }

    public function verify(Transaction $transaction): Transaction
    {
        if (! $transaction->gateway_reference || $transaction->status !== TransactionStatus::Pending) {
            return $transaction;
        }

        $response = $this->signedRequest(
            'GET',
            $this->baseUrl().'/checkout/sessions/'.$transaction->gateway_reference
        );

        if ($response->successful()) {
            $data = $response->json();
            $status = $data['checkout_status'] ?? $data['payment_status'] ?? null;

            $newStatus = match ($status) {
                'complete', 'succeeded' => TransactionStatus::Completed,
                'expired', 'failed' => TransactionStatus::Failed,
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

        return SignatureVerifier::verifyWaveSignature($signature, $rawBody, $secret);
    }

    /**
     * Send a signed request to the Wave API.
     */
    protected function signedRequest(string $method, string $url, array $data = [])
    {
        $method = strtoupper($method);
        $hasBody = in_array($method, ['POST', 'PATCH', 'PUT'], true);
        $jsonBody = $hasBody ? json_encode($data) : '';

        $request = Http::withToken($this->config['api_key']);

        if ($hasBody) {
            $request = $request->withBody($jsonBody, 'application/json');
        }

        $signingSecret = $this->config['api_secret'] ?? '';
        if ($signingSecret !== '') {
            $signature = SignatureVerifier::buildWaveSignature($jsonBody, $signingSecret);
            $request = $request->withHeaders(['Wave-Signature' => $signature]);
        }

        return $request->send($method, $url);
    }

    private function baseUrl(): string
    {
        return $this->config['base_url'] ?? 'https://api.wave.com/v1';
    }
}
