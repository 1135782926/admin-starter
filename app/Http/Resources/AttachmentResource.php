<?php

namespace App\Http\Resources;

use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class AttachmentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isPublicImage = $this->resource->visibility === Attachment::PUBLIC && $this->resource->is_image;

        return [
            'id' => $this->resource->id,
            'title' => $this->resource->title,
            'original_name' => $this->resource->original_name,
            'category' => $this->resource->category,
            'extension' => $this->resource->extension,
            'mime_type' => $this->resource->mime_type,
            'size' => $this->resource->size,
            'sha256' => $this->resource->sha256,
            'width' => $this->resource->width,
            'height' => $this->resource->height,
            'disk' => $this->resource->disk,
            'object_key' => $this->resource->object_key,
            'visibility' => $this->resource->visibility,
            'is_image' => $this->resource->is_image,
            'url' => $isPublicImage ? Storage::disk($this->resource->disk)->url($this->resource->object_key) : null,
            'uploader' => $this->whenLoaded('uploader', function (): ?array {
                return $this->resource->uploader ? [
                    'id' => $this->resource->uploader->id,
                    'username' => $this->resource->uploader->username,
                    'nickname' => $this->resource->uploader->nickname,
                ] : null;
            }),
            'created_at' => $this->resource->created_at?->toDateTimeString(),
            'updated_at' => $this->resource->updated_at?->toDateTimeString(),
        ];
    }
}
