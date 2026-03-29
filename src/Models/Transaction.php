<?php

namespace SunuCode\AfriPay\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use SunuCode\AfriPay\Enums\TransactionStatus;

class Transaction extends Model
{
    use SoftDeletes;

    protected $table = 'afripay_transactions';

    protected $fillable = [
        'gateway',
        'reference',
        'gateway_reference',
        'amount',
        'currency',
        'status',
        'gateway_response',
        'metadata',
        'processed_at',
        'payable_type',
        'payable_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'gateway_response' => 'array',
        'metadata' => 'array',
        'status' => TransactionStatus::class,
        'processed_at' => 'datetime',
    ];

    /**
     * The polymorphic payable model (subscription, order, etc.).
     */
    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Mark this transaction as processed (atomic, idempotent).
     * Returns true if this call actually set the flag, false if already processed.
     */
    public function markProcessed(): bool
    {
        if ($this->processed_at) {
            return false;
        }

        $updated = static::where('id', $this->id)
            ->whereNull('processed_at')
            ->update(['processed_at' => now()]);

        if ($updated) {
            $this->refresh();
        }

        return (bool) $updated;
    }

    /**
     * Check if the transaction has already been processed.
     */
    public function isProcessed(): bool
    {
        return $this->processed_at !== null;
    }

    /**
     * Scope: only pending transactions.
     */
    public function scopePending($query)
    {
        return $query->where('status', TransactionStatus::Pending);
    }

    /**
     * Scope: only completed transactions.
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', TransactionStatus::Completed);
    }

    /**
     * Scope: only failed transactions.
     */
    public function scopeFailed($query)
    {
        return $query->where('status', TransactionStatus::Failed);
    }

    /**
     * Scope: filter by gateway.
     */
    public function scopeForGateway($query, string $gateway)
    {
        return $query->where('gateway', $gateway);
    }
}
