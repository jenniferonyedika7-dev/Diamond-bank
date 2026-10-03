<?php

use App\Models\Branch;
use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

function fakeSessionFor(User $user): string
{
    $id = 'test-session-'.$user->user_id;
    DB::table('sessions')->insert([
        'id' => $id, 'user_id' => $user->user_id, 'ip_address' => '127.0.0.1',
        'user_agent' => 'Pest', 'payload' => '', 'last_activity' => now()->timestamp,
    ]);

    return $id;
}

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

it('approves pending staff: ACTIVE, branch and department links from today, audited', function () {
    $staff = User::factory()->staff()->pending()->create();
    $branch = Branch::factory()->create();
    $department = Department::create(['department_name' => 'Operations']);

    $this->postJson("/api/v1/admin/staff/{$staff->user_id}/approve", [
        'branch_id' => $branch->branch_id,
        'department_id' => $department->department_id,
    ])->assertOk()->assertJson(['success' => true, 'message' => "{$staff->user_name} has been approved."]);

    expect($staff->fresh()->status)->toBe('ACTIVE');
    $this->assertDatabaseHas('employee_branch_lnk', [
        'employee_id' => $staff->employee_id, 'branch_id' => $branch->branch_id, 'start_date' => now()->toDateString(), 'end_date' => null,
    ]);
    $this->assertDatabaseHas('employee_department_lnk', [
        'employee_id' => $staff->employee_id, 'department_id' => $department->department_id, 'end_date' => null,
    ]);

    $audit = DB::table('audit_log')->where('action_type', 'STAFF_APPROVED')->first();
    expect($audit->user_id)->toBe($this->admin->user_id)
        ->and($audit->record_id)->toBe($staff->user_id)
        ->and(json_decode($audit->details, true))->toMatchArray([
            'before' => ['status' => 'PENDING'],
            'after' => ['status' => 'ACTIVE', 'branch_id' => $branch->branch_id, 'department_id' => $department->department_id],
        ]);

    $this->getJson('/api/v1/admin/staff?status=ACTIVE')
        ->assertOk()
        ->assertJsonPath('data.items.0.branch.branch_id', $branch->branch_id)
        ->assertJsonPath('data.items.0.department.department_name', 'Operations');
});

it('approves without a department', function () {
    $staff = User::factory()->staff()->pending()->create();

    $this->postJson("/api/v1/admin/staff/{$staff->user_id}/approve", ['branch_id' => Branch::factory()->create()->branch_id])
        ->assertOk();

    $this->assertDatabaseCount('employee_department_lnk', 0);
});

it('refuses to approve staff who are not pending', function () {
    $staff = User::factory()->staff()->create(); // ACTIVE

    $this->postJson("/api/v1/admin/staff/{$staff->user_id}/approve", ['branch_id' => Branch::factory()->create()->branch_id])
        ->assertStatus(409)
        ->assertJson(['success' => false, 'message' => 'Only pending staff can be approved.']);

    $this->assertDatabaseCount('employee_branch_lnk', 0);
    $this->assertDatabaseMissing('audit_log', ['action_type' => 'STAFF_APPROVED']);
});

