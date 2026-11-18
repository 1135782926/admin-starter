<?php

namespace App\Services;

use App\Models\AdminUser;
use App\Models\Attachment;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentService
{
    public function __construct(private AdminLogRecorder $logs) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Attachment>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = Attachment::query()
            ->with(['uploader:id,username,nickname'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');
        $keyword = trim($filters['keyword'] ?? '');
        if ($keyword !== '') {
            $query->where(function (Builder $query) use ($keyword): void {
                $query->where('title', 'like', '%'.$keyword.'%')
                    ->orWhere('original_name', 'like', '%'.$keyword.'%')
                    ->orWhere('category', 'like', '%'.$keyword.'%');
            });
        }
        foreach (['category', 'visibility', 'admin_user_id'] as $filter) {
            if (isset($filters[$filter])) {
                $query->where($filter, $filters[$filter]);
            }
        }
        if (isset($filters['type'])) {
            match ($filters['type']) {
                'image' => $query->where('is_image', true),
                'document' => $query->whereIn('extension', ['pdf', 'doc', 'docx', 'xls', 'xlsx']),
                'archive' => $query->where('extension', 'zip'),
                'text' => $query->where('extension', 'txt'),
            };
        }
        foreach (['date_from' => '>=', 'date_to' => '<='] as $filter => $operator) {
            if (isset($filters[$filter])) {
                $datetime = CarbonImmutable::parse($filters[$filter])->setTimezone(config('app.timezone', 'UTC'));
                $query->where('created_at', $operator, $datetime->toDateTimeString());
            }
        }

        return $query->paginate($filters['per_page'] ?? 20, ['*'], 'page', $filters['page'] ?? 1);
    }

    /** @param array<string, mixed> $data */
    public function store(Request $request, UploadedFile $file, AdminUser $uploader, array $data, bool $forcePublic = false): Attachment
    {
        $extension = mb_strtolower($file->getClientOriginalExtension());
        $isImage = in_array($extension, config('attachments.image_extensions', []), true);
        $visibility = $forcePublic ? Attachment::PUBLIC : ($data['visibility'] ?? Attachment::PRIVATE);
        if (! $isImage && $visibility === Attachment::PUBLIC) {
            throw ValidationException::withMessages(['visibility' => '仅图片附件可以公开。']);
        }

        $path = $file->getRealPath();
        $mimeType = $file->getMimeType();
        $size = $file->getSize();
        $sha256 = $path === false ? false : hash_file('sha256', $path);
        if ($path === false || ! is_string($mimeType) || $size === false || ! is_string($sha256)) {
            throw new RuntimeException('无法读取上传文件的元数据。');
        }
        [$width, $height] = $this->imageDimensions($path, $isImage);

        $disk = $visibility === Attachment::PUBLIC ? 'public' : 'local';
        $objectKey = 'attachments/'.now()->format('Ym').'/'.Str::uuid()->toString().'.'.$extension;
        $stored = Storage::disk($disk)->putFileAs(dirname($objectKey), $file, basename($objectKey), ['visibility' => $visibility]);
        if ($stored === false) {
            throw new RuntimeException('附件文件写入存储失败。');
        }
        $this->logs->onRollback($request, function () use ($disk, $objectKey): void {
            $storage = Storage::disk($disk);
            if ($storage->exists($objectKey) && ! $storage->delete($objectKey)) {
                throw new RuntimeException('附件事务回滚后的文件清理失败。');
            }
        });

        $attachment = Attachment::create([
            'title' => $data['title'] ?? $file->getClientOriginalName(),
            'original_name' => $file->getClientOriginalName(),
            'category' => $data['category'] ?? 'default',
            'extension' => $extension,
            'mime_type' => $mimeType,
            'size' => $size,
            'sha256' => $sha256,
            'width' => $width,
            'height' => $height,
            'disk' => $disk,
            'object_key' => $objectKey,
            'visibility' => $visibility,
            'is_image' => $isImage,
            'admin_user_id' => $uploader->id,
        ]);
        $attachment->setRelation('uploader', $uploader);

        return $attachment;
    }

    /** @param array<string, mixed> $data */
    public function update(Attachment $attachment, array $data): Attachment
    {
        $attachment->update(Arr::only($data, ['title', 'category']));

        return $attachment->refresh()->load('uploader');
    }

    public function delete(Attachment $attachment): void
    {
        $storage = Storage::disk($attachment->disk);
        if ($storage->exists($attachment->object_key) && ! $storage->delete($attachment->object_key)) {
            throw new RuntimeException('附件文件删除失败，记录已保留。');
        }
        if (! $attachment->delete()) {
            throw new RuntimeException('附件记录删除失败。');
        }
    }

    /** @return array{url: string, expires_at: string} */
    public function temporaryUrl(Attachment $attachment, string $disposition): array
    {
        $expiresAt = now()->addSeconds((int) config('attachments.temporary_url_ttl'));

        return [
            'url' => URL::temporarySignedRoute('admin.attachments.file', $expiresAt, [
                'attachment' => $attachment->id,
                'disposition' => $disposition,
            ]),
            'expires_at' => $expiresAt->toDateTimeString(),
        ];
    }

    public function file(Attachment $attachment, string $disposition): StreamedResponse
    {
        $storage = Storage::disk($attachment->disk);
        abort_unless($storage->exists($attachment->object_key), 404, '附件文件不存在。');

        return $storage->response($attachment->object_key, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ], $disposition);
    }

    /** @return array{int|null, int|null} */
    private function imageDimensions(string $path, bool $isImage): array
    {
        if (! $isImage) {
            return [null, null];
        }
        $dimensions = getimagesize($path);
        if ($dimensions === false || $dimensions[0] < 1 || $dimensions[1] < 1) {
            throw ValidationException::withMessages(['file' => '无法识别图片内容或图片已损坏。']);
        }

        return [$dimensions[0], $dimensions[1]];
    }
}
