<?php

namespace App\Services;

use App\Models\AdminRole;
use App\Models\AdminUser;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

class AdminUserService
{
    public function __construct(
        private AdminManagementScope $scope,
        private AdminMenuAccess $access,
        private AdminLogRecorder $logs,
    ) {}

    /** @param array<string, mixed> $filters */
    public function paginate(AdminUser $actor, array $filters): LengthAwarePaginator
    {
        $query = $this->scope->adminQuery($actor)->with('roles')->orderByDesc('id');
        $keyword = trim($filters['keyword'] ?? '');
        if ($keyword !== '') {
            $query->where(function (Builder $query) use ($keyword): void {
                $query->where('username', 'like', '%'.$keyword.'%')
                    ->orWhere('nickname', 'like', '%'.$keyword.'%')
                    ->orWhere('email', 'like', '%'.$keyword.'%')
                    ->orWhere('mobile', 'like', '%'.$keyword.'%');
            });
        }
        if (array_key_exists('status', $filters)) {
            $query->where('status', $filters['status']);
        }

        return $query->paginate($filters['per_page'] ?? 20, ['*'], 'page', $filters['page'] ?? 1);
    }

    public function find(AdminUser $actor, AdminUser $admin): AdminUser
    {
        return $this->scope->adminQuery($actor)->with('roles')->whereKey($admin->id)->firstOrFail();
    }

    private function managedAdmin(AdminUser $actor, AdminUser $admin): AdminUser
    {
        $target = $this->scope->adminQuery($actor)->with('roles')->whereKey($admin->id)->first();
        abort_unless($target, 403, '该管理员不在当前管理范围内。');

        return $target;
    }

