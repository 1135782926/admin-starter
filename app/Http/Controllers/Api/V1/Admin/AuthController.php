<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminLoginRequest;
use App\Http\Resources\AdminUserResource;
use App\Http\Responses\ApiResponse;
use App\Models\AdminUser;
use App\Notifications\AdminSessionStarted;
use App\Services\AdminLogRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    private static ?string $dummyPasswordHash = null;

    public function login(AdminLoginRequest $request): JsonResponse
    {
        self::$dummyPasswordHash ??= Hash::make('admin-login-timing-placeholder');
        $token = DB::transaction(function () use ($request): string {
            $admin = AdminUser::where('username', $request->validated('userName'))->lockForUpdate()->first();
            $password = $request->validated('password');
            $validPassword = Hash::check($password, $admin?->password ?? self::$dummyPasswordHash);
            abort_unless($admin && $validPassword && $admin->status, 401, '账号或密码错误，或账号不可用');

            if (Hash::needsRehash($admin->password)) {
                $admin->update(['password' => Hash::make($password)]);
            }

            $hasActiveSession = $admin->tokens()->where('name', 'admin')->where('expires_at', '>', now())->exists();
            $token = $admin->createToken('admin', ['*'], now()->addDays((int) config('auth.admin_token_days', 7)));
            app(AdminLogRecorder::class)->loginSucceeded($request, $admin);
            if ($hasActiveSession) {
                $admin->notify(new AdminSessionStarted);
            }

            return $token->plainTextToken;
        });

        return ApiResponse::success(['token' => $token])
            ->header('Cache-Control', 'no-store');
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success(new AdminUserResource($request->user()));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return ApiResponse::success();
    }
}
