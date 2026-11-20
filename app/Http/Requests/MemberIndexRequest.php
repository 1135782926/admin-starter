<?php

namespace App\Http\Requests;

use App\Models\AdminUser;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MemberIndexRequest extends FormRequest
{
    /** Authorization for the action is enforced by the admin route middleware. */
    public function authorize(): bool
    {
        return $this->user() instanceof AdminUser && $this->user()->status;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'keyword' => ['sometimes', 'nullable', 'string', 'max:255'],
            'member_group_id' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'integer', Rule::in([0, 1])],
        ];
    }
}
