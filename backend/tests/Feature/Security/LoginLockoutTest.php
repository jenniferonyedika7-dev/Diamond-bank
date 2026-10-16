<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/*
| Wrong passwords at the login page count toward the same per-user lockout as
| the in-app password re-checks (PasswordLockout): 5 wrong -> 1 minute, then
| 1 wrong -> 2 minutes, then 1 wrong -> BLOCKED (never an admin). Only ACTIVE
| logins are counted; unknown user_names fall to the per-IP limit.
*/

beforeEach(function () {
    $this->customer = bankCustomer();
});

function loginAs(string $userName, string $password = 'password1', string $ip = '127.0.0.1'): TestResponse
{
    Auth::forgetGuards();

    return test()->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson('/api/v1/auth/login', ['user_name' => $userName, 'password' => $password]);
}

function wrongLogins(string $userName, int $times, string $ip = '127.0.0.1'): void
{
    foreach (range(1, $times) as $i) {
        loginAs($userName, 'wrong-password', $ip)->assertUnauthorized()->assertJsonPath('message', 'Invalid username or password.');
    }
}

function assertLoginLocked(TestResponse $response, int $minutes): void
{
    $response->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertJson(['success' => false, 'message' => "Too many wrong passwords. Try again in {$minutes} ".($minutes === 1 ? 'minute' : 'minutes').'.']);
}

function loginLockoutAudit(): array
{
    return DB::table('audit_log')->whereIn('action_type', ['PASSWORD_LOCKOUT', 'PASSWORD_LOCKOUT_BLOCKED'])->orderBy('audit_log_id')
        ->get(['action_type', 'details'])->map(fn ($row) => [$row->action_type, json_decode($row->details, true)])->all();
}

it('locks the login for 1 minute after 5 wrong passwords, even for the correct one', function () {
    wrongLogins($this->customer->user_name, 4);
    assertLoginLocked(loginAs($this->customer->user_name, 'wrong-password'), 1);

    assertLoginLocked(loginAs($this->customer->user_name), 1);
    assertLoginLocked(loginAs($this->customer->user_name, ip: '10.1.1.1'), 1);   // per user, not per IP

    $this->travel(61)->seconds();
    loginAs($this->customer->user_name)->assertOk();
});

it('goes 1 minute -> 2 minutes -> blocked at the login page', function () {
    $name = $this->customer->user_name;
    wrongLogins($name, 4);
    assertLoginLocked(loginAs($name, 'wrong-password'), 1);

    $this->travel(61)->seconds();
    assertLoginLocked(loginAs($name, 'wrong-password'), 2);
    $this->travel(1)->minutes();
    assertLoginLocked(loginAs($name), 1);

    $this->travel(61)->seconds();
    loginAs($name, 'wrong-password')
        ->assertForbidden()
        ->assertJson(['message' => 'Your account is locked. Please contact your branch.', 'data' => ['account_locked' => true]]);

    expect($this->customer->fresh()->status)->toBe('BLOCKED')
        ->and(loginLockoutAudit())->toBe([
            ['PASSWORD_LOCKOUT', ['stage' => 1, 'minutes' => 1]],
            ['PASSWORD_LOCKOUT', ['stage' => 2, 'minutes' => 2]],
            ['PASSWORD_LOCKOUT_BLOCKED', ['stage' => 3]],
        ]);

    loginAs($name)->assertForbidden()->assertJsonPath('message', 'Your account is locked. Please contact your branch.');
    // Wrong passwords on a blocked login are not counted again.
    wrongLogins($name, 6);
    expect(loginLockoutAudit())->toHaveCount(3);
});

it('shares one count between wrong logins and wrong in-app passwords', function () {
    $from = accountFor($this->customer, '5000.00');
    $to = accountFor(bankCustomer(), '100.00');
    wrongLogins($this->customer->user_name, 3);

    $this->actingAs($this->customer);
    $transfer = fn (string $password) => $this->postJson('/api/v1/customer/transfers', [
        'from_account_number' => $from->account_number, 'to_account_number' => $to->account_number,
        'amount' => '20.00', 'password' => $password,
    ]);
    $transfer('wrong-password')->assertUnprocessable();
    assertLoginLocked($transfer('wrong-password'), 1);
    assertLoginLocked($transfer('password1'), 1);

    assertLoginLocked(loginAs($this->customer->user_name), 1);
});

