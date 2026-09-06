<?php

use App\Enums\Permission;
use App\Models\Financial\Account;
use App\Models\Note;

test('guests receive a 401', function () {
    $this->getJson('/api/v1/notes')->assertUnauthorized();
});

test('notes on financial records require view-finances', function () {
    $note = Note::factory()->create();

    actingWithPermissions();

    $this->getJson("/api/v1/notes?noteable_type=financial.account&noteable_id={$note->noteable_id}")
        ->assertForbidden();
});

test('writing a note on a financial record requires manage-finances', function () {
    $account = Account::factory()->create();

    actingWithPermissions(Permission::ViewFinances);

    $this->postJson('/api/v1/notes', [
        'noteable_type' => 'financial.account',
        'noteable_id' => $account->id,
        'body' => 'text',
    ])->assertForbidden();
});

describe('authenticated', function () {
    beforeEach(function () {
        actingWithPermissions(Permission::ViewFinances, Permission::ManageFinances);
    });

    test('a note can be attached to an account', function () {
        $account = Account::factory()->create();

        $this->postJson('/api/v1/notes', [
            'noteable_type' => 'financial.account',
            'noteable_id' => $account->id,
            'body' => 'Rate drops to 3.9% in March.',
        ])->assertCreated()
            ->assertJsonPath('data.body', 'Rate drops to 3.9% in March.')
            ->assertJsonPath('data.user.name', auth()->user()->name);

        expect($account->notes()->count())->toBe(1);
    });

    test('an unmapped morph type is rejected', function () {
        $this->postJson('/api/v1/notes', [
            'noteable_type' => 'App\\Models\\User',
            'noteable_id' => 1,
            'body' => 'text',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('noteable_type');
    });

    test('a missing target record is rejected', function () {
        $this->postJson('/api/v1/notes', [
            'noteable_type' => 'financial.account',
            'noteable_id' => 999999,
            'body' => 'text',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('noteable_id');
    });

    test('the index lists notes for one record only', function () {
        $account = Account::factory()->create();
        Note::factory()->count(2)->create(['noteable_id' => $account->id]);
        Note::factory()->create();

        $this->getJson("/api/v1/notes?noteable_type=financial.account&noteable_id={$account->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    });

    test('the index requires the noteable filter', function () {
        $this->getJson('/api/v1/notes')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['noteable_type', 'noteable_id']);
    });

    test('a note can be edited and soft deleted', function () {
        $note = Note::factory()->create();

        $this->putJson("/api/v1/notes/{$note->id}", ['body' => 'Updated.'])
            ->assertOk()
            ->assertJsonPath('data.body', 'Updated.');

        $this->deleteJson("/api/v1/notes/{$note->id}")->assertNoContent();

        $this->assertSoftDeleted($note);
    });
});
