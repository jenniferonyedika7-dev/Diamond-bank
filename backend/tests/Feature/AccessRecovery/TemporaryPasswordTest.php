<?php

use App\Models\Branch;
use App\Support\TemporaryPassword;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/*
| The temporary password from an approved UNBLOCK_AND_RESET request: the
| system makes it, stores only its hash and texts it. Nobody else ever sees
| it. It works for bank.temp_password_expiry_minutes and must be changed at
| first login; wrong passwords still count toward the lockout.
*/

beforeEach(function () {
    $this->branch = Branch::factory()->create();
    $this->staff = staffAtBranch($this->branch);
    $this->customer = customerWithLogin('VERIFIED', $this->branch->branch_id);
    $this->login = $this->customer->user;
    $this->admin = adminUser();
    lockoutBlock($this->login);
});

function approveReset(): string
{
    raiseAccessRequest(test()->staff, test()->customer, ['request_type' => 'UNBLOCK_AND_RESET'])->assertCreated();
    actingFresh(test()->admin)->postJson('/api/v1/admin/access-requests/'.pendingRequestId().'/approve')->assertOk();

    return temporaryPasswordFor(test()->login);
}

it('stores only a hash, and never shows the temporary password in a response, the audit log or a log', function () {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
        $logged[] = $e->message.' '.json_encode($e->context);
    });

    raiseAccessRequest($this->staff, $this->customer)->assertCreated();
    $id = pendingRequestId();
    $responses = [actingFresh($this->admin)->postJson("/api/v1/admin/access-requests/{$id}/approve")->assertOk()];
    $temporary = temporaryPasswordFor($this->login);
    Log::info('access request approved', ['access_request_id' => $id]);   // the capture works

    $responses[] = actingFresh($this->admin)->getJson("/api/v1/admin/access-requests/{$id}")->assertOk();
    $responses[] = actingFresh($this->admin)->getJson('/api/v1/admin/access-requests?status=APPROVED')->assertOk();
    $responses[] = actingFresh($this->admin)->getJson("/api/v1/admin/customers/{$this->customer->customer_id}")->assertOk();
    $responses[] = actingFresh($this->admin)->getJson('/api/v1/staff/audit-log?per_page=100')->assertOk();
    $responses[] = actingFresh($this->staff)->getJson("/api/v1/staff/customers/{$this->customer->customer_id}")->assertOk();
    $responses[] = actingFresh($this->staff)->getJson('/api/v1/staff/audit-log?per_page=100')->assertOk();
    $responses[] = attemptLogin($this->login->user_name, $temporary)->assertOk();
    $responses[] = $this->getJson('/api/v1/auth/me')->assertForbidden();
    $responses[] = $this->getJson('/api/v1/demo/sms-outbox')->assertNotFound();   // not local

    foreach ($responses as $response) {
        expect($response->getContent())->not->toContain($temporary);
    }
    $stored = DB::table('users')->where('user_id', $this->login->user_id)->value('password');
    expect($stored)->not->toBe($temporary)
        ->and(Hash::check($temporary, $stored))->toBeTrue()
        ->and(json_encode(DB::table('audit_log')->get()))->not->toContain($temporary)
        ->and(json_encode(DB::table('access_request')->get()))->not->toContain($temporary)
        ->and(DB::table('demo_sms_outbox')->pluck('message')->implode(' '))->not->toContain($temporary)   // encrypted at rest
        ->and($logged)->toHaveCount(1)
        ->and(implode("\n", $logged))->not->toContain($temporary);
});

it('makes 12-character passwords with upper, lower and digits, different every time', function () {
    $passwords = collect(range(1, 50))->map(fn () => TemporaryPassword::generate());

    expect($passwords->unique())->toHaveCount(50);
    $passwords->each(fn (string $p) => expect($p)->toMatch('/^(?=.*[A-Z])(?=.*[a-z])(?=.*[2-9])[A-HJ-NP-Za-km-z2-9]{12}$/'));
});

it('refuses an expired temporary password at login, telling the customer to contact their branch', function () {
    $temporary = approveReset();
    $minutes = config('bank.temp_password_expiry_minutes');

    $this->travel($minutes * 60 - 5)->seconds();
    attemptLogin($this->login->user_name, $temporary)->assertOk();   // still inside its lifetime

    $this->travel(6)->seconds();
    attemptLogin($this->login->user_name, $temporary)
        ->assertForbidden()
        ->assertExactJson(['success' => false, 'message' => 'This temporary password has expired. Please contact your branch.', 'data' => null]);
    $this->getJson('/api/v1/auth/me')->assertUnauthorized();

    $this->assertDatabaseHas('audit_log', ['action_type' => 'TEMP_PASSWORD_EXPIRED', 'record_id' => $this->login->user_id]);
    expect($this->login->fresh()->status)->toBe('ACTIVE');
});

it('lets staff raise a new request for an expired temporary password, which the admin can approve', function () {
    $old = approveReset();
    $this->travel(config('bank.temp_password_expiry_minutes') + 1)->minutes();

    actingFresh($this->staff)->getJson("/api/v1/staff/customers/{$this->customer->customer_id}")
        ->assertJsonPath('data.login.recovery', 'TEMP_PASSWORD_EXPIRED');
    raiseAccessRequest($this->staff, $this->customer, ['request_type' => 'UNBLOCK_AND_RESET'])->assertCreated();
    actingFresh($this->admin)->postJson('/api/v1/admin/access-requests/'.pendingRequestId().'/approve')->assertOk();
    $new = temporaryPasswordFor($this->login);

    expect($new)->not->toBe($old);
    attemptLogin($this->login->user_name, $old)->assertUnauthorized();
    attemptLogin($this->login->user_name, $new)->assertOk()->assertJsonPath('data.must_change_password', true);
});

it('refuses change-password once the temporary password has expired', function () {
    $temporary = approveReset();
    attemptLogin($this->login->user_name, $temporary)->assertOk();
    $this->travel(config('bank.temp_password_expiry_minutes') + 1)->minutes();

    $this->postJson('/api/v1/auth/change-password', [
        'current_password' => $temporary, 'password' => 'MyNewSecret123', 'password_confirmation' => 'MyNewSecret123',
    ])->assertForbidden()->assertJsonPath('message', 'This temporary password has expired. Please contact your branch.');

    $this->assertDatabaseMissing('audit_log', ['action_type' => 'PASSWORD_CHANGED']);
});

it('still counts wrong passwords toward the lockout', function () {
    $temporary = approveReset();

    foreach (range(1, 4) as $i) {
        attemptLogin($this->login->user_name, 'wrong-password')->assertUnauthorized();
    }
    attemptLogin($this->login->user_name, 'wrong-password')->assertStatus(429);
    attemptLogin($this->login->user_name, $temporary)->assertStatus(429);
});
