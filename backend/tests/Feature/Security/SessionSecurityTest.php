<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
| Real sessions (database driver): each login's session id is replayed as the
| session cookie, as the browser would send it (onSession(), session_helpers.php).
*/

beforeEach(function () {
    config(['session.driver' => 'database']);
    $this->user = User::factory()->create(['user_name' => 'awa']);
});

/** Logs in as awa on a fresh session and returns that session's id. */
function freshSession(string $password = 'password1'): string
{
    $response = onSession('')
        ->postJson('/api/v1/auth/login', ['user_name' => 'awa', 'password' => $password])
        ->assertOk();

    return sessionIdFrom($response);
}

it('ends the session on logout: the old cookie no longer works', function () {
    $session = freshSession();
    onSession($session)->getJson('/api/v1/auth/me')->assertOk();

    onSession($session)->postJson('/api/v1/auth/logout')->assertOk();
    expect(DB::table('sessions')->where('id', $session)->exists())->toBeFalse();

    onSession($session)->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('logs out every other session when the password changes, and keeps this one', function () {
    $sessionA = freshSession();
    $sessionB = freshSession();
    expect($sessionA)->not->toBe($sessionB);
    onSession($sessionB)->getJson('/api/v1/auth/me')->assertOk();

    $change = onSession($sessionA)->postJson('/api/v1/auth/change-password', [
        'current_password' => 'password1',
        'password' => 'NewSecret123',
        'password_confirmation' => 'NewSecret123',
    ])->assertOk();
    $sessionA2 = $change->getCookie(config('session.cookie'))->getValue();

    onSession($sessionA2)->getJson('/api/v1/auth/me')->assertOk();
    onSession($sessionB)->getJson('/api/v1/auth/me')->assertUnauthorized();
    onSession($sessionA)->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('never limits successful password changes', function () {
    $session = freshSession();
    $password = 'password1';

    foreach (range(1, 8) as $i) {
        $next = "NewSecret{$i}x";
        $response = onSession($session)->postJson('/api/v1/auth/change-password', [
            'current_password' => $password,
            'password' => $next,
            'password_confirmation' => $next,
        ])->assertOk();
        $session = $response->getCookie(config('session.cookie'))->getValue();
        $password = $next;
    }
});
