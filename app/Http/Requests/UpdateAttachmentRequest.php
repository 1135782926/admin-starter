<?php

namespace App\Http\Requests;

use App\Models\AdminUser;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAttachmentRequest extends FormRequest
{
    /** Authorization for the action is enforced by the admin route middleware. */
    public function authorize(): bool
    {
        return $this->user() instanceof AdminUser && $this->user()->status;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'category' => ['sometimes', 'nullable', 'string', 'max:100'],
            'file' => ['missing'],
            'original_name' => ['missing'],
            'extension' => ['missing'],
            'mime_type' => ['missing'],
            'size' => ['missing'],
            'sha256' => ['missing'],
            'width' => ['missing'],
            'height' => ['missing'],
            'disk' => ['missing'],
            'object_key' => ['missing'],
            'visibility' => ['missing'],
            'is_image' => ['missing'],
            'admin_user_id' => ['missing'],
        ];
    }
}
