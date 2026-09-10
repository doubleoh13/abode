<?php

namespace App\Http\Controllers\Api\V1\Financial;

use App\Http\Controllers\Controller;
use App\Http\Requests\Financial\StoreRecurringTransactionRequest;
use App\Http\Resources\Financial\RecurringTransactionResource;
use App\Http\Resources\Financial\TransactionResource;
use App\Models\Financial\RecurringTransaction;
use App\Models\Financial\Transaction;
use App\Support\Financial\RecurringTransactionMaterializer;
use App\Support\Financial\RecurringTransactionWriter;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

#[Group('Financial / Recurring Transactions')]
class RecurringTransactionController extends Controller
{
    private const array EAGER_LOADS = ['payee', 'postings.account', 'postings.commodity'];

    private const array POSTED_EAGER_LOADS = ['payee', 'postings.account', 'postings.commodity', 'postings.lot'];

    public function __construct(
        private readonly RecurringTransactionWriter $recurringTransactionWriter,
        private readonly RecurringTransactionMaterializer $materializer,
    ) {}

    /**
     * Every schedule, soonest due first.
     */
    public function index(): AnonymousResourceCollection
    {
        return RecurringTransactionResource::collection(
            RecurringTransaction::query()
                ->with(self::EAGER_LOADS)
                ->orderBy('next_due_on')
                ->orderBy('id')
                ->get(),
        );
    }

    /**
     * The due dates a save with this payload would post immediately. Pass
     * the schedule being edited so its anchor is honored when the date is
     * unchanged.
     */
    public function preview(StoreRecurringTransactionRequest $request, ?RecurringTransaction $recurringTransaction = null): JsonResponse
    {
        $draft = $this->recurringTransactionWriter->draft($request->validated(), $recurringTransaction);

        return response()->json([
            'data' => [
                'due_dates' => array_map(
                    fn (CarbonImmutable $dueOn): string => $dueOn->toDateString(),
                    $this->materializer->dueOccurrences($draft),
                ),
            ],
        ]);
    }

    /**
     * Create a schedule and post every occurrence already due within the
     * lead window.
     */
    public function store(StoreRecurringTransactionRequest $request): JsonResponse
    {
        $result = $this->recurringTransactionWriter->store($request->validated());

        return $this->savedResponse($result['schedule'], $result['posted'], Response::HTTP_CREATED);
    }

    public function show(RecurringTransaction $recurringTransaction): RecurringTransactionResource
    {
        return new RecurringTransactionResource($recurringTransaction->load(self::EAGER_LOADS));
    }

    /**
     * Replace the schedule and its posting templates. Transactions already
     * posted from it are left untouched.
     */
    public function update(StoreRecurringTransactionRequest $request, RecurringTransaction $recurringTransaction): JsonResponse
    {
        $result = $this->recurringTransactionWriter->update($recurringTransaction, $request->validated());

        return $this->savedResponse($result['schedule'], $result['posted'], Response::HTTP_OK);
    }

    /**
     * Delete the schedule. Posted transactions stay in the journal and lose
     * only their link to it.
     */
    public function destroy(RecurringTransaction $recurringTransaction): Response
    {
        $this->recurringTransactionWriter->destroy($recurringTransaction);

        return response()->noContent();
    }

    /**
     * @param  list<Transaction>  $posted
     */
    private function savedResponse(RecurringTransaction $schedule, array $posted, int $status): JsonResponse
    {
        $resource = new RecurringTransactionResource($schedule->load(self::EAGER_LOADS));

        return $resource
            ->additional([
                'posted' => TransactionResource::collection(
                    collect($posted)->each(fn ($transaction) => $transaction->load(self::POSTED_EAGER_LOADS)),
                ),
            ])
            ->response()
            ->setStatusCode($status);
    }
}
