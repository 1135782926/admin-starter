<?php

namespace App\Http\Controllers\Api\Common;

use App\Http\Controllers\Controller;
use App\Http\Requests\WangEditorUploadRequest;
use App\Services\AttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class WangEditorUploadController extends Controller
{
    public function __construct(private AttachmentService $attachments) {}

    public function __invoke(WangEditorUploadRequest $request): JsonResponse
    {
        $attachment = $this->attachments->store(
            $request,
            $request->file('file'),
            $request->user(),
            $request->validated(),
            forcePublic: true,
        );
        $url = Storage::disk($attachment->disk)->url($attachment->object_key);

        return response()->json([
            'errno' => 0,
            'data' => [
                'id' => $attachment->id,
                'url' => $url,
                'alt' => $attachment->title,
                'href' => $url,
            ],
        ]);
    }
}
