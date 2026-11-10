<?php

namespace SunuCode\AfriPay\Exceptions;

use RuntimeException;
use SunuCode\AfriPay\Models\Transaction;

/**
 * The bank wants the customer, and no retry will change that.
 *
 * Strong customer authentication applies to cards even when the business
 * charges on its own. Stripe answers `authentication_required`, and the only
 * way through is to bring the customer back to a page and let them confirm.
 *
 * It is a distinct exception because the caller must do something different:
 * a declined card is retried later, this one is not. Treating both as a plain
 * failure means a customer is dunned and then suspended for a card that was
 * never refused.
 */
class RequiresCustomerAuthentication extends RuntimeException
{
    public function __construct(
        public readonly Transaction $transaction,
        public readonly ?string $redirectUrl = null,
    ) {
        parent::__construct('The card issuer requires the customer to confirm this payment.');
    }
}
