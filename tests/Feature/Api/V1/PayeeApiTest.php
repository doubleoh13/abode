<?php

use App\Enums\Permission;
use App\Models\Financial\Payee;
use App\Models\Financial\Transaction;

test('guests receive a 401', function () {
    $this->getJson('/api/v1/financial/payees')->assertUnauthorized();
});

test('a viewer cannot write', function () {
    actingWithPermissions(Permission::ViewFinances);

    $this->postJson('/api/v1/financial/payees', ['name' => 'Kroger'])->assertForbidden();
});

describe('with finance permissions', function () {
    beforeEach(function () {
        actingWithPermissions(Permission::ViewFinances, Permission::ManageFinances);
    });

    test('a payee name is required with form language', function () {
        $this->postJson('/api/v1/financial/payees', [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'Enter a payee name.');
    });

    test('payees are listed alphabetically', function () {
        Payee::factory()->create(['name' => 'Kroger']);
        Payee::factory()->create(['name' => 'Costco']);

        $this->getJson('/api/v1/financial/payees')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Costco')
            ->assertJsonPath('data.1.name', 'Kroger');
    });

    test('a payee can be created and renamed', function () {
        $response = $this->postJson('/api/v1/financial/payees', ['name' => 'Kroger'])
            ->assertCreated();

        $this->putJson("/api/v1/financial/payees/{$response->json('data.id')}", [
            'name' => 'Kroger Marketplace',
        ])->assertOk()->assertJsonPath('data.name', 'Kroger Marketplace');
    });

    test('duplicate names are rejected', function () {
        Payee::factory()->create(['name' => 'Kroger']);

        $this->postJson('/api/v1/financial/payees', ['name' => 'Kroger'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'This payee already exists.');
    });

    test('a payee can be deleted', function () {
        $payee = Payee::factory()->create();

        $this->deleteJson("/api/v1/financial/payees/{$payee->id}")->assertNoContent();

        $this->assertModelMissing($payee);
    });

    test('deleting a payee on journal transactions conflicts', function () {
        $payee = Payee::factory()->create();
        Transaction::factory()->withPayee($payee)->create();

        $this->deleteJson("/api/v1/financial/payees/{$payee->id}")->assertConflict();
    });

    test('a note can be attached to a payee', function () {
        $payee = Payee::factory()->create();

        $this->postJson('/api/v1/notes', [
            'noteable_type' => 'financial.payee',
            'noteable_id' => $payee->id,
            'body' => 'Ask for the contractor discount.',
        ])->assertCreated();

        expect($payee->notes()->count())->toBe(1);
    });
});
