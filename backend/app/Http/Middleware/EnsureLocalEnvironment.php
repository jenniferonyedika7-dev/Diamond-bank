<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Development-only routes (the demo SMS outbox): a 404 unless APP_ENV=local,
 * so anywhere else they look like they don't exist. Checked on every request.
 * Registered as 'local.only' in bootstrap/app.php.
 */
class EnsureLocalEnvironment
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->environment('local')) {
            return ApiResponse::error('Not Found', 404);
        }

        return $next($request);
    }
}
