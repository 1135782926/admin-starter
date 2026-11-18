<?php

namespace App\Http\Requests;

use App\Models\AdminUser;
use App\Models\Attachment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttachmentIndexRequest extends FormRequest
{
    /** Authorization for the action is enforced by the admin route middleware. */
    public function authorize(): bool
    {
        return $this->user() instanceof AdminUser && $this->user()->status;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $datetime = ['sometimes', 'nullable', 'bail', 'string', 'date', 'regex:/^\d{4}-\d{2}-\d{2}[Tt ]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:[Zz]|[+-](?:0\d|1[0-4]):?[0-5]\d)$/D'];

        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'keyword' => ['sometimes', 'nullable', 'string', 'max:255'],
            'category' => ['sometimes', 'nullable', 'string', 'max:100'],
            'type' => ['sometimes', 'nullable', Rule::in(['image', 'document', 'archive', 'text'])],
            'visibility' => ['sometimes', 'nullable', Rule::in([Attachment::PRIVATE, Attachment::PUBLIC])],
            'admin_user_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'date_from' => $datetime,
            'date_to' => [...$datetime, Rule::when($this->filled('date_from'), ['after_or_equal:date_from'])],
        ];
    }
}
