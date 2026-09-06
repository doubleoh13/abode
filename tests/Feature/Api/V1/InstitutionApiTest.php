<?php

use App\Enums\Permission;
use App\Models\Account;
use App\Models\Institution;

test('guests receive a 401', function () {
    $this->getJson('/api/v1/institutions')->assertUnauthorized();
});

test('a viewer cannot write', function () {
    actingWithPermissions(Permission::ViewFinances);

    $this->postJson('/api/v1/institutions', ['name' => 'Fidelity'])->assertForbidden();
});

describe('with finance permissions', function () {
    beforeEach(function () {
        actingWithPermissions(Permission::ViewFinances, Permission::ManageFinances);
    });

    test('institutions are listed alphabetically', function () {
        Institution::factory()->create(['name' => 'Vanguard']);
        Institution::factory()->create(['name' => 'Fidelity']);

        $this->getJson('/api/v1/institutions')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Fidelity')
            ->assertJsonPath('data.1.name', 'Vanguard');
    });

    test('an institution can be created and renamed', function () {
        $response = $this->postJson('/api/v1/institutions', ['name' => 'Fidelity'])
            ->assertCreated();

        $this->putJson("/api/v1/institutions/{$response->json('data.id')}", [
            'name' => 'Fidelity Investments',
        ])->assertOk()->assertJsonPath('data.name', 'Fidelity Investments');
    });

    test('duplicate names are rejected', function () {
        Institution::factory()->create(['name' => 'Fidelity']);

        $this->postJson('/api/v1/institutions', ['name' => 'Fidelity'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    });

    test('deleting an institution with accounts conflicts', function () {
        $institution = Institution::factory()->create();
        Account::factory()->atInstitution($institution)->create();

        $this->deleteJson("/api/v1/institutions/{$institution->id}")->assertConflict();
    });

    test('an empty institution can be deleted', function () {
        $institution = Institution::factory()->create();

        $this->deleteJson("/api/v1/institutions/{$institution->id}")->assertNoContent();

        $this->assertModelMissing($institution);
    });
});
