<?php

use App\Models\Branch;

/*
| A login blocked by the wrong-password lockout is only reopened through an
| access request (customers) or Restore access (staff). The plain unblock
| still works for logins blocked by hand.
*/

it('refuses staff a direct unblock of a customer locked by wrong passwords', function () {
    $branch = Branch::factory()->create();
    $staff = staffAtBranch($branch);
    $customer = customerWithLogin('VERIFIED', $branch->branch_id);
    lockoutBlock($customer->user);

    actingFresh($staff)->postJson("/api/v1/staff/customers/{$customer->customer_id}/unblock")
        ->assertConflict()
        ->assertJsonPath('message', 'This login was locked after too many wrong passwords. Raise an account access request instead.');

    expect($customer->user->fresh()->status)->toBe('BLOCKED');
    $this->assertDatabaseMissing('audit_log', ['action_type' => 'CUSTOMER_UNBLOCKED']);
});

it('keeps the normal unblock for a customer blocked by staff, even after an earlier lockout', function (bool $lockedBefore) {
    $branch = Branch::factory()->create();
    $staff = staffAtBranch($branch);
    $customer = customerWithLogin('VERIFIED', $branch->branch_id);
    if ($lockedBefore) {
        lockoutBlock($customer->user);
        raiseAccessRequest($staff, $customer, ['request_type' => 'UNBLOCK_ONLY'])->assertCreated();
        actingFresh(adminUser())->postJson('/api/v1/admin/access-requests/'.pendingRequestId().'/approve')->assertOk();
    }

    actingFresh($staff)->postJson("/api/v1/staff/customers/{$customer->customer_id}/block", ['reason' => 'Suspicious activity'])->assertOk();
    actingFresh($staff)->postJson("/api/v1/staff/customers/{$customer->customer_id}/unblock")->assertOk();

    expect($customer->user->fresh()->status)->toBe('ACTIVE');
})->with(['never locked out' => false, 'locked out and restored before' => true]);

it('refuses the admin a plain unblock of staff locked by wrong passwords, but not of staff blocked by hand', function () {
    $admin = adminUser();
    $locked = staffAtBranch();
    lockoutBlock($locked);
    $blocked = staffAtBranch();

    actingFresh($admin)->postJson("/api/v1/admin/staff/{$locked->user_id}/unblock")
        ->assertConflict()
        ->assertJsonPath('message', 'This staff member was locked out by wrong passwords. Use Restore access instead.');
    expect($locked->fresh()->status)->toBe('BLOCKED');

    actingFresh($admin)->postJson("/api/v1/admin/staff/{$blocked->user_id}/block", ['reason' => 'Left the bank'])->assertOk();
    actingFresh($admin)->postJson("/api/v1/admin/staff/{$blocked->user_id}/unblock")->assertOk();
    expect($blocked->fresh()->status)->toBe('ACTIVE');

    $rows = collect(actingFresh($admin)->getJson('/api/v1/admin/staff?per_page=100')->assertOk()->json('data.items'))->keyBy('user_id');
    expect($rows[$locked->user_id]['recovery'])->toBe('LOCKOUT_BLOCKED')
        ->and($rows[$blocked->user_id]['recovery'])->toBeNull();
});
