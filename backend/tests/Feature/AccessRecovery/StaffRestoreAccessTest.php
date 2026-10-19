<?php

use App\Models\Employee;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/*
| A staff member locked out by wrong passwords contacts the administrator,
| who checks who they are and restores the login directly (no higher
| approver). Same temporary password rules as customers; the SMS goes to
| employee.phone.
*/

beforeEach(function () {
    $this->admin = adminUser();
    $this->staff = staffAtBranch();
    Employee::query()->whereKey($this->staff->employee_id)->update(['phone' => '871234567']);
});

function restoreAccess(int $userId, array $overrides = []): TestResponse
{
    return test()->postJson("/api/v1/admin/staff/{$userId}/restore-access", [
        'contact_channel' => 'IN_PERSON',
        'verification_note' => 'Came to head office with their staff ID card.',
        'request_type' => 'UNBLOCK_AND_RESET',
        ...$overrides,
    ]);
}

it('restores a locked-out staff member with a temporary password texted to their phone', function () {
    lockoutBlock($this->staff);

    actingFresh($this->admin);
    restoreAccess($this->staff->user_id)
        ->assertOk()
        ->assertJsonPath('message', "{$this->staff->user_name}'s access has been restored. A temporary password has been sent by SMS.");

    $temporary = temporaryPasswordFor($this->staff);
    $staff = $this->staff->fresh();
    expect($staff->status)->toBe('ACTIVE')
        ->and($staff->must_change_password)->toBeTrue()
        ->and($staff->temp_password_expires_at->toDateTimeString())->toBe(now()->addMinutes(config('bank.temp_password_expiry_minutes'))->toDateTimeString())
        ->and(DB::table('demo_sms_outbox')->where('user_id', $staff->user_id)->value('phone'))->toBe('871234567');

    $audit = DB::table('audit_log')->where('action_type', 'STAFF_ACCESS_RESTORED')->sole();
    expect($audit->user_id)->toBe($this->admin->user_id)
        ->and(json_decode($audit->details, true))->toMatchArray([
            'reason' => 'LOCKOUT_BLOCKED', 'contact_channel' => 'IN_PERSON', 'request_type' => 'UNBLOCK_AND_RESET',
            'verification_note' => 'Came to head office with their staff ID card.', 'before' => ['status' => 'BLOCKED'], 'after' => ['status' => 'ACTIVE'],
        ]);
    $this->assertDatabaseHas('audit_log', ['action_type' => 'TEMP_PASSWORD_ISSUED', 'record_id' => $staff->user_id]);
    $this->assertDatabaseHas('audit_log', ['action_type' => 'DEMO_SMS_SENT', 'user_id' => $this->admin->user_id]);

    attemptLogin($staff->user_name, 'password1')->assertUnauthorized();
    attemptLogin($staff->user_name, $temporary)->assertOk()->assertJsonPath('data.must_change_password', true);
});

it('restores with UNBLOCK_ONLY, keeping their own password', function () {
    lockoutBlock($this->staff);

    actingFresh($this->admin);
    restoreAccess($this->staff->user_id, ['contact_channel' => 'PHONE_CALL', 'request_type' => 'UNBLOCK_ONLY'])->assertOk();

    expect($this->staff->fresh()->status)->toBe('ACTIVE')
        ->and(Cache::get("password-lockout:{$this->staff->user_id}"))->toBeNull()
        ->and(smsTexts($this->staff))->toBe(['Diamond Bank: Your account has been unblocked. If you did not request this, call us immediately.']);
    $this->assertDatabaseMissing('audit_log', ['action_type' => 'TEMP_PASSWORD_ISSUED']);
    attemptLogin($this->staff->user_name, 'password1')->assertOk()->assertJsonPath('data.must_change_password', false);

    // Restored, so a later block by hand uses the plain unblock again.
    actingFresh($this->admin)->postJson("/api/v1/admin/staff/{$this->staff->user_id}/block", ['reason' => 'Holiday'])->assertOk();
    actingFresh($this->admin)->postJson("/api/v1/admin/staff/{$this->staff->user_id}/unblock")->assertOk();
});

it('needs a reset, not just an unblock, for an expired temporary password', function () {
    $this->staff->forceFill(['must_change_password' => true, 'temp_password_expires_at' => now()->subMinute()])->save();
    attemptLogin($this->staff->user_name, 'password1')
        ->assertForbidden()
        ->assertJsonPath('message', 'This temporary password has expired. Please contact the administrator.');

    actingFresh($this->admin);
    restoreAccess($this->staff->user_id, ['request_type' => 'UNBLOCK_ONLY'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['request_type']);
    $this->assertDatabaseMissing('audit_log', ['action_type' => 'STAFF_ACCESS_RESTORED']);

    restoreAccess($this->staff->user_id, ['request_type' => 'UNBLOCK_AND_RESET'])->assertOk();
    expect(json_decode(DB::table('audit_log')->where('action_type', 'STAFF_ACCESS_RESTORED')->value('details'), true)['reason'])->toBe('TEMP_PASSWORD_EXPIRED');
});

it('refuses staff who do not need restoring', function () {
    actingFresh($this->admin);
    restoreAccess($this->staff->user_id)->assertConflict();   // active

    $this->postJson("/api/v1/admin/staff/{$this->staff->user_id}/block", ['reason' => 'Left the bank'])->assertOk();
    actingFresh($this->admin);
    restoreAccess($this->staff->user_id)->assertConflict();   // blocked by hand

    expect(DB::table('demo_sms_outbox')->count())->toBe(0);
});

it('needs a verification note and a contact channel', function () {
    lockoutBlock($this->staff);

    actingFresh($this->admin);
    restoreAccess($this->staff->user_id, ['verification_note' => '', 'contact_channel' => 'BRANCH_VISIT'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['verification_note', 'contact_channel']);
    expect($this->staff->fresh()->status)->toBe('BLOCKED');
});

it('is for administrators only', function () {
    lockoutBlock($this->staff);

    actingFresh(staffAtBranch());
    restoreAccess($this->staff->user_id)->assertForbidden();
    expect($this->staff->fresh()->status)->toBe('BLOCKED');
});
