<?php

use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
| Staff manage customers and accounts at their own branch only: KYC, edits,
| login blocks and freezes at another branch get 403 and change nothing.
| Viewing and cash deposits/withdrawals stay open at any branch.
*/

beforeEach(function () {
    $this->staff = staffAtBranch();
    $this->otherBranch = Branch::factory()->create();
    $this->actingAs($this->staff);
});

function staffEditPayload(object $customer, array $overrides = []): array
{
    return array_merge([
        'first_name' => $customer->first_name, 'last_name' => $customer->last_name,
        'date_of_birth' => '1990-02-03', 'gender' => 'F', 'national_id' => $customer->national_id,
        'phone' => '7778888', 'email' => $customer->email, 'branch_id' => $customer->branch_id,
    ], $overrides);
}

it('refuses every management action on another branch\'s customer with 403, changing nothing', function (string $method, string $action, array $body, string $loginStatus) {
    $customer = customerWithLogin('PENDING', $this->otherBranch->branch_id);
    DB::table('users')->where('customer_id', $customer->customer_id)->update(['status' => $loginStatus]);
    $auditRows = DB::table('audit_log')->count();
    $body = $body === ['payload'] ? staffEditPayload($customer) : $body;

    $this->json($method, "/api/v1/staff/customers/{$customer->customer_id}{$action}", $body)
        ->assertForbidden()
        ->assertJson(['success' => false, 'message' => 'This customer is managed at another branch.']);

    $fresh = DB::table('customer')->where('customer_id', $customer->customer_id)->first();
    expect($fresh->kyc_status)->toBe('PENDING')
        ->and($fresh->phone)->toBe($customer->phone)
        ->and(DB::table('users')->where('customer_id', $customer->customer_id)->value('status'))->toBe($loginStatus)
        ->and(DB::table('audit_log')->count())->toBe($auditRows);
})->with([
    'edit' => ['PUT', '', ['payload'], 'ACTIVE'],
    'verify KYC' => ['POST', '/verify', [], 'ACTIVE'],
    'reject KYC' => ['POST', '/reject-kyc', ['reason' => 'Unreadable ID'], 'ACTIVE'],
    'block' => ['POST', '/block', ['reason' => 'Fraud'], 'ACTIVE'],
    'unblock' => ['POST', '/unblock', [], 'BLOCKED'],
]);

it('answers 403 before validation, so another branch\'s customer gives nothing away', function () {
    $customer = customerWithLogin('PENDING', $this->otherBranch->branch_id);

    $this->putJson("/api/v1/staff/customers/{$customer->customer_id}", [])->assertForbidden();
    $this->postJson("/api/v1/staff/customers/{$customer->customer_id}/block", [])->assertForbidden();
});

it('manages customers at the staff member\'s own branch', function () {
    $customer = customerWithLogin('PENDING', currentBranchId($this->staff));

    $this->postJson("/api/v1/staff/customers/{$customer->customer_id}/verify")->assertOk();
    $this->putJson("/api/v1/staff/customers/{$customer->customer_id}", staffEditPayload($customer->fresh()))->assertOk();
    $this->postJson("/api/v1/staff/customers/{$customer->customer_id}/block", ['reason' => 'Lost phone'])->assertOk();
    $this->postJson("/api/v1/staff/customers/{$customer->customer_id}/unblock")->assertOk();
});

it('still shows another branch\'s customer', function () {
    $customer = customerWithLogin('PENDING', $this->otherBranch->branch_id);

    $this->getJson("/api/v1/staff/customers/{$customer->customer_id}")->assertOk();
    $this->getJson('/api/v1/staff/customers')->assertOk()->assertJsonPath('data.pagination.total', 1);
});

it('refuses to freeze or unfreeze another branch\'s account with 403, changing nothing', function () {
    $owner = bankCustomer(['branch_id' => $this->otherBranch->branch_id]);
    $active = accountFor($owner, '1000.00');
    $frozen = accountFor($owner, '1000.00', ['status' => 'FROZEN']);

    $this->postJson("/api/v1/staff/accounts/{$active->account_number}/freeze", ['reason' => 'Check'])
        ->assertForbidden()
        ->assertJson(['success' => false, 'message' => 'This account is managed at another branch.']);
    $this->postJson("/api/v1/staff/accounts/{$active->account_number}/freeze", [])->assertForbidden();
    $this->postJson("/api/v1/staff/accounts/{$frozen->account_number}/unfreeze")
        ->assertForbidden()
        ->assertJsonPath('message', 'This account is managed at another branch.');

    expect(DB::table('account')->where('account_id', $active->account_id)->value('status'))->toBe('ACTIVE')
        ->and(DB::table('account')->where('account_id', $frozen->account_id)->value('status'))->toBe('FROZEN');
    $this->assertDatabaseMissing('audit_log', ['action_type' => 'ACCOUNT_FROZEN']);
    $this->assertDatabaseMissing('audit_log', ['action_type' => 'ACCOUNT_UNFROZEN']);
});

it('still shows another branch\'s account and takes cash for it', function () {
    $owner = bankCustomer(['branch_id' => $this->otherBranch->branch_id]);
    $account = accountFor($owner, '1000.00');

    $this->getJson("/api/v1/staff/accounts/{$account->account_number}")->assertOk();
    $this->getJson("/api/v1/staff/accounts/{$account->account_number}/transactions")->assertOk();
    $this->postJson("/api/v1/staff/accounts/{$account->account_number}/deposit", ['amount' => '50.00'])->assertOk();
    $this->postJson("/api/v1/staff/accounts/{$account->account_number}/withdraw", ['amount' => '20.00'])->assertOk();

    expect(DB::table('account')->where('account_id', $account->account_id)->value('balance'))->toBe('1030.00');
});

it('freezes and unfreezes an account at the staff member\'s own branch', function () {
    $owner = bankCustomer(['branch_id' => currentBranchId($this->staff)]);
    $account = accountFor($owner, '1000.00');

    $this->postJson("/api/v1/staff/accounts/{$account->account_number}/freeze", ['reason' => 'Check'])->assertOk();
    $this->postJson("/api/v1/staff/accounts/{$account->account_number}/unfreeze")->assertOk();
});

it('holds an admin working at a branch to the same rule', function () {
    $admin = User::query()->whereHas('role', fn ($q) => $q->where('role_name', 'admin'))->firstOrFail();
    DB::table('employee_branch_lnk')->insert([
        'employee_id' => $admin->employee_id, 'branch_id' => currentBranchId($this->staff),
        'start_date' => now()->toDateString(), 'end_date' => null,
    ]);
    DB::table('users')->where('user_id', $admin->user_id)->update(['must_change_password' => false]);
    $this->actingAs($admin->fresh());

    $theirs = customerWithLogin('PENDING', $this->otherBranch->branch_id);
    $ours = customerWithLogin('PENDING', currentBranchId($this->staff));

    $this->postJson("/api/v1/staff/customers/{$theirs->customer_id}/verify")->assertForbidden();
    $this->postJson("/api/v1/staff/customers/{$ours->customer_id}/verify")->assertOk();
});
