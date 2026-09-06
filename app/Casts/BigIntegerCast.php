<?php

namespace App\Casts;

use Brick\Math\BigInteger;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * @implements CastsAttributes<BigInteger, BigInteger|int|string>
 */
class BigIntegerCast implements CastsAttributes
{
    /**
     * Cast the given value.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?BigInteger
    {
        return $value === null ? null : BigInteger::of($value);
    }

    /**
     * Prepare the given value for storage.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : (string) BigInteger::of($value);
    }
}
