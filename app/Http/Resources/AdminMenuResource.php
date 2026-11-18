<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminMenuResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'type' => $this->type,
            'title' => $this->title,
            'name' => $this->name,
            'permission_id' => $this->permission_id,
            'permission_name' => $this->permission?->name,
            'open_type' => $this->open_type,
            'path' => $this->path,
            'component' => $this->component,
            'url' => $this->url,
            'icon' => $this->icon,
            'sort' => $this->sort,
            'keep_alive' => $this->keep_alive,
            'status' => $this->status,
            'remark' => $this->remark,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
