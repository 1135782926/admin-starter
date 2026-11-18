<?php

namespace App\Services;

use App\Models\AdminMenu;
use App\Models\AdminPermission;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

class AdminMenuService
{
    public const BUTTONS = ['view' => '查看', 'create' => '添加', 'update' => '编辑', 'delete' => '删除'];

    /** @param array<string, mixed> $data */
    public function create(array $data): AdminMenu
    {
        try {
            return DB::transaction(function () use ($data): AdminMenu {
                $nodes = AdminMenu::orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $this->validateParent($data, null, $nodes);
                $buttons = $data['buttons'] ?? array_keys(self::BUTTONS);
                unset($data['buttons'], $data['permission_id']);
                $menu = $this->createNode($data);

                if ($menu->type === 'menu' && $menu->open_type === 'page') {
                    foreach ($buttons as $sort => $operation) {
                        $this->createNode([
                            'parent_id' => $menu->id,
                            'type' => 'button',
                            'title' => self::BUTTONS[$operation],
                            'name' => $menu->name.'.'.$operation,
                            'sort' => $sort,
                        ]);
                    }
                }

                $this->invalidatePermissionsAfterCommit();

                return $menu->refresh()->load('permission');
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => '节点标识或生成的权限名称已存在。']);
        }
    }

    /** @param array<string, mixed> $data */
    private function createNode(array $data): AdminMenu
    {
        if ($data['type'] !== 'directory') {
            $permissionName = $data['type'] === 'menu' ? $data['name'].'.access' : $data['name'];
            if (AdminPermission::where('name', $permissionName)->where('guard_name', 'web')->exists()) {
                throw ValidationException::withMessages(['name' => '生成的权限名称已存在。']);
            }
            $data['permission_id'] = AdminPermission::create(['name' => $permissionName, 'guard_name' => 'web'])->id;
        }

        return AdminMenu::create($data);
    }

    /** @param array<string, mixed> $data */
    public function update(AdminMenu $menu, array $data): AdminMenu
    {
        return DB::transaction(function () use ($menu, $data): AdminMenu {
            $nodes = AdminMenu::orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $current = $nodes->get($menu->id);
            abort_unless($current, 404);
            $this->validateParent(array_replace($current->getAttributes(), $data), $current, $nodes);
            $current->update($data);

            return $current->refresh()->load('permission');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  Collection<int, AdminMenu>  $nodes
     */
    private function validateParent(array $data, ?AdminMenu $menu, Collection $nodes): void
    {
        $parentId = $data['parent_id'] ?? null;
        $parent = $parentId === null ? null : $nodes->get($parentId);
        $invalid = $parentId !== null && ! $parent;
        $invalid = $invalid || ($data['type'] === 'button' ? $parent?->type !== 'menu' : ($parent && $parent->type !== 'directory'));

        if ($menu?->type === 'button' && $parentId != $menu->parent_id) {
            $invalid = true;
        }
        if (! $invalid && $data['type'] === 'button' && $parent && (! str_starts_with($data['name'], $parent->name.'.') || $data['name'] === $parent->name.'.access')) {
            throw ValidationException::withMessages(['name' => '按钮标识必须位于所属菜单权限前缀下，且不能使用 access。']);
        }

        $visited = [];
        for ($ancestor = $parent; $ancestor; $ancestor = $nodes->get($ancestor->parent_id)) {
            if ($ancestor->id === $menu?->id || isset($visited[$ancestor->id])) {
                $invalid = true;
                break;
            }
            $visited[$ancestor->id] = true;
        }

        if ($invalid) {
            throw ValidationException::withMessages(['parent_id' => '父级类型不允许、形成循环，或按钮跨菜单移动。']);
        }
    }

    /** @return array{children_count: int, roles_count: int, direct_users_count: int, can_delete: bool} */
    public function deletionImpact(AdminMenu $menu): array
    {
        $children = $menu->children()->count();
        $permissionId = $menu->permission_id;
        $directUserGrants = $permissionId ? DB::table(config('permission.table_names.model_has_permissions'))
            ->select('model_type', 'model_id')
            ->where('permission_id', $permissionId)
            ->distinct() : null;

        return [
            'children_count' => $children,
            'roles_count' => $permissionId ? DB::table(config('permission.table_names.role_has_permissions'))->where('permission_id', $permissionId)->count() : 0,
            'direct_users_count' => $directUserGrants ? DB::query()->fromSub($directUserGrants, 'direct_users')->count() : 0,
            'can_delete' => $children === 0,
        ];
    }

    public function delete(AdminMenu $menu): void
    {
        DB::transaction(function () use ($menu): void {
            $nodes = AdminMenu::orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $current = $nodes->get($menu->id);
            abort_unless($current, 404);
            abort_if($current->children()->exists(), 409, '存在子节点，请先处理子节点。');
            $permission = $current->permission()->lockForUpdate()->first();
            $current->delete();
            if ($permission) {
                DB::table(config('permission.table_names.role_has_permissions'))->where('permission_id', $permission->id)->delete();
                DB::table(config('permission.table_names.model_has_permissions'))->where('permission_id', $permission->id)->delete();
                $permission->delete();
            }
            $this->invalidatePermissionsAfterCommit();
        });
    }

    private function invalidatePermissionsAfterCommit(): void
    {
        DB::afterCommit(fn () => app(PermissionRegistrar::class)->forgetCachedPermissions());
    }
}
