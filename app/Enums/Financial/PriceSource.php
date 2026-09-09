<?php

namespace App\Enums\Financial;

enum PriceSource: string
{
    case Yahoo = 'yahoo';
    case In529 = 'in529';
    case Manual = 'manual';

    /**
     * Whether the fetch-prices command can pull quotes for this source.
     */
    public function fetchable(): bool
    {
        return $this !== self::Manual;
    }
}
