<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AttachmentIndexRequest;
use App\Http\Requests\StoreAttachmentRequest;
use App\Http\Requests\UpdateAttachmentRequest;
use App\Http\Resources\AttachmentResource;
use App\Http\Responses\ApiResponse;
use App\Models\Attachment;
use App\Services\AttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    public function __construct(private AttachmentService $attachments) {}

    public function index(AttachmentIndexRequest $request): JsonResponse
    {
        $page = $this->attachments->paginate($request->validated());

        return ApiResponse::success([
            'records' => AttachmentResource::collection($page->getCollection())->resolve($request),
            'total' => $page->total(),
            'current' => $page->currentPage(),
            'size' => $page->perPage(),
        ]);
    }

    public function store(StoreAttachmentRequest $request): JsonResponse
    {
        $attachment = $this->attachments->store($request, $request->file('file'), $request->user(), $request->validated());

        return ApiResponse::success(new AttachmentResource($attachment), 201);
    }

    public function show(Request $request, Attachment $attachment): JsonResponse
    {
        return ApiResponse::success(new AttachmentResource($attachment->load('uploader')));
    }

    public function update(UpdateAttachmentRequest $request, Attachment $attachment): JsonResponse
    {
        return ApiResponse::success(new AttachmentResource($this->attachments->update($attachment, $request->validated())));
    }

    public function destroy(Request $request, Attachment $attachment): JsonResponse
    {
        $request->validate(['confirmed' => ['required', 'accepted']]);
        $this->attachments->delete($attachment);

        return ApiResponse::success(null);
    }

    public function temporaryUrl(Request $request, Attachment $attachment): JsonResponse
    {
        $data = $request->validate([
            'disposition' => ['sometimes', 'string', Rule::in(['inline', 'attachment'])],
        ]);

        return ApiResponse::success($this->attachments->temporaryUrl($attachment, $data['disposition'] ?? 'inline'));
    }

    public function file(Request $request, Attachment $attachment): StreamedResponse
    {
        $data = $request->validate([
            'disposition' => ['sometimes', 'string', Rule::in(['inline', 'attachment'])],
        ]);

        return $this->attachments->file($attachment, $data['disposition'] ?? 'inline');
    }
}
