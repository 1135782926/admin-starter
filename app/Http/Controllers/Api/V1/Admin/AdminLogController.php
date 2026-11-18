<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminLogIndexRequest;
use App\Http\Resources\AdminLogResource;
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

    public function show(Request $request, AdminLog $log): JsonResponse
    {
        return ApiResponse::success(new AdminLogResource($this->logs->find($request->user(), $log)));
    }
}
