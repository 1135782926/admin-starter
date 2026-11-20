<?php

namespace App\Http\Requests;

use App\Models\AdminMenu;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AdminMenuRequest extends FormRequest
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
        $menu = $this->route('menu');
        $updating = $menu instanceof AdminMenu;
        $type = $updating ? $menu->type : $this->input('type');
        $openType = $this->input('open_type', $updating ? $menu->open_type : null);
        $required = $updating ? 'sometimes' : 'required';

        return [
            'type' => $updating ? ['missing'] : ['required', Rule::in(['directory', 'menu', 'button'])],
            'name' => $updating ? ['missing'] : ['required', 'string', 'max:248', 'regex:/^[a-zA-Z0-9_-]+(?:\.[a-zA-Z0-9_-]+)*$/', Rule::unique('admin_menus')],
            'title' => [$required, 'required', 'string', 'max:255'],
            'parent_id' => ['sometimes', 'nullable', 'integer', Rule::exists('admin_menus', 'id')],
            'permission_id' => ['missing'],
            'permission_name' => ['missing'],
            'open_type' => $type === 'menu' ? [$required, 'required', Rule::in(['page', 'external', 'iframe'])] : ['prohibited'],
            'path' => $type !== 'button' ? [$required, 'required', 'string', 'max:255', 'regex:/^\/(?!\/)[^?#\s]*$/'] : ['prohibited'],
            'component' => $type === 'menu' && $openType === 'page' ? [$required, 'required', 'string', 'max:255', 'regex:/^\/(?!\/)(?!src\/views\/)(?!.*\.\.)[^?#\s]+$/'] : ['prohibited'],
            'url' => $type === 'menu' && in_array($openType, ['external', 'iframe'], true) ? [$required, 'required', 'url:http,https', 'max:2048'] : ['prohibited'],
            'icon' => $type !== 'button' ? ['sometimes', 'nullable', 'string', 'max:255'] : ['prohibited'],
            'sort' => ['sometimes', 'integer', 'between:-2147483648,2147483647'],
            'placement' => $updating && $type !== 'button'
                ? ['sometimes', 'array:target_id,position', 'required_array_keys:target_id,position']
                : ['missing'],
            'placement.target_id' => ['required_with:placement', 'integer', 'min:1'],
            'placement.position' => ['required_with:placement', Rule::in(['before', 'after'])],
            'status' => ['sometimes', 'boolean'],
            'keep_alive' => $type === 'menu' && $openType === 'page' ? ['sometimes', 'boolean'] : ['prohibited'],
            'remark' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'buttons' => ! $updating && $type === 'menu' && $openType === 'page' ? ['sometimes', 'array', 'list'] : ['missing'],
            'buttons.*' => ['string', 'distinct', Rule::in(['view', 'create', 'update', 'delete'])],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $menu = $this->route('menu');
            if (! $menu instanceof AdminMenu || $validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->has('placement') && $this->hasAny(['parent_id', 'sort'])) {
                $validator->errors()->add('placement', '拖动位置不能与上级节点或排序值同时提交。');

                return;
            }

            $data = array_replace($menu->getAttributes(), $this->validated());
            if ($data['type'] === 'menu') {
                $field = $data['open_type'] === 'page' ? 'component' : 'url';
                if (blank($data[$field] ?? null)) {
                    $validator->errors()->add($field, '此打开方式需要该字段。');
                }
                $forbidden = $field === 'component' ? ['url'] : ['component'];
                foreach ($forbidden as $key) {
                    if (filled($data[$key] ?? null)) {
                        $validator->errors()->add($key, '此打开方式不允许该字段，请显式清空。');
                    }
                }
                if ($field === 'url' && ($data['keep_alive'] ?? false)) {
                    $validator->errors()->add('keep_alive', '外链和 Iframe 不支持页面缓存。');
                }
            }
        }];
    }
}
