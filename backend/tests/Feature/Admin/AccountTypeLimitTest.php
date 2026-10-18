<?php

use App\Models\AccountType;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/*
| Admins set each account type's daily_transfer_limit. A change of the value
| writes ACCOUNT_TYPE_LIMIT_CHANGED (old and new value), and only a real change.
*/

beforeEach(function () {
    actingFresh(adminUser());
    $this->type = AccountType::query()->create(['type_name' => 'Savings', 'interest_rate' => 3.5, 'minimum_balance' => 500, 'daily_transfer_limit' => '100000.00']);
});

function saveType(AccountType $type, array $changes): TestResponse
{
    return test()->putJson("/api/v1/admin/account-types/{$type->account_type_id}", [
        ...$type->fresh()->only(['type_name', 'interest_rate', 'minimum_balance', 'daily_transfer_limit']),
        ...$changes,
    ]);
}

function limitChanges(): array
{
    return DB::table('audit_log')->where('action_type', 'ACCOUNT_TYPE_LIMIT_CHANGED')->orderBy('audit_log_id')
        ->pluck('details')->map(fn ($d) => json_decode($d, true))->all();
}

it('requires a positive daily transfer limit', function (mixed $value) {
    $this->postJson('/api/v1/admin/account-types', ['type_name' => 'Business', 'interest_rate' => 1, 'minimum_balance' => 0, 'daily_transfer_limit' => $value])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['daily_transfer_limit']);
})->with([null, 0, -5, 'lots', '10.001']);

it('lists the limit', function () {
    $this->getJson('/api/v1/admin/account-types')->assertOk()->assertJsonPath('data.0.daily_transfer_limit', '100000.00');
});

it('audits a change of the limit with the old and new value', function () {
    saveType($this->type, ['daily_transfer_limit' => '150000'])->assertOk();

    expect(limitChanges())->toEqual([['type_name' => 'Savings', 'old_value' => '100000.00', 'new_value' => '150000.00']])
        ->and($this->type->fresh()->daily_transfer_limit)->toBe('150000.00');
    $audit = DB::table('audit_log')->where('action_type', 'ACCOUNT_TYPE_LIMIT_CHANGED')->sole();
    expect($audit->record_id)->toBe($this->type->account_type_id)
        ->and($audit->table_affected)->toBe('account_type');
});

it('writes no limit row when the value does not change', function () {
    saveType($this->type, ['daily_transfer_limit' => '100000.00'])->assertOk();
    saveType($this->type, ['daily_transfer_limit' => '100000'])->assertOk();
    saveType($this->type, ['minimum_balance' => '750.00'])->assertOk();

    expect(limitChanges())->toBe([])
        ->and(DB::table('audit_log')->where('action_type', 'ACCOUNT_TYPE_UPDATED')->count())->toBe(3);
});
