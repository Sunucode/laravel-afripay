<?php

namespace SunuCode\AfriPay\Support;

class SignatureVerifier
{
    /**
     * Verify a Wave-style HMAC signature: t={timestamp},v1={hmac}
     * Used by Wave for both outbound requests and webhook verification.
     */
    public static function verifyWaveSignature(string $signature, string $rawBody, string $secret, int $toleranceSeconds = 300): bool
    {
        if (! preg_match('/t=(\d+),v1=(.+)/', $signature, $matches)) {
            return false;
        }

        $timestamp = $matches[1];
        $receivedHmac = $matches[2];

        // Replay attack protection
        if (abs(time() - (int) $timestamp) > $toleranceSeconds) {
            return false;
        }

        $expectedHmac = hash_hmac('sha256', $timestamp.$rawBody, $secret);

        return hash_equals($expectedHmac, $receivedHmac);
    }

    /**
     * Verify a Stripe webhook signature.
     *
     * @throws \RuntimeException
     */
    public static function verifyStripeSignature(string $payload, string $header, string $secret, int $toleranceSeconds = 300): bool
    {
        $elements = explode(',', $header);
        $timestamp = null;
        $signatures = [];

        foreach ($elements as $element) {
            $parts = explode('=', trim($element), 2);
            if (count($parts) !== 2) {
                continue;
            }
            [$prefix, $value] = $parts;
            if ($prefix === 't') {
                $timestamp = (int) $value;
            } elseif ($prefix === 'v1') {
                $signatures[] = $value;
            }
        }

        if (! $timestamp || empty($signatures)) {
            return false;
        }

        if (abs(time() - $timestamp) > $toleranceSeconds) {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        foreach ($signatures as $sig) {
            if (hash_equals($expectedSignature, $sig)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verify a PayTech HMAC-SHA256 signature: hash(amount|ref|api_key, secret).
     */
    public static function verifyPayTechSignature(string $amount, string $refCommand, string $apiKey, string $apiSecret, string $receivedSignature): bool
    {
        $expected = hash_hmac('sha256', "{$amount}|{$refCommand}|{$apiKey}", $apiSecret);

        return hash_equals($expected, $receivedSignature);
    }

    /**
     * Build a Wave-style signed header for outbound API requests.
     */
    public static function buildWaveSignature(string $jsonBody, string $secret): string
    {
        $timestamp = (string) time();
        $hmac = hash_hmac('sha256', $timestamp.$jsonBody, $secret);

        return "t={$timestamp},v1={$hmac}";
    }
}
