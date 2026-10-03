<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\ChangePassword;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function login(LoginRequest $request): JsonResponse
    {
        if ($seconds = $request->throttledFor()) {
            return ApiResponse::error("Too many login attempts. Try again in {$seconds} seconds.", 429, null, ['Retry-After' => (string) $seconds]);
        }

        if (! $request->hasSession()) {
            return ApiResponse::error('Login needs a session: call GET /sanctum/csrf-cookie first and send the web app\'s Origin or Referer header.', 400);
        }

        $userName = $request->validated('user_name');
        $user = User::query()->where('user_name', $userName)->first();

        if (! $this->passwordMatches($user, $request->validated('password'))) {
            $request->recordFailedAttempt();
            $this->audit->logFor($user?->user_id, 'LOGIN_FAILED', 'users', $user?->user_id, ['user_name' => $userName]);

            // Same message whether or not the user_name exists.
            return ApiResponse::error('Invalid username or password.', 401);
        }

        // Status is only revealed once the password is proven correct.
        if ($user->status !== 'ACTIVE') {
            $this->audit->logFor($user->user_id, 'LOGIN_FAILED', 'users', $user->user_id, ['user_name' => $userName, 'reason' => $user->status]);

            return $user->status === 'PENDING'
                ? ApiResponse::error('Your account is awaiting approval.', 403)
                : ApiResponse::error('Your account is blocked. Please contact the bank.', 403);
        }

        $request->clearIpThrottle();

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

    public function changePassword(ChangePasswordRequest $request, ChangePassword $changePassword): JsonResponse
    {
        $user = $request->user();
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
