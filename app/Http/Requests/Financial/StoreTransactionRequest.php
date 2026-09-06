<?php

namespace App\Http\Requests\Financial;

use App\Enums\Financial\AccountType;
use App\Enums\Financial\PostingStatus;
use App\Models\Financial\Account;
use App\Models\Financial\Commodity;
use App\Models\Financial\Lot;
use App\Models\Financial\Payee;
use App\Rules\ExactInteger;
use App\Support\Financial\CostBasisBalancer;
use Brick\Math\BigInteger;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreTransactionRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date'],
            'financial_payee_id' => ['nullable', 'integer', Rule::exists(Payee::class, 'id')],
            'memo' => ['nullable', 'string', 'max:255'],
            'metadata' => ['sometimes', 'array'],
            'postings' => ['required', 'array', 'min:2'],
            'postings.*.status' => ['nullable', Rule::enum(PostingStatus::class)],
            'postings.*.financial_account_id' => ['required', 'integer', Rule::exists(Account::class, 'id')],
            'postings.*.financial_commodity_id' => ['required', 'integer', Rule::exists(Commodity::class, 'id')],
            'postings.*.amount' => [
                'required',
                new ExactInteger(allowNegative: true, allowZero: false, message: 'Enter a valid nonzero amount.'),
            ],
            'postings.*.memo' => ['nullable', 'string', 'max:255'],
            'postings.*.metadata' => ['sometimes', 'array'],
            'postings.*.financial_lot_id' => ['nullable', 'integer', Rule::exists(Lot::class, 'id')],
            'postings.*.lot' => ['nullable', 'array'],
            'postings.*.lot.acquired_at' => ['nullable', 'date'],
            'postings.*.lot.cost' => [
                'required_with:postings.*.lot',
                new ExactInteger(allowNegative: false, allowZero: true, message: 'Enter a valid nonnegative lot cost.'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date.required' => 'Enter a transaction date.',
            'date.date' => 'Enter a valid transaction date.',
            'financial_payee_id.integer' => 'Choose a valid payee.',
            'financial_payee_id.exists' => 'Choose a valid payee.',
            'memo.string' => 'Enter a valid memo.',
            'memo.max' => 'The transaction memo may not exceed 255 characters.',
            'metadata.array' => 'Transaction metadata must be an array.',
            'postings.required' => 'Add at least two postings.',
            'postings.array' => 'Postings must be an array.',
            'postings.min' => 'Add at least two postings.',
            'postings.*.status.enum' => 'Choose a valid status.',
            'postings.*.financial_account_id.required' => 'Choose an account.',
            'postings.*.financial_account_id.integer' => 'Choose a valid account.',
            'postings.*.financial_account_id.exists' => 'Choose a valid account.',
            'postings.*.financial_commodity_id.required' => 'Choose a commodity.',
            'postings.*.financial_commodity_id.integer' => 'Choose a valid commodity.',
            'postings.*.financial_commodity_id.exists' => 'Choose a valid commodity.',
            'postings.*.amount.required' => 'Enter an amount.',
            'postings.*.memo.string' => 'Enter a valid posting memo.',
            'postings.*.memo.max' => 'Posting memos may not exceed 255 characters.',
            'postings.*.metadata.array' => 'Posting metadata must be an array.',
            'postings.*.financial_lot_id.integer' => 'Choose a valid lot.',
            'postings.*.financial_lot_id.exists' => 'Choose a valid lot.',
            'postings.*.lot.array' => 'New lot details must be an array.',
            'postings.*.lot.acquired_at.date' => 'Enter a valid acquisition date.',
            'postings.*.lot.cost.required_with' => 'Enter the lot cost.',
        ];
    }

    /**
     * Get the "after" validation callables for the request. Each check skips
     * when anything before it failed, so the balance check only ever runs
     * against structurally sound postings.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->validatePostingStatuses($validator),
            fn (Validator $validator) => $this->validateLotStructure($validator),
            fn (Validator $validator) => $this->validateReferencedLots($validator),
            fn (Validator $validator) => $this->validateBalance($validator),
        ];
    }

    protected function validatePostingStatuses(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $accounts = Account::query()
            ->findMany(collect($this->postingInputs())->pluck('financial_account_id')->unique())
            ->keyBy('id');

        foreach ($this->postingInputs() as $index => $posting) {
            $account = $accounts->get((int) $posting['financial_account_id']);

            if ($account === null) {
                continue;
            }

            $status = $posting['status'] ?? null;
            $carriesStatus = in_array($account->account_type, [AccountType::Asset, AccountType::Liability], true);

            if ($carriesStatus && $status === null) {
                $validator->errors()->add("postings.{$index}.status", 'A status is required for asset and liability postings.');
            }

            if (! $carriesStatus && $status !== null) {
                $validator->errors()->add("postings.{$index}.status", 'Only asset and liability postings may have a status.');
            }
        }
    }

    protected function validateLotStructure(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $baseCurrencyId = Commodity::baseCurrency()->id;

        foreach ($this->postingInputs() as $index => $posting) {
            $referencesLot = isset($posting['financial_lot_id']);
            $createsLot = isset($posting['lot']);

            if ((int) $posting['financial_commodity_id'] === $baseCurrencyId) {
                if ($referencesLot || $createsLot) {
                    $validator->errors()->add(
                        "postings.{$index}.".($referencesLot ? 'financial_lot_id' : 'lot'),
                        'A base-currency posting cannot reference a lot.',
                    );
                }

                continue;
            }

            if ($referencesLot && $createsLot) {
                $validator->errors()->add(
                    "postings.{$index}.financial_lot_id",
                    'Provide either an existing lot or a new lot, not both.',
                );
            }

            if (! $referencesLot && ! $createsLot) {
                $validator->errors()->add(
                    "postings.{$index}.financial_lot_id",
                    'A non-currency posting must reference or create a lot.',
                );
            }

            if ($createsLot && BigInteger::of($posting['amount'])->isNegativeOrZero()) {
                $validator->errors()->add(
                    "postings.{$index}.amount",
                    'A new lot requires a positive amount.',
                );
            }
        }
    }

    protected function validateReferencedLots(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $lots = $this->referencedLots();
        $acquiredQuantities = $this->acquiredQuantities($lots);

        foreach ($this->postingInputs() as $index => $posting) {
            if (! isset($posting['financial_lot_id'])) {
                continue;
            }

            $lot = $lots->get((int) $posting['financial_lot_id']);

            if ($lot->financial_commodity_id !== (int) $posting['financial_commodity_id']) {
                $validator->errors()->add(
                    "postings.{$index}.financial_lot_id",
                    'The lot holds a different commodity than the posting.',
                );

                continue;
            }

            if ($acquiredQuantities[$lot->id]->isNegativeOrZero()) {
                $validator->errors()->add(
                    "postings.{$index}.financial_lot_id",
                    'The lot has no acquired quantity to draw basis from.',
                );
            }
        }
    }

    protected function validateBalance(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $baseCurrencyId = Commodity::baseCurrency()->id;
        $lots = $this->referencedLots();
        $acquiredQuantities = $this->acquiredQuantities($lots);
        $legs = [];

        foreach ($this->postingInputs() as $index => $posting) {
            if ((int) $posting['financial_commodity_id'] === $baseCurrencyId) {
                $legs[] = ['is_base' => true, 'amount' => BigInteger::of($posting['amount']), 'lot_key' => null, 'lot_cost' => null, 'lot_total_quantity' => null];
            } elseif (isset($posting['lot'])) {
                $amount = BigInteger::of($posting['amount']);
                $legs[] = ['is_base' => false, 'amount' => $amount, 'lot_key' => "new-{$index}", 'lot_cost' => BigInteger::of($posting['lot']['cost']), 'lot_total_quantity' => $amount];
            } else {
                $lot = $lots->get((int) $posting['financial_lot_id']);
                $legs[] = ['is_base' => false, 'amount' => BigInteger::of($posting['amount']), 'lot_key' => $lot->id, 'lot_cost' => $lot->cost, 'lot_total_quantity' => $acquiredQuantities[$lot->id]];
            }
        }

        $residual = new CostBasisBalancer()->residual($legs);

        if (! $residual->isZero()) {
            $validator->errors()->add(
                'postings',
                'Postings must balance at cost.',
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function postingInputs(): array
    {
        return array_values($this->input('postings', []));
    }

    /**
     * @return Collection<int, Lot>
     */
    protected function referencedLots(): Collection
    {
        return Lot::query()
            ->findMany(collect($this->postingInputs())->pluck('financial_lot_id')->filter()->unique())
            ->keyBy('id');
    }

    /**
     * Total acquired quantity per referenced lot: persisted acquisitions
     * outside this transaction plus positive payload amounts drawing on it.
     *
     * @param  Collection<int, Lot>  $lots
     * @return array<int, BigInteger>
     */
    protected function acquiredQuantities(Collection $lots): array
    {
        $quantities = [];

        foreach ($lots as $lot) {
            $quantities[$lot->id] = $lot->acquiredQuantity($this->excludedTransactionId());
        }

        foreach ($this->postingInputs() as $posting) {
            $amount = BigInteger::of($posting['amount'] ?? 0);

            if (isset($posting['financial_lot_id']) && $amount->isPositive()) {
                $lotId = (int) $posting['financial_lot_id'];
                $quantities[$lotId] = $quantities[$lotId]->plus($amount);
            }
        }

        return $quantities;
    }

    protected function excludedTransactionId(): ?int
    {
        return null;
    }
}
