<?php

use App\Models\Customer;
use App\Models\DemoSms;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** A login attempt from the login page, with nobody logged in (a fresh session). */
function attemptLogin(string $userName, string $password): TestResponse
{
    Auth::forgetGuards();
    test()->flushSession();

    return test()->postJson('/api/v1/auth/login', ['user_name' => $userName, 'password' => $password]);
}

/** Blocks $user's login the real way (PasswordLockout): 5 wrong, 1 wrong after the first lock, 1 wrong after the second. */
function lockoutBlock(User $user): void
{
    foreach (range(1, 4) as $i) {
        attemptLogin($user->user_name, 'wrong-password')->assertUnauthorized();
    }
    attemptLogin($user->user_name, 'wrong-password')->assertStatus(429);
    test()->travel(config('bank.password_lockout.first_minutes') * 60 + 1)->seconds();
    attemptLogin($user->user_name, 'wrong-password')->assertStatus(429);
    test()->travel(config('bank.password_lockout.second_minutes') * 60 + 1)->seconds();
    attemptLogin($user->user_name, 'wrong-password')->assertForbidden()->assertJsonPath('data.account_locked', true);

    expect($user->fresh()->status)->toBe('BLOCKED');
}

/** The seeded administrator, past its first-login password change. */
function adminUser(): User
{
    $admin = User::query()->whereHas('role', fn ($q) => $q->where('role_name', 'admin'))->firstOrFail();
    $admin->forceFill(['must_change_password' => false, 'password' => 'password1'])->save();

    return $admin->fresh();
}

/** Acts as $user in a fresh session: the test app keeps one session store between requests (see session_helpers.php). */
function actingFresh(User $user): TestCase
{
    Auth::forgetGuards();
    test()->flushSession();

    return test()->actingAs($user->fresh());
}

/** @param  array<string, mixed>  $overrides */
function accessRequestPayload(array $overrides = []): array
{
    return [
        'contact_channel' => 'BRANCH_VISIT',
        'dob_confirmed' => true,
        'phone_confirmed' => true,
        'history_reviewed' => true,
        'declaration_confirmed' => true,
        'request_type' => 'UNBLOCK_AND_RESET',
        'notes' => 'Customer came in with their national ID card.',
        ...$overrides,
    ];
}

/** @param  array<string, mixed>  $overrides */
function raiseAccessRequest(User $staff, Customer $customer, array $overrides = []): TestResponse
{
    return actingFresh($staff)->postJson("/api/v1/staff/customers/{$customer->customer_id}/access-requests", accessRequestPayload($overrides));
}

/** The newest PENDING access request. */
function pendingRequestId(): int
{
    return DB::table('access_request')->where('status', 'PENDING')->orderByDesc('access_request_id')->value('access_request_id');
}

/** The texts $user received, oldest first (decrypted). */
function smsTexts(User $user): array
{
    return DemoSms::query()->where('user_id', $user->user_id)->orderBy('demo_sms_id')->get()->pluck('message')->all();
}

/** The temporary password from the last SMS to $user, the way the user reads it off their phone. */
function temporaryPasswordFor(User $user): string
{
    $text = DemoSms::query()->where('user_id', $user->user_id)->orderByDesc('demo_sms_id')->firstOrFail()->message;
    expect(preg_match('/Your temporary password is ([A-Za-z0-9]{12})\./', $text, $match))->toBe(1);

    return $match[1];
}

/** @return list<string> action types of the audit rows, oldest first */
function auditActions(): array
{
    return DB::table('audit_log')->orderBy('audit_log_id')->pluck('action_type')->all();
}
