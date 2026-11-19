<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminProfileResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'username' => $this->username,
            'nickname' => $this->nickname,
            'avatar' => filled($this->avatar) ? url($this->avatar) : null,
            'email' => $this->email,
            'mobile' => $this->mobile,
            'signature' => $this->signature,
        ];
    }
}
