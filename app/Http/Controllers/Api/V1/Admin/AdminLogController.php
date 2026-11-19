<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminLogIndexRequest;
use App\Http\Resources\AdminLogResource;
use App\Http\Resources\AdminPersonalLogResource;
use App\Http\Responses\ApiResponse;
use App\Models\AdminLog;
use App\Services\AdminLogQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminLogController extends Controller
{
    public function __construct(private AdminLogQuery $logs) {}

    public function index(AdminLogIndexRequest $request): JsonResponse
    {
        $page = $this->logs->paginate($request->user(), $request->validated());

        return ApiResponse::success([
            'records' => AdminLogResource::collection($page->getCollection())->resolve($request),
            'total' => $page->total(),
            'current' => $page->currentPage(),
            'size' => $page->perPage(),
        ]);
    }

    public function personal(Request $request): JsonResponse
    {
        $validated = $request->validate(['page' => ['sometimes', 'required', 'integer', 'min:1']]);
        $actor = $request->user();
        $page = $this->logs->paginatePersonal($actor, (int) ($validated['page'] ?? 1));

        return ApiResponse::success([
            'records' => AdminPersonalLogResource::collection($page->getCollection())->resolve($request),
            'total' => $page->total(),
            'current' => $page->currentPage(),
            'size' => $page->perPage(),
            'last_login_at' => $this->logs->lastLoginAt($actor),
        ])->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, AdminLog $log): JsonResponse
    {
        return ApiResponse::success(new AdminLogResource($this->logs->find($request->user(), $log)));
    }
}
