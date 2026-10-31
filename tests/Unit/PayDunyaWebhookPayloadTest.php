<?php

use Illuminate\Support\Facades\Http;
use SunuCode\AfriPay\Enums\TransactionStatus;
use SunuCode\AfriPay\Gateways\PayDunyaGateway;
use SunuCode\AfriPay\Models\Transaction;
use SunuCode\AfriPay\Tests\TestCase;

uses(TestCase::class);

/**
 * PayDunya poste ses IPN en application/x-www-form-urlencoded, avec toutes les
 * données imbriquées sous un unique nœud `data`. La passerelle lisait au
 * premier niveau : ni le token ni la référence ne résolvaient, et tout IPN
 * était ignoré en silence — y compris avec une signature valide.
 *
 * Charge utile conforme à la documentation officielle :
 * https://developers.paydunya.com/doc/FR/php
 *
 * Depuis la 1.1.0, le statut et le montant ne sont jamais lus dans l'IPN :
 * la passerelle les relit sur l'API de confirmation, simulée ici.
 */
function paydunyaIpnPayload(string $token, string $status = 'completed'): array
{
    return [
        'data' => [
            'response_code' => '00',
            'response_text' => 'Transaction Found',
            'hash' => hash('sha512', 'test_master_key'),
            'invoice' => [
                'token' => $token,
                'total_amount' => '2900',
                'description' => 'Abonnement',
            ],
            'custom_data' => ['reference' => 'PD-TEST123'],
            'mode' => 'live',
            'status' => $status,
            'customer' => ['name' => 'Awa Ndiaye'],
        ],
    ];
}

beforeEach(function () {
    Http::fake([
        '*/checkout-invoice/confirm/tok_fail' => Http::response(['response_code' => '00', 'status' => 'cancelled', 'invoice' => ['total_amount' => '2900']]),
        '*/checkout-invoice/confirm/*' => Http::response(['response_code' => '00', 'status' => 'completed', 'invoice' => ['total_amount' => '2900']]),
    ]);
});

test('a PayDunya IPN nested under data completes the transaction', function () {
    $transaction = Transaction::create([
        'gateway' => 'paydunya',
        'reference' => 'PD-TEST123',
        'gateway_reference' => 'tok_abc123',
        'amount' => 2900,
        'currency' => 'XOF',
        'status' => TransactionStatus::Pending,
    ]);

    $gateway = new PayDunyaGateway([
        'master_key' => 'test_master_key',
        'private_key' => 'live_private_x',
        'token' => 'jeton',
        'mode' => 'live',
    ]);

    $result = $gateway->handleWebhook(paydunyaIpnPayload('tok_abc123'));

    expect($result)->not->toBeNull()
        ->and($result->status)->toBe(TransactionStatus::Completed);
});

test('a PayDunya IPN reporting a failure marks the transaction failed', function () {
    Transaction::create([
        'gateway' => 'paydunya',
        'reference' => 'PD-TEST123',
        'gateway_reference' => 'tok_fail',
        'amount' => 2900,
        'currency' => 'XOF',
        'status' => TransactionStatus::Pending,
    ]);

    $gateway = new PayDunyaGateway(['master_key' => 'test_master_key', 'mode' => 'live']);

    $result = $gateway->handleWebhook(paydunyaIpnPayload('tok_fail', 'cancelled'));

    expect($result?->status)->toBe(TransactionStatus::Failed);
});

test('a flat payload is still handled', function () {
    Transaction::create([
        'gateway' => 'paydunya',
        'reference' => 'PD-FLAT',
        'gateway_reference' => 'tok_flat',
        'amount' => 2900,
        'currency' => 'XOF',
        'status' => TransactionStatus::Pending,
    ]);

    $gateway = new PayDunyaGateway(['master_key' => 'test_master_key', 'mode' => 'live']);

    $flat = paydunyaIpnPayload('tok_flat')['data'];

    expect($gateway->handleWebhook($flat)?->status)->toBe(TransactionStatus::Completed);
});
