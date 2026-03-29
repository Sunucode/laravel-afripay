<?php

namespace SunuCode\AfriPay\Gateways;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use SunuCode\AfriPay\Contracts\GatewayInterface;
use SunuCode\AfriPay\Enums\TransactionStatus;
use SunuCode\AfriPay\Models\Transaction;
use SunuCode\AfriPay\Support\AmountConverter;

abstract class AbstractGateway implements GatewayInterface
{
    protected array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Generate a unique payment reference with a prefix.
     */
    protected function generateReference(string $prefix = 'PAY'): string
    {
        return $prefix.'-'.Str::upper(Str::random(10));
    }

    /**
     * Build a callback URL using the configured base URL.
     */
    protected function callbackUrl(string $path): string
    {
        $base = config('afripay.callback_base_url') ?: config('app.url');

        return rtrim($base, '/').'/'.ltrim($path, '/');
    }

    /**
     * Get the default currency from config.
     */
    protected function defaultCurrency(): string
    {
        return config('afripay.currency', 'XOF');
    }

    /**
     * Create a pending transaction record.
     */
    protected function createTransaction(array $params): Transaction
    {
        return Transaction::create([
            'gateway' => $this->name(),
            'reference' => $params['reference'],
            'amount' => $params['amount'],
            'currency' => $params['currency'] ?? $this->defaultCurrency(),
            'status' => TransactionStatus::Pending,
            'metadata' => $params['metadata'] ?? null,
            'payable_type' => $params['payable_type'] ?? null,
            'payable_id' => $params['payable_id'] ?? null,
        ]);
    }

    /**
     * Find a pending transaction and lock it for processing.
     */
    protected function findAndLock(string $reference, ?string $gatewayRef = null): ?Transaction
    {
        return DB::transaction(function () use ($reference, $gatewayRef) {
            $query = Transaction::where('gateway', $this->name())
                ->where('status', TransactionStatus::Pending);

            if ($gatewayRef) {
                $query->where(function ($q) use ($reference, $gatewayRef) {
                    $q->where('reference', $reference)
                        ->orWhere('gateway_reference', $gatewayRef);
                });
            } else {
                $query->where('reference', $reference);
            }

            return $query->lockForUpdate()->first();
        });
    }

    /**
     * Update a transaction to completed status with amount verification.
     */
    protected function completeTransaction(Transaction $transaction, array $webhookData, ?float $paidAmount = null): Transaction
    {
        // Verify amount if provided
        if ($paidAmount !== null && $paidAmount > 0) {
            if (! AmountConverter::amountsMatch((float) $transaction->amount, $paidAmount)) {
                Log::warning('AfriPay: amount mismatch', [
                    'expected' => $transaction->amount,
                    'received' => $paidAmount,
                    'reference' => $transaction->reference,
                    'gateway' => $this->name(),
                ]);

                $transaction->update([
                    'status' => TransactionStatus::Failed,
                    'gateway_response' => $webhookData,
                ]);

                return $transaction->fresh();
            }
        }

        $transaction->update([
            'status' => TransactionStatus::Completed,
            'gateway_response' => $webhookData,
        ]);

        return $transaction->fresh();
    }

    /**
     * Mark a transaction as failed.
     */
    protected function failTransaction(Transaction $transaction, array $data): Transaction
    {
        $transaction->update([
            'status' => TransactionStatus::Failed,
            'gateway_response' => $data,
        ]);

        return $transaction->fresh();
    }

    /**
     * Redact sensitive fields from webhook data for logging.
     */
    protected function safeLog(array $data): array
    {
        $redacted = ['card', 'card_number', 'cvv', 'cvc', 'password', 'secret',
            'account_number', 'phone_number', 'token', 'access_token'];

        return collect($data)->map(function ($value, $key) use ($redacted) {
            if (in_array(strtolower($key), $redacted)) {
                return '***REDACTED***';
            }

            return is_array($value) ? $this->safeLog($value) : $value;
        })->toArray();
    }
}
