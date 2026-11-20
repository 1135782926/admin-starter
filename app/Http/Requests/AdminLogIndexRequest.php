<?php

namespace App\Http\Requests;

use App\Models\AdminUser;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminLogIndexRequest extends FormRequest
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
        $datetime = ['sometimes', 'nullable', 'bail', 'string', 'date', 'regex:/^\d{4}-\d{2}-\d{2}[Tt ]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:[Zz]|[+-](?:0\d|1[0-4]):?[0-5]\d)$/D'];

        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'keyword' => ['sometimes', 'nullable', 'string', 'max:255'],
            'actor_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'module' => ['sometimes', 'nullable', Rule::in(['auth', 'admin', 'role', 'menu', 'attachment', 'profile', 'member', 'member_group'])],
            'action' => ['sometimes', 'nullable', Rule::in(['login', 'logout', 'create', 'update', 'delete', 'change_password'])],
            'success' => ['sometimes', 'boolean'],
            'status_code' => ['sometimes', 'nullable', 'integer', 'between:100,599'],
            'ip_address' => ['sometimes', 'nullable', 'ip'],
            'date_from' => $datetime,
            'date_to' => [...$datetime, Rule::when($this->filled('date_from'), ['after_or_equal:date_from'])],
        ];
    }
}
