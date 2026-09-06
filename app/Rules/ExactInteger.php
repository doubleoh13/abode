<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class ExactInteger implements ValidationRule
{
    public function __construct(
        private readonly bool $allowNegative,
        private readonly bool $allowZero,
        private readonly string $message,
        private readonly int $maximumDigits = 78,
    ) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail($this->message);

            return;
        }

        $pattern = $this->allowNegative ? '/^-?(?:0|[1-9][0-9]*)$/' : '/^(?:0|[1-9][0-9]*)$/';

        if (preg_match($pattern, $value) !== 1) {
            $fail($this->message);

            return;
        }

        $digits = ltrim($value, '-');

        if (strlen($digits) > $this->maximumDigits || (! $this->allowZero && $digits === '0')) {
            $fail($this->message);
        }
    }
}
