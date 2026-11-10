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
     * Ask the gateway for the current state of a transaction, without saving
     * anything. Gateways that support a read-only status check override it.
     *
     * @return array{status: TransactionStatus, amount: ?float, currency: ?string, data: array}|null
     */
    protected function fetchStatus(Transaction $transaction): ?array
    {
        return null;
    }

    /**
     * Read-only by default: the reported status is kept on the instance, never
     * saved, so a return page can never settle a payment the webhook has not.
     */
    public function verify(Transaction $transaction): Transaction
    {
        if ($transaction->status !== TransactionStatus::Pending) {
            return $transaction;
        }

        // A return page must not break when the gateway is unreachable: the
        // status stays unknown and the webhook settles the payment later.
        try {
            $remote = $this->fetchStatus($transaction);
        } catch (\Throwable $e) {
            Log::warning('AfriPay: status check failed', [
                'gateway' => $this->name(),
                'reference' => $transaction->reference,
                'error' => $e->getMessage(),
            ]);
            $remote = null;
        }

        $transaction->remoteStatus = $remote['status'] ?? null;

        return $transaction;
    }

    /**
     * Ask the gateway and settle the transaction if it is still pending.
     */
    public function settle(Transaction $transaction): ?Transaction
    {
        $remote = $this->fetchStatus($transaction);

        if ($remote === null || $remote['status'] === TransactionStatus::Pending) {
            return null;
        }

        return $this->transition($transaction->reference, $remote['status'], $remote['data'], $remote['amount'], $remote['currency']);
    }

    /**
     * The only way a transaction leaves pending: under a lock, once. A completed
     * payment whose amount or currency does not match the expected one fails.
     * Returns the transaction only when its status changed, so a replayed or late
     * notification never dispatches a second event.
     */
    protected function transition(?string $reference, TransactionStatus $to, array $data, ?float $paidAmount = null, ?string $paidCurrency = null, ?string $gatewayReference = null): ?Transaction
    {
        if ($reference === null || $reference === '' || $to === TransactionStatus::Pending) {
            return null;
        }

        return DB::transaction(function () use ($reference, $to, $data, $paidAmount, $paidCurrency, $gatewayReference) {
            $transaction = Transaction::where('gateway', $this->name())
                ->where('reference', $reference)
                ->lockForUpdate()
                ->first();

            if (! $transaction || $transaction->status !== TransactionStatus::Pending) {
                return null;
            }

            if ($to === TransactionStatus::Completed && ! $this->paidAsExpected($transaction, $paidAmount, $paidCurrency)) {
                $to = TransactionStatus::Failed;
            }

            $transaction->update(array_filter([
                'status' => $to,
                'gateway_response' => $this->safeLog($data),
                'gateway_reference' => $gatewayReference,
            ], fn ($value) => $value !== null));

            return $transaction->fresh();
        });
    }

    /**
     * A completed payment must carry its amount; its currency, when given, must
     * be the transaction's. Missing amount: the payment is not accepted.
     */
    protected function paidAsExpected(Transaction $transaction, ?float $paidAmount, ?string $paidCurrency): bool
    {
        if ($paidAmount === null || ! AmountConverter::amountsMatch((float) $transaction->amount, $paidAmount)) {
            Log::warning('AfriPay: amount missing or mismatched', [
                'expected' => $transaction->amount,
                'received' => $paidAmount,
                'reference' => $transaction->reference,
                'gateway' => $this->name(),
            ]);

            return false;
        }

        if ($paidCurrency !== null && strtoupper($paidCurrency) !== strtoupper((string) $transaction->currency)) {
            Log::warning('AfriPay: currency mismatched', [
                'expected' => $transaction->currency,
                'received' => $paidCurrency,
                'reference' => $transaction->reference,
                'gateway' => $this->name(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Find a pending transaction and lock it for processing.
     */
    protected function findAndLock(string $reference, ?string $gatewayRef = null): ?Transaction
    {
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

        // Call inside a DB transaction: the lock lasts until it commits.
        return $query->lockForUpdate()->first();
    }

    /**
     * Update a transaction to completed status with amount verification.
     */
    protected function completeTransaction(Transaction $transaction, array $webhookData, ?float $paidAmount = null): Transaction
    {
        // Verify amount if provided
        if ($paidAmount !== null && $paidAmount > 0) {
            if (! AmountConverter::amountsMatch((float) $transaction->amount, $paidAmount, null, (string) $transaction->currency)) {
                Log::warning('AfriPay: amount mismatch', [
                    'expected' => $transaction->amount,
                    'received' => $paidAmount,
                    'reference' => $transaction->reference,
                    'gateway' => $this->name(),
                ]);

                $transaction->update([
                    'status' => TransactionStatus::Failed,
                    'gateway_response' => $this->safeLog($webhookData),
                ]);

                return $transaction->fresh();
            }
        }

        $transaction->update([
            'status' => TransactionStatus::Completed,
            'gateway_response' => $this->safeLog($webhookData),
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
            'gateway_response' => $this->safeLog($data),
        ]);

        return $transaction->fresh();
    }

    /**
     * Redact sensitive fields from webhook data for logging.
     */
    protected function safeLog(array $data): array
    {
        // `client_secret` autorise a confirmer un paiement cote navigateur :
        // conserve en clair dans gateway_response et dans les journaux de
        // webhook, il y restait lisible par tout acces en lecture a la base.
        $redacted = ['card', 'card_number', 'cvv', 'cvc', 'password', 'secret',
            'account_number', 'phone_number', 'token', 'access_token',
            'client_secret', 'payment_method_details'];

        return collect($data)->map(function ($value, $key) use ($redacted) {
            if (in_array(strtolower($key), $redacted)) {
                return '***REDACTED***';
            }

            return is_array($value) ? $this->safeLog($value) : $value;
        })->toArray();
    }
}
