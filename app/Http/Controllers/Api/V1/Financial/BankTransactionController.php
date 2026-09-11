<?php

namespace App\Http\Controllers\Api\V1\Financial;

use App\Enums\Financial\PostingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Financial\MatchBankTransactionRequest;
use App\Http\Resources\Financial\BankTransactionResource;
use App\Models\Financial\Account;
use App\Models\Financial\BankTransaction;
use App\Models\Financial\Posting;
use App\Support\Financial\BankTransactionMatcher;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

#[Group('Financial / Bank Transactions')]
class BankTransactionController extends Controller
{
    /**
     * An account's bank-reported rows not yet linked to a posting, newest
     * first. `candidate_posting_id` names the posting a row plainly settles
     * (same amount, dates within the match window, unambiguous) awaiting
     * approval; null when a person has to decide.
     */
    public function index(Request $request, BankTransactionMatcher $matcher): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'financial_account_id' => ['required', 'integer', Rule::exists(Account::class, 'id')],
        ]);

        $account = Account::query()->findOrFail($validated['financial_account_id']);

        $rows = BankTransaction::query()
            ->where('financial_account_id', $account->id)
            ->whereNull('financial_posting_id')
            ->orderByDesc('posted_on')
            ->orderByDesc('id')
            ->get();

        $proposals = $matcher->proposals($account, $rows);

        foreach ($rows as $row) {
            $row->setAttribute('candidate_posting_id', $proposals[$row->id] ?? null);
        }

        return BankTransactionResource::collection($rows);
    }

    /**
     * Link the bank row to the posting that settles it. A posting still
     * pending is marked cleared once the bank reports the row as posted.
     */
    public function match(MatchBankTransactionRequest $request, BankTransaction $bankTransaction): BankTransactionResource
    {
        abort_if($bankTransaction->financial_posting_id !== null, Response::HTTP_CONFLICT, 'This bank transaction is already matched.');

        $posting = $request->posting();

        DB::transaction(function () use ($bankTransaction, $posting): void {
            $bankTransaction->update(['financial_posting_id' => $posting->id]);

            if (! $bankTransaction->pending && $posting->status === PostingStatus::Pending) {
                $posting->update(['status' => PostingStatus::Cleared]);
            }
        });

        return new BankTransactionResource($bankTransaction->refresh());
    }

    /**
     * Undo a match. The row returns to the unmatched list and the posting it
     * settled is recorded as declined so it is not proposed straight back.
     */
    public function unmatch(BankTransaction $bankTransaction): BankTransactionResource
    {
        abort_if($bankTransaction->financial_posting_id === null, Response::HTTP_CONFLICT, 'This bank transaction is not matched.');

        $bankTransaction->update([
            'financial_posting_id' => null,
            'rejected_posting_ids' => array_values(array_unique([...($bankTransaction->rejected_posting_ids ?? []), $bankTransaction->financial_posting_id])),
        ]);

        return new BankTransactionResource($bankTransaction->refresh());
    }

    /**
     * Decline a proposed posting so it is never suggested for this row
     * again. The row returns to the unmatched list.
     */
    public function reject(Request $request, BankTransaction $bankTransaction): BankTransactionResource
    {
        $validated = $request->validate([
            'financial_posting_id' => ['required', 'integer', Rule::exists(Posting::class, 'id')],
        ]);

        abort_if($bankTransaction->financial_posting_id !== null, Response::HTTP_CONFLICT, 'This bank transaction is already matched.');

        $bankTransaction->update([
            'rejected_posting_ids' => array_values(array_unique([...($bankTransaction->rejected_posting_ids ?? []), (int) $validated['financial_posting_id']])),
        ]);

        return new BankTransactionResource($bankTransaction->refresh());
    }
}
