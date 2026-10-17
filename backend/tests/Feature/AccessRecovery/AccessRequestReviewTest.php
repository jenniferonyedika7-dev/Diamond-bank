<?php

use App\Models\Branch;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
| An administrator approves or rejects the access requests staff raise.
| UNBLOCK_ONLY reopens the login; UNBLOCK_AND_RESET also texts a
| system-generated temporary password that must be changed at first login.
*/

beforeEach(function () {
    $this->branch = Branch::factory()->create(['branch_name' => 'Serrekunda']);
    $this->staff = staffAtBranch($this->branch);
    $this->customer = customerWithLogin('VERIFIED', $this->branch->branch_id);
    $this->login = $this->customer->user;
    $this->admin = adminUser();
    lockoutBlock($this->login);
});

it('does not let staff approve or reject', function () {
    raiseAccessRequest($this->staff, $this->customer)->assertCreated();
    $id = pendingRequestId();

    actingFresh($this->staff)->postJson("/api/v1/admin/access-requests/{$id}/approve")->assertForbidden();
    actingFresh($this->staff)->postJson("/api/v1/admin/access-requests/{$id}/reject", ['reason' => 'No'])->assertForbidden();
    actingFresh($this->staff)->getJson('/api/v1/admin/access-requests')->assertForbidden();

    expect(DB::table('access_request')->value('status'))->toBe('PENDING')
        ->and($this->login->fresh()->status)->toBe('BLOCKED');
});

it('needs a reason to reject, then texts the customer, who stays blocked', function () {
    raiseAccessRequest($this->staff, $this->customer)->assertCreated();
    $id = pendingRequestId();

    actingFresh($this->admin)->postJson("/api/v1/admin/access-requests/{$id}/reject", ['reason' => ''])
        ->assertUnprocessable()->assertJsonValidationErrors(['reason']);
    expect(DB::table('access_request')->value('status'))->toBe('PENDING');

    actingFresh($this->admin)->postJson("/api/v1/admin/access-requests/{$id}/reject", ['reason' => 'Caller could not confirm the date of birth.'])
        ->assertOk();

    expect(DB::table('access_request')->first())->toMatchArray([
        'status' => 'REJECTED', 'reviewed_by' => $this->admin->user_id, 'rejection_reason' => 'Caller could not confirm the date of birth.',
    ])
        ->and($this->login->fresh()->status)->toBe('BLOCKED')
        ->and(smsTexts($this->login)[1])->toBe('Diamond Bank: We could not complete your account access request. Please visit your branch.');
    $audit = DB::table('audit_log')->where('action_type', 'ACCESS_REQUEST_REJECTED')->sole();
    expect(json_decode($audit->details, true)['reason'])->toBe('Caller could not confirm the date of birth.');
    attemptLogin($this->login->user_name, 'password1')->assertForbidden();

    // A new request can be raised afterwards.
    raiseAccessRequest($this->staff, $this->customer)->assertCreated();
});

it('UNBLOCK_ONLY: reopens the login with the customer\'s own password', function () {
    raiseAccessRequest($this->staff, $this->customer, ['request_type' => 'UNBLOCK_ONLY'])->assertCreated();
    $id = pendingRequestId();
    actingFresh($this->admin)->getJson('/api/v1/admin/access-requests')
        ->assertOk()
        ->assertJsonPath('data.pending_count', 1)
        ->assertJsonPath('data.items.0.access_request_id', $id)
        ->assertJsonPath('data.items.0.requested_by_name', $this->staff->employee->full_name);
    actingFresh($this->admin)->getJson("/api/v1/admin/access-requests/{$id}")
        ->assertOk()
        ->assertJsonPath('data.checks', ['dob_confirmed' => true, 'phone_confirmed' => true, 'history_reviewed' => true, 'declaration_confirmed' => true])
        ->assertJsonPath('data.customer.phone', $this->customer->phone)
        ->assertJsonPath('data.login.recovery', 'LOCKOUT_BLOCKED');
    actingFresh($this->admin)->getJson('/api/v1/admin/overview')->assertJsonPath('data.pending_access_requests', 1);

    actingFresh($this->admin)->postJson("/api/v1/admin/access-requests/{$id}/approve")->assertOk();

    $login = $this->login->fresh();
    expect($login->status)->toBe('ACTIVE')
        ->and($login->must_change_password)->toBeFalse()
        ->and($login->temp_password_expires_at)->toBeNull()
        ->and(Cache::get("password-lockout:{$login->user_id}"))->toBeNull()
        ->and(DB::table('access_request')->first())->toMatchArray(['status' => 'APPROVED', 'reviewed_by' => $this->admin->user_id])
        ->and(smsTexts($login))->toBe([
            'Diamond Bank: We received your account access request at Serrekunda. You will receive an SMS when it has been reviewed.',
            'Diamond Bank: Your account has been unblocked. If you did not request this, call us immediately.',
        ]);
    expect(array_values(array_filter(auditActions(), fn ($a) => ! in_array($a, ['ADMIN_SEEDED', 'LOGIN_FAILED', 'PASSWORD_LOCKOUT', 'PASSWORD_LOCKOUT_BLOCKED'], true))))
        ->toBe(['ACCESS_REQUEST_CREATED', 'DEMO_SMS_SENT', 'ACCESS_REQUEST_APPROVED', 'CUSTOMER_UNBLOCKED', 'DEMO_SMS_SENT']);
    $this->assertDatabaseMissing('audit_log', ['action_type' => 'TEMP_PASSWORD_ISSUED']);

    attemptLogin($this->login->user_name, 'password1')->assertOk()->assertJsonPath('data.must_change_password', false);

    // The count starts again from zero: 4 wrong passwords don't lock.
    foreach (range(1, 4) as $i) {
        attemptLogin($this->login->user_name, 'wrong-password')->assertUnauthorized();
    }
});

