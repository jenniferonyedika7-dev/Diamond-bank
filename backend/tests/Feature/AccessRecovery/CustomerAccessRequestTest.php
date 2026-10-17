<?php

use App\Models\Branch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| Staff at a customer's home branch raise an access request for a login
| locked by wrong passwords (or holding an expired temporary password),
| recording the identity checks. One PENDING request per customer.
*/

beforeEach(function () {
    $this->branch = Branch::factory()->create(['branch_name' => 'Serrekunda']);
    $this->staff = staffAtBranch($this->branch);
    $this->customer = customerWithLogin('VERIFIED', $this->branch->branch_id);
    $this->login = $this->customer->user;
});

it('records a PENDING request, audits it and texts the customer', function () {
    lockoutBlock($this->login);

    raiseAccessRequest($this->staff, $this->customer, ['contact_channel' => 'PHONE_CALL', 'request_type' => 'UNBLOCK_ONLY'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'PENDING');

    $request = DB::table('access_request')->sole();
    expect($request)->toMatchArray([
        'customer_id' => $this->customer->customer_id, 'user_id' => $this->login->user_id, 'branch_id' => $this->branch->branch_id,
        'reason' => 'LOCKOUT_BLOCKED', 'contact_channel' => 'PHONE_CALL', 'request_type' => 'UNBLOCK_ONLY', 'status' => 'PENDING',
        'requested_by' => $this->staff->user_id, 'reviewed_by' => null,
    ])
        ->and($this->login->fresh()->status)->toBe('BLOCKED')
        ->and(smsTexts($this->login))->toBe([
            'Diamond Bank: We received your account access request at Serrekunda. You will receive an SMS when it has been reviewed.',
        ]);

    $audit = DB::table('audit_log')->where('action_type', 'ACCESS_REQUEST_CREATED')->sole();
    expect($audit->user_id)->toBe($this->staff->user_id)
        ->and($audit->record_id)->toBe($request->access_request_id)
        ->and(json_decode($audit->details, true))->toMatchArray(['reason' => 'LOCKOUT_BLOCKED', 'contact_channel' => 'PHONE_CALL', 'request_type' => 'UNBLOCK_ONLY']);
    $this->assertDatabaseHas('audit_log', ['action_type' => 'DEMO_SMS_SENT', 'table_affected' => 'demo_sms_outbox']);
});

it('refuses staff at another branch with 403, writing nothing', function () {
    lockoutBlock($this->login);
    $before = DB::table('audit_log')->count();

    raiseAccessRequest(staffAtBranch(), $this->customer)
        ->assertForbidden()
        ->assertJsonPath('message', 'This customer is managed at another branch.');

    expect(DB::table('access_request')->count())->toBe(0)
        ->and(DB::table('demo_sms_outbox')->count())->toBe(0)
        ->and(DB::table('audit_log')->count())->toBe($before);
});

it('needs every check and the declaration', function (string $check) {
    lockoutBlock($this->login);

    raiseAccessRequest($this->staff, $this->customer, [$check => false])->assertUnprocessable()->assertJsonValidationErrors([$check]);
    $payload = accessRequestPayload();
    unset($payload[$check]);
    actingFresh($this->staff)->postJson("/api/v1/staff/customers/{$this->customer->customer_id}/access-requests", $payload)
        ->assertUnprocessable()->assertJsonValidationErrors([$check]);

    expect(DB::table('access_request')->count())->toBe(0);
})->with(['dob_confirmed', 'phone_confirmed', 'history_reviewed', 'declaration_confirmed']);

it('needs a contact channel, a request type and notes', function () {
    lockoutBlock($this->login);

    raiseAccessRequest($this->staff, $this->customer, ['contact_channel' => 'EMAIL', 'request_type' => null, 'notes' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['contact_channel', 'request_type', 'notes']);
});

it('allows one PENDING request per customer, in the app and in the database', function () {
    lockoutBlock($this->login);
    raiseAccessRequest($this->staff, $this->customer)->assertCreated();

    raiseAccessRequest($this->staff, $this->customer)
        ->assertConflict()
        ->assertJsonPath('message', 'This customer already has a pending access request.');
    expect(DB::table('access_request')->count())->toBe(1);

    $row = (array) DB::table('access_request')->first();
    unset($row['access_request_id'], $row['pending_customer_id']);
    expect(fn () => DB::table('access_request')->insert($row))->toThrow(QueryException::class, 'uq_access_request_one_pending');
});

it('refuses a login that does not need one', function (string $state) {
    if ($state === 'blocked by staff') {
        actingFresh($this->staff)->postJson("/api/v1/staff/customers/{$this->customer->customer_id}/block", ['reason' => 'Fraud'])->assertOk();
    }

    raiseAccessRequest($this->staff, $this->customer)
        ->assertConflict()
        ->assertJsonPath('message', "This customer's login doesn't need an access request.");
    expect(DB::table('access_request')->count())->toBe(0);
})->with(['active', 'blocked by staff']);

it('needs a reset, not just an unblock, for an expired temporary password', function () {
    $this->login->forceFill(['must_change_password' => true, 'temp_password_expires_at' => now()->subMinute()])->save();

    raiseAccessRequest($this->staff, $this->customer, ['request_type' => 'UNBLOCK_ONLY'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['request_type']);

    raiseAccessRequest($this->staff, $this->customer, ['request_type' => 'UNBLOCK_AND_RESET'])->assertCreated();
    expect(DB::table('access_request')->value('reason'))->toBe('TEMP_PASSWORD_EXPIRED');
});

it('refuses the database a request with a missing check', function () {
    expect(fn () => DB::table('access_request')->insert([
        'customer_id' => $this->customer->customer_id, 'user_id' => $this->login->user_id, 'branch_id' => $this->branch->branch_id,
        'reason' => 'LOCKOUT_BLOCKED', 'contact_channel' => 'BRANCH_VISIT', 'dob_confirmed' => true, 'phone_confirmed' => false,
        'history_reviewed' => true, 'declaration_confirmed' => true, 'request_type' => 'UNBLOCK_ONLY', 'notes' => 'x',
        'requested_by' => $this->staff->user_id, 'requested_at' => now(),
    ]))->toThrow(QueryException::class, 'chk_access_request_checks');
});

it('shows staff why the login is locked and the pending request', function () {
    lockoutBlock($this->login);
    actingFresh($this->staff)->getJson("/api/v1/staff/customers/{$this->customer->customer_id}")
        ->assertOk()
        ->assertJsonPath('data.login.recovery', 'LOCKOUT_BLOCKED')
        ->assertJsonPath('data.pending_access_request', null);

    raiseAccessRequest($this->staff, $this->customer)->assertCreated();
    actingFresh($this->staff)->getJson("/api/v1/staff/customers/{$this->customer->customer_id}")
        ->assertJsonPath('data.pending_access_request.request_type', 'UNBLOCK_AND_RESET')
        ->assertJsonPath('data.pending_access_request.requested_by_name', $this->staff->employee->full_name);
});
