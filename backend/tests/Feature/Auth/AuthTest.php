<?php

use App\Http\Requests\Auth\LoginRequest;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

function customerPayload(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Awa',
        'last_name' => 'Jallow',
        'date_of_birth' => '1995-04-12',
        'gender' => 'F',
        'national_id' => 'GM-123456',
        'phone' => '7001234',
        'email' => 'awa@example.test',
        'branch_id' => Branch::factory()->create()->branch_id,
        'user_name' => 'awa',
        'password' => 'secret123',
        'password_confirmation' => 'secret123',
    ], $overrides);
}

function staffPayload(array $overrides = []): array
{
    return array_merge([
        'full_name' => 'Lamin Ceesay',
        'national_id' => 'GM-STAFF-1',
        'position' => 'Teller',
        'phone' => '7005555',
        'email' => 'lamin@diamondbank.test',
        'user_name' => 'lamin',
        'password' => 'secret123',
        'password_confirmation' => 'secret123',
    ], $overrides);
}

function login(string $userName, string $password, string $ip = '127.0.0.1')
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson('/api/v1/auth/login', ['user_name' => $userName, 'password' => $password]);
}

it('lets a customer register, log in, and see kyc_status PENDING on /me', function () {
    $this->postJson('/api/v1/auth/register/customer', customerPayload())
        ->assertCreated()
        ->assertJson(['success' => true, 'data' => ['user_name' => 'awa']]);

    login('awa', 'secret123')
        ->assertOk()
        ->assertJson(['success' => true, 'data' => ['role' => 'customer', 'status' => 'ACTIVE']]);

    $this->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJson([
            'success' => true,
            'data' => ['user_name' => 'awa', 'role' => 'customer', 'kyc_status' => 'PENDING', 'must_change_password' => false],
        ]);

    $user = User::firstWhere('user_name', 'awa');
    expect($user->last_login)->not->toBeNull();
    $this->assertDatabaseHas('audit_log', ['action_type' => 'CUSTOMER_REGISTERED', 'user_id' => $user->user_id]);
    $this->assertDatabaseHas('audit_log', ['action_type' => 'LOGIN_SUCCESS', 'user_id' => $user->user_id]);
});

