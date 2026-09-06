<?php

namespace App\Casts;

use Brick\Math\BigDecimal;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * @implements CastsAttributes<BigDecimal, BigDecimal|int|string>
 */
class BigDecimalCast implements CastsAttributes
{
    /**
     * Cast the given value.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?BigDecimal
    {
        return $value === null ? null : BigDecimal::of($value);
    }

    /**
     * Prepare the given value for storage.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if (is_float($value)) {
            throw new InvalidArgumentException('Exact decimals must be assigned as strings, integers, or BigDecimal values.');
        }

        return $value === null ? null : (string) BigDecimal::of($value);
    }
}
