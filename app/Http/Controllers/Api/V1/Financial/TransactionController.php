<?php

namespace App\Http\Controllers\Api\V1\Financial;

use App\Enums\Financial\PostingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Financial\MergeTransactionsRequest;
use App\Http\Requests\Financial\StoreTransactionRequest;
use App\Http\Requests\Financial\UpdateTransactionRequest;
use App\Http\Resources\Financial\TransactionResource;
use App\Models\Financial\Account;
use App\Models\Financial\Transaction;
use App\Support\Financial\TransactionWriter;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

#[Group('Financial / Transactions')]
class TransactionController extends Controller
{
    private const array EAGER_LOADS = ['payee', 'postings.account', 'postings.commodity', 'postings.lot', 'postings.bankTransaction'];

    public function __construct(private readonly TransactionWriter $transactionWriter) {}

    /**
     * Journal transactions, newest first. Optional filters: an account
     * (including its descendants), a payee/memo search, a date range, and
     * the derived transaction status — included via `status`, excluded via
     * `exclude_status`.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'financial_account_id' => ['nullable', 'integer', Rule::exists(Account::class, 'id')],
            'search' => ['nullable', 'string', 'max:255'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'status' => ['nullable', 'array'],
            'status.*' => [Rule::enum(PostingStatus::class)],
            'exclude_status' => ['nullable', 'array'],
            'exclude_status.*' => [Rule::enum(PostingStatus::class)],
        ]);

        return TransactionResource::collection(
            Transaction::query()
                ->with(self::EAGER_LOADS)
                ->when($validated['financial_account_id'] ?? null, fn (Builder $query, int $accountId) => $query
                    ->whereHas('postings', fn (Builder $postings) => $postings
                        ->whereIn('financial_account_id', $this->accountAndDescendantIds($accountId))))
                ->when($validated['search'] ?? null, fn (Builder $query, string $search) => $query
                    ->where(fn (Builder $matches) => $matches
                        ->where('memo', 'ilike', "%{$search}%")
                        ->orWhereHas('payee', fn (Builder $payee) => $payee->where('name', 'ilike', "%{$search}%"))))
                ->when($validated['from'] ?? null, fn (Builder $query, string $from) => $query->where('date', '>=', $from))
                ->when($validated['to'] ?? null, fn (Builder $query, string $to) => $query->where('date', '<=', $to))
                ->when($validated['status'] ?? null, fn (Builder $query, array $statuses) => $query
                    ->where(fn (Builder $matches) => $this->whereAnyDerivedStatus($matches, $statuses)))
                ->when($validated['exclude_status'] ?? null, fn (Builder $query, array $statuses) => $query
                    ->whereNot(fn (Builder $matches) => $this->whereAnyDerivedStatus($matches, $statuses)))
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->paginate(50)
                ->withQueryString(),
        );
    }

    /**
     * @param  list<string>  $statuses
     */
    private function whereAnyDerivedStatus(Builder $query, array $statuses): void
    {
        foreach ($statuses as $status) {
            $query->orWhere(fn (Builder $derived) => $this->whereDerivedStatus($derived, PostingStatus::from($status)));
        }
    }

    /**
     * Derived status is the least-advanced Asset/Liability posting status;
     * match transactions holding the target status with nothing less advanced.
     */
    private function whereDerivedStatus(Builder $query, PostingStatus $status): void
    {
        $lessAdvanced = array_filter(
            PostingStatus::cases(),
            fn (PostingStatus $candidate): bool => $candidate->rank() < $status->rank(),
        );

        $query->whereHas('postings', fn (Builder $postings) => $postings->where('status', $status));

        if ($lessAdvanced !== []) {
            $query->whereDoesntHave('postings', fn (Builder $postings) => $postings->whereIn('status', $lessAdvanced));
        }
    }

    /**
     * @return list<int>
     */
    private function accountAndDescendantIds(int $accountId): array
    {
        $childrenByParent = Account::query()->get(['id', 'parent_id'])->groupBy('parent_id');
        $ids = [$accountId];

        for ($index = 0; $index < count($ids); $index++) {
            foreach ($childrenByParent->get($ids[$index]) ?? [] as $child) {
                $ids[] = $child->id;
            }
        }

        return $ids;
    }

    public function store(StoreTransactionRequest $request): TransactionResource
    {
        $transaction = $this->transactionWriter->store($request->validated());

        return new TransactionResource($transaction->load(self::EAGER_LOADS));
    }

    /**
     * Replace two transactions with a single new one. The payload is a full
     * transaction; the originals are deleted and their notes, attachments,
     * and schedule link move to the result.
     */
    public function merge(MergeTransactionsRequest $request): TransactionResource
    {
        $transaction = $this->transactionWriter->merge($request->absorbedTransactionIds(), $request->validated());

        return new TransactionResource($transaction->load(self::EAGER_LOADS));
    }

    public function show(Transaction $transaction): TransactionResource
    {
        return new TransactionResource($transaction->load(self::EAGER_LOADS));
    }

    public function update(UpdateTransactionRequest $request, Transaction $transaction): TransactionResource
    {
        $transaction = $this->transactionWriter->update($transaction, $request->validated());

        return new TransactionResource($transaction->load(self::EAGER_LOADS));
    }

    public function destroy(Transaction $transaction): Response
    {
        abort_if(
            $transaction->opensLotsConsumedElsewhere(),
            Response::HTTP_CONFLICT,
            'Other transactions consume lots opened by this transaction.',
        );

        $this->transactionWriter->destroy($transaction);

        return response()->noContent();
    }
}
