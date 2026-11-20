<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\MemberIndexRequest;
use App\Http\Requests\MemberRequest;
use App\Http\Resources\MemberResource;
use App\Http\Responses\ApiResponse;
use App\Models\Member;
use App\Services\MemberService;
use Illuminate\Http\JsonResponse;

class MemberController extends Controller
{
    public function __construct(private MemberService $members) {}

    public function index(MemberIndexRequest $request): JsonResponse
    {
        $page = $this->members->paginate($request->validated());

        return ApiResponse::success([
            'records' => MemberResource::collection($page->getCollection())->resolve($request),
            'total' => $page->total(),
            'current' => $page->currentPage(),
            'size' => $page->perPage(),
        ]);
    }

    public function show(Member $member): JsonResponse
    {
        return ApiResponse::success(new MemberResource($member->load('group')));
    }

    public function store(MemberRequest $request): JsonResponse
    {
        return ApiResponse::success(new MemberResource($this->members->save($request, new Member)), 201);
    }

    public function update(MemberRequest $request, Member $member): JsonResponse
    {
        return ApiResponse::success(new MemberResource($this->members->save($request, $member)));
    }
}
