<?php

use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

it('refuses every staff route to customers with 403', function () {
    $this->actingAs(User::factory()->create());

    $routes = staffRoutes();
    expect(count($routes))->toBeGreaterThan(15);

    foreach ($routes as [$method, $uri]) {
        $this->json($method, $uri)
            ->assertForbidden()
            ->assertJson(['success' => false, 'message' => 'You do not have permission to access this resource.']);
    }
});

it('refuses every staff route to staff without a current branch', function () {
    $staff = User::factory()->staff()->create();
    // An ended assignment doesn't count.
    DB::table('employee_branch_lnk')->insert([
        'employee_id' => $staff->employee_id,
        'branch_id' => Branch::factory()->create()->branch_id,
        'start_date' => '2020-01-01',
        'end_date' => '2021-01-01',
    ]);
    $this->actingAs($staff);

    foreach (staffRoutes() as [$method, $uri]) {
        $this->json($method, $uri)
            ->assertForbidden()
            ->assertJson(['success' => false, 'message' => 'You are not assigned to a branch. Contact the administrator.']);
    }
});

it('keeps staff out of admin routes', function () {
    $this->actingAs(staffAtBranch());

    $this->getJson('/api/v1/admin/overview')->assertForbidden();
    $this->putJson('/api/v1/admin/bank', [])->assertForbidden();
});

it('lets staff and a branch-less admin read the audit log, newest first, with filters', function () {
    $staff = staffAtBranch();
    $customer = customerWithLogin();
    $this->actingAs($staff);
    $this->postJson("/api/v1/staff/customers/{$customer->customer_id}/verify")->assertOk();

    $this->getJson('/api/v1/staff/audit-log?action_type=CUSTOMER_VERIFIED')
        ->assertOk()
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.user_name', $staff->user_name)
        ->assertJsonPath('data.items.0.role_name', 'staff')
        ->assertJsonPath('data.items.0.details.after.kyc_status', 'VERIFIED')
        ->assertJsonPath('data.filters.action_types', fn ($types) => in_array('CUSTOMER_VERIFIED', $types, true));

    $this->getJson('/api/v1/staff/audit-log?from='.now()->addDay()->toDateString())->assertJsonCount(0, 'data.items');
    $this->getJson('/api/v1/staff/audit-log?user='.$staff->user_name.'&table=customer')->assertJsonCount(1, 'data.items');

    // Admins have no branch, but may read the audit log (and only that).
    Auth::forgetGuards();
    $this->actingAs(User::factory()->admin()->create());
    $this->getJson('/api/v1/staff/audit-log')->assertOk();
    $this->getJson('/api/v1/staff/customers')
        ->assertForbidden()
        ->assertJsonPath('message', 'You are not assigned to a branch. Contact the administrator.');
});