it('clears the count on a correct login', function () {
    wrongLogins($this->customer->user_name, 4);
    loginAs($this->customer->user_name)->assertOk();

    // Counting from zero again: 4 more don't lock, the 5th does.
    wrongLogins($this->customer->user_name, 4);
    assertLoginLocked(loginAs($this->customer->user_name, 'wrong-password'), 1);
});

it('never blocks an admin at the login page: repeated 2-minute locks', function () {
    $admin = User::query()->whereHas('role', fn ($q) => $q->where('role_name', 'admin'))->firstOrFail();
    $admin->forceFill(['password' => 'password1'])->save();

    wrongLogins($admin->user_name, 4);
    assertLoginLocked(loginAs($admin->user_name, 'wrong-password'), 1);
    $this->travel(61)->seconds();
    assertLoginLocked(loginAs($admin->user_name, 'wrong-password'), 2);
    $this->travel(121)->seconds();
    assertLoginLocked(loginAs($admin->user_name, 'wrong-password'), 2);
    $this->travel(121)->seconds();
    assertLoginLocked(loginAs($admin->user_name, 'wrong-password'), 2);

    expect($admin->fresh()->status)->toBe('ACTIVE');
    $this->assertDatabaseMissing('audit_log', ['action_type' => 'PASSWORD_LOCKOUT_BLOCKED']);
    $this->travel(121)->seconds();
    loginAs($admin->user_name)->assertOk();
});

it('blocks staff at the login page, telling them to contact the administrator', function () {
    $staff = User::factory()->staff()->create();
    wrongLogins($staff->user_name, 4);
    assertLoginLocked(loginAs($staff->user_name, 'wrong-password'), 1);
    $this->travel(61)->seconds();
    assertLoginLocked(loginAs($staff->user_name, 'wrong-password'), 2);
    $this->travel(121)->seconds();

    loginAs($staff->user_name, 'wrong-password')
        ->assertForbidden()
        ->assertJson(['message' => 'Your account is locked. Please contact the administrator.', 'data' => ['account_locked' => true]]);
    expect($staff->fresh()->status)->toBe('BLOCKED');

    loginAs($staff->user_name)->assertForbidden()->assertJsonPath('message', 'Your account is locked. Please contact the administrator.');
});

it('tells staff blocked in-app (change-password) to contact the administrator too', function () {
    $staff = User::factory()->staff()->create();
    $this->actingAs($staff);
    $change = fn () => $this->postJson('/api/v1/auth/change-password', [
        'current_password' => 'wrong-password', 'password' => 'NewSecret123', 'password_confirmation' => 'NewSecret123',
    ]);
    foreach (range(1, 4) as $i) {
        $change()->assertUnprocessable();
    }
    $change()->assertStatus(429);
    $this->travel(61)->seconds();
    $change()->assertStatus(429);
    $this->travel(121)->seconds();

    $change()->assertForbidden()->assertJsonPath('message', 'Your account is locked. Please contact the administrator.');
});

it('answers unknown user_names with 401, locking nobody', function () {
    wrongLogins('nobody-here', 10);

    expect(loginLockoutAudit())->toBe([])
        ->and(Cache::get('password-lockout:'.$this->customer->user_id))->toBeNull();
    loginAs($this->customer->user_name)->assertOk();
});

it('does not count wrong passwords for a login that is not active', function () {
    $pending = User::factory()->staff()->create(['status' => 'PENDING']);

    wrongLogins($pending->user_name, 8);

    expect($pending->fresh()->status)->toBe('PENDING')
        ->and(loginLockoutAudit())->toBe([]);
    loginAs($pending->user_name)->assertForbidden()->assertJsonPath('message', 'Your account is awaiting approval.');
});
