<?php

namespace App\Services;

use App\Models\AdminMenu;
use App\Models\AdminRole;
use App\Models\AdminUser;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\RoleAlreadyExists;
use Spatie\Permission\PermissionRegistrar;

class AdminRoleService
{
    public function __construct(private AdminManagementScope $scope) {}

    /** @param array<string, mixed> $data */
    public function create(AdminUser $actor, array $data): AdminRole
    {
        try {
            return DB::transaction(function () use ($actor, $data): AdminRole {
                $nodes = $this->lockStructure();
                $actor = $this->authorizedActor($actor, 'create');
                $parent = $this->validateParent($actor, $data['parent_id'] ?? null, null, $nodes);
                $ids = $this->normalizePermissions($data['permission_ids'] ?? []);
                $this->validateAllocation($actor, $parent, $ids, $nodes);
                unset($data['permission_ids']);
                $role = AdminRole::create(['guard_name' => 'web', ...$data]);
                $role->syncPermissions($ids);
                $this->invalidateAfterCommit();

                return $role->refresh()->load('permissions');
            });
        } catch (UniqueConstraintViolationException|RoleAlreadyExists) {
            throw ValidationException::withMessages(['name' => '角色组编码已存在。']);
        }
    }

    /** @param array<string, mixed> $data */
    public function update(AdminUser $actor, AdminRole $role, array $data): AdminRole
    {
        return DB::transaction(function () use ($actor, $role, $data): AdminRole {
            $nodes = $this->lockStructure();
            $actor = $this->authorizedActor($actor, 'update');
            $role = $nodes->get($role->id);
            abort_unless($role, 404);
            abort_if($role->name === 'super-admin', 409, '超级管理员组是保留角色，不允许修改。');
            abort_unless($this->scope->canManageRole($actor, $role), 403, '只能管理授权范围内的下级角色组。');
            $parent = $this->validateParent($actor, $data['parent_id'] ?? (array_key_exists('parent_id', $data) ? null : $role->parent_id), $role, $nodes);
            $old = $role->permissions->pluck('id')->all();
            $ids = array_key_exists('permission_ids', $data) ? $this->normalizePermissions($data['permission_ids'], $old) : $old;
            $this->validateAllocation($actor, $parent, $ids, $nodes);
            unset($data['permission_ids']);
            $role->update($data);
            $role->syncPermissions($ids);
            $nodes->put($role->id, $role->refresh()->load('permissions'));
            $this->pruneDescendants($role, $ids, $nodes);
            $this->invalidateAfterCommit();

            return $role;
        });
    }

    public function delete(AdminUser $actor, AdminRole $role): void
    {
        DB::transaction(function () use ($actor, $role): void {
            $nodes = $this->lockStructure();
            $actor = $this->authorizedActor($actor, 'delete');
            $role = $nodes->get($role->id);
            abort_unless($role, 404);
            abort_if($role->name === 'super-admin', 409, '超级管理员组是保留角色，不允许删除。');
            abort_unless($this->scope->canManageRole($actor, $role), 403, '只能管理授权范围内的下级角色组。');
            abort_if($role->children()->exists(), 409, '存在下级角色组，请先处理下级角色组。');
            abort_if(DB::table(config('permission.table_names.model_has_roles'))->where('role_id', $role->id)->exists(), 409, '该角色组仍有关联成员，请先调整成员角色。');
            $role->delete();
            $this->invalidateAfterCommit();
        });
    }

    /** @return Collection<int, AdminRole> */
    private function lockStructure(): Collection
    {
        $nodes = AdminRole::where('guard_name', 'web')->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        AdminUser::orderBy('id')->lockForUpdate()->get();
        $nodes->load('permissions');

        return $nodes;
    }

    private function authorizedActor(AdminUser $actor, string $action): AdminUser
    {
        $actor = AdminUser::find($actor->id);
        abort_unless($actor && $actor->status && app(AdminMenuAccess::class)->allows($actor, 'system.role.'.$action), 403, '无权管理角色组。');

        return $actor;
    }

