<?php

namespace App\Exceptions\Financial;

use App\Models\Financial\Commodity;
use RuntimeException;
use Throwable;

class PriceFetchFailed extends RuntimeException
{
    public static function for(Commodity $commodity, Throwable $cause): self
    {
        return new self(
            "{$commodity->code} ({$commodity->price_symbol} via {$commodity->price_source?->value}): {$cause->getMessage()}",
            previous: $cause,
        );
    }
}
