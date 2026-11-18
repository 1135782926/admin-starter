<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminUserRequest;
use App\Http\Resources\AdminAccountResource;
use App\Models\AdminUser;
use App\Services\AdminUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminUserController extends Controller
{
    public function __construct(private AdminUserService $admins) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'keyword' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'boolean'],
        ]);
        $page = $this->admins->paginate($request->user(), $filters);

        return $this->success([
            'records' => AdminAccountResource::collection($page->getCollection())->resolve($request),
            'total' => $page->total(),
            'current' => $page->currentPage(),
            'size' => $page->perPage(),
        ]);
    }

    public function show(Request $request, AdminUser $adminUser): JsonResponse
    {
        return $this->success(new AdminAccountResource($this->admins->find($request->user(), $adminUser)));
    }

    public function store(AdminUserRequest $request): JsonResponse
    {
        return $this->success(new AdminAccountResource($this->admins->create($request->user(), $request->validated())), 201);
    }

    public function update(AdminUserRequest $request, AdminUser $adminUser): JsonResponse
    {
        return $this->success(new AdminAccountResource($this->admins->update($request->user(), $adminUser, $request->validated())));
    }

    public function destroy(Request $request, AdminUser $adminUser): JsonResponse
    {
        $request->validate(['confirmed' => ['required', 'accepted']]);
        $this->admins->delete($request->user(), $adminUser);

        return $this->success(null);
    }

    private function success(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json(['code' => 200, 'msg' => '操作成功', 'data' => $data], $status);
    }
}
