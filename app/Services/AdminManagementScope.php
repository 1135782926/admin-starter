<?php

namespace App\Services;

use App\Models\AdminMenu;
use App\Models\AdminRole;
use App\Models\AdminUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class AdminManagementScope
{
    /** @return Collection<int, AdminRole> */
    public function roleNodes(): Collection
    {
        return AdminRole::where('guard_name', 'web')->with('permissions')->orderBy('id')->get()->keyBy('id');
    }

    /** @param Collection<int, AdminRole> $nodes */
    public function roleEnabled(AdminRole $role, Collection $nodes): bool
    {
        $seen = [];
        while (true) {
            if (! $role->status || isset($seen[$role->id])) {
                return false;
            }
            $seen[$role->id] = true;
            if ($role->parent_id === null) {
                return true;
            }
            $role = $nodes->get($role->parent_id);
            if (! $role) {
                return false;
            }
        }
    }

    /**
     * @param  Collection<int, AdminRole>  $nodes
     * @return array<int, int>
     */
    public function allocatedPermissionIds(AdminRole $role, Collection $nodes): array
    {
        if ($role->name === 'super-admin') {
            return $this->menuPermissionIds();
        }
        $ids = $role->permissions->where('guard_name', 'web')->pluck('id')->all();
        $seen = [$role->id => true];
        while ($role->parent_id !== null) {
            $role = $nodes->get($role->parent_id);
            if (! $role || isset($seen[$role->id])) {
                return [];
            }
            $seen[$role->id] = true;
            if ($role->name !== 'super-admin') {
                $ids = array_values(array_intersect($ids, $role->permissions->where('guard_name', 'web')->pluck('id')->all()));
            }
        }

        return $ids;
    }

    /**
     * @param  Collection<int, AdminRole>  $nodes
     * @return array<int, int>
     */
    public function rolePermissionIds(AdminRole $role, Collection $nodes): array
    {
        return $this->roleEnabled($role, $nodes) ? $this->allocatedPermissionIds($role, $nodes) : [];
    }

    /** @return array<int, int> */
    public function menuPermissionIds(): array
    {
        return AdminMenu::whereHas('permission', fn (Builder $query) => $query->where('guard_name', 'web'))->pluck('permission_id')->all();
    }

    /** @return array{nodes: Collection<int, AdminRole>, own: array<int, int>, granted: array<int, int>, super: bool} */
    public function context(AdminUser $actor): array
    {
        $nodes = $this->roleNodes();
        $own = $actor->roles()->where('guard_name', 'web')->pluck('admin_roles.id')->all();
        $enabled = $nodes->only($own)->filter(fn (AdminRole $role): bool => $this->roleEnabled($role, $nodes));
        $super = $actor->status && $enabled->contains('name', 'super-admin');
        $granted = $actor->permissions()->where('guard_name', 'web')->pluck('admin_permissions.id')->all();
        foreach ($enabled as $role) {
            $granted = array_merge($granted, $this->allocatedPermissionIds($role, $nodes));
        }

        return ['nodes' => $nodes, 'own' => $enabled->modelKeys(), 'granted' => $actor->status ? array_values(array_unique($granted)) : [], 'super' => $super];
    }

    public function isSuper(AdminUser $actor): bool
    {
        return $this->context($actor)['super'];
    }

    /** @return array<int, int> */
    public function grantedPermissionIds(AdminUser $actor): array
    {
        return $this->context($actor)['granted'];
    }

    /**
     * @param  array{nodes: Collection<int, AdminRole>, own: array<int, int>, granted: array<int, int>, super: bool}  $context
     */
    private function manageable(AdminRole $role, array $context): bool
    {
        if ($role->guard_name !== 'web' || $role->name === 'super-admin') {
            return false;
        }
        if ($context['super']) {
            return true;
        }
        if (in_array($role->id, $context['own'], true) || array_diff($this->allocatedPermissionIds($role, $context['nodes']), $context['granted']) !== []) {
            return false;
        }
        $seen = [];
        while ($role->parent_id !== null) {
            if (in_array($role->parent_id, $context['own'], true)) {
                return true;
            }
            $role = $context['nodes']->get($role->parent_id);
            if (! $role || isset($seen[$role->id])) {
                return false;
            }
            $seen[$role->id] = true;
        }

        return false;
    }

    public function canManageRole(AdminUser $actor, AdminRole $role, ?array $context = null): bool
    {
        return $actor->status && $this->manageable($role, $context ?? $this->context($actor));
    }

    public function canParentRole(AdminUser $actor, AdminRole $role): bool
    {
        $context = $this->context($actor);

        return $actor->status && $role->guard_name === 'web' && ($context['super'] || in_array($role->id, $context['own'], true) || $this->manageable($role, $context));
    }

    /** @return Collection<int, AdminRole> */
    public function visibleRoles(AdminUser $actor): Collection
    {
        $context = $this->context($actor);

        return $context['nodes']->filter(fn (AdminRole $role): bool => $actor->status && ($context['super'] || in_array($role->id, $context['own'], true) || $this->manageable($role, $context)));
    }

    /** @return array<int, int> */
    public function assignableRoleIds(AdminUser $actor): array
    {
        $context = $this->context($actor);

        return $context['nodes']->filter(fn (AdminRole $role): bool => $actor->status && ($context['super'] || $this->manageable($role, $context)))->modelKeys();
    }

    /** @return Builder<AdminUser> */
    public function adminQuery(AdminUser $actor): Builder
    {
        $query = AdminUser::query();
        $context = $this->context($actor);
        if ($context['super']) {
            return $query;
        }
        $roles = $context['nodes']->filter(fn (AdminRole $role): bool => $this->manageable($role, $context))->modelKeys();

        return $query->where('id', '!=', $actor->id)
            ->whereHas('roles', fn (Builder $query) => $query->whereIn('admin_roles.id', $roles))
            ->whereDoesntHave('roles', fn (Builder $query) => $query->whereNotIn('admin_roles.id', $roles))
            ->whereDoesntHave('permissions', fn (Builder $query) => $query->whereNotIn('admin_permissions.id', $context['granted']));
    }
}
