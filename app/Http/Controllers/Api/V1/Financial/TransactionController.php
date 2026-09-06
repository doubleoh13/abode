<?php

namespace App\Http\Controllers\Api\V1\Financial;

use App\Http\Controllers\Controller;
use App\Http\Requests\Financial\StoreTransactionRequest;
use App\Http\Requests\Financial\UpdateTransactionRequest;
use App\Http\Resources\Financial\TransactionResource;
use App\Models\Financial\Transaction;
use App\Support\Financial\TransactionWriter;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

#[Group('Financial / Transactions')]
class TransactionController extends Controller
{
    private const array EAGER_LOADS = ['payee', 'postings.account', 'postings.commodity', 'postings.lot'];

    public function __construct(private readonly TransactionWriter $transactionWriter) {}

    public function index(): AnonymousResourceCollection
    {
        return TransactionResource::collection(
            Transaction::query()
                ->with(self::EAGER_LOADS)
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->paginate(50),
        );
    }

    public function store(StoreTransactionRequest $request): TransactionResource
    {
        $transaction = $this->transactionWriter->store($request->validated());

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
