<?php

namespace SunuCode\AfriPay\Enums;

enum TransactionStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente',
            self::Completed => 'Terminé',
            self::Failed => 'Échoué',
            self::Refunded => 'Remboursé',
        };
    }

    public function isPending(): bool
    {
        return $this === self::Pending;
    }

    public function isCompleted(): bool
    {
        return $this === self::Completed;
    }

    public function isFailed(): bool
    {
        return $this === self::Failed;
    }

    public function isRefunded(): bool
    {
        return $this === self::Refunded;
    }
}
