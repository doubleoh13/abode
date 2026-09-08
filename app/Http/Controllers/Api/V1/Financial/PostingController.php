<?php

namespace App\Http\Controllers\Api\V1\Financial;

use App\Http\Controllers\Controller;
use App\Http\Requests\Financial\UpdatePostingRequest;
use App\Http\Resources\Financial\PostingResource;
use App\Models\Financial\Posting;
use Dedoc\Scramble\Attributes\Group;

#[Group('Financial / Transactions')]
class PostingController extends Controller
{
    /**
     * Update a posting's reconciliation status.
     */
    public function update(UpdatePostingRequest $request, Posting $posting): PostingResource
    {
        $posting->update($request->validated());

        return new PostingResource($posting->load(['account', 'commodity', 'lot']));
    }
}
