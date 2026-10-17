<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\ChangePassword;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PasswordLockout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Wrong passwords for an ACTIVE login count toward the per-user
     * PasswordLockout shared with the in-app password re-checks; while it is
     * locked even the correct password is refused. Unknown user_names (and
     * password spraying) are held back by LoginRequest's per-IP limit.
     */
    public function login(LoginRequest $request, PasswordLockout $lockout): JsonResponse
    {
        if ($seconds = $request->throttledFor()) {
            return ApiResponse::error("Too many login attempts. Try again in {$seconds} seconds.", 429, null, ['Retry-After' => (string) $seconds]);
        }

        if (! $request->hasSession()) {
            return ApiResponse::error('Login needs a session: call GET /sanctum/csrf-cookie first and send the web app\'s Origin or Referer header.', 400);
        }

        $userName = $request->validated('user_name');
        $user = User::query()->where('user_name', $userName)->first();

        if ($user !== null && ($seconds = $lockout->lockedFor($user)) !== null) {
            $this->audit->logFor($user->user_id, 'LOGIN_FAILED', 'users', $user->user_id, ['user_name' => $userName, 'reason' => 'LOCKED']);

            return PasswordLockout::lockedResponse($seconds);
        }

        if (! $this->passwordMatches($user, $request->validated('password'))) {
            $request->recordFailedAttempt();
            $this->audit->logFor($user?->user_id, 'LOGIN_FAILED', 'users', $user?->user_id, ['user_name' => $userName]);

            // Only an ACTIVE login is counted: a PENDING applicant or an already blocked login isn't
            // blocked (again). This may answer 429 (a lock starts) or 403 (the login is now blocked).
            if ($user?->status === 'ACTIVE') {
                $lockout->recordFailure($user);
            }

            // Same message whether or not the user_name exists.
            return ApiResponse::error('Invalid username or password.', 401);
        }

        // Status is only revealed once the password is proven correct.
        if ($user->status !== 'ACTIVE') {
            $this->audit->logFor($user->user_id, 'LOGIN_FAILED', 'users', $user->user_id, ['user_name' => $userName, 'reason' => $user->status]);

            return match (true) {
                $user->status === 'PENDING' => ApiResponse::error('Your account is awaiting approval.', 403),
                PasswordLockout::blockedByLockout($user) => ApiResponse::error(PasswordLockout::blockedMessage($user), 403),
                default => ApiResponse::error('Your account is blocked. Please contact the bank.', 403),
            };
        }

        // The password was right, but a temporary one only works for its lifetime (AccessRecovery).
        if ($user->hasExpiredTemporaryPassword()) {
            $this->audit->logFor($user->user_id, 'TEMP_PASSWORD_EXPIRED', 'users', $user->user_id, ['user_name' => $userName]);

            return ApiResponse::error(PasswordLockout::expiredTemporaryPasswordMessage($user), 403);
        }

        $lockout->cleared($user);

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        $user->last_login = now();
        if (Hash::needsRehash($user->password)) {
            $user->password = $request->validated('password');
        }
        $user->save();

        $this->audit->log('LOGIN_SUCCESS', 'users', $user->user_id, null, $user);

        return ApiResponse::success('Login successful.', $this->userPayload($user));
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->audit->log('LOGOUT', 'users', $user->user_id, null, $user);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return ApiResponse::success('Logged out.');
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success('Authenticated user.', $this->userPayload($request->user()));
    }

    public function changePassword(ChangePasswordRequest $request, ChangePassword $changePassword, PasswordLockout $lockout): JsonResponse
    {
        $user = $request->user();

        if (! Hash::check($request->validated('current_password'), $user->password)) {
            $this->audit->log('CHANGE_PASSWORD_FAILED', 'users', $user->user_id, null, $user);
            $lockout->recordFailure($user);

            throw ValidationException::withMessages(['current_password' => __('validation.current_password')]);
        }
        $lockout->cleared($user);

        // Signed in before the temporary password expired: it can't be used to set a new one after.
        if ($user->hasExpiredTemporaryPassword()) {
            $this->audit->log('TEMP_PASSWORD_EXPIRED', 'users', $user->user_id, null, $user);

            return ApiResponse::error(PasswordLockout::expiredTemporaryPasswordMessage($user), 403);
        }

        $changePassword($user, $request->validated('password'));

        $request->session()->regenerate();

        return ApiResponse::success('Password changed.', $this->userPayload($user->refresh()));
    }

    /** @return array<string, mixed> */
    private function userPayload(User $user): array
    {
        $user->loadMissing(['role', 'customer', 'employee']);

        return [
            'user_id' => $user->user_id,
            'user_name' => $user->user_name,
            'role' => $user->role->role_name,
            'status' => $user->status,
            'must_change_password' => $user->must_change_password,
            ...($user->customer ? ['kyc_status' => $user->customer->kyc_status] : []),
            ...($user->employee ? ['full_name' => $user->employee->full_name] : []),
        ];
    }

    /**
     * Runs a bcrypt check even when the user does not exist, so response time
     * does not reveal whether a user_name is registered.
     */
    private function passwordMatches(?User $user, string $password): bool
    {
        if ($user === null) {
            $dummyHash = Cache::rememberForever(
                'auth:dummy-hash:'.config('hashing.bcrypt.rounds'),
                fn () => Hash::make(Str::random(40)),
            );
            Hash::check($password, $dummyHash);

            return false;
        }

        return Hash::check($password, $user->password);
    }
}
