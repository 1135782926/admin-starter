<?php

namespace App\Http\Resources;

use App\Models\AdminRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminAccountResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $roles = $this->resource->roles->map(fn (AdminRole $role): array => [
            'id' => $role->id,
            'name' => $role->name,
            'display_name' => $role->display_name ?: ($role->name === 'super-admin' ? '超级管理员组' : $role->name),
        ])->values();

        return [
            'id' => $this->id,
            'username' => $this->username,
            'nickname' => $this->nickname,
            'email' => $this->email,
            'mobile' => $this->mobile,
            'status' => (bool) $this->status,
            'role_ids' => $roles->pluck('id')->all(),
            'roles' => $roles->all(),
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
            'can_manage' => true,
        ];
    }
}
