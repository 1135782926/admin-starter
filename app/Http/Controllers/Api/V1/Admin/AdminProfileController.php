<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateAdminPasswordRequest;
use App\Http\Requests\UpdateAdminProfileRequest;
use App\Http\Resources\AdminProfileResource;
use App\Http\Responses\ApiResponse;
use App\Services\AdminLogRecorder;
use App\Services\AdminUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AdminProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success(new AdminProfileResource($request->user()))
            ->header('Cache-Control', 'no-store');
    }

    public function update(UpdateAdminProfileRequest $request, AdminUserService $users, AdminLogRecorder $logs): JsonResponse
    {
        return $logs->transaction($request, function () use ($request, $users, $logs): JsonResponse {
            $logs->captureBefore($request);
            $admin = $users->updateProfile($request, $request->validated());

            return ApiResponse::success(new AdminProfileResource($admin));
        });
    }

    public function changePassword(UpdateAdminPasswordRequest $request, AdminLogRecorder $logs): JsonResponse
    {
        return $logs->transaction($request, function () use ($request): JsonResponse {
            $admin = $request->user();
            if (! Hash::check($request->validated('current_password'), $admin->password)) {
                throw ValidationException::withMessages(['current_password' => '当前密码不正确。']);
            }

            $admin->update(['password' => Hash::make($request->validated('password'))]);
            $admin->tokens()->delete();

            return ApiResponse::success();
        });
    }
}
