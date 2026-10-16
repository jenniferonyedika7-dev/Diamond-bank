<?php

namespace App\Http\Middleware;

use App\Services\PasswordLockout;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses the password re-check routes with a 429 while the user is locked
 * out (PasswordLockout), before validation or the password itself is looked
 * at, so even the correct password waits for the lock to end.
 * Registered as 'password.lockout' in bootstrap/app.php.
 */
class EnsurePasswordNotLocked
{
    public function __construct(private PasswordLockout $lockout) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ($seconds = $this->lockout->lockedFor($user)) !== null) {
            return PasswordLockout::lockedResponse($seconds);
        }

        return $next($request);
    }
}
