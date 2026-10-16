<?php

namespace App\Providers;

use App\Http\Responses\ApiResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Used by registration and change-password via Password::defaults().
        Password::defaults(fn () => Password::min(8)->letters()->numbers());

        // Transfer recipient lookups, per user: every attempt counts.
        RateLimiter::for('customer-lookup', fn (Request $request) => $this->perUserPerMinute($request, 10));
        // The password re-check routes (transfers, card reveal, loan payments, change-password): a high
        // ceiling against scripts only. Wrong passwords are limited by PasswordLockout instead.
        RateLimiter::for('password-actions', fn (Request $request) => $this->perUserPerMinute($request, 60));
        // The public sign-up forms, per IP, both forms together.
        RateLimiter::for('registrations', fn (Request $request) => $this->limit(Limit::perMinute(5)->by($request->ip())));
        // The apply form re-quotes as the customer changes the terms, so it gets its own, looser limit.
        RateLimiter::for('customer-loan-quote', fn (Request $request) => $this->perUserPerMinute($request, 30));
    }

    private function perUserPerMinute(Request $request, int $attempts): Limit
    {
        return $this->limit(Limit::perMinute($attempts)->by((string) $request->user()?->getAuthIdentifier()));
    }

    /** $limit with the API's 429 envelope. */
    private function limit(Limit $limit): Limit
    {
        return $limit->response(fn (Request $request, array $headers) => ApiResponse::error(
            "Too many attempts. Try again in {$headers['Retry-After']} seconds.", 429, null, $headers,
        ));
    }
}
