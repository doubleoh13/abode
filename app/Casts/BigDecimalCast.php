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
        return $value === null ? null : BigDecimal::of($value)->strippedOfTrailingZeros();
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

        if ($value === null) {
            return null;
        }

        $decimal = BigDecimal::of($value)->toScale(25);

        if ($decimal->abs()->isGreaterThanOrEqualTo('1'.str_repeat('0', 53))) {
            throw new InvalidArgumentException('Exact decimals cannot exceed 53 integer digits.');
        }

        return (string) $decimal->strippedOfTrailingZeros();
    }
}
