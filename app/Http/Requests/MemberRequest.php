<?php

namespace App\Http\Requests;

use App\Models\AdminUser;
use App\Models\Member;
use App\Models\MemberGroup;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MemberRequest extends FormRequest
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
        $member = $this->route('member');
        $updating = $member instanceof Member;
        $required = $updating ? ['sometimes', 'required'] : ['required'];
        $passwordPresence = $updating ? ['sometimes', 'nullable'] : ['required'];
        $username = Rule::unique(Member::class, 'username');
        if ($updating) {
            $username->ignore($member);
        }

        return [
            'username' => [...$required, 'bail', 'string', 'max:64', $username],
            'nickname' => [...$required, 'string', 'max:100'],
            'member_group_id' => [...$required, 'bail', 'integer', 'min:1', Rule::exists(MemberGroup::class, 'id')],
            'avatar' => ['sometimes', 'nullable', 'bail', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'extensions:jpg,jpeg,png,webp,gif', 'max:2048'],
            'password' => [
                ...$passwordPresence,
                'bail',
                'string',
                'min:8',
                static function (string $attribute, string $value, Closure $fail): void {
                    if (strlen($value) > 72) {
                        $fail('密码不得超过 72 字节。');
                    } elseif (str_contains($value, "\0")) {
                        $fail('密码不得包含空字节。');
                    }
                },
            ],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'mobile' => ['sometimes', 'nullable', 'string', 'max:32'],
            'gender' => ['sometimes', 'string', Rule::in(['unknown', 'male', 'female'])],
            'birthday' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'signature' => ['sometimes', 'nullable', 'string', 'max:500'],
            'status' => ['sometimes', 'boolean'],
        ];
    }
}
