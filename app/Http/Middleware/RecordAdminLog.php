<?php

namespace App\Http\Middleware;

use App\Services\AdminLogRecorder;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class RecordAdminLog
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $recorder = app(AdminLogRecorder::class);
        if (! $recorder->prepare($request)) {
            return $next($request);
        }
        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $status = match (true) {
                $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
                $exception instanceof ValidationException => $exception->status,
                $exception instanceof AuthenticationException => 401,
                $exception instanceof AuthorizationException => 403,
                default => 500,
            };
            $recorder->finish($request, $status);
            throw $exception;
        }
        $recorder->finish($request, $response->getStatusCode());

        return $response;
    }
}
