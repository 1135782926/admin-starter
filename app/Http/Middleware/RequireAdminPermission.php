<?php

namespace App\Http\Middleware;

use App\Services\AdminLogRecorder;
use App\Services\AdminMenuAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAdminPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        abort_unless(app(AdminMenuAccess::class)->allows($request->user(), $permission), 403, '无权访问或菜单分支已禁用。');

        $recorder = app(AdminLogRecorder::class);
        if ($recorder->isBusinessWrite($request)) {
            return $recorder->transaction($request, function () use ($request, $next, $permission, $recorder): Response {
                abort_unless(app(AdminMenuAccess::class)->allows($request->user(), $permission), 403, '无权访问或菜单分支已禁用。');
                $recorder->captureBefore($request);

                return $next($request);
            });
        }

        return $next($request);
    }
}
