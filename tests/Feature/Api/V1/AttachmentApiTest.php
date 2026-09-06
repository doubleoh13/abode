<?php

use App\Enums\Permission;
use App\Models\Attachment;
use App\Models\Financial\Account;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

test('guests receive a 401', function () {
    $this->getJson('/api/v1/attachments')->assertUnauthorized();
});

test('downloading a financial attachment requires view-finances', function () {
    $attachment = Attachment::factory()->create();

    actingWithPermissions();

    $this->get("/api/v1/attachments/{$attachment->id}/download")->assertForbidden();
});

describe('authenticated', function () {
    beforeEach(function () {
        Storage::fake(config('filesystems.default'));
        actingWithPermissions(Permission::ViewFinances, Permission::ManageFinances);
    });

    test('a file can be attached to an account', function () {
        $account = Account::factory()->create();
        $file = UploadedFile::fake()->create('statement.pdf', 120, 'application/pdf');

        $response = $this->postJson('/api/v1/attachments', [
            'attachable_type' => 'financial.account',
            'attachable_id' => $account->id,
            'file' => $file,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'statement.pdf')
            ->assertJsonPath('data.mime_type', 'application/pdf');

        $attachment = Attachment::query()->sole();

        Storage::disk($attachment->disk)->assertExists($attachment->path);
        expect($attachment->hash)->toHaveLength(64)
            ->and($account->attachments()->count())->toBe(1);
    });

    test('the stored hash matches the file content', function () {
        $account = Account::factory()->create();
        $file = UploadedFile::fake()->createWithContent('note.txt', 'known content');

        $this->postJson('/api/v1/attachments', [
            'attachable_type' => 'financial.account',
            'attachable_id' => $account->id,
            'file' => $file,
        ])->assertCreated();

        expect(Attachment::query()->sole()->hash)->toBe(hash('sha256', 'known content'));
    });

    test('an attachment can be downloaded with its original name', function () {
        $account = Account::factory()->create();
        $file = UploadedFile::fake()->createWithContent('statement.pdf', 'pdf bytes');

        $id = $this->postJson('/api/v1/attachments', [
            'attachable_type' => 'financial.account',
            'attachable_id' => $account->id,
            'file' => $file,
        ])->json('data.id');

        $this->get("/api/v1/attachments/{$id}/download")
            ->assertOk()
            ->assertDownload('statement.pdf');
    });

    test('deleting soft deletes the row and keeps the file', function () {
        $account = Account::factory()->create();

        $id = $this->postJson('/api/v1/attachments', [
            'attachable_type' => 'financial.account',
            'attachable_id' => $account->id,
            'file' => UploadedFile::fake()->create('statement.pdf', 10),
        ])->json('data.id');

        $this->deleteJson("/api/v1/attachments/{$id}")->assertNoContent();

        $attachment = Attachment::withTrashed()->findOrFail($id);
        $this->assertSoftDeleted($attachment);
        Storage::disk($attachment->disk)->assertExists($attachment->path);
    });

    test('a missing target record is rejected', function () {
        $this->postJson('/api/v1/attachments', [
            'attachable_type' => 'financial.account',
            'attachable_id' => 999999,
            'file' => UploadedFile::fake()->create('statement.pdf', 10),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('attachable_id');
    });
});
