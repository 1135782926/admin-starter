<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\MemberGroupRequest;
use App\Http\Responses\ApiResponse;
use App\Models\MemberGroup;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class MemberGroupController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success(MemberGroup::query()->orderBy('id')->get(['id', 'name']));
    }

    public function store(MemberGroupRequest $request): JsonResponse
    {
        return $this->save($request, new MemberGroup, 201);
    }

    public function update(MemberGroupRequest $request, MemberGroup $memberGroup): JsonResponse
    {
        return $this->save($request, $memberGroup, 200);
    }

    private function save(MemberGroupRequest $request, MemberGroup $group, int $status): JsonResponse
    {
        try {
            $group->fill($request->validated())->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => '该分组名称已被使用。']);
        }

        return ApiResponse::success($group->only(['id', 'name']), $status);
    }
}
