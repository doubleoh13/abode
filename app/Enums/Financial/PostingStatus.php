<?php

namespace App\Enums\Financial;

enum PostingStatus: string
{
    case Pending = 'pending';
    case Cleared = 'cleared';
    case Reconciled = 'reconciled';

    public function rank(): int
    {
        return match ($this) {
            self::Pending => 0,
            self::Cleared => 1,
            self::Reconciled => 2,
        };
    }
}