it('UNBLOCK_AND_RESET: texts a temporary password that must be changed at first login', function () {
    raiseAccessRequest($this->staff, $this->customer, ['request_type' => 'UNBLOCK_AND_RESET'])->assertCreated();
    actingFresh($this->admin)->postJson('/api/v1/admin/access-requests/'.pendingRequestId().'/approve')
        ->assertOk()
        ->assertJsonPath('message', 'Access request approved. The customer has been sent a temporary password by SMS.');

    $temporary = temporaryPasswordFor($this->login);
    $login = $this->login->fresh();
    expect($login->status)->toBe('ACTIVE')
        ->and($login->must_change_password)->toBeTrue()
        ->and($login->temp_password_expires_at->toDateTimeString())->toBe(now()->addMinutes(config('bank.temp_password_expiry_minutes'))->toDateTimeString())
        ->and(smsTexts($login))->toBe([
            'Diamond Bank: We received your account access request at Serrekunda. You will receive an SMS when it has been reviewed.',
            "Diamond Bank: Your temporary password is {$temporary}. It expires in 24 hours. You will be asked to change it when you log in. Never share this code.",
        ])
        ->and(json_decode(DB::table('audit_log')->where('action_type', 'TEMP_PASSWORD_ISSUED')->value('details'), true))
        ->toBe(['expires_at' => $login->temp_password_expires_at->toDateTimeString()]);
    $this->assertDatabaseHas('audit_log', ['action_type' => 'CUSTOMER_UNBLOCKED', 'record_id' => $login->user_id]);

    attemptLogin($this->login->user_name, 'password1')->assertUnauthorized();   // the old password is gone
    attemptLogin($this->login->user_name, $temporary)->assertOk()->assertJsonPath('data.must_change_password', true);
    $this->getJson('/api/v1/auth/me')->assertForbidden()->assertJsonPath('data.must_change_password', true);

    $this->postJson('/api/v1/auth/change-password', [
        'current_password' => $temporary, 'password' => 'MyNewSecret123', 'password_confirmation' => 'MyNewSecret123',
    ])->assertOk()->assertJsonPath('data.must_change_password', false);

    expect($this->login->fresh()->temp_password_expires_at)->toBeNull();
    attemptLogin($this->login->user_name, 'MyNewSecret123')->assertOk();
});

it('words the SMS from the configured lifetime', function (int $minutes, string $words) {
    config(['bank.temp_password_expiry_minutes' => $minutes]);
    raiseAccessRequest($this->staff, $this->customer)->assertCreated();
    actingFresh($this->admin)->postJson('/api/v1/admin/access-requests/'.pendingRequestId().'/approve')->assertOk();

    expect(smsTexts($this->login)[1])->toContain(". It expires in {$words}. You will")
        ->and($this->login->fresh()->temp_password_expires_at->toDateTimeString())->toBe(now()->addMinutes($minutes)->toDateTimeString());
})->with([[30, '30 minutes'], [1, '1 minute'], [60, '1 hour'], [90, '90 minutes'], [2880, '48 hours']]);

it('does not let an administrator approve a request they raised', function () {
    DB::table('employee_branch_lnk')->insert([
        'employee_id' => $this->admin->employee_id, 'branch_id' => $this->branch->branch_id, 'start_date' => now()->toDateString(), 'end_date' => null,
    ]);
    raiseAccessRequest($this->admin, $this->customer)->assertCreated();

    actingFresh($this->admin)->postJson('/api/v1/admin/access-requests/'.pendingRequestId().'/approve')
        ->assertForbidden()
        ->assertJsonPath('message', 'You raised this access request, so another administrator must approve it.');
    expect($this->login->fresh()->status)->toBe('BLOCKED');
});

it('only decides a PENDING request, once', function () {
    raiseAccessRequest($this->staff, $this->customer)->assertCreated();
    $id = pendingRequestId();
    actingFresh($this->admin)->postJson("/api/v1/admin/access-requests/{$id}/approve")->assertOk();

    actingFresh($this->admin)->postJson("/api/v1/admin/access-requests/{$id}/approve")->assertConflict();
    actingFresh($this->admin)->postJson("/api/v1/admin/access-requests/{$id}/reject", ['reason' => 'Late'])->assertConflict();
    actingFresh($this->admin)->postJson('/api/v1/admin/access-requests/999999/approve')->assertNotFound();
    expect(DB::table('audit_log')->where('action_type', 'TEMP_PASSWORD_ISSUED')->count())->toBe(1);
});

it('refuses to approve once the login no longer needs it', function () {
    raiseAccessRequest($this->staff, $this->customer)->assertCreated();
    DB::table('users')->where('user_id', $this->login->user_id)->update(['status' => 'ACTIVE']);

    actingFresh($this->admin)->postJson('/api/v1/admin/access-requests/'.pendingRequestId().'/approve')->assertConflict();
    expect(DB::table('access_request')->value('status'))->toBe('PENDING');
});
