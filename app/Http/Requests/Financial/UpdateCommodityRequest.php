<?php

namespace App\Http\Requests\Financial;

use App\Models\Financial\Commodity;
use App\Models\Financial\Lot;
use App\Models\Financial\Posting;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\Validator;

class UpdateCommodityRequest extends StoreCommodityRequest
{
    /**
     * Get the "after" validation callables for the request.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('precision')) {
                    return;
                }

                $commodity = $this->route('commodity');

                assert($commodity instanceof Commodity);

                $precision = (int) $this->input('precision');

                if ($precision === $commodity->precision) {
                    return;
                }

                if ($precision > $commodity->precision) {
                    $additionalDigits = $precision - $commodity->precision;
                    $postingDigits = (int) Posting::query()
                        ->where('financial_commodity_id', $commodity->id)
                        ->selectRaw('max(length(abs(amount)::text)) as maximum_digits')
                        ->value('maximum_digits');
                    $lotCostDigits = $commodity->id === Commodity::baseCurrency()->id
                        ? (int) Lot::query()
                            ->selectRaw('max(length(abs(cost)::text)) as maximum_digits')
                            ->value('maximum_digits')
                        : 0;

                    if (max($postingDigits, $lotCostDigits) + $additionalDigits > 78) {
                        $validator->errors()->add(
                            'precision',
                            'Precision cannot increase because a stored value would exceed 78 digits.',
                        );
                    }

                    return;
                }

                $referenced = Posting::query()->where('financial_commodity_id', $commodity->id)->exists()
                    || Lot::query()->where('financial_commodity_id', $commodity->id)->exists();

                if ($referenced) {
                    $validator->errors()->add(
                        'precision',
                        'Precision cannot decrease while journal postings reference this commodity.',
                    );
                }
            },
        ];
    }

    protected function uniqueCodeRule(): Unique
    {
        return parent::uniqueCodeRule()->ignore($this->route('commodity'));
    }
}
