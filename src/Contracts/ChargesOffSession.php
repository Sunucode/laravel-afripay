<?php

namespace SunuCode\AfriPay\Contracts;

use Illuminate\Database\Eloquent\Model;
use SunuCode\AfriPay\Models\PaymentMethod;
use SunuCode\AfriPay\Models\Transaction;

/**
 * A gateway that can charge a customer who is not there.
 *
 * `charge()` always needs the customer: it returns a URL they must open. That
 * is the right shape for Mobile Money, where every payment is authorised one
 * by one, and it is why AfriPay never had recurring billing.
 *
 * Card gateways can do more. The customer authorises once, and the gateway
 * keeps a handle the business can charge again later, on its own. Usage
 * billing and subscriptions both need exactly that, and nothing else.
 *
 * Optional on purpose, like SettlesFromGateway: a gateway that cannot do this
 * simply does not implement it, and PaymentManager checks before asking.
 */
interface ChargesOffSession
{
    /**
     * Open an authorisation page. Nothing is charged, the customer only
     * agrees that this payment method may be charged later.
     *
     * @param  array{
     *     success_url: string,
     *     error_url: string,
     *     reference?: string,
     *     description?: string,
     *     metadata?: array<string, mixed>,
     * }  $params
     * @return array{redirect_url: string, reference: string}
     */
    public function startSavingMethod(array $params): array;

    /**
     * Turn a completed authorisation into a stored payment method.
     *
     * Returns null when the authorisation is not complete, so a return page
     * opened too early, or opened twice, cannot store anything wrong.
     */
    public function confirmSavedMethod(string $gatewayReference, ?Model $payable = null): ?PaymentMethod;

    /**
     * Charge a stored payment method, without the customer.
     *
     * @param  array{
     *     amount: float,
     *     currency?: string,
     *     description?: string,
     *     reference?: string,
     *     metadata?: array<string, mixed>,
     * }  $params
     *
     * @throws \SunuCode\AfriPay\Exceptions\RequiresCustomerAuthentication when
     *         the bank asks for the customer, who must then be brought back.
     */
    public function chargeSaved(PaymentMethod $method, array $params): Transaction;
}
