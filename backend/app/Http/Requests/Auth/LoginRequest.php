<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Two failed-login limiters, both checked before the password:
 *  - lower(user_name)|ip: 5 failures per minute (one client hammering one account)
 *  - lower(user_name):    20 failures per hour across all IPs (distributed guessing)
 * Hitting either returns the same 429. The account itself is never locked or blocked.
 */
class LoginRequest extends FormRequest
{
    public const PER_IP_MAX_ATTEMPTS = 5;

    public const PER_IP_DECAY_SECONDS = 60;

    public const PER_USER_MAX_ATTEMPTS = 20;

    public const PER_USER_DECAY_SECONDS = 3600;

    public function rules(): array
    {
        return [
            'user_name' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string'],
        ];
    }

    /** Seconds until the caller may try again, or null if neither limiter is tripped. */
    public function throttledFor(): ?int
    {
        $waits = [];

        if (RateLimiter::tooManyAttempts($this->perIpKey(), self::PER_IP_MAX_ATTEMPTS)) {
            $waits[] = RateLimiter::availableIn($this->perIpKey());
        }

        if (RateLimiter::tooManyAttempts($this->perUserKey(), self::PER_USER_MAX_ATTEMPTS)) {
            $waits[] = RateLimiter::availableIn($this->perUserKey());
        }

        return $waits === [] ? null : max(1, ...$waits);
    }

    public function recordFailedAttempt(): void
    {
        RateLimiter::hit($this->perIpKey(), self::PER_IP_DECAY_SECONDS);
        RateLimiter::hit($this->perUserKey(), self::PER_USER_DECAY_SECONDS);
    }

    /**
     * Called on success. Only the per-IP counter is cleared: failures from other
     * IPs still count toward the hourly per-user limit.
     */
    public function clearIpThrottle(): void
    {
        RateLimiter::clear($this->perIpKey());
    }

    private function perIpKey(): string
    {
        return 'login:'.$this->normalizedUserName().'|'.$this->ip();
    }

    private function perUserKey(): string
    {
        return 'login-user:'.$this->normalizedUserName();
    }

    private function normalizedUserName(): string
    {
        return Str::lower(trim((string) $this->input('user_name')));
    }
}
