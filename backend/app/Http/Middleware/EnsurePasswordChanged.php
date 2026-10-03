<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forces users flagged with must_change_password to change their password
 * before they can use any other endpoint. Registered as 'password.changed'
 * in bootstrap/app.php; routes/api.php applies it to every authenticated
 * route except auth/change-password and auth/logout.
 */
class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password) {
            return ApiResponse::error('You must change your password before continuing.', 403, ['must_change_password' => true]);
        }

        return $next($request);
    }
}
