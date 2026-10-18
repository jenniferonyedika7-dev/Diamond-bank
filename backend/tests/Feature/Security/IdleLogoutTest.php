<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/*
| A signed-in session with no user activity for bank.idle_timeout_minutes is
| logged out by the server: 401 with idle_logout, the session invalidated and
| an IDLE_LOGOUT audit row. Only login and POST auth/activity (real clicks,
| typing, navigation, reported by the frontend) restart the clock.
*/

// One clock for login and every check: travel() alone would freeze it a fraction of a second after login.
beforeEach(fn () => $this->freezeSecond());

function signedIn(string $role): User
{
    $user = match ($role) {
        'customer' => bankCustomer(),
        'staff' => staffAtBranch(),
        'admin' => adminUser(),
    };
    attemptLogin($user->user_name, 'password1')->assertOk()->assertJsonPath('data.idle_timeout_minutes', 5);

    return $user;
}

function idleLogouts(): array
{
    return DB::table('audit_log')->where('action_type', 'IDLE_LOGOUT')->get()->all();
}

it('logs out an idle session on the server, for every role', function (string $role) {
    $user = signedIn($role);

    $this->travel(5 * 60 - 1)->seconds();
    $this->getJson('/api/v1/auth/me')->assertOk();

    $this->travel(2)->seconds();
    $this->getJson('/api/v1/auth/me')
        ->assertUnauthorized()
        ->assertExactJson(['success' => false, 'message' => 'You were logged out after 5 minutes of inactivity.', 'data' => ['idle_logout' => true, 'idle_minutes' => 5]]);

    $audit = idleLogouts();
    expect($audit)->toHaveCount(1)
        ->and($audit[0]->user_id)->toBe($user->user_id)
        ->and(json_decode($audit[0]->details, true)['idle_minutes'])->toBe(5);

    // The session is gone: a plain 401, not audited again. (A real request starts with fresh guards;
    // the test app would otherwise keep the user cached in the sanctum guard.)
    Auth::forgetGuards();
    $this->getJson('/api/v1/auth/me')->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
    expect(idleLogouts())->toHaveCount(1);
})->with(['customer', 'staff', 'admin']);

it('does not let background requests keep the session alive', function () {
    signedIn('customer');

    $this->travel(4)->minutes();
    $this->getJson('/api/v1/auth/me')->assertOk();
    $this->getJson('/api/v1/customer/overview')->assertOk();
    $this->getJson('/api/v1/auth/session')->assertOk()->assertJsonPath('data.expires_in', 60);

    $this->travel(61)->seconds();
    $this->getJson('/api/v1/customer/overview')->assertUnauthorized()->assertJsonPath('data.idle_logout', true);
});

it('restarts the clock on reported user activity', function () {
    signedIn('staff');

    $this->travel(4)->minutes();
    $this->postJson('/api/v1/auth/activity')->assertOk()->assertJsonPath('data.expires_in', 300);

    $this->travel(4)->minutes();
    $this->getJson('/api/v1/auth/me')->assertOk();
    $this->getJson('/api/v1/auth/session')->assertJsonPath('data.expires_in', 60);

    $this->travel(61)->seconds();
    $this->getJson('/api/v1/auth/me')->assertUnauthorized()->assertJsonPath('data.idle_logout', true);
});

it('takes the timeout from config', function () {
    config(['bank.idle_timeout_minutes' => 1]);
    $user = bankCustomer();
    attemptLogin($user->user_name, 'password1')->assertOk()->assertJsonPath('data.idle_timeout_minutes', 1);

    $this->travel(61)->seconds();
    $this->getJson('/api/v1/auth/me')
        ->assertUnauthorized()
        ->assertJsonPath('message', 'You were logged out after 1 minute of inactivity.')
        ->assertJsonPath('data.idle_minutes', 1);
});

it('also logs out an idle session that tries to log out or report activity', function (string $method, string $uri) {
    signedIn('customer');
    $this->travel(6)->minutes();

    $this->json($method, $uri)->assertUnauthorized()->assertJsonPath('data.idle_logout', true);
    expect(idleLogouts())->toHaveCount(1);
})->with([['POST', '/api/v1/auth/activity'], ['POST', '/api/v1/auth/logout'], ['GET', '/api/v1/auth/session']]);
