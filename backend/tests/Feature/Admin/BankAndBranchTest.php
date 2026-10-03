<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function bankPayload(array $overrides = []): array
{
    return array_merge([
        'bank_name' => 'Diamond Bank',
        'swift_code' => 'dbnkgmgm',
        'established_date' => '2001-05-10',
    ], $overrides);
}

function branchPayload(array $overrides = []): array
{
    return array_merge([
        'branch_name' => 'Banjul Main',
        'branch_code' => 'bjl001',
        'address' => '1 Independence Drive, Banjul',
        'phone' => '4220000',
        'opened_date' => '2001-06-01',
    ], $overrides);
}

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
});

it('creates the bank once, then updates the same row', function () {
    $this->getJson('/api/v1/admin/bank')->assertOk()->assertJsonPath('data', null);

    $this->putJson('/api/v1/admin/bank', bankPayload())
        ->assertCreated()
        ->assertJson(['success' => true, 'data' => ['bank_name' => 'Diamond Bank', 'swift_code' => 'DBNKGMGM']]);

    $this->putJson('/api/v1/admin/bank', bankPayload(['bank_name' => 'Diamond Bank Gambia', 'swift_code' => 'DBNKGMGMXXX']))
        ->assertOk()
        ->assertJsonPath('data.bank_name', 'Diamond Bank Gambia');

    expect(DB::table('bank')->count())->toBe(1);
    $this->getJson('/api/v1/admin/bank')->assertOk()->assertJsonPath('data.swift_code', 'DBNKGMGMXXX');

    $created = DB::table('audit_log')->where('action_type', 'BANK_CREATED')->first();
    $updated = DB::table('audit_log')->where('action_type', 'BANK_UPDATED')->first();
    expect(json_decode($created->details, true)['after']['bank_name'])->toBe('Diamond Bank')
        ->and(json_decode($updated->details, true))->toMatchArray([
            'before' => ['bank_id' => $created->record_id, 'bank_name' => 'Diamond Bank', 'swift_code' => 'DBNKGMGM', 'established_date' => '2001-05-10'],
            'after' => ['bank_id' => $created->record_id, 'bank_name' => 'Diamond Bank Gambia', 'swift_code' => 'DBNKGMGMXXX', 'established_date' => '2001-05-10'],
        ]);
});

it('validates the swift code and established date', function (array $override, string $field) {
    $this->putJson('/api/v1/admin/bank', bankPayload($override))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'swift too short' => [['swift_code' => 'ABC'], 'swift_code'],
    'swift 9 chars' => [['swift_code' => 'ABCDEFGHI'], 'swift_code'],
    'swift symbols' => [['swift_code' => 'ABCD-FGH'], 'swift_code'],
    'future date' => [['established_date' => now()->addDay()->toDateString()], 'established_date'],
]);

it('refuses to create a branch before the bank exists', function () {
    $this->postJson('/api/v1/admin/branches', branchPayload())
        ->assertUnprocessable()
        ->assertJson(['message' => 'Set up the bank details first.'])
        ->assertJsonValidationErrors('bank');

    expect(DB::table('branch')->count())->toBe(0);
});

it('creates a branch with the bank id and an uppercased code, and rejects a duplicate code', function () {
    $this->putJson('/api/v1/admin/bank', bankPayload())->assertCreated();

    $this->postJson('/api/v1/admin/branches', branchPayload())
        ->assertCreated()
        ->assertJsonPath('data.branch_code', 'BJL001')
        ->assertJsonPath('data.bank_id', DB::table('bank')->value('bank_id'));

    $this->postJson('/api/v1/admin/branches', branchPayload(['branch_name' => 'Other', 'branch_code' => 'BJL001']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('branch_code');

    $this->assertDatabaseHas('audit_log', ['action_type' => 'BRANCH_CREATED', 'table_affected' => 'branch']);
});

it('lets a branch keep its own code on update', function () {
    $branch = Branch::factory()->create(['branch_code' => 'KNF01']);

    $this->putJson("/api/v1/admin/branches/{$branch->branch_id}", branchPayload(['branch_code' => 'knf01', 'branch_name' => 'Kanifing']))
        ->assertOk()
        ->assertJsonPath('data.branch_name', 'Kanifing');

    $this->assertDatabaseHas('audit_log', ['action_type' => 'BRANCH_UPDATED', 'record_id' => $branch->branch_id]);
});

it('refuses to delete a branch in use with 409, and deletes an unused one', function () {
    $used = Branch::factory()->create();
    Customer::factory()->create(['branch_id' => $used->branch_id]);
    $unused = Branch::factory()->create();

    $this->deleteJson("/api/v1/admin/branches/{$used->branch_id}")
        ->assertStatus(409)
        ->assertJson(['success' => false, 'message' => "This branch can't be deleted: it is used by 1 customer."]);
    $this->assertDatabaseHas('branch', ['branch_id' => $used->branch_id]);

    $this->deleteJson("/api/v1/admin/branches/{$unused->branch_id}")->assertOk();
    $this->assertDatabaseMissing('branch', ['branch_id' => $unused->branch_id]);
    $this->assertDatabaseHas('audit_log', ['action_type' => 'BRANCH_DELETED', 'record_id' => $unused->branch_id]);
});

it('searches and paginates branches', function () {
    Branch::factory()->create(['branch_name' => 'Serrekunda Market', 'branch_code' => 'SRK01']);
    Branch::factory()->count(3)->create();

    $this->getJson('/api/v1/admin/branches?search=srk')
        ->assertOk()
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.branch_name', 'Serrekunda Market')
        ->assertJsonPath('data.pagination.total', 1);

    $this->getJson('/api/v1/admin/branches?per_page=2')
        ->assertOk()
        ->assertJsonCount(2, 'data.items')
        ->assertJsonPath('data.pagination.last_page', 2);
});

it('returns a friendly 404 for a missing record', function () {
    $this->putJson('/api/v1/admin/branches/999999', branchPayload())
        ->assertNotFound()
        ->assertJson(['success' => false, 'message' => 'The requested record was not found.']);
});
