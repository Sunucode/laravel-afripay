<?php

namespace SunuCode\AfriPay\Contracts;

use SunuCode\AfriPay\Models\Transaction;

/**
 * A gateway whose status check is read-only and whose settlement is a single,
 * locked transition from pending: the event is dispatched once, whichever path
 * (webhook or return URL) settles the transaction first.
 */
interface SettlesFromGateway
{
    /**
     * Ask the gateway for the current state and settle the transaction if it is
     * still pending. Returns the transaction only when its status changed.
     */
    public function settle(Transaction $transaction): ?Transaction;
}
