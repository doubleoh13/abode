<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAttachmentRequest;
use App\Http\Resources\AttachmentResource;
use App\Models\Attachment;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Group('Attachments')]
class AttachmentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'attachable_type' => ['required', Rule::in(array_keys(Relation::morphMap()))],
            'attachable_id' => ['required', 'integer'],
        ]);

        $this->authorizeDomain($validated['attachable_type'], writing: false);

        $attachments = Attachment::query()
            ->where('attachable_type', $validated['attachable_type'])
            ->where('attachable_id', $validated['attachable_id'])
            ->with('user')
            ->latest()
            ->get();

        return AttachmentResource::collection($attachments);
    }

    public function store(StoreAttachmentRequest $request): AttachmentResource
    {
        $this->authorizeDomain($request->validated('attachable_type'), writing: true);

        $file = $request->file('file');
        $disk = config('filesystems.default');
        $path = $file->store('attachments', $disk);

        $attachment = Attachment::query()->create([
            'attachable_type' => $request->validated('attachable_type'),
            'attachable_id' => $request->validated('attachable_id'),
            'user_id' => $request->user()->id,
            'disk' => $disk,
            'path' => $path,
            'name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'size' => $file->getSize(),
            'hash' => hash_file('sha256', $file->getRealPath()),
        ]);

        return new AttachmentResource($attachment->load('user'));
    }

    public function download(Attachment $attachment): StreamedResponse
    {
        $this->authorizeDomain($attachment->attachable_type, writing: false);

        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->name);
    }

    public function destroy(Attachment $attachment): Response
    {
        $this->authorizeDomain($attachment->attachable_type, writing: true);

        $attachment->delete();

        return response()->noContent();
    }

    /**
     * Attachments inherit the access rules of the domain they belong to.
     */
    private function authorizeDomain(string $morphAlias, bool $writing): void
    {
        if (str_starts_with($morphAlias, 'financial.')) {
            Gate::authorize($writing ? 'manage-finances' : 'view-finances');
        }
    }
}
