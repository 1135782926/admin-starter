<?php

namespace App\Http\Resources;

use App\Services\AdminMenuAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminUserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'userId' => $this->id,
            'userName' => $this->username,
            'email' => $this->email ?? '',
            'avatar' => $this->when(filled($this->avatar), fn () => url($this->avatar)),
            'roles' => $this->resource->getRoleNames()->values()->all(),
            'buttons' => app(AdminMenuAccess::class)->buttons($this->resource),
        ];
    }
}
