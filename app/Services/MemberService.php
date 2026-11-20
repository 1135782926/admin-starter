<?php

namespace App\Services;

use App\Http\Requests\MemberRequest;
use App\Models\Member;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\QueryBuilder\AllowedFilter;

/** @extends BaseListService<Member> */
class MemberService extends BaseListService
{
    public function __construct(private AdminLogRecorder $logs) {}

    protected function listQuery(): Builder
    {
        return Member::query()->with('group:id,name');
    }

    protected function allowedFilters(): array
    {
        return [
            TableFilters::keyword('username', 'nickname', 'email', 'mobile'),
            AllowedFilter::exact('member_group_id'),
            AllowedFilter::exact('status'),
        ];
    }

    public function save(MemberRequest $request, Member $member): Member
    {
        $attributes = $request->validated();
        $updateAvatar = array_key_exists('avatar', $attributes);
        $avatar = $attributes['avatar'] ?? null;
        unset($attributes['avatar']);

        if (filled($attributes['password'] ?? null)) {
            $attributes['password'] = Hash::make($attributes['password']);
        } else {
            unset($attributes['password']);
        }

        if ($member->exists) {
            $member = Member::query()->lockForUpdate()->findOrFail($member->getKey());
        }

        try {
            $member->fill($attributes);
            if ($updateAvatar) {
                $this->updateAvatar($request, $member, $avatar);
            }
            $member->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['username' => '该用户名已被使用。']);
        }

        return $member->refresh()->load('group');
    }

    private function updateAvatar(MemberRequest $request, Member $member, ?UploadedFile $file): void
    {
        $previous = $member->avatar;
        $member->avatar = $file === null ? null : $this->storeAvatar($request, $file);

        if ($previous !== null && preg_match('~\A/storage/(member-avatars/[a-zA-Z0-9]{40}\.(?:jpe?g|png|webp|gif))\z~', $previous, $matches)) {
            DB::afterCommit(fn () => rescue(fn () => Storage::disk('public')->delete($matches[1])));
        }
    }

    private function storeAvatar(MemberRequest $request, UploadedFile $file): string
    {
        $path = $file->store('member-avatars', 'public');
        if ($path === false) {
            throw new RuntimeException('会员头像文件写入存储失败。');
        }

        $this->logs->onRollback($request, function () use ($path): void {
            if (! Storage::disk('public')->delete($path)) {
                throw new RuntimeException('会员头像事务回滚后的文件清理失败。');
            }
        });

        return '/storage/'.$path;
    }
}
