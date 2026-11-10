<?php

namespace SunuCode\AfriPay\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A payment method the business may charge again, without the customer.
 *
 * It holds handles, never a card number: the gateway keeps the card. What is
 * stored here is what is needed to charge it, plus what a human needs to
 * recognise it on screen.
 *
 * @property string $gateway
 * @property string $gateway_customer_id
 * @property string $gateway_method_id
 * @property string|null $brand
 * @property string|null $last4
 * @property int|null $exp_month
 * @property int|null $exp_year
 * @property bool $is_default
 */
class PaymentMethod extends Model
{
    use SoftDeletes;

    protected $table = 'afripay_payment_methods';

    protected $fillable = [
        'gateway',
        'gateway_customer_id',
        'gateway_method_id',
        'brand',
        'last4',
        'exp_month',
        'exp_year',
        'payable_type',
        'payable_id',
        'is_default',
        'metadata',
    ];

    protected $casts = [
        'exp_month' => 'integer',
        'exp_year' => 'integer',
        'is_default' => 'boolean',
        'metadata' => 'array',
    ];

    /**
     * Whatever the host application bills: an organisation, a tenant, a user.
     *
     * @return MorphTo<Model, $this>
     */
    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * A card the gateway will refuse before it is even tried.
     *
     * The expiry is known in advance, so it is worth saying so before a
     * billing run fails: a customer warned a month ahead replaces the card,
     * a customer warned by a service cut does not.
     */
    public function hasExpired(): bool
    {
        if ($this->exp_year === null || $this->exp_month === null) {
            return false;
        }

        return now()->startOfMonth()->greaterThan(
            now()->setDate($this->exp_year, $this->exp_month, 1)->startOfMonth(),
        );
    }

    /**
     * « Visa ···· 4242 », pour l'écran.
     */
    public function label(): string
    {
        return trim(($this->brand ?? '').' ···· '.($this->last4 ?? '????'));
    }
}
