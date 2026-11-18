<?php

namespace App\Http\Middleware;

use App\Models\AdminUser;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('admin');
        $guard->forgetUser();
        $admin = $guard->user();

        if (! $request->bearerToken() || ! $admin instanceof AdminUser || ! $admin->status || ! $admin->currentAccessToken() instanceof PersonalAccessToken) {
            throw new AuthenticationException;
        }

        $request->setUserResolver(fn () => $admin);
        $request->attributes->set('admin.authenticated', true);

        return $next($request);
    }
}
