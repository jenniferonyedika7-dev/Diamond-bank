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

        // Customer transfers, per user: every attempt counts, including wrong passwords.
        RateLimiter::for('customer-lookup', fn (Request $request) => $this->perUserPerMinute($request, 10));
        RateLimiter::for('customer-transfers', fn (Request $request) => $this->perUserPerMinute($request, 5));
    }

    private function perUserPerMinute(Request $request, int $attempts): Limit
    {
        return Limit::perMinute($attempts)
            ->by((string) $request->user()?->getAuthIdentifier())
            ->response(fn (Request $request, array $headers) => ApiResponse::error(
                "Too many attempts. Try again in {$headers['Retry-After']} seconds.", 429, null, $headers,
            ));
    }
}
