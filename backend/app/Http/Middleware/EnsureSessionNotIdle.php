<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Services\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logs out a session with no user activity for bank.idle_timeout_minutes. Only
 * real activity counts: login and POST auth/activity, which the frontend sends
 * on clicks, typing and navigation (see recordActivity()). Every other request,
 * including background ones, is checked but never extends the session.
 * Registered as 'idle' in bootstrap/app.php.
 */
class EnsureSessionNotIdle
{
    public const KEY = 'idle.last_activity';

    public function __construct(private AuditLogger $audit) {}

    public static function timeoutSeconds(): int
    {
        return config('bank.idle_timeout_minutes') * 60;
    }

    /** Marks now as the session's last real user activity. */
    public static function recordActivity(Request $request): void
    {
        $request->session()->put(self::KEY, now()->getTimestamp());
    }

    /** Seconds until the session is logged out for inactivity. */
    public static function expiresIn(Request $request): int
    {
        return max(0, (int) $request->session()->get(self::KEY) + self::timeoutSeconds() - now()->getTimestamp());
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || $request->user() === null) {
            return $next($request);
        }

        $session = $request->session();
        // A session from before idle logout existed (or a test's actingAs): its clock starts now.
        if (! $session->has(self::KEY)) {
            self::recordActivity($request);
        }

        $lastActivity = (int) $session->get(self::KEY);
        if (now()->getTimestamp() - $lastActivity > self::timeoutSeconds()) {
            $user = $request->user();
            $minutes = (int) config('bank.idle_timeout_minutes');
            $this->audit->log('IDLE_LOGOUT', 'users', $user->user_id, [
                'idle_minutes' => $minutes,
                'last_activity_at' => date('Y-m-d H:i:s', $lastActivity),
            ], $user);

            Auth::guard('web')->logout();
            $session->invalidate();
            $session->regenerateToken();

            return ApiResponse::error(
                "You were logged out after {$minutes} ".Str::plural('minute', $minutes).' of inactivity.',
                401,
                ['idle_logout' => true, 'idle_minutes' => $minutes],
            );
        }

        return $next($request);
    }
}
