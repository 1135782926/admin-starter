<?php

namespace App\Http\Requests;

use App\Models\AdminUser;
use App\Models\Attachment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

class StoreAttachmentRequest extends FormRequest
{
    /** Authorization for the action is enforced by the admin route middleware. */
    public function authorize(): bool
    {
        return $this->user() instanceof AdminUser && $this->user()->status;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $mimeTypes = config('attachments.mime_types', []);
        $extensions = array_keys($mimeTypes);
        $allowedMimeTypes = array_values(array_unique(array_merge(...array_values($mimeTypes))));

        return [
            'file' => [
                'bail', 'required', 'file',
                'extensions:'.implode(',', $extensions),
                'mimetypes:'.implode(',', $allowedMimeTypes),
                'max:'.max(1, intdiv((int) config('attachments.max_size'), 1024)),
            ],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'category' => ['sometimes', 'nullable', 'string', 'max:100'],
            'visibility' => ['sometimes', 'string', Rule::in([Attachment::PRIVATE, Attachment::PUBLIC])],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('file')) {
                    return;
                }

                $file = $this->file('file');
                if (! $file instanceof UploadedFile) {
                    return;
                }

                $extension = mb_strtolower($file->getClientOriginalExtension());
                $allowedMimeTypes = config('attachments.mime_types', [])[$extension] ?? [];
                if (! in_array($file->getMimeType(), $allowedMimeTypes, true)) {
                    $validator->errors()->add('file', '文件扩展名与真实 MIME 类型不匹配。');
                }
                if ($file->getClientOriginalName() === '' || mb_strlen($file->getClientOriginalName()) > 255) {
                    $validator->errors()->add('file', '原始文件名长度必须为 1 至 255 个字符。');
                }
                if ($this->input('visibility') === Attachment::PUBLIC
                    && ! in_array($extension, config('attachments.image_extensions', []), true)) {
                    $validator->errors()->add('visibility', '仅图片附件可以公开。');
                }
            },
        ];
    }
}
