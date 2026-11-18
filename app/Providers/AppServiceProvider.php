<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
        RateLimiter::for('admin-login', fn (Request $request): array => [
            Limit::perMinute(5)->by('account:'.hash('sha256', mb_strtolower(trim(is_string($request->input('userName')) ? $request->input('userName') : '')).'|'.$request->ip())),
            Limit::perMinute(60)->by('ip:'.$request->ip()),
        ]);
    }
}
