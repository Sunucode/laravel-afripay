<?php

namespace SunuCode\AfriPay\Facades;

use Illuminate\Support\Facades\Facade;
use SunuCode\AfriPay\Models\Transaction;
use SunuCode\AfriPay\PaymentManager;

/**
 * @method static array charge(array $params)
 * @method static PaymentManager via(string $gateway)
 * @method static Transaction|null handleWebhook(string $gateway, array $data)
 * @method static Transaction verify(Transaction $transaction)
 * @method static Transaction verifyAndProcess(Transaction $transaction)
 * @method static Transaction refund(Transaction $transaction, ?string $reason = null)
 * @method static bool verifyWebhookSignature(string $gateway, string $signature, string $rawBody)
 * @method static array enabledGateways()
 * @method static bool isEnabled(string $gateway)
 * @method static void extend(string $name, callable $resolver)
 *
 * @see PaymentManager
 */
class AfriPay extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return PaymentManager::class;
    }
}
