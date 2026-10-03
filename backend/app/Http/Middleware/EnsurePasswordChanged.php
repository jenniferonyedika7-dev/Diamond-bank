<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forces users flagged with must_change_password to change their password
 * before they can use any other page.
 *
 * TODO (auth phase): create these named routes:
 *   - password.change         GET  change-password page
 *   - password.change.update  PUT  form submit (call App\Actions\Auth\ChangePassword)
 *   - logout                  POST
 * Until password.change exists, flagged users get a 403 instead of a redirect.
 */
class EnsurePasswordChanged
{
    private const ALLOWED_ROUTES = ['password.change', 'password.change.update', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->must_change_password || $request->routeIs(...self::ALLOWED_ROUTES)) {
            return $next($request);
        }

        if ($request->expectsJson() || ! Route::has('password.change')) {
            abort(403, 'You must change your password before continuing.');
        }

        return redirect()->route('password.change');
    }
}
