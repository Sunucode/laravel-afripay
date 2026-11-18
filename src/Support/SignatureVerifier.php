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
        // t={timestamp},v1={hmac}[,v1={hmac}...]: several v1 during a key rotation (https://docs.wave.com/webhook).
        $timestamp = null;
        $received = [];

        foreach (explode(',', $signature) as $element) {
            $parts = explode('=', trim($element), 2);
            if (count($parts) !== 2) {
                continue;
            }
            if ($parts[0] === 't' && ctype_digit($parts[1])) {
                $timestamp = $parts[1];
            } elseif ($parts[0] === 'v1') {
                $received[] = $parts[1];
            }
        }

        if ($timestamp === null || $received === []) {
            return false;
        }

        // Replay attack protection
        if (abs(time() - (int) $timestamp) > $toleranceSeconds) {
            return false;
        }

        $expectedHmac = hash_hmac('sha256', $timestamp.$rawBody, $secret);

        foreach ($received as $hmac) {
            if (hash_equals($expectedHmac, $hmac)) {
                return true;
            }
        }

        return false;
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
     * Vérifie un webhook Bictorys.
     *
     * Deux formes, les deux documentées par Bictorys : un HMAC-SHA256 calculé
     * sur « {horodatage}.{corps} », que l'appelant ramène au format
     * « t=...,v1=... » déjà utilisé ici pour Wave et Stripe, ou à défaut la
     * clé statique envoyée dans l'en-tête `X-Secret-Key`.
     *
     * Leur horodatage est en millisecondes là où Stripe compte en secondes :
     * lu comme des secondes, il situerait le message dans quelques millénaires
     * et toute signature valide serait rejetée.
     */
    public static function verifyBictorysSignature(string $signature, string $rawBody, string $secret, int $toleranceSeconds = 300): bool
    {
        if ($secret === '' || $signature === '') {
            return false;
        }

        if (preg_match('/^t=(\\d+),v1=([a-f0-9]+)$/i', trim($signature), $parts) !== 1) {
            // Clé statique : comparaison à temps constant, jamais `===`.
            return hash_equals($secret, $signature);
        }

        [, $timestamp, $received] = $parts;

        $millisecondes = (int) $timestamp;
        $secondes = $millisecondes >= 1_000_000_000_000 ? intdiv($millisecondes, 1000) : $millisecondes;

        if (abs(time() - $secondes) > $toleranceSeconds) {
            return false;
        }

        // Le HMAC porte l'horodatage tel qu'il a été envoyé, pas sa conversion.
        return hash_equals(hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret), $received);
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