    /** @param Collection<int, AdminRole> $nodes */
    private function validateParent(AdminUser $actor, ?int $parentId, ?AdminRole $role, Collection $nodes): ?AdminRole
    {
        $parent = $parentId === null ? null : $nodes->get($parentId);
        if ($parentId !== null && ! $parent) {
            throw ValidationException::withMessages(['parent_id' => '上级角色组不存在。']);
        }
        $seen = [];
        for ($ancestor = $parent; $ancestor; $ancestor = $nodes->get($ancestor->parent_id)) {
            if ($ancestor->id === $role?->id || isset($seen[$ancestor->id])) {
                throw ValidationException::withMessages(['parent_id' => '不能将角色组移动到自身或后代。']);
            }
            $seen[$ancestor->id] = true;
        }
        if ($parent) {
            abort_unless($this->scope->canParentRole($actor, $parent), 403, '上级角色组不在管理范围内。');
        } else {
            abort_unless($this->scope->isSuper($actor), 403, '只有超级管理员可以创建或移动顶级角色组。');
        }

        return $parent;
    }

    /**
     * @param  array<int, int>  $requested
     * @param  array<int, int>  $old
     * @return array<int, int>
     */
    private function normalizePermissions(array $requested, array $old = []): array
    {
        $ids = array_values(array_unique(array_map(intval(...), $requested)));
        $nodes = AdminMenu::with('permission')->whereNotNull('permission_id')->get()->keyBy('permission_id');
        if (array_diff($ids, $nodes->keys()->all()) !== []) {
            throw ValidationException::withMessages(['permission_ids' => '只能选择菜单树中的有效权限 ID。']);
        }
        $pages = $nodes->where('type', 'menu')->keyBy('id');
        $removedAccess = [];
        foreach ($pages as $page) {
            if (in_array($page->permission_id, $old, true) && ! in_array($page->permission_id, $ids, true)) {
                $removedAccess[$page->id] = true;
            }
        }
        foreach ($nodes->only($ids) as $node) {
            if ($node->permission?->guard_name !== 'web') {
                throw ValidationException::withMessages(['permission_ids' => '权限必须使用管理员 web guard。']);
            }
            if ($node->type !== 'button') {
                continue;
            }
            $page = $pages->get($node->parent_id);
            if (! $page) {
                throw ValidationException::withMessages(['permission_ids' => '操作权限缺少所属菜单。']);
            }
            if (isset($removedAccess[$page->id])) {
                $ids = array_values(array_diff($ids, [$node->permission_id]));
            } else {
                $ids[] = $page->permission_id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<int, int>  $ids
     * @param  Collection<int, AdminRole>  $nodes
     */
    private function validateAllocation(AdminUser $actor, ?AdminRole $parent, array $ids, Collection $nodes): void
    {
        if ($parent && array_diff($ids, $this->scope->allocatedPermissionIds($parent, $nodes)) !== []) {
            throw ValidationException::withMessages(['permission_ids' => '下级角色组权限不能超出上级角色组。']);
        }
        if (! $this->scope->isSuper($actor) && array_diff($ids, $this->scope->grantedPermissionIds($actor)) !== []) {
            throw ValidationException::withMessages(['permission_ids' => '不能授予本人不拥有的权限。']);
        }
    }

    /**
     * @param  array<int, int>  $allowed
     * @param  Collection<int, AdminRole>  $nodes
     */
    private function pruneDescendants(AdminRole $role, array $allowed, Collection $nodes): void
    {
        foreach ($nodes->where('parent_id', $role->id) as $child) {
            $ids = array_values(array_intersect($child->permissions->pluck('id')->all(), $allowed));
            $child->syncPermissions($ids);
            $this->pruneDescendants($child, $ids, $nodes);
        }
    }

    private function invalidateAfterCommit(): void
    {
        DB::afterCommit(fn () => app(PermissionRegistrar::class)->forgetCachedPermissions());
    }
}
