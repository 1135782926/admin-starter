<?php

namespace App\Http\Requests;

use App\Models\AdminRole;
use App\Models\AdminUser;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminUserRequest extends FormRequest
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
        $admin = $this->route('adminUser');
        $updating = $admin instanceof AdminUser;
        $required = $updating ? 'sometimes' : 'required';
        $username = Rule::unique(AdminUser::class, 'username');
        if ($updating) {
            $username->ignore($admin);
        }

        return [
            'username' => [$required, 'required', 'string', 'max:255', $username],
            'nickname' => [$required, 'required', 'string', 'max:255'],
            'avatar' => ['sometimes', 'bail', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:2048'],
            'password' => $updating
                ? ['sometimes', 'nullable', 'string', 'min:8', 'max:255']
                : ['required', 'string', 'min:8', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'mobile' => ['sometimes', 'nullable', 'string', 'max:32'],
            'status' => ['sometimes', 'boolean'],
            'role_ids' => [$required, 'required', 'array', 'list', 'min:1'],
            'role_ids.*' => ['required', 'integer', 'distinct', Rule::exists(AdminRole::class, 'id')->where('guard_name', 'web')],
            'permissions' => ['missing'],
            'permission_ids' => ['missing'],
            'roles' => ['missing'],
            'guard_name' => ['missing'],
            'remember_token' => ['missing'],
            'tokens' => ['missing'],
        ];
    }
}
