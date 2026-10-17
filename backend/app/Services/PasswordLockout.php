<?php

namespace App\Services;

use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Wrong-password lockout for the login page and the in-app password re-checks
 * (transfers, card reveal, loan payments, change-password): one count per
 * user, in the cache, shared by all of them.
 *
 *  - stage 0: max_failures wrong passwords -> stage 1, locked for first_minutes
 *  - stage 1, lock over: one wrong password -> stage 2, locked for second_minutes
 *  - stage 2, lock over: one wrong password -> the login is BLOCKED and every
 *    session ends (an admin is never blocked: another second_minutes lock)
 *
 * The caller checks the password: cleared() after a correct one,
 * recordFailure() after a wrong one. reset_hours without a wrong password
 * also clears it. While locked, EnsurePasswordNotLocked refuses the routes
 * before the password is looked at. Limits: config('bank.password_lockout').
 */
class PasswordLockout
{
    /** What a login blocked by the lockout is told: customers go to their branch, staff to the administrator. */
    public static function blockedMessage(User $user): string
    {
        return $user->hasRole('staff')
            ? 'Your account is locked. Please contact the administrator.'
            : 'Your account is locked. Please contact your branch.';
    }

    /** What a user with an expired temporary password is told at login. */
    public static function expiredTemporaryPasswordMessage(User $user): string
    {
        return $user->hasRole('staff')
            ? 'This temporary password has expired. Please contact the administrator.'
            : 'This temporary password has expired. Please contact your branch.';
    }

    /**
     * Whether $user's latest block or unblock was this lockout's. A login blocked
     * this way is only reopened through an access request (customers) or an
     * administrator's restore (staff), not the plain unblock.
     */
    public static function blockedByLockout(User $user): bool
    {
        return $user->status === 'BLOCKED' && DB::table('audit_log')
            ->where('table_affected', 'users')
            ->where('record_id', $user->user_id)
            ->whereIn('action_type', [
                'PASSWORD_LOCKOUT_BLOCKED', 'CUSTOMER_BLOCKED', 'CUSTOMER_UNBLOCKED',
                'STAFF_BLOCKED', 'STAFF_REJECTED', 'STAFF_UNBLOCKED', 'STAFF_ACCESS_RESTORED',
            ])
            ->orderByDesc('audit_log_id')
            ->value('action_type') === 'PASSWORD_LOCKOUT_BLOCKED';
    }

    public function __construct(private AuditLogger $audit, private Request $request) {}

    /** Seconds until $user may try a password again, or null when not locked. */
    public function lockedFor(User $user): ?int
    {
        $lockedUntil = $this->state($user)['locked_until'];
        $seconds = $lockedUntil === null ? 0 : $lockedUntil - now()->getTimestamp();

        return $seconds > 0 ? $seconds : null;
    }

    /** A correct password: the count and stage start again from zero. */
    public function cleared(User $user): void
    {
        Cache::forget($this->key($user));
    }

    /**
     * Counts a wrong password. Returns when it only counts; throws a 429 when
     * it starts a lock, or a 403 when it blocks the login.
     *
     * @throws HttpResponseException
     */
    public function recordFailure(User $user): void
    {
        $config = config('bank.password_lockout');
        $state = $this->state($user);

        if ($state['stage'] === 0) {
            $state['failures']++;
            if ($state['failures'] < $config['max_failures']) {
                $this->save($user, $state);

                return;
            }
            $this->lock($user, 1, $config['first_minutes']);
        }

        if ($state['stage'] === 1 || $user->hasRole('admin')) {
            $this->lock($user, 2, $config['second_minutes']);
        }

        $this->block($user);
    }

    /** The 429 a locked user gets, with the minutes left. */
    public static function lockedResponse(int $seconds): JsonResponse
    {
        $minutes = (int) ceil($seconds / 60);

        return ApiResponse::error(
            "Too many wrong passwords. Try again in {$minutes} ".Str::plural('minute', $minutes).'.',
            429, null, ['Retry-After' => (string) $seconds],
        );
    }

    /** @throws HttpResponseException always: the 429 for the new lock */
    private function lock(User $user, int $stage, int $minutes): never
    {
        $seconds = $minutes * 60;
        $this->save($user, ['failures' => 0, 'stage' => $stage, 'locked_until' => now()->getTimestamp() + $seconds]);
        $this->audit->log('PASSWORD_LOCKOUT', 'users', $user->user_id, ['stage' => $stage, 'minutes' => $minutes], $user);

        throw new HttpResponseException(self::lockedResponse($seconds));
    }

    /** @throws HttpResponseException always: the 403 for the blocked login */
    private function block(User $user): never
    {
        DB::transaction(function () use ($user) {
            DB::table('users')->where('user_id', $user->user_id)->update(['status' => 'BLOCKED', 'updated_at' => now()]);
            $this->audit->log('PASSWORD_LOCKOUT_BLOCKED', 'users', $user->user_id, ['stage' => 3], $user);
        });
        Cache::forget($this->key($user));

        // Every stored session, and this request's, which would otherwise be saved again at the end
        // (at the login page nobody is logged in yet).
        $user->endSessions();
        if (Auth::guard('web')->check()) {
            Auth::guard('web')->logout();
        }
        if ($this->request->hasSession()) {
            $this->request->session()->invalidate();
            $this->request->session()->regenerateToken();
        }

        throw new HttpResponseException(ApiResponse::error(self::blockedMessage($user), 403, ['account_locked' => true]));
    }

    /** @return array{failures: int, stage: int, locked_until: ?int} */
    private function state(User $user): array
    {
        return Cache::get($this->key($user)) ?? ['failures' => 0, 'stage' => 0, 'locked_until' => null];
    }

    /** Kept reset_hours after this wrong password, and at least until the lock ends. */
    private function save(User $user, array $state): void
    {
        $seconds = max(config('bank.password_lockout.reset_hours') * 3600, ($state['locked_until'] ?? 0) - now()->getTimestamp());

        Cache::put($this->key($user), $state, $seconds);
    }

    private function key(User $user): string
    {
        return "password-lockout:{$user->user_id}";
    }
}
