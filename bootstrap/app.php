<?php

use App\Http\Middleware\AuthenticateAdmin;
use App\Http\Middleware\RecordAdminLog;
use App\Http\Middleware\RequireAdminPermission;
use App\Http\Responses\ApiResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(
            fn (Request $request): ?string => $request->is('api/*') ? null : route('login'),
        );
        $middleware->trimStrings(except: ['password']);
        $middleware->api(prepend: [RecordAdminLog::class]);
        $middleware->alias([
            'admin.auth' => AuthenticateAdmin::class,
            'admin.permission' => RequireAdminPermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->respond(function (Response $response) {
            if (! request()->is('api/v1/admin/*') || $response->getStatusCode() < 400) {
                return $response;
            }

            $payload = json_decode($response->getContent(), true) ?? [];
            $status = $response->getStatusCode();
            $message = $status === 429 ? '请求过于频繁，请稍后重试' : ($payload['message'] ?? Response::$statusTexts[$status] ?? '请求失败');

            return ApiResponse::error($message, $status, $payload['errors'] ?? null)
                ->withHeaders(collect($response->headers->all())->except(['content-type', 'content-length'])->all());
        });
    })->create();
