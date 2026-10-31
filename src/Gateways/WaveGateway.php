<?php

namespace SunuCode\AfriPay\Gateways;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SunuCode\AfriPay\Contracts\SettlesFromGateway;
use SunuCode\AfriPay\Enums\TransactionStatus;
use SunuCode\AfriPay\Models\Transaction;
use SunuCode\AfriPay\Support\AmountConverter;
use SunuCode\AfriPay\Support\SignatureVerifier;

class WaveGateway extends AbstractGateway implements SettlesFromGateway
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

        // Wave has no sandbox. A small test amount is charged only when it is set
        // explicitly (WAVE_TEST_AMOUNT), never because of the environment's name.
        $chargedAmount = $this->testAmount() ?? (int) $amount;

        $body = [
            'amount' => (string) $chargedAmount,
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

        // The transaction records what was really charged, so the webhook's amount matches it.
        $transaction = $this->createTransaction(array_merge($params, [
            'reference' => $reference,
            'amount' => $chargedAmount,
            'currency' => $currency,
        ]));

        $transaction->update(['gateway_reference' => $data['id'], 'gateway_response' => $data]);

        return [
            'redirect_url' => $data['wave_launch_url'],
            'transaction' => $transaction,
        ];
    }

    /**
     * checkout.session.completed settles the payment, checkout.session.payment_failed
     * fails it (https://docs.wave.com/webhook). A completed session without its
     * amount is checked against the API rather than accepted as is.
     */
    public function handleWebhook(array $data): ?Transaction
    {
        $checkout = $data['data'] ?? $data;
        $type = $data['type'] ?? null;
        $reference = $checkout['client_reference'] ?? null;

        if (! $reference) {
            return null;
        }

        if ($type === 'checkout.session.payment_failed') {
            return $this->transition($reference, TransactionStatus::Failed, $checkout);
        }

        $status = $checkout['checkout_status'] ?? $checkout['payment_status'] ?? null;
        if ($status !== 'complete' && $status !== 'succeeded') {
            return null;
        }

        if (! isset($checkout['amount'])) {
            $transaction = Transaction::where('gateway', 'wave')->where('reference', $reference)->first();

            return $transaction ? $this->settle($transaction) : null;
        }

        return $this->transition(
            $reference,
            TransactionStatus::Completed,
            $checkout,
            (float) $checkout['amount'],
            $checkout['currency'] ?? null,
            $checkout['transaction_id'] ?? null,
        );
    }

    protected function fetchStatus(Transaction $transaction): ?array
    {
        if (! $transaction->gateway_reference) {
            return null;
        }

        $response = $this->signedRequest('GET', $this->baseUrl().'/checkout/sessions/'.$transaction->gateway_reference);

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json();

        return [
            'status' => match ($data['checkout_status'] ?? $data['payment_status'] ?? null) {
                'complete', 'succeeded' => TransactionStatus::Completed,
                'expired', 'failed' => TransactionStatus::Failed,
                default => TransactionStatus::Pending,
            },
            'amount' => isset($data['amount']) ? (float) $data['amount'] : null,
            'currency' => $data['currency'] ?? null,
            'data' => $data,
        ];
    }

    /**
     * With a test amount, Wave is paid that amount instead of the transaction's:
     * it then counts as the expected payment.
     */
    protected function paidAsExpected(Transaction $transaction, ?float $paidAmount, ?string $paidCurrency): bool
    {
        $testAmount = $this->testAmount();

        if ($testAmount !== null && $paidAmount !== null && AmountConverter::amountsMatch((float) $testAmount, $paidAmount)) {
            $paidAmount = (float) $transaction->amount;
        }

        return parent::paidAsExpected($transaction, $paidAmount, $paidCurrency);
    }

    private function testAmount(): ?int
    {
        $testAmount = $this->config['test_amount'] ?? null;

        return $testAmount !== null && $testAmount !== '' ? (int) $testAmount : null;
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
