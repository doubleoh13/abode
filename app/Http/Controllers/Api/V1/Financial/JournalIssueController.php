<?php

namespace App\Http\Controllers\Api\V1\Financial;

use App\Http\Controllers\Controller;
use App\Support\Financial\JournalIssueFinder;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Financial / Journal')]
class JournalIssueController extends Controller
{
    /**
     * Soft integrity issues derived from the journal: lots driven negative
     * and transactions retroactively unbalanced by lot edits.
     */
    public function index(JournalIssueFinder $journalIssueFinder): JsonResponse
    {
        return response()->json(['data' => $journalIssueFinder->find()]);
    }
}
