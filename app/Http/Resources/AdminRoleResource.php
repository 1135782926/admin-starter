<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminRoleResource extends JsonResource
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
            'name' => $this->name,
            'display_name' => $this->display_name ?: ($this->name === 'super-admin' ? '超级管理员组' : $this->name),
            'parent_id' => $this->parent_id,
            'status' => $this->status,
            'effective_status' => $this->effective_status,
            'remark' => $this->remark,
            'permission_ids' => $this->permission_ids,
            'permissions_count' => count($this->permission_ids),
            'admins_count' => (int) $this->admins_count,
            'can_manage' => $this->can_manage,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
