<?php

namespace SunuCode\AfriPay\Contracts;

use SunuCode\AfriPay\Models\Transaction;

interface GatewayInterface
{
    /**
     * Get the gateway identifier (e.g. 'wave', 'stripe').
     */
    public function name(): string;

    /**
     * Initiate a payment and return the redirect URL + transaction.
     *
     * @param  array{
     *     amount: float,
     *     currency?: string,
     *     description?: string,
     *     reference?: string,
     *     success_url: string,
     *     error_url: string,
     *     metadata?: array,
     * }  $params
     * @return array{redirect_url: string, transaction: Transaction}
     */
    public function charge(array $params): array;

    /**
     * Handle an incoming webhook callback from the gateway.
     * Returns the updated transaction, or null if ignored.
     */
    public function handleWebhook(array $data): ?Transaction;

    /**
     * Verify a transaction status directly with the gateway API.
     * Read-only — does NOT trigger activation.
     */
    public function verify(Transaction $transaction): Transaction;

    /**
     * Verify the signature of an incoming webhook request.
     */
    public function verifySignature(string $signature, string $rawBody): bool;
}
