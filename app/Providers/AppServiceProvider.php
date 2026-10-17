<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $email = Str::lower((string) $request->input('email'));

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });

        RateLimiter::for('register', fn (Request $request) => Limit::perMinute(
            config('pulsefeed.rate_limits.register_per_minute')
        )->by($request->ip()));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(
            config('pulsefeed.rate_limits.reads_per_minute')
        )->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('writes', fn (Request $request) => Limit::perMinute(
            config('pulsefeed.rate_limits.writes_per_minute')
        )->by($request->user()?->id ?: $request->ip()));
    }
}
