<?php

namespace SunuCode\AfriPay\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use SunuCode\AfriPay\Models\Transaction;

class PaymentInitiated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Transaction $transaction,
        public readonly string $gateway,
    ) {}
}
