<?php

namespace App\Rules;

use Brick\Math\BigDecimal;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class ExactDecimal implements ValidationRule
{
    public function __construct(
        private readonly bool $allowNegative,
        private readonly bool $allowZero,
        private readonly string $message,
    ) {}

    /** @param Closure(string, ?string=): PotentiallyTranslatedString $fail */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $sign = $this->allowNegative ? '-?' : '';

        if (! is_string($value) || preg_match('/^'.$sign.'(?:0|[1-9][0-9]{0,52})(?:\.[0-9]{1,25})?$/D', $value) !== 1) {
            $fail($this->message);

            return;
        }

        if (! $this->allowZero && BigDecimal::of($value)->isZero()) {
            $fail($this->message);
        }
    }
}