it('requires a branch to approve', function () {
    $staff = User::factory()->staff()->pending()->create();

    $this->postJson("/api/v1/admin/staff/{$staff->user_id}/approve", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('branch_id');
});

it('blocks active staff, ends their sessions and logs them out', function () {
    $staff = User::factory()->staff()->create();
    $sessionId = fakeSessionFor($staff);

    $this->postJson("/api/v1/admin/staff/{$staff->user_id}/block", ['reason' => 'Left the bank'])
        ->assertOk()
        ->assertJsonPath('message', "{$staff->user_name} has been blocked.");

    expect($staff->fresh()->status)->toBe('BLOCKED');
    $this->assertDatabaseMissing('sessions', ['id' => $sessionId]);

    $audit = DB::table('audit_log')->where('action_type', 'STAFF_BLOCKED')->first();
    expect(json_decode($audit->details, true))->toMatchArray([
        'before' => ['status' => 'ACTIVE'], 'after' => ['status' => 'BLOCKED'], 'reason' => 'Left the bank',
    ]);

    // Their next request with a still-authenticated guard is refused.
    Auth::forgetGuards();
    $this->actingAs($staff->fresh());
    $this->getJson('/api/v1/auth/me')
        ->assertForbidden()
        ->assertJson(['success' => false, 'message' => 'Your account is not active.']);
});

it('requires a reason of at most 255 characters to block', function () {
    $staff = User::factory()->staff()->create();

    $this->postJson("/api/v1/admin/staff/{$staff->user_id}/block", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->postJson("/api/v1/admin/staff/{$staff->user_id}/block", ['reason' => str_repeat('x', 256)])->assertUnprocessable()->assertJsonValidationErrors('reason');
    expect($staff->fresh()->status)->toBe('ACTIVE');
});

it('rejects a pending registration with a reason, audited', function () {
    $staff = User::factory()->staff()->pending()->create();
    $sessionId = fakeSessionFor($staff);

    $this->postJson("/api/v1/admin/staff/{$staff->user_id}/reject", ['reason' => 'Not an employee'])
        ->assertOk()
        ->assertJsonPath('message', "{$staff->user_name}'s registration has been rejected.");

    expect($staff->fresh()->status)->toBe('BLOCKED');
    $this->assertDatabaseMissing('sessions', ['id' => $sessionId]);

    $audit = DB::table('audit_log')->where('action_type', 'STAFF_REJECTED')->first();
    expect($audit->record_id)->toBe($staff->user_id)
        ->and(json_decode($audit->details, true))->toMatchArray([
            'before' => ['status' => 'PENDING'], 'after' => ['status' => 'BLOCKED'], 'reason' => 'Not an employee',
        ]);
});

it('refuses to reject staff who are already active', function () {
    $staff = User::factory()->staff()->create();

    $this->postJson("/api/v1/admin/staff/{$staff->user_id}/reject", ['reason' => 'Too late'])
        ->assertStatus(409)
        ->assertJsonPath('message', 'Only pending staff can be rejected.');

    expect($staff->fresh()->status)->toBe('ACTIVE');
    $this->assertDatabaseMissing('audit_log', ['action_type' => 'STAFF_REJECTED']);
});

it('unblocks blocked staff who have a branch, but not a rejected applicant', function () {
    $staff = User::factory()->staff()->pending()->create();
    $this->postJson("/api/v1/admin/staff/{$staff->user_id}/approve", ['branch_id' => Branch::factory()->create()->branch_id])->assertOk();
    $this->postJson("/api/v1/admin/staff/{$staff->user_id}/block", ['reason' => 'Investigation'])->assertOk();

    $this->postJson("/api/v1/admin/staff/{$staff->user_id}/unblock")->assertOk();
    expect($staff->fresh()->status)->toBe('ACTIVE');
    $this->assertDatabaseHas('audit_log', ['action_type' => 'STAFF_UNBLOCKED', 'record_id' => $staff->user_id]);

    $rejected = User::factory()->staff()->pending()->create();
    $this->postJson("/api/v1/admin/staff/{$rejected->user_id}/reject", ['reason' => 'Unknown person'])->assertOk();
    $this->postJson("/api/v1/admin/staff/{$rejected->user_id}/unblock")
        ->assertStatus(409)
        ->assertJsonPath('message', "This registration was rejected and can't be unblocked.");
    expect($rejected->fresh()->status)->toBe('BLOCKED');
});

it('never lets an admin block, reject or approve an admin, including themselves', function (string $action, array $body) {
    $otherAdmin = User::factory()->admin()->create();

    foreach ([$otherAdmin, $this->admin] as $target) {
        $this->postJson("/api/v1/admin/staff/{$target->user_id}/{$action}", $body)
            ->assertForbidden()
            ->assertJson(['success' => false, 'message' => "Admin accounts can't be changed from here."]);

        expect($target->fresh()->status)->toBe('ACTIVE');
    }
})->with([
    'block' => ['block', ['reason' => 'x']],
    'block without a reason' => ['block', []],
    'reject' => ['reject', ['reason' => 'x']],
    'approve' => ['approve', []],
    'unblock' => ['unblock', []],
]);

it('treats customers as not found on staff endpoints', function () {
    $customer = User::factory()->create();

    $this->postJson("/api/v1/admin/staff/{$customer->user_id}/block", ['reason' => 'x'])
        ->assertNotFound()
        ->assertJsonPath('message', 'Staff member not found.');
});

it('filters and searches the staff list', function () {
    User::factory()->staff()->pending()->create(['user_name' => 'teststaff']);
    User::factory()->staff()->create(['user_name' => 'active1']);

    $this->getJson('/api/v1/admin/staff?status=PENDING')
        ->assertOk()
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.user_name', 'teststaff')
        ->assertJsonPath('data.items.0.branch', null);

    $this->getJson('/api/v1/admin/staff?search=active')->assertJsonCount(1, 'data.items');
    $this->getJson('/api/v1/admin/staff?status=WRONG')->assertUnprocessable();
});
