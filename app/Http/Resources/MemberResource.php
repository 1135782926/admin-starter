<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MemberResource extends JsonResource
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
            'username' => $this->username,
            'nickname' => $this->nickname,
            'member_group_id' => $this->member_group_id,
            'group' => $this->group->only(['id', 'name']),
            'avatar' => $this->avatar === null ? null : url($this->avatar),
            'email' => $this->email,
            'mobile' => $this->mobile,
            'gender' => $this->gender,
            'birthday' => $this->birthday?->toDateString(),
            'signature' => $this->signature,
            'status' => $this->status,
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
