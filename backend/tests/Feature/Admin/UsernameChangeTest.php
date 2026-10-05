<?php

use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

it('renames a customer login and audits old -> new', function () {
    $customer = customerWithLogin('VERIFIED');
    $user = $customer->user;
    $old = $user->user_name;

    $this->putJson("/api/v1/admin/customers/{$customer->customer_id}/username", ['user_name' => 'awa.jallow'])
        ->assertOk()
        ->assertJson(['success' => true, 'message' => "Username changed from {$old} to awa.jallow.", 'data' => ['user_name' => 'awa.jallow']]);

    expect($user->fresh()->user_name)->toBe('awa.jallow');

    $audit = DB::table('audit_log')->where('action_type', 'USERNAME_CHANGED')->sole();
    expect($audit->user_id)->toBe($this->admin->user_id)
        ->and($audit->table_affected)->toBe('users')
        ->and($audit->record_id)->toBe($user->user_id)
        // MySQL's JSON type reorders keys, so compare without order.
        ->and(json_decode($audit->details, true))->toEqual([
            'customer_id' => $customer->customer_id,
            'before' => ['user_name' => $old],
            'after' => ['user_name' => 'awa.jallow'],
        ]);
});

it('renames a staff login and audits old -> new', function () {
    $staff = User::factory()->staff()->create(['user_name' => 'lamin']);

    $this->putJson("/api/v1/admin/staff/{$staff->user_id}/username", ['user_name' => 'lamin.ceesay'])->assertOk();

    expect($staff->fresh()->user_name)->toBe('lamin.ceesay');
    $details = json_decode(DB::table('audit_log')->where('action_type', 'USERNAME_CHANGED')->sole()->details, true);
    expect($details)->toEqual(['employee_id' => $staff->employee_id, 'before' => ['user_name' => 'lamin'], 'after' => ['user_name' => 'lamin.ceesay']]);
});

it('validates the new username', function (mixed $value, string $message) {
    User::factory()->create(['user_name' => 'taken']);
    $staff = User::factory()->staff()->create(['user_name' => 'lamin']);

    $this->putJson("/api/v1/admin/staff/{$staff->user_id}/username", ['user_name' => $value])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['user_name' => $message]);

    expect($staff->fresh()->user_name)->toBe('lamin');
    $this->assertDatabaseMissing('audit_log', ['action_type' => 'USERNAME_CHANGED']);
})->with([
    'taken' => ['taken', 'has already been taken'],
    'taken, other case' => ['TAKEN', 'has already been taken'],
    'unchanged' => ['lamin', 'This is already the username.'],
    'empty' => ['', 'required'],
    'too short' => ['ab', 'at least 3'],
    'spaces' => ['lamin ceesay', 'may only contain'],
    'too long' => [str_repeat('a', 51), 'not be greater than 50'],
]);

it('lets a user change only the case of their own name', function () {
    $staff = User::factory()->staff()->create(['user_name' => 'lamin']);

    $this->putJson("/api/v1/admin/staff/{$staff->user_id}/username", ['user_name' => 'Lamin'])->assertOk();

    expect($staff->fresh()->user_name)->toBe('Lamin');
});

it('refuses a customer without a login, admins, and customers on the staff route', function () {
    $noLogin = Customer::factory()->create();
    $this->putJson("/api/v1/admin/customers/{$noLogin->customer_id}/username", ['user_name' => 'someone'])
        ->assertStatus(409)
        ->assertJsonPath('message', 'This customer has no login.');

    $otherAdmin = User::factory()->admin()->create();
    $this->putJson("/api/v1/admin/staff/{$otherAdmin->user_id}/username", ['user_name' => 'someone'])
        ->assertForbidden()
        ->assertJsonPath('message', "Admin accounts can't be changed from here.");
    $this->putJson("/api/v1/admin/staff/{$this->admin->user_id}/username", ['user_name' => 'someone'])->assertForbidden();

    $customerUser = customerWithLogin()->user;
    $this->putJson("/api/v1/admin/staff/{$customerUser->user_id}/username", ['user_name' => 'someone'])->assertNotFound();

    $this->putJson('/api/v1/admin/customers/999999/username', ['user_name' => 'someone'])->assertNotFound();

    $this->assertDatabaseMissing('users', ['user_name' => 'someone']);
});

it('ignores a password sent with a rename', function () {
    $staff = User::factory()->staff()->create(['user_name' => 'lamin']);
    $hash = $staff->password;

    $this->putJson("/api/v1/admin/staff/{$staff->user_id}/username", [
        'user_name' => 'lamin2', 'password' => 'Hijacked123', 'password_confirmation' => 'Hijacked123',
    ])->assertOk();

    $fresh = $staff->fresh();
    expect($fresh->password)->toBe($hash)
        ->and(Hash::check('Hijacked123', $fresh->password))->toBeFalse()
        ->and(DB::table('audit_log')->pluck('details')->implode(' '))->not->toContain('Hijacked123');
});

it('keeps the renamed user logged in: their session survives and shows the new name', function () {
    config(['session.driver' => 'database']);
    $user = User::factory()->create(['user_name' => 'awa']);
    Auth::forgetGuards();

    // The customer logs in for real; keep their session cookie.
    $login = $this->postJson('/api/v1/auth/login', ['user_name' => 'awa', 'password' => 'password1'])->assertOk();
    $sessionId = $login->getCookie(config('session.cookie'))->getValue();
    expect(DB::table('sessions')->where('id', $sessionId)->value('user_id'))->toBe($user->user_id);

    // The admin renames them from another session.
    Auth::forgetGuards();
    $this->actingAs($this->admin);
    $this->putJson("/api/v1/admin/customers/{$user->customer_id}/username", ['user_name' => 'awa.jallow'])->assertOk();

    // The customer's next request on their existing session still works.
    Auth::forgetGuards();
    $this->withCookie(config('session.cookie'), $sessionId)
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.user_name', 'awa.jallow');
    expect(DB::table('sessions')->where('id', $sessionId)->exists())->toBeTrue();

    // From now on only the new name logs in.
    Auth::forgetGuards();
    $this->withCookies([])->postJson('/api/v1/auth/login', ['user_name' => 'awa', 'password' => 'password1'])->assertUnauthorized();
    Auth::forgetGuards();
    $this->postJson('/api/v1/auth/login', ['user_name' => 'awa.jallow', 'password' => 'password1'])->assertOk();
});

it('has no admin route that sets a password', function () {
    $adminPasswordRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/admin') && str_contains($route->uri(), 'password'));
    expect($adminPasswordRoutes)->toBeEmpty();

    $staff = User::factory()->staff()->create();
    $customer = customerWithLogin();
    $hashes = [$staff->password, $customer->user->password];

    foreach ([
        "/api/v1/admin/staff/{$staff->user_id}/password",
        "/api/v1/admin/customers/{$customer->customer_id}/password",
        "/api/v1/admin/users/{$staff->user_id}/password",
    ] as $uri) {
        foreach (['put', 'post', 'patch'] as $method) {
            $status = $this->json($method, $uri, ['password' => 'Hijacked123', 'password_confirmation' => 'Hijacked123'])->status();
            expect($status)->toBeIn([404, 405]);
        }
    }

    expect([$staff->fresh()->password, $customer->user->fresh()->password])->toBe($hashes);
});
