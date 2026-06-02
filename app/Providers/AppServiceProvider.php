<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use App\Mail\BrevoTransport;
use Illuminate\Support\Facades\Mail;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureRateLimiting();

        Mail::extend('brevo', function () {
            return new BrevoTransport(config('services.brevo.key'));
        });
    }

    protected function configureRateLimiting(): void
    {
        // Global API rate limit: 60 requests per minute per user/IP
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by(
                $request->user()?->id ?: $request->ip()
            );
        });

        // Stricter limit for auth endpoints
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // Very strict for OTP/sensitive endpoints
        RateLimiter::for('otp', function (Request $request) {
            return Limit::perMinute(15)->by(
                $request->user()?->id ?: $request->ip()
            );
        });
    }
}