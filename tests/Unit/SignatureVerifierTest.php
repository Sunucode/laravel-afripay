<?php

use SunuCode\AfriPay\Support\SignatureVerifier;

test('wave signature verification succeeds with valid signature', function () {
    $secret = 'test_secret';
    $body = '{"amount":"5000","currency":"XOF"}';
    $timestamp = (string) time();
    $hmac = hash_hmac('sha256', $timestamp.$body, $secret);
    $signature = "t={$timestamp},v1={$hmac}";

    expect(SignatureVerifier::verifyWaveSignature($signature, $body, $secret))->toBeTrue();
});

test('wave signature verification fails with wrong secret', function () {
    $body = '{"amount":"5000"}';
    $timestamp = (string) time();
    $hmac = hash_hmac('sha256', $timestamp.$body, 'correct_secret');
    $signature = "t={$timestamp},v1={$hmac}";

    expect(SignatureVerifier::verifyWaveSignature($signature, $body, 'wrong_secret'))->toBeFalse();
});

test('wave signature verification fails with expired timestamp', function () {
    $secret = 'test_secret';
    $body = '{"amount":"5000"}';
    $timestamp = (string) (time() - 600); // 10 minutes ago
    $hmac = hash_hmac('sha256', $timestamp.$body, $secret);
    $signature = "t={$timestamp},v1={$hmac}";

    expect(SignatureVerifier::verifyWaveSignature($signature, $body, $secret))->toBeFalse();
});

test('wave signature verification fails with invalid format', function () {
    expect(SignatureVerifier::verifyWaveSignature('invalid', '{}', 'secret'))->toBeFalse();
    expect(SignatureVerifier::verifyWaveSignature('', '{}', 'secret'))->toBeFalse();
});

test('stripe signature verification succeeds with valid signature', function () {
    $secret = 'whsec_test';
    $payload = '{"type":"checkout.session.completed"}';
    $timestamp = time();
    $expectedSignature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);
    $header = "t={$timestamp},v1={$expectedSignature}";

    expect(SignatureVerifier::verifyStripeSignature($payload, $header, $secret))->toBeTrue();
});

test('stripe signature verification fails with wrong secret', function () {
    $payload = '{"type":"checkout.session.completed"}';
    $timestamp = time();
    $sig = hash_hmac('sha256', "{$timestamp}.{$payload}", 'correct');
    $header = "t={$timestamp},v1={$sig}";

    expect(SignatureVerifier::verifyStripeSignature($payload, $header, 'wrong'))->toBeFalse();
});

test('paytech signature verification succeeds', function () {
    $apiKey = 'pk_test';
    $apiSecret = 'sk_test';
    $amount = '15000';
    $ref = 'PT-123';

    $expected = hash_hmac('sha256', "{$amount}|{$ref}|{$apiKey}", $apiSecret);

    expect(SignatureVerifier::verifyPayTechSignature($amount, $ref, $apiKey, $apiSecret, $expected))->toBeTrue();
});

test('paytech signature verification fails with wrong data', function () {
    $apiKey = 'pk_test';
    $apiSecret = 'sk_test';

    $signature = hash_hmac('sha256', "15000|PT-123|{$apiKey}", $apiSecret);

    // Different amount
    expect(SignatureVerifier::verifyPayTechSignature('20000', 'PT-123', $apiKey, $apiSecret, $signature))->toBeFalse();
});

test('buildWaveSignature produces valid signature', function () {
    $secret = 'test_secret';
    $body = '{"amount":"5000"}';

    $signature = SignatureVerifier::buildWaveSignature($body, $secret);

    expect($signature)->toMatch('/^t=\d+,v1=[a-f0-9]{64}$/');

    // Verify it passes our own verification
    expect(SignatureVerifier::verifyWaveSignature($signature, $body, $secret))->toBeTrue();
});

test('paydunya IPN is accepted when data.hash is the sha512 of the master key', function () {
    // PayDunya ne signe pas ses IPN : il renvoie data[hash], qui vaut le
    // SHA-512 de la clé maître. C'est le cas nominal, et il était rejeté.
    $gateway = new SunuCode\AfriPay\Gateways\PayDunyaGateway([
        'master_key' => 'ma-cle-maitre',
        'private_key' => 'live_private_x',
        'token' => 'jeton',
        'mode' => 'test',
    ]);

    expect($gateway->verifySignature(hash('sha512', 'ma-cle-maitre'), ''))->toBeTrue();
});

test('paydunya IPN still accepts the raw master key', function () {
    $gateway = new SunuCode\AfriPay\Gateways\PayDunyaGateway([
        'master_key' => 'ma-cle-maitre',
    ]);

    expect($gateway->verifySignature('ma-cle-maitre', ''))->toBeTrue();
});

test('paydunya IPN is rejected with a wrong or empty hash', function () {
    $gateway = new SunuCode\AfriPay\Gateways\PayDunyaGateway([
        'master_key' => 'ma-cle-maitre',
    ]);

    expect($gateway->verifySignature(hash('sha512', 'autre-cle'), ''))->toBeFalse();
    expect($gateway->verifySignature('', ''))->toBeFalse();
});

test('paydunya IPN is rejected when no master key is configured', function () {
    $gateway = new SunuCode\AfriPay\Gateways\PayDunyaGateway(['master_key' => '']);

    expect($gateway->verifySignature(hash('sha512', ''), ''))->toBeFalse();
});
