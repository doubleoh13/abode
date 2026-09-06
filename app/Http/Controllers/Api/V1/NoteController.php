<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreNoteRequest;
use App\Http\Requests\UpdateNoteRequest;
use App\Http\Resources\NoteResource;
use App\Models\Note;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

#[Group('Notes')]
class NoteController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'noteable_type' => ['required', Rule::in(array_keys(Relation::morphMap()))],
            'noteable_id' => ['required', 'integer'],
        ]);

        $this->authorizeDomain($validated['noteable_type'], writing: false);

        $notes = Note::query()
            ->where('noteable_type', $validated['noteable_type'])
            ->where('noteable_id', $validated['noteable_id'])
            ->with('user')
            ->latest()
            ->get();

        return NoteResource::collection($notes);
    }

    public function store(StoreNoteRequest $request): NoteResource
    {
        $this->authorizeDomain($request->validated('noteable_type'), writing: true);

        $note = Note::query()->create([
            ...$request->validated(),
            'user_id' => $request->user()->id,
        ]);

        return new NoteResource($note->load('user'));
    }

    public function update(UpdateNoteRequest $request, Note $note): NoteResource
    {
        $this->authorizeDomain($note->noteable_type, writing: true);

        $note->update($request->validated());

        return new NoteResource($note->load('user'));
    }

    public function destroy(Note $note): Response
    {
        $this->authorizeDomain($note->noteable_type, writing: true);

        $note->delete();

        return response()->noContent();
    }

    /**
     * Notes inherit the access rules of the domain they annotate.
     */
    private function authorizeDomain(string $morphAlias, bool $writing): void
    {
        if (str_starts_with($morphAlias, 'financial.')) {
            Gate::authorize($writing ? 'manage-finances' : 'view-finances');
        }
    }
}
