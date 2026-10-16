<?php

use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/*
| Staff see the audit rows of their own branch: actions by staff who worked
| there at the time, and by customers whose home branch it is. Admins, rows by
| admins and rows without a user stay in the admin's bank-wide view.
*/

beforeEach(function () {
    $this->branchA = Branch::factory()->create();
    $this->branchB = Branch::factory()->create();
    $this->staffA = staffAtBranch($this->branchA);
    $this->staffB = staffAtBranch($this->branchB);
    $customerA = customerWithLogin('VERIFIED', $this->branchA->branch_id)->user;
    $customerB = customerWithLogin('VERIFIED', $this->branchB->branch_id)->user;
    $this->admin = User::query()->whereHas('role', fn ($q) => $q->where('role_name', 'admin'))->firstOrFail();
    $this->admin->forceFill(['must_change_password' => false])->save();

    // Worked at B, then moved to A.
    $moved = User::factory()->staff()->create();
    DB::table('employee_branch_lnk')->insert([
        ['employee_id' => $moved->employee_id, 'branch_id' => $this->branchB->branch_id,
            'start_date' => now()->subDays(10)->toDateString(), 'end_date' => now()->subDays(6)->toDateString()],
        ['employee_id' => $moved->employee_id, 'branch_id' => $this->branchA->branch_id,
            'start_date' => now()->subDays(5)->toDateString(), 'end_date' => null],
    ]);

    $row = fn (?int $userId, string $action, $at = null) => DB::table('audit_log')->insert([
        'user_id' => $userId, 'action_type' => $action, 'table_affected' => 'users', 'record_id' => $userId,
        'ip_address' => '127.0.0.1', 'action_timestamp' => ($at ?? now())->toDateTimeString(),
    ]);
    DB::table('audit_log')->delete();
    $row($this->staffA->user_id, 'T_STAFF_A');
    $row($this->staffB->user_id, 'T_STAFF_B');
    $row($customerA->user_id, 'T_CUSTOMER_A');
    $row($customerB->user_id, 'T_CUSTOMER_B');
    $row($moved->user_id, 'T_MOVED_AT_B', now()->subDays(8));
    $row($moved->user_id, 'T_MOVED_AT_A', now()->subDays(2));
    $row($this->admin->user_id, 'T_ADMIN');
    $row(null, 'T_NO_USER');
});

/** The action types the acting user sees, from the rows and from the filter list. */
function visibleActions(): array
{
    $data = test()->getJson('/api/v1/staff/audit-log?per_page=100')->assertOk()->json('data');
    $items = collect($data['items'])->pluck('action_type')->sort()->values()->all();
    expect(collect($data['filters']['action_types'])->sort()->values()->all())->toBe($items);

    return $items;
}

it('shows staff only their own branch\'s rows', function () {
    $this->actingAs($this->staffA);
    expect(visibleActions())->toBe(['T_CUSTOMER_A', 'T_MOVED_AT_A', 'T_STAFF_A']);

    Auth::forgetGuards();
    $this->actingAs($this->staffB);
    expect(visibleActions())->toBe(['T_CUSTOMER_B', 'T_MOVED_AT_B', 'T_STAFF_B']);
});

it('keeps the scope when filtering', function () {
    $this->actingAs($this->staffA);

    $this->getJson('/api/v1/staff/audit-log?action_type=T_STAFF_B')->assertOk()->assertJsonCount(0, 'data.items');
    $this->getJson('/api/v1/staff/audit-log?user='.$this->staffB->user_name)->assertOk()->assertJsonCount(0, 'data.items');
    $this->getJson('/api/v1/staff/audit-log?action_type=T_STAFF_A')->assertOk()->assertJsonCount(1, 'data.items');
});

it('shows the admin every row, with or without a branch', function () {
    $everything = ['T_ADMIN', 'T_CUSTOMER_A', 'T_CUSTOMER_B', 'T_MOVED_AT_A', 'T_MOVED_AT_B', 'T_NO_USER', 'T_STAFF_A', 'T_STAFF_B'];

    $this->actingAs($this->admin);
    expect(visibleActions())->toBe($everything);

    DB::table('employee_branch_lnk')->insert([
        'employee_id' => $this->admin->employee_id, 'branch_id' => $this->branchA->branch_id,
        'start_date' => now()->toDateString(), 'end_date' => null,
    ]);
    Auth::forgetGuards();
    $this->actingAs($this->admin->fresh());
    expect(visibleActions())->toBe($everything);
});
