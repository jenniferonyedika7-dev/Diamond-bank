<?php

use App\Models\Customer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->staff = staffAtBranch();
    $this->actingAs($this->staff);
});

it('returns the staff member\'s branch and department', function () {
    $this->getJson('/api/v1/staff/me/branch')
        ->assertOk()
        ->assertJsonPath('data.branch.branch_id', currentBranchId($this->staff))
        ->assertJsonPath('data.department', null);
});

it('lists customers by KYC status and searches by username', function () {
    $pending = customerWithLogin('PENDING');
    customerWithLogin('VERIFIED');

    $this->getJson('/api/v1/staff/customers?kyc_status=PENDING')
        ->assertOk()
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.customer_id', $pending->customer_id)
        ->assertJsonPath('data.items.0.user_status', 'ACTIVE')
        ->assertJsonPath('data.items.0.accounts_count', 0);

    $userName = $pending->user->user_name;
    $this->getJson('/api/v1/staff/customers?search='.urlencode($userName))
        ->assertJsonPath('data.items.0.customer_id', $pending->customer_id);
});

it('verifies a pending customer, recording who and when', function () {
    $customer = customerWithLogin();

    $this->postJson("/api/v1/staff/customers/{$customer->customer_id}/verify")
        ->assertOk()
        ->assertJson(['success' => true, 'message' => 'Customer verified.']);

    $customer->refresh();
    expect($customer->kyc_status)->toBe('VERIFIED')
        ->and($customer->verified_by)->toBe($this->staff->employee_id)
        ->and($customer->verified_at)->not->toBeNull();

    $audit = DB::table('audit_log')->where('action_type', 'CUSTOMER_VERIFIED')->first();
    expect($audit->user_id)->toBe($this->staff->user_id)
        ->and(json_decode($audit->details, true)['after']['verified_by'])->toBe($this->staff->employee_id);

    $this->getJson("/api/v1/staff/customers/{$customer->customer_id}")
        ->assertJsonPath('data.verified_by_name', $this->staff->employee->full_name);

    $this->postJson("/api/v1/staff/customers/{$customer->customer_id}/verify")
        ->assertStatus(409)
        ->assertJsonPath('message', 'Only customers with pending KYC can be verified.');
});

it('needs a reason to reject KYC, and audits it', function () {
    $customer = customerWithLogin();

    $this->postJson("/api/v1/staff/customers/{$customer->customer_id}/reject-kyc", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reason');
    expect($customer->fresh()->kyc_status)->toBe('PENDING');

    $this->postJson("/api/v1/staff/customers/{$customer->customer_id}/reject-kyc", ['reason' => 'ID photo unreadable'])
        ->assertOk();
    expect($customer->fresh()->kyc_status)->toBe('REJECTED');

    $audit = DB::table('audit_log')->where('action_type', 'CUSTOMER_KYC_REJECTED')->first();
    expect(json_decode($audit->details, true))->toMatchArray([
        'before' => ['kyc_status' => 'PENDING'], 'after' => ['kyc_status' => 'REJECTED'], 'reason' => 'ID photo unreadable',
    ]);
});

it('edits a customer with audit, and locks the national ID after KYC', function () {
    $customer = customerWithLogin();
    $payload = fn (array $overrides = []) => array_merge([
        'first_name' => $customer->first_name, 'last_name' => $customer->last_name,
        'date_of_birth' => '1990-02-03', 'gender' => 'F', 'national_id' => $customer->national_id,
        'phone' => '7778888', 'email' => $customer->email, 'branch_id' => $customer->branch_id,
    ], $overrides);

    $this->putJson("/api/v1/staff/customers/{$customer->customer_id}", $payload(['national_id' => 'NEW-ID-1']))->assertOk();
    expect($customer->fresh()->national_id)->toBe('NEW-ID-1');

    $audit = DB::table('audit_log')->where('action_type', 'CUSTOMER_UPDATED')->first();
    $details = json_decode($audit->details, true);
    expect($details['before']['phone'])->toBe($customer->phone)
        ->and($details['after']['phone'])->toBe('7778888');

    DB::table('customer')->where('customer_id', $customer->customer_id)->update(['kyc_status' => 'VERIFIED']);
    $customer->refresh();

    $this->putJson("/api/v1/staff/customers/{$customer->customer_id}", $payload(['national_id' => 'NEW-ID-2']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['national_id' => 'The national ID can only be changed while KYC is pending.']);

    $this->putJson("/api/v1/staff/customers/{$customer->customer_id}", $payload(['date_of_birth' => now()->subYears(17)->toDateString()]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('date_of_birth');
});

it('blocks a customer login, ending their sessions, and unblocks it', function () {
    $customer = customerWithLogin('VERIFIED');
    $user = $customer->user;
    DB::table('sessions')->insert([
        'id' => 'cust-session', 'user_id' => $user->user_id, 'ip_address' => '127.0.0.1',
        'user_agent' => 'Pest', 'payload' => '', 'last_activity' => now()->timestamp,
    ]);

    $this->postJson("/api/v1/staff/customers/{$customer->customer_id}/block", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->postJson("/api/v1/staff/customers/{$customer->customer_id}/block", ['reason' => 'Suspected fraud'])
        ->assertOk()
        ->assertJsonPath('message', 'Customer login blocked.');

    expect($user->fresh()->status)->toBe('BLOCKED');
    $this->assertDatabaseMissing('sessions', ['id' => 'cust-session']);
    $audit = DB::table('audit_log')->where('action_type', 'CUSTOMER_BLOCKED')->first();
    expect(json_decode($audit->details, true)['reason'])->toBe('Suspected fraud');

    // The customer's next request is refused.
    Auth::forgetGuards();
    $this->actingAs($user->fresh());
    $this->getJson('/api/v1/auth/me')->assertForbidden()->assertJsonPath('message', 'Your account is not active.');

    Auth::forgetGuards();
    $this->actingAs($this->staff);
    $this->postJson("/api/v1/staff/customers/{$customer->customer_id}/unblock")->assertOk();
    expect($user->fresh()->status)->toBe('ACTIVE');
    $this->assertDatabaseHas('audit_log', ['action_type' => 'CUSTOMER_UNBLOCKED', 'record_id' => $user->user_id]);
});

it('reports the KYC counts on the overview', function () {
    customerWithLogin('PENDING');
    customerWithLogin('PENDING');
    customerWithLogin('VERIFIED');

    $this->getJson('/api/v1/staff/overview')
        ->assertOk()
        ->assertJsonPath('data.customers', ['total' => 3, 'PENDING' => 2, 'VERIFIED' => 1, 'REJECTED' => 0]);
});

it('returns a friendly 404 for an unknown customer', function () {
    $this->getJson('/api/v1/staff/customers/999999')
        ->assertNotFound()
        ->assertJsonPath('message', 'The requested record was not found.');
});

it('refuses a customer without a login', function () {
    $customer = Customer::factory()->create();

    $this->postJson("/api/v1/staff/customers/{$customer->customer_id}/block", ['reason' => 'x'])
        ->assertStatus(409)
        ->assertJsonPath('message', 'This customer has no login.');
});
