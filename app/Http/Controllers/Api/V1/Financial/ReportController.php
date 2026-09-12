<?php

namespace App\Http\Controllers\Api\V1\Financial;

use App\Http\Controllers\Controller;
use App\Support\Financial\BalanceSheetBuilder;
use App\Support\Financial\IncomeStatementBuilder;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Financial / Reports')]
class ReportController extends Controller
{
    /**
     * Asset and liability holdings as of the end of a date (default today),
     * valued at the last known price on or before it, with lot-derived cost
     * basis and signed section totals.
     */
    public function balanceSheet(Request $request, BalanceSheetBuilder $balanceSheet): JsonResponse
    {
        $validated = $request->validate([
            'as_of' => ['nullable', 'date'],
        ]);

        $asOf = isset($validated['as_of'])
            ? CarbonImmutable::parse($validated['as_of'])
            : CarbonImmutable::today();

        return response()->json(['data' => $balanceSheet->build($asOf)]);
    }

    /**
     * Income and expense activity between two dates inclusive (default: the
     * current year through today), with income earned and expenses spent
     * both positive and a net line.
     */
    public function incomeStatement(Request $request, IncomeStatementBuilder $incomeStatement): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $from = isset($validated['from'])
            ? CarbonImmutable::parse($validated['from'])
            : CarbonImmutable::today()->startOfYear();
        $to = isset($validated['to'])
            ? CarbonImmutable::parse($validated['to'])
            : CarbonImmutable::today();

        return response()->json(['data' => $incomeStatement->build($from, $to)]);
    }
}