    /** @param array<string, mixed> $data */
    public function create(Request $request, array $data): AdminUser
    {
        try {
            return DB::transaction(function () use ($request, $data): AdminUser {
                $admins = $this->lockManagementRows();
                $actor = $this->authorizedActor($admins, $request->user(), 'create');
                $roles = $this->assignmentRoles($actor, $data['role_ids']);
                $this->validateUsername($data['username']);
                $admin = new AdminUser;
                $admin->fill(Arr::only($data, ['username', 'nickname', 'email', 'mobile', 'password', 'status']));
                if (isset($data['avatar'])) {
                    $this->storeAvatar($request, $admin, $data['avatar']);
                }
                $admin->save();
                $admin->syncRoles($roles);
                $this->invalidatePermissionsAfterCommit();

                return $admin->load('roles');
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['username' => '该用户名已被使用。']);
        }
    }

    /** @param array<string, mixed> $data */
    public function update(Request $request, AdminUser $admin, array $data): AdminUser
    {
        try {
            return DB::transaction(function () use ($request, $admin, $data): AdminUser {
                $admins = $this->lockManagementRows();
                $actor = $this->authorizedActor($admins, $request->user(), 'update');
                if ($actor->is($admin)) {
                    $actor->load('roles');
                    $ownRoleIds = array_key_exists('role_ids', $data) ? array_map('intval', $data['role_ids']) : $actor->roles->modelKeys();
                    $this->protectSelf($actor, $actor, (bool) ($data['status'] ?? $actor->status), $ownRoleIds);
                }
                $admin = $this->managedAdmin($actor, $admin);
                $roles = array_key_exists('role_ids', $data) ? $this->assignmentRoles($actor, $data['role_ids']) : null;
                $roleIds = $roles?->modelKeys() ?? $admin->roles->modelKeys();
                $enabled = (bool) ($data['status'] ?? $admin->status);
                $this->protectLastSuper($admin, $enabled, $roleIds, $admins);
                if (array_key_exists('username', $data)) {
                    $this->validateUsername($data['username'], $admin);
                }
                $attributes = Arr::only($data, ['username', 'nickname', 'email', 'mobile', 'password', 'status']);
                $passwordChanged = filled($attributes['password'] ?? null);
                if (! $passwordChanged) {
                    unset($attributes['password']);
                }
                $admin->fill($attributes);
                if (isset($data['avatar'])) {
                    $this->storeAvatar($request, $admin, $data['avatar']);
                }
                $admin->save();
                if ($roles !== null) {
                    $admin->syncRoles($roles);
                }
                if ($passwordChanged) {
                    $admin->tokens()->delete();
                }
                $this->invalidatePermissionsAfterCommit();

                return $admin->load('roles');
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['username' => '该用户名已被使用。']);
        }
    }

    /** @param array{nickname?: string, avatar?: UploadedFile, email?: ?string, mobile?: ?string, signature?: ?string} $data */
    public function updateProfile(Request $request, array $data): AdminUser
    {
        $admin = $request->user();
        $admin->fill(Arr::only($data, ['nickname', 'email', 'mobile', 'signature']));
        if (isset($data['avatar'])) {
            $this->storeAvatar($request, $admin, $data['avatar']);
        }
        $admin->save();

        return $admin;
    }

    public function delete(AdminUser $actor, AdminUser $admin): void
    {
        DB::transaction(function () use ($actor, $admin): void {
            $admins = $this->lockManagementRows();
            $actor = $this->authorizedActor($admins, $actor, 'delete');
            abort_if($actor->is($admin), 409, '不能删除当前管理员。');
            $admin = $this->managedAdmin($actor, $admin);
            $this->protectLastSuper($admin, false, [], $admins);
            $admin->tokens()->delete();
            $admin->syncRoles([]);
            $admin->syncPermissions([]);
            $admin->delete();
            $this->deleteAvatarAfterCommit($admin->avatar);
            $this->invalidatePermissionsAfterCommit();
        });
    }

    private function storeAvatar(Request $request, AdminUser $admin, UploadedFile $file): void
    {
        $path = $file->store('admin-avatars', 'public');
        if ($path === false) {
            throw new RuntimeException('头像文件写入存储失败。');
        }

        $this->logs->onRollback($request, function () use ($path): void {
            if (! Storage::disk('public')->delete($path)) {
                throw new RuntimeException('头像事务回滚后的文件清理失败。');
            }
        });
        $this->deleteAvatarAfterCommit($admin->avatar);
        $admin->avatar = '/storage/'.$path;
    }

    private function deleteAvatarAfterCommit(?string $avatar): void
    {
        if ($avatar !== null && preg_match('~\A/storage/(admin-avatars/[a-zA-Z0-9]{40}\.(?:jpe?g|png|webp|gif))\z~', $avatar, $matches)) {
            DB::afterCommit(fn () => Storage::disk('public')->delete($matches[1]));
        }
    }

    /** @return Collection<int, AdminUser> */
    private function lockManagementRows(): Collection
    {
        AdminRole::query()->where('guard_name', 'web')->orderBy('id')->lockForUpdate()->get();

        return AdminUser::query()->orderBy('id')->lockForUpdate()->get();
    }

    /** @param Collection<int, AdminUser> $admins */
    private function authorizedActor(Collection $admins, AdminUser $actor, string $action): AdminUser
    {
        $actor = $admins->find($actor->id);
        abort_unless($actor && $actor->status && $this->access->allows($actor, 'system.admin.'.$action), 403, '无权管理管理员。');

        return $actor;
    }

    /**
     * @param  array<int, int|string>  $roleIds
     * @return Collection<int, AdminRole>
     */
    private function assignmentRoles(AdminUser $actor, array $roleIds): Collection
    {
        $roleIds = array_map('intval', $roleIds);
        $assignable = $this->scope->assignableRoleIds($actor);
        abort_if($roleIds === [] || array_diff($roleIds, $assignable) !== [], 403, '只能分配当前管理范围内的角色组。');
        $roles = AdminRole::query()->where('guard_name', 'web')->whereIn('id', $roleIds)->orderBy('id')->get();
        if ($roles->count() !== count(array_unique($roleIds))) {
            throw ValidationException::withMessages(['role_ids' => '角色组不存在或已被删除。']);
        }

        return $roles;
    }

    private function validateUsername(string $username, ?AdminUser $admin = null): void
    {
        $query = AdminUser::query()->where('username', $username);
        if ($admin !== null) {
            $query->whereKeyNot($admin->id);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages(['username' => '该用户名已被使用。']);
        }
    }

    /** @param array<int, int> $roleIds */
    private function protectSelf(AdminUser $actor, AdminUser $admin, bool $enabled, array $roleIds): void
    {
        if ($actor->is($admin)) {
            abort_if(! $enabled || array_diff($admin->roles->modelKeys(), $roleIds) !== [], 409, '不能禁用自己或移除自己的角色组。');
        }
    }

    /**
     * @param  array<int, int>  $roleIds
     * @param  Collection<int, AdminUser>  $admins
     */
    private function protectLastSuper(AdminUser $admin, bool $enabled, array $roleIds, Collection $admins): void
    {
        if (! $this->scope->isSuper($admin)) {
            return;
        }
        $superRole = AdminRole::query()->where('guard_name', 'web')->where('name', 'super-admin')->first();
        if ($enabled && $superRole && in_array($superRole->id, $roleIds, true)) {
            return;
        }
        $others = AdminUser::query()->where('status', true)->whereKeyNot($admin->id)
            ->whereHas('roles', fn (Builder $query) => $query->where('guard_name', 'web')->where('name', 'super-admin'))
            ->whereKey($admins->modelKeys())->get();
        abort_unless($others->contains(fn (AdminUser $other): bool => $this->scope->isSuper($other)), 409, '必须保留至少一位启用的超级管理员。');
    }

    private function invalidatePermissionsAfterCommit(): void
    {
        DB::afterCommit(fn () => app(PermissionRegistrar::class)->forgetCachedPermissions());
    }
}
