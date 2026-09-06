<?php

use App\Enums\Permission;
use App\Models\Financial\Account;
use App\Models\Financial\Institution;

test('guests receive a 401', function () {
    $this->getJson('/api/v1/financial/institutions')->assertUnauthorized();
});

test('a viewer cannot write', function () {
    actingWithPermissions(Permission::ViewFinances);

    $this->postJson('/api/v1/financial/institutions', ['name' => 'Fidelity'])->assertForbidden();
});

describe('with finance permissions', function () {
    beforeEach(function () {
        actingWithPermissions(Permission::ViewFinances, Permission::ManageFinances);
    });

    test('an institution name is required with form language', function () {
        $this->postJson('/api/v1/financial/institutions', [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'Enter an institution name.');
    });

    test('institutions are listed alphabetically', function () {
        Institution::factory()->create(['name' => 'Vanguard']);
        Institution::factory()->create(['name' => 'Fidelity']);

        $this->getJson('/api/v1/financial/institutions')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Fidelity')
            ->assertJsonPath('data.1.name', 'Vanguard');
    });

    test('an institution can be created and renamed', function () {
        $response = $this->postJson('/api/v1/financial/institutions', ['name' => 'Fidelity'])
            ->assertCreated();

        $this->putJson("/api/v1/financial/institutions/{$response->json('data.id')}", [
            'name' => 'Fidelity Investments',
        ])->assertOk()->assertJsonPath('data.name', 'Fidelity Investments');
    });

    test('duplicate names are rejected', function () {
        Institution::factory()->create(['name' => 'Fidelity']);

        $this->postJson('/api/v1/financial/institutions', ['name' => 'Fidelity'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'This institution already exists.');
    });

    test('deleting an institution with accounts conflicts', function () {
        $institution = Institution::factory()->create();
        Account::factory()->atInstitution($institution)->create();

        $this->deleteJson("/api/v1/financial/institutions/{$institution->id}")->assertConflict();
    });

    test('an empty institution can be deleted', function () {
        $institution = Institution::factory()->create();

        $this->deleteJson("/api/v1/financial/institutions/{$institution->id}")->assertNoContent();

        $this->assertModelMissing($institution);
    });
});
