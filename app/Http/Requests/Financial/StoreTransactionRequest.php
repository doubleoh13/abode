<?php

namespace App\Http\Requests\Financial;

use App\Enums\Financial\AccountType;
use App\Enums\Financial\PostingStatus;
use App\Models\Financial\Account;
use App\Models\Financial\Commodity;
use App\Models\Financial\Lot;
use App\Models\Financial\Payee;
use App\Support\Financial\CostBasisBalancer;
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
            'postings.*.amount' => ['required', 'integer', 'not_in:0'],
            'postings.*.memo' => ['nullable', 'string', 'max:255'],
            'postings.*.metadata' => ['sometimes', 'array'],
            'postings.*.financial_lot_id' => ['nullable', 'integer', Rule::exists(Lot::class, 'id')],
            'postings.*.lot' => ['nullable', 'array'],
            'postings.*.lot.acquired_at' => ['nullable', 'date'],
            'postings.*.lot.cost' => ['required_with:postings.*.lot', 'integer', 'min:0'],
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

            if ($createsLot && (int) $posting['amount'] <= 0) {
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

            if ($acquiredQuantities[$lot->id] <= 0) {
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
                $legs[] = ['is_base' => true, 'amount' => (int) $posting['amount'], 'lot_key' => null, 'lot_cost' => null, 'lot_total_quantity' => null];
            } elseif (isset($posting['lot'])) {
                $legs[] = ['is_base' => false, 'amount' => (int) $posting['amount'], 'lot_key' => "new-{$index}", 'lot_cost' => (int) $posting['lot']['cost'], 'lot_total_quantity' => (int) $posting['amount']];
            } else {
                $lot = $lots->get((int) $posting['financial_lot_id']);
                $legs[] = ['is_base' => false, 'amount' => (int) $posting['amount'], 'lot_key' => $lot->id, 'lot_cost' => $lot->cost, 'lot_total_quantity' => $acquiredQuantities[$lot->id]];
            }
        }

        $residual = new CostBasisBalancer()->residual($legs);

        if ($residual !== 0) {
            $validator->errors()->add(
                'postings',
                "Postings must balance at cost (off by {$residual} USD minor units).",
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
     * @return array<int, int>
     */
    protected function acquiredQuantities(Collection $lots): array
    {
        $quantities = [];

        foreach ($lots as $lot) {
            $quantities[$lot->id] = $lot->acquiredQuantity($this->excludedTransactionId());
        }

        foreach ($this->postingInputs() as $posting) {
            $amount = (int) ($posting['amount'] ?? 0);

            if (isset($posting['financial_lot_id']) && $amount > 0) {
                $quantities[(int) $posting['financial_lot_id']] += $amount;
            }
        }

        return $quantities;
    }

    protected function excludedTransactionId(): ?int
    {
        return null;
    }
}
