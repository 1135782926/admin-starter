<?php

namespace App\Http\Requests;

use App\Models\AdminRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminRoleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $updating = $this->route('role') instanceof AdminRole;
        $required = $updating ? 'sometimes' : 'required';

        return [
            'name' => $updating ? ['missing'] : ['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9_-]+(?:\.[a-zA-Z0-9_-]+)*$/', Rule::notIn(['super-admin']), Rule::unique('admin_roles')->where('guard_name', 'web')],
            'display_name' => [$required, 'required', 'string', 'max:255'],
            'parent_id' => ['sometimes', 'nullable', 'integer', Rule::exists('admin_roles', 'id')->where('guard_name', 'web')],
            'status' => ['sometimes', 'boolean'],
            'remark' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'permission_ids' => [$updating ? 'sometimes' : 'present', 'array', 'list'],
            'permission_ids.*' => ['integer', 'distinct', Rule::exists('admin_permissions', 'id')->where('guard_name', 'web')],
            'guard_name' => ['missing'],
        ];
    }
}
