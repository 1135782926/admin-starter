<?php

namespace App\Http\Requests;

use App\Models\AdminUser;
use App\Models\MemberGroup;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MemberGroupRequest extends FormRequest
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
        $memberGroup = $this->route('memberGroup');
        $name = Rule::unique(MemberGroup::class, 'name');
        if ($memberGroup instanceof MemberGroup) {
            $name->ignore($memberGroup);
        }

        return [
            'name' => ['required', 'bail', 'string', 'max:100', $name],
        ];
    }
}
