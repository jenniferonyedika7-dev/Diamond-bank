<?php

use App\Models\Customer;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
});

it('creates, updates and deletes an account type with audit rows', function () {
    $id = $this->postJson('/api/v1/admin/account-types', ['type_name' => 'SAVINGS', 'interest_rate' => '3.5', 'minimum_balance' => 100])
        ->assertCreated()
        ->assertJson(['data' => ['type_name' => 'SAVINGS', 'interest_rate' => '3.50', 'minimum_balance' => '100.00']])
        ->json('data.account_type_id');

    $this->putJson("/api/v1/admin/account-types/{$id}", ['type_name' => 'SAVINGS', 'interest_rate' => 4, 'minimum_balance' => 50])
        ->assertOk()
        ->assertJsonPath('data.interest_rate', '4.00');

    $this->deleteJson("/api/v1/admin/account-types/{$id}")->assertOk();

    expect(DB::table('audit_log')->whereIn('action_type', ['ACCOUNT_TYPE_CREATED', 'ACCOUNT_TYPE_UPDATED', 'ACCOUNT_TYPE_DELETED'])->count())->toBe(3);
});

it('validates account type numbers', function (array $payload, string $field) {
    $this->postJson('/api/v1/admin/account-types', array_merge(['type_name' => 'X', 'interest_rate' => 1, 'minimum_balance' => 0], $payload))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'rate over 100' => [['interest_rate' => 101], 'interest_rate'],
    'rate 3 decimals' => [['interest_rate' => '1.005'], 'interest_rate'],
    'negative rate' => [['interest_rate' => -1], 'interest_rate'],
    'negative balance' => [['minimum_balance' => -0.01], 'minimum_balance'],
    'balance 3 decimals' => [['minimum_balance' => '10.123'], 'minimum_balance'],
]);

it('refuses to delete an account type used by an account', function () {
    $typeId = DB::table('account_type')->insertGetId(['type_name' => 'CURRENT', 'interest_rate' => 0, 'minimum_balance' => 0], 'account_type_id');
    $customer = Customer::factory()->create();
    DB::table('account')->insert([
        'customer_id' => $customer->customer_id, 'branch_id' => $customer->branch_id,
        'account_type_id' => $typeId, 'account_number' => 'TEST0001',
    ]);

    $this->deleteJson("/api/v1/admin/account-types/{$typeId}")
        ->assertStatus(409)
        ->assertJsonPath('message', "This account type can't be deleted: it is used by 1 account.");
});

it('requires a positive card daily limit and a unique name', function () {
    $this->postJson('/api/v1/admin/card-types', ['type_name' => 'DEBIT', 'daily_limit' => 0])
        ->assertUnprocessable()->assertJsonValidationErrors('daily_limit');

    $this->postJson('/api/v1/admin/card-types', ['type_name' => 'DEBIT', 'daily_limit' => '5000.50'])
        ->assertCreated()->assertJsonPath('data.daily_limit', '5000.50');

    $this->postJson('/api/v1/admin/card-types', ['type_name' => 'DEBIT', 'daily_limit' => 100])
        ->assertUnprocessable()->assertJsonValidationErrors('type_name');
});

it('refuses to delete a department with employees assigned', function () {
    $departmentId = $this->postJson('/api/v1/admin/departments', ['department_name' => 'Operations'])
        ->assertCreated()->json('data.department_id');
    $employee = Employee::factory()->create();
    DB::table('employee_department_lnk')->insert([
        'employee_id' => $employee->employee_id, 'department_id' => $departmentId, 'start_date' => now()->toDateString(),
    ]);

    $this->deleteJson("/api/v1/admin/departments/{$departmentId}")
        ->assertStatus(409)
        ->assertJsonPath('message', "This department can't be deleted: it is used by 1 employee assignment.");
});

it('lists transaction types read-only', function () {
    $this->getJson('/api/v1/admin/transaction-types')
        ->assertOk()
        ->assertJsonFragment(['type_name' => 'TRANSFER_OUT']);
});

it('reports overview counts', function () {
    User::factory()->staff()->pending()->count(2)->create();
    User::factory()->staff()->create();

    $this->getJson('/api/v1/admin/overview')
        ->assertOk()
        ->assertExactJson(['success' => true, 'message' => 'Overview.', 'data' => [
            'bank_configured' => false,
            'pending_staff' => 2,
            'active_staff' => 1,
            'blocked_staff' => 0,
            'branches' => 0,
            'customers' => ['total' => 0, 'PENDING' => 0, 'VERIFIED' => 0, 'REJECTED' => 0],
            'accounts' => 0,
        ]]);

    Customer::factory()->create(['kyc_status' => 'VERIFIED']);

    $this->getJson('/api/v1/admin/overview')
        ->assertJsonPath('data.bank_configured', true)
        ->assertJsonPath('data.branches', 1)
        ->assertJsonPath('data.customers.VERIFIED', 1);
});
