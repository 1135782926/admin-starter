<?php

namespace App\Http\Requests;

use App\Models\AdminUser;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAdminPasswordRequest extends FormRequest
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
            'current_password' => ['required', 'string'],
            'password' => [
                'bail', 'required', 'string', 'min:8', 'max:255', 'confirmed', 'different:current_password', 'not_regex:/\x00/',
                function (string $attribute, string $value, Closure $fail): void {
                    if (config('hashing.driver') !== 'bcrypt') {
                        return;
                    }

                    $maxBytes = min(72, (int) (config('hashing.bcrypt.limit') ?: 72));
                    if (strlen($value) > $maxBytes) {
                        $fail("新密码不能超过 {$maxBytes} 字节。");
                    }
                },
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'current_password.required' => '请输入当前密码。',
            'password.required' => '请输入新密码。',
            'password.min' => '新密码至少需要 8 个字符。',
            'password.max' => '新密码不能超过 255 个字符。',
            'password.confirmed' => '两次输入的新密码不一致。',
            'password.different' => '新密码不能与当前密码相同。',
            'password.not_regex' => '新密码不能包含空字节。',
        ];
    }
}
