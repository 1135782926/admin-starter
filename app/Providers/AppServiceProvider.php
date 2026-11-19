<?php

namespace App\Providers;

use App\Models\AdminUser;
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
        require base_path('routes/channels.php');

        RateLimiter::for('admin-login', fn (Request $request): array => [
            Limit::perMinute(5)->by('account:'.hash('sha256', mb_strtolower(trim(is_string($request->input('userName')) ? $request->input('userName') : '')).'|'.$request->ip())),
            Limit::perMinute(60)->by('ip:'.$request->ip()),
        ]);
        RateLimiter::for('admin-password', fn (Request $request): array => [
            Limit::perMinute(5)->by('token:'.hash('sha256', $request->bearerToken() ?? '')),
            Limit::perMinute(60)->by('ip:'.$request->ip()),
        ]);
        RateLimiter::for('attachment-upload', function (Request $request): Limit {
            $actor = $request->user();

            return Limit::perMinute((int) config('attachments.uploads_per_minute', 30))
                ->by($actor instanceof AdminUser ? 'admin:'.$actor->id : 'ip:'.$request->ip());
        });
    }
}
