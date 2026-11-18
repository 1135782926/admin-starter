<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminLogResource extends JsonResource
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
            'actor_id' => $this->actor_id,
            'actor_username' => $this->actor_username,
            'actor_nickname' => $this->actor_nickname,
            'attempted_username' => $this->attempted_username,
            'module' => $this->module,
            'action' => $this->action,
            'permission_name' => $this->permission_name,
            'route_name' => $this->route_name,
            'method' => $this->method,
            'path' => $this->path,
            'target_type' => $this->target_type,
            'target_id' => $this->target_id,
            'target_label' => $this->target_label,
            'status_code' => $this->status_code,
            'success' => $this->success,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'duration_ms' => $this->duration_ms,
            'created_at' => $this->created_at?->toDateTimeString(),
            'changes' => $this->when($request->routeIs('admin.logs.show'), fn () => $this->changes),
            'related_changes' => $this->when($request->routeIs('admin.logs.show'), fn () => $this->related_changes),
        ];
    }
}