it('refuses login for a newly registered staff member awaiting approval', function () {
    $this->postJson('/api/v1/auth/register/staff', staffPayload())
        ->assertCreated()
        ->assertJsonPath('message', 'Registration received. Your account is awaiting admin approval.');

    $this->assertDatabaseHas('users', ['user_name' => 'lamin', 'status' => 'PENDING']);

    login('lamin', 'secret123')
        ->assertForbidden()
        ->assertJson(['success' => false, 'message' => 'Your account is awaiting approval.']);

    $this->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('does not let a blocked user log in', function () {
    User::factory()->blocked()->create(['user_name' => 'blocked1']);

    login('blocked1', 'password1')
        ->assertForbidden()
        ->assertJson(['success' => false, 'message' => 'Your account is blocked. Please contact the bank.']);

    $this->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('gives the same generic message for a wrong password and an unknown user, and audits without the password', function () {
    $user = User::factory()->create(['user_name' => 'real']);

    login('real', 'wrong-pass1')->assertUnauthorized()->assertJsonPath('message', 'Invalid username or password.');
    login('ghost', 'wrong-pass1')->assertUnauthorized()->assertJsonPath('message', 'Invalid username or password.');

    $rows = DB::table('audit_log')->where('action_type', 'LOGIN_FAILED')->orderBy('audit_log_id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->user_id)->toBe($user->user_id)
        ->and(json_decode($rows[0]->details, true))->toBe(['user_name' => 'real'])
        ->and($rows[1]->user_id)->toBeNull()
        ->and(json_decode($rows[1]->details, true))->toBe(['user_name' => 'ghost'])
        ->and($rows->pluck('details')->implode(' '))->not->toContain('wrong-pass1');
});

it('throttles the 6th failed login in a minute for the same user_name and IP', function () {
    User::factory()->create(['user_name' => 'target']);

    foreach (range(1, 5) as $i) {
        login('target', "wrong{$i}x")->assertUnauthorized();
    }

    // Even the correct password is refused while throttled; case doesn't dodge the key.
    login('TARGET', 'password1')
        ->assertTooManyRequests()
        ->assertHeader('Retry-After')
        ->assertJson(['success' => false, 'data' => null])
        ->assertJsonPath('message', fn ($m) => str_starts_with($m, 'Too many login attempts. Try again in '));

    // A different IP is not affected by the per-IP limit.
    login('target', 'password1', '10.9.9.9')->assertOk();
});

it('throttles a user_name after 20 failed logins in an hour across many IPs, without locking the account', function () {
    $user = User::factory()->create(['user_name' => 'victim']);

    // 20 failures spread over 5 IPs, 4 each: the per-IP limit (5/min) never trips.
    foreach (range(1, 5) as $ipSuffix) {
        foreach (range(1, 4) as $attempt) {
            login('Victim', "wrong{$attempt}x", "10.0.0.{$ipSuffix}")->assertUnauthorized();
        }
    }

    // A fresh IP, with the correct password, is refused with the same 429 message.
    $response = login('victim', 'password1', '10.0.0.99')
        ->assertTooManyRequests()
        ->assertHeader('Retry-After')
        ->assertJson(['success' => false, 'data' => null]);

    expect($response->json('message'))->toMatch('/^Too many login attempts\. Try again in \d+ seconds\.$/');
    expect((int) $response->headers->get('Retry-After'))
        ->toBeGreaterThan(LoginRequest::PER_IP_DECAY_SECONDS)
        ->toBeLessThanOrEqual(LoginRequest::PER_USER_DECAY_SECONDS);

    // The account itself is untouched, and other user_names are unaffected.
    expect($user->fresh()->status)->toBe('ACTIVE');
    User::factory()->create(['user_name' => 'bystander']);
    login('bystander', 'password1', '10.0.0.1')->assertOk();

    // Once the hour has passed, the real user can log in again.
    $this->travel(LoginRequest::PER_USER_DECAY_SECONDS + 1)->seconds();
    login('victim', 'password1', '10.0.0.99')->assertOk();
});

it('blocks an admin who must change their password from /me until they change it', function () {
    $admin = User::firstWhere('user_name', config('admin.username'));
    expect($admin->must_change_password)->toBeTrue();

    login($admin->user_name, config('admin.password'))
        ->assertOk()
        ->assertJsonPath('data.must_change_password', true);

    $this->getJson('/api/v1/auth/me')
        ->assertForbidden()
        ->assertJson(['success' => false, 'data' => ['must_change_password' => true]]);

    $this->postJson('/api/v1/auth/change-password', [
        'current_password' => config('admin.password'),
        'password' => config('admin.password'),
        'password_confirmation' => config('admin.password'),
    ])->assertUnprocessable()->assertJsonValidationErrors('password');

    $this->postJson('/api/v1/auth/change-password', [
        'current_password' => config('admin.password'),
        'password' => 'NewAdmin2026',
        'password_confirmation' => 'NewAdmin2026',
    ])->assertOk()->assertJsonPath('data.must_change_password', false);

    $this->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJson(['data' => ['role' => 'admin', 'must_change_password' => false, 'full_name' => 'System Administrator']]);

    expect(Hash::check('NewAdmin2026', $admin->fresh()->password))->toBeTrue();
    $this->assertDatabaseHas('audit_log', ['action_type' => 'PASSWORD_CHANGED', 'user_id' => $admin->user_id]);
});

it('stores passwords as bcrypt hashes, never plain text', function () {
    $this->postJson('/api/v1/auth/register/customer', customerPayload())
        ->assertCreated()
        ->assertJsonMissingPath('data.password');

    $stored = DB::table('users')->where('user_name', 'awa')->value('password');

    expect($stored)->not->toBe('secret123')
        ->toStartWith('$2y$')
        ->and(Hash::info($stored)['algoName'])->toBe('bcrypt')
        ->and(Hash::check('secret123', $stored))->toBeTrue()
        ->and(DB::table('audit_log')->pluck('details')->implode(' '))->not->toContain('secret123');
});

it('keeps a customer out of a role:admin,staff route', function () {
    Route::middleware(['api', 'auth:sanctum', 'active', 'password.changed', 'role:admin,staff'])
        ->get('/api/v1/test/staff-only', fn () => response()->json(['success' => true]));

    User::factory()->create(['user_name' => 'cust']);
    User::factory()->staff()->create(['user_name' => 'teller']);

    login('cust', 'password1')->assertOk();
    $this->getJson('/api/v1/test/staff-only')
        ->assertForbidden()
        ->assertJson(['success' => false, 'message' => 'You do not have permission to access this resource.']);

    $this->postJson('/api/v1/auth/logout')->assertOk();
    $this->assertDatabaseHas('audit_log', ['action_type' => 'LOGOUT']);

    // The test app is reused across requests and Sanctum's guard caches its user; a real request starts fresh.
    Auth::forgetGuards();

    login('teller', 'password1')->assertOk();
    $this->getJson('/api/v1/test/staff-only')->assertOk();
});
