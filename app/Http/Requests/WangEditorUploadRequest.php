<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\UploadedFile;

class WangEditorUploadRequest extends StoreAttachmentRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [...parent::rules(), 'visibility' => ['missing']];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [
            ...parent::after(),
            function (Validator $validator): void {
                if ($validator->errors()->has('file')) {
                    return;
                }
                $file = $this->file('file');
                if ($file instanceof UploadedFile
                    && ! in_array(mb_strtolower($file->getClientOriginalExtension()), config('attachments.image_extensions', []), true)) {
                    $validator->errors()->add('file', '富文本上传仅支持图片。');
                }
            },
        ];
    }
}
