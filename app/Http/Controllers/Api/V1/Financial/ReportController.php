<?php

namespace App\Http\Controllers\Api\V1\Financial;

use App\Http\Controllers\Controller;
use App\Support\Financial\BalanceSheetBuilder;
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
}
