<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The per-IP failed-login limit: 30 failures a minute from one IP, whatever
 * the user_name (password spraying, and guessing at unknown user_names).
 * Checked before the password; the 429 says how long to wait. Wrong passwords
 * for a known, ACTIVE login are also counted per user by PasswordLockout
 * (AuthController::login), shared with the in-app password re-checks.
 */
class LoginRequest extends FormRequest
{
    public const PER_IP_ANY_USER_MAX_ATTEMPTS = 30;

    public const PER_IP_ANY_USER_DECAY_SECONDS = 60;

    public function rules(): array
    {
        return [
            'user_name' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string'],
        ];
    }

    /** Seconds until this IP may try again, or null if it isn't limited. */
    public function throttledFor(): ?int
    {
        return RateLimiter::tooManyAttempts($this->perIpKey(), self::PER_IP_ANY_USER_MAX_ATTEMPTS)
            ? max(1, RateLimiter::availableIn($this->perIpKey()))
            : null;
    }

    /** Never cleared on a success: one correct password doesn't reset a spraying IP's count. */
    public function recordFailedAttempt(): void
    {
        RateLimiter::hit($this->perIpKey(), self::PER_IP_ANY_USER_DECAY_SECONDS);
    }

    private function perIpKey(): string
    {
        return 'login-ip:'.$this->ip();
    }
}
